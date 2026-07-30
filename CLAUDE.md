# WooCommerce Bulk Product Editor

Editor produk WooCommerce ala spreadsheet di admin. Inline edit, bulk action,
saved views, export CSV.

- **Versi:** 3.11.0 · **PHP:** 8.3+ · **WooCommerce:** wajib aktif (plugin
  langsung `return` kalau tidak)
- **Terpasang saat ini:** WordPress 7.0.2, WooCommerce 10.9.4 — HPOS default
- **Text domain:** `wc-bulk-editor`
- **Prefix:** fungsi/AJAX `wc_bulk_`, konstanta `WCBULK_`, user meta `_wcbulk_`
- **Entry point:** `wc-bulk-editor.php` — satu class `WC_Bulk_Product_Editor`
  (singleton, tanpa namespace)

## Peta File

| File | Isi |
|---|---|
| `wc-bulk-editor.php` | Semua PHP: bootstrap, menu, enqueue, 14 handler AJAX, CRUD produk |
| `views/admin-page.php` | Markup halaman admin (di-include dari `render_admin_page()`) |
| `assets/admin.js` | Seluruh UI: render tabel, edit, modal, dirty-tracking |
| `assets/admin.css` | Styling admin |

Tidak ada composer, npm, build step, atau autoloader. Edit file langsung.

## Aturan Wajib

### Struktur & gaya
- `declare(strict_types=1);` di setiap file PHP. Semua method wajib punya
  type hint parameter dan return type.
- Ikuti gaya yang sudah ada: 4 spasi, brace class/method di baris baru,
  array multi-baris pakai trailing comma, `private` kecuali memang dipanggil
  WordPress sebagai callback.
- Daftarkan handler AJAX baru di `ajax_actions()` — jangan `add_action('wp_ajax_…')`
  terpisah.
- Nilai konfigurasi baru masuk ke `const` di dalam class, bukan variabel global
  atau `define()` baru.
- Kolom baru wajib didaftarkan di `const COLUMNS` **dan** dipetakan ke salah satu
  dari `SCALAR_SETTERS` / `BOOL_SETTERS` / `ENUM_SETTERS`, atau diberi handler
  `set_*()` khusus di `apply_fields()`.

### Keamanan — tidak bisa ditawar
- Setiap handler AJAX **wajib** memanggil `$this->guard()` di baris pertama
  (nonce + `manage_woocommerce`). Aksi yang lebih berbahaya perlu cek tambahan:
  `current_user_can('delete_post', $id)`, `manage_product_terms`, dst.
- Semua output di-escape saat dicetak: `esc_html()`, `esc_attr()`, `esc_url()`,
  `wp_kses_post()`. Di JS, jangan pernah `innerHTML` dengan data produk mentah.
- Semua input lewat `$this->post_string()` / `$this->post_ids()` atau
  `sanitize_*()`. Jangan sentuh `$_POST` langsung.
- Query DB kustom wajib `$wpdb->prepare()`. Saat ini plugin tidak punya query
  mentah — jangan tambahkan tanpa alasan kuat.

### WooCommerce
- Produk **selalu** lewat CRUD: `wc_get_product()`, `$product->set_*()`,
  `$product->save()`. **Dilarang** `update_post_meta()` untuk field produk.
- Order (kalau nanti dipakai): `wc_get_order()` saja — HPOS aktif, jangan
  `get_post_meta()` / `WP_Query`.
- Ambil daftar produk pakai `wc_get_products()` / `WC_Product_Query`, bukan
  `WP_Query` langsung.
- Batas `MAX_PER_PAGE = 100` — jangan dinaikkan tanpa mengukur dampaknya.

### i18n
- Semua string yang dilihat user dibungkus `__()` dengan text domain literal
  `'wc-bulk-editor'`.
- String untuk JS ditaruh di `i18n_strings()`, dipakai lewat
  `WCBulkEditor.i18n.<key>`. Jangan hardcode teks Inggris di `admin.js`.

### Larangan
- Jangan buat file `.bak-before-*` lagi. Folder ini sudah punya git — pakai
  commit atau branch. 47 file backup lama sudah dipindah ke luar webroot
  (`C:\laragon\backup-wcbulk\`) karena file seperti
  `admin-page.php.bak-before-align` disajikan server sebagai teks biasa,
  sehingga source code bocor lewat URL.
- Jangan tambah dependency (composer/npm) tanpa persetujuan eksplisit.
- Jangan mengubah keputusan yang tercatat di `docs/adr/` tanpa membaca ADR-nya
  lebih dulu (monolit satu file, ES5 tanpa build step, preload bukan AJAX).

## Uji Manual (belum ada test otomatis)

Setelah mengubah kode, cek di `http://larisdigital.test/wp-admin/`
→ WooCommerce → Bulk Editor:

1. Tabel tampil tanpa AJAX pertama (data di-preload lewat `wp_localize_script`).
2. Edit satu sel → indikator dirty muncul → Save → nilai persist setelah reload.
3. Buka Console — nol error JS.
4. Aktifkan Query Monitor: tidak ada PHP notice/deprecated, tidak ada lonjakan
   jumlah query saat paginasi.
5. Bulk action (trash/duplicate) dan Export CSV masih jalan.

Kalau menambah field yang bisa diedit, uji juga sebagai user role `shop_manager`,
bukan hanya administrator.

## Cara Kerja Data (ringkas)

Halaman dimuat → `enqueue_assets()` mengirim produk halaman pertama, kolom user,
kategori, tax/shipping class, dan saved views sekaligus lewat
`wp_localize_script('WCBulkEditor', …)`. Paint pertama karena itu tidak butuh
AJAX sama sekali. Interaksi berikutnya baru memanggil `admin-ajax.php`. Setiap
round-trip admin-ajax = satu bootstrap WordPress penuh, jadi **kalau data sudah
bisa dikirim saat enqueue, kirim di situ.**

Preferensi per-user disimpan di user meta: `_wcbulk_columns` (kolom terpilih)
dan `_wcbulk_views` (saved views).

## Detail Lanjutan

Baca file berikut saat relevan — jangan dibaca semua sekaligus:

| Kalau mengerjakan… | Baca |
|---|---|
| Menambah field/kolom, mengubah alur data, menavigasi `admin.js` | `docs/ARCHITECTURE.md` |
| Handler AJAX baru, capability, escaping, sanitasi | `docs/SECURITY.md` |
| Menyentuh produk/order, `wc_get_products()`, HPOS | `docs/WOOCOMMERCE.md` |
| Menulis kode baru (gaya PHP/JS/CSS) | `docs/CODING-STANDARDS.md` |
| Query, caching, aset, cron, mengukur performa | `docs/PERFORMANCE.md` |
| Menu, notice, penargetan layar, form admin | `docs/ADMIN-UI.md` |
| Aktivasi, uninstall, migrasi versi | `docs/LIFECYCLE.md` |
| String baru, terjemahan, `i18n_strings()` | `docs/I18N.md` |
| Menambah pengaturan tingkat-situs | `docs/SETTINGS-API.md` |
| Menyiapkan rilis publik | `docs/WORDPRESS-ORG.md` |
| Mempertimbangkan perubahan arsitektur | `docs/adr/` |

`docs/SECURITY.md` bagian 9 memuat temuan yang belum diperbaiki — periksa
sebelum menyentuh area terkait.
