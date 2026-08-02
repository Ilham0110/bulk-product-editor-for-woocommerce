# REST API Kustom

Plugin ini **tidak memakai REST API** — 14 endpoint-nya seluruhnya lewat
`admin-ajax.php`. Itu keputusan sengaja yang dicatat di
[ADR 0004](adr/0004-admin-ajax-bukan-rest.md).

Dokumen ini untuk kalau keadaan berubah: ada klien kedua, integrasi eksternal,
atau aplikasi mobile. Bagian 1 membantu memutuskan apakah pindah memang perlu.

Diverifikasi terhadap WordPress 7.0.2: `wp-includes/rest-api.php`,
`wp-includes/rest-api/class-wp-rest-server.php`.

---

## 1. Kapan Pindah ke REST

`admin-ajax` sudah cukup kalau **semua** ini benar:

- Hanya dipanggil dari halaman admin plugin sendiri
- Satu klien: `admin.js`
- Tidak ada pihak lain yang perlu mengakses

REST menjadi tepat kalau **salah satu** ini terjadi:

| Pemicu | Kenapa REST |
|---|---|
| Aplikasi mobile | butuh autentikasi non-cookie |
| Integrasi pihak ketiga | butuh schema dan dokumentasi |
| Plugin lain memakai data ini | kontrak yang stabil |
| Blok Gutenberg | `@wordpress/api-fetch` mengandaikan REST |
| Operasi butuh URL sendiri | debugging dan log lebih mudah dibaca |

**Jangan pindah hanya karena REST "lebih modern".** Untuk satu halaman admin
dengan satu file JS, REST menambah schema, `permission_callback`, dan penanganan
nonce `wp_rest` — semuanya menyelesaikan masalah yang tidak ada.

### Kalau pindah, pindahkan semua

Campuran REST untuk baca dan `admin-ajax` untuk tulis berarti dua mekanisme
autentikasi, dua bentuk respons, dan dua cara menangani error. Pilih satu.

---

## 2. Namespace dan Registrasi

```php
add_action('rest_api_init', static function (): void {
    register_rest_route(
        'bulk-product-editor-for-woocommerce/v1',              // namespace: nama-plugin/versi
        '/products',                       // route
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'wcbulk_rest_get_products',
            'permission_callback' => 'wcbulk_rest_can_read',
            'args'                => wcbulk_rest_products_args(),
        ]
    );
});
```

Menghasilkan: `/wp-json/bulk-product-editor-for-woocommerce/v1/products`

### Aturan namespace

Core memaksa (`rest-api.php:35-75`):

- **Wajib ada namespace.** Route tanpa namespace ditolak.
- **Tidak boleh diawali atau diakhiri slash.** `'bulk-product-editor-for-woocommerce/v1'`, bukan
  `'/bulk-product-editor-for-woocommerce/v1/'`.
- **Wajib menyertakan versi.** `v1` memungkinkan `v2` berdampingan saat kontrak
  berubah.

**Jangan pakai namespace milik orang lain:**

```php
register_rest_route('wc/store/v1', …);   // ❌ milik WooCommerce
register_rest_route('wp/v2', …);         // ❌ milik core
```

Keduanya bisa berubah tanpa peringatan, dan plugin kamu akan rusak saat update.

### Konstanta metode

Dari `class-wp-rest-server.php`:

| Konstanta | Nilai |
|---|---|
| `READABLE` | `GET` |
| `CREATABLE` | `POST` |
| `EDITABLE` | `POST, PUT, PATCH` |
| `DELETABLE` | `DELETE` |
| `ALLMETHODS` | `GET, POST, PUT, PATCH, DELETE` |

Pakai konstanta, bukan string. `EDITABLE` mencakup tiga metode sekaligus —
menuliskannya manual mudah terlewat satu.

### Registrasi hanya di `rest_api_init`

Mendaftarkan di luar hook itu memicu `_doing_it_wrong` (sejak WordPress 5.1) dan
route tidak berfungsi.

---

## 3. `permission_callback` — Wajib

Sejak WordPress 5.5, route tanpa `permission_callback` memicu peringatan
(`rest-api.php:122-134`). Route **tetap terdaftar** dan dapat diakses — jadi
melewatkannya berarti endpoint terbuka untuk publik.

### Bentuk yang benar

```php
function wcbulk_rest_can_edit(WP_REST_Request $request): bool|WP_Error
{
    if (!current_user_can('manage_woocommerce')) {
        return new WP_Error(
            'wcbulk_forbidden',
            __('Anda tidak punya izin untuk ini.', 'bulk-product-editor-for-woocommerce'),
            ['status' => 403]
        );
    }

    return true;
}
```

Kembalikan `WP_Error` dengan `['status' => 403]`, bukan `false`. Perbedaannya
nyata:

| Kembalian | Respons |
|---|---|
| `false` | 401 generik, tanpa penjelasan |
| `WP_Error` + status 403 | 403 dengan pesan yang bisa ditampilkan klien |

### Cek per objek

Untuk endpoint yang menyentuh produk tertentu, `manage_woocommerce` saja tidak
cukup:

```php
function wcbulk_rest_can_edit_product(WP_REST_Request $request): bool|WP_Error
{
    if (!current_user_can('manage_woocommerce')) {
        return new WP_Error('wcbulk_forbidden', …, ['status' => 403]);
    }

    $id = (int) $request['id'];

    if (!current_user_can('edit_post', $id)) {
        return new WP_Error(
            'wcbulk_cannot_edit',
            __('Anda tidak boleh menyunting produk ini.', 'bulk-product-editor-for-woocommerce'),
            ['status' => 403]
        );
    }

    return true;
}
```

Ini menutup asimetri yang ada di versi `admin-ajax` saat ini — lihat
[THREAT-MODEL.md §4.2](THREAT-MODEL.md#42-asimetri-hapus-dicek-per-objek-edit-tidak).

Untuk operasi massal, cek per objek harus terjadi di **callback**, bukan di
`permission_callback` — karena sebagian ID bisa diizinkan dan sebagian tidak:

```php
foreach ($ids as $id) {
    if (!current_user_can('edit_post', $id)) {
        $skipped[] = $id;
        continue;
    }
    // proses
}
```

### `__return_true` hanya untuk yang benar-benar publik

```php
'permission_callback' => '__return_true',
```

Ini sah untuk data publik (daftar produk yang sudah tampil di toko). Untuk
apa pun yang menyentuh data admin, ini adalah lubang.

---

## 4. Schema

Schema adalah kontrak: ia mendokumentasikan **dan** memvalidasi sekaligus.

### Args per endpoint

```php
function wcbulk_rest_products_args(): array
{
    return [
        'page' => [
            'description' => __('Halaman saat ini.', 'bulk-product-editor-for-woocommerce'),
            'type'        => 'integer',
            'default'     => 1,
            'minimum'     => 1,
        ],
        'per_page' => [
            'description' => __('Jumlah produk per halaman.', 'bulk-product-editor-for-woocommerce'),
            'type'        => 'integer',
            'default'     => 50,
            'minimum'     => 1,
            'maximum'     => 100,           // sejajar dengan MAX_PER_PAGE
        ],
        'search' => [
            'description'       => __('Cari nama atau SKU.', 'bulk-product-editor-for-woocommerce'),
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ],
        'status' => [
            'type' => 'string',
            'enum' => ['publish', 'draft', 'pending', 'private'],   // whitelist
        ],
        'category' => [
            'type'    => 'integer',
            'minimum' => 0,
        ],
    ];
}
```

WordPress memvalidasi dan menyanitasi **sebelum** callback berjalan. Yang tiba
di callback sudah bertipe benar dan dalam rentang yang diizinkan.

Ini keuntungan nyata dibanding `admin-ajax`, di mana setiap handler harus
memvalidasi sendiri lewat `post_string()` dan `in_array()`.

### Keyword yang berguna

| Keyword | Untuk |
|---|---|
| `type` | `string`, `integer`, `number`, `boolean`, `array`, `object` |
| `enum` | daftar nilai yang sah — pengganti `in_array()` |
| `minimum` / `maximum` | rentang angka |
| `minLength` / `maxLength` | panjang string |
| `pattern` | regex |
| `format` | `email`, `uri`, `date-time`, `ip` |
| `required` | wajib ada |
| `items` | tipe elemen array |
| `properties` | struktur object |
| `default` | nilai bila tidak dikirim |

### Array dan object bersarang

Untuk endpoint massal seperti `save_inline`:

```php
'changes' => [
    'description' => __('Perubahan per produk.', 'bulk-product-editor-for-woocommerce'),
    'type'        => 'object',
    'required'    => true,
    'additionalProperties' => [
        'type'       => 'object',
        'properties' => [
            'regular_price'  => ['type' => 'string'],
            'sale_price'     => ['type' => 'string'],
            'stock_quantity' => ['type' => ['integer', 'null']],
            'stock_status'   => [
                'type' => 'string',
                'enum' => ['instock', 'outofstock', 'onbackorder'],
            ],
        ],
    ],
],
```

`additionalProperties` dipakai karena kunci-nya adalah ID produk yang dinamis.

**Perhatikan `['integer', 'null']`** — tipe union. Ini penting untuk
`stock_quantity`, yang bernilai `null` saat `manage_stock` mati.

### Schema item untuk respons

Untuk dokumentasi otomatis dan `_fields` filtering:

```php
'schema' => static fn(): array => [
    '$schema'    => 'http://json-schema.org/draft-04/schema#',
    'title'      => 'wcbulk_product',
    'type'       => 'object',
    'properties' => [
        'id'            => ['type' => 'integer', 'readonly' => true],
        'name'          => ['type' => 'string'],
        'sku'           => ['type' => 'string'],
        'regular_price' => ['type' => 'string'],
        'stock_quantity'=> ['type' => ['integer', 'null']],
    ],
],
```

Properti `readonly` dikecualikan otomatis dari args endpoint
(`rest-api.php:3371`) — nilai itu tidak bisa dikirim klien.

Dari schema, args bisa dibuat otomatis:

```php
'args' => rest_get_endpoint_args_for_schema($schema, WP_REST_Server::CREATABLE),
```

Fungsi itu menambahkan `rest_validate_request_arg` dan
`rest_sanitize_request_arg` ke setiap properti (`rest-api.php:3375-3378`).

---

## 5. Validasi Kustom

Kalau schema tidak cukup:

```php
'sku' => [
    'type'              => 'string',
    'validate_callback' => static function ($value, $request, $param) {
        if (!is_string($value) || $value === '') {
            return new WP_Error(
                'rest_invalid_param',
                __('SKU tidak boleh kosong.', 'bulk-product-editor-for-woocommerce'),
                ['status' => 400]
            );
        }

        $existing = wc_get_product_id_by_sku($value);

        if ($existing && $existing !== (int) $request['id']) {
            return new WP_Error(
                'wcbulk_sku_exists',
                __('SKU sudah dipakai produk lain.', 'bulk-product-editor-for-woocommerce'),
                ['status' => 400]
            );
        }

        return true;
    },
],
```

`validate_callback` berjalan **sebelum** `sanitize_callback`. Kembalikan
`WP_Error` untuk pesan yang berguna, atau `false` untuk penolakan generik.

### Validasi lintas-field

Schema memeriksa field satu per satu. Untuk aturan yang melibatkan dua field —
misalnya sale price tidak boleh melebihi regular price — lakukan di callback:

```php
function wcbulk_rest_update_product(WP_REST_Request $request)
{
    $regular = $request['regular_price'] ?? null;
    $sale    = $request['sale_price'] ?? null;

    if ($regular !== null && $sale !== null && (float) $sale > (float) $regular) {
        return new WP_Error(
            'wcbulk_invalid_sale',
            __('Harga diskon tidak boleh melebihi harga normal.', 'bulk-product-editor-for-woocommerce'),
            ['status' => 400]
        );
    }
    // …
}
```

---

## 6. Bentuk Respons

### Sukses

```php
function wcbulk_rest_get_products(WP_REST_Request $request): WP_REST_Response
{
    $query = wc_get_products([
        'limit'    => $request['per_page'],
        'page'     => $request['page'],
        'paginate' => true,
        'status'   => $request['status'] ?? ['publish', 'draft', 'pending', 'private'],
    ]);

    $items = array_map('wcbulk_prepare_product', $query->products);

    $response = new WP_REST_Response($items, 200);

    // Paginasi di header, konvensi REST WordPress.
    $response->header('X-WP-Total', (string) $query->total);
    $response->header('X-WP-TotalPages', (string) $query->max_num_pages);

    return $response;
}
```

**Kembalikan array item langsung**, bukan dibungkus `{success: true, data: …}`.
Itu pola `admin-ajax`; REST memakai status HTTP untuk menandakan hasil.

Metadata paginasi masuk **header**, bukan body. Klien membacanya lewat
`response.headers.get('X-WP-Total')`.

### Error

```php
return new WP_Error(
    'wcbulk_product_not_found',                            // kode
    __('Produk tidak ditemukan.', 'bulk-product-editor-for-woocommerce'),       // pesan
    ['status' => 404]                                       // status HTTP
);
```

WordPress mengubahnya menjadi:

```json
{
  "code": "wcbulk_product_not_found",
  "message": "Produk tidak ditemukan.",
  "data": { "status": 404 }
}
```

Status yang tepat:

| Status | Kapan |
|---|---|
| 400 | input tidak valid |
| 401 | belum login |
| 403 | login tapi tidak berizin |
| 404 | tidak ditemukan |
| 409 | konflik (SKU duplikat) |
| 500 | kesalahan server |

### Operasi massal: sukses sebagian

Ini kasus yang paling sering salah dirancang. Menyimpan 50 produk di mana 3
gagal bukan sukses total, bukan juga kegagalan total.

```php
$result = ['updated' => [], 'failed' => []];

foreach ($changes as $pid => $fields) {
    try {
        $product = wc_get_product($pid);

        if (!$product) {
            $result['failed'][] = ['id' => $pid, 'reason' => 'not_found'];
            continue;
        }

        if (!current_user_can('edit_post', $pid)) {
            $result['failed'][] = ['id' => $pid, 'reason' => 'forbidden'];
            continue;
        }

        wcbulk_apply_fields($product, $fields);
        $product->save();
        $result['updated'][] = $pid;
    } catch (Throwable $e) {
        $result['failed'][] = ['id' => $pid, 'reason' => $e->getMessage()];
    }
}

// 207 Multi-Status kalau sebagian gagal.
$status = $result['failed'] === [] ? 200 : 207;

return new WP_REST_Response($result, $status);
```

Klien dapat mengetahui **produk mana** yang gagal dan **kenapa** — bukan hanya
"3 gagal". Pola `admin-ajax` saat ini menggabungkan pesan error menjadi satu
string, yang lebih sulit diproses klien.

### Bentuk item yang konsisten

```php
function wcbulk_prepare_product(WC_Product $product): array
{
    return [
        'id'             => $product->get_id(),
        'name'           => $product->get_name(),
        'sku'            => $product->get_sku(),
        'regular_price'  => $product->get_regular_price(),
        'stock_quantity' => $product->get_manage_stock()
            ? (int) $product->get_stock_quantity()
            : null,
    ];
}
```

Satu fungsi untuk semua endpoint yang mengembalikan produk. Bentuk yang berbeda
antar endpoint memaksa klien menulis penanganan terpisah.

Tipe harus **stabil**: `stock_quantity` selalu `int` atau `null`, jangan
kadang string. Klien JavaScript yang mengandalkan `typeof` akan rusak.

---

## 7. Autentikasi dari JavaScript

REST memakai nonce `wp_rest`, berbeda dari nonce `admin-ajax`.

```php
wp_localize_script('my-script', 'MyRest', [
    'root'  => esc_url_raw(rest_url('bulk-product-editor-for-woocommerce/v1/')),
    'nonce' => wp_create_nonce('wp_rest'),
]);
```

```js
fetch(MyRest.root + 'products?per_page=50', {
    headers: { 'X-WP-Nonce': MyRest.nonce },
    credentials: 'same-origin',
})
    .then(function (r) {
        if (!r.ok) {
            return r.json().then(function (e) { throw new Error(e.message); });
        }
        return r.json();
    })
    .then(function (products) { /* … */ });
```

Tiga hal wajib:

- **Header `X-WP-Nonce`**, bukan parameter `nonce` di body
- **`credentials: 'same-origin'`** — tanpa itu cookie tidak dikirim dan request
  dianggap tidak login
- **Cek `r.ok`** — `fetch` tidak melempar error pada status 4xx/5xx

Kalau memakai `@wordpress/api-fetch`, nonce dan credentials ditangani otomatis.
Tapi itu memerlukan build step, yang bertentangan dengan
[ADR 0002](adr/0002-tanpa-build-step.md).

---

## 8. Kesalahan Umum

❌ **`permission_callback` dilewatkan** — endpoint jadi publik, hanya ada
peringatan di log.

❌ **`return false` dari permission callback** — 401 tanpa penjelasan. Pakai
`WP_Error`.

❌ **Membungkus respons seperti admin-ajax:**
```php
return ['success' => true, 'data' => $items];   // pakai status HTTP
```

❌ **Namespace milik orang lain** (`wc/store/v1`, `wp/v2`).

❌ **Namespace tanpa versi** — tidak bisa berubah tanpa merusak klien.

❌ **Validasi manual padahal schema bisa:**
```php
$page = absint($request['page'] ?? 1);   // schema 'minimum' => 1 sudah menangani
```

❌ **Status 200 untuk operasi yang sebagian gagal** — klien tidak tahu ada
masalah.

❌ **Bentuk item berbeda antar endpoint.**

❌ **Mengembalikan objek `WC_Product` mentah** — serialisasinya bocor
properti internal dan berubah antar versi WooCommerce.

---

## 9. Kalau Memigrasikan Plugin Ini

Urutan yang masuk akal, bukan sekaligus:

1. **Satu endpoint baca dulu** — `GET /products`. Paling mudah diuji, paling
   kecil risikonya.
2. **Bentuk item bersama** — `wcbulk_prepare_product()`, dipakai REST dan
   `product_to_row()` sekaligus agar tidak menyimpang.
3. **Endpoint tulis** dengan pola 207 Multi-Status.
4. **Pindahkan `admin.js`** ke `fetch` + `X-WP-Nonce`.
5. **Hapus handler `admin-ajax`** hanya setelah semuanya terbukti bekerja.

Yang ikut membaik kalau migrasi dilakukan:

- Validasi terpusat di schema, bukan tersebar di 14 handler
- Cek `edit_post` per objek jadi natural
- Error massal jadi terstruktur, bukan string gabungan
- Endpoint punya URL sendiri — lebih mudah dibaca di log dan DevTools

Yang jadi lebih rumit:

- Nonce dan credentials perlu penanganan eksplisit di JS
- Schema harus dijaga sinkron dengan `const COLUMNS`
- Lebih banyak kode untuk hasil yang sama

**Catat sebagai ADR baru** kalau diputuskan — itu menggantikan
[ADR 0004](adr/0004-admin-ajax-bukan-rest.md).

---

## Rujukan

- `wp-includes/rest-api.php:34` — `register_rest_route()`
- `wp-includes/rest-api.php:122` — peringatan `permission_callback`
- `wp-includes/rest-api.php:2186` — `rest_validate_value_from_schema()`
- `wp-includes/rest-api.php:2784` — `rest_sanitize_value_from_schema()`
- `wp-includes/rest-api.php:3361` — `rest_get_endpoint_args_for_schema()`
- `wp-includes/rest-api/class-wp-rest-server.php:24-56` — konstanta metode
- [ADR 0004](adr/0004-admin-ajax-bukan-rest.md) — kenapa plugin ini belum pindah
