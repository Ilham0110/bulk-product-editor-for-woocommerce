# Settings API

Plugin ini **tidak memakai Settings API** — nol `register_setting()`, nol
`add_settings_field()`, nol option milik plugin sendiri.

Itu keputusan yang tepat, dan dokumen ini menjelaskan kenapa. Bagian kedua
menjelaskan cara memakainya dengan benar kalau nanti memang dibutuhkan.

---

## 1. Kenapa Plugin Ini Tidak Memakainya

Settings API dirancang untuk **pengaturan tingkat-situs**: satu nilai yang
berlaku untuk semua orang, diisi administrator, tersimpan di `wp_options`.

Yang disimpan plugin ini bukan itu:

| Data | Sifat | Disimpan di |
|---|---|---|
| Kolom aktif | preferensi per-user | user meta `_wcbulk_columns` |
| Saved views | preferensi per-user | user meta `_wcbulk_views` |
| Panel collapse | kosmetik per-browser | `localStorage` |

Ketiganya milik **user**, bukan milik situs. Dua admin harus bisa punya layout
kolom berbeda. Settings API tidak bisa melakukan itu — ia menulis ke
`wp_options`, yang tunggal untuk seluruh situs.

Lihat [ADR 0006](adr/0006-tanpa-tabel-kustom.md) untuk alasan lengkap pemilihan
user meta.

Selain itu, preferensi di plugin ini diubah lewat **modal di halaman yang sama**,
bukan lewat halaman pengaturan terpisah. Alurnya: buka Columns → centang →
tersimpan lewat AJAX → tabel langsung berubah. Settings API mengandaikan alur
form-submit-reload yang berbeda sepenuhnya.

**Aturan singkat:** kalau nilainya berbeda per user, Settings API bukan
jawabannya.

---

## 2. Kapan Settings API Tepat Dipakai

Pakai kalau **semua** syarat ini terpenuhi:

- Nilai berlaku untuk seluruh situs, bukan per user
- Diisi administrator, bukan pengguna biasa
- Diubah jarang — bukan bagian dari alur kerja harian
- Punya halaman pengaturan sendiri dengan tombol Save

Contoh yang cocok kalau nanti plugin ini butuh:

| Pengaturan | Kenapa cocok |
|---|---|
| Default jumlah produk per halaman | tingkat situs, jarang diubah |
| Role mana yang boleh akses Bulk Editor | kebijakan situs |
| Aktifkan/matikan Export CSV | keputusan administrator |
| Batas maksimum produk per operasi | pengaman tingkat situs |

Contoh yang **tidak** cocok: kolom aktif, saved views, urutan kolom, filter
terakhir — semuanya per user.

---

## 3. Cara Memakainya dengan Benar

Kalau nanti diperlukan, ini pola lengkapnya. Tiga bagian: daftarkan, tampilkan,
baca.

### a. Daftarkan

```php
add_action('admin_init', static function (): void {
    register_setting(
        'wcbulk_settings',              // group — harus cocok dengan settings_fields()
        'wcbulk_options',               // nama option di wp_options
        [
            'type'              => 'array',
            'sanitize_callback' => 'wcbulk_sanitize_options',   // WAJIB
            'default'           => [
                'per_page'      => 50,
                'allow_export'  => true,
            ],
            'show_in_rest'      => false,
        ]
    );

    add_settings_section(
        'wcbulk_main',
        __('Pengaturan Editor', 'wc-bulk-editor'),
        '__return_false',               // tanpa deskripsi section
        'wcbulk_settings_page'
    );

    add_settings_field(
        'wcbulk_per_page',
        __('Produk per halaman', 'wc-bulk-editor'),
        'wcbulk_render_per_page_field',
        'wcbulk_settings_page',
        'wcbulk_main'
    );
});
```

**Satu option array, bukan banyak option terpisah.** Menyimpan sepuluh
pengaturan sebagai `wcbulk_per_page`, `wcbulk_allow_export`, dan seterusnya
berarti sepuluh baris di `wp_options` dan sepuluh `register_setting()`. Satu
array cukup.

### b. `sanitize_callback` bukan opsional

Ini titik paling sering salah. Tanpa sanitasi, apa pun yang dikirim form masuk
ke database.

```php
/**
 * @param  mixed $input Nilai mentah dari form.
 * @return array<string, mixed>
 */
function wcbulk_sanitize_options($input): array
{
    $clean = [];

    // Whitelist, bukan blacklist — pola yang sama dengan apply_fields().
    $clean['per_page'] = min(100, max(10, absint($input['per_page'] ?? 50)));

    $clean['allow_export'] = !empty($input['allow_export']);

    $role = sanitize_key($input['min_role'] ?? 'shop_manager');
    $clean['min_role'] = in_array($role, ['administrator', 'shop_manager'], true)
        ? $role
        : 'shop_manager';

    return $clean;
}
```

Tiga aturan:

**Kembalikan hanya key yang dikenali.** Jangan `return $input` dengan beberapa
field dibersihkan — field yang tidak kamu periksa tetap lolos.

**Batasi rentang angka.** `absint()` saja tidak cukup; `per_page = 999999` akan
membuat halaman tidak bisa dimuat.

**Validasi enum dengan `in_array(..., true)`.** Parameter ketiga wajib — sama
seperti di [SECURITY.md](SECURITY.md#4-whitelist-bukan-blacklist).

### c. Halaman pengaturan

```php
function wcbulk_render_settings_page(): void
{
    // Settings API tidak mengecek capability untuk kita.
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('Akses ditolak.', 'wc-bulk-editor'));
    }

    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form action="options.php" method="post">
            <?php
            settings_fields('wcbulk_settings');          // nonce + option group
            do_settings_sections('wcbulk_settings_page');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}
```

`settings_fields()` menghasilkan nonce, referer, dan field `option_page`.
**Jangan menulis nonce sendiri** — `options.php` mengharapkan bentuk yang
persis dihasilkan fungsi itu.

`action="options.php"` wajib. Mengarahkan form ke halaman sendiri berarti harus
menangani penyimpanan, nonce, dan redirect secara manual — dan kehilangan
seluruh manfaat Settings API.

### d. Render field

```php
function wcbulk_render_per_page_field(): void
{
    $options = get_option('wcbulk_options', []);
    $value   = $options['per_page'] ?? 50;

    printf(
        '<input type="number" name="wcbulk_options[per_page]" value="%d" min="10" max="100" class="small-text" />',
        (int) $value
    );
}
```

Nama field memakai notasi array: `wcbulk_options[per_page]`. Ini yang membuat
seluruh pengaturan tiba di `sanitize_callback` sebagai satu array.

### e. Baca nilainya

```php
private function per_page(): int
{
    $options = get_option('wcbulk_options', []);

    return min(
        self::MAX_PER_PAGE,
        max(10, absint($options['per_page'] ?? 50))
    );
}
```

**Validasi ulang saat membaca**, jangan hanya saat menyimpan. Option bisa
diubah lewat WP-CLI, lewat plugin lain, atau langsung di database — semuanya
melewati `sanitize_callback`.

---

## 4. Menu: Jangan Buat Halaman Top-Level

Plugin ini memakai submenu di bawah WooCommerce:

```php
add_submenu_page(
    'woocommerce',                  // parent
    __('Bulk Product Editor', 'wc-bulk-editor'),
    __('Bulk Editor', 'wc-bulk-editor'),
    self::CAPABILITY,
    self::PAGE_SLUG,
    $this->render_admin_page(...),
);
```

Kalau nanti ada halaman pengaturan, taruh di tempat yang sama:

```php
add_submenu_page(
    'woocommerce',
    __('Pengaturan Bulk Editor', 'wc-bulk-editor'),
    __('Pengaturan Bulk Editor', 'wc-bulk-editor'),
    'manage_woocommerce',
    'wc-bulk-editor-settings',
    'wcbulk_render_settings_page'
);
```

❌ Jangan buat menu top-level untuk satu plugin:
```php
add_menu_page(…);   // menambah item di sidebar utama
```

Menu admin WordPress cepat penuh. Plugin extension WooCommerce sebaiknya berada
di bawah WooCommerce, bukan bersaing dengan Posts dan Pages.

### Parameter capability bukan pengaman

`add_submenu_page()` dengan capability hanya menyembunyikan item menu. Halaman
tetap bisa diakses lewat URL langsung. Callback render **wajib** mengecek
sendiri:

```php
if (!current_user_can('manage_woocommerce')) {
    wp_die(esc_html__('Akses ditolak.', 'wc-bulk-editor'));
}
```

---

## 5. Alternatif: Sistem Pengaturan WooCommerce

Untuk plugin extension WooCommerce, ada pilihan lain yang lebih terintegrasi:
menambahkan tab atau section ke **WooCommerce → Settings**.

WooCommerce tidak memakai Settings API WordPress. Ia punya sistemnya sendiri
(`includes/admin/settings/class-wc-settings-page.php`) dengan dua hook utama:

```php
// Tambah tab baru
add_filter('woocommerce_get_settings_pages', function (array $pages): array {
    $pages[] = new WCBulk_Settings_Page();
    return $pages;
});
```

Atau lebih sederhana — tambahkan section ke tab Products yang sudah ada:

```php
add_filter('woocommerce_get_sections_products', function (array $sections): array {
    $sections['wcbulk'] = __('Bulk Editor', 'wc-bulk-editor');
    return $sections;
});

add_filter('woocommerce_get_settings_products', function (array $settings, string $section): array {
    if ($section !== 'wcbulk') {
        return $settings;
    }

    return [
        [
            'title' => __('Bulk Editor', 'wc-bulk-editor'),
            'type'  => 'title',
            'id'    => 'wcbulk_options',
        ],
        [
            'title'    => __('Produk per halaman', 'wc-bulk-editor'),
            'id'       => 'wcbulk_per_page',
            'type'     => 'number',
            'default'  => '50',
            'custom_attributes' => ['min' => 10, 'max' => 100],
        ],
        [
            'type' => 'sectionend',
            'id'   => 'wcbulk_options',
        ],
    ];
}, 10, 2);
```

WooCommerce menangani render, penyimpanan, dan nonce sendiri.

### Mana yang dipilih

| | Settings API | WooCommerce Settings |
|---|---|---|
| Lokasi | halaman sendiri | WooCommerce → Settings |
| Tampilan | perlu diatur sendiri | otomatis konsisten dengan Woo |
| Sanitasi | `sanitize_callback` sendiri | sebagian ditangani Woo per tipe field |
| Ketergantungan | core WordPress | struktur internal WooCommerce |
| Risiko | stabil | bisa berubah antar versi Woo |

Untuk plugin ini — extension yang jelas-jelas milik WooCommerce dan sudah
bermenu di bawahnya — **WooCommerce Settings lebih pas** kalau pengaturan
memang dibutuhkan. Konsisten dengan tempat user sudah biasa mencari.

Tapi perlu diketahui: field WooCommerce **tidak** menjamin sanitasi selengkap
`sanitize_callback` milikmu. Tetap validasi ulang saat membaca.

---

## 6. Yang Harus Dihindari

❌ **Menyimpan preferensi per-user di option:**
```php
update_option('wcbulk_columns', $columns);   // dua admin saling menimpa
```
Pakai `update_user_meta()` — pola yang sudah dipakai plugin ini.

❌ **Option ber-autoload untuk data besar:**
```php
add_option('wcbulk_cache', $huge_array);     // autoload default = yes
```
Option ber-autoload dimuat di **setiap** request WordPress, termasuk seluruh
halaman frontend. Untuk data yang hanya dipakai di satu halaman admin:
```php
add_option('wcbulk_cache', $data, '', false);   // autoload = no
```

❌ **Form ke halaman sendiri:**
```php
<form method="post" action="">   <!-- harus tangani nonce & simpan manual -->
```

❌ **`register_setting()` tanpa `sanitize_callback`:**
```php
register_setting('wcbulk_settings', 'wcbulk_options');   // apa pun masuk DB
```

❌ **Mengandalkan capability di `add_submenu_page()` sebagai satu-satunya
pengaman.** Cek ulang di callback.

❌ **Membaca option tanpa default:**
```php
$per_page = get_option('wcbulk_options')['per_page'];   // fatal kalau belum ada
```
Selalu `get_option('wcbulk_options', [])` lalu `?? 50`.

---

## 7. Keadaan Saat Ini

Tidak ada yang perlu dikerjakan. Plugin ini tidak butuh Settings API, dan
menambahkannya tanpa kebutuhan nyata hanya menambah halaman yang tidak dibuka
siapa pun.

Kalau suatu saat muncul pengaturan tingkat-situs yang sungguh dibutuhkan:

1. Pastikan dulu itu memang per-situs, bukan per-user
2. Pilih WooCommerce Settings, bukan halaman sendiri
3. Satu option array, bukan banyak option terpisah
4. `sanitize_callback` dengan whitelist
5. Validasi ulang saat membaca
6. Catat sebagai ADR kalau menambah option pertama — itu mengubah
   [ADR 0006](adr/0006-tanpa-tabel-kustom.md)

---

## Rujukan

- `wp-includes/option.php:2994` — `register_setting()` dan argumennya
- `wp-admin/includes/template.php:1637` — `add_settings_section()`
- `wp-admin/includes/template.php:1715` — `add_settings_field()`
- `wp-admin/includes/plugin.php:2347` — `settings_fields()`
- `woocommerce/includes/admin/class-wc-admin-settings.php:70` — filter
  `woocommerce_get_settings_pages`
- `woocommerce/includes/admin/settings/class-wc-settings-page.php:24` —
  `WC_Settings_Page` abstract class
