<?php
// phpcs:ignoreFile -- alat baris perintah, bukan kode plugin. Lihat catatan di bawah.
/**
 * Membangun ZIP rilis dari isi folder ini.
 *
 * Pakai:  php -d extension=zip build.php
 *
 * Hasilnya <slug>-build/<slug>.<versi>.zip di samping folder plugin, berisi
 * satu folder <slug>/ — bentuk yang diharapkan WordPress pada
 * Plugins > Add New > Upload Plugin.
 *
 * Ditulis dalam PHP, bukan shell, karena PHP sudah pasti ada di mana pun
 * plugin ini dikembangkan, sementara rsync dan zip tidak (keduanya tidak ada
 * di Git Bash bawaan Windows).
 *
 * Daftar pengecualian dibaca dari .distignore, tidak ditulis ulang di sini,
 * supaya tidak ada dua daftar yang bisa berbeda diam-diam.
 *
 * CATATAN soal `phpcs:ignoreFile` di baris pertama.
 *
 * Berkas ini alat baris perintah, bukan bagian dari plugin. Ia tidak pernah
 * dimuat WordPress, tidak pernah menerima request HTTP, dan tidak ikut ke
 * paket rilis (lihat .distignore). Plugin Check tetap memindainya karena ada
 * di dalam folder plugin, lalu menerapkan aturan yang tidak sesuai konteks:
 *
 *   - "output harus di-escape" — keluarannya ke terminal, bukan HTML. Membungkus
 *     nama berkas dengan esc_html() justru merusak pesannya.
 *   - "jangan menulis ke folder plugin" — tepat untuk kode yang berjalan saat
 *     runtime, karena folder plugin terhapus saat upgrade. Tapi menulis
 *     paket build memang seluruh tugas berkas ini, dan ia dijalankan manual
 *     oleh pengembang.
 *   - "prefiks semua global" — tidak ada ruang nama WordPress yang bisa
 *     ditabrak dalam skrip yang berjalan sendiri.
 *
 * Karena itu seluruh berkas dikecualikan, bukan tiap barisnya diakali. Kalau
 * suatu saat berkas ini dipindahkan ke luar folder plugin, baris
 * `phpcs:ignoreFile` bisa dihapus.
 *
 * @package BulkProductEditorForWooCommerce
 */

declare(strict_types=1);

// Berkas ini ada di dalam webroot, jadi web server menyajikannya seperti PHP
// biasa — diuji dengan curl, hasilnya HTTP 200. Tanpa penjaga ini siapa pun
// bisa memicu build lewat URL: menghapus lalu menulis ulang sebuah folder di
// samping direktori plugin, berulang kali, tanpa login.
//
// Dua kondisi, dan keduanya perlu:
//
//   PHP_SAPI !== 'cli'      penjaga yang sebenarnya. Berkas ini alat baris
//                           perintah; apa pun yang datang lewat web server
//                           ditolak, terlepas dari WordPress dimuat atau tidak.
//
//   defined('ABSPATH')      selalu false di sini, karena berkas ini tidak
//                           pernah dimuat WordPress. Ditulis eksplisit supaya
//                           Plugin Check mengenali pola perlindungan akses
//                           langsung yang dicarinya; tanpa itu ia melaporkan
//                           ERROR meski berkasnya sudah aman.
if (PHP_SAPI !== 'cli' || defined('ABSPATH')) {
    http_response_code(403);
    exit('Berkas ini hanya bisa dijalankan dari baris perintah.');
}

const SLUG = 'bulk-product-editor-for-woocommerce';

$src = __DIR__;

// Hasil build ditaruh SATU TINGKAT DI ATAS folder plugin, bukan di dalamnya.
//
// Plugin Check memindai filesystem, bukan indeks git, dan sebuah .zip di dalam
// folder plugin adalah ERROR baginya ("Compressed files are not permitted") —
// sekalipun .gitignore sudah mengabaikannya. Menaruh keluaran di luar membuat
// pemeriksaan itu bersih tanpa perlu menghapus paket setiap kali selesai.
$out   = dirname($src) . '/' . SLUG . '-build';
$stage = $out . '/' . SLUG;

/** Cetak dan hentikan. */
function fail(string $message): never
{
    fwrite(STDERR, "GAGAL: {$message}\n");
    exit(1);
}

/* -------------------------------------------------------------------------
   1. Versi

   Diambil dari header plugin, bukan dari konstanta terpisah, supaya nama
   berkas tidak bisa menyimpang dari yang dilaporkan WordPress.
   ------------------------------------------------------------------------- */
$main = @file_get_contents($src . '/' . SLUG . '.php') ?: fail('berkas utama tidak terbaca.');

preg_match('/^ \* Version: *(.+)$/m', $main, $m) || fail('header Version tidak ditemukan.');
$version = trim($m[1]);

$readme = @file_get_contents($src . '/readme.txt') ?: fail('readme.txt tidak terbaca.');

preg_match('/^Stable tag: *(.+)$/m', $readme, $m) || fail('Stable tag tidak ditemukan di readme.txt.');
$stable = trim($m[1]);

// Kalau keduanya berbeda, WordPress.org menyajikan versi yang salah kepada
// pengguna. Lebih baik ketahuan di sini daripada setelah rilis.
if ($version !== $stable) {
    fail("Version header ({$version}) tidak sama dengan Stable tag readme.txt ({$stable}).");
}

echo 'Membangun ' . SLUG . " {$version}\n";

/* -------------------------------------------------------------------------
   2. Pola pengecualian
   ------------------------------------------------------------------------- */
$patterns = [];

foreach (file($src . '/.distignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);

    if ($line !== '' && !str_starts_with($line, '#')) {
        $patterns[] = $line;
    }
}

$patterns[] = 'build';

/**
 * Apakah path relatif ini dikecualikan?
 *
 * Sebuah pola cocok bila ia sama dengan salah satu segmen path (sehingga
 * `docs` menutup seluruh isinya), atau bila fnmatch cocok dengan nama berkas
 * (untuk pola bertanda bintang seperti `*.bak`).
 */
function excluded(string $relative, array $patterns): bool
{
    $segments = explode('/', $relative);
    $basename = end($segments);

    foreach ($patterns as $pattern) {
        if (in_array($pattern, $segments, true)) {
            return true;
        }

        if (str_contains($pattern, '*') && fnmatch($pattern, $basename)) {
            return true;
        }
    }

    return false;
}

/* -------------------------------------------------------------------------
   3. Salin ke staging
   ------------------------------------------------------------------------- */
/** Hapus folder beserta isinya. */
function rmtree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($dir);
}

rmtree($out);
mkdir($stage, 0777, true);

$copied  = 0;
$skipped = 0;

$walk = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST,
);

foreach ($walk as $item) {
    $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($src) + 1));

    if (excluded($relative, $patterns)) {
        if ($item->isFile()) {
            $skipped++;
        }

        continue;
    }

    $target = $stage . '/' . $relative;

    if ($item->isDir()) {
        is_dir($target) || mkdir($target, 0777, true);

        continue;
    }

    is_dir(dirname($target)) || mkdir(dirname($target), 0777, true);
    copy($item->getPathname(), $target);
    $copied++;
}

echo "  {$copied} berkas disalin, {$skipped} dikecualikan\n";

/* -------------------------------------------------------------------------
   4. Verifikasi isi paket

   Dua arah: yang wajib ada, dan yang wajib TIDAK ada. Sebuah paket yang
   kehilangan admin.js sama rusaknya dengan paket yang membawa serta docs/,
   jadi keduanya diperiksa.
   ------------------------------------------------------------------------- */
$required = [
    SLUG . '.php',
    'readme.txt',
    'uninstall.php',
    'LICENSE',
    'assets/admin.js',
    'assets/admin.css',
    'views/admin-page.php',
    'languages/' . SLUG . '.pot',
];

$forbidden = ['.git', '.gitignore', '.distignore', 'CLAUDE.md', 'README.md', 'docs', 'build.php'];

$problems = [];

foreach ($required as $path) {
    is_file($stage . '/' . $path) || $problems[] = "berkas wajib hilang: {$path}";
}

foreach ($forbidden as $path) {
    file_exists($stage . '/' . $path) && $problems[] = "berkas pengembangan ikut terbawa: {$path}";
}

if ($problems !== []) {
    fwrite(STDERR, implode("\n", array_map(static fn($p) => "GAGAL: {$p}", $problems)) . "\n");
    exit(1);
}

/* -------------------------------------------------------------------------
   5. Kemas

   Harus ZipArchive, dan ini bukan sekadar preferensi.

   Spesifikasi ZIP mewajibkan pemisah path berupa "/". PowerShell
   Compress-Archive di Windows menulis "\" — arsip yang dihasilkan terbuka
   normal di Windows, tapi pada server Linux seluruh path bisa terbaca sebagai
   satu nama berkas, sehingga plugin terpasang sebagai berkas tunggal bernama
   "plugin\assets\admin.js" alih-alih struktur folder. Kesalahan seperti ini
   tidak terlihat sampai seseorang memasangnya di server sungguhan.

   Karena itu ZipArchive dijadikan syarat, bukan pilihan pertama dari
   beberapa. Ekstensi zip sering nonaktif pada PHP bawaan Laragon/XAMPP, jadi
   skrip ini memuatnya sendiri saat berjalan — tidak perlu menyunting php.ini.
   ------------------------------------------------------------------------- */
if (!class_exists('ZipArchive') && function_exists('dl')) {
    @dl(PHP_SHLIB_SUFFIX === 'dll' ? 'php_zip.dll' : 'zip.so');
}

if (!class_exists('ZipArchive')) {
    fail(
        "ekstensi zip PHP tidak aktif.\n"
        . "       Jalankan ulang dengan ekstensi dimuat:\n"
        . '         php -d extension=zip ' . basename(__FILE__) . "\n"
        . "       (atau hapus tanda ; pada baris extension=zip di php.ini)"
    );
}

$zip     = $out . '/' . SLUG . '.' . $version . '.zip';
$archive = new ZipArchive();

$archive->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true
    || fail("tidak bisa membuat {$zip}.");

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
);

foreach ($files as $file) {
    $archive->addFile(
        $file->getPathname(),
        SLUG . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($stage) + 1)),
    );
}

$archive->close();

// Baca kembali arsipnya. Kalau ada satu saja entri yang mengandung "\",
// paketnya rusak untuk server Linux dan tidak boleh dikirim.
$verify = new ZipArchive();
$verify->open($zip) === true || fail('arsip yang baru dibuat tidak bisa dibuka kembali.');

$entries = [];

for ($i = 0; $i < $verify->numFiles; $i++) {
    $entries[] = $verify->getNameIndex($i);
}

$verify->close();

foreach ($entries as $entry) {
    if (str_contains($entry, '\\')) {
        fail("entri arsip memakai pemisah Windows: {$entry}");
    }

    if (!str_starts_with($entry, SLUG . '/')) {
        fail("entri arsip tidak berada di dalam folder " . SLUG . '/: ' . $entry);
    }
}

printf(
    "  dikemas: %d entri, semuanya di dalam %s/\n\nSelesai: %s\nUkuran : %s KB\n",
    count($entries),
    SLUG,
    $zip,
    number_format(filesize($zip) / 1024, 1),
);
