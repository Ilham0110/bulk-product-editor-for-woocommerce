# 0003. Preload data lewat `wp_localize_script`

**Status:** Diterima
**Tanggal:** 2026-07-30 (retroaktif — diambil pada v3.9, seri `bak-before-preload`)

## Konteks

Versi awal plugin memuat halaman kosong, lalu menembakkan beberapa request AJAX
untuk mengisi tabel produk, dropdown kategori, tax class, shipping class, dan
saved views. Panel muncul satu per satu seiring respons berdatangan.

Setiap request ke `admin-ajax.php` memuat WordPress **secara penuh**: semua
plugin aktif, tema, dan seluruh rantai hook. Pada instalasi ini —
WooCommerce 10.9.4, Elementor Pro, Sejoli, dan lainnya — bootstrap itu jauh
lebih mahal daripada query yang dijalankan. Query kategori mungkin memakan 2 ms;
bootstrap yang mendahuluinya bisa 200 ms.

Lima request paralel berarti lima kali bootstrap penuh untuk data yang totalnya
beberapa puluh kilobyte.

## Keputusan

Semua data yang dibutuhkan render pertama dikirim bersama halaman lewat
`wp_localize_script()`:

```php
wp_localize_script('wc-bulk-editor-js', 'WCBulkEditor', [
    'preloaded'        => $this->get_initial_products(),   // 50 produk
    'all_cats'         => $this->get_all_categories(),
    'tax_classes'      => $this->get_tax_class_list(),
    'shipping_classes' => $this->get_shipping_class_list(),
    'views'            => $this->get_saved_views($uid),
    'columns'          => $this->get_user_columns($uid),
    // …
]);
```

`init()` memakai `WCB.preloaded` kalau tersedia, dan hanya memanggil AJAX kalau
preload gagal.

Aturan turunannya: **kalau sebuah data bisa dihitung saat enqueue, kirim di
situ — jangan buat endpoint AJAX untuk itu.**

## Konsekuensi

**Lebih mudah:**

- Render pertama tanpa satu pun request AJAX. Tabel sudah terisi begitu halaman
  tampil.
- Tidak ada panel yang muncul bergantian — `revealPanels()` menampilkan
  semuanya sekaligus.
- Query berjalan dalam bootstrap yang **memang sudah terjadi**, bukan memicu
  yang baru.
- Logika bersama: `build_product_payload()` dipakai preload maupun AJAX, jadi
  bentuk datanya dijamin identik.

**Lebih sulit:**

- Halaman admin jadi lebih besar. 50 produk × ~35 field ≈ 100–150 KB JSON
  tertanam di HTML.
- Waktu render halaman bertambah oleh query-query itu. Bertukar dengan waktu
  yang lebih besar di sisi AJAX — tapi tetap sebuah pertukaran.
- `enqueue_assets()` sekarang menjalankan enam query. Kalau salah satunya
  lambat, seluruh halaman ikut lambat.
- Duplikasi: endpoint `wc_bulk_get_categories`, `wc_bulk_get_tax_classes`,
  `wc_bulk_get_shipping_classes`, dan `wc_bulk_get_columns` masih ada tapi tidak
  terpanggil saat boot normal. Perlu dipelihara meski jarang dipakai.
- Data preload adalah snapshot. Kalau ada admin lain mengubah produk di antara
  render halaman dan interaksi pertama, tabel menampilkan data basi sampai
  paginasi atau filter dijalankan.

**Ambang batas peninjauan:** `MAX_PER_PAGE` 100 sudah mendekati batas. Kalau
per-page dinaikkan lebih jauh, payload tertanam bisa membuat parsing HTML jadi
hambatan baru. Ukur sebelum menaikkan.

## Alternatif yang ditolak

**Endpoint REST API tunggal untuk semua data awal.** Tetap satu bootstrap penuh
lebih banyak daripada nol. Menyelesaikan masalah "lima request" tapi tidak
menyelesaikan "request sama sekali".

**Transient untuk hasil query.** Mengurangi biaya query, tapi biaya sebenarnya
ada di bootstrap WordPress, bukan di query. Menyembuhkan gejala yang salah.

**Server-side rendering tabel di PHP.** Menghilangkan payload JSON dan
menghasilkan HTML langsung. Ditolak karena JS tetap harus merender ulang tabel
setiap paginasi, filter, dan perubahan kolom — sehingga logika render harus ada
di dua tempat dan wajib dijaga tetap sinkron. Sumber bug yang mahal.

**Preload sebagian (hanya produk).** Kompromi yang tidak menyelesaikan apa pun:
dropdown tetap butuh AJAX, panel tetap muncul bergantian.
