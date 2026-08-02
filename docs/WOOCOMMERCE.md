# Integrasi WooCommerce

Aturan spesifik WooCommerce untuk plugin ini. Banyak tutorial dan jawaban
Stack Overflow yang beredar masih memakai pola era 2019 — pola itu **rusak**
di WooCommerce modern. Dokumen ini menandai mana yang masih berlaku.

**Lingkungan yang terpasang saat ini:**

| Komponen | Versi |
|---|---|
| WordPress | 7.0.2 |
| WooCommerce | 10.9.4 |
| PHP | 8.3 |

---

## 1. Deklarasi Kompatibilitas

WooCommerce menampilkan peringatan di admin untuk plugin yang tidak
mendeklarasikan dukungan fitur. Deklarasi dilakukan di `before_woocommerce_init`
— sebelum WooCommerce selesai boot.

Yang **sudah ada** di `bulk-product-editor-for-woocommerce.php:23-27`:

```php
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables', __FILE__, true
        );
    }
});
```

Plugin ini **tidak** mendeklarasikan `cart_checkout_blocks`, dan itu benar:
plugin murni admin-side, tidak menyentuh cart maupun checkout. Jangan
mendeklarasikan kompatibilitas untuk fitur yang tidak kamu sentuh — deklarasi
palsu justru menyembunyikan masalah nyata dari pemilik toko.

`class_exists()` bukan basa-basi. Tanpa itu, plugin fatal error pada WooCommerce
lama yang belum punya `FeaturesUtil`.

---

## 2. HPOS — Order Tidak Lagi di Tabel Post

**High-Performance Order Storage** memindahkan order dari `wp_posts`/`wp_postmeta`
ke tabel khusus (`wp_wc_orders`, `wp_wc_orders_meta`, dan lainnya). Pada
WooCommerce 10.x, HPOS adalah **default untuk instalasi baru**.

Plugin ini sekarang tidak menyentuh order sama sekali. Kalau nanti perlu:

❌ **Rusak di HPOS** — mengembalikan data kosong atau basi:
```php
$total  = get_post_meta($order_id, '_order_total', true);
$orders = new WP_Query(['post_type' => 'shop_order']);
$posts  = get_posts(['post_type' => 'shop_order']);
$status = get_post_status($order_id);
```

✅ **Benar**:
```php
$order = wc_get_order($order_id);
if (!$order) { return; }

$total  = $order->get_total();
$status = $order->get_status();
$custom = $order->get_meta('_my_field');

$order->update_meta_data('_my_field', $value);
$order->save();

$orders = wc_get_orders([
    'limit'  => 20,
    'status' => 'processing',
]);
```

**Jangan pernah menulis SQL langsung ke `wp_posts` untuk order.** Tergantung
mode sinkronisasi toko, data bisa ada di satu tabel, tabel lain, atau keduanya.
Hanya CRUD API yang tahu yang mana.

Aturan sederhana: **kalau kode kamu menyebut `post` dan `order` dalam kalimat
yang sama, itu tanda bahaya.**

---

## 3. Produk: CRUD, Bukan Meta

Produk masih tinggal di `wp_posts` — belum ada "HPOS untuk produk". Tapi
`update_post_meta()` tetap salah, karena WooCommerce memelihara tabel bantu
`wp_wc_product_meta_lookup` untuk harga, stok, dan rating.

❌ **Salah** — lookup table jadi tidak sinkron:
```php
update_post_meta($pid, '_regular_price', '99000');
update_post_meta($pid, '_stock', 5);
```

Akibatnya: filter harga di frontend salah, sorting "urutkan berdasar harga"
salah, badge sale tidak muncul. Bug seperti ini muncul di *toko*, bukan di
admin — jadi sering baru ketahuan setelah pelanggan komplain.

✅ **Benar** — pola yang dipakai `wc_bulk_save_inline()`:
```php
$product = wc_get_product($pid);
if (!$product) { return; }

$product->set_regular_price('99000');
$product->set_stock_quantity(5);
$product->save();                    // lookup table ikut diperbarui

wc_delete_product_transients($pid);  // buang cache produk
```

### Setter yang mudah salah

| Field | Setter | Catatan |
|---|---|---|
| Harga | `set_regular_price()` | **string**, bukan float |
| Harga coret | `set_sale_price()` | string kosong `''` untuk menghapus |
| Stok | `set_stock_quantity()` | diabaikan kalau `manage_stock` mati |
| Shipping class | `set_shipping_class_id()` | **term ID**, bukan slug |
| Tax class | `set_tax_class()` | **slug**, bukan ID |
| Status | `set_status()` | bukan `set_post_status()` |

Perhatikan `shipping_class` vs `tax_class` — yang satu ID, yang satu slug.
Ini sumber bug yang berulang; `product_to_row()` karena itu mengirim
**keduanya** (`shipping_class` slug dan `shipping_class_id`).

### Jebakan stok

```php
$product->set_stock_quantity(10);
$product->save();   // diabaikan kalau manage_stock = false
```

WooCommerce membuang nilai stok kalau manajemen stok mati. `apply_fields()`
menanganinya dengan menyalakan `manage_stock` lebih dulu — lihat
[ARCHITECTURE.md](ARCHITECTURE.md#kejutan-yang-sengaja-stock-quantity).

### Kapan `save()` dipanggil

Satu kali di akhir, setelah semua setter — bukan setelah tiap setter.
Tiap `save()` menjalankan query tulis dan seluruh rantai hook.

❌ 12 kali write untuk satu produk:
```php
foreach ($fields as $f => $v) {
    $product->{"set_$f"}($v);
    $product->save();
}
```

✅ Satu kali:
```php
foreach ($fields as $f => $v) {
    $product->{"set_$f"}($v);
}
$product->save();
```

---

## 4. Query Produk

Pakai `wc_get_products()`, bukan `WP_Query`. Argumennya berbeda dari `WP_Query`
dan tidak selalu terdokumentasi dengan jelas.

Yang dipakai plugin ini (`wc_bulk_fetch_products()`):

```php
$args = [
    'limit'    => $per_page,
    'page'     => $page,
    'paginate' => true,          // ← mengubah bentuk nilai kembali
    'orderby'  => 'ID',
    'order'    => 'DESC',
    'status'   => ['publish', 'draft', 'pending', 'private'],
    's'                   => $search,        // nama atau SKU
    'product_category_id' => [$term_id],     // term ID
    'type'                => 'simple',
    'stock_status'        => 'instock',
    'featured'            => true,           // boolean, bukan 'yes'
];
```

**`'paginate' => true` mengubah bentuk kembalian** dari array produk menjadi
objek:

```php
$q = wc_get_products($args);
$q->products;         // list<WC_Product>
$q->total;            // int
$q->max_num_pages;    // int
```

Tanpa flag itu, `wc_get_products()` mengembalikan array biasa dan
`$q->products` menjadi error. Ini penyebab fatal error yang sering terjadi saat
menyalin contoh kode dari dokumentasi.

Catatan argumen:
- `product_category_id` menerima **term ID**; `category` menerima **slug**.
  Keduanya didukung, jangan tertukar.
- `featured` menerima boolean asli. `'yes'` akan di-cast menjadi `true` dan
  filter "Not Featured" jadi rusak — karena itu handler mengubahnya eksplisit:
  `$featured === 'yes'`.
- `limit => -1` mengambil semua produk. **Jangan** — toko dengan 10.000 produk
  akan kehabisan memori.

### N+1 query

❌ Dua query per produk, 100 query untuk satu halaman:
```php
foreach ($products as $p) {
    $cats = wp_get_object_terms($p->get_id(), 'product_cat');
    $tags = wp_get_object_terms($p->get_id(), 'product_tag');
}
```

✅ Satu query untuk seluruh halaman — pola `collect_terms()`:
```php
$terms = wp_get_object_terms(
    $ids,
    ['product_cat', 'product_tag'],
    ['fields' => 'all_with_object_id']
);
```

`all_with_object_id` membuat tiap term membawa `object_id`-nya, sehingga hasil
bisa dipetakan kembali ke produk masing-masing. Ikuti pola ini untuk data
per-produk apa pun yang ditambahkan nanti.

---

## 5. Produk Variable

Yang diedit tabel ini adalah **produk induk**. Harga dan stok variasi tinggal di
objek `WC_Product_Variation` terpisah.

```php
$product = wc_get_product($pid);

if ($product->is_type('variable')) {
    $product->get_price();           // rentang harga dari variasi (read-only)
    $product->set_regular_price(…);  // tidak berpengaruh apa pun
}
```

Menyetel harga pada produk variable tidak error — hanya diabaikan diam-diam.
Itu perilaku plugin saat ini dan sudah didokumentasikan di README sebagai
batasan.

Kalau nanti mau mendukung edit variasi:
```php
foreach ($product->get_children() as $variation_id) {
    $variation = wc_get_product($variation_id);
    $variation->set_regular_price('99000');
    $variation->save();
}
WC_Product_Variable::sync($product->get_id());   // wajib: perbarui rentang harga induk
```

Melewatkan `sync()` membuat rentang harga induk basi di frontend.

---

## 6. Cart & Checkout Blocks

Tidak relevan untuk plugin ini (murni admin), tapi penting kalau kamu membuat
plugin lain.

Sejak WooCommerce 8.3, checkout default adalah **block-based**. Hook PHP lama
**tidak dijalankan** di sana:

❌ Tidak jalan di block checkout:
```php
add_action('woocommerce_after_order_notes', …);
add_filter('woocommerce_checkout_fields', …);
```

✅ Cara baru:
```php
// Field bawaan
woocommerce_register_additional_checkout_field([
    'id'    => 'my-plugin/nomor-npwp',
    'label' => 'Nomor NPWP',
    'type'  => 'text',
]);

// Data kustom → Store API
woocommerce_store_api_register_endpoint_data([…]);
```

Plugin yang menambah field checkout wajib mendeklarasikan
`cart_checkout_blocks` dan mengimplementasikan `IntegrationInterface`.
Kelasnya ada di `src/Blocks/Integrations/IntegrationInterface.php` pada
WooCommerce yang terpasang.

---

## 7. Store API vs admin-ajax

Plugin ini memakai `admin-ajax.php`, dan untuk konteks admin itu **pilihan yang
tepat** — nonce dan capability WordPress berlaku langsung, tanpa perlu
mendaftarkan schema.

Panduan memilih:

| Konteks | Pakai |
|---|---|
| Admin-only, penulis tunggal | `admin-ajax.php` ← plugin ini |
| Admin, butuh REST/schema | `register_rest_route()` di namespace sendiri |
| Frontend cart/checkout | Store API (`/wc/store/v1/`) |
| Frontend non-cart | REST route sendiri |

**Jangan** menambahkan endpoint ke namespace `wc/store/v1` — itu milik
WooCommerce dan bisa berubah tanpa peringatan.

Biaya `admin-ajax.php`: tiap request memuat WordPress penuh. Karena itu plugin
ini melakukan preload lewat `wp_localize_script()` alih-alih memanggil AJAX saat
boot. Lihat [ARCHITECTURE.md](ARCHITECTURE.md#prinsip-utama-preload-bukan-ajax).

---

## 8. Cache & Transient

Setelah mengubah produk:

```php
$product->save();
wc_delete_product_transients($pid);   // sudah dilakukan wc_bulk_save_inline()
```

Yang dibersihkan: transient harga, hitungan rating, hitungan term.

Untuk perubahan yang memengaruhi banyak produk sekaligus:
```php
wc_delete_product_transients();   // tanpa argumen = semua
```
Mahal — jangan panggil di dalam loop.

Kalau mengubah term produk, hitungan term perlu disegarkan:
```php
wp_update_term_count_now($term_ids, 'product_cat');
```

---

## 9. Ringkasan Larangan

| Jangan | Pakai |
|---|---|
| `get_post_meta()` untuk order | `$order->get_meta()` |
| `WP_Query(['post_type' => 'shop_order'])` | `wc_get_orders()` |
| `update_post_meta()` untuk field produk | `$product->set_*()` + `save()` |
| `WP_Query` untuk produk | `wc_get_products()` |
| `$q->products` tanpa `'paginate' => true` | sertakan flag-nya |
| `save()` di dalam loop setter | satu `save()` di akhir |
| `wp_get_object_terms()` per produk | sekali untuk seluruh halaman |
| `limit => -1` | paginasi |
| Endpoint di namespace `wc/store/v1` | namespace sendiri |
| `set_shipping_class()` dengan slug | `set_shipping_class_id()` dengan ID |
| Hook checkout PHP lama | `woocommerce_register_additional_checkout_field()` |

---

## Rujukan

- HPOS: `woocommerce/src/Internal/DataStores/Orders/`
- Store API: `woocommerce/src/StoreApi/`
- Blocks integration: `woocommerce/src/Blocks/Integrations/IntegrationInterface.php`
- Argumen query produk: `woocommerce/includes/data-stores/class-wc-product-data-store-cpt.php`

Membaca source WooCommerce yang terpasang lebih dapat diandalkan daripada
mencari di web — hasil pencarian sering menampilkan pola era 2019 yang sudah
tidak berlaku.
