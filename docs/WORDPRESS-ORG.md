# Standar WordPress.org

Apa yang dibutuhkan untuk mengirim plugin ini ke direktori resmi
WordPress.org — dan apakah itu sepadan.

**Kesimpulan di depan:** dokumen ini awalnya ditulis ketika plugin masih
bernama `WooCommerce Bulk Product Editor` dengan slug `wc-bulk-editor` — dua
hal yang membuatnya pasti ditolak. Keduanya sudah diperbaiki. Yang tersisa
hanya urusan rilis (screenshot, paket ZIP); lihat checklist di bagian akhir.

---

## 1. Penghalang yang Tidak Bisa Dinegosiasikan: Nama

Nama lama plugin ini:

```
Plugin Name: WooCommerce Bulk Product Editor
```

**"WooCommerce" adalah merek dagang Automattic.** Kebijakan direktori melarang
nama plugin yang dimulai dengan merek dagang pihak lain. Plugin dengan nama
seperti ini ditolak di tahap pertama review, sebelum kodenya dibaca.

Yang diizinkan adalah menyebut kompatibilitas, bukan mengklaim nama:

| ❌ Ditolak | ✅ Diterima |
|---|---|
| `WooCommerce Bulk Product Editor` | `Bulk Product Editor for WooCommerce` |
| `WooCommerce Quick Edit` | `Quick Edit — WooCommerce Add-on` |

Pola `"… for WooCommerce"` diterima karena tidak dimulai dengan merek dagang.
Nama plugin sekarang `Bulk Product Editor for WooCommerce`.

### Slug: aturannya lebih ketat, dan kami sempat salah duga

Dokumen ini semula menyatakan slug `wc-bulk-editor` aman karena `wc-` bukan
kata "woocommerce" utuh. Plugin Check membantahnya:

```
The plugin slug "wc-bulk-editor" contains the restricted term "wc"
which cannot be used at all in your plugin slug.
```

`wc` ada di daftar istilah yang dilarang mutlak — bukan hanya sebagai awalan,
tapi di posisi mana pun. Slug sekarang `bulk-product-editor-for-woocommerce`,
mengikuti nama plugin.

Pelajarannya: jangan menebak soal slug, jalankan `wp plugin check` sebelum
menulis kode yang mengandungnya. Mengganti slug bukan penggantian satu baris —
ia menyentuh nama folder, nama file utama, text domain di setiap pemanggilan
fungsi terjemahan (197 tempat di plugin ini), `PAGE_SLUG`, `SCREEN_ID`, handle
aset, dan nama file POT. Semakin lambat ditemukan, semakin mahal.

---

## 2. File Wajib

Hasil audit folder plugin:

| File | Status | Wajib? |
|---|---|---|
| `readme.txt` | ✅ ada | **ya** — tanpa ini tidak bisa disubmit |
| `uninstall.php` | ✅ ada | praktis wajib (lihat [LIFECYCLE.md](LIFECYCLE.md#3-uninstall--yang-seharusnya-ada)) |
| `LICENSE` | ✅ ada | ya |
| `languages/*.pot` | ✅ ada | tidak wajib, tapi membuka terjemahan komunitas |
| `assets/screenshot-1.png` | ❌ belum | tidak wajib, tapi sangat dianjurkan |

Bagian di bawah menjelaskan format masing-masing, sebagai rujukan saat
memperbaruinya.

### `readme.txt` — format wajib

Bukan Markdown. Format khusus WordPress.org:

```
=== Bulk Product Editor for WooCommerce ===
Contributors: ilhamdarmawan
Tags: woocommerce, bulk edit, products, inline edit, spreadsheet
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 3.12.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Edit ratusan produk WooCommerce dari satu tabel ala spreadsheet.

== Description ==

Deskripsi panjang di sini. Paragraf biasa, tanpa HTML.

Fitur:

* Inline editing 30 kolom, 28 di antaranya dapat disunting
* Quick Apply untuk perubahan harga massal
* Saved views
* Export CSV

== Installation ==

1. Unggah folder ke `/wp-content/plugins/`
2. Aktifkan lewat menu Plugins
3. Buka WooCommerce → Bulk Editor

== Frequently Asked Questions ==

= Apakah mendukung produk variable? =

Yang diedit adalah produk induk. Harga per-variasi tetap diatur lewat
halaman produk.

== Screenshots ==

1. Tabel editor dengan inline editing
2. Modal pemilihan kolom

== Changelog ==

= 3.11.0 =
* Perbaikan escaping pada dropdown tax class dan shipping class
* Perbaikan duplikasi option saat render ulang

== Upgrade Notice ==

= 3.11.0 =
Perbaikan keamanan. Disarankan memperbarui.
```

Aturan yang mudah terlewat:

- **`Stable tag` harus cocok** dengan tag SVN yang ada. Salah di sini adalah
  penyebab paling umum plugin "terinstal tapi versinya salah".
- **Maksimal 12 tag**, dan tag yang terlalu umum diabaikan.
- **`Tested up to`** harus versi WordPress yang benar-benar diuji. Jangan
  menebak ke depan.
- Deskripsi singkat (di bawah `===`) maksimal **150 karakter**.
- Validasi sebelum submit lewat validator readme resmi WordPress.org.

### `LICENSE`

Direktori hanya menerima kode berlisensi GPL-compatible. Sertakan teks GPL v2
lengkap sebagai `LICENSE`, dan tambahkan ke header plugin:

```php
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
```

### Screenshot

Ditaruh di folder `assets/` **repository SVN**, bukan di dalam folder plugin.
Nama: `screenshot-1.png`, `screenshot-2.png`, dan seterusnya — urutannya harus
cocok dengan daftar di bagian `== Screenshots ==` pada `readme.txt`.

---

## 3. Header Plugin

Sudah lengkap — sepuluh field wajib terisi:

```php
 * Plugin Name:          Bulk Product Editor for WooCommerce
 * Plugin URI:           https://github.com/Ilham0110/bulk-product-editor-for-woocommerce
 * Description:          Spreadsheet-style inline editing for WooCommerce products.
 * Version:              3.12.0
 * Author:               Ilham Darmawan
 * Author URI:           https://github.com/Ilham0110
 * Requires at least:    6.5
 * Requires PHP:         8.3
 * Requires Plugins:     woocommerce
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          bulk-product-editor-for-woocommerce
 * Domain Path:          /languages
 * WC requires at least: 9.0
 * WC tested up to:      10.9
```

Dua yang berdampak langsung:

**`Requires Plugins: woocommerce`** (WordPress 6.5+) membuat WordPress menolak
aktivasi kalau WooCommerce belum aktif. Lebih baik daripada `return` diam-diam
di baris 15, yang membuat plugin tampak aktif padahal tidak berfungsi.

**`Domain Path: /languages`** diperlukan agar terjemahan yang dibundel bisa
ditemukan — lihat [I18N.md](I18N.md#1-kenapa-terjemahan-tidak-akan-dimuat).

---

## 4. Yang Sudah Bersih

Hasil audit — tidak ada masalah di area ini:

| Pemeriksaan | Hasil |
|---|---|
| `eval()`, `extract()`, `base64_decode()`, `exec()` | ✅ nol |
| Aset dari CDN / remote | ✅ nol — semua lokal |
| `<script>` inline di view | ✅ nol |
| Script/style lewat `wp_enqueue_*` | ✅ ya |
| Escaping output | ✅ konsisten (setelah perbaikan v3.11) |
| Nonce + capability di semua endpoint | ✅ `guard()` |
| Text domain literal | ✅ 211 pemanggilan, nol pelanggaran |
| Prefix konsisten (`wcbulk_`, `wc_bulk_`, `_wcbulk_`) | ✅ |
| Ukuran plugin | ✅ 340 KB |
| Slug cocok dengan text domain | ✅ `bulk-product-editor-for-woocommerce` |

Ini bagian yang paling sering menggagalkan plugin lain, dan di sini sudah
beres.

---

## 5. Hasil Plugin Check (PCP)

Plugin Check adalah alat resmi yang menjalankan sebagian pemeriksaan reviewer.
Sudah dijalankan (PCP 2.0.0) lewat WP-CLI:

```bash
wp plugin check bulk-product-editor-for-woocommerce
```

**Hasil akhir: nol temuan di dalam kode.** Yang tersisa hanya dua peringatan
tentang berkas pengembangan yang memang tidak boleh ikut dikirim:

| Peringatan | Berkas | Tindakan |
|---|---|---|
| `hidden_files` | `.gitignore` | dikecualikan dari paket rilis |
| `unexpected_markdown_file` | `CLAUDE.md` | dikecualikan dari paket rilis |

Temuan yang **dulu** muncul dan sudah diselesaikan:

**a. Slug memakai istilah terlarang.** `wc-bulk-editor` → lihat bagian 1. Ini
temuan paling mahal, karena baru muncul setelah kodenya jadi.

**b. `readme.txt` berbahasa Indonesia.** Sejak Juli 2025 WordPress.org
mewajibkan `readme.txt` dalam bahasa Inggris — terjemahan lewat
translate.wordpress.org, bukan di berkas itu sendiri. Ditulis ulang penuh;
`wp plugin check --checks=plugin_readme` sekarang bersih.

**c. `$_POST` diakses langsung.** Kode memakai pola aman, tapi sniff PHPCS
tidak mengenali `(int)` sebagai sanitasi. Diselesaikan dengan
`// phpcs:ignore` yang menyebut alasannya (semua endpoint lewat `guard()`, dan
tiap daun `changes` disanitasi di `apply_fields()`) — bukan ignore telanjang.

**d. Indentasi spasi, bukan tab.** WPCS mewajibkan tab. PCP dengan konfigurasi
standar tidak menandai ini, tapi reviewer manusia mungkin berkomentar. Lihat
[ADR 0005](adr/0005-menyimpang-dari-wpcs.md) — keputusan ini disengaja.

Yang **tidak** ditandai: penggunaan PHP 8.3 modern (`match`, `??=`,
first-class callable). PCP tidak melarangnya.

---

## 6. Daftar Kerja Kalau Memutuskan Submit

Sudah selesai:

1. [x] **Nama plugin** → `Bulk Product Editor for WooCommerce`
2. [x] **`readme.txt`** format WordPress.org, 8 field + 6 bagian
3. [x] **`uninstall.php`** — menghapus `_wcbulk_columns` & `_wcbulk_views`,
       termasuk penanganan multisite
4. [x] **`LICENSE`** — GPL v2 lengkap
5. [x] **Header plugin** — 10 field wajib lengkap
6. [x] **`languages/bulk-product-editor-for-woocommerce.pot`** — 156 string
7. [x] **Nol string hardcoded** di `admin.js`
8. [x] **30 label kolom** diterjemahkan lewat `column_labels()` dan
       `column_headers()`
9. [x] **Slug** → `bulk-product-editor-for-woocommerce` (folder, berkas utama,
       text domain 197 tempat, `PAGE_SLUG`, `SCREEN_ID`, handle aset, POT)
10. [x] **`readme.txt` bahasa Inggris** — syarat WordPress.org sejak Juli 2025
11. [x] **Jalankan Plugin Check** — nol temuan di kode

Tersisa:

12. [ ] **Siapkan screenshot** minimal 2, ditaruh di folder `assets/` repo SVN
        (bukan di dalam folder plugin)
13. [ ] **Putuskan soal WPCS** — konversi penuh, atau siapkan alasan untuk
        reviewer
14. [ ] Kecualikan `.git/`, `.gitignore`, `docs/`, dan `CLAUDE.md` dari paket
        rilis

Nomor 13 adalah yang terbesar. Konversi ke WPCS berarti menyentuh 1224 baris PHP
dan 2136 baris JS tanpa memperbaiki satu pun bug. Reviewer kadang menerima
penyimpangan gaya kalau kodenya jelas aman, tapi tidak ada jaminan.

---

## 7. Apakah Sepadan?

Pertimbangan jujur.

**Alasan submit:**

- Distribusi dan pembaruan otomatis tanpa infrastruktur sendiri
- Terjemahan komunitas lewat translate.wordpress.org
- Kredibilitas dan penemuan lewat pencarian

**Alasan tidak submit:**

- Plugin ini alat internal untuk satu toko. Direktori menyelesaikan masalah
  distribusi yang tidak kamu miliki.
- Nomor 1, 8, dan 11 di daftar atas adalah pekerjaan berhari-hari
- Sekali dipublikasikan, ada kewajiban: menjawab forum dukungan, mengikuti
  perubahan WordPress dan WooCommerce, menerbitkan patch keamanan
- Kalau plugin tidak dipelihara, direktori akan menandainya "belum diuji dengan
  3 versi WordPress terakhir" — dan itu terlihat lebih buruk daripada tidak
  terdaftar

**Jalan tengah:** kerjakan nomor **2–7** saja. Semuanya membuat plugin lebih
baik terlepas dari submit atau tidak — `uninstall.php` mencegah data yatim,
header lengkap membuat WordPress menolak aktivasi tanpa WooCommerce, `.pot`
membuka jalan terjemahan, `readme.txt` mendokumentasikan changelog.

Lewati nomor 1 dan 11 sampai ada keputusan pasti untuk publikasi. Keduanya
hanya berguna untuk direktori, tidak untuk pemakaian internal.

---

## 8. Alternatif Distribusi

Kalau plugin akan dibagikan tapi tidak lewat direktori:

**Rilis GitHub + pembaruan otomatis.** Library seperti Plugin Update Checker
memungkinkan pembaruan dari repo GitHub, dengan tampilan yang sama seperti
plugin direktori. Tanpa review, tanpa batasan nama, tanpa WPCS.

**Distribusi ZIP biasa.** Cukup untuk beberapa situs milik sendiri. Pembaruan
manual.

Keduanya tidak memerlukan perubahan nama maupun konversi WPCS — hanya nomor
2–7 yang tetap relevan sebagai praktik baik.

---

## Rujukan

- Panduan direktori: `developer.wordpress.org/plugins/wordpress-org/`
- Kebijakan merek dagang: bagian "Plugin Guidelines" nomor 17
- Plugin Check (PCP): tersedia di direktori plugin
- Validator readme: `wordpress.org/plugins/developers/readme-validator/`
- Header plugin: `developer.wordpress.org/plugins/plugin-basics/header-requirements/`
