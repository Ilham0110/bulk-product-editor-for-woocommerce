# 0006. Tanpa tabel database kustom

**Status:** Diterima
**Tanggal:** 2026-07-30 (retroaktif)

## Konteks

Plugin menyimpan dua jenis preferensi per-user:

- **Kolom aktif** — kolom mana yang ditampilkan, dan urutannya
- **Saved views** — kombinasi filter yang diberi nama

Keduanya bersifat per-user, berukuran kecil (beberapa kilobyte), dan hanya
dibaca saat halaman editor dibuka.

Ada tiga tempat penyimpanan yang mungkin: tabel kustom, option, atau user meta.

## Keputusan

Simpan di **user meta**, dengan prefix `_wcbulk_`:

| Key | Isi |
|---|---|
| `_wcbulk_columns` | daftar kolom aktif |
| `_wcbulk_views` | saved views |

State UI yang murni kosmetik (panel terbuka/tertutup) disimpan di
`localStorage` browser, bukan dikirim ke server sama sekali.

Tanpa tabel kustom. Tanpa option global. Tanpa `dbDelta()`, tanpa versioning
skema, tanpa migrasi.

## Konsekuensi

**Lebih mudah:**

- Aktivasi tidak melakukan apa pun. Tidak ada hook aktivasi, tidak ada
  pembuatan tabel yang bisa gagal.
- Tidak ada kode migrasi yang harus dipelihara lintas versi. Tidak ada
  `_wcbulk_db_version` yang harus dicek setiap kali plugin dimuat.
- Data ikut berpindah bersama user saat migrasi situs — `wp_usermeta` termasuk
  dalam ekspor standar WordPress, tabel kustom tidak.
- Data ikut terhapus otomatis saat user dihapus. Tidak ada baris yatim.
- Naturalnya per-user. Setiap admin punya layout sendiri tanpa kolom `user_id`
  dan indeks yang perlu dikelola manual.
- Uninstall sederhana: hapus dua meta key.

**Lebih sulit:**

- Tidak bisa membuat query terhadap isinya. Tidak mungkin menjawab
  "view apa yang paling sering dipakai?" tanpa memindai seluruh `wp_usermeta`.
- Nilai disimpan sebagai PHP serialized. Tidak bisa diinspeksi lewat SQL biasa,
  dan berisiko rusak kalau ada yang mengeditnya langsung di phpMyAdmin.
- Tidak ada batas ukuran yang ditegakkan. User yang membuat 500 saved views
  akan menghasilkan satu baris meta yang besar — dimuat seluruhnya setiap kali
  halaman dibuka.
- Tidak bisa berbagi view antar user. Setiap admin membangun view-nya
  sendiri-sendiri.
- Tidak ada validasi skema. Bentuk data hanya dijaga oleh kode yang menulisnya.

**Ambang batas peninjauan:** kalau muncul kebutuhan view bersama antar user,
riwayat perubahan (audit log siapa mengubah harga apa), atau pengaturan
tingkat-toko, tabel kustom jadi masuk akal. Sampai saat itu, tabel kustom
berarti menambah kode migrasi tanpa manfaat.

## Alternatif yang ditolak

**Tabel kustom `wp_wcbulk_views`.** Memungkinkan query, indeks, dan skema yang
tegas. Ditolak karena membawa serta seluruh perangkat migrasi: `dbDelta()`
dengan segala keanehannya (spasi ganda wajib, `KEY` bukan `INDEX`), option
versi skema, jalur upgrade, dan penanganan uninstall. Untuk menyimpan satu
daftar nama kolom, biayanya jauh melebihi manfaatnya.

**Option global (`wp_options`).** Lebih sederhana untuk ditulis, tapi salah
secara konseptual — preferensi ini milik user, bukan milik situs. Dua admin
akan saling menimpa pilihan kolom masing-masing. Option ber-autoload juga ikut
dimuat di **setiap** request WordPress, termasuk seluruh halaman frontend yang
tidak ada hubungannya dengan editor ini.

**`localStorage` untuk semuanya.** Nol permintaan ke server. Ditolak karena
preferensi jadi hilang saat ganti browser atau perangkat, dan tidak bisa
diakses PHP saat preload — sehingga tabel akan tampil dengan kolom default lalu
"melompat" ke kolom pilihan user setelah JS berjalan. `localStorage` tetap
dipakai, tapi hanya untuk state kosmetik yang tidak masalah kalau hilang.
