# 0005. Menyimpang dari WordPress Coding Standards

**Status:** Diterima
**Tanggal:** 2026-07-30 (retroaktif)

## Konteks

WordPress Coding Standards (WPCS) adalah standar resmi ekosistem WordPress.
Plugin ini melanggarnya di beberapa titik mendasar — dan melanggarnya secara
konsisten:

| Aspek | WPCS | Plugin ini |
|---|---|---|
| Indentasi | tab | 4 spasi (830 baris, nol tab) |
| Yoda condition | wajib | tidak dipakai |
| `declare(strict_types=1)` | tidak lazim | wajib di setiap file |
| Type hint | opsional | wajib, parameter dan return |
| First-class callable | belum didukung penuh | dipakai luas |
| `match()` | belum didukung penuh | dipakai untuk dispatch |

Kode ini memakai idiom PHP 8.3 yang sebagian ditandai WPCS sebagai pelanggaran
atau belum dikenali oleh sniff-nya.

## Keputusan

Ikuti gaya internal yang sudah ada. Jangan pasang PHPCS dengan ruleset WPCS.

Gaya internal didokumentasikan di
[CODING-STANDARDS.md](../CODING-STANDARDS.md) dan ditegakkan lewat pembacaan,
bukan mesin.

## Konsekuensi

**Lebih mudah:**

- Bisa memakai PHP modern sepenuhnya: `match(true)` untuk dispatch field,
  `??=` untuk memoisasi, `$this->method(...)` untuk pendaftaran hook, arrow
  function bertipe lengkap.
- `declare(strict_types=1)` menangkap bug type coercion yang tidak terdeteksi
  WPCS. Ini keuntungan korektnes nyata, bukan preferensi gaya.
- Type hint di seluruh method membuat PHPStan bisa bekerja efektif kalau nanti
  dipasang.
- Tidak ada ribuan peringatan PHPCS yang harus di-`ignore` satu per satu.

**Lebih sulit:**

- **Tidak bisa disubmit ke WordPress.org tanpa penulisan ulang besar.** Plugin
  Check (PCP) akan menandai indentasi dan beberapa idiom. Ini biaya nyata,
  bukan teoretis.
- Kontributor yang terbiasa WPCS akan menulis dengan gaya berbeda kalau tidak
  membaca dokumentasi lebih dulu.
- Tidak ada penegakan otomatis. Gaya bertahan hanya selama orang membaca kode
  yang ada sebelum menulis kode baru.
- Editor yang dikonfigurasi untuk WPCS akan otomatis mengubah spasi jadi tab,
  merusak konsistensi tanpa disadari.

**Ambang batas peninjauan:** kalau plugin akan disubmit ke WordPress.org atau
dirilis publik, konversi ke WPCS jadi wajib. Rencanakan sebagai pekerjaan
tersendiri — bukan sesuatu yang dikerjakan sambil menambah fitur.

## Alternatif yang ditolak

**Konversi penuh ke WPCS.** Mengubah 852 baris PHP dan 1741 baris JS. Nol bug
terperbaiki, nol fitur bertambah, dan riwayat `git blame` jadi tidak berguna
karena setiap baris tercatat berubah. Untuk alat internal satu toko, biayanya
tidak sebanding.

**Ruleset PHPCS kustom.** Memakai WPCS sebagai dasar lalu menonaktifkan sniff
yang bertabrakan (indentasi, Yoda, dan lainnya). Secara teknis bisa. Ditolak
karena daftar pengecualiannya panjang dan membingungkan: ruleset yang mengklaim
"WPCS" tapi mematikan setengah aturannya lebih menyesatkan daripada tidak
memakai PHPCS sama sekali.

**PHPCS dengan standar PSR-12.** Lebih dekat ke gaya yang dipakai (4 spasi,
konvensi modern), tapi PSR-12 mengharuskan `PascalCase` untuk method sementara
kode ini memakai `snake_case` mengikuti konvensi WordPress. Setengah cocok
berarti tetap harus dikonfigurasi ulang — dan hasilnya bukan PSR-12 lagi.

**Tanpa dokumentasi gaya sama sekali.** Yang berlaku sebelum dokumen ini
ditulis. Ditolak karena gaya yang tidak tertulis akan luntur — terutama saat
Claude Code menulis kode berdasarkan pola umum WordPress dari data
pelatihannya, bukan pola file ini.
