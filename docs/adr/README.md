# Architecture Decision Records

Catatan keputusan arsitektur: **kenapa** kode ini berbentuk seperti sekarang.

## Untuk apa folder ini

Kode menjelaskan *apa* yang dilakukan. Git menjelaskan *kapan* diubah. Yang
hilang adalah *kenapa* — dan itu justru yang paling mahal saat hilang.

Tanpa catatan ini, keputusan yang disengaja terlihat seperti kelalaian. Orang
berikutnya (termasuk kamu enam bulan lagi, atau Claude di sesi baru) akan
"memperbaiki" hal yang sebenarnya sudah dipertimbangkan — memecah file menjadi
banyak class, menambahkan bundler, mengganti `var` jadi `const`. Lalu
menemukan alasannya dengan cara yang mahal.

## Kapan menulis ADR

Tulis kalau keputusannya **sulit dibalik** atau **terlihat aneh dari luar**:

- Memilih satu pendekatan padahal ada alternatif yang lebih umum
- Sengaja tidak memakai sesuatu yang biasanya dipakai orang
- Menerima kelemahan yang diketahui demi keuntungan lain

**Jangan** tulis ADR untuk hal yang sudah jelas dari kode, atau untuk pilihan
yang bisa dibatalkan dalam lima menit.

## Format

Satu file per keputusan, dinomori berurutan: `0001-judul-singkat.md`.
Pendek — satu layar cukup. ADR yang panjang tidak dibaca.

```markdown
# NNNN. Judul keputusan

**Status:** Diterima | Digantikan oleh [NNNN](…) | Ditinjau ulang
**Tanggal:** YYYY-MM-DD

## Konteks
Situasi yang memaksa keputusan ini. Fakta, bukan opini.

## Keputusan
Apa yang dipilih. Kalimat aktif: "Kami memakai X."

## Konsekuensi
Apa yang jadi lebih mudah, apa yang jadi lebih sulit.
Bagian ini yang paling berguna — dan paling sering dilewatkan.

## Alternatif yang ditolak
Apa lagi yang dipertimbangkan, dan kenapa tidak dipilih.
```

## Mengubah keputusan

ADR **tidak dihapus dan tidak diedit isinya**. Kalau keputusan berubah, tulis
ADR baru dan ubah status yang lama menjadi *Digantikan oleh [NNNN]*.

Riwayat keputusan yang salah sama berharganya dengan keputusan yang benar — ia
mencegah orang mengulangi jalan buntu yang sama.

## Daftar

| # | Keputusan | Status |
|---|---|---|
| [0001](0001-monolit-satu-file.md) | PHP dalam satu file, tanpa namespace | Diterima |
| [0002](0002-tanpa-build-step.md) | Tanpa build step, JavaScript ES5 | Diterima |
| [0003](0003-preload-bukan-ajax.md) | Preload data lewat `wp_localize_script` | Diterima |
| [0004](0004-admin-ajax-bukan-rest.md) | `admin-ajax.php`, bukan REST API | Diterima |
| [0005](0005-menyimpang-dari-wpcs.md) | Menyimpang dari WordPress Coding Standards | Diterima |
| [0006](0006-tanpa-tabel-kustom.md) | Tanpa tabel database kustom | Diterima |

Enam ADR pertama bersifat **retroaktif** — ditulis 2026-07-30 untuk merekam
keputusan yang sudah diambil selama pengembangan v1 sampai v3.11.0. Tanggalnya
adalah tanggal pencatatan, bukan tanggal keputusan.
