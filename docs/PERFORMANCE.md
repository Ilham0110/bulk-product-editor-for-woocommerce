# Performa

Pola query, caching, pemuatan aset, dan cron.

Semua angka di dokumen ini **diukur dari instalasi ini** (`larisdigital`),
bukan estimasi.

---

## 1. Ukuran Toko Saat Ini

```
Produk               : 15 (semua publish)
Variasi              : 0
Kategori produk      : 10
Baris postmeta       : 2.086
Option autoload      : 210 baris / 79 KB
Plugin aktif         : 7
```

**Ini toko kecil.** Semua pola di plugin ini bekerja baik pada skala segini —
tidak ada yang perlu dioptimalkan sekarang.

Angka itu penting untuk konteks: bagian 3 membahas hal-hal yang **akan** jadi
masalah pada ribuan produk, tapi belum menjadi masalah di sini. Jangan
mengoptimalkan sesuatu yang belum terukur lambat.

---

## 2. Biaya Sebenarnya: Bootstrap, Bukan Query

Ini prinsip yang membentuk seluruh arsitektur plugin.

Setiap request ke `admin-ajax.php` memuat WordPress **penuh**: 7 plugin aktif,
tema, seluruh rantai hook. Pada instalasi ini itu berarti WooCommerce 10.9.4,
Elementor Pro, Sejoli, dan lainnya — untuk kemudian menjalankan satu query
yang mungkin memakan 2 ms.

Perbandingan kasarnya:

| Operasi | Biaya |
|---|---|
| Bootstrap WordPress + 7 plugin | ~150–300 ms |
| `get_terms()` untuk 10 kategori | ~1–3 ms |
| `wc_get_products()` 50 produk | ~20–50 ms |

**Bootstrap 10× lebih mahal daripada query yang dijalankannya.**

Konsekuensinya, aturan utama plugin ini:

> Kalau sebuah data bisa dihitung saat halaman dirender, kirim di situ.
> Jangan buat endpoint AJAX untuk itu.

Yang dilakukan `enqueue_assets()`:

```php
wp_localize_script('wc-bulk-editor-js', 'WCBulkEditor', [
    'preloaded'        => $this->get_initial_products(),   // 50 produk
    'all_cats'         => $this->get_all_categories(),
    'tax_classes'      => $this->get_tax_class_list(),
    'shipping_classes' => $this->get_shipping_class_list(),
    'views'            => $this->get_saved_views($uid),
    'columns'          => $this->get_user_columns($uid),
]);
```

Enam query dalam satu bootstrap yang **memang sudah terjadi**, menggantikan
lima bootstrap terpisah. Detail keputusannya di
[ADR 0003](adr/0003-preload-bukan-ajax.md).

Ini juga alasan `MAX_PER_PAGE = 100`: menaikkannya mengurangi jumlah
round-trip, tapi memperbesar payload dan beban render browser. Angka 100 adalah
titik tengah yang sudah diuji — jangan diubah tanpa mengukur ulang.

---

## 3. Pola Query

### a. N+1 — kesalahan paling mahal

Pola yang benar sudah dipakai di `collect_terms()`:

❌ Dua query per produk — 100 query untuk satu halaman:
```php
foreach ($products as $p) {
    $cats = wp_get_object_terms($p->get_id(), 'product_cat');
    $tags = wp_get_object_terms($p->get_id(), 'product_tag');
}
```

✅ Satu query untuk seluruh halaman:
```php
$terms = wp_get_object_terms(
    $ids,
    ['product_cat', 'product_tag'],
    ['fields' => 'all_with_object_id']
);
```

`all_with_object_id` membuat tiap term membawa `object_id`-nya, sehingga bisa
dipetakan kembali per produk.

**Ikuti pola ini untuk data per-produk apa pun yang ditambahkan nanti.** Kalau
menambah kolom yang butuh data dari tabel lain, ambil sekali untuk seluruh
halaman — jangan di dalam loop.

### b. Memoisasi per-request

```php
private ?string $placeholder_image = null;

private function placeholder_image(): string
{
    return $this->placeholder_image ??= (string) wc_placeholder_img_src('thumbnail');
}
```

`wc_placeholder_img_src()` membaca tabel options. Tanpa memoisasi, itu terjadi
sekali per baris — 50 kali untuk satu halaman.

Pola `??=` ini murah dan tepat untuk nilai yang sama sepanjang request. Pakai
kapan pun ada fungsi mahal dipanggil di dalam loop.

### c. Duplikasi kerja yang ada

`get_all_categories()` dipanggil di dua tempat:

- baris 202 — saat preload
- baris 580 — di akhir `wc_bulk_create_category()`

Yang kedua wajar: setelah membuat kategori baru, daftar harus dikirim ulang.
Tapi keduanya menjalankan `get_terms()` penuh tanpa cache.

Pada 10 kategori ini tidak terasa. Pada 500 kategori, setiap pembuatan kategori
berarti membaca ulang semuanya. Kalau nanti jadi masalah, memoisasi seperti
`placeholder_image()` sudah cukup — bukan transient.

### d. Batas yang belum ada

`get_all_categories()` tidak punya limit:

```php
$terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name']);
```

Toko dengan 2.000 kategori akan mengirim semuanya ke browser di setiap
pemuatan halaman. Pada 10 kategori, non-isu.

Ambang perhatian: sekitar **500 kategori**. Di atas itu, dropdown perlu diganti
pencarian AJAX (select2 dengan `minimumInputLength`), bukan daftar penuh.

### e. `'paginate' => true` wajib

```php
$q = wc_get_products(['limit' => 50, 'page' => 1, 'paginate' => true, …]);
$q->products;        // list<WC_Product>
$q->total;           // int
$q->max_num_pages;   // int
```

Tanpa flag itu, `wc_get_products()` mengembalikan array biasa dan `$q->products`
fatal error. Lihat [WOOCOMMERCE.md](WOOCOMMERCE.md#4-query-produk).

### f. `limit => -1` — jangan

```php
wc_get_products(['limit' => -1]);   // memuat SEMUA produk sebagai objek
```

Setiap `WC_Product` membawa seluruh meta-nya. Pada 5.000 produk ini menghabiskan
memori PHP sebelum sempat mengembalikan apa pun.

Kalau butuh memproses semua produk, lakukan bertahap:

```php
$page = 1;

do {
    $batch = wc_get_products([
        'limit'    => 100,
        'page'     => $page++,
        'paginate' => true,
        'return'   => 'ids',        // ← ID saja, jauh lebih ringan
    ]);

    foreach ($batch->products as $id) {
        // proses
    }
} while ($page <= $batch->max_num_pages);
```

`'return' => 'ids'` melewatkan hidrasi objek sepenuhnya. Pakai kapan pun kamu
hanya butuh ID.

### g. Menulis: satu `save()` per produk

❌ 12 write untuk satu produk:
```php
foreach ($fields as $f => $v) {
    $product->{"set_$f"}($v);
    $product->save();
}
```

✅ Satu write — pola `apply_fields()`:
```php
foreach ($fields as $f => $v) {
    $product->{"set_$f"}($v);
}
$product->save();
```

Tiap `save()` menjalankan query tulis **dan** seluruh rantai hook WooCommerce.

---

## 4. Caching

### Yang dipakai plugin ini

Hanya satu:

```php
wc_delete_product_transients($pid);   // setelah $product->save()
```

Ini **invalidasi**, bukan caching. Membuang transient harga dan rating produk
agar frontend tidak menampilkan data basi.

**Tidak ada** `set_transient()`, `wp_cache_set()`, atau caching hasil query.
Itu keputusan yang tepat, dan alasannya penting.

### Kenapa tidak ada caching

Plugin ini adalah **editor**. Datanya berubah terus — itu justru tujuannya.
Cache di sini akan lebih sering salah daripada benar, dan menampilkan harga
basi di alat yang dipakai untuk mengubah harga adalah bug, bukan optimasi.

Query produknya juga sudah bergantung pada filter dan paginasi, sehingga kunci
cache-nya akan hampir unik per request.

### Kapan caching baru masuk akal

Untuk data yang **jarang berubah** dan **mahal dihitung**:

```php
private function get_all_categories(): array
{
    $cached = get_transient('wcbulk_all_cats');

    if (is_array($cached)) {
        return $cached;
    }

    $terms = get_terms([…]);
    $list  = array_map(…, $terms);

    set_transient('wcbulk_all_cats', $list, HOUR_IN_SECONDS);

    return $list;
}
```

**Wajib disertai invalidasi**, atau kategori baru tidak muncul selama satu jam:

```php
add_action('created_product_cat', static fn() => delete_transient('wcbulk_all_cats'));
add_action('edited_product_cat',  static fn() => delete_transient('wcbulk_all_cats'));
add_action('delete_product_cat',  static fn() => delete_transient('wcbulk_all_cats'));
```

Cache tanpa invalidasi lebih buruk daripada tidak ada cache — bug-nya muncul
belakangan dan sulit dilacak.

Pada 10 kategori, ini belum sepadan. Catat sebagai opsi kalau nanti tumbuh.

### Transient vs object cache

| | Persistensi | Kapan dipakai |
|---|---|---|
| `set_transient()` | tabel options (atau object cache kalau ada) | data yang boleh bertahan antar request |
| `wp_cache_set()` | memori request saja (tanpa Redis/Memcached) | hasil hitungan dalam satu request |

**`wp_cache_set()` tanpa object cache persisten hilang di akhir request.** Untuk
memoisasi dalam satu request, properti class seperti `$placeholder_image` lebih
sederhana dan lebih jelas.

### Option autoload

Terukur di instalasi ini: **210 option autoload, 79 KB**. Itu dimuat di
**setiap** request WordPress — termasuk seluruh halaman frontend.

Plugin ini tidak menambah satu pun option, jadi tidak berkontribusi ke angka
itu. Kalau nanti menambah:

```php
add_option('wcbulk_cache', $data, '', false);   // autoload = no
```

Data yang hanya dipakai di satu halaman admin **tidak boleh** autoload.

---

## 5. Pemuatan Aset

### Penargetan layar

```php
public function enqueue_assets(string $hook): void
{
    if ($hook !== self::SCREEN_ID) {
        return;
    }
    // …
}
```

Aset plugin ini **tidak pernah** dimuat di halaman admin lain. Ini yang
membedakan plugin yang sopan dari plugin yang membuat admin lambat.

Ukurannya: `admin.js` 77 KB, `admin.css` 34 KB. Tidak diminifikasi (lihat
[ADR 0002](adr/0002-tanpa-build-step.md)). Untuk satu halaman yang dibuka
sesekali, ini tidak bermasalah — tapi akan bermasalah kalau dimuat di setiap
halaman admin.

### Cache busting lewat `filemtime()`

```php
private function asset_version(string $relative_path): string
{
    $path = WCBULK_PLUGIN_DIR . $relative_path;

    if (!is_readable($path)) {
        return WCBULK_VERSION;
    }

    $mtime = filemtime($path);

    return $mtime !== false ? (string) $mtime : WCBULK_VERSION;
}
```

Lebih baik daripada memakai `WCBULK_VERSION` saja: mengedit CSS tanpa menaikkan
versi plugin tetap memaksa browser mengambil ulang. Penting karena plugin ini
sering diedit langsung.

Biayanya dua panggilan `filemtime()` per pemuatan halaman — sekitar 0,1 ms,
dan hasilnya di-cache OS. Sepadan.

> **Catatan:** `filemtime()` di jaringan berbagi (NFS) bisa lebih lambat. Di
> lingkungan seperti itu, `WCBULK_VERSION` saja lebih aman.

### Dependency

```php
wp_enqueue_script(
    'wc-bulk-editor-js',
    WCBULK_PLUGIN_URL . 'assets/admin.js',
    ['jquery', 'jquery-ui-sortable', 'wp-util'],
    $this->asset_version('assets/admin.js'),
    true,                                        // ← di footer
);
```

Tiga hal benar di sini:

- **Dependency dideklarasikan**, bukan diasumsikan sudah ada
- **`true` = footer**, sehingga tidak memblokir render
- **Semua lokal** — tidak ada CDN

Jangan menambahkan library eksternal tanpa alasan kuat. jQuery dan
jquery-ui-sortable sudah tersedia di admin WordPress.

### Ukuran payload preload

50 produk × ~35 field ≈ **100–150 KB JSON** tertanam di HTML halaman.

Ini pertukaran yang disengaja: halaman lebih besar, tapi nol AJAX untuk render
pertama. Pada 15 produk di instalasi ini, payloadnya jauh lebih kecil.

Yang perlu diperhatikan: `product_to_row()` mengirim **semua** field termasuk
`description` dan `short_description` — meski kolom itu tidak aktif secara
default. Deskripsi produk bisa panjang.

Optimasi yang mungkin (belum diperlukan): kirim hanya field untuk kolom yang
aktif. Trade-off-nya, mengaktifkan kolom baru jadi butuh AJAX. Jangan
dikerjakan sebelum terukur jadi masalah.

---

## 6. Cron

**Plugin ini tidak memakai cron sama sekali**, dan itu benar. Semua operasinya
dipicu user dan selesai dalam satu request.

### Kalau nanti perlu

Kasus yang mungkin: impor CSV besar, atau operasi massal ribuan produk yang
melebihi batas waktu eksekusi PHP.

```php
// Jadwalkan sekali, bukan di setiap request
if (!wp_next_scheduled('wcbulk_process_batch')) {
    wp_schedule_single_event(time() + 60, 'wcbulk_process_batch', [$batch_id]);
}

add_action('wcbulk_process_batch', 'wcbulk_run_batch', 10, 1);
```

Empat hal yang wajib diketahui:

**a. WP-Cron bukan cron sungguhan.** Ia berjalan saat ada pengunjung membuka
situs. Situs sepi = cron tidak jalan. Untuk pekerjaan yang harus tepat waktu:

```php
// wp-config.php
define('DISABLE_WP_CRON', true);
```
lalu jadwalkan lewat Task Scheduler (Windows) atau crontab yang memanggil
`wp-cron.php`.

**b. Selalu cek `wp_next_scheduled()`** sebelum menjadwalkan, atau event
menumpuk setiap request.

**c. Bersihkan saat deaktivasi:**
```php
register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook('wcbulk_process_batch');
});
```
Lihat [LIFECYCLE.md](LIFECYCLE.md#2-deaktivasi).

**d. Cron berjalan tanpa user login.** Tidak ada `current_user_can()` yang
bermakna, tidak ada nonce. Semua otorisasi harus sudah terjadi saat pekerjaan
dijadwalkan.

### Alternatif: Action Scheduler

WooCommerce membawa **Action Scheduler** — sudah tersedia tanpa dependency
tambahan, dan lebih baik daripada WP-Cron untuk pekerjaan massal:

```php
as_enqueue_async_action('wcbulk_process_batch', [$batch_id], 'wc-bulk-editor');
```

Kelebihannya: antrean tersimpan di tabel sendiri, ada UI pemantauan di
**WooCommerce → Status → Scheduled Actions**, otomatis retry, dan dirancang
untuk ribuan job.

Untuk plugin extension WooCommerce, ini pilihan yang lebih tepat daripada
WP-Cron.

---

## 7. Cara Mengukur

Jangan menebak. Instalasi ini sudah punya **Query Monitor** aktif.

Yang diperiksa saat membuka Bulk Editor:

| Metrik | Nilai wajar | Tanda bahaya |
|---|---|---|
| Jumlah query | < 60 | > 200 → kemungkinan N+1 |
| Waktu query total | < 100 ms | > 500 ms |
| Peak memory | < 64 MB | > 128 MB |
| PHP notice/deprecated | 0 | apa pun |

**Uji paginasi:** buka halaman 1, lalu halaman 2. Jumlah query harus **hampir
sama**. Kalau naik seiring nomor halaman, ada query yang tidak dibatasi.

**Uji dengan kolom banyak:** aktifkan semua 29 kolom lalu muat ulang. Jumlah
query seharusnya tidak berubah — semua data sudah diambil `product_to_row()`.
Kalau naik, ada kolom yang query sendiri.

### Membaca urutan query lambat

Query Monitor → tab Queries → urutkan by time. Yang muncul di atas biasanya:

- `wc_get_products()` — wajar, ini query utama
- `wp_get_object_terms()` — wajar, satu untuk seluruh halaman
- Query berulang dengan pola sama → **itu N+1**, cari loop-nya

---

## 8. Ambang Perhatian

Kapan hal-hal di dokumen ini mulai jadi masalah nyata:

| Ambang | Yang perlu ditinjau |
|---|---|
| > 500 kategori | `get_all_categories()` — ganti dropdown dengan pencarian AJAX |
| > 5.000 produk | paginasi sudah menangani; pastikan filter memakai index |
| > 100 per halaman | payload preload dan beban render browser |
| Operasi > 30 detik | pindah ke Action Scheduler |
| Produk variable banyak | plugin ini belum menyentuh variasi — akan mahal |

**Toko ini punya 15 produk dan 10 kategori.** Tidak ada satu pun ambang yang
terlampaui. Optimasi sekarang adalah menambah kerumitan tanpa manfaat terukur.

---

## 9. Checklist

Saat menambah kode yang menyentuh data:

- [ ] Tidak ada query di dalam loop — ambil sekali untuk seluruh halaman
- [ ] Fungsi mahal di dalam loop dimemoisasi (`??=`)
- [ ] `wc_get_products()` memakai `'paginate' => true`
- [ ] `'return' => 'ids'` kalau objek penuh tidak dibutuhkan
- [ ] Tidak ada `limit => -1`
- [ ] Satu `save()` per produk, setelah semua setter
- [ ] `wc_delete_product_transients()` setelah menulis produk
- [ ] Option baru (kalau ada) memakai `autoload = false`
- [ ] Cache baru (kalau ada) disertai hook invalidasi
- [ ] Aset tetap dibatasi `$hook !== self::SCREEN_ID`
- [ ] Diukur dengan Query Monitor sebelum dan sesudah

---

## Rujukan

- [ADR 0003](adr/0003-preload-bukan-ajax.md) — keputusan preload
- [ADR 0002](adr/0002-tanpa-build-step.md) — kenapa aset tidak diminifikasi
- [WOOCOMMERCE.md](WOOCOMMERCE.md#4-query-produk) — argumen `wc_get_products()`
- `woocommerce/packages/action-scheduler/` — Action Scheduler
- Query Monitor — sudah terpasang dan aktif di instalasi ini
