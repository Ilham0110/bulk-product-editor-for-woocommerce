# Antarmuka Admin

Menu, notice, penargetan layar, dan alur form di admin WordPress.

Plugin ini murni admin-side, jadi semua yang dibahas di sini berlaku langsung.
Untuk halaman pengaturan berbasis Settings API, lihat
[SETTINGS-API.md](SETTINGS-API.md) — dokumen ini membahas sisanya.

Diverifikasi terhadap WordPress 7.0.2: `wp-admin/admin-header.php:290-321`,
`wp-includes/functions.php:9078`, `wp-admin/includes/class-wp-screen.php`,
`wp-admin/admin.php:242`.

---

## 1. Menu Admin

### Yang dipakai plugin ini

```php
add_action('admin_menu', $this->add_admin_menu(...), 99);

public function add_admin_menu(): void
{
    add_submenu_page(
        'woocommerce',                                       // parent slug
        __('Bulk Product Editor', 'wc-bulk-editor'),         // <title> halaman
        __('Bulk Editor', 'wc-bulk-editor'),                 // teks menu
        self::CAPABILITY,                                    // manage_woocommerce
        self::PAGE_SLUG,                                     // wc-bulk-editor
        $this->render_admin_page(...),                       // callback render
    );
}
```

Menghasilkan hook suffix `woocommerce_page_wc-bulk-editor`, yang disimpan
sebagai `self::SCREEN_ID` dan dipakai untuk penargetan aset.

### Prioritas 99 bukan kebetulan

Tanpa prioritas, submenu bisa menyelip di tengah item WooCommerce bawaan.
Prioritas tinggi menempatkannya di bawah.

### Parent slug yang umum

| Parent | Lokasi |
|---|---|
| `woocommerce` | WooCommerce ← plugin ini |
| `edit.php?post_type=product` | Products |
| `options-general.php` | Settings |
| `tools.php` | Tools |
| `edit.php` | Posts |

**Jangan buat menu top-level** (`add_menu_page()`) untuk satu plugin. Sidebar
admin cepat penuh, dan extension WooCommerce sebaiknya berada di bawah
WooCommerce.

### Capability bukan pengaman

Parameter capability di `add_submenu_page()` hanya **menyembunyikan item menu**.
Halamannya tetap bisa diakses lewat URL langsung:

```
/wp-admin/admin.php?page=wc-bulk-editor
```

Callback render wajib mengecek sendiri:

```php
public function render_admin_page(): void
{
    if (!current_user_can(self::CAPABILITY)) {
        wp_die(esc_html__('Akses ditolak.', 'wc-bulk-editor'));
    }
    // …
}
```

> **Catatan untuk plugin ini:** `render_admin_page()` saat ini **tidak**
> melakukan pengecekan itu. Halaman hanya berisi markup kosong yang diisi JS,
> dan semua data mengalir lewat AJAX yang dijaga `guard()` — jadi tidak ada
> kebocoran data. Tapi menambahkannya tetap benar: satu baris, dan
> menghilangkan seluruh kelas masalah kalau nanti ada data dirender langsung
> di PHP.

### Menu tersembunyi

Halaman yang perlu URL tapi tidak perlu item menu — misalnya halaman detail
atau konfirmasi:

```php
add_submenu_page(
    '',                          // parent kosong = tidak muncul di sidebar
    __('Detail', 'wc-bulk-editor'),
    '',
    'manage_woocommerce',
    'wc-bulk-editor-detail',
    'render_detail_page'
);
```

Tetap wajib cek capability di callback — ini menyembunyikan, bukan mengamankan.

---

## 2. Penargetan Layar

### Aturan utama: jangan muat aset di mana-mana

Plugin yang memuat CSS/JS di seluruh admin adalah penyebab umum konflik dan
admin yang lambat. Plugin ini melakukannya dengan benar:

```php
public function enqueue_assets(string $hook): void
{
    if ($hook !== self::SCREEN_ID) {
        return;
    }
    // enqueue di sini
}
```

`$hook` yang diterima `admin_enqueue_scripts` adalah nilai kembalian
`add_submenu_page()` — untuk plugin ini `woocommerce_page_wc-bulk-editor`.

### Tiga cara menargetkan, dan kapan memakainya

**a. Perbandingan hook suffix** — paling sederhana, dipakai plugin ini:

```php
if ($hook !== 'woocommerce_page_wc-bulk-editor') {
    return;
}
```

Cocok untuk satu halaman yang kamu daftarkan sendiri.

**b. `get_current_screen()`** — untuk kondisi yang lebih kaya:

```php
$screen = get_current_screen();

if (!$screen) {
    return;   // bisa null di beberapa konteks
}

if ($screen->post_type !== 'product') {
    return;
}
```

Properti yang tersedia (`class-wp-screen.php`):

| Properti | Isi | Contoh |
|---|---|---|
| `id` | pengenal layar penuh | `woocommerce_page_wc-bulk-editor` |
| `base` | layar tanpa konteks | `woocommerce_page_wc-bulk-editor` |
| `post_type` | tipe post | `product` |
| `taxonomy` | taksonomi | `product_cat` |
| `action` | aksi saat ini | `add` |
| `parent_base` | slug parent menu | `woocommerce` |

**`get_current_screen()` bisa mengembalikan `null`.** Selalu cek sebelum
mengakses propertinya — fatal error di admin adalah layar putih.

Tersedia sejak action `current_screen` (`class-wp-screen.php:426`), yang
berjalan setelah `admin_init` dan sebelum `admin_enqueue_scripts`. Memanggilnya
di `plugins_loaded` menghasilkan `null`.

**c. Hook `load-{page}`** — berjalan **sebelum** halaman dirender:

```php
$hook = add_submenu_page(…);              // simpan nilai kembaliannya

add_action("load-{$hook}", static function (): void {
    // Tempat yang tepat untuk: memproses form, redirect, cek prasyarat
});
```

Ini yang paling berguna untuk alur form — lihat bagian 4. Terdaftar di
`wp-admin/admin.php:289`.

### Perbedaan `id` dan `base`

Untuk halaman plugin biasa keduanya sama. Bedanya muncul di layar bawaan:

| Layar | `id` | `base` |
|---|---|---|
| Daftar produk | `edit-product` | `edit` |
| Edit produk | `product` | `post` |
| Tambah produk | `product` | `post` |

Untuk membedakan tambah dan edit, pakai `$screen->action === 'add'`.

---

## 3. Notice

Ada dua sistem berbeda di plugin ini, dan keduanya benar untuk konteksnya
masing-masing.

### a. Notice PHP — `wp_admin_notice()`

WordPress 6.4+ menyediakan fungsi yang menangani markup dan escaping:

```php
wp_admin_notice(
    esc_html__('Bulk Editor: the admin template is missing.', 'wc-bulk-editor'),
    ['type' => 'error']
);
```

Argumen yang tersedia (`wp-includes/functions.php:9078`):

```php
[
    'type'               => 'error',      // error | warning | success | info
    'dismissible'        => true,
    'id'                 => 'my-notice',
    'additional_classes' => ['inline'],
    'attributes'         => ['data-slug' => 'x'],
    'paragraph_wrap'     => true,
]
```

**Pesan tidak di-escape otomatis.** Escape sendiri sebelum mengirim — pola yang
sudah dipakai plugin ini.

Untuk WordPress di bawah 6.4, markup manualnya:

```php
printf(
    '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
    esc_attr($type),
    esc_html($message)
);
```

Class `notice` **wajib** — tanpa itu WordPress tidak memindahkan notice ke
posisi yang benar di bawah judul halaman.

### b. Hook mana yang dipakai

Urutan pemanggilan di `wp-admin/admin-header.php`:

```
network_admin_notices     baris 299   (hanya network admin)
user_admin_notices        baris 305   (hanya user admin)
admin_notices             baris 313   (admin situs biasa)
all_admin_notices         baris 321   (semua konteks)
```

Untuk plugin biasa, `admin_notices` sudah tepat.

### c. Notice hanya di layar yang relevan

Notice global yang muncul di setiap halaman admin adalah keluhan umum pemilik
situs.

❌ Muncul di mana-mana:
```php
add_action('admin_notices', 'my_notice');
```

✅ Dibatasi:
```php
add_action('admin_notices', static function (): void {
    $screen = get_current_screen();

    if (!$screen || $screen->id !== 'woocommerce_page_wc-bulk-editor') {
        return;
    }

    wp_admin_notice(
        esc_html__('Pesan khusus halaman ini.', 'wc-bulk-editor'),
        ['type' => 'info', 'dismissible' => true]
    );
});
```

### d. Notice yang bisa ditutup permanen

`is-dismissible` hanya menyembunyikan sampai halaman dimuat ulang. Untuk
menyimpan penutupan:

```php
add_action('admin_notices', static function (): void {
    if (get_user_meta(get_current_user_id(), '_wcbulk_dismissed_tip', true)) {
        return;
    }

    wp_admin_notice(
        esc_html__('Tekan Ctrl+S untuk menyimpan semua perubahan.', 'wc-bulk-editor'),
        [
            'type'               => 'info',
            'dismissible'        => true,
            'additional_classes' => ['wcbulk-tip'],
            'attributes'         => ['data-nonce' => wp_create_nonce('wcbulk_dismiss')],
        ]
    );
});

add_action('wp_ajax_wcbulk_dismiss_tip', static function (): void {
    check_ajax_referer('wcbulk_dismiss', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(null, 403);
    }

    update_user_meta(get_current_user_id(), '_wcbulk_dismissed_tip', 1);
    wp_send_json_success();
});
```

Simpan di **user meta**, bukan option — penutupan itu keputusan per orang.

### e. Notice in-page — sistem plugin ini

Untuk umpan balik hasil AJAX, plugin ini memakai sistem sendiri
(`admin.js:1775`):

```js
showNotice: function (type, msg) {
    var n = $('<div class="wc-bulk-notice ' + type + '">…' + this.esc(msg) + '</div>');
    $('.wc-bulk-notice-wrapper').html(n);
    clearTimeout(this._nt);
    this._nt = setTimeout(function () { n.fadeOut(400, …); }, 5000);
}
```

Ini pilihan yang tepat. Notice WordPress muncul di atas halaman, jauh dari
tabel — untuk umpan balik "12 produk tersimpan" yang menyertai aksi di tabel,
notice in-page lebih dekat ke tempat kerja user.

Perhatikan `clearTimeout(this._nt)` — tanpa itu, dua aksi beruntun membuat
timer pertama menghapus notice kedua lebih cepat.

Pesan di-escape lewat `this.esc()`. Pertahankan.

### f. Jangan pakai `add_settings_error()` di luar Settings API

Fungsi itu mengandaikan alur `options.php` dan siklus transient tersendiri.
Di halaman kustom, notice-nya sering tidak muncul atau muncul di halaman yang
salah.

---

## 4. Alur Form Admin

Plugin ini seluruhnya AJAX, jadi tidak ada form POST tradisional. Tapi kalau
nanti diperlukan — misalnya halaman impor CSV — ini polanya.

### Pola Post/Redirect/Get

Tanpa redirect setelah POST, menekan F5 mengirim ulang form. Untuk operasi
massal, itu berarti data diproses dua kali.

```php
$hook = add_submenu_page(…);

add_action("load-{$hook}", static function (): void {
    if (($_POST['action'] ?? '') !== 'wcbulk_import') {
        return;
    }

    // 1. Nonce
    check_admin_referer('wcbulk_import', 'wcbulk_nonce');

    // 2. Capability
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('Akses ditolak.', 'wc-bulk-editor'));
    }

    // 3. Proses
    $count = wcbulk_process_import($_POST);

    // 4. Redirect dengan hasil di query string
    wp_safe_redirect(add_query_arg(
        [
            'page'     => 'wc-bulk-editor-import',
            'imported' => $count,
        ],
        admin_url('admin.php')
    ));
    exit;   // WAJIB
});
```

Empat hal yang wajib:

**a. Proses di `load-{hook}`, bukan di callback render.** Callback render
berjalan setelah header terkirim — `wp_safe_redirect()` di sana menghasilkan
"headers already sent".

**b. `exit` setelah redirect.** Tanpa itu eksekusi berlanjut dan halaman tetap
dirender.

**c. `wp_safe_redirect()`, bukan `wp_redirect()`.** Yang pertama menolak
tujuan di luar domain sendiri — mencegah open redirect kalau URL berasal dari
input.

**d. `check_admin_referer()`, bukan `wp_verify_nonce()` manual.** Fungsi itu
juga memeriksa referer dan menampilkan halaman error standar.

### Form-nya

```php
<form method="post" action="">
    <?php wp_nonce_field('wcbulk_import', 'wcbulk_nonce'); ?>
    <input type="hidden" name="action" value="wcbulk_import" />

    <input type="file" name="csv" />

    <?php submit_button(__('Impor', 'wc-bulk-editor')); ?>
</form>
```

`wp_nonce_field()` menghasilkan nonce dan referer sekaligus. Jangan menulis
field nonce manual.

### Menampilkan hasil setelah redirect

```php
public function render_import_page(): void
{
    $imported = isset($_GET['imported']) ? absint($_GET['imported']) : null;

    if ($imported !== null) {
        wp_admin_notice(
            esc_html(sprintf(
                /* translators: %d: jumlah produk */
                _n('%d produk diimpor.', '%d produk diimpor.', $imported, 'wc-bulk-editor'),
                $imported
            )),
            ['type' => 'success', 'dismissible' => true]
        );
    }
    // …
}
```

Parameter dari query string tetap harus disanitasi — `absint()` di sini.

### Aksi destruktif: konfirmasi dulu

Untuk hapus atau reset, jangan langsung eksekusi dari link:

```php
// Link dengan nonce
printf(
    '<a href="%s" class="button">%s</a>',
    esc_url(wp_nonce_url(
        add_query_arg(['page' => 'wc-bulk-editor', 'action' => 'reset']),
        'wcbulk_reset',
        'wcbulk_nonce'
    )),
    esc_html__('Reset kolom', 'wc-bulk-editor')
);
```

```php
// Verifikasi
check_admin_referer('wcbulk_reset', 'wcbulk_nonce');
```

`wp_nonce_url()` menambahkan nonce ke URL. Tetap perlu konfirmasi user —
crawler dan prefetch browser bisa mengikuti link GET.

### File upload

```php
if (!function_exists('wp_handle_upload')) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
}

$file = wp_handle_upload(
    $_FILES['csv'],
    [
        'test_form' => false,
        'mimes'     => ['csv' => 'text/csv'],
    ]
);

if (isset($file['error'])) {
    wp_die(esc_html($file['error']));
}
```

Selalu batasi `mimes`. Tanpa itu, file apa pun bisa diunggah ke folder uploads
— termasuk PHP.

---

## 5. Kesalahan Umum

❌ **Memuat aset di seluruh admin:**
```php
add_action('admin_enqueue_scripts', function () {
    wp_enqueue_script('my-script', …);   // tanpa cek $hook
});
```

❌ **`get_current_screen()` tanpa cek null:**
```php
if (get_current_screen()->id === 'x') { … }   // fatal kalau null
```

❌ **Redirect di callback render:**
```php
public function render_page(): void {
    wp_safe_redirect(…);   // headers already sent
}
```

❌ **Notice global tanpa batasan layar.**

❌ **Mengandalkan capability `add_submenu_page()` sebagai satu-satunya
pengaman.**

❌ **`echo` di dalam hook `admin_init`** — merusak layout admin.

❌ **Notice tanpa class `notice`** — posisinya kacau.

❌ **Form POST tanpa redirect** — F5 memproses ulang.

---

## 6. Keadaan Plugin Ini

| Aspek | Status |
|---|---|
| Submenu di bawah WooCommerce | ✅ benar |
| Prioritas 99 | ✅ disengaja |
| Aset dibatasi satu layar | ✅ `$hook !== self::SCREEN_ID` |
| Notice in-page untuk AJAX | ✅ tepat untuk konteksnya |
| Escape di `showNotice()` | ✅ `this.esc()` |
| Cek capability di `render_admin_page()` | ⚠️ belum ada — lihat catatan bagian 1 |
| Form POST tradisional | — tidak ada, seluruhnya AJAX |
| Settings page | — tidak ada, lihat [SETTINGS-API.md](SETTINGS-API.md) |

---

## Rujukan

- `wp-admin/admin-header.php:290-321` — urutan hook notice
- `wp-includes/functions.php:9078` — `wp_get_admin_notice()` dan argumennya
- `wp-includes/functions.php:9189` — `wp_admin_notice()`
- `wp-admin/includes/class-wp-screen.php:426` — action `current_screen`
- `wp-admin/admin.php:289` — hook `load-{page}`
- `wp-admin/includes/plugin.php` — `add_submenu_page()`, `add_menu_page()`
