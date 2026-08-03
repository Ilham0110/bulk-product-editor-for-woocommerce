# Siklus Hidup Plugin

Aktivasi, deaktivasi, uninstall, dan upgrade. Empat momen yang dijalankan
WordPress di luar alur request normal — dan empat momen yang paling sering
salah ditangani.

**Keadaan plugin ini saat ini:** tidak punya satu pun hook siklus hidup.
Sebagian besar itu benar. Satu bagian tidak.

---

## Ringkasan

| Hook | Kapan jalan | Dipakai plugin ini? |
|---|---|---|
| `register_activation_hook` | sekali, saat diaktifkan | ❌ tidak perlu |
| `register_deactivation_hook` | sekali, saat dinonaktifkan | ❌ tidak perlu |
| `uninstall.php` | saat dihapus dari admin | ❌ **seharusnya ada** |
| Rutin upgrade | saat versi berubah | ❌ belum perlu |

---

## 1. Aktivasi

### Kenapa plugin ini tidak memakainya

Hook aktivasi diperlukan kalau plugin harus **menyiapkan sesuatu** sebelum bisa
bekerja: membuat tabel, menulis option default, menjadwalkan cron, atau
menambah role. Plugin ini tidak melakukan satu pun dari itu — lihat
[ADR 0006](adr/0006-tanpa-tabel-kustom.md).

Preferensi user dibuat saat dibutuhkan, bukan saat aktivasi:

```php
// get_user_columns() — default dihitung, tidak perlu ditulis dulu
private function get_user_columns(int $uid): array
{
    $saved = get_user_meta($uid, self::META_COLUMNS, true);
    // kalau kosong → pakai kolom default dari const COLUMNS
}
```

Pola "hitung default saat dibaca" lebih baik daripada "tulis default saat
aktivasi". Alasannya: kalau daftar kolom default berubah di versi berikutnya,
user lama otomatis ikut — tanpa perlu rutin migrasi.

### Kalau nanti perlu

```php
register_activation_hook(__FILE__, static function (): void {
    // …
});
```

Empat hal yang wajib diketahui:

**a. Hook ini jalan sebelum plugin lain dimuat.** WooCommerce belum tentu
tersedia. Jangan memanggil fungsi `wc_*` di dalamnya.

❌ Fatal error kalau WooCommerce belum siap:
```php
register_activation_hook(__FILE__, function () {
    $count = count(wc_get_products(['limit' => -1]));
});
```

✅ Tunda ke request berikutnya:
```php
register_activation_hook(__FILE__, function () {
    update_option('wcbulk_needs_setup', true);
});

add_action('admin_init', function () {
    if (get_option('wcbulk_needs_setup')) {
        delete_option('wcbulk_needs_setup');
        // di sini WooCommerce sudah dimuat
    }
});
```

**b. Tidak jalan saat update plugin.** Naik dari 3.11 ke 3.12 lewat pembaruan
otomatis **tidak** memicu hook aktivasi. Perubahan yang perlu berjalan pada user
lama harus lewat rutin upgrade (bagian 4), bukan hook aktivasi.

**c. Tidak jalan untuk tiap situs di multisite.** "Network activate" hanya
menjalankannya sekali. Untuk multisite:

```php
register_activation_hook(__FILE__, static function (bool $network_wide): void {
    if ($network_wide && is_multisite()) {
        foreach (get_sites(['fields' => 'ids']) as $blog_id) {
            switch_to_blog($blog_id);
            wcbulk_setup();
            restore_current_blog();
        }
        return;
    }
    wcbulk_setup();
});
```

**d. Output apa pun akan merusak aktivasi.** Satu `echo`, satu PHP notice, atau
satu baris kosong setelah `?>` membuat WordPress menampilkan "The plugin
generated N characters of unexpected output."

### Cara menolak aktivasi

Plugin ini memakai `return` diam-diam kalau WooCommerce tidak aktif
(baris 15–17). Plugin tampak aktif tapi tidak melakukan apa pun — membingungkan.

Cara yang lebih baik untuk WordPress 6.5+ adalah header plugin:

```
Requires Plugins: woocommerce
```

WordPress akan menolak aktivasi dan menampilkan alasannya sendiri. Tidak perlu
kode sama sekali.

Kalau butuh syarat yang tidak bisa dinyatakan header (misal versi WooCommerce
minimum):

```php
register_activation_hook(__FILE__, static function (): void {
    if (!defined('WC_VERSION') || version_compare(WC_VERSION, '9.0', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('Plugin ini butuh WooCommerce 9.0 atau lebih baru.', 'bulk-product-editor-for-woocommerce'),
            '',
            ['back_link' => true]
        );
    }
});
```

`wp_die()` di sini benar — ini satu-satunya konteks di mana menghentikan
eksekusi memang yang diinginkan.

---

## 2. Deaktivasi

### Kenapa plugin ini tidak memakainya

Deaktivasi seharusnya **hanya menghentikan hal yang berjalan**, bukan menghapus
data. Plugin ini tidak menjalankan apa pun di latar belakang: tidak ada cron,
tidak ada cache persisten, tidak ada rewrite rule.

### Aturan yang tidak boleh dilanggar

❌ **Jangan pernah menghapus data user saat deaktivasi**:
```php
register_deactivation_hook(__FILE__, function () {
    delete_metadata('user', 0, '_wcbulk_views', '', true);   // SALAH
});
```

User menonaktifkan plugin untuk mendiagnosis konflik sepanjang waktu. Kalau
saved views mereka hilang setiap kali, itu kehilangan data — bukan pembersihan.

✅ **Yang pantas ada di deaktivasi**:
```php
register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook('wcbulk_cleanup');   // hentikan cron
    flush_rewrite_rules();                        // kalau ada rewrite
});
```

Pembagian tanggung jawabnya jelas:

| Momen | Boleh melakukan |
|---|---|
| Deaktivasi | menghentikan proses, membuang cache |
| Uninstall | menghapus data |

---

## 3. Uninstall — **yang seharusnya ada**

### Masalahnya

Plugin ini menulis dua user meta:

```
_wcbulk_columns   kolom aktif per user
_wcbulk_views     saved views per user
```

Tanpa `uninstall.php`, meta itu **tertinggal selamanya** setelah plugin dihapus.
Tidak berbahaya dan tidak besar, tapi ini yang oleh Plugin Check disebut
*orphaned data* — dan salah satu hal pertama yang ditanyakan reviewer
WordPress.org.

### `uninstall.php`, bukan `register_uninstall_hook()`

Dua cara tersedia. Pakai yang pertama:

```php
<?php
/**
 * Uninstall routine — dijalankan saat plugin dihapus dari admin.
 */

// Konstanta ini hanya ada kalau WordPress benar-benar yang memanggil file ini.
defined('WP_UNINSTALL_PLUGIN') || exit();

delete_metadata('user', 0, '_wcbulk_columns', '', true);
delete_metadata('user', 0, '_wcbulk_views', '', true);
```

Parameter `delete_metadata()`: `('user', 0, $key, '', true)` — argumen terakhir
`$delete_all = true` membuatnya menghapus meta itu untuk **semua** user, dan
`0` sebagai object id diabaikan saat mode itu aktif.

`defined('WP_UNINSTALL_PLUGIN') || exit()` **wajib**. Tanpa itu, file bisa
dipanggil langsung lewat URL dan menghapus data siapa pun.

Kenapa `uninstall.php` lebih baik daripada `register_uninstall_hook()`:
file utama plugin **tidak dimuat** saat uninstall.php dijalankan. Jadi tidak ada
risiko efek samping dari kode bootstrap, dan tidak perlu plugin dalam keadaan
bisa dimuat.

### Yang tidak boleh dihapus

Hanya hapus data yang **dibuat plugin ini**. Produk, kategori, tag, dan tax
class adalah data WooCommerce — plugin ini hanya mengeditnya.

❌ Menghancurkan toko:
```php
// JANGAN PERNAH
$wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type = 'product'");
```

Aturannya: kalau prefix-nya bukan `_wcbulk_`, bukan milik plugin ini.

### Batas ukuran

`delete_metadata()` dengan `$delete_all` menjalankan satu query `DELETE`.
Untuk dua meta key, itu cukup. Kalau nanti ada ribuan baris yang harus dibuang,
uninstall perlu dipecah bertahap — proses uninstall punya batas waktu eksekusi
seperti request biasa.

### Diuji, bukan diasumsikan

Uninstall berjalan sekali, tanpa undo, dan tidak ada yang melihatnya. Suite
`t25-uninstall` menjalankannya dengan data tetangga ditanam lebih dulu:

| Yang diperiksa | Hasil |
|---|---|
| `_wcbulk_columns` & `_wcbulk_views` terhapus | ✅ |
| `nickname` pengguna tidak tersentuh | ✅ |
| `_woocommerce_persistent_cart_1` tidak tersentuh | ✅ |
| Option milik plugin lain tidak tersentuh | ✅ |
| 15 produk & 11 kategori tetap utuh | ✅ |
| Permintaan langsung ke `uninstall.php` tidak menghapus apa pun | ✅ |
| Dijalankan dua kali tetap aman | ✅ |

Yang terakhir bukan sekadar formalitas: WordPress bisa mengulang uninstall
kalau yang pertama gagal di tengah jalan.

### `wp plugin delete` tidak menjalankan uninstall

Perbedaan yang mudah menyesatkan saat menguji:

| Perintah | Menjalankan `uninstall.php`? |
|---|---|
| `wp plugin uninstall <slug> --deactivate` | ya |
| `wp plugin delete <slug>` | **tidak** — hanya menghapus berkas |
| Tombol Delete di layar Plugins | ya |
| `delete_plugins()` di kode | ya |

Selama pengujian ini sempat terlihat seperti bug — preferensi tertinggal
setelah `wp plugin delete`. Yang membuktikan sebaliknya adalah membandingkan
dengan `delete_plugins()` milik core pada instalasi yang sama: lewat jalur itu
meta terhapus dengan benar. Jadi ini perilaku WP-CLI, bukan kekurangan plugin.

---

## 4. Rutin Upgrade

### Kenapa belum perlu

Plugin ini tidak punya skema database dan tidak punya format data yang bisa
berubah bentuk. Naik versi hanya berarti file diganti.

### Kapan mulai perlu

Begitu **bentuk** data tersimpan berubah. Contoh nyata yang bisa terjadi di
plugin ini: `_wcbulk_views` saat ini menyimpan `filters` sebagai array datar.
Kalau suatu saat butuh nested filter, view lama harus dikonversi.

Hook aktivasi **tidak** akan menolong — ia tidak jalan saat update.

### Pola yang benar

```php
add_action('plugins_loaded', static function (): void {
    $stored = get_option('wcbulk_version', '0');

    if (version_compare($stored, WCBULK_VERSION, '>=')) {
        return;   // jalur normal: satu pembacaan option, lalu keluar
    }

    if (version_compare($stored, '4.0', '<')) {
        wcbulk_migrate_views_to_v4();
    }

    update_option('wcbulk_version', WCBULK_VERSION);
});
```

Empat hal penting:

**a. Bandingkan dengan `version_compare()`, bukan `!=`.** String `'3.9'` vs
`'3.10'` salah kalau dibandingkan sebagai teks — `'3.9' > '3.10'` bernilai
`true`.

**b. Setiap migrasi berdiri sendiri dan idempoten.** User bisa melompat dari
3.5 langsung ke 5.0. Kalau tiap blok `if` mengecek versi asalnya sendiri,
lompatan berapa pun tetap aman.

**c. Jalur normal harus murah.** Kode di atas hanya membaca satu option ketika
versi sudah cocok. Option itu ber-autoload, jadi tidak ada query tambahan.

**d. Migrasi jangan dijalankan pada request frontend.** Kalau prosesnya berat,
batasi:

```php
if (!is_admin()) {
    return;
}
```

### Yang harus dihindari

❌ Migrasi tanpa penanda versi — jalan di setiap request:
```php
add_action('init', 'wcbulk_migrate_views');   // tidak tahu kapan harus berhenti
```

❌ Migrasi destruktif tanpa cadangan:
```php
$views = transform(get_user_meta($uid, '_wcbulk_views', true));
update_user_meta($uid, '_wcbulk_views', $views);   // format lama hilang selamanya
```

✅ Simpan bentuk lama dulu:
```php
$old = get_user_meta($uid, '_wcbulk_views', true);
update_user_meta($uid, '_wcbulk_views_backup_v3', $old);
update_user_meta($uid, '_wcbulk_views', transform($old));
```

---

## 5. Urutan Hook Saat Request Normal

Konteks untuk memahami kenapa plugin ini memasang hook di tempatnya:

```
muat file plugin        → definisi konstanta, cek WooCommerce, declare HPOS
plugins_loaded          → WC_Bulk_Product_Editor::instance()   ← plugin ini
init                    → taxonomy, post type, textdomain
admin_menu              → add_submenu_page()  (prioritas 99)
admin_enqueue_scripts   → enqueue_assets() + wp_localize_script()
                          ↓
                          halaman dirender
```

Kenapa `plugins_loaded` dan bukan `init`: pada `plugins_loaded`, WooCommerce
sudah dimuat sehingga `WC_Product` tersedia, tapi masih cukup awal untuk
mendaftarkan hook `admin_menu` dan `wp_ajax_*`.

Kenapa `admin_menu` prioritas 99: agar submenu muncul setelah item WooCommerce
bawaan, bukan menyelip di tengah.

❌ Terlambat — `admin_menu` sudah lewat:
```php
add_action('admin_init', function () {
    add_submenu_page(…);   // tidak muncul
});
```

❌ Terlalu awal — WooCommerce belum ada:
```php
add_action('muplugins_loaded', function () {
    wc_get_products(…);   // fatal error
});
```

---

## 6. Yang Harus Dikerjakan

Satu item, dan ini yang nyata:

- [ ] **Buat `uninstall.php`** yang menghapus `_wcbulk_columns` dan
      `_wcbulk_views` untuk semua user. Tanpa ini, plugin meninggalkan data
      yatim setelah dihapus.

Sisanya sengaja tidak ada, dan itu benar. Jangan menambahkan hook aktivasi
hanya karena "biasanya plugin punya".

---

## Rujukan

- `wp-admin/includes/plugin.php` — `register_activation_hook()`,
  `register_uninstall_hook()`
- `wp-admin/includes/plugin.php` — `uninstall_plugin()`, tempat
  `WP_UNINSTALL_PLUGIN` didefinisikan
- Header `Requires Plugins`: WordPress 6.5+
