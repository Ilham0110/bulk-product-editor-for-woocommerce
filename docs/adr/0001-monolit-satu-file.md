# 0001. PHP dalam satu file, tanpa namespace

**Status:** Diterima
**Tanggal:** 2026-07-30 (retroaktif — keputusan diambil sejak v1)

## Konteks

Seluruh logika PHP plugin ini ada dalam satu file 852 baris
(`wc-bulk-editor.php`), berisi satu class `WC_Bulk_Product_Editor` tanpa
namespace: bootstrap, menu admin, enqueue aset, 14 handler AJAX, dan semua
logika penulisan produk.

Praktik yang lazim untuk plugin sebesar ini adalah memecahnya menjadi
`includes/class-admin.php`, `includes/class-ajax.php`,
`includes/class-product-writer.php`, dengan namespace PSR-4 dan autoloader
Composer.

Plugin ini adalah alat internal untuk satu toko. Dipasang dengan cara menyalin
folder, sering diedit langsung di server saat ada masalah, dan dikembangkan
oleh satu orang.

## Keputusan

Semua PHP tetap dalam satu file dengan satu class. Tanpa namespace, tanpa
autoloader, tanpa direktori `includes/`.

Struktur di dalam file dijaga dengan komentar pembatas seksi:

```php
// ---------------------------------------------------------------------
// AJAX: read
// ---------------------------------------------------------------------
```

## Konsekuensi

**Lebih mudah:**

- Membaca alur lengkap tanpa melompat antar file. Dari nonce sampai
  `$product->save()` semuanya terlihat dalam satu buffer.
- Deploy: satu file disalin, selesai. Tidak ada `composer install`, tidak ada
  urusan autoloader.
- Debugging di server: buka satu file, semua ada di situ.
- Pencarian: `grep` satu file mengembalikan seluruh konteks.

**Lebih sulit:**

- File 852 baris melebihi batas nyaman satu layar. Navigasi bergantung pada
  komentar seksi dan pencarian.
- Tidak ada batas modul yang dipaksakan compiler. Yang mencegah percampuran
  tanggung jawab hanyalah disiplin.
- Unit testing per komponen praktis tidak mungkin — semuanya `private` di dalam
  satu class.
- Class `WC_Bulk_Product_Editor` bisa bentrok kalau ada plugin lain memakai nama
  identik. Risikonya sangat kecil karena namanya spesifik, tapi namespace akan
  menghilangkannya sepenuhnya.

**Ambang batas peninjauan:** kalau file melewati ~1200 baris atau ada orang
kedua mulai mengerjakan plugin ini secara rutin, keputusan ini perlu ditinjau
ulang. Sampai itu terjadi, memecah file berarti menambah kerumitan tanpa
manfaat yang setara.

## Alternatif yang ditolak

**PSR-4 + Composer autoloader.** Menambahkan folder `vendor/` dan langkah
`composer install` ke plugin yang saat ini bisa disalin apa adanya. Untuk satu
class, autoloader hanya menambah lapisan tanpa mengurangi apa pun.

**Beberapa file dengan `require`.** Mendapat file yang lebih kecil, tapi
kehilangan keuntungan utama (alur terbaca dalam satu tempat) tanpa mendapat
keuntungan namespace. Pilihan paling buruk dari kedua sisi.

**Namespace tanpa autoloader.** Menyelesaikan risiko bentrok nama, tapi
memaksa semua kelas WooCommerce ditulis dengan prefix `\` atau di-`use` — dan
ada puluhan referensi seperti itu. Biaya keterbacaan tidak sebanding dengan
risiko yang dihilangkan.
