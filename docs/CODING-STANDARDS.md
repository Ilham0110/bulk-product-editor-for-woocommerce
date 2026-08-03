# Standar Penulisan Kode

Dokumen ini menjelaskan gaya kode yang **sudah dipakai** di plugin ini, bukan
gaya ideal menurut buku. Tujuannya satu: kode baru tidak terlihat asing di
antara kode lama.

Semua angka di bawah adalah hasil pengukuran terhadap file yang ada, bukan
anjuran teoretis.

> **Catatan penting:** plugin ini **tidak** mengikuti WordPress Coding Standards
> (WPCS) resmi. WPCS mewajibkan indentasi tab dan `snake_case` untuk semua nama.
> Plugin ini memakai 4 spasi dan PHP modern. Itu keputusan yang sudah diambil
> dan konsisten di seluruh file — **ikuti yang ada, jangan campur**. Mengubahnya
> berarti menyentuh 1349 baris sekaligus, dan itu bukan pekerjaan sambilan.

---

## PHP

### Yang terukur

| Aspek | Nilai | Konsistensi |
|---|---|---|
| Indentasi | 4 spasi | 830 baris, **nol** tab |
| Kutip | tunggal `'` | 1345 vs 9 |
| Trailing comma | ya | 228 kemunculan |
| Docblock | `/** */` | 28 blok |
| Baris > 120 karakter | 3 | praktis tidak ada |

### Kerangka file

```php
<?php

declare(strict_types=1);

/**
 * Plugin Name: …
 */

defined('ABSPATH') || exit();
```

`declare(strict_types=1)` **wajib di setiap file PHP**, dan harus berada
sebelum apa pun kecuali `<?php`. Tanpa itu, PHP diam-diam mengubah `"abc"`
menjadi `0` saat menemui parameter bertipe `int` — persis jenis bug yang ingin
dicegah oleh type hint.

`defined('ABSPATH') || exit()` mencegah file dieksekusi lewat akses langsung.
Wajib di setiap file PHP, termasuk file view.

### Tipe

Setiap method punya type hint parameter **dan** return type. Tanpa pengecualian.

❌ **Salah**:
```php
private function set_scalar($product, $field, $value)
{
    …
}
```

✅ **Benar**:
```php
private function set_scalar(WC_Product $product, string $field, mixed $value): void
{
    …
}
```

Method tanpa nilai kembali tetap ditandai `: void` — itu informasi, bukan
formalitas. Pembaca jadi tahu tidak ada gunanya menangkap hasilnya.

Untuk tipe yang tidak bisa dinyatakan PHP, pakai docblock dengan sintaks
PHPStan:

```php
/** @return list<int> */
private function post_ids(string $key): array

/** @param array<string, mixed> $fields */
private function apply_fields(WC_Product $product, array $fields): void

/** @return array<int, array{cat_names: list<string>, cat_ids: list<int>}> */
private function collect_terms(array $ids): array
```

`list<int>` berarti array berindeks berurutan dari 0. `array<string, mixed>`
berarti array asosiatif. Bedanya penting saat membaca kode berbulan-bulan
kemudian.

### Visibilitas

Semuanya `private` **kecuali** yang benar-benar dipanggil dari luar class:

| Visibilitas | Untuk apa |
|---|---|
| `public` | callback WordPress (handler AJAX, `render_admin_page`, `add_admin_menu`, `enqueue_assets`) dan `instance()` |
| `private` | segala sesuatu yang lain |
| `protected` | tidak dipakai — class-nya `final`, tidak ada yang mewarisi |

Class-nya `final`. Kalau butuh menambah perilaku, tambahkan method, jangan
membuka pewarisan.

### Konstanta

Nilai konfigurasi jadi `private const` di dalam class, bukan `define()` baru
atau variabel global:

```php
private const CAPABILITY   = 'manage_woocommerce';
private const NONCE        = 'wc_bulk_editor_nonce';
private const MAX_PER_PAGE = 100;
```

Hanya tiga konstanta global yang ada, semuanya di level file dan berprefix
`WCBULK_`: `WCBULK_VERSION`, `WCBULK_PLUGIN_DIR`, `WCBULK_PLUGIN_URL`.
Jangan tambah yang keempat tanpa alasan kuat.

### Idiom PHP modern yang dipakai

Plugin ini menargetkan PHP 8.3 dan memakainya sungguhan. Contoh dari kode:

```php
// First-class callable — bukan array [$this, 'method'] atau string
add_action('admin_menu', $this->add_admin_menu(...), 99);

// match(true) untuk dispatch bertingkat
match (true) {
    isset(self::SCALAR_SETTERS[$field]) => $this->set_scalar($product, $field, $value),
    $field === 'post_status'            => $this->set_post_status($product, $value),
    default                             => null,
};

// Null coalescing assignment untuk memoisasi
return $this->placeholder_image ??= (string) wc_placeholder_img_src('thumbnail');
return self::$instance ??= new self();

// Arrow function dengan tipe lengkap
static fn(WP_Term $c): array => ['id' => $c->term_id, 'name' => $c->name]

// Destructuring dari konstanta array
[$setter, $allowed] = self::ENUM_SETTERS[$field];
```

Pakai idiom ini. Menulis `array($this, 'method')` di tengah kode yang memakai
`$this->method(...)` membuat file terasa ditulis dua orang berbeda.

**Yang belum dipakai** dan tidak perlu diperkenalkan tanpa alasan: named
arguments, `enum`, `readonly` properties. Konsistensi lebih berharga daripada
kebaruan.

### Penamaan

| Elemen | Gaya | Contoh |
|---|---|---|
| Class | `PascalCase` dengan underscore | `WC_Bulk_Product_Editor` |
| Method | `snake_case` | `build_product_payload()` |
| Variabel | `snake_case` | `$per_page`, `$stock_status` |
| Konstanta | `SCREAMING_SNAKE` | `MAX_PER_PAGE` |
| Handler AJAX | `wc_bulk_` + kata kerja | `wc_bulk_save_inline()` |
| User meta | `_wcbulk_` | `_wcbulk_columns` |

Nama method AJAX **harus** sama persis dengan nama action-nya, karena
didaftarkan lewat `'wp_ajax_' . $action`. Ketidakcocokan berarti endpoint tidak
pernah terpanggil — tanpa error apa pun.

### Perataan vertikal

Gaya yang dipakai konsisten meratakan `=>` dan `=` pada blok yang berdekatan:

```php
private const CAPABILITY   = 'manage_woocommerce';
private const NONCE        = 'wc_bulk_editor_nonce';
private const PAGE_SLUG    = 'bulk-product-editor-for-woocommerce';

$page     = max(1, absint($_POST['page'] ?? 1));
$per_page = min(absint($_POST['per_page'] ?? 50) ?: 50, self::MAX_PER_PAGE);
$status   = $this->post_string('status');
```

Pertahankan saat mengedit blok yang sudah rata. Kalau menambah baris yang lebih
panjang, ratakan ulang seluruh blok — bukan hanya baris baru.

### Komentar

Komentar di plugin ini menjelaskan **kenapa**, bukan **apa**. Contoh yang bagus
dari kode yang ada:

```php
// WooCommerce silently discards _stock unless manage_stock is on, so a
// quantity arriving on its own has to switch stock management on first.

// jquery-ui-sortable powers the drag-to-reorder list in the Columns modal.

/** Resolved once per request; the lookup hits the options table. */
```

Ketiganya menjelaskan sesuatu yang tidak terbaca dari kodenya sendiri.

❌ Jangan menulis ulang kode dalam bahasa manusia:
```php
// Ambil produk berdasarkan ID
$product = wc_get_product($pid);
```

Bahasa komentar: **Inggris**, mengikuti seluruh komentar yang ada. (Dokumentasi
di `docs/` boleh bahasa Indonesia — itu untuk pembaca manusia, bukan bagian dari
kode.)

---

## JavaScript

### Yang terukur

| Aspek | Nilai |
|---|---|
| Indentasi | 4 spasi (1721 baris) |
| Deklarasi | `var` — 123 kemunculan, **nol** `const`/`let` |
| Arrow function | **nol** |
| Kutip | tunggal (1564 vs 219) |
| Baris > 100 karakter | 8 |

### ES5, dan itu disengaja

Tidak ada build step. File dimuat browser apa adanya. Jadi:

❌ **Jangan**:
```js
const rows = products.map(p => renderRow(p));
let total = 0;
`Template ${literal}`
```

✅ **Ikuti gaya yang ada**:
```js
var rows = $.map(products, function (p) {
    return renderRow(p);
});
var total = 0;
'String ' + concat;
```

Ini bukan soal browser lama — semua browser modern mendukung ES6. Ini soal
konsistensi: 2213 baris memakai satu gaya, dan campuran dua gaya membuat file
lebih sulit dibaca daripada gaya lama yang konsisten.

### Idiom `var s = this`

Dipakai di hampir setiap method:

```js
loadProducts: function () {
    var s = this;
    $.post(WCB.ajax_url, d, function (r) {
        s.renderTable(r.data.products);   // `this` di sini adalah XHR, bukan B
    });
},
```

Selalu `s`, bukan `self`, `that`, atau `_this`. Ikuti.

### Struktur

Satu objek `B`, di-boot dalam IIFE:

```js
(function ($, WCB) {
    'use strict';
    var B = { … };
    $(document).ready(function () { B.init(); });
})(jQuery, window.WCBulkEditor || {});
```

`$` dan `WCB` masuk sebagai parameter, bukan diakses sebagai global. Method baru
masuk ke seksi yang sesuai (lihat peta di
[ARCHITECTURE.md](ARCHITECTURE.md#peta-adminjs)) — jangan ditempel di akhir file.

### Escape wajib

Setiap nilai dari server yang masuk ke HTML harus melewati helper:

```js
s.esc(value)      // isi elemen
s.escAttr(value)  // nilai atribut
```

Lihat [SECURITY.md](SECURITY.md#7-output-escape-di-titik-cetak).

### Event delegation

Baris tabel dirender ulang terus-menerus, jadi handler dipasang ke `document`,
bukan ke elemen:

❌ Lepas setelah render ulang:
```js
$('.wc-bulk-row-check').on('change', …);
```

✅ Bertahan:
```js
$(document).on('change', '.wc-bulk-row-check', function () { … });
```

---

## CSS

`admin.css` memakai gaya **satu baris per selector** — padat, bukan
multi-baris. Ini hasil konsolidasi lima file duplikat dan sengaja
dipertahankan agar file tetap ringkas.

```css
.wc-bulk-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;}
```

### Design token

Semua warna dan ukuran berulang ada di `:root` sebagai custom property:

```css
--wcb-primary: #7F54B3;      /* ungu WooCommerce */
--wcb-changed-bg: #FFF9E6;   /* penanda sel berubah */
--wcb-radius: 6px;
--wcb-trans: 150ms ease;
```

❌ Jangan tulis nilai mentah:
```css
.wc-bulk-btn { background: #7F54B3; }
```

✅ Pakai token:
```css
.wc-bulk-btn { background: var(--wcb-primary); }
```

Warna baru yang dipakai lebih dari sekali harus jadi token dulu.

### Prefix dan spesifisitas

Semua class berprefix `wc-bulk-` dan berada di bawah `.wc-bulk-editor-wrap`.
Ini mencegah bentrok dengan stylesheet admin WordPress dan plugin lain.

`!important` sudah dipakai di beberapa tempat untuk mengalahkan CSS admin
WordPress — itu terpaksa dan terbatas pada seksi "Base & WordPress overrides".
**Jangan menyebarkannya ke seksi lain.** Kalau butuh `!important` di tempat
baru, kemungkinan besar selektornya kurang spesifik.

Ikuti pembagian delapan seksi yang sudah ada di header file — jangan menempel
aturan baru di akhir.

---

## PHP dan HTML dalam View

`views/admin-page.php` memakai gaya rapat — tag PHP menempel tanpa spasi:

```php
<?php esc_html_e('Columns','bulk-product-editor-for-woocommerce');?>
```

Perhatikan: tidak ada spasi setelah koma, tidak ada spasi sebelum `?>`.
Konsisten di seluruh file.

Setiap seksi ditandai komentar HTML:
```php
<!-- Filters -->
<!-- Quick Apply panel -->
```

**Peringatan:** JS mengikat diri ke ID dan class di file ini. Mengganti nama
`id="wc-bulk-search"` berarti harus mengubah `admin.js` juga — dan tidak ada
yang akan error, fiturnya hanya berhenti bekerja diam-diam.

---

## Tanpa Tooling Otomatis

Plugin ini tidak punya PHPCS, PHPStan, ESLint, atau Prettier. Itu berarti
standar di dokumen ini ditegakkan oleh **pembacaan**, bukan oleh mesin.

Kalau suatu saat mau menambahkan tooling, urutan yang masuk akal:

1. **PHPStan** level 5–6 dengan `php-stubs/woocommerce-stubs` — nilai tertinggi
   per usaha. Menangkap salah nama method, tipe tidak cocok, null dereference.
2. **Prettier** untuk JS/CSS — deterministik, tidak perlu diperdebatkan.
3. **PHPCS** — perlu ruleset kustom, karena WPCS bawaan akan menandai seluruh
   file (indentasi spasi vs tab). Nilainya paling rendah untuk plugin ini.

Menambahkan tooling berarti memperkenalkan `composer.json` dan folder `vendor/`
— keputusan yang mengubah karakter plugin dari "edit langsung di server" menjadi
"perlu langkah instalasi". Catat sebagai ADR kalau diambil.

---

## Checklist Sebelum Commit

- [ ] PHP: `declare(strict_types=1)` ada
- [ ] PHP: semua method punya type hint parameter dan return type
- [ ] PHP: array kompleks punya docblock `@param`/`@return` bergaya PHPStan
- [ ] PHP: 4 spasi, kutip tunggal, trailing comma
- [ ] PHP: perataan `=`/`=>` pada blok berdekatan tetap terjaga
- [ ] JS: `var` saja — tidak ada `const`, `let`, arrow function, template literal
- [ ] JS: `var s = this` untuk konteks di dalam callback
- [ ] JS: event baru pakai delegation dari `document`
- [ ] JS: semua nilai server melewati `esc()`/`escAttr()`
- [ ] CSS: warna baru jadi token di `:root`, bukan nilai mentah
- [ ] CSS: aturan masuk ke seksi yang tepat, bukan ditempel di akhir
- [ ] Komentar menjelaskan **kenapa**, dalam bahasa Inggris
- [ ] Tidak ada file `.bak-*` baru
