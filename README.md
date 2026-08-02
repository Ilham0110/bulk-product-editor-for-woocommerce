# Bulk Product Editor for WooCommerce

Edit ratusan produk WooCommerce langsung dari satu tabel ala spreadsheet —
tanpa membuka halaman edit produk satu per satu.

**Versi 3.12.0**

---

## Kenapa Plugin Ini Ada

Mengubah harga 200 produk lewat admin WooCommerce bawaan berarti 200 kali buka
halaman, 200 kali klik Update, 200 kali tunggu reload. Plugin ini menampilkan
semuanya dalam satu tabel yang bisa diketik langsung, lalu menyimpan seluruh
perubahan dalam satu kali request.

## Fitur

**Inline editing**
Klik sel mana pun, ketik, lanjut ke sel berikutnya. Sel yang berubah ditandai
warna sampai disimpan. Tekan **Ctrl+S** (atau ⌘+S) untuk menyimpan semua
perubahan sekaligus. Tombol *Discard* mengembalikan semuanya ke nilai server.

**30 kolom yang bisa dipilih, 28 dapat disunting**
Dari nama produk, harga, stok, dan SKU sampai dimensi, tax class, shipping
class, visibility, purchase note, dan menu order. Pilih kolom yang kamu
butuhkan lewat tombol **Columns** — bisa di-drag untuk mengatur urutan.
Pilihan kolom disimpan per-user, jadi tiap admin punya layout sendiri.

Kolom **Product Name** menyertakan tautan ke halaman produk lengkap dan ID-nya,
sehingga baris tetap mudah dikenali meski namanya sedang diubah. Nama tidak
boleh dikosongkan.

**Filter & Saved Views**
Saring produk berdasarkan kata kunci (nama/SKU), kategori, tipe, status, status
stok, dan featured. Kombinasi filter yang sering dipakai bisa disimpan sebagai
**View** dan dipanggil lagi dengan satu klik.

**Quick Apply**
Ubah harga banyak produk sekaligus dengan operasi relatif: naikkan 10%, turunkan
Rp 5.000, atau set ke nilai tetap. Untuk perubahan yang lebih kompleks, tersedia
**Advanced Bulk Edit**.

**Bulk action**
Duplicate, pindahkan ke trash, atau hapus permanen produk terpilih.

**Quick Add**
Tambah produk baru langsung dari halaman ini tanpa pindah halaman.

**New Category**
Buat kategori produk baru dari dalam editor — tidak perlu buka halaman taxonomy.

**Export CSV**
Ekspor produk terpilih ke CSV (12 kolom: ID, nama, SKU, harga, stok, status,
tipe, kategori, tag, berat). Nilai yang diawali `=`, `+`, `-`, atau `@` diberi
prefix kutip otomatis supaya tidak dieksekusi sebagai formula saat file dibuka
di Excel atau Google Sheets.

## Kebutuhan Sistem

| Komponen | Minimum |
|---|---|
| PHP | 8.3 |
| WooCommerce | Wajib aktif — plugin tidak berjalan tanpanya |
| Hak akses | `manage_woocommerce` (Administrator & Shop Manager) |

Kompatibel dengan **HPOS** (High-Performance Order Storage).

## Instalasi

1. Salin folder `wc-bulk-editor` ke `wp-content/plugins/`.
2. Aktifkan lewat **Plugins** di admin WordPress.
3. Buka **WooCommerce → Bulk Editor**.

Tidak ada langkah build. Tidak ada dependency Composer atau npm — plugin
berjalan apa adanya.

## Cara Pakai

1. Buka **WooCommerce → Bulk Editor**. Tabel langsung tampil berisi 50 produk
   terbaru.
2. Atur filter bila perlu, klik **Apply Filters**.
3. Klik sel untuk mengedit. Sel yang berubah akan ditandai.
4. Klik **Save All** atau tekan **Ctrl+S**.

Untuk perubahan massal seperti diskon serentak, pakai panel **Quick Apply** di
atas tabel — pilih operasi, isi nilai, terapkan ke produk terpilih.

### Catatan penting

- **Mengisi Stock Qty otomatis mengaktifkan "Manage Stock"** pada produk
  tersebut. WooCommerce mengabaikan angka stok kalau manajemen stok mati, jadi
  plugin menyalakannya untuk kamu.
- **Sale price** divalidasi terhadap regular price.
- **Produk variable**: yang diedit di tabel ini adalah data produk induk.
  Harga dan stok per-variasi tetap diatur lewat halaman produk.
- Maksimal **100 produk per halaman**. Batas ini disengaja — lebih dari itu,
  browser mulai berat saat merender tabel dengan banyak kolom.

## Struktur Kode

```
wc-bulk-editor/
├── wc-bulk-editor.php    1224 baris  — seluruh logika PHP dalam satu class
├── uninstall.php           51 baris  — bersihkan preferensi saat plugin dihapus
├── views/admin-page.php   340 baris  — markup halaman admin
├── assets/admin.js       2136 baris  — seluruh UI (jQuery)
├── assets/admin.css       450 baris  — styling
├── languages/                        — wc-bulk-editor.pot, 156 string
├── docs/                             — arsitektur, keamanan, i18n, ADR
├── readme.txt                        — format WordPress.org
└── LICENSE                           — GPL v2
```

Detail arsitektur, alur data, dan aturan kontribusi ada di
[`CLAUDE.md`](CLAUDE.md).

## Keamanan

Setiap endpoint AJAX memverifikasi nonce dan capability `manage_woocommerce`
sebelum melakukan apa pun. Hapus dan trash memerlukan cek `delete_post`
per-produk; pembuatan kategori memerlukan `manage_product_terms`. Semua
perubahan produk ditulis lewat WooCommerce CRUD API, bukan `update_post_meta()`
langsung, sehingga hook dan cache WooCommerce tetap konsisten.

## Riwayat Pembersihan

Folder ini dulu menyimpan 47 file backup manual berpola `*.bak-before-*` dan
`assets/_css-backup/`. Karena tidak berekstensi `.php`, web server menyajikannya
sebagai teks biasa — siapa pun yang menebak URL-nya bisa membaca seluruh source
code.

Pada 2026-07-30 file-file itu dipindahkan ke `C:\laragon\backup-wcbulk\` (di
luar webroot) dan plugin ini mulai memakai git. `.gitignore` kini memblokir pola
tersebut agar tidak terulang.

## Lisensi

GPL-2.0-or-later, mengikuti WordPress dan WooCommerce.
