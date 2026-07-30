# 0002. Tanpa build step, JavaScript ES5

**Status:** Diterima
**Tanggal:** 2026-07-30 (retroaktif)

## Konteks

`assets/admin.js` berisi 1741 baris JavaScript ES5: `var` di 123 tempat, nol
`const`/`let`, nol arrow function, nol template literal. Dimuat browser apa
adanya lewat `wp_enqueue_script()`.

Praktik lazim untuk plugin WordPress 2026 adalah `@wordpress/scripts`: JSX,
modul ES, bundling webpack, dan output ke `build/`.

UI plugin ini adalah tabel dengan sel yang bisa diedit. Interaksinya:
pelacakan perubahan, beberapa modal, dan penghitungan lebar kolom. jQuery dan
jquery-ui-sortable sudah tersedia di admin WordPress tanpa perlu di-bundle.

## Keputusan

Tanpa build step. JavaScript ditulis ES5 dengan jQuery, dimuat langsung.

Konsistensi ES5 ditegakkan sebagai aturan — bukan karena keterbatasan browser
(semua browser modern mendukung ES6), tapi karena satu gaya yang konsisten di
1741 baris lebih mudah dibaca daripada campuran dua gaya.

## Konsekuensi

**Lebih mudah:**

- Edit file, muat ulang browser, selesai. Tidak ada watcher, tidak ada
  kompilasi.
- Nomor baris di Console DevTools menunjuk ke kode asli. Tidak perlu source map.
- Perbaikan darurat bisa dilakukan langsung di server.
- Tidak ada `node_modules/` — tidak ada 200 MB dependency, tidak ada
  audit kerentanan npm, tidak ada build yang rusak karena versi Node berubah.
- Tidak ada langkah build yang bisa gagal di mesin orang lain.

**Lebih sulit:**

- Semua kode terkirim dalam satu file 77 KB tanpa minifikasi. Untuk halaman
  admin yang dibuka sesekali, ini tidak berarti.
- Tidak bisa memakai React, JSX, atau komponen `@wordpress/components`. Kalau
  UI berkembang ke arah yang butuh state management sungguhan, keputusan ini
  jadi penghalang.
- Tidak ada tree-shaking atau code splitting.
- `var` punya function scope, bukan block scope. Butuh kehati-hatian di dalam
  loop yang membuat closure.
- Tidak bisa memakai perkakas yang mengandaikan modul ES (ESLint dengan
  konfigurasi modern, Prettier plugin tertentu).

**Ambang batas peninjauan:** kalau UI mulai butuh state bersama yang kompleks
antar komponen, atau kalau plugin akan disubmit ke WordPress.org (di mana
struktur berbasis block lebih diharapkan), keputusan ini perlu ditinjau ulang.

## Alternatif yang ditolak

**`@wordpress/scripts` + React.** Perkakas resmi WordPress dan pilihan tepat
untuk UI berbasis komponen. Ditolak karena menambahkan `node_modules/`, langkah
build, dan source map demi UI yang sudah bekerja tanpa semua itu. Menulis ulang
1741 baris yang berfungsi menjadi React adalah pekerjaan berminggu-minggu tanpa
satu pun fitur baru untuk pengguna.

**ES6 tanpa build step.** Browser modern mendukungnya, jadi secara teknis bisa.
Ditolak karena akan menghasilkan file campuran: 1741 baris `var` ditambah kode
baru `const`. Campuran itu lebih buruk daripada ES5 yang konsisten. Kalau mau
pindah ke ES6, seluruh file harus dikonversi sekaligus — dan itu keputusan
tersendiri.

**Minifikasi saja, tanpa transpiling.** Menghemat ~40 KB pada halaman admin
yang dibuka beberapa kali sehari. Sebagai gantinya, nomor baris di Console jadi
tidak berguna saat debugging. Pertukaran yang merugikan.
