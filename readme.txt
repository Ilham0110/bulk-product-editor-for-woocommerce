=== Bulk Product Editor for WooCommerce ===
Contributors: ilhamdarmawan
Tags: woocommerce, bulk edit, products, inline edit, spreadsheet
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 3.12.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Edit hundreds of WooCommerce products from one spreadsheet-style table, without opening each product page.

== Description ==

Changing the price of 200 products through the stock WooCommerce admin means 200 page loads, 200 clicks on Update, and 200 waits for the page to come back.

This plugin puts every product in one table you can type straight into, then saves all of your edits in a single request.

**Inline editing**

Click any cell, type, move to the next one. Changed cells are highlighted until they are saved. Press Ctrl+S (or Cmd+S) to commit everything at once, or Discard to put it all back.

**30 selectable columns, 28 of them editable**

From product name, price, stock and SKU through to dimensions, tax class, shipping class, visibility, purchase note and menu order. Your column choice is stored per user, so every administrator gets their own layout.

The Product Name column keeps a link to the full product screen and the product ID alongside the input, so a row stays recognisable while you are renaming it.

**Filters and saved views**

Filter by keyword, category, type, status, stock status and featured flag. A filter combination you use often can be saved as a view and recalled with one click.

**Quick Apply**

Change many prices at once: raise by a percentage, lower by a fixed amount, or set them all to the same value. Nothing is written until you press Save.

**Other features**

* Bulk actions: duplicate, move to trash, delete permanently
* Quick Add for creating a product without leaving the page
* Create a product category from inside the editor
* CSV export with formula-injection protection

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate it through the **Plugins** menu in WordPress
3. Open **WooCommerce > Bulk Editor**

WooCommerce must be active. There is no build step and no Composer or npm dependency.

== Frequently Asked Questions ==

= Does it support variable products? =

It edits the parent product. Per-variation prices and stock are still managed on each product's own screen.

= Why can't I type into the Stock Qty column? =

That product has "Manage Stock" turned off. Entering a quantity switches it on for you — WooCommerce discards a stock figure when stock management is disabled.

= Is it compatible with HPOS? =

Yes. The plugin declares compatibility with High-Performance Order Storage, although it only ever touches products, never orders.

= How many products can be shown per page? =

Up to 100. The limit is deliberate: beyond that the browser struggles to render a table with many columns.

= Who can use the editor? =

Users with the `manage_woocommerce` capability, which covers Administrators and Shop Managers. Deleting a product also requires `delete_post` for that product, and editing requires `edit_post`, so role restrictions from other plugins are respected.

= Will my data be removed if I uninstall the plugin? =

Only this plugin's own preferences — your column choice and saved views. Products, categories and other WooCommerce data are left untouched.

= Does it work on a multisite network? =

Yes. Uninstalling clears the stored preferences on every site in the network.

== Screenshots ==

1. The editor table with inline editing and change markers
2. The column picker, with drag-to-reorder
3. The Quick Apply panel for bulk price changes

== Changelog ==

= 3.12.1 =
* Fixed: a backslash in a product name, description or short description was silently dropped when saving, so "C:\Users\Public" became "C:UsersPublic". These three fields live in the posts table, which strips one level of escaping as it writes.
* Fixed: a page of 100 products froze the interface for about twelve seconds after the rows appeared. Two faults in the textarea auto-sizing: every field forced a full page re-layout as it was set up, and fields from previous pages were never released.

= 3.12.0 =
* New: product names can now be edited directly in the table. The Product Name column still shows a link to the full product screen and the product ID, so rows stay recognisable while being renamed.
* A product name cannot be blank — this is rejected in the browser and again on the server, so a product without a title is never saved.
* Column widths are now measured from their content rather than fixed numbers: a select is sized to its longest option, and columns whose contents depend on your store (categories, tax classes) fit the names you actually use.
* Price columns keep room for large figures, so a value such as 100,000,000 is not clipped as you type it.
* Column widths are now identical on every screen size. Previously columns stretched to almost double width on a wide monitor.
* Fixed: pressing Discard no longer changes the row height.
* Fixed: the name cell now lines up with the other columns.
* Fixed: bulk action results use correct plural forms.
* Security: duplicating a product now checks the `read_post` capability per product, matching the checks already made for trash and delete.
* Security: the editor screen refuses direct URL access for users without `manage_woocommerce`.
* An expired session is now reported as such, with a prompt to reload, instead of a generic error.
* All column labels and interface strings can now be translated, and `languages/` ships with a POT file.

= 3.11.0 =
* Security: tax class and shipping class option labels are now escaped. A tax class name containing HTML could previously be executed in the browser.
* Security: editing a product now checks the `edit_post` capability per product, matching the checks already made for trash and delete.
* Fixed: options in the tax class and shipping class dropdowns no longer multiply each time the table is redrawn.
* Fixed: the selected option is now marked by comparing values rather than by string manipulation, which could target the wrong entry.
* Added `uninstall.php` — per-user preferences are now cleaned up when the plugin is deleted.
* All JavaScript interface text can now be translated.
* Plugin header completed: `Requires Plugins`, `Domain Path`, `License`, and WooCommerce compatibility information.

= 3.10.0 =
* The first page of products is now sent with the page itself, so the table appears without waiting for an AJAX request.

== Upgrade Notice ==

= 3.12.0 =
Adds product name editing, fixes column widths on wide screens, and tightens two permission checks. Updating is recommended.

= 3.11.0 =
Contains security fixes (output escaping and permission checks). Updating is recommended.
