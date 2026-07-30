# 0004. `admin-ajax.php`, bukan REST API

**Status:** Diterima
**Tanggal:** 2026-07-30 (retroaktif)

## Konteks

Plugin ini punya 14 endpoint, semuanya lewat `admin-ajax.php`:

```php
foreach ($this->ajax_actions() as $action => $callback) {
    add_action('wp_ajax_' . $action, $callback);
}
```

Sejak WordPress 4.7, REST API adalah pendekatan yang dianjurkan untuk endpoint
baru. `admin-ajax.php` sering disebut peninggalan lama.

Semua endpoint plugin ini bersifat admin-only, dipanggil dari satu halaman
admin, oleh satu file JavaScript, dan tidak pernah diakses klien lain.

## Keputusan

Tetap memakai `admin-ajax.php`.

## Konsekuensi

**Lebih mudah:**

- Nonce dan capability langsung berlaku. `check_ajax_referer()` +
  `current_user_can()` di `guard()` sudah cukup — tidak perlu
  `permission_callback` terpisah per route.
- Autentikasi cookie berjalan apa adanya. REST API butuh nonce `wp_rest` dengan
  penanganan tersendiri.
- Tidak perlu mendefinisikan schema, tidak perlu `register_rest_route()`, tidak
  perlu memikirkan versioning namespace.
- Pendaftaran endpoint baru cukup satu baris di `ajax_actions()`.
- `wp_send_json_success()` / `wp_send_json_error()` menghasilkan bentuk respons
  seragam yang sudah dipahami `admin.js`.

**Lebih sulit:**

- Endpoint tidak bisa dipakai klien lain. Kalau nanti ada aplikasi mobile atau
  integrasi eksternal, semuanya harus ditulis ulang sebagai REST.
- Tidak ada validasi schema otomatis. Setiap handler memvalidasi input sendiri
  — itulah sebabnya `post_string()`, `post_ids()`, dan pola whitelist
  `in_array()` harus diterapkan konsisten.
- Tidak ada dokumentasi endpoint otomatis.
- Semua endpoint dibedakan lewat parameter `action`, bukan URL. Lebih sulit
  dibaca di tab Network DevTools dan di access log.
- Setiap request memuat WordPress penuh — mitigasinya ada di
  [ADR 0003](0003-preload-bukan-ajax.md).

**Ambang batas peninjauan:** kalau muncul klien kedua (aplikasi mobile, plugin
lain, integrasi pihak ketiga), pindah ke REST. Untuk satu halaman admin dengan
satu file JS, REST hanya menambah lapisan.

## Alternatif yang ditolak

**`register_rest_route()` di namespace sendiri.** Pendekatan modern dan benar
untuk API publik. Ditolak karena membutuhkan `permission_callback`, definisi
`args` schema, dan penanganan nonce `wp_rest` — semuanya menyelesaikan masalah
yang tidak dimiliki plugin ini. Manfaatnya (klien pihak ketiga, dokumentasi
otomatis, validasi schema) tidak satu pun relevan untuk satu halaman admin.

**Endpoint di namespace `wc/store/v1`.** Ditolak tegas. Namespace itu milik
WooCommerce dan bisa berubah tanpa peringatan. Menempelkan endpoint di sana
berarti plugin bisa rusak setiap kali WooCommerce diperbarui.

**Campuran: REST untuk baca, admin-ajax untuk tulis.** Dua mekanisme
autentikasi, dua bentuk respons, dua cara penanganan error. Kerumitan ganda
tanpa keuntungan yang jelas.
