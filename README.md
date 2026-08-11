# Bulk Product Editor for WooCommerce

Edit hundreds of WooCommerce products from a single spreadsheet-like table —
without opening each product's edit page one by one.

**Version 3.12.0**

---

## Why This Plugin Exists

Changing the price of 200 products in the default WooCommerce admin means 200
page loads, 200 Update clicks, and 200 waits for reload. This plugin puts
everything in one directly-editable table, then saves all changes in a single
request.

## Features

**Inline editing**
Click any cell, type, move on. Changed cells stay highlighted until saved.
Press **Ctrl+S** (or ⌘+S) to save every change at once. The *Discard* button
restores everything back to the server values.

**30 selectable columns, 28 editable**
From product name, price, stock, and SKU to dimensions, tax class, shipping
class, visibility, purchase note, and menu order. Pick the columns you need
via the **Columns** button — drag to reorder. Column choices are stored
per-user, so each admin gets their own layout.

The **Product Name** column includes a link to the full product page and its
ID, so rows stay recognizable even while the name is being edited. The name
cannot be left empty.

**Filters & Saved Views**
Filter products by keyword (name/SKU), category, type, status, stock status,
and featured. Frequently-used filter combinations can be saved as **Views**
and recalled with one click.

**Quick Apply**
Change prices across many products at once with relative operations: raise by
10%, lower by a fixed amount, or set to a fixed value. For more complex
changes, **Advanced Bulk Edit** is available.

**Bulk actions**
Duplicate, move to trash, or permanently delete selected products.

**Quick Add**
Add a new product directly from this page without navigating away.

**New Category**
Create a new product category from inside the editor — no need to open the
taxonomy page.

**CSV Export**
Export selected products to CSV (12 columns: ID, name, SKU, price, stock,
status, type, categories, tags, weight). Values starting with `=`, `+`, `-`,
or `@` are automatically prefixed with a quote so they are not executed as
formulas when opened in Excel or Google Sheets.

## Requirements

| Component | Minimum |
|---|---|
| PHP | 8.3 |
| WooCommerce | Must be active — the plugin will not run without it |
| Capability | `manage_woocommerce` (Administrator & Shop Manager) |

Compatible with **HPOS** (High-Performance Order Storage).

## Installation

1. Copy the `bulk-product-editor-for-woocommerce` folder into `wp-content/plugins/`.
2. Activate via **Plugins** in the WordPress admin.
3. Open **WooCommerce → Bulk Editor**.

There is no build step. No Composer or npm dependencies — the plugin runs as-is.

## Usage

1. Open **WooCommerce → Bulk Editor**. The table loads immediately with the
   50 most recent products.
2. Set filters if needed, then click **Apply Filters**.
3. Click a cell to edit. Changed cells are highlighted.
4. Click **Save All** or press **Ctrl+S**.

For bulk changes such as a store-wide discount, use the **Quick Apply** panel
above the table — pick an operation, enter a value, apply to the selected
products.

### Important notes

- **Entering a Stock Qty automatically enables "Manage Stock"** on that
  product. WooCommerce ignores the stock number when stock management is off,
  so the plugin turns it on for you.
- **Sale price** is validated against the regular price.
- **Variable products**: the table edits the parent product's data.
  Per-variation price and stock are still managed from the product page.
- Maximum **100 products per page**. This limit is deliberate — beyond it the
  browser starts struggling to render a table with many columns.

## Code Structure

```
bulk-product-editor-for-woocommerce/
├── bulk-product-editor-for-woocommerce.php    1349 lines — all PHP logic in one class
├── uninstall.php           51 lines  — cleans up preferences when the plugin is deleted
├── views/admin-page.php   340 lines  — admin page markup
├── assets/admin.js       2213 lines  — the entire UI (jQuery)
├── assets/admin.css       450 lines  — styling
├── languages/                        — bulk-product-editor-for-woocommerce.pot, 156 strings
├── readme.txt                        — WordPress.org format
├── LICENSE                           — GPL v2
│
├── docs/                             — architecture, security, i18n, ADRs
├── build.php                         — creates the release ZIP (CLI only)
└── .distignore                       — what is excluded from the release package
```

The first eight files are the plugin. The rest are for development and are
not included in the release package.

Architecture, data flow, and contribution rules are documented in
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) and [`docs/adr/`](docs/adr/).

## Building a Release Package

```bash
php -d extension=zip build.php
```

This produces `../bulk-product-editor-for-woocommerce-build/<slug>.<version>.zip`
containing the 8 files (253 KB) the plugin needs to run. The script refuses to
continue if the plugin header version does not match the `Stable tag` in
`readme.txt`, if any required file is missing, or if any development file
slips in. Details in
[`docs/WORDPRESS-ORG.md`](docs/WORDPRESS-ORG.md#6b-membuat-paket-rilis).

## Security

Every AJAX endpoint verifies a nonce and the `manage_woocommerce` capability
before doing anything. Trash and delete require a per-product `delete_post`
check; category creation requires `manage_product_terms`. All product changes
are written through the WooCommerce CRUD API rather than direct
`update_post_meta()` calls, so WooCommerce hooks and caches stay consistent.

## License

GPL-2.0-or-later, following WordPress and WooCommerce.
