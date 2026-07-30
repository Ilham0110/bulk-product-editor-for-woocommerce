=== Bulk Product Editor for WooCommerce ===
Contributors: ilhamdarmawan
Tags: woocommerce, bulk edit, products, inline edit, spreadsheet
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 3.11.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Edit ratusan produk WooCommerce dari satu tabel ala spreadsheet, tanpa membuka halaman edit satu per satu.

== Description ==

Mengubah harga 200 produk lewat admin WooCommerce bawaan berarti 200 kali buka halaman, 200 kali klik Update, 200 kali tunggu reload.

Plugin ini menampilkan semua produk dalam satu tabel yang bisa diketik langsung, lalu menyimpan seluruh perubahan dalam satu kali request.

**Inline editing**

Klik sel mana pun, ketik, lanjut ke sel berikutnya. Sel yang berubah ditandai warna sampai disimpan. Tekan Ctrl+S (atau Cmd+S) untuk menyimpan semua perubahan sekaligus.

**29 kolom yang bisa dipilih**

Dari harga, stok, dan SKU sampai dimensi, tax class, shipping class, visibility, purchase note, dan menu order. Pilihan kolom disimpan per-user, jadi tiap admin punya layout sendiri.

**Filter dan Saved Views**

Saring berdasarkan kata kunci, kategori, tipe, status, status stok, dan featured. Kombinasi filter yang sering dipakai bisa disimpan sebagai View.

**Quick Apply**

Ubah harga banyak produk sekaligus: naikkan 10%, turunkan nominal tetap, atau set ke nilai tertentu.

**Fitur lain**

* Bulk action: duplicate, trash, hapus permanen
* Quick Add produk baru tanpa pindah halaman
* Buat kategori baru langsung dari editor
* Export CSV dengan proteksi formula injection

== Installation ==

1. Unggah folder `wc-bulk-editor` ke `/wp-content/plugins/`
2. Aktifkan lewat menu **Plugins** di admin WordPress
3. Buka **WooCommerce > Bulk Editor**

WooCommerce wajib aktif. Tidak ada langkah build, tidak ada dependency Composer atau npm.

== Frequently Asked Questions ==

= Apakah mendukung produk variable? =

Yang diedit adalah data produk induk. Harga dan stok per-variasi tetap diatur lewat halaman produk masing-masing.

= Kenapa kolom Stock Qty tidak bisa diisi? =

Produk itu punya "Manage Stock" mati. Mengisi jumlah stok akan otomatis menyalakannya — WooCommerce mengabaikan angka stok kalau manajemen stok tidak aktif.

= Apakah kompatibel dengan HPOS? =

Ya. Plugin mendeklarasikan kompatibilitas dengan High-Performance Order Storage, meski sebenarnya hanya menyentuh produk, bukan order.

= Berapa maksimal produk per halaman? =

100. Batas ini disengaja — lebih dari itu browser mulai berat merender tabel dengan banyak kolom.

= Siapa yang bisa mengakses? =

Pengguna dengan capability `manage_woocommerce` (Administrator dan Shop Manager). Menghapus produk memerlukan izin `delete_post` per produk, dan menyunting memerlukan `edit_post`.

= Apakah data saya ikut terhapus kalau plugin di-uninstall? =

Hanya preferensi plugin ini (pilihan kolom dan saved views). Produk, kategori, dan data WooCommerce lain tidak disentuh.

== Screenshots ==

1. Tabel editor dengan inline editing dan penanda perubahan
2. Modal pemilihan dan pengurutan kolom
3. Panel Quick Apply untuk perubahan harga massal

== Changelog ==

= 3.11.0 =
* Keamanan: label option tax class dan shipping class kini di-escape. Sebelumnya nama tax class yang mengandung HTML dapat dieksekusi di browser.
* Keamanan: penyuntingan produk kini memeriksa capability `edit_post` per produk, menyamai pemeriksaan yang sudah ada pada trash dan delete.
* Perbaikan: option pada dropdown tax class dan shipping class tidak lagi berlipat ganda setiap tabel dirender ulang.
* Perbaikan: option yang terpilih kini ditandai dengan perbandingan nilai, bukan manipulasi string yang bisa salah target.
* Ditambahkan `uninstall.php` — preferensi per-user kini dibersihkan saat plugin dihapus.
* Seluruh teks antarmuka JavaScript kini dapat diterjemahkan.
* Header plugin dilengkapi: `Requires Plugins`, `Domain Path`, `License`, dan informasi kompatibilitas WooCommerce.

= 3.10.0 =
* Data halaman pertama dimuat bersama halaman, sehingga tabel tampil tanpa menunggu AJAX.

== Upgrade Notice ==

= 3.11.0 =
Berisi perbaikan keamanan (escaping dan pemeriksaan izin). Disarankan memperbarui.
