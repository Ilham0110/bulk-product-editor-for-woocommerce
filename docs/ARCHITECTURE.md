# Arsitektur

Dokumen ini menjelaskan bagaimana plugin bekerja dari dalam: alur boot, kontrak
data antara PHP dan JavaScript, dan peta navigasi `admin.js`.

Baca ini sebelum mengubah apa pun yang menyentuh lebih dari satu file.

---

## Gambaran Umum

Empat file, tanpa autoloader, tanpa build step:

```
wc-bulk-editor.php     →  Semua PHP. Satu class, singleton.
views/admin-page.php   →  Markup statis. Semua isi tabel dirender JS.
assets/admin.js        →  Satu controller object `B`, di-boot saat DOM ready.
assets/admin.css       →  Styling.
```

Pembagian tanggung jawabnya tegas: **PHP tidak pernah merender baris produk,
JS tidak pernah menghitung ulang data produk.** PHP mengirim array datar,
JS menggambarnya.

---

## Alur Boot

```
plugins_loaded
  └─ WC_Bulk_Product_Editor::instance()
       └─ __construct()
            ├─ add_action('admin_menu', add_admin_menu, 99)
            ├─ add_action('admin_enqueue_scripts', enqueue_assets)
            └─ foreach ajax_actions() → add_action('wp_ajax_…')
```

Sebelum itu, di level file (baris 15–27):

1. `defined('ABSPATH') || exit()` — cegah akses langsung.
2. Cek WooCommerce aktif. Kalau tidak, `return` — seluruh class tidak pernah
   didefinisikan.
3. Definisikan `WCBULK_VERSION`, `WCBULK_PLUGIN_DIR`, `WCBULK_PLUGIN_URL`.
4. Deklarasi kompatibilitas HPOS lewat `before_woocommerce_init`.

> **Kenapa `admin_menu` prioritas 99?** Supaya submenu muncul setelah item
> WooCommerce bawaan, bukan menyelip di tengah.

`enqueue_assets()` keluar lebih awal kalau `$hook !== self::SCREEN_ID`
(`woocommerce_page_wc-bulk-editor`). Aset plugin ini tidak pernah dimuat di
halaman admin lain.

---

## Prinsip Utama: Preload, Bukan AJAX

Ini keputusan desain paling penting di plugin ini.

Setiap request ke `admin-ajax.php` = satu bootstrap WordPress penuh (load semua
plugin aktif, tema, dan hook). Biayanya jauh lebih besar daripada query yang
sebenarnya dijalankan. Karena itu **semua data yang dibutuhkan paint pertama
dikirim sekaligus lewat `wp_localize_script()`**, bukan lewat AJAX:

```php
wp_localize_script('wc-bulk-editor-js', 'WCBulkEditor', [
    'ajax_url'         => …,  'nonce'    => …,
    'currency'         => …,  'decimals' => …,   // format harga
    'columns'          => …,  'all_columns' => …, // kolom user & katalog kolom
    'all_cats'         => …,                      // isi dropdown kategori
    'tax_classes'      => …,  'shipping_classes' => …,
    'preloaded'        => …,                      // 50 produk halaman pertama
    'views'            => …,                      // saved views
    'i18n'             => …,                      // string untuk JS
]);
```

Hasilnya: halaman dibuka → tabel langsung tampil. **Nol AJAX.**

`init()` di `admin.js:73` memakai `WCB.preloaded` kalau ada, dan hanya jatuh ke
`loadProducts()` (AJAX) kalau preload gagal.

**Aturannya: kalau sebuah data sudah bisa dihitung saat enqueue, kirim di situ.
Jangan bikin endpoint AJAX baru untuk itu.**

Endpoint `wc_bulk_get_categories`, `wc_bulk_get_tax_classes`,
`wc_bulk_get_shipping_classes`, dan `wc_bulk_get_columns` masih ada sebagai
cadangan/refresh, tapi tidak dipanggil saat boot normal.

---

## Kontrak Data PHP ↔ JS

Ini bagian yang paling mudah rusak. Ada **tiga bentuk data berbeda** untuk hal
yang sama, dan tiap perubahan field harus konsisten di ketiganya.

### 1. Bentuk baris (server → browser)

`product_to_row()` mendatarkan satu `WC_Product` menjadi array ~35 key.
Bentuk ini dipakai baik oleh preload maupun AJAX — keduanya lewat
`build_product_payload()`, jadi strukturnya dijamin identik.

Perhatikan ketidakseragaman yang disengaja:

| Key | Tipe | Catatan |
|---|---|---|
| `stock_quantity` | `int\|null` | `null` kalau `manage_stock` mati |
| `categories` | `list<string>` | nama, untuk ditampilkan |
| `category_ids` | `list<int>` | id, untuk dibandingkan |
| `tags` | `list<string>` | hanya nama |
| `shipping_class` | `string` | slug |
| `shipping_class_id` | `int` | yang dipakai untuk edit |
| `featured`, `virtual`, dll. | `bool` | boolean asli, bukan `'yes'`/`'no'` |
| `status` | `string` | perhatikan: **`status`**, bukan `post_status` |

Query dijalankan `wc_get_products()` dengan `'paginate' => true`, sehingga
mengembalikan objek dengan `->products`, `->total`, `->max_num_pages`.

Term diambil sekali untuk seluruh halaman lewat `collect_terms()` —
satu `wp_get_object_terms()` untuk 50 produk, bukan 50 query di dalam loop.
Kalau menambah data taxonomy baru, ikuti pola ini.

### 2. Bentuk state (di browser)

```js
B.originals    // { pid: <baris dari server> }   nilai server
B.changes      // { pid: { field: "string" } }   edit tertunda
B.selectedRows // { pid: true }                  checkbox
```

**Semua nilai di `changes` adalah string.** Tidak ada boolean, tidak ada angka.
`trackChange()` melakukan `String(newVal)` sebelum menyimpan.

### 3. Normalisasi perbandingan

Karena `originals` berisi tipe asli tapi `changes` berisi string,
`origVal(pid, field)` menormalkan nilai server ke bentuk string yang sebanding:

```js
categories      → String(category_ids[0] || '')   // single-select, ambil id pertama
tags            → tags.join(', ')
shipping_class  → String(shipping_class_id || '')
boolean         → 'yes' / 'no'
null/undefined  → ''
lainnya         → String(v)
```

`trackChange()` membandingkan hasil `origVal()` dengan nilai baru. Kalau sama,
entry dihapus dari `changes` (bukan disimpan sebagai "tidak berubah") — inilah
sebabnya mengedit sel lalu mengembalikannya ke nilai semula menghilangkan
tanda dirty.

> **Kalau menambah field dengan tipe tidak biasa** (array, objek, angka yang
> perlu format), kamu **wajib** menambahkan cabang di `origVal()`. Kalau lupa,
> field itu akan selalu terdeteksi berubah, dan tersimpan berulang tiap kali
> Save ditekan.

### 4. Kembali ke server

```
POST admin-ajax.php
  action=wc_bulk_save_inline
  nonce=…
  changes[pid][field]=value
```

`wc_bulk_save_inline()` → untuk tiap pid: `wc_get_product()` →
`apply_fields()` → `$product->save()` → `wc_delete_product_transients()`.

Tiap produk dibungkus `try/catch` sendiri. **Satu produk gagal tidak
membatalkan yang lain** — yang berhasil tetap tersimpan, yang gagal
dikumpulkan ke `$errors` dan dikembalikan sebagai `wp_send_json_error` beserta
jumlah yang berhasil.

---

## Dispatch Field: `apply_fields()`

Titik masuk tunggal untuk semua penulisan produk. Field dirutekan lewat
`match(true)` ke salah satu dari:

| Peta | Contoh | Perlakuan |
|---|---|---|
| `SCALAR_SETTERS` | `sku`, `weight`, `menu_order` | cast string, panggil setter |
| `BOOL_SETTERS` | `featured`, `virtual` | `'yes'` → `true` |
| `ENUM_SETTERS` | `stock_status`, `backorders` | **divalidasi terhadap daftar nilai sah** |
| kasus khusus | `name`, `post_status`, `categories`, `tags`, `tax_class`, `shipping_class` | method `set_*()` sendiri |

Nilai non-skalar ditolak kecuali `categories` dan `tags` (yang memang array).

**Menambah field baru yang bisa diedit:**

1. Tambahkan ke `const COLUMNS` (label + `editable => true`).
2. Petakan ke salah satu dari tiga peta setter di atas — atau tulis
   `set_*()` sendiri dan daftarkan di `match` pada `apply_fields()`.
3. Tambahkan ke `product_to_row()` supaya nilainya sampai ke browser.
4. Kalau tipenya tidak biasa, tambahkan cabang di `origVal()` (JS).
5. Kalau butuh renderer khusus, tambahkan di objek `R`.
6. Tambahkan label di `column_labels()` dan `column_headers()`, atau kolom itu
   akan memakai teks Inggris dari konstanta.

Lewatkan salah satu langkah dan field itu akan gagal secara diam-diam.

### Menolak nilai, bukan mengabaikannya

Sebagian besar setter mengabaikan input tak sah tanpa suara — `set_enum()`
membuang nilai di luar daftar, `set_bool()` hanya menerima `'yes'`/`'no'`. Itu
tepat untuk field yang punya nilai default yang masuk akal.

`set_name()` berbeda: ia **melempar `WC_Data_Exception`** saat nama kosong.

```php
if ($name === '') {
    throw new WC_Data_Exception(
        'wcbulk_empty_name',
        __('Product name cannot be empty.', 'wc-bulk-editor')
    );
}
```

Alasannya, mengabaikan diam-diam akan menyesatkan: user mengosongkan nama,
menekan Save, melihat "1 product updated", lalu menemukan namanya tidak
berubah. Exception ditangkap `try/catch` per produk di `wc_bulk_save_inline()`
dan dilaporkan sebagai kegagalan per-item — produk lain dalam batch yang sama
tetap tersimpan.

Pakai pola ini kalau nilai kosong berarti **keadaan rusak**, bukan sekadar
"tidak diubah".

### Kejutan yang sengaja: stock quantity

```php
// apply_fields(), baris 685–692
```

WooCommerce **membuang** `_stock` kalau `manage_stock` mati. Jadi kalau
`stock_quantity` datang sendirian ke produk yang manajemen stoknya mati,
`apply_fields()` menyisipkan `manage_stock => 'yes'` **di depan** array
(`['manage_stock' => 'yes'] + $fields`) supaya diproses lebih dulu.

Urutan itu penting. Jangan ubah jadi `array_merge()` atau menaruhnya di
belakang.

---

## Keamanan: `guard()`

```php
private function guard(): void
{
    check_ajax_referer(self::NONCE, 'nonce');
    if (!current_user_can(self::CAPABILITY)) {
        wp_send_json_error(['message' => …], 403);
    }
}
```

**Baris pertama setiap handler AJAX.** Tanpa pengecualian.

Dua handler menambahkan cek di atasnya karena `manage_woocommerce` saja tidak
cukup:

- `wc_bulk_create_category()` → `manage_product_terms`
- `wc_bulk_bulk_action()` → `current_user_can('delete_post', $id)` **per produk**,
  dievaluasi di dalam loop, bukan sekali di awal

Handler baru wajib mengikuti pola yang sama: `guard()` dulu, lalu cek khusus.

---

## Peta `admin.js`

2136 baris, satu objek `B`, tanpa modul. Navigasi lewat header seksi:

| Baris | Seksi | Isi |
|---|---|---|
| 19 | *(state)* | `page`, `changes`, `originals`, `selectedRows` |
| 29 | COLUMN STATE | `getActiveColumns()` — cache di `_activeColumns`, selalu menyisipkan `cb` di depan |
| 53 | BOOTSTRAP & EVENT WIRING | `init()`, semua `.on()`, Ctrl+S |
| 410 | PRODUCT LOADING | `loadProducts()` — satu-satunya pemanggil `wc_bulk_fetch_products` |
| 446 | TABLE RENDERING | `renderTable()`, objek `R` = renderer per kolom, `renderTableHead()` |
| 750 | VIEWPORT WIDTH | `measureColumnWidths()`, `columnWidth()`, `writeColumnWidths()` |
| 982 | VERTICAL FIT | tinggi area scroll dihitung dari tinggi window |
| 1169 | CELL RENDERERS | `renderEditableCell()`, `renderSelectCell()`, `classOptions()` |
| 1320 | CHANGE TRACKING | `trackChange()`, `origVal()`, `baseVal()`, `isChanged()` |
| 1379 | BULK OPERATIONS | `quickApply()`, `saveAll()`, `discardAll()`, `doBulkAction()` |
| 1595 | COLUMNS MODAL | pemilihan & pengurutan kolom (jquery-ui-sortable) |
| 1656 | BULK EDIT MODAL | Advanced Bulk Edit |
| 1760 | QUICK ADD PRODUCT |  |
| 1763 | NEW CATEGORY |  |
| 1891 | CSV EXPORT | menerima CSV dari server, memicu unduhan di browser |
| 1928 | SAVED VIEWS |  |
| 2031 | PAGINATION & NOTICES |  |
| 2087 | HELPERS | `esc()`, `escAttr()`, `fmtPrice()`, `failMessage()` |

**Gaya kode JS:** ES5 — `var`, `function`, jQuery. Tidak ada build step, jadi
tidak ada transpiling. Jangan campurkan `const`/`let`/arrow function; ikuti
gaya yang sudah ada agar konsisten.

Idiom `var s = this;` di awal method adalah pola yang dipakai di seluruh file
untuk mempertahankan konteks di dalam callback. Ikuti.

### Detail yang mudah terlewat

- **`quickApply()` hanya menyentuh produk yang sedang dimuat di layar**, bukan
  seluruh hasil filter. Operasinya menulis ke input DOM lalu memanggil
  `trackChange()` — jadi hasilnya tetap tersimpan di `changes` dan belum masuk
  database sampai Save ditekan.
- **`baseVal()` memakai `regular_price` sebagai basis saat menghitung
  `sale_price`.** "Turun 20%" pada sale price berarti 20% dari harga normal,
  bukan dari sale price sebelumnya. Ini disengaja.
- **Setelah `saveAll()` sukses**, `originals` di-patch dengan nilai baru,
  `changes` dikosongkan, lalu `loadProducts()` dipanggil untuk mengambil nilai
  server yang sebenarnya (harga bisa diformat ulang WooCommerce).
- **State panel collapse** disimpan di `localStorage` (`wcbFilters`,
  `wcbQuickApply`), dibungkus `try/catch` karena localStorage bisa diblokir.
  Default: Filters terbuka, Quick Apply tertutup.
- **Tiap field Quick Apply punya set operasi sendiri**, jadi `switch` di
  `quickApply()` tidak punya cabang mati:

  | Field | Operasi |
  |---|---|
  | `regular_price` | `set`, `increase_percent`, `decrease_percent`, `increase_fixed`, `decrease_fixed` |
  | `sale_price` | `set`, `reduce_percent`, `clear` |
  | `stock_quantity` | `set`, `increase`, `decrease` |

  `increase`/`decrease` hanya muncul di stok; `reduce_percent` hanya di sale
  price. Memeriksa satu dropdown saja akan menyesatkan.
- **`classOptions()` mengembalikan array pasangan `[value, label]`, bukan
  objek.** JavaScript mengurutkan key integer-like ke depan, yang akan
  mendorong opsi kosong "No shipping class" ke akhir daftar — dan produk tanpa
  shipping class akan tampak punya kelas pertama. `renderSelectCell()`
  menerima kedua bentuk: objek untuk daftar tetap, array pasangan bila urutan
  penting.
- **`renderEditableCell()` tidak memakai `value || ''`.** Angka `0` adalah
  nilai sah untuk `menu_order` dan dimensi; memakai `||` akan mengosongkan sel
  sehingga `origVal()` selalu melihat perbedaan dan menandainya berubah.

---

## Penyimpanan Data

Plugin ini **tidak membuat tabel database sendiri**. Semua state ada di:

| Lokasi | Key | Isi |
|---|---|---|
| user meta | `_wcbulk_columns` | kolom aktif, per user |
| user meta | `_wcbulk_views` | saved views, per user |
| localStorage | `wcbFilters`, `wcbQuickApply` | state collapse panel, per browser |

Tidak ada option global, tidak ada tabel kustom, tidak ada migrasi skema. Kalau
suatu saat perlu, itu keputusan arsitektur yang harus dicatat lebih dulu —
bukan ditambahkan diam-diam.

---

## Batas yang Diketahui

- **`MAX_PER_PAGE = 100`.** Bukan batas server, tapi batas browser: merender
  lebih banyak baris × banyak kolom membuat tabel tersendat.
- **Produk variable** hanya diedit di level induk. Harga/stok per-variasi tidak
  tersentuh plugin ini.
- **Tanpa test otomatis.** Setiap perubahan diverifikasi manual — lihat bagian
  Uji Manual di [`../CLAUDE.md`](../CLAUDE.md).
- **Tanpa build step.** Ini disengaja: file bisa diedit langsung di server dan
  langsung berlaku. Menambahkan bundler berarti mengubah keputusan ini.
