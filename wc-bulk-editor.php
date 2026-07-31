<?php

declare(strict_types=1);

/**
 * Plugin Name:          Bulk Product Editor for WooCommerce
 * Plugin URI:           https://github.com/Ilham0110/wc-bulk-editor
 * Description:          Spreadsheet-style inline editing for WooCommerce products.
 * Version:              3.12.0
 * Author:               Ilham Darmawan
 * Author URI:           https://github.com/Ilham0110
 * Requires at least:    6.5
 * Requires PHP:         8.3
 * Requires Plugins:     woocommerce
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          wc-bulk-editor
 * Domain Path:          /languages
 * WC requires at least: 9.0
 * WC tested up to:      10.9
 */

defined('ABSPATH') || exit();

if (!in_array('woocommerce/woocommerce.php', (array) apply_filters('active_plugins', get_option('active_plugins')), true)) {
    return;
}

define('WCBULK_VERSION', '3.12.0');
define('WCBULK_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCBULK_PLUGIN_URL', plugin_dir_url(__FILE__));

add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

final class WC_Bulk_Product_Editor
{
    private const CAPABILITY   = 'manage_woocommerce';
    private const NONCE        = 'wc_bulk_editor_nonce';
    private const PAGE_SLUG    = 'wc-bulk-editor';
    private const SCREEN_ID    = 'woocommerce_page_wc-bulk-editor';
    private const META_COLUMNS = '_wcbulk_columns';
    private const META_VIEWS   = '_wcbulk_views';
    private const MAX_PER_PAGE = 100;

    /** Post statuses a product may be listed or saved with. */
    private const POST_STATUSES = ['publish', 'draft', 'pending', 'private'];

    /**
     * Every column the editor knows about.
     *
     * default  — shown until the user customises their columns
     * editable — rendered as an input rather than read-only text
     */
    private const COLUMNS = [
        'thumb'              => ['label' => 'Image',             'default' => true],
        'name'               => ['label' => 'Product Name',      'default' => true,  'editable' => true],
        'sku'                => ['label' => 'SKU',               'default' => true,  'editable' => true],
        'regular_price'      => ['label' => 'Regular Price',     'default' => true,  'editable' => true],
        'sale_price'         => ['label' => 'Sale Price',        'default' => true,  'editable' => true],
        'stock_quantity'     => ['label' => 'Stock Qty',         'default' => true,  'editable' => true],
        'stock_status'       => ['label' => 'Stock Status',      'default' => true,  'editable' => true],
        'post_status'        => ['label' => 'Status',            'default' => true,  'editable' => true],
        'categories'         => ['label' => 'Categories',        'default' => true,  'editable' => true],
        'tags'               => ['label' => 'Tags',              'default' => false, 'editable' => true],
        'type'               => ['label' => 'Type',              'default' => false],
        'tax_status'         => ['label' => 'Tax Status',        'default' => false, 'editable' => true],
        'tax_class'          => ['label' => 'Tax Class',         'default' => false, 'editable' => true],
        'shipping_class'     => ['label' => 'Shipping Class',    'default' => false, 'editable' => true],
        'weight'             => ['label' => 'Weight',            'default' => false, 'editable' => true],
        'length'             => ['label' => 'Length',            'default' => false, 'editable' => true],
        'width'              => ['label' => 'Width',             'default' => false, 'editable' => true],
        'height'             => ['label' => 'Height',            'default' => false, 'editable' => true],
        'featured'           => ['label' => 'Featured',          'default' => false, 'editable' => true],
        'catalog_visibility' => ['label' => 'Visibility',        'default' => false, 'editable' => true],
        'description'        => ['label' => 'Description',       'default' => false, 'editable' => true],
        'short_description'  => ['label' => 'Short Desc',        'default' => false, 'editable' => true],
        'virtual'            => ['label' => 'Virtual',           'default' => false, 'editable' => true],
        'downloadable'       => ['label' => 'Downloadable',      'default' => false, 'editable' => true],
        'manage_stock'       => ['label' => 'Manage Stock',      'default' => false, 'editable' => true],
        'backorders'         => ['label' => 'Backorders',        'default' => false, 'editable' => true],
        'sold_individually'  => ['label' => 'Sold Individually', 'default' => false, 'editable' => true],
        'reviews_allowed'    => ['label' => 'Reviews',           'default' => false, 'editable' => true],
        'purchase_note'      => ['label' => 'Purchase Note',     'default' => false, 'editable' => true],
        'menu_order'         => ['label' => 'Menu Order',        'default' => false, 'editable' => true],
    ];

    /** Product setters that take a plain scalar, keyed by field name. */
    private const SCALAR_SETTERS = [
        'sku'               => 'set_sku',
        'regular_price'     => 'set_regular_price',
        'sale_price'        => 'set_sale_price',
        'stock_quantity'    => 'set_stock_quantity',
        'weight'            => 'set_weight',
        'length'            => 'set_length',
        'width'             => 'set_width',
        'height'            => 'set_height',
        'description'       => 'set_description',
        'short_description' => 'set_short_description',
        'purchase_note'     => 'set_purchase_note',
        'menu_order'        => 'set_menu_order',
    ];

    /** Product setters that take a yes/no boolean. */
    private const BOOL_SETTERS = [
        'featured'          => 'set_featured',
        'virtual'           => 'set_virtual',
        'downloadable'      => 'set_downloadable',
        'sold_individually' => 'set_sold_individually',
        'reviews_allowed'   => 'set_reviews_allowed',
        'manage_stock'      => 'set_manage_stock',
    ];

    /** Fields validated against a fixed set before they reach the product. */
    private const ENUM_SETTERS = [
        'stock_status'       => ['set_stock_status',       ['instock', 'outofstock', 'onbackorder']],
        'tax_status'         => ['set_tax_status',         ['taxable', 'shipping', 'none']],
        'catalog_visibility' => ['set_catalog_visibility', ['visible', 'catalog', 'search', 'hidden']],
        'backorders'         => ['set_backorders',         ['no', 'notify', 'yes']],
    ];

    private static ?self $instance = null;

    /** Placeholder image URL, resolved once per request instead of per row. */
    private ?string $placeholder_image = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        add_action('admin_menu', $this->add_admin_menu(...), 99);
        add_action('admin_enqueue_scripts', $this->enqueue_assets(...));

        foreach ($this->ajax_actions() as $action => $callback) {
            add_action('wp_ajax_' . $action, $callback);
        }
    }

    /** @return array<string, callable> */
    private function ajax_actions(): array
    {
        return [
            'wc_bulk_fetch_products'      => $this->wc_bulk_fetch_products(...),
            'wc_bulk_save_inline'         => $this->wc_bulk_save_inline(...),
            'wc_bulk_get_categories'      => $this->wc_bulk_get_categories(...),
            'wc_bulk_get_columns'         => $this->wc_bulk_get_columns(...),
            'wc_bulk_save_columns'        => $this->wc_bulk_save_columns(...),
            'wc_bulk_get_views'           => $this->wc_bulk_get_views(...),
            'wc_bulk_save_view'           => $this->wc_bulk_save_view(...),
            'wc_bulk_delete_view'         => $this->wc_bulk_delete_view(...),
            'wc_bulk_export_csv'          => $this->wc_bulk_export_csv(...),
            'wc_bulk_bulk_action'         => $this->wc_bulk_bulk_action(...),
            'wc_bulk_quick_add'           => $this->wc_bulk_quick_add(...),
            'wc_bulk_get_tax_classes'     => $this->wc_bulk_get_tax_classes(...),
            'wc_bulk_get_shipping_classes'=> $this->wc_bulk_get_shipping_classes(...),
            'wc_bulk_create_category'     => $this->wc_bulk_create_category(...),
        ];
    }
    // ---------------------------------------------------------------------
    // Admin screen
    // ---------------------------------------------------------------------

    public function add_admin_menu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Bulk Product Editor', 'wc-bulk-editor'),
            __('Bulk Editor', 'wc-bulk-editor'),
            self::CAPABILITY,
            self::PAGE_SLUG,
            $this->render_admin_page(...),
        );
    }

    public function enqueue_assets(string $hook): void
    {
        if ($hook !== self::SCREEN_ID) {
            return;
        }

        wp_enqueue_style(
            'wc-bulk-editor-css',
            WCBULK_PLUGIN_URL . 'assets/admin.css',
            [],
            $this->asset_version('assets/admin.css'),
        );

        // jquery-ui-sortable powers the drag-to-reorder list in the Columns modal.
        wp_enqueue_script(
            'wc-bulk-editor-js',
            WCBULK_PLUGIN_URL . 'assets/admin.js',
            ['jquery', 'jquery-ui-sortable', 'wp-util'],
            $this->asset_version('assets/admin.js'),
            true,
        );

        wp_localize_script('wc-bulk-editor-js', 'WCBulkEditor', [
            'ajax_url'    => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce(self::NONCE),
            'currency'    => get_woocommerce_currency_symbol(),
            'decimals'    => wc_get_price_decimals(),
            'thousands'   => wc_get_price_thousand_separator(),
            'decimal'     => wc_get_price_decimal_separator(),
            'columns'      => $this->get_user_columns(get_current_user_id()),
            'all_columns'  => $this->translated_columns(),
            'col_headers'  => $this->column_headers(),
            'all_cats'     => $this->get_all_categories(),
            // Preloaded so the first paint needs no AJAX at all. Each
            // admin-ajax round-trip costs a full WordPress bootstrap, which
            // dwarfs the queries themselves.
            'tax_classes'      => $this->get_tax_class_list(),
            'shipping_classes' => $this->get_shipping_class_list(),
            'preloaded'        => $this->get_initial_products(),
            'views'       => $this->get_saved_views(get_current_user_id()),
            'i18n'        => $this->i18n_strings(),
        ]);
    }

    /**
     * Column labels, translated.
     *
     * const COLUMNS cannot hold __() calls, so the labels live here and are
     * merged in on the way out. Runs during admin_enqueue_scripts — well after
     * init, so translations are loaded.
     *
     * @return array<string, string>
     */
    private function column_labels(): array
    {
        return [
            'thumb'              => __('Image', 'wc-bulk-editor'),
            'name'               => __('Product Name', 'wc-bulk-editor'),
            'sku'                => __('SKU', 'wc-bulk-editor'),
            'regular_price'      => __('Regular Price', 'wc-bulk-editor'),
            'sale_price'         => __('Sale Price', 'wc-bulk-editor'),
            'stock_quantity'     => __('Stock Qty', 'wc-bulk-editor'),
            'stock_status'       => __('Stock Status', 'wc-bulk-editor'),
            'post_status'        => __('Status', 'wc-bulk-editor'),
            'categories'         => __('Categories', 'wc-bulk-editor'),
            'tags'               => __('Tags', 'wc-bulk-editor'),
            'type'               => __('Type', 'wc-bulk-editor'),
            'tax_status'         => __('Tax Status', 'wc-bulk-editor'),
            'tax_class'          => __('Tax Class', 'wc-bulk-editor'),
            'shipping_class'     => __('Shipping Class', 'wc-bulk-editor'),
            'weight'             => __('Weight', 'wc-bulk-editor'),
            'length'             => __('Length', 'wc-bulk-editor'),
            'width'              => __('Width', 'wc-bulk-editor'),
            'height'             => __('Height', 'wc-bulk-editor'),
            'featured'           => __('Featured', 'wc-bulk-editor'),
            'catalog_visibility' => __('Visibility', 'wc-bulk-editor'),
            'description'        => __('Description', 'wc-bulk-editor'),
            'short_description'  => __('Short Desc', 'wc-bulk-editor'),
            'virtual'            => __('Virtual', 'wc-bulk-editor'),
            'downloadable'       => __('Downloadable', 'wc-bulk-editor'),
            'manage_stock'       => __('Manage Stock', 'wc-bulk-editor'),
            'backorders'         => __('Backorders', 'wc-bulk-editor'),
            'sold_individually'  => __('Sold Individually', 'wc-bulk-editor'),
            'reviews_allowed'    => __('Reviews', 'wc-bulk-editor'),
            'purchase_note'      => __('Purchase Note', 'wc-bulk-editor'),
            'menu_order'         => __('Menu Order', 'wc-bulk-editor'),
        ];
    }

    /**
     * Short labels for the table header.
     *
     * Deliberately terser than column_labels(): a header has to fit a narrow
     * column, while the Columns modal has room for the full name. Keys absent
     * here fall back to the JS-side defaults.
     *
     * @return array<string, string>
     */
    private function column_headers(): array
    {
        return [
            'name'               => __('Product', 'wc-bulk-editor'),
            'sku'                => __('SKU', 'wc-bulk-editor'),
            'regular_price'      => __('Price', 'wc-bulk-editor'),
            'sale_price'         => __('Sale', 'wc-bulk-editor'),
            'stock_quantity'     => __('Stock', 'wc-bulk-editor'),
            'stock_status'       => __('Stock Status', 'wc-bulk-editor'),
            'post_status'        => __('Status', 'wc-bulk-editor'),
            'categories'         => __('Categories', 'wc-bulk-editor'),
            'tags'               => __('Tags', 'wc-bulk-editor'),
            'type'               => __('Type', 'wc-bulk-editor'),
            'tax_status'         => __('Tax', 'wc-bulk-editor'),
            'tax_class'          => __('Tax Class', 'wc-bulk-editor'),
            'shipping_class'     => __('Shipping', 'wc-bulk-editor'),
            'weight'             => __('Weight', 'wc-bulk-editor'),
            'length'             => __('Length', 'wc-bulk-editor'),
            'width'              => __('Width', 'wc-bulk-editor'),
            'height'             => __('Height', 'wc-bulk-editor'),
            'catalog_visibility' => __('Visibility', 'wc-bulk-editor'),
            /* translators: abbreviated column header for "Virtual". */
            'virtual'            => __('Virt', 'wc-bulk-editor'),
            /* translators: abbreviated column header for "Downloadable". */
            'downloadable'       => __('DL', 'wc-bulk-editor'),
            /* translators: abbreviated column header for "Manage Stock". */
            'manage_stock'       => __('Mgmt', 'wc-bulk-editor'),
            /* translators: abbreviated column header for "Backorders". */
            'backorders'         => __('Backord', 'wc-bulk-editor'),
            'sold_individually'  => __('Sold Ind.', 'wc-bulk-editor'),
            'reviews_allowed'    => __('Reviews', 'wc-bulk-editor'),
            'description'        => __('Description', 'wc-bulk-editor'),
            'short_description'  => __('Short Desc', 'wc-bulk-editor'),
            'purchase_note'      => __('Purch. Note', 'wc-bulk-editor'),
            'menu_order'         => __('Order', 'wc-bulk-editor'),
        ];
    }

    /**
     * The column catalogue with translated labels, ready for the browser.
     *
     * @return array<string, array{label: string, default: bool, editable?: bool}>
     */
    private function translated_columns(): array
    {
        $labels  = $this->column_labels();
        $columns = [];

        foreach (self::COLUMNS as $key => $meta) {
            // Fall back to the English constant if a key is ever added to
            // COLUMNS without a matching label, so nothing renders blank.
            $columns[$key] = ['label' => $labels[$key] ?? $meta['label']] + $meta;
        }

        return $columns;
    }

    /** @return array<string, string> */
    private function i18n_strings(): array
    {
        return [
            'confirm_save'         => __('Save changes for {count} product(s)?', 'wc-bulk-editor'),
            'no_changes'          => __('No changes detected.', 'wc-bulk-editor'),
            'saving'              => __('Saving...', 'wc-bulk-editor'),
            'saved'               => __('All changes saved!', 'wc-bulk-editor'),
            'error'               => __('An error occurred.', 'wc-bulk-editor'),
            'loading'             => __('Loading...', 'wc-bulk-editor'),
            'no_results'          => __('No products found.', 'wc-bulk-editor'),
            'confirm_delete'      => __('Delete {count} product(s)?', 'wc-bulk-editor'),
            'confirm_trash'       => __('Move {count} product(s) to trash?', 'wc-bulk-editor'),
            'confirm_duplicate'   => __('Duplicate {count} product(s)?', 'wc-bulk-editor'),
            'bulk_deleted'        => __('{count} product(s) deleted.', 'wc-bulk-editor'),
            'bulk_trashed'        => __('{count} product(s) moved to trash.', 'wc-bulk-editor'),
            'bulk_duplicated'     => __('{count} product(s) duplicated.', 'wc-bulk-editor'),
            'no_products_selected'=> __('Select products first.', 'wc-bulk-editor'),
            'view_saved'          => __('View saved.', 'wc-bulk-editor'),
            'no_views'            => __('No saved views yet — set your filters, then click Save View.', 'wc-bulk-editor'),
            'cat_name_required'   => __('Category name is required.', 'wc-bulk-editor'),
            'cat_created'         => __('Category created.', 'wc-bulk-editor'),
            'cat_exists'          => __('That category already exists.', 'wc-bulk-editor'),
            'new_category'        => __('New Category', 'wc-bulk-editor'),
            'no_category'         => __('— None —', 'wc-bulk-editor'),
            'view_deleted'        => __('View deleted.', 'wc-bulk-editor'),
            'csv_exported'        => __('CSV exported.', 'wc-bulk-editor'),
            'product_created'     => __('Product created!', 'wc-bulk-editor'),
            'columns_saved'       => __('Columns saved.', 'wc-bulk-editor'),

            // Quick Apply, Advanced Bulk Edit and saved views.
            'confirm_discard'     => __('Discard all changes?', 'wc-bulk-editor'),
            'changes_discarded'   => __('Changes discarded.', 'wc-bulk-editor'),
            'select_field'        => __('Select a field first.', 'wc-bulk-editor'),
            'quick_applied'       => __('Applied to all loaded products.', 'wc-bulk-editor'),
            'select_one_field'    => __('Select at least one field to change.', 'wc-bulk-editor'),
            'confirm_bulk_edit'   => __('Apply changes to {count} product(s)?', 'wc-bulk-editor'),
            'product_name_req'    => __('Product name is required.', 'wc-bulk-editor'),
            'no_export'           => __('No products to export.', 'wc-bulk-editor'),
            'view_name_prompt'    => __('View name:', 'wc-bulk-editor'),
            'confirm_delete_view' => __('Delete this view?', 'wc-bulk-editor'),

            // Bulk edit modal, Quick Add button states and session handling.
            'selected_count'      => __('{count} selected', 'wc-bulk-editor'),
            'no_selection'        => __('No selection (applies to all loaded)', 'wc-bulk-editor'),
            'changes_staged'      => __('Changes staged for {count} product(s). Click Save All to commit.', 'wc-bulk-editor'),
            'creating'            => __('Creating...', 'wc-bulk-editor'),
            'create_product'      => __('Create Product', 'wc-bulk-editor'),
            'session_expired'     => __('Your session expired. Reload the page and try again.', 'wc-bulk-editor'),
            'open_product'        => __('Open product editor', 'wc-bulk-editor'),
            'name_required'       => __('Product name cannot be empty.', 'wc-bulk-editor'),
            /* translators: {id} is replaced with the numeric product id. */
            'product_id_title'    => __('Product ID {id}', 'wc-bulk-editor'),
        ];
    }

    /**
     * Cache-busting version for an asset: the file's mtime in development,
     * falling back to the plugin version if the file is unreadable. Stops
     * browsers serving a stale stylesheet after an edit.
     */
    private function asset_version(string $relative_path): string
    {
        $path = WCBULK_PLUGIN_DIR . $relative_path;

        if (!is_readable($path)) {
            return WCBULK_VERSION;
        }

        $mtime = filemtime($path);

        return $mtime !== false ? (string) $mtime : WCBULK_VERSION;
    }

    // ---------------------------------------------------------------------
    // User preferences
    // ---------------------------------------------------------------------

    /** @return list<string> */
    private function get_user_columns(int $uid): array
    {
        $saved = get_user_meta($uid, self::META_COLUMNS, true);

        if (is_array($saved) && $saved !== []) {
            return $saved;
        }

        return array_keys(array_filter(self::COLUMNS, static fn(array $c): bool => $c['default']));
    }

    /** @return list<array<string, mixed>> */
    private function get_saved_views(int $uid): array
    {
        $views = get_user_meta($uid, self::META_VIEWS, true);

        return is_array($views) ? $views : [];
    }

    /** All product_cat terms, used to build the inline Categories multi-select. */
    private function get_all_categories(): array
    {
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name']);

        if (!is_array($terms)) {
            return [];
        }

        return array_map(
            static fn(WP_Term $t): array => ['id' => $t->term_id, 'name' => $t->name],
            $terms,
        );
    }

    // ---------------------------------------------------------------------
    // AJAX plumbing
    // ---------------------------------------------------------------------

    /**
     * Verify nonce and capability. Every AJAX handler starts with this.
     */
    private function guard(): void
    {
        check_ajax_referer(self::NONCE, 'nonce');

        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(['message' => __('Permission denied.', 'wc-bulk-editor')], 403);
        }
    }

    private function post_string(string $key, string $default = ''): string
    {
        return sanitize_text_field(wp_unslash((string) ($_POST[$key] ?? $default)));
    }

    /** @return list<int> */
    private function post_ids(string $key): array
    {
        $ids = array_map('absint', (array) ($_POST[$key] ?? []));

        return array_values(array_filter($ids));
    }
    // ---------------------------------------------------------------------
    // AJAX: read
    // ---------------------------------------------------------------------

    public function wc_bulk_fetch_products(): void
    {
        $this->guard();

        // absint() takes the absolute value, so a negative page would silently
        // become a positive one — cast first, then clamp.
        $page     = max(1, (int) ($_POST['page'] ?? 1));
        $per_page = min(max(1, (int) ($_POST['per_page'] ?? 50) ?: 50), self::MAX_PER_PAGE);
        $status   = $this->post_string('status');

        $args = [
            'limit'    => $per_page,
            'page'     => $page,
            'paginate' => true,
            'orderby'  => 'ID',
            'order'    => 'DESC',
            'status'   => $status !== '' ? $status : self::POST_STATUSES,
        ];

        if (($search = $this->post_string('search')) !== '') {
            $args['s'] = $search;
        }
        if (($category = absint($_POST['category'] ?? 0)) > 0) {
            $args['product_category_id'] = [$category];
        }
        if (($type = $this->post_string('type')) !== '') {
            $args['type'] = $type;
        }
        if (($stock_status = $this->post_string('stock_status')) !== '') {
            $args['stock_status'] = $stock_status;
        }

        $featured = $this->post_string('featured');
        if ($featured === 'yes' || $featured === 'no') {
            $args['featured'] = $featured === 'yes';
        }

        $payload = $this->build_product_payload($args, $page);

        if ($payload === null) {
            wp_send_json_error(['message' => __('Query failed.', 'wc-bulk-editor')]);
        }

        wp_send_json_success($payload);
    }

    /**
     * Run the product query and shape it for the table.
     *
     * Shared by the AJAX handler and the initial page preload, so both return
     * exactly the same structure. Returns null when the query fails.
     */
    private function build_product_payload(array $args, int $page): ?array
    {
        $query = wc_get_products($args);

        if (!$query || !is_object($query)) {
            return null;
        }

        $products = $query->products;
        $terms    = $this->collect_terms(array_map(static fn($p): int => $p->get_id(), $products));

        return [
            'products'     => array_map(fn($p): array => $this->product_to_row($p, $terms), $products),
            'total'        => $query->total,
            'total_pages'  => $query->max_num_pages,
            'current_page' => $page,
        ];
    }

    /**
     * First page of products, embedded in the page so the table paints without
     * waiting for an AJAX round-trip.
     */
    private function get_initial_products(): ?array
    {
        return $this->build_product_payload([
            'limit'    => 50,
            'page'     => 1,
            'paginate' => true,
            'orderby'  => 'ID',
            'order'    => 'DESC',
            'status'   => self::POST_STATUSES,
        ], 1);
    }

    /**
     * Fetch every product_cat / product_tag term for a set of products in a
     * single query, instead of two-plus queries per product inside the loop.
     *
     * @param  list<int> $ids
     * @return array<int, array{cat_names: list<string>, cat_ids: list<int>, tag_names: list<string>}>
     */
    private function collect_terms(array $ids): array
    {
        $map = [];

        if ($ids === []) {
            return $map;
        }

        $terms = wp_get_object_terms($ids, ['product_cat', 'product_tag'], ['fields' => 'all_with_object_id']);

        if (is_wp_error($terms)) {
            return $map;
        }

        foreach ($terms as $term) {
            $pid = (int) $term->object_id;

            $map[$pid] ??= ['cat_names' => [], 'cat_ids' => [], 'tag_names' => []];

            if ($term->taxonomy === 'product_cat') {
                $map[$pid]['cat_names'][] = $term->name;
                $map[$pid]['cat_ids'][]   = (int) $term->term_id;
            } else {
                $map[$pid]['tag_names'][] = $term->name;
            }
        }

        return $map;
    }

    /**
     * Flatten one product into the row shape the JS table expects.
     *
     * @param array<int, array{cat_names: list<string>, cat_ids: list<int>, tag_names: list<string>}> $terms
     */
    private function product_to_row(WC_Product $p, array $terms): array
    {
        $pid   = $p->get_id();
        $t     = $terms[$pid] ?? ['cat_names' => [], 'cat_ids' => [], 'tag_names' => []];
        $image = $p->get_image_id();

        return [
            'id'                 => $pid,
            'name'               => $p->get_name(),
            'sku'                => $p->get_sku(),
            'regular_price'      => $p->get_regular_price(),
            'sale_price'         => $p->get_sale_price(),
            'price'              => $p->get_price(),
            'stock_quantity'     => $p->get_manage_stock() ? (int) $p->get_stock_quantity() : null,
            'stock_status'       => $p->get_stock_status(),
            'manage_stock'       => $p->get_manage_stock(),
            'status'             => $p->get_status(),
            'type'               => $p->get_type(),
            'categories'         => $t['cat_names'],
            'category_ids'       => $t['cat_ids'],
            'tags'               => $t['tag_names'],
            'image_url'          => $image ? wp_get_attachment_image_url($image, 'thumbnail') : $this->placeholder_image(),
            'edit_url'           => get_edit_post_link($pid, ''),
            'tax_status'         => $p->get_tax_status(),
            'tax_class'          => $p->get_tax_class(),
            'shipping_class'     => $p->get_shipping_class(),
            'shipping_class_id'  => $p->get_shipping_class_id(),
            'weight'             => $p->get_weight(),
            'length'             => $p->get_length(),
            'width'              => $p->get_width(),
            'height'             => $p->get_height(),
            'featured'           => $p->get_featured(),
            'catalog_visibility' => $p->get_catalog_visibility(),
            'virtual'            => $p->get_virtual(),
            'downloadable'       => $p->get_downloadable(),
            'backorders'         => $p->get_backorders(),
            'sold_individually'  => $p->get_sold_individually(),
            'reviews_allowed'    => $p->get_reviews_allowed(),
            'purchase_note'      => $p->get_purchase_note(),
            'menu_order'         => $p->get_menu_order(),
            'description'        => $p->get_description(),
            'short_description'  => $p->get_short_description(),
        ];
    }

    /** Resolved once per request; the lookup hits the options table. */
    private function placeholder_image(): string
    {
        return $this->placeholder_image ??= (string) wc_placeholder_img_src('thumbnail');
    }

    public function wc_bulk_get_categories(): void
    {
        $this->guard();

        $cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name']);
        $list = is_array($cats)
            ? array_map(
                static fn(WP_Term $c): array => ['id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug],
                $cats,
            )
            : [];

        wp_send_json_success(['categories' => $list]);
    }

    /**
     * Create a product category and return the refreshed list.
     *
     * Used by the inline Categories editor so a new term can be added without
     * leaving the bulk editor. A duplicate name under the same parent resolves
     * to the existing term rather than erroring, so a double submit is harmless.
     */
    public function wc_bulk_create_category(): void
    {
        $this->guard();

        if (!current_user_can('manage_product_terms')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wc-bulk-editor')], 403);
        }

        $name   = $this->post_string('name');
        $parent = absint($_POST['parent'] ?? 0);

        if ($name === '') {
            wp_send_json_error(['message' => __('Category name is required.', 'wc-bulk-editor')]);
        }

        if ($parent > 0 && !term_exists($parent, 'product_cat')) {
            $parent = 0;
        }

        $result = wp_insert_term($name, 'product_cat', ['parent' => $parent]);

        // An existing term is not a failure here - reuse it.
        if (is_wp_error($result)) {
            $existing = $result->get_error_data('term_exists');

            if ($existing === null) {
                wp_send_json_error(['message' => $result->get_error_message()]);
            }

            $term_id = (int) $existing;
            $created = false;
        } else {
            $term_id = (int) $result['term_id'];
            $created = true;
        }

        $term = get_term($term_id, 'product_cat');

        if (!$term instanceof WP_Term) {
            wp_send_json_error(['message' => __('Category could not be loaded.', 'wc-bulk-editor')]);
        }

        wp_send_json_success([
            'category' => ['id' => $term->term_id, 'name' => $term->name],
            'created'  => $created,
            'all_cats' => $this->get_all_categories(),
        ]);
    }

    /** @return list<array{slug: string, name: string}> */
    private function get_tax_class_list(): array
    {
        $list = [['slug' => '', 'name' => __('Standard', 'wc-bulk-editor')]];

        foreach (WC_Tax::get_tax_classes() as $class) {
            $list[] = ['slug' => sanitize_title($class), 'name' => $class];
        }

        return $list;
    }

    public function wc_bulk_get_tax_classes(): void
    {
        $this->guard();

        wp_send_json_success(['tax_classes' => $this->get_tax_class_list()]);
    }

    /** @return list<array{id: int|string, name: string}> */
    private function get_shipping_class_list(): array
    {
        $list    = [['id' => '', 'name' => __('No shipping class', 'wc-bulk-editor')]];
        $classes = get_terms(['taxonomy' => 'product_shipping_class', 'hide_empty' => false]);

        if (is_array($classes)) {
            foreach ($classes as $class) {
                $list[] = ['id' => $class->term_id, 'name' => $class->name];
            }
        }

        return $list;
    }

    public function wc_bulk_get_shipping_classes(): void
    {
        $this->guard();

        wp_send_json_success(['shipping_classes' => $this->get_shipping_class_list()]);
    }
    // ---------------------------------------------------------------------
    // AJAX: write
    // ---------------------------------------------------------------------

    public function wc_bulk_save_inline(): void
    {
        $this->guard();

        $changes = (array) wp_unslash($_POST['changes'] ?? []);

        if ($changes === []) {
            wp_send_json_error(['message' => __('No changes.', 'wc-bulk-editor')]);
        }

        $updated = 0;
        $errors  = [];

        foreach ($changes as $pid => $fields) {
            $pid     = absint($pid);
            $product = wc_get_product($pid);

            if (!$product || !is_array($fields)) {
                continue;
            }

            // Per-object check, matching the one bulk_action() does for trash
            // and delete. manage_woocommerce implies full catalogue control on
            // stock WordPress, but multivendor plugins narrow edit_post per
            // product and that restriction has to hold here too.
            if (!current_user_can('edit_post', $pid)) {
                $errors[] = sprintf(
                    /* translators: 1: product id, 2: product name */
                    __('#%1$d %2$s: permission denied.', 'wc-bulk-editor'),
                    $pid,
                    $product->get_name(),
                );

                continue;
            }

            try {
                $this->apply_fields($product, $fields);
                $product->save();
                wc_delete_product_transients($pid);
                $updated++;
            } catch (Throwable $e) {
                $errors[] = sprintf('#%d %s: %s', $pid, $product->get_name(), $e->getMessage());
            }
        }

        $message = sprintf(
            /* translators: %d: number of products saved successfully. */
            _n('%d product updated.', '%d products updated.', $updated, 'wc-bulk-editor'),
            $updated,
        );

        if ($errors !== []) {
            $failed = sprintf(
                /* translators: %d: number of products that could not be saved. */
                _n('%d failed:', '%d failed:', count($errors), 'wc-bulk-editor'),
                count($errors)
            );

            wp_send_json_error([
                'updated' => $updated,
                'errors'  => $errors,
                'message' => $message . ' ' . $failed . ' ' . implode(' | ', $errors),
            ]);
        }

        wp_send_json_success(['updated' => $updated, 'message' => $message]);
    }
    /**
     * Write one row's submitted fields onto a product.
     *
     * @param array<string, mixed> $fields
     */
    private function apply_fields(WC_Product $product, array $fields): void
    {
        // WooCommerce silently discards _stock unless manage_stock is on, so a
        // quantity arriving on its own has to switch stock management on first.
        if (
            isset($fields['stock_quantity'])
            && $fields['stock_quantity'] !== ''
            && !isset($fields['manage_stock'])
            && !$product->get_manage_stock()
        ) {
            $fields = ['manage_stock' => 'yes'] + $fields;
        }

        foreach ($fields as $field => $value) {
            // Taxonomy fields accept arrays; everything else must be scalar.
            if (!is_scalar($value) && !in_array($field, ['categories', 'tags'], true)) {
                continue;
            }

            match (true) {
                isset(self::SCALAR_SETTERS[$field]) => $this->set_scalar($product, $field, $value),
                isset(self::BOOL_SETTERS[$field])   => $this->set_bool($product, $field, $value),
                isset(self::ENUM_SETTERS[$field])   => $this->set_enum($product, $field, $value),
                $field === 'name'                   => $this->set_name($product, $value),
                $field === 'post_status'            => $this->set_post_status($product, $value),
                $field === 'tax_class'              => $product->set_tax_class((string) $value),
                $field === 'shipping_class'         => $product->set_shipping_class_id(absint($value)),
                $field === 'categories'             => $this->set_categories($product, $value),
                $field === 'tags'                   => $this->set_tags($product, $value),
                default                             => null,
            };
        }
    }
    private function set_scalar(WC_Product $product, string $field, mixed $value): void
    {
        $setter = self::SCALAR_SETTERS[$field];
        $value  = (string) $value;

        $product->{$setter}(match ($field) {
            'regular_price', 'sale_price'      => $value === '' ? '' : (string) max(0, (float) $value),
            'stock_quantity', 'menu_order'     => $value === '' ? null : max(0, (int) $value),
            'description', 'short_description' => wp_kses_post($value),
            'purchase_note'                    => sanitize_textarea_field($value),
            default                            => $value === '' ? '' : sanitize_text_field($value),
        });
    }

    /**
     * Rename a product.
     *
     * Kept out of SCALAR_SETTERS because a blank name is not a valid state:
     * WordPress would show "(no title)" everywhere and the row would become
     * hard to identify. An empty submission is therefore ignored rather than
     * written, and the row keeps its previous name.
     */
    private function set_name(WC_Product $product, mixed $value): void
    {
        $name = sanitize_text_field((string) $value);

        if ($name === '') {
            throw new WC_Data_Exception(
                'wcbulk_empty_name',
                __('Product name cannot be empty.', 'wc-bulk-editor')
            );
        }

        $product->set_name($name);
    }

    private function set_bool(WC_Product $product, string $field, mixed $value): void
    {
        if ($value !== 'yes' && $value !== 'no') {
            return;
        }

        $setter = self::BOOL_SETTERS[$field];
        $product->{$setter}($value === 'yes');
    }

    private function set_enum(WC_Product $product, string $field, mixed $value): void
    {
        [$setter, $allowed] = self::ENUM_SETTERS[$field];

        if (in_array($value, $allowed, true)) {
            $product->{$setter}($value);
        }
    }

    private function set_post_status(WC_Product $product, mixed $value): void
    {
        if (in_array($value, self::POST_STATUSES, true)) {
            $product->set_status((string) $value);
        }
    }
    /**
     * Categories arrive either as an array of IDs or as a comma-joined string
     * from the inline multi-select. Mapping a comma string straight through
     * absint() would collapse it to the first ID and silently drop the rest.
     */
    private function set_categories(WC_Product $product, mixed $value): void
    {
        $raw = is_array($value) ? $value : explode(',', (string) $value);
        $ids = array_values(array_filter(array_map('absint', $raw)));

        wp_set_post_terms($product->get_id(), $ids, 'product_cat', false);
    }

    private function set_tags(WC_Product $product, mixed $value): void
    {
        $names = is_array($value) ? $value : explode(',', (string) $value);
        $names = array_values(array_filter(array_map('trim', $names)));

        wp_set_post_terms($product->get_id(), $names, 'product_tag', false);
    }

    public function wc_bulk_quick_add(): void
    {
        $this->guard();

        $name = $this->post_string('name');

        if ($name === '') {
            wp_send_json_error(['message' => __('Product name required.', 'wc-bulk-editor')]);
        }

        try {
            $product = new WC_Product_Simple();
            $product->set_name($name);

            if (($sku = $this->post_string('sku')) !== '') {
                $product->set_sku($sku);
            }

            if (isset($_POST['regular_price'])) {
                $product->set_regular_price((string) max(0, (float) $_POST['regular_price']));
            }

            $status = $this->post_string('status', 'publish');
            $product->set_status(in_array($status, ['publish', 'draft'], true) ? $status : 'draft');

            $id = $product->save();
        } catch (Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        wp_send_json_success(['id' => $id, 'edit_url' => get_edit_post_link($id, '')]);
    }
    public function wc_bulk_bulk_action(): void
    {
        $this->guard();

        $action = $this->post_string('bulk_action');
        $ids    = $this->post_ids('product_ids');

        if ($ids === [] || !in_array($action, ['duplicate', 'trash', 'delete'], true)) {
            wp_send_json_error(['message' => __('Nothing to do.', 'wc-bulk-editor')]);
        }

        $count = 0;

        foreach ($ids as $id) {
            $product = wc_get_product($id);

            if (!$product) {
                continue;
            }

            // Every branch checks a per-object capability. Duplicating creates
            // a new product rather than altering this one, so reading it is
            // enough — but a vendor plugin that hides other sellers' products
            // should still stop the copy being made.
            $done = match ($action) {
                'duplicate' => current_user_can('read_post', $id)
                    && (bool) (new WC_Admin_Duplicate_Product())->product_duplicate($product),
                'trash'     => current_user_can('delete_post', $id) && (bool) wp_trash_post($id),
                'delete'    => current_user_can('delete_post', $id) && (bool) wp_delete_post($id, true),
            };

            if ($done) {
                $count++;
            }
        }

        $messages = [
            'duplicate' => __('{count} duplicated.', 'wc-bulk-editor'),
            'trash'     => __('{count} trashed.', 'wc-bulk-editor'),
            'delete'    => __('{count} deleted.', 'wc-bulk-editor'),
        ];

        wp_send_json_success([
            'count'   => $count,
            'message' => str_replace('{count}', (string) $count, $messages[$action]),
        ]);
    }
    // ---------------------------------------------------------------------
    // AJAX: columns and saved views
    // ---------------------------------------------------------------------

    public function wc_bulk_get_columns(): void
    {
        $this->guard();

        wp_send_json_success([
            'columns' => $this->translated_columns(),
            'active'  => $this->get_user_columns(get_current_user_id()),
        ]);
    }

    public function wc_bulk_save_columns(): void
    {
        $this->guard();

        // Only keys we actually know about get stored.
        $requested = array_map('sanitize_text_field', (array) ($_POST['columns'] ?? []));
        $columns   = array_values(array_intersect($requested, array_keys(self::COLUMNS)));

        update_user_meta(get_current_user_id(), self::META_COLUMNS, $columns);

        wp_send_json_success(['columns' => $columns]);
    }

    public function wc_bulk_get_views(): void
    {
        $this->guard();

        wp_send_json_success(['views' => $this->get_saved_views(get_current_user_id())]);
    }

    public function wc_bulk_save_view(): void
    {
        $this->guard();

        $name = $this->post_string('name');

        if ($name === '') {
            wp_send_json_error(['message' => __('View name required.', 'wc-bulk-editor')]);
        }

        $uid   = get_current_user_id();
        $views = $this->get_saved_views($uid);

        $views[] = [
            'id'      => uniqid('v'),
            'name'    => $name,
            'filters' => array_map('sanitize_text_field', (array) ($_POST['filters'] ?? [])),
        ];

        update_user_meta($uid, self::META_VIEWS, $views);

        wp_send_json_success(['views' => $views]);
    }

    public function wc_bulk_delete_view(): void
    {
        $this->guard();

        $view_id = $this->post_string('view_id');
        $uid     = get_current_user_id();

        $views = array_values(array_filter(
            $this->get_saved_views($uid),
            static fn(array $v): bool => ($v['id'] ?? '') !== $view_id,
        ));

        update_user_meta($uid, self::META_VIEWS, $views);

        wp_send_json_success(['views' => $views]);
    }
    // ---------------------------------------------------------------------
    // AJAX: export
    // ---------------------------------------------------------------------

    /** Column header => callback resolving that column for one product. */
    private function csv_columns(): array
    {
        return [
            'ID'           => static fn(WC_Product $p, array $t): string => (string) $p->get_id(),
            'Name'         => static fn(WC_Product $p, array $t): string => $p->get_name(),
            'SKU'          => static fn(WC_Product $p, array $t): string => $p->get_sku(),
            'Reg Price'    => static fn(WC_Product $p, array $t): string => (string) $p->get_regular_price(),
            'Sale Price'   => static fn(WC_Product $p, array $t): string => (string) $p->get_sale_price(),
            'Stock'        => static fn(WC_Product $p, array $t): string => (string) $p->get_stock_quantity(),
            'Stock Status' => static fn(WC_Product $p, array $t): string => $p->get_stock_status(),
            'Status'       => static fn(WC_Product $p, array $t): string => $p->get_status(),
            'Type'         => static fn(WC_Product $p, array $t): string => $p->get_type(),
            'Categories'   => static fn(WC_Product $p, array $t): string => implode('|', $t['cat_names']),
            'Tags'         => static fn(WC_Product $p, array $t): string => implode('|', $t['tag_names']),
            'Weight'       => static fn(WC_Product $p, array $t): string => (string) $p->get_weight(),
        ];
    }
    public function wc_bulk_export_csv(): void
    {
        $this->guard();

        $ids = $this->post_ids('product_ids');

        if ($ids === []) {
            wp_send_json_error(['message' => __('No products selected.', 'wc-bulk-editor')]);
        }

        $columns = $this->csv_columns();
        $terms   = $this->collect_terms($ids);
        $empty   = ['cat_names' => [], 'cat_ids' => [], 'tag_names' => []];

        $rows = [implode(',', array_keys($columns))];

        foreach ($ids as $id) {
            $product = wc_get_product($id);

            if (!$product) {
                continue;
            }

            $cells = [];

            foreach ($columns as $resolve) {
                $cells[] = $this->csv_cell($resolve($product, $terms[$id] ?? $empty));
            }

            $rows[] = implode(',', $cells);
        }

        wp_send_json_success([
            'csv'      => implode("\n", $rows) . "\n",
            'filename' => 'bulk-export-' . wp_date('Y-m-d-His') . '.csv',
        ]);
    }

    /**
     * Quote one CSV cell, neutralising spreadsheet formula injection by
     * prefixing values a spreadsheet would otherwise evaluate.
     */
    private function csv_cell(string $value): string
    {
        if (preg_match('/^[=+\\-@]/', $value) === 1) {
            $value = '\'' . $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
    }

    // ---------------------------------------------------------------------
    // View
    // ---------------------------------------------------------------------

    /**
     * Render the admin screen.
     *
     * The markup lives in views/admin-page.php so this file stays PHP-only.
     */
    public function render_admin_page(): void
    {
        // add_submenu_page()'s capability argument only hides the menu item —
        // the page is still reachable by URL, so it has to be checked here too.
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to access this page.', 'wc-bulk-editor'));
        }

        $template = WCBULK_PLUGIN_DIR . 'views/admin-page.php';

        if (!is_readable($template)) {
            wp_admin_notice(
                esc_html__('Bulk Editor: the admin template is missing.', 'wc-bulk-editor'),
                ['type' => 'error']
            );

            return;
        }

        require $template;
    }

}

add_action('plugins_loaded', static function (): void {
    WC_Bulk_Product_Editor::instance();
});
