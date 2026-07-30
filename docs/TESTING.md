# Pengujian

Strategi pengujian untuk plugin ini: apa yang layak diuji, apa yang tidak, dan
apa yang tidak bisa ditangkap alat apa pun.

**Keadaan sekarang:** nol test otomatis. Tidak ada `tests/`, `phpunit.xml`,
`composer.json`, maupun `package.json`.

Dokumen ini bukan ajakan menulis test untuk semuanya. Ia berusaha menjawab satu
pertanyaan: *dengan usaha terbatas, pengujian apa yang paling mencegah
kerusakan?*

---

## 1. Kendala Nyata

Sebelum merencanakan, ini batasan lingkungan yang terukur:

| Alat | Status |
|---|---|
| Node.js | ✅ v24.18.0 |
| npm / npx | ✅ 12.0.1 |
| Composer | ❌ **tidak terpasang** |
| WP-CLI | ❌ tidak terpasang |

**Composer tidak ada.** Itu berarti PHPUnit dan WordPress test suite —
jalur standar untuk unit dan integration test PHP — tidak bisa dipasang tanpa
menambah tooling baru lebih dulu.

Ini bukan hambatan teknis yang mustahil, tapi mengubah karakter plugin:
dari "salin folder, selesai" menjadi "perlu langkah instalasi". Keputusan itu
menyentuh [ADR 0001](adr/0001-monolit-satu-file.md) dan
[ADR 0002](adr/0002-tanpa-build-step.md), jadi bukan hal sepele.

**Node ada.** Playwright dan Vitest bisa dipasang tanpa Composer.

---

## 2. Apa yang Bisa Diuji, dan Seberapa Mahal

### Struktur kode menentukan biaya pengujian

Plugin ini punya **26 method private** dan **17 public** dalam satu class
`final`. Method private tidak bisa dipanggil langsung dari test.

Konsekuensinya: unit test PHP memerlukan salah satu dari

- Refleksi (rapuh, dan menguji detail internal)
- Mengubah visibilitas jadi `public` (melemahkan enkapsulasi demi test)
- Memecah class jadi beberapa unit (bertentangan dengan ADR 0001)

Ketiganya berbiaya nyata. Ini konsekuensi arsitektur monolit yang **sudah
dicatat** di ADR 0001 sebagai kelemahan yang diterima.

### Klasifikasi realistis

| Bagian | Bisa diuji | Biaya | Nilai |
|---|---|---|---|
| 6 fungsi JS murni (`esc`, `escAttr`, `origVal`, `baseVal`, `fmtPrice`, `isChanged`) | ✅ mudah | rendah | **sedang** |
| 7 method PHP semi-murni (`csv_cell`, `set_enum`, `post_ids`, …) | ⚠️ perlu refleksi | sedang | sedang |
| 38 titik yang memanggil API WP/WC | ⚠️ perlu WP test suite | tinggi | sedang |
| 91 method JS yang menyentuh DOM | ⚠️ perlu browser | tinggi | **tinggi** |
| Layout dan CSS | ⚠️ perlu visual regression | sedang | **tinggi** |

Perhatikan ketidakcocokan: yang **paling murah diuji** (fungsi murni) justru
yang **paling jarang rusak**. Yang **paling sering rusak** (DOM, layout) justru
paling mahal.

Itu bukan alasan menyerah — itu alasan memilih E2E lebih dulu.

---

## 3. Regression Risk — Berdasarkan Data

Riwayat perubahan adalah penunjuk terbaik ke mana kerusakan akan terjadi. Dari
47 file backup yang dipindahkan, nama-nama iterasinya terbaca:

**CSS/layout — 17 iterasi berbeda:**
```
align, baseline, breathing, colwidths, compact, h40,
hdralign, hdrpad, iconalign, pad30, rowfix, tafix,
views-toggle, 9col, rmInline, addcat, admin-v2..v5
```

**PHP/JS — 8 iterasi:**
```
addcat, catdropdown, preload, refactor, rmInline,
stockcat, views, autoload
```

Kesimpulannya jelas: **layout dan lebar kolom adalah area yang paling sering
diubah**, lebih dari dua kali lipat perubahan logika.

### Bug nyata yang pernah terjadi

Satu-satunya perbaikan bug yang tercatat di git (v3.11) menyentuh tiga hal
sekaligus di renderer `tax_class`/`shipping_class`:

1. XSS — label tidak di-escape
2. Option salah tertandai `selected`
3. **Option berlipat setiap render**

Nomor 3 penting untuk perencanaan test: bug itu **hanya muncul setelah render
berulang**. Test yang memuat halaman sekali dan memeriksa hasilnya tidak akan
menangkapnya. Yang menangkapnya adalah interaksi berulang — dan itu ranah E2E.

### Peta risiko

| Area | Frekuensi ubah | Deteksi manual | Prioritas test |
|---|---|---|---|
| Layout, lebar kolom | sangat tinggi | mudah (terlihat) | visual regression |
| Renderer kolom | tinggi | **sulit** — rusak diam-diam | **E2E** |
| Change tracking (`origVal`) | sedang | sulit | **unit JS** |
| Simpan produk | sedang | mudah (nilai salah) | E2E |
| Escape/keamanan | rendah | **sangat sulit** | unit JS + review |
| Query & filter | rendah | sedang | E2E |

Yang perlu diprioritaskan adalah baris dengan **deteksi manual sulit** — bukan
yang paling sering berubah. Layout sering berubah tapi kerusakannya langsung
terlihat mata.

---

## 4. Rekomendasi: Tiga Lapis, Berurutan

### Lapis 1 — E2E dengan Playwright (nilai tertinggi)

Alasannya: plugin ini **adalah** UI. 91 dari 97 method JS menyentuh DOM.
Menguji logika PHP tanpa menguji tabel yang direndernya berarti menguji bagian
yang jarang rusak.

Playwright bisa dipasang tanpa Composer:

```bash
npm init -y
npm install -D @playwright/test
npx playwright install chromium
```

Skenario yang paling bernilai — semuanya berasal dari risiko nyata di atas:

```js
// tests/e2e/bulk-editor.spec.js
const { test, expect } = require('@playwright/test');

test.beforeEach(async ({ page }) => {
    await page.goto('/wp-login.php');
    await page.fill('#user_login', process.env.WP_USER);
    await page.fill('#user_pass', process.env.WP_PASS);
    await page.click('#wp-submit');
    await page.goto('/wp-admin/admin.php?page=wc-bulk-editor');
});

test('tabel terisi tanpa AJAX pada render pertama', async ({ page }) => {
    // Preload adalah keputusan arsitektur (ADR 0003) — jaga agar tidak hilang.
    const rows = page.locator('#wc-bulk-table-body tr');
    await expect(rows.first()).toBeVisible();
    expect(await rows.count()).toBeGreaterThan(0);
});

test('option dropdown tidak berlipat setelah render berulang', async ({ page }) => {
    // Regresi nyata dari v3.11.
    await aktifkanKolom(page, 'tax_class');

    const hitung = async () =>
        page.locator('td select[data-field="tax_class"]').first().locator('option').count();

    const awal = await hitung();

    for (let i = 0; i < 3; i++) {
        await page.click('#wc-bulk-load');
        await page.waitForResponse(r => r.url().includes('admin-ajax'));
    }

    expect(await hitung()).toBe(awal);
});

test('edit lalu simpan bertahan setelah reload', async ({ page }) => {
    const sel = 'input[data-field="regular_price"]';
    await page.locator(sel).first().fill('12345');
    await page.keyboard.press('Control+s');
    await page.waitForResponse(r => r.url().includes('admin-ajax'));

    await page.reload();
    await expect(page.locator(sel).first()).toHaveValue('12345');
});

test('mengembalikan nilai menghapus tanda dirty', async ({ page }) => {
    // Menguji origVal() lewat perilaku, bukan lewat unit test.
    const input = page.locator('input[data-field="sku"]').first();
    const asli  = await input.inputValue();

    await input.fill('BERUBAH');
    await expect(input).toHaveClass(/changed/);

    await input.fill(asli);
    await expect(input).not.toHaveClass(/changed/);
});

test('nol error konsol', async ({ page }) => {
    const errors = [];
    page.on('console', m => m.type() === 'error' && errors.push(m.text()));
    page.on('pageerror', e => errors.push(e.message));

    await page.click('#wc-bulk-btn-columns');
    await page.click('.wc-bulk-modal-close');
    await page.click('#wc-bulk-load');

    expect(errors).toEqual([]);
});
```

Enam test ini menutup sebagian besar risiko yang deteksi manualnya sulit.

**Peringatan:** E2E menulis ke database sungguhan. Jalankan terhadap salinan
lokal, bukan data produksi. Test "edit lalu simpan" mengubah harga produk
nyata.

### Lapis 2 — Unit test JS untuk fungsi murni

Enam fungsi bisa diuji tanpa DOM sama sekali:

```js
// tests/unit/escape.test.js
import { describe, it, expect } from 'vitest';

// Fungsi disalin atau diekstrak — lihat catatan di bawah.
describe('escAttr', () => {
    it('menutup keempat karakter berbahaya', () => {
        expect(escAttr('<img src=x onerror=alert(1)>'))
            .toBe('&lt;img src=x onerror=alert(1)&gt;');
        expect(escAttr('a"b')).toBe('a&quot;b');
        expect(escAttr('a&b')).toBe('a&amp;b');
    });

    it('mempertahankan 0, bukan mengubahnya jadi kosong', () => {
        expect(escAttr(0)).toBe('0');   // bug klasik: !t bernilai true untuk 0
    });
});

describe('origVal', () => {
    it('boolean jadi yes/no', () => { /* … */ });
    it('null jadi string kosong', () => { /* … */ });
    it('categories mengambil id pertama', () => { /* … */ });
    it('shipping_class memakai id, bukan slug', () => { /* … */ });
});
```

**Hambatannya:** `admin.js` adalah satu IIFE tanpa ekspor. Fungsi-fungsi itu
tidak dapat di-`import` tanpa mengubah struktur file — dan itu bertentangan
dengan [ADR 0002](adr/0002-tanpa-build-step.md).

Dua jalan keluar, keduanya berkompromi:

- Salin fungsi ke file test (bisa menyimpang dari sumber — bahaya nyata)
- Ekspor bersyarat di akhir file:
  ```js
  if (typeof module !== 'undefined' && module.exports) {
      module.exports = { esc: B.esc, escAttr: B.escAttr, origVal: B.origVal };
  }
  ```
  Baris ini diabaikan browser dan tidak memerlukan build step.

Yang kedua lebih baik. Tapi nilai lapis ini lebih rendah daripada E2E —
`origVal()` sudah tertutup oleh test "mengembalikan nilai menghapus tanda
dirty" di atas.

### Lapis 3 — PHP (paling mahal, tunda)

Memerlukan Composer, PHPUnit, dan WordPress test suite. Untuk 26 method private
di satu class monolitik, biayanya tinggi dan hasilnya terbatas.

**Kalau tetap dikerjakan**, mulai dari yang paling bernilai:

```php
// Sanitasi CSV — kerentanan nyata, logika murni.
public function test_csv_cell_menetralkan_formula(): void
{
    $this->assertSame('"\'=HYPERLINK(1)"', $this->invoke('csv_cell', ['=HYPERLINK(1)']));
    $this->assertSame('"\'+A1"',           $this->invoke('csv_cell', ['+A1']));
    $this->assertSame('"\'-A1"',           $this->invoke('csv_cell', ['-A1']));
    $this->assertSame('"\'@SUM(A1)"',      $this->invoke('csv_cell', ['@SUM(A1)']));
    $this->assertSame('"biasa"',           $this->invoke('csv_cell', ['biasa']));
    $this->assertSame('"a""b"',            $this->invoke('csv_cell', ['a"b']));
}

// Whitelist enum — mencegah nilai tidak sah masuk produk.
public function test_set_enum_menolak_nilai_di_luar_daftar(): void
{
    $product = new WC_Product_Simple();
    $product->set_stock_status('instock');

    $this->invoke('set_enum', [$product, 'stock_status', 'nilai_karangan']);

    $this->assertSame('instock', $product->get_stock_status());
}

// Jebakan stok — perilaku yang tidak jelas dari kodenya.
public function test_stock_quantity_menyalakan_manage_stock(): void
{
    $product = new WC_Product_Simple();
    $this->assertFalse($product->get_manage_stock());

    $this->invoke('apply_fields', [$product, ['stock_quantity' => '10']]);

    $this->assertTrue($product->get_manage_stock());
    $this->assertSame(10, $product->get_stock_quantity());
}
```

Ketiganya menguji **perilaku yang tidak terbaca dari kodenya sendiri** — itu
kriteria test yang berguna.

---

## 5. Yang Tidak Bisa Ditangkap Test Apa Pun

Bagian ini yang paling sering dilewatkan dalam perencanaan pengujian.

### a. Kombinasi lingkungan

WordPress test suite berjalan pada **satu** konfigurasi. Yang tidak diuji:

| Variabel | Kenapa penting |
|---|---|
| HPOS aktif vs nonaktif | plugin mendeklarasikan kompatibilitas — belum pernah diuji dengan HPOS mati |
| Peran `shop_manager` | semua pengujian manual selama ini sebagai administrator |
| Multisite | `switch_to_blog()` tidak pernah dilalui |
| WooCommerce versi lain | dikembangkan pada 10.9.4 saja |
| PHP 8.4+ | header menyatakan 8.3 |

**Yang paling mendesak dari daftar ini: peran `shop_manager`.** Plugin memakai
`manage_woocommerce` yang dimiliki peran itu, tapi tidak ada catatan bahwa
pengujian pernah dilakukan dengannya. Ini tercantum di
[CLAUDE.md](../CLAUDE.md) sebagai langkah uji manual — tapi belum tentu
dijalankan.

### b. Data ekstrem

Instalasi ini punya **15 produk dan 10 kategori**
([PERFORMANCE.md](PERFORMANCE.md#1-ukuran-toko-saat-ini)). Yang tidak
tersentuh:

- 5.000 produk — apakah paginasi tetap responsif?
- 500 kategori — dropdown tanpa limit
- Produk variable — plugin ini hanya menyentuh induk
- Nama produk dengan emoji, RTL, atau karakter 4-byte
- Deskripsi produk sangat panjang di payload preload

E2E terhadap 15 produk tidak akan menemukan masalah skala.

### c. Interaksi dengan plugin lain

Tujuh plugin aktif di instalasi ini, termasuk Elementor Pro dan Sejoli. Test
suite WordPress berjalan dengan plugin **minimal** — konflik justru muncul di
kombinasi nyata.

Contoh yang tidak akan tertangkap: plugin lain yang mengaitkan
`woocommerce_product_object_updated_props` dan melempar exception saat
`$product->save()` dipanggil 50 kali beruntun.

### d. Rendering visual

17 iterasi CSS menunjukkan layout adalah area paling aktif. Playwright bisa
menangkap sebagian lewat screenshot:

```js
await expect(page.locator('.wc-bulk-table-card')).toHaveScreenshot('tabel.png');
```

Tapi ini rapuh: perubahan font sistem atau versi browser menghasilkan
perbedaan piksel yang bukan bug. Berguna untuk mendeteksi *perubahan tak
disengaja*, bukan untuk menilai *benar atau salah*.

### e. Kondisi balapan

`saveAll()` punya penjaga `if (s.saving) return;`. Menguji apa yang terjadi
saat dua tab admin menyimpan produk yang sama secara bersamaan — praktis di
luar jangkauan test otomatis.

---

## 6. Rencana Bertahap

Berurutan menurut nilai per usaha:

**Tahap 1 — tanpa tooling baru** (bisa sekarang)
- [ ] Perluas daftar uji manual di [CLAUDE.md](../CLAUDE.md) dengan skenario
      dari bagian 3
- [ ] Uji sebagai `shop_manager`, catat hasilnya
- [ ] Uji dengan HPOS dinonaktifkan

**Tahap 2 — Playwright** (npm sudah ada)
- [ ] `npm install -D @playwright/test`
- [ ] Enam skenario di bagian 4 lapis 1
- [ ] Jalankan sebelum setiap perubahan besar

**Tahap 3 — unit JS** (opsional)
- [ ] Ekspor bersyarat di akhir `admin.js`
- [ ] Vitest untuk enam fungsi murni

**Tahap 4 — PHP** (perlu keputusan tooling)
- [ ] Pasang Composer
- [ ] Catat sebagai ADR — ini mengubah karakter plugin
- [ ] Mulai dari `csv_cell()`, `set_enum()`, `apply_fields()`

**Jangan lompat ke tahap 4.** Composer dan PHPUnit adalah investasi tooling
terbesar dengan nilai paling rendah untuk plugin berbentuk seperti ini.

---

## 7. Uji Manual — Yang Berlaku Sekarang

Sampai ada otomasi, ini yang harus dijalankan setelah setiap perubahan.
Diurutkan menurut kemungkinan menemukan kerusakan:

1. **Render berulang** — Apply Filters 3×, periksa jumlah option di dropdown
   tidak bertambah *(regresi v3.11)*
2. **Edit → Save → reload** — nilai bertahan
3. **Edit → kembalikan ke nilai asli** — tanda dirty hilang
4. **Console bersih** — nol error setelah membuka semua modal
5. **Query Monitor** — nol PHP notice, jumlah query tidak melonjak saat
   paginasi
6. **Aktifkan semua 29 kolom** — jumlah query tidak berubah
7. **Sebagai `shop_manager`** — bukan hanya administrator
8. **Bulk action** — trash dan duplicate masih bekerja
9. **Export CSV** — buka di spreadsheet, pastikan formula tidak dieksekusi

Nomor 1, 6, dan 7 adalah yang paling sering terlewat dan paling sering
menyembunyikan masalah.

---

## 8. Kalau Menambah Kode Baru

Sebelum menganggap selesai:

- [ ] Field baru: uji semua lima langkah di
      [ARCHITECTURE.md](ARCHITECTURE.md#dispatch-field-apply_fields) —
      melewatkan `origVal()` menyebabkan bug diam-diam
- [ ] Renderer baru: uji dengan render berulang, bukan sekali
- [ ] Handler AJAX baru: uji tanpa `manage_woocommerce` — harus 403
- [ ] Perubahan query: bandingkan jumlah query sebelum dan sesudah
- [ ] Perubahan JS: `node --check assets/admin.js`
- [ ] Perubahan escape: uji dengan nilai `<img src=x onerror=alert(1)>`

---

## Rujukan

- [ADR 0001](adr/0001-monolit-satu-file.md) — kenapa unit test PHP mahal di sini
- [ADR 0002](adr/0002-tanpa-build-step.md) — kenapa `admin.js` sulit di-import
- [THREAT-MODEL.md](THREAT-MODEL.md#8-cara-mengaudit-ulang) — perintah audit
  keamanan
- [PERFORMANCE.md](PERFORMANCE.md#7-cara-mengukur) — cara mengukur dengan
  Query Monitor
