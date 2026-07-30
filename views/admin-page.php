<?php
/**
 * Admin screen markup for the Bulk Product Editor.
 *
 * Rendered by WC_Bulk_Product_Editor::render_admin_page().
 *
 * The JS binds to the IDs and classes below, so renaming anything here means
 * updating assets/admin.js to match. Sections are marked with HTML comments.
 */

defined('ABSPATH') || exit();
?>
<div class="wrap wc-bulk-editor-wrap">

    <!-- Page header: title + primary actions -->
    <div class="wc-bulk-header">
        <div class="wc-bulk-header-left">
            <h1 class="wp-heading-inline"><span class="dashicons dashicons-edit-page"></span><span class="wc-bulk-title"><?php esc_html_e('Bulk Product Editor','wc-bulk-editor');?></span><span class="wc-bulk-version">v<?php echo esc_html(WCBULK_VERSION);?></span></h1>
        </div>
        <div class="wc-bulk-header-right">
            <button type="button" class="button" id="wc-bulk-btn-columns"><span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e('Columns','wc-bulk-editor');?></button>
            <button type="button" class="button" id="wc-bulk-btn-views"><span class="dashicons dashicons-visibility"></span> <?php esc_html_e('Views','wc-bulk-editor');?></button>
            <button type="button" class="button" id="wc-bulk-btn-new-cat"><span class="dashicons dashicons-category"></span> <?php esc_html_e('New Category','wc-bulk-editor');?></button>
            <button type="button" class="button" id="wc-bulk-btn-export"><span class="dashicons dashicons-download"></span> <?php esc_html_e('Export CSV','wc-bulk-editor');?></button>
            <button type="button" class="button button-primary" id="wc-bulk-btn-add-product"><span class="dashicons dashicons-plus"></span> <?php esc_html_e('Add Product','wc-bulk-editor');?></button>
        </div>
    </div>

    <!-- Notice area (filled by JS) -->
    <div class="wc-bulk-notice-wrapper"></div>

    <!-- Saved views bar (toggled by the Views button) -->
    <div class="wc-bulk-views-bar" style="display:none;">
        <span class="wc-bulk-views-label"><?php esc_html_e('Saved Views:','wc-bulk-editor');?></span>
        <div class="wc-bulk-views-list"></div>
    </div>

    <!-- Filters -->
    <div class="wc-bulk-card wc-bulk-filters-card">
        <div class="wc-bulk-card-header">
            <h2><button type="button" class="wc-bulk-collapse-toggle" aria-expanded="true"><span class="dashicons dashicons-arrow-right-alt2"></span><?php esc_html_e('Filters','wc-bulk-editor');?></button></h2>
            <div class="wc-bulk-filter-actions">
                <button type="button" class="button button-small" id="wc-bulk-save-view"><span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e('Save View','wc-bulk-editor');?></button>
            </div>
        </div>
        <div class="wc-bulk-card-body">
            <div class="wc-bulk-filter-grid">
                <div class="wc-bulk-filter-item wc-bulk-filter-search">
                    <label><?php esc_html_e('Search','wc-bulk-editor');?></label>
                    <div class="wc-bulk-input-icon">
                        <span class="dashicons dashicons-search"></span><input type="text" id="wc-bulk-search" placeholder="<?php esc_attr_e('Name or SKU...','wc-bulk-editor');?>" />
                    </div>
                </div>
                <div class="wc-bulk-filter-item">
                    <label><?php esc_html_e('Category','wc-bulk-editor');?></label><select id="wc-bulk-category"><option value=""><?php esc_html_e('All','wc-bulk-editor');?></option></select>
                </div>
                <div class="wc-bulk-filter-item">
                    <label><?php esc_html_e('Type','wc-bulk-editor');?></label><select id="wc-bulk-type"><option value=""><?php esc_html_e('All','wc-bulk-editor');?></option><option value="simple">Simple</option><option value="variable">Variable</option><option value="grouped">Grouped</option><option value="external">External</option></select>
                </div>
                <div class="wc-bulk-filter-item">
                    <label><?php esc_html_e('Status','wc-bulk-editor');?></label><select id="wc-bulk-status"><option value=""><?php esc_html_e('All','wc-bulk-editor');?></option><option value="publish">Published</option><option value="draft">Draft</option><option value="pending">Pending</option><option value="private">Private</option></select>
                </div>
                <div class="wc-bulk-filter-item">
                    <label><?php esc_html_e('Stock','wc-bulk-editor');?></label><select id="wc-bulk-stock-status"><option value=""><?php esc_html_e('All','wc-bulk-editor');?></option><option value="instock">In Stock</option><option value="outofstock">Out of Stock</option><option value="onbackorder">On Backorder</option></select>
                </div>
                <div class="wc-bulk-filter-item">
                    <label><?php esc_html_e('Featured','wc-bulk-editor');?></label><select id="wc-bulk-featured"><option value=""><?php esc_html_e('All','wc-bulk-editor');?></option><option value="yes">Featured</option><option value="no">Not Featured</option></select>
                </div>
                <div class="wc-bulk-filter-item wc-bulk-filter-action">
                    <label>&nbsp;</label><button type="button" id="wc-bulk-load" class="button button-primary wc-bulk-btn-load"><span class="dashicons dashicons-search"></span> <?php esc_html_e('Apply Filters','wc-bulk-editor');?></button>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Apply panel -->
    <div class="wc-bulk-card wc-bulk-quick-card is-collapsed">
        <div class="wc-bulk-card-header">
            <h2><button type="button" class="wc-bulk-collapse-toggle" aria-expanded="false"><span class="dashicons dashicons-arrow-right-alt2"></span><?php esc_html_e('Quick Apply','wc-bulk-editor');?></button></h2><button type="button" class="button button-small" id="wc-bulk-btn-bulk-modal"><span class="dashicons dashicons-edit"></span> <?php esc_html_e('Advanced Bulk Edit','wc-bulk-editor');?></button>
        </div>
        <div class="wc-bulk-card-body">
            <div class="wc-bulk-quick-grid">
                <div class="wc-bulk-quick-item">
                    <label><?php esc_html_e('Reg. Price','wc-bulk-editor');?></label>
                    <div class="wc-bulk-quick-input-group">
                        <select class="wc-bulk-quick-op" data-field="regular_price"><option value="">—</option><option value="set">Set</option><option value="increase_percent">+%</option><option value="decrease_percent">-%</option><option value="increase_fixed">+</option><option value="decrease_fixed">-</option></select><input type="number" class="wc-bulk-quick-val" step="0.01" min="0" placeholder="0" disabled />
                    </div>
                </div>
                <div class="wc-bulk-quick-item">
                    <label><?php esc_html_e('Sale Price','wc-bulk-editor');?></label>
                    <div class="wc-bulk-quick-input-group">
                        <select class="wc-bulk-quick-op" data-field="sale_price"><option value="">—</option><option value="set">Set</option><option value="reduce_percent">-% from reg</option><option value="clear">Clear</option></select><input type="number" class="wc-bulk-quick-val" step="0.01" min="0" placeholder="0" disabled />
                    </div>
                </div>
                <div class="wc-bulk-quick-item">
                    <label><?php esc_html_e('Stock Qty','wc-bulk-editor');?></label>
                    <div class="wc-bulk-quick-input-group">
                        <select class="wc-bulk-quick-op" data-field="stock_quantity"><option value="">—</option><option value="set">Set</option><option value="increase">+</option><option value="decrease">-</option></select><input type="number" class="wc-bulk-quick-val" step="1" min="0" placeholder="0" disabled />
                    </div>
                </div>
                <div class="wc-bulk-quick-item">
                    <label><?php esc_html_e('Stock Status','wc-bulk-editor');?></label><select class="wc-bulk-quick-direct" data-field="stock_status"><option value="">—</option><option value="instock">In Stock</option><option value="outofstock">Out of Stock</option><option value="onbackorder">On Backorder</option></select>
                </div>
                <div class="wc-bulk-quick-item">
                    <label><?php esc_html_e('Status','wc-bulk-editor');?></label><select class="wc-bulk-quick-direct" data-field="post_status"><option value="">—</option><option value="publish">Published</option><option value="draft">Draft</option><option value="pending">Pending</option><option value="private">Private</option></select>
                </div>
                <div class="wc-bulk-quick-item wc-bulk-quick-action">
                    <label>&nbsp;</label><button type="button" id="wc-bulk-quick-apply" class="button button-secondary"><?php esc_html_e('Apply to All','wc-bulk-editor');?></button>
                </div>
            </div>
        </div>
    </div>

    <!-- Unsaved-changes bar -->
    <div class="wc-bulk-save-bar" style="display:none;">
        <div class="wc-bulk-save-bar-inner">
            <div class="wc-bulk-save-bar-left">
                <span class="dashicons dashicons-edit"></span><span class="wc-bulk-changed-count">0 <?php esc_html_e('modified','wc-bulk-editor');?></span>
            </div>
            <div class="wc-bulk-save-bar-right">
                <button type="button" id="wc-bulk-reset-all" class="button"><?php esc_html_e('Discard','wc-bulk-editor');?></button>
                <button type="button" id="wc-bulk-save-all" class="button button-primary"><span class="dashicons dashicons-yes"></span> <?php esc_html_e('Save All Changes','wc-bulk-editor');?></button>
            </div>
        </div>
        <div class="wc-bulk-save-progress">
            <div class="wc-bulk-save-progress-fill"></div>
        </div>
    </div>

    <!-- Bulk actions bar -->
    <div class="wc-bulk-actions-bar">
        <select id="wc-bulk-action-select"><option value=""><?php esc_html_e('Bulk Actions','wc-bulk-editor');?></option><option value="duplicate"><?php esc_html_e('Duplicate','wc-bulk-editor');?></option><option value="trash"><?php esc_html_e('Move to Trash','wc-bulk-editor');?></option><option value="delete"><?php esc_html_e('Delete Permanently','wc-bulk-editor');?></option></select><button type="button" id="wc-bulk-action-apply" class="button"><?php esc_html_e('Apply','wc-bulk-editor');?></button><span class="wc-bulk-selected-count"></span>
    </div>

    <!-- Product table -->
    <div class="wc-bulk-card wc-bulk-table-card">
        <div class="wc-bulk-card-header wc-bulk-table-header-bar">
            <div class="wc-bulk-table-header-left">
                <h2><?php esc_html_e('Products','wc-bulk-editor');?></h2><span class="wc-bulk-results-count"></span>
            </div>
            <div class="wc-bulk-table-header-right">
                <select id="wc-bulk-per-page" class="wc-bulk-per-page"><option value="20">20</option><option value="50" selected>50</option><option value="100">100</option></select><span class="wc-bulk-per-page-label"><?php esc_html_e('per page','wc-bulk-editor');?></span>
            </div>
        </div>
        <div class="wc-bulk-card-body wc-bulk-table-body-wrap">
            <div class="wc-bulk-table-scroll">
                <table id="wc-bulk-table" class="wc-bulk-table">
                    <thead id="wc-bulk-table-head"></thead>
                    <tbody id="wc-bulk-table-body">
                        <tr>
                            <td colspan="20" class="wc-bulk-empty-state">
                            <div class="wc-bulk-empty-icon">
                                <span class="dashicons dashicons-search"></span>
                            </div>
                            <p><?php esc_html_e('Use filters and click "Load Products".','wc-bulk-editor');?></p></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="wc-bulk-card-footer">
            <div class="wc-bulk-pagination"></div>
        </div>
    </div>
</div>

<!-- Modal: column manager -->
<div id="wc-bulk-modal-columns" class="wc-bulk-modal" style="display:none;">
    <div class="wc-bulk-modal-backdrop"></div>
    <div class="wc-bulk-modal-content wc-bulk-modal-md">
        <div class="wc-bulk-modal-header">
            <h3><?php esc_html_e('Column Manager','wc-bulk-editor');?></h3><button type="button" class="wc-bulk-modal-close">&times;</button>
        </div>
        <div class="wc-bulk-modal-body">
            <p class="description"><?php esc_html_e('Check to show. Uncheck to hide. ✏️ = editable.','wc-bulk-editor');?></p>
            <div id="wc-bulk-columns-list" class="wc-bulk-columns-list"></div>
        </div>
        <div class="wc-bulk-modal-footer">
            <button type="button" class="button" id="wc-bulk-columns-reset"><?php esc_html_e('Reset','wc-bulk-editor');?></button>
            <button type="button" class="button button-primary" id="wc-bulk-columns-save"><?php esc_html_e('Save','wc-bulk-editor');?></button>
        </div>
    </div>
</div>

<!-- Modal: bulk edit -->
<div id="wc-bulk-modal-edit" class="wc-bulk-modal" style="display:none;">
    <div class="wc-bulk-modal-backdrop"></div>
    <div class="wc-bulk-modal-content wc-bulk-modal-lg">
        <div class="wc-bulk-modal-header">
            <h3><?php esc_html_e('Advanced Bulk Edit','wc-bulk-editor');?></h3><button type="button" class="wc-bulk-modal-close">&times;</button>
        </div>
        <div class="wc-bulk-modal-body">
            <div class="wc-bulk-modal-tabs">
                <button type="button" class="wc-bulk-tab active" data-tab="general"><?php esc_html_e('General','wc-bulk-editor');?></button>
                <button type="button" class="wc-bulk-tab" data-tab="pricing"><?php esc_html_e('Pricing','wc-bulk-editor');?></button>
                <button type="button" class="wc-bulk-tab" data-tab="stock"><?php esc_html_e('Stock','wc-bulk-editor');?></button>
                <button type="button" class="wc-bulk-tab" data-tab="categories"><?php esc_html_e('Categories & Tags','wc-bulk-editor');?></button>
                <button type="button" class="wc-bulk-tab" data-tab="shipping"><?php esc_html_e('Shipping','wc-bulk-editor');?></button>
                <button type="button" class="wc-bulk-tab" data-tab="advanced"><?php esc_html_e('Advanced','wc-bulk-editor');?></button>
            </div>
            <div class="wc-bulk-tab-content active" data-tab="general">
                <div class="wc-bulk-field-row">
                    <label><?php esc_html_e('Status','wc-bulk-editor');?></label><select data-field="post_status"><option value="">— No change —</option><option value="publish">Published</option><option value="draft">Draft</option><option value="pending">Pending</option><option value="private">Private</option></select>
                </div>
                <div class="wc-bulk-field-row">
                    <label><?php esc_html_e('Catalog Visibility','wc-bulk-editor');?></label><select data-field="catalog_visibility"><option value="">— No change —</option><option value="visible">Visible</option><option value="catalog">Catalog Only</option><option value="search">Search Only</option><option value="hidden">Hidden</option></select>
                </div>
                <div class="wc-bulk-field-row">
                    <label><?php esc_html_e('Featured','wc-bulk-editor');?></label><select data-field="featured"><option value="">— No change —</option><option value="yes">Yes</option><option value="no">No</option></select>
                </div>
                <div class="wc-bulk-field-row">
                    <label><?php esc_html_e('Virtual','wc-bulk-editor');?></label><select data-field="virtual"><option value="">— No change —</option><option value="yes">Yes</option><option value="no">No</option></select>
                </div>
                <div class="wc-bulk-field-row">
                    <label><?php esc_html_e('Downloadable','wc-bulk-editor');?></label><select data-field="downloadable"><option value="">— No change —</option><option value="yes">Yes</option><option value="no">No</option></select>
                </div>
                <div class="wc-bulk-field-row">
                    <label><?php esc_html_e('Sold Individually','wc-bulk-editor');?></label><select data-field="sold_individually"><option value="">— No change —</option><option value="yes">Yes</option><option value="no">No</option></select>
                </div>
                <div class="wc-bulk-field-row">
                    <label><?php esc_html_e('Enable Reviews','wc-bulk-editor');?></label><select data-field="reviews_allowed"><option value="">— No change —</option><option value="yes">Yes</option><option value="no">No</option></select>
                </div>
                <div class="wc-bulk-field-row">
                    <label><?php esc_html_e('Menu Order','wc-bulk-editor');?></label><input type="number" data-field="menu_order" placeholder="0" step="1" />
                </div>
            </div>
            <div class="wc-bulk-tab-content" data-tab="pricing">
                <div class="wc-bulk-field-row">
                    <label><?php esc_html_e('Regular Price','wc-bulk-editor');?></label><select data-field="regular_price_op"><option value="">— No change —</option><option value="set">Set to</option><option value="increase_percent">Increase by %</option><option value="decrease_percent">Decrease by %</option><option value="increase_fixed">Increase by fixed</option><option value="decrease_fixed">Decrease by fixed</option></select><input type="number" data-field="regular_price" step="0.01" min="0" placeholder="0" />
                </div>
                <div class="wc-bulk-field-row">
                    <label><?php esc_html_e('Sale Price','wc-bulk-editor');?></label><select data-field="sale_price_op"><option value="">— No change —</option><option value="set">Set to</option><option value="reduce_percent">Reduce by % from regular</option><option value="clear">Clear sale price</option></select><input type="number" data-field="sale_price" step="0.01" min="0" placeholder="0" />
                </div>
                <div class="wc-bulk-field-row">
                    <label><?php esc_html_e('Tax Status','wc-bulk-editor');?></label><select data-field="tax_status"><option value="">— No change —</option><option value="taxable">Taxable</option><option value="shipping">Shipping Only</option><option value="none">None</option></select>
                </div>
                <div class="wc-bulk-field-row">
                    <label><?php esc_html_e('Tax Class','wc-bulk-editor');?></label><select data-field="tax_class"><option value="">— No change —</option></select>
                </div>
            </div>
            <div class="wc-bulk-tab-content" data-tab="stock">
                <div class="wc-bulk-field-row">
                    <label><?php esc_html_e('Manage Stock','wc-bulk-editor');?></label><select data-field="manage_stock"><option value="">— No change —</option><option value="yes">Yes</option><option value="no">No</option></select>
                </div>
                <div class="wc-bulk-field-row">
                    <label><?php esc_html_e('Stock Qty','wc-bulk-editor');?></label><select data-field="stock_quantity_op"><option value="">— No change —</option><option value="set">Set to</option><option value="increase">Increase by</option><option value="decrease">Decrease by</option></select><input type="number" data-field="stock_quantity" step="1" min="0" placeholder="0" />
                </div>
                <div class="wc-bulk-field-row">
                    <label><?php esc_html_e('Stock Status','wc-bulk-editor');?></label><select data-field="stock_status"><option value="">— No change —</option><option value="instock">In Stock</option><option value="outofstock">Out of Stock</option><option value="onbackorder">On Backorder</option></select>
                </div>
                <div class="wc-bulk-field-row">
                    <label><?php esc_html_e('Allow Backorders','wc-bulk-editor');?></label><select data-field="backorders"><option value="">— No change —</option><option value="no">Do not allow</option><option value="notify">Allow but notify</option><option value="yes">Allow</option></select>
                </div>
            </div>
            <div class="wc-bulk-tab-content" data-tab="categories">
                <div class="wc-bulk-field-row">
                    <label><?php esc_html_e('Categories (replace all)','wc-bulk-editor');?></label><select data-field="categories" multiple style="height:150px;"></select>
                </div>
                <div class="wc-bulk-field-row">
                    <label><?php esc_html_e('Tags (replace all)','wc-bulk-editor');?></label><input type="text" data-field="tags" placeholder="tag1, tag2, tag3" style="width:100%;" />
                </div>
            </div>
            <div class="wc-bulk-tab-content" data-tab="shipping">
                <div class="wc-bulk-field-row">
                    <label><?php esc_html_e('Weight','wc-bulk-editor');?></label><input type="number" data-field="weight" step="0.01" min="0" placeholder="0" />
                </div>
                <div class="wc-bulk-field-row">
                    <label><?php esc_html_e('Length','wc-bulk-editor');?></label><input type="number" data-field="length" step="0.01" min="0" placeholder="0" />
                </div>
                <div class="wc-bulk-field-row">
                    <label><?php esc_html_e('Width','wc-bulk-editor');?></label><input type="number" data-field="width" step="0.01" min="0" placeholder="0" />
                </div>
                <div class="wc-bulk-field-row">
                    <label><?php esc_html_e('Height','wc-bulk-editor');?></label><input type="number" data-field="height" step="0.01" min="0" placeholder="0" />
                </div>
                <div class="wc-bulk-field-row">
                    <label><?php esc_html_e('Shipping Class','wc-bulk-editor');?></label><select data-field="shipping_class"><option value="">— No change —</option></select>
                </div>
            </div>
            <div class="wc-bulk-tab-content" data-tab="advanced">
                <div class="wc-bulk-field-row">
                    <label><?php esc_html_e('Purchase Note','wc-bulk-editor');?></label><textarea data-field="purchase_note" rows="3" style="width:100%;"></textarea>
                </div>
            </div>
        </div>
        <div class="wc-bulk-modal-footer">
            <span class="wc-bulk-modal-selected"></span><button type="button" class="button button-primary" id="wc-bulk-modal-apply"><?php esc_html_e('Apply to Selected','wc-bulk-editor');?></button>
        </div>
    </div>
</div>

<!-- Modal: quick add product -->
<div id="wc-bulk-modal-add" class="wc-bulk-modal" style="display:none;">
    <div class="wc-bulk-modal-backdrop"></div>
    <div class="wc-bulk-modal-content wc-bulk-modal-sm">
        <div class="wc-bulk-modal-header">
            <h3><?php esc_html_e('Quick Add Product','wc-bulk-editor');?></h3><button type="button" class="wc-bulk-modal-close">&times;</button>
        </div>
        <div class="wc-bulk-modal-body">
            <div class="wc-bulk-field-row">
                <label><?php esc_html_e('Product Name','wc-bulk-editor');?> <span style="color:red;">*</span></label><input type="text" id="wc-bulk-add-name" placeholder="<?php esc_attr_e('Enter product name...','wc-bulk-editor');?>" />
            </div>
            <div class="wc-bulk-field-row">
                <label><?php esc_html_e('SKU','wc-bulk-editor');?></label><input type="text" id="wc-bulk-add-sku" />
            </div>
            <div class="wc-bulk-field-row">
                <label><?php esc_html_e('Regular Price','wc-bulk-editor');?></label><input type="number" id="wc-bulk-add-price" step="0.01" min="0" />
            </div>
            <div class="wc-bulk-field-row">
                <label><?php esc_html_e('Status','wc-bulk-editor');?></label><select id="wc-bulk-add-status"><option value="publish">Published</option><option value="draft">Draft</option></select>
            </div>
        </div>
        <div class="wc-bulk-modal-footer">
            <button type="button" class="button button-primary" id="wc-bulk-add-confirm"><?php esc_html_e('Create Product','wc-bulk-editor');?></button>
        </div>
    </div>
</div>

<!-- Modal: new category -->
<div id="wc-bulk-modal-category" class="wc-bulk-modal" style="display:none;">
    <div class="wc-bulk-modal-backdrop"></div>
    <div class="wc-bulk-modal-content wc-bulk-modal-small">
        <div class="wc-bulk-modal-header">
            <h2><?php esc_html_e('New Category','wc-bulk-editor');?></h2>
            <button type="button" class="wc-bulk-modal-close"><span class="dashicons dashicons-no-alt"></span></button>
        </div>
        <div class="wc-bulk-modal-body">
            <div class="wc-bulk-field-row">
                <label for="wc-bulk-cat-name"><?php esc_html_e('Name','wc-bulk-editor');?></label><input type="text" id="wc-bulk-cat-name" placeholder="<?php esc_attr_e('e.g. Sepatu','wc-bulk-editor');?>" />
            </div>
            <div class="wc-bulk-field-row">
                <label for="wc-bulk-cat-parent"><?php esc_html_e('Parent','wc-bulk-editor');?></label><select id="wc-bulk-cat-parent"><option value="0"><?php esc_html_e('— None —','wc-bulk-editor');?></option></select>
            </div>
            <p class="wc-bulk-cat-hint description"><?php esc_html_e('The new category becomes available in every row straight away.','wc-bulk-editor');?></p>
        </div>
        <div class="wc-bulk-modal-footer">
            <button type="button" class="button button-primary" id="wc-bulk-cat-confirm"><?php esc_html_e('Create Category','wc-bulk-editor');?></button>
        </div>
    </div>
</div>
