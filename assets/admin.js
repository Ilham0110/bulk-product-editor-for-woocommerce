/*!
 * WooCommerce Bulk Product Editor — admin UI
 *
 * Version: 3.11.0
 *
 * Spreadsheet-style inline editing for the product list. Everything hangs off
 * a single controller object (B) that is booted on DOM ready.
 *
 * State:
 *   originals    — server values, keyed by product id
 *   changes      — pending edits, keyed by product id then field
 *   selectedRows — checkbox selection, keyed by product id
 *
 * Edits are staged in `changes` and only written when Save All posts them to
 * wc_bulk_save_inline. Globals come from wp_localize_script as WCBulkEditor.
 */
(function ($, WCB) {
    'use strict';
    var B = {
        page: 1,
        pages: 1,
        changes: {},
        originals: {},
        saving: false,
        _activeColumns: null,
        selectedRows: {},

        /* ------------------------------------------------------------------
           COLUMN STATE
           ------------------------------------------------------------------ */
        getActiveColumns: function () {
            if (this._activeColumns) return this._activeColumns;
            var cols =
                WCB.columns && WCB.columns.length
                    ? WCB.columns.slice()
                    : [
                          'thumb',
                          'name',
                          'sku',
                          'regular_price',
                          'sale_price',
                          'stock_quantity',
                          'stock_status',
                          'post_status',
                          'categories',
                      ];
            if (cols[0] !== 'cb') cols.unshift('cb');
            this._activeColumns = cols;
            return cols;
        },

        /* ------------------------------------------------------------------
           BOOTSTRAP & EVENT WIRING
           ------------------------------------------------------------------ */
        init: function () {
            var s = this;
            s.bindEvents();
            s.renderViewsBar();

            // Everything the first paint needs is embedded in the page by
            // wp_localize_script, so no AJAX is required to show the table.
            // Each admin-ajax round-trip costs a full WordPress bootstrap,
            // which is far more expensive than the queries themselves.
            s.fillCategoryFilter(WCB.all_cats || []);
            s.fillModalCategories(WCB.all_cats || []);
            s.fillTaxClasses(WCB.tax_classes || []);
            s.fillShippingClasses(WCB.shipping_classes || []);

            // Reveal every panel at once rather than letting them pop in one
            // after another as requests land.
            s.revealPanels();
            s.restorePanels();

            if (WCB.preloaded && WCB.preloaded.products) {
                s.pages = WCB.preloaded.total_pages;
                s.page = WCB.preloaded.current_page;
                s.renderTable(WCB.preloaded.products);
                s.renderPagination(WCB.preloaded.total);
                s.updateResults(WCB.preloaded.total);
            } else {
                // Preload unavailable (query failed): fall back to AJAX.
                s.page = 1;
                s.loadProducts();
            }
        },

        // Quick Apply starts collapsed to leave room for product rows; the
        // user's choice is remembered per browser.
        // Storage key for a collapsible card.
        panelKey: function ($card) {
            return $card.hasClass('wc-bulk-filters-card') ? 'wcbFilters' : 'wcbQuickApply';
        },

        // Quick Apply starts collapsed to leave room for product rows;
        // Filters starts open. Both remember the user's choice per browser.
        restorePanels: function () {
            var s = this;

            $('.wc-bulk-card').has('.wc-bulk-collapse-toggle').each(function () {
                var $card = $(this),
                    key = s.panelKey($card),
                    stored = null;

                try {
                    stored = localStorage.getItem(key);
                } catch (e) {}

                // Default: Filters open, Quick Apply collapsed.
                var open = stored === null ? key === 'wcbFilters' : stored === '1';

                $card
                    .toggleClass('is-collapsed', !open)
                    .find('.wc-bulk-collapse-toggle')
                    .attr('aria-expanded', open ? 'true' : 'false');
            });
        },

        // Show the filter, quick-apply, actions and table panels together.
        revealPanels: function () {
            // The bulk actions bar needs a selection to do anything, so it
            // stays out of the way until one exists — that is ~54px of
            // vertical space handed back to product rows.
            $('.wc-bulk-table-card,.wc-bulk-quick-card').show();
            $('.wc-bulk-actions-bar').toggle(Object.keys(this.selectedRows).length > 0);
        },
        bindEvents: function () {
            var s = this;
            $('#wc-bulk-load').on('click', function () {
                s.page = 1;
                s.loadProducts();
            });
            $('#wc-bulk-search').on('keypress', function (e) {
                if (e.which === 13) {
                    e.preventDefault();
                    s.page = 1;
                    s.loadProducts();
                }
            });
            $('#wc-bulk-per-page').on('change', function () {
                s.page = 1;
                s.loadProducts();
            });
            $(document).on('input change', '.wc-bulk-inline-input', function () {
                s.trackChange(
                    $(this).data('product-id'),
                    $(this).data('field'),
                    $(this).val(),
                    $(this)
                );
            });
            $(document).on('input change', '.wc-bulk-inline-textarea', function () {
                s.trackChange(
                    $(this).data('product-id'),
                    $(this).data('field'),
                    $(this).val(),
                    $(this)
                );
            });
            $(document).on('change', '.wc-bulk-inline-select', function () {
                s.trackChange(
                    $(this).data('product-id'),
                    $(this).data('field'),
                    $(this).val(),
                    $(this)
                );
            });
            $(document).on('change', '.wc-bulk-quick-op', function () {
                $(this)
                    .siblings('.wc-bulk-quick-val')
                    .prop('disabled', $(this).val() === '' || $(this).val() === 'clear')
                    .focus();
            });
            $('#wc-bulk-quick-apply').on('click', function () {
                s.quickApply();
            });
            $('#wc-bulk-save-all').on('click', function () {
                s.saveAll();
            });
            $('#wc-bulk-reset-all').on('click', function () {
                if (confirm(WCB.i18n.confirm_discard)) s.discardAll();
            });
            $(document).on('keydown', function (e) {
                if (
                    (e.ctrlKey || e.metaKey) &&
                    e.key === 's' &&
                    Object.keys(s.changes).length > 0
                ) {
                    e.preventDefault();
                    s.saveAll();
                }
            });
            $('#wc-bulk-action-apply').on('click', function () {
                s.doBulkAction();
            });
            $(document).on('change', '.wc-bulk-row-check', function () {
                var pid = $(this).val();
                if ($(this).prop('checked')) s.selectedRows[pid] = true;
                else delete s.selectedRows[pid];
                $(this).closest('tr').toggleClass('row-selected', $(this).prop('checked'));
                s.updateSelectCount();
            });
            // The cap is clamped to the available width, so recompute on resize.
            var resizeTimer = null;
            $(window).on('resize', function () {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function () {
                    s.applyColumnViewport();
                    s.applyTableHeight();
                }, 120);
            });
            // Collapsing a panel frees vertical space, so re-fit the table.
            $(document).on('click', '.wc-bulk-collapse-toggle', function () {
                var $card = $(this).closest('.wc-bulk-card'),
                    collapsed = $card.toggleClass('is-collapsed').hasClass('is-collapsed');
                $(this).attr('aria-expanded', collapsed ? 'false' : 'true');
                try {
                    localStorage.setItem(s.panelKey($card), collapsed ? '0' : '1');
                } catch (e) {}
                s.applyTableHeight();
            });
            $('#wc-bulk-btn-new-cat').on('click', function () {
                s.openCategoryModal();
            });
            $('#wc-bulk-cat-confirm').on('click', function () {
                s.createCategory();
            });
            // Enter in the name field submits the modal.
            $('#wc-bulk-cat-name').on('keypress', function (e) {
                if (e.which === 13) {
                    e.preventDefault();
                    s.createCategory();
                }
            });
            $('#wc-bulk-btn-columns').on('click', function () {
                s.openColumnsModal();
            });
            $('#wc-bulk-columns-save').on('click', function () {
                s.saveColumns();
            });
            $('#wc-bulk-columns-reset').on('click', function () {
                s.resetColumns();
            });
            $('#wc-bulk-btn-bulk-modal').on('click', function () {
                s.openBulkEditModal();
            });
            $('#wc-bulk-modal-apply').on('click', function () {
                s.applyBulkEditModal();
            });
            $(document).on('click', '.wc-bulk-tab', function () {
                var t = $(this).data('tab');
                $('.wc-bulk-tab,.wc-bulk-tab-content').removeClass('active');
                $(this).addClass('active');
                $('.wc-bulk-tab-content[data-tab="' + t + '"]').addClass('active');
            });
            $('#wc-bulk-btn-add-product').on('click', function () {
                s.openAddModal();
            });
            $('#wc-bulk-add-confirm').on('click', function () {
                s.quickAddProduct();
            });
            $('#wc-bulk-btn-export').on('click', function () {
                s.exportCSV();
            });
            $('#wc-bulk-btn-views').on('click', function () {
                s.toggleViewsBar();
            });
            $('#wc-bulk-save-view').on('click', function () {
                s.saveView();
            });
            $(document).on('click', '.wc-bulk-view-chip-delete', function (e) {
                e.stopPropagation();
                s.deleteView($(this).closest('.wc-bulk-view-chip').data('view-id'));
            });
            $(document).on('click', '.wc-bulk-view-chip', function (e) {
                if ($(e.target).hasClass('wc-bulk-view-chip-delete')) return;
                s.loadView($(this).data('view-id'));
            });
            $(document).on('click', '.wc-bulk-modal-close, .wc-bulk-modal-backdrop', function () {
                $(this).closest('.wc-bulk-modal').hide();
            });

            // Grow to fit the content while typing. A height the user dragged
            // to is recorded and treated as a floor, so typing never undoes it.
            // The drag grip resizes the element without firing any input event,
            // and mouseup often lands outside it, so the box is observed directly.
            if (window.ResizeObserver) {
                var textareaObserver = new ResizeObserver(function (entries) {
                    entries.forEach(function (entry) {
                        var el = entry.target;

                        // Ignore the resize we cause ourselves while typing.
                        if (el.dataset.wcbAutoResize === '1') {
                            el.dataset.wcbAutoResize = '';
                            return;
                        }

                        var current = Math.round(el.getBoundingClientRect().height),
                            saved = el.style.height;

                        // scrollHeight follows the box once it has been dragged,
                        // so the content height is measured with the box
                        // momentarily collapsed.
                        el.style.height = 'auto';
                        var content = el.scrollHeight;
                        el.style.height = saved;

                        if (current > content + 2) {
                            $(el).data('userHeight', current);
                        }
                    });
                });

                s.observeTextareas = function () {
                    $('.wc-bulk-inline-textarea').each(function () {
                        if (this.dataset.wcbObserved === '1') return;
                        this.dataset.wcbObserved = '1';
                        textareaObserver.observe(this);
                    });
                };
            }

            $(document).on('input', '.wc-bulk-inline-textarea', function () {
                var floor = $(this).data('userHeight') || 0;

                this.dataset.wcbAutoResize = '1';
                this.style.height = 'auto';
                this.style.height = Math.max(this.scrollHeight, floor) + 'px';
            });
        },

        /* ------------------------------------------------------------------
           REFERENCE DATA (CATEGORIES, TAX & SHIPPING CLASSES)
           ------------------------------------------------------------------ */
        // Populate the category filter from data already on the page.
        fillCategoryFilter: function (cats) {
            var $filter = $('#wc-bulk-category');
            $.each(cats, function (i, c) {
                if ($filter.find('option[value="' + c.id + '"]').length) return;
                $filter.append($('<option>', { value: c.id, text: c.name }));
            });
        },

        // The Advanced Bulk Edit modal's category picker. Separate from the
        // filter above: this one is a multi-select that replaces a product's
        // categories, so it carries no "All" placeholder.
        fillModalCategories: function (cats) {
            var $sel = $('#wc-bulk-modal-edit [data-field="categories"]');

            if (!$sel.length) return;

            $.each(cats, function (i, c) {
                if ($sel.find('option[value="' + c.id + '"]').length) return;
                $sel.append($('<option>', { value: c.id, text: c.name }));
            });
        },

        // Scoped to the modal: an unscoped [data-field] selector also matches
        // the table's own cells once rows are drawn.
        fillTaxClasses: function (list) {
            var $t = $('#wc-bulk-modal-edit [data-field="tax_class"]');
            $.each(list, function (i, c) {
                $t.append($('<option>', { value: c.slug, text: c.name }));
            });
        },

        fillShippingClasses: function (list) {
            var $t = $('#wc-bulk-modal-edit [data-field="shipping_class"]');
            $.each(list, function (i, c) {
                $t.append($('<option>', { value: c.id, text: c.name }));
            });
        },

        loadCategories: function () {
            var s = this;
            return $.post(
                WCB.ajax_url,
                { action: 'wc_bulk_get_categories', nonce: WCB.nonce },
                function (r) {
                    if (r.success) s.fillCategoryFilter(r.data.categories);
                }
            );
        },
        loadTaxClasses: function () {
            var s = this;
            return $.post(
                WCB.ajax_url,
                { action: 'wc_bulk_get_tax_classes', nonce: WCB.nonce },
                function (r) {
                    if (r.success) s.fillTaxClasses(r.data.tax_classes);
                }
            );
        },
        loadShippingClasses: function () {
            var s = this;
            return $.post(
                WCB.ajax_url,
                { action: 'wc_bulk_get_shipping_classes', nonce: WCB.nonce },
                function (r) {
                    if (r.success) s.fillShippingClasses(r.data.shipping_classes);
                }
            );
        },

        /* ------------------------------------------------------------------
           PRODUCT LOADING
           ------------------------------------------------------------------ */
        loadProducts: function () {
            var s = this;
            $('#wc-bulk-table-body').html(
                '<tr><td colspan="20" class="wc-bulk-loading"><span class="spinner is-active"></span>' +
                    WCB.i18n.loading +
                    '</td></tr>'
            );
            $('.wc-bulk-table-card,.wc-bulk-quick-card,.wc-bulk-actions-bar').show();
            var d = {
                action: 'wc_bulk_fetch_products',
                nonce: WCB.nonce,
                search: $('#wc-bulk-search').val().trim(),
                category: $('#wc-bulk-category').val(),
                type: $('#wc-bulk-type').val(),
                status: $('#wc-bulk-status').val(),
                stock_status: $('#wc-bulk-stock-status').val(),
                featured: $('#wc-bulk-featured').val(),
                page: s.page,
                per_page: parseInt($('#wc-bulk-per-page').val()) || 50,
            };
            $.post(WCB.ajax_url, d, function (r) {
                if (r.success) {
                    s.pages = r.data.total_pages;
                    s.page = r.data.current_page;
                    s.renderTable(r.data.products);
                    s.renderPagination(r.data.total);
                    s.updateResults(r.data.total);
                } else s.showNotice('error', r.data.message || WCB.i18n.error);
            }).fail(function () {
                s.showNotice('error', WCB.i18n.error);
            });
        },

        /* ------------------------------------------------------------------
           TABLE RENDERING
           ------------------------------------------------------------------ */
        renderTable: function (products) {
            var s = this,
                cols = s.getActiveColumns(),
                tbody = $('#wc-bulk-table-body');
            tbody.empty();
            s.renderTableHead();
            if (!products || !products.length) {
                tbody.html(
                    '<tr><td colspan="' +
                        cols.length +
                        '"><div class="wc-bulk-empty-icon"><span class="dashicons dashicons-search"></span></div><p>' +
                        WCB.i18n.no_results +
                        '</p></td></tr>'
                );
                return;
            }

            var R = {
                cb: function (p) {
                    return (
                        '<th scope="row" class="check-column"><input type="checkbox" class="wc-bulk-row-check" value="' +
                        p.id +
                        '"' +
                        (s.selectedRows[p.id] ? ' checked' : '') +
                        ' /></th>'
                    );
                },
                thumb: function (p) {
                    return (
                        '<td class="column-thumb"><img src="' +
                        s.esc(p.image_url) +
                        '" class="wc-bulk-thumb" width="40" height="40" loading="lazy" /></td>'
                    );
                },
                name: function (p) {
                    return (
                        '<td class="column-name"><strong><a href="' +
                        s.esc(p.edit_url) +
                        '" class="wc-bulk-product-name" target="_blank">' +
                        s.esc(p.name) +
                        '</a></strong><span class="wc-bulk-product-id"> #' +
                        p.id +
                        '</span>' +
                        (p.type !== 'simple'
                            ? ' <small style="color:#999">— ' + p.type + '</small>'
                            : '') +
                        '</td>'
                    );
                },
                sku: function (p) {
                    return s.renderEditableCell(p, 'sku', 'text', '', '', p.sku || '');
                },
                regular_price: function (p) {
                    return s.renderEditableCell(
                        p,
                        'regular_price',
                        'number',
                        '0.01',
                        '0',
                        s.fmtPrice(p.regular_price)
                    );
                },
                sale_price: function (p) {
                    return s.renderEditableCell(
                        p,
                        'sale_price',
                        'number',
                        '0.01',
                        '0',
                        s.fmtPrice(p.sale_price)
                    );
                },
                stock_quantity: function (p) {
                    var ch = s.isChanged(p.id, 'stock_quantity') ? ' changed' : '',
                        cv =
                            s.changes[p.id] && s.changes[p.id].stock_quantity !== undefined
                                ? s.changes[p.id].stock_quantity
                                : p.stock_quantity === null || p.stock_quantity === undefined
                                  ? ''
                                  : p.stock_quantity,
                        dis = p.manage_stock ? '' : ' disabled',
                        ttl = p.manage_stock
                            ? ''
                            : ' title="Set Manage Stock to Yes to edit the quantity"';
                    return (
                        '<td class="column-stock_quantity"><input type="number" class="wc-bulk-inline-input wcbx-stock' +
                        ch +
                        '" data-product-id="' +
                        p.id +
                        '" data-field="stock_quantity" value="' +
                        s.escAttr(cv) +
                        '" step="1" min="0" placeholder="' +
                        (p.manage_stock ? '0' : 'N/A') +
                        '"' +
                        dis +
                        ttl +
                        ' /></td>'
                    );
                },
                stock_status: function (p) {
                    return s.renderSelectCell(p, 'stock_status', {
                        instock: 'In Stock',
                        outofstock: 'Out of Stock',
                        onbackorder: 'On Backorder',
                    });
                },
                post_status: function (p) {
                    return s.renderSelectCell(p, 'post_status', {
                        publish: 'Published',
                        draft: 'Draft',
                        pending: 'Pending',
                        private: 'Private',
                    });
                },
                categories: function (p) {
                    // Single-select dropdown: a product carries one category
                    // here, matching the Status and Stock Status columns.
                    var ch = s.isChanged(p.id, 'categories') ? ' changed' : '',
                        cv =
                            s.changes[p.id] && s.changes[p.id].categories !== undefined
                                ? String(s.changes[p.id].categories).split(',')[0]
                                : String((p.category_ids || [])[0] || '');

                    var h =
                        '<td class="column-categories"><select class="wc-bulk-inline-select wcbx-cats' +
                        ch +
                        '" data-product-id="' +
                        p.id +
                        '" data-field="categories"><option value=""' +
                        (cv === '' ? ' selected' : '') +
                        '>' +
                        s.esc(WCB.i18n.no_category) +
                        '</option>';

                    $.each(WCB.all_cats || [], function (i, c) {
                        h +=
                            '<option value="' +
                            c.id +
                            '"' +
                            (String(c.id) === cv ? ' selected' : '') +
                            '>' +
                            s.esc(c.name) +
                            '</option>';
                    });

                    return h + '</select></td>';
                },
                // EXPANDABLE TEXTAREA FIELDS
                tags: function (p) {
                    return s.renderTextareaCell(p, 'tags', (p.tags || []).join(', '));
                },
                description: function (p) {
                    return s.renderTextareaCell(p, 'description', p.description || '');
                },
                short_description: function (p) {
                    return s.renderTextareaCell(p, 'short_description', p.short_description || '');
                },
                purchase_note: function (p) {
                    return s.renderTextareaCell(p, 'purchase_note', p.purchase_note || '');
                },
                // STANDARD FIELDS
                type: function (p) {
                    return '<td><span class="wc-bulk-ro">' + p.type + '</span></td>';
                },
                tax_status: function (p) {
                    return s.renderSelectCell(p, 'tax_status', {
                        taxable: 'Taxable',
                        shipping: 'Shipping Only',
                        none: 'None',
                    });
                },
                // Options come from the preloaded lists, not from scraping the
                // bulk-edit modal's <select>: that selector also matched the
                // table's own cells, so options multiplied on every re-render.
                tax_class: function (p) {
                    return s.renderSelectCell(
                        p,
                        'tax_class',
                        s.classOptions(WCB.tax_classes, 'slug')
                    );
                },
                // Edited by term id, so the fallback is shipping_class_id
                // rather than p.shipping_class (a slug).
                shipping_class: function (p) {
                    return s.renderSelectCell(
                        p,
                        'shipping_class',
                        s.classOptions(WCB.shipping_classes, 'id'),
                        p.shipping_class_id
                    );
                },
                weight: function (p) {
                    return s.renderEditableCell(p, 'weight', 'number', '0.01', '0', p.weight);
                },
                length: function (p) {
                    return s.renderEditableCell(p, 'length', 'number', '0.01', '0', p.length);
                },
                width: function (p) {
                    return s.renderEditableCell(p, 'width', 'number', '0.01', '0', p.width);
                },
                height: function (p) {
                    return s.renderEditableCell(p, 'height', 'number', '0.01', '0', p.height);
                },
                featured: function (p) {
                    return s.renderBoolCell(p, 'featured', '★', '☆');
                },
                catalog_visibility: function (p) {
                    return s.renderSelectCell(p, 'catalog_visibility', {
                        visible: 'Visible',
                        catalog: 'Catalog',
                        search: 'Search',
                        hidden: 'Hidden',
                    });
                },
                virtual: function (p) {
                    return s.renderBoolCell(p, 'virtual', '✓', '—');
                },
                downloadable: function (p) {
                    return s.renderBoolCell(p, 'downloadable', '✓', '—');
                },
                manage_stock: function (p) {
                    return s.renderBoolCell(p, 'manage_stock', '✓', '—');
                },
                backorders: function (p) {
                    return s.renderSelectCell(p, 'backorders', {
                        no: 'No',
                        notify: 'Notify',
                        yes: 'Yes',
                    });
                },
                sold_individually: function (p) {
                    return s.renderBoolCell(p, 'sold_individually', '✓', '—');
                },
                reviews_allowed: function (p) {
                    return s.renderBoolCell(p, 'reviews_allowed', '✓', '—');
                },
                menu_order: function (p) {
                    return s.renderEditableCell(p, 'menu_order', 'number', '1', '0', p.menu_order);
                },
            };

            $.each(products, function (i, p) {
                if (!s.originals[p.id]) s.originals[p.id] = p;
                var row = $('<tr>')
                    .attr('id', 'product-' + p.id)
                    .toggleClass('row-modified', !!s.changes[p.id])
                    .toggleClass('row-selected', !!s.selectedRows[p.id]);
                $.each(cols, function (j, col) {
                    if (R[col]) row.append(R[col](p));
                    else row.append('<td>—</td>');
                });
                tbody.append(row);
            });
            s.updateSelectCount();
            s.updateSaveBar();
            s.applyColumnViewport();
            s.applyTableHeight();

            // Rows are re-created on every render, so newly drawn fields need
            // watching too.
            if (s.observeTextareas) s.observeTextareas();
        },

        /* ------------------------------------------------------------------
           VIEWPORT WIDTH
           ------------------------------------------------------------------ */

        // Column widths, mirroring the rules in admin.css. Keep the two in sync.
        COL_WIDTHS: { cb: 42, thumb: 60, name: 200 },

        // Per-column widths, sized to the widest header label or control.
        // Keep in sync with the matching block in admin.css.
        COL_SPECIFIC_WIDTHS: {
            sku: 140,
            regular_price: 110,
            sale_price: 110,
            stock_quantity: 90,
            stock_status: 130,
            post_status: 110,
            type: 80,
            tax_status: 110,
            tax_class: 120,
            shipping_class: 130,
            catalog_visibility: 110,
            backorders: 100,
            weight: 90,
            length: 90,
            width: 90,
            height: 90,
            menu_order: 80,
            featured: 60,
            virtual: 60,
            downloadable: 60,
            manage_stock: 70,
            sold_individually: 90,
            reviews_allowed: 90,
        },
        COL_WIDTH_WIDE: 200,
        COL_WIDTH_DEFAULT: 120,
        WIDE_COLUMNS: ['tags', 'description', 'short_description', 'purchase_note'],
        VISIBLE_DATA_COLUMNS: 9,

        columnWidth: function (col) {
            if (this.COL_WIDTHS[col]) return this.COL_WIDTHS[col];
            if (this.COL_SPECIFIC_WIDTHS[col]) return this.COL_SPECIFIC_WIDTHS[col];
            if ($.inArray(col, this.WIDE_COLUMNS) !== -1) return this.COL_WIDTH_WIDE;
            return this.COL_WIDTH_DEFAULT;
        },

        /**
         * Size the table so exactly VISIBLE_DATA_COLUMNS data columns fill the
         * available width; anything beyond that goes behind a horizontal scroll.
         *
         * The scroll container always stays full width. What changes is the
         * table: when there are more columns than fit, each column is widened
         * proportionally so the first nine span the full container, which
         * pushes the tenth off the visible area and triggers the scrollbar.
         *
         * cb and thumb are fixed-width and are not counted, matching the
         * Columns modal which also hides them from its list.
         */
        /* ------------------------------------------------------------------
           VERTICAL FIT
           ------------------------------------------------------------------ */

        // Never shrink the table below this, even on a very short window.
        // Kept modest so the page itself does not gain a scrollbar on a 720px
        // laptop screen — the table has its own.
        MIN_TABLE_HEIGHT: 150,

        /**
         * Give the table whatever vertical space is left below it.
         *
         * A fixed `max-height: 58vh` is measured against the whole window, not
         * against what the filters and toolbars have already used, so on a
         * short laptop screen the table ended up with almost nothing. Measuring
         * the real offset means the table grows to fill the window instead.
         */
        // Always show at least this many product rows, even when that pushes
        // the page past the bottom of the window — scrolling the page is
        // preferable to a table too short to work in.
        MIN_VISIBLE_ROWS: 5,

        applyTableHeight: function () {
            var s = this,
                $scroll = $('.wc-bulk-table-scroll');

            if (!$scroll.length) return;

            var winH = $(window).height(),
                top = $scroll[0].getBoundingClientRect().top,
                $card = $scroll.closest('.wc-bulk-table-card');

            // Only what sits BELOW the table counts — the card header above it
            // is already part of `top`.
            var trailing = 0;

            if ($card.length) {
                var cardBox = $card[0].getBoundingClientRect(),
                    scrollBox = $scroll[0].getBoundingClientRect();

                trailing =
                    cardBox.bottom - scrollBox.bottom + (parseFloat($card.css('marginBottom')) || 0);
            }

            var available = winH - top - trailing - 12;

            // Floor the height at MIN_VISIBLE_ROWS rows plus the sticky header,
            // measured from the rendered table rather than assumed.
            var $row = $scroll.find('tbody tr').first(),
                $head = $scroll.find('thead'),
                rowH = $row.length ? $row.outerHeight() : 0,
                headH = $head.length ? $head.outerHeight() : 0,
                floorH = rowH ? rowH * s.MIN_VISIBLE_ROWS + headH + 2 : s.MIN_TABLE_HEIGHT;

            var height = Math.max(available, floorH);

            $scroll.css('max-height', Math.floor(height) + 'px');
        },

        applyColumnViewport: function () {
            var s = this,
                cols = s.getActiveColumns(),
                $scroll = $('.wc-bulk-table-scroll'),
                $table = $('#wc-bulk-table');

            if (!$scroll.length || !$table.length) return;

            var dataCols = $.grep(cols, function (c) {
                return c !== 'cb' && c !== 'thumb';
            });

            // Clear any previous sizing before measuring.
            s.clearColumnWidths();

            if (dataCols.length <= s.VISIBLE_DATA_COLUMNS) {
                // Everything fits: let the stylesheet's widths apply.
                return;
            }

            // Measure the fixed columns as actually rendered: padding and
            // borders make the painted width larger than the CSS width, and
            // guessing here pushes the ninth column off screen.
            var fixed = 0;
            $table
                .find('thead th, thead td')
                .each(function () {
                    var $cell = $(this);
                    if ($cell.hasClass('check-column') || $cell.hasClass('column-thumb')) {
                        fixed += $cell.outerWidth() || 0;
                    }
                });

            // Fall back to the stylesheet values if the head is not rendered yet.
            if (!fixed) {
                $.each(cols, function (i, c) {
                    if (c === 'cb' || c === 'thumb') fixed += s.columnWidth(c);
                });
            }

            var available = $scroll.innerWidth() - fixed;
            if (available <= 0) return;

            // Natural width of the first nine data columns.
            var visible = dataCols.slice(0, s.VISIBLE_DATA_COLUMNS),
                natural = 0;
            $.each(visible, function (i, c) {
                natural += s.columnWidth(c);
            });

            // Only stretch — never shrink below the stylesheet widths, or the
            // columns would be cramped on a narrow screen.
            var scale = available / natural;
            if (scale < 1) scale = 1;

            var css = [];
            $.each(dataCols, function (i, c) {
                var w = Math.floor(s.columnWidth(c) * scale);
                // The stylesheet's generic width rule is highly specific
                // (.wc-bulk-table thead th:not()...:not()), so these selectors
                // must out-specify it or the widths never take effect.
                var sel = '#wc-bulk-table thead th.column-' + c +
                    ',#wc-bulk-table thead td.column-' + c +
                    ',#wc-bulk-table tbody td.column-' + c;
                css.push(
                    sel + '{box-sizing:border-box!important;width:' + w + 'px!important;' +
                        'min-width:' + w + 'px!important;max-width:' + w + 'px!important;}'
                );
            });

            $('#wc-bulk-col-widths').remove();
            $('<style id="wc-bulk-col-widths">').text(css.join('')).appendTo(document.head);
        },

        clearColumnWidths: function () {
            $('#wc-bulk-col-widths').remove();
        },

        renderTableHead: function () {
            var s = this,
                cols = s.getActiveColumns(),
                thead = $('#wc-bulk-table-head'),
                L = {
                    cb: '<input type="checkbox" id="wc-bulk-select-all-top" />',
                    thumb: '',
                    name: 'Product',
                    sku: 'SKU',
                    regular_price: 'Price',
                    sale_price: 'Sale',
                    stock_quantity: 'Stock',
                    stock_status: 'Stock Status',
                    post_status: 'Status',
                    categories: 'Categories',
                    tags: 'Tags',
                    type: 'Type',
                    tax_status: 'Tax',
                    tax_class: 'Tax Class',
                    shipping_class: 'Shipping',
                    weight: 'Weight',
                    length: 'Length',
                    width: 'Width',
                    height: 'Height',
                    featured: '★',
                    catalog_visibility: 'Visibility',
                    virtual: 'Virt',
                    downloadable: 'DL',
                    manage_stock: 'Mgmt',
                    backorders: 'Backord',
                    sold_individually: 'Sold Ind.',
                    reviews_allowed: 'Reviews',
                    description: 'Description',
                    short_description: 'Short Desc',
                    purchase_note: 'Purch. Note',
                    menu_order: 'Order',
                };
            var row = $('<tr>');
            $.each(cols, function (i, c) {
                // L holds hardcoded labels and, for `cb`, intentional markup —
                // so it passes through. The fallback is the raw column key
                // from the user's saved list, which does not.
                var lab = L[c] !== undefined ? L[c] : s.esc(c);
                if (c === 'cb') {
                    row.append('<td class="manage-column column-cb check-column">' + lab + '</td>');
                } else if (c === 'thumb') {
                    row.append('<th class="manage-column column-thumb">' + lab + '</th>');
                } else {
                    row.append(
                        '<th class="manage-column column-' + s.escAttr(c) + '">' + lab + '</th>'
                    );
                }
            });
            thead.empty().append(row);
            $('#wc-bulk-select-all-top').on('change', function () {
                var ck = $(this).prop('checked');
                $('.wc-bulk-row-check').prop('checked', ck).trigger('change');
            });
        },

        // Standard input cell

        /* ------------------------------------------------------------------
           CELL RENDERERS
           ------------------------------------------------------------------ */
        renderEditableCell: function (p, field, type, step, placeholder, value) {
            var s = this,
                ch = s.isChanged(p.id, field) ? ' changed' : '',
                cv =
                    s.changes[p.id] && s.changes[p.id][field] !== undefined
                        ? s.changes[p.id][field]
                        : value || '';
            return (
                '<td><input type="' +
                type +
                '" class="wc-bulk-inline-input' +
                ch +
                '" data-product-id="' +
                p.id +
                '" data-field="' +
                field +
                '" value="' +
                s.escAttr(cv) +
                '" step="' +
                step +
                '" placeholder="' +
                placeholder +
                '" /></td>'
            );
        },

        // EXPANDABLE TEXTAREA CELL
        renderTextareaCell: function (p, field, value) {
            var s = this,
                ch = s.isChanged(p.id, field) ? ' changed' : '',
                cv =
                    s.changes[p.id] && s.changes[p.id][field] !== undefined
                        ? s.changes[p.id][field]
                        : value || '';
            return (
                '<td><textarea class="wc-bulk-inline-textarea' +
                ch +
                '" data-product-id="' +
                p.id +
                '" data-field="' +
                field +
                '" rows="1" placeholder="' +
                field.replace(/_/g, ' ') +
                '...">' +
                s.esc(cv) +
                '</textarea></td>'
            );
        },

        // `serverValue` overrides the fallback for fields whose editable value
        // is not p[field] — shipping_class edits by term id, not by slug.
        renderSelectCell: function (p, field, options, serverValue) {
            var s = this,
                ch = s.isChanged(p.id, field) ? ' changed' : '',
                fallback = serverValue === undefined ? p[field] : serverValue,
                cv = String(
                    s.changes[p.id] && s.changes[p.id][field] !== undefined
                        ? s.changes[p.id][field]
                        : fallback === null || fallback === undefined
                          ? ''
                          : fallback
                );
            var h =
                '<td><select class="wc-bulk-inline-select' +
                ch +
                '" data-product-id="' +
                p.id +
                '" data-field="' +
                field +
                '">';
            // Labels may come from user-created terms, so both halves are
            // escaped rather than trusted.
            $.each(options, function (v, l) {
                h +=
                    '<option value="' +
                    s.escAttr(v) +
                    '"' +
                    (cv === String(v) ? ' selected' : '') +
                    '>' +
                    s.esc(l) +
                    '</option>';
            });
            return h + '</select></td>';
        },

        // Build a {value: label} map from a preloaded list, e.g. WCB.tax_classes.
        classOptions: function (list, valueKey) {
            var map = {};
            $.each(list || [], function (i, item) {
                map[item[valueKey]] = item.name;
            });
            return map;
        },
        renderBoolCell: function (p, field, yesIcon, noIcon) {
            var s = this,
                val =
                    s.changes[p.id] && s.changes[p.id][field] !== undefined
                        ? s.changes[p.id][field]
                        : p[field]
                          ? 'yes'
                          : 'no';
            var ch = s.isChanged(p.id, field) ? ' changed' : '';
            return (
                '<td><select class="wc-bulk-inline-select' +
                ch +
                '" data-product-id="' +
                p.id +
                '" data-field="' +
                field +
                '"><option value="yes"' +
                (val === 'yes' ? ' selected' : '') +
                '>' +
                yesIcon +
                '</option><option value="no"' +
                (val === 'no' ? ' selected' : '') +
                '>' +
                noIcon +
                '</option></select></td>'
            );
        },
        renderCats: function (cats) {
            if (!cats || !cats.length) return '<span class="wc-bulk-ro muted">—</span>';
            var h = '<div class="wc-bulk-categories">',
                max = 3;
            $.each(cats.slice(0, max), function (i, c) {
                h += '<span class="wc-bulk-cat-tag">' + B.esc(c) + '</span>';
            });
            if (cats.length > max)
                h += '<span class="wc-bulk-cat-tag">+' + (cats.length - max) + '</span>';
            return h + '</div>';
        },

        /* ------------------------------------------------------------------
           CHANGE TRACKING
           ------------------------------------------------------------------ */
        trackChange: function (pid, field, newVal, $el) {
            var s = this,
                orig = s.originals[pid];
            if (!orig) return;
            // The categories column is a single-select, so normalise to one id
            // (or an empty string) before comparing against the original.
            if (field === 'categories') {
                var first = $.isArray(newVal) ? newVal[0] : newVal;
                newVal = first ? String(first) : '';
            }
            var ov = s.origVal(pid, field),
                cv = String(newVal === null || newVal === undefined ? '' : newVal);
            if (cv === ov) {
                if (s.changes[pid]) {
                    delete s.changes[pid][field];
                    if (!Object.keys(s.changes[pid]).length) delete s.changes[pid];
                }
                $el.removeClass('changed');
            } else {
                if (!s.changes[pid]) s.changes[pid] = {};
                s.changes[pid][field] = cv;
                $el.addClass('changed');
            }
            $el.closest('tr').toggleClass('row-modified', !!s.changes[pid]);
            s.updateSaveBar();
        },
        origVal: function (pid, field) {
            var o = this.originals[pid];
            if (!o) return '';
            var v = o[field];
            if (field === 'categories') return String((o.category_ids || [])[0] || '');
            if (field === 'tags') return (v || []).join(', ');
            if (field === 'shipping_class')
                return o.shipping_class_id ? String(o.shipping_class_id) : '';
            if (typeof v === 'boolean') return v ? 'yes' : 'no';
            if (v === null || v === undefined) return '';
            return String(v);
        },
        // basis hitung untuk sale_price selalu regular_price
        baseVal: function (pid, field) {
            var o = this.originals[pid];
            if (!o) return 0;
            var src = field === 'sale_price' ? 'regular_price' : field;
            return parseFloat(o[src]) || 0;
        },
        isChanged: function (pid, field) {
            return this.changes[pid] && this.changes[pid][field] !== undefined;
        },

        /* ------------------------------------------------------------------
           BULK OPERATIONS
           ------------------------------------------------------------------ */
        quickApply: function () {
            var s = this,
                qc = {};
            $('.wc-bulk-quick-op').each(function () {
                var f = $(this).data('field'),
                    op = $(this).val(),
                    val = $(this).siblings('.wc-bulk-quick-val').val();
                if (op && op !== '' && val !== '' && val !== null)
                    qc[f] = { op: op, value: parseFloat(val) };
                else if (op === 'clear') qc[f] = { op: 'clear', value: '' };
            });
            $('.wc-bulk-quick-direct').each(function () {
                var v = $(this).val();
                if (v !== '') qc[$(this).data('field')] = { op: 'set', value: v };
            });
            if ($.isEmptyObject(qc)) {
                alert(WCB.i18n.select_field);
                return;
            }
            $('.wc-bulk-inline-input,.wc-bulk-inline-textarea,.wc-bulk-inline-select').each(
                function () {
                    var pid = $(this).data('product-id'),
                        f = $(this).data('field');
                    if (!qc[f]) return;
                    var orig = s.originals[pid];
                    if (!orig) return;
                    var ov = s.baseVal(pid, f),
                        nv,
                        q = qc[f];
                    switch (q.op) {
                        case 'set':
                            nv = String(q.value);
                            break;
                        case 'clear':
                            nv = '';
                            break;
                        case 'increase':
                            nv = String(Math.max(0, ov + q.value));
                            break;
                        case 'decrease':
                            nv = String(Math.max(0, ov - q.value));
                            break;
                        case 'increase_percent':
                            nv = String(Math.max(0, ov * (1 + q.value / 100)).toFixed(2));
                            break;
                        case 'decrease_percent':
                            nv = String(Math.max(0, ov * (1 - q.value / 100)).toFixed(2));
                            break;
                        case 'increase_fixed':
                            nv = String(Math.max(0, ov + q.value).toFixed(2));
                            break;
                        case 'decrease_fixed':
                            nv = String(Math.max(0, ov - q.value).toFixed(2));
                            break;
                        case 'reduce_percent':
                            nv = String(Math.max(0, ov * (1 - q.value / 100)).toFixed(2));
                            break;
                        default:
                            return;
                    }
                    $(this).val(nv);
                    s.trackChange(pid, f, nv, $(this));
                }
            );
            s.showNotice('success', WCB.i18n.quick_applied);
        },

        saveAll: function () {
            var s = this,
                ids = Object.keys(s.changes);
            if (!ids.length) {
                alert(WCB.i18n.no_changes);
                return;
            }
            if (!confirm(WCB.i18n.confirm_save.replace('{count}', ids.length))) return;
            if (s.saving) return;
            s.saving = true;
            $('#wc-bulk-save-all').prop('disabled', true);
            $('.wc-bulk-save-progress-fill').css('width', '40%');
            $.post(
                WCB.ajax_url,
                { action: 'wc_bulk_save_inline', nonce: WCB.nonce, changes: s.changes },
                function (r) {
                    $('.wc-bulk-save-progress-fill').css('width', '100%');
                    if (r.success) {
                        $.each(s.changes, function (pid, fields) {
                            $.each(fields, function (f, v) {
                                if (s.originals[pid]) s.originals[pid][f] = v;
                            });
                        });
                        s.changes = {};
                        s.showNotice('success', r.data.message);
                        s.loadProducts();
                        $(
                            '.wc-bulk-inline-input,.wc-bulk-inline-textarea,.wc-bulk-inline-select'
                        ).removeClass('changed');
                        $('.wc-bulk-table tbody tr').removeClass('row-modified');
                        s.updateSaveBar();
                    } else {
                        s.showNotice('error', (r.data && r.data.message) || WCB.i18n.error);
                        if (r.data && r.data.updated) s.loadProducts();
                    }
                    setTimeout(function () {
                        $('.wc-bulk-save-progress-fill').css('width', '0');
                        $('#wc-bulk-save-all').prop('disabled', false);
                    }, 600);
                    s.saving = false;
                }
            ).fail(function (jq) {
                s.showNotice(
                    'error',
                    (jq.responseJSON && jq.responseJSON.data && jq.responseJSON.data.message) ||
                        WCB.i18n.error
                );
                s.saving = false;
                $('#wc-bulk-save-all').prop('disabled', false);
            });
        },
        discardAll: function () {
            var s = this;
            s.changes = {};
            $('.wc-bulk-inline-input,.wc-bulk-inline-textarea,.wc-bulk-inline-select').each(
                function () {
                    var pid = $(this).data('product-id'),
                        f = $(this).data('field'),
                        orig = s.originals[pid];
                    if (orig) {
                        $(this).val(s.origVal(pid, f));
                        $(this).trigger('input');
                    }
                    $(this).removeClass('changed');
                }
            );
            $('.wc-bulk-table tbody tr').removeClass('row-modified');
            s.updateSaveBar();
            s.showNotice('success', WCB.i18n.changes_discarded);
        },
        updateSaveBar: function () {
            var c = Object.keys(this.changes).length;
            $('.wc-bulk-save-bar').toggle(c > 0);
            $('.wc-bulk-changed-count').text(c + ' modified');
        },

        doBulkAction: function () {
            var s = this,
                action = $('#wc-bulk-action-select').val(),
                ids = Object.keys(s.selectedRows);
            if (!action || !ids.length) {
                alert(action ? WCB.i18n.no_products_selected : 'Select an action.');
                return;
            }
            var msgs = {
                duplicate: WCB.i18n.confirm_duplicate,
                trash: WCB.i18n.confirm_trash,
                delete: WCB.i18n.confirm_delete,
            };
            if (!confirm(msgs[action].replace('{count}', ids.length))) return;
            $.post(
                WCB.ajax_url,
                {
                    action: 'wc_bulk_bulk_action',
                    nonce: WCB.nonce,
                    bulk_action: action,
                    product_ids: ids,
                },
                function (r) {
                    if (r.success) {
                        s.showNotice('success', r.data.message);
                        s.selectedRows = {};
                        s.loadProducts();
                    } else s.showNotice('error', r.data.message || WCB.i18n.error);
                }
            );
        },
        updateSelectCount: function () {
            $('.wc-bulk-actions-bar').toggle(Object.keys(this.selectedRows).length > 0);
            this.applyTableHeight();

            var c = Object.keys(this.selectedRows).length;
            $('.wc-bulk-selected-count').text(c > 0 ? c + ' selected' : '');
        },

        /* ------------------------------------------------------------------
           COLUMNS MODAL
           ------------------------------------------------------------------ */
        openColumnsModal: function () {
            var s = this,
                list = $('#wc-bulk-columns-list');
            list.empty();
            $.post(WCB.ajax_url, { action: 'wc_bulk_get_columns', nonce: WCB.nonce }, function (r) {
                if (!r.success) return;
                var activeCols = s.getActiveColumns();
                $.each(r.data.columns, function (key, col) {
                    if (key === 'cb' || key === 'thumb') return;
                    var active = $.inArray(key, activeCols) !== -1;
                    list.append(
                        '<div class="wc-bulk-column-item" data-column="' +
                            B.escAttr(key) +
                            '"><span class="dashicons dashicons-menu"></span><input type="checkbox" ' +
                            (active ? 'checked' : '') +
                            ' /><span>' +
                            B.esc(col.label) +
                            '</span>' +
                            (col.editable
                                ? '<span class="dashicons dashicons-edit" title="Editable"></span>'
                                : '') +
                            '</div>'
                    );
                });
                list.sortable({ axis: 'y', cursor: 'grabbing' });
            });
            $('#wc-bulk-modal-columns').show();
        },
        saveColumns: function () {
            var s = this,
                cols = ['cb', 'thumb'];
            $('#wc-bulk-columns-list .wc-bulk-column-item').each(function () {
                if ($(this).find('input').prop('checked')) cols.push($(this).data('column'));
            });
            $.post(
                WCB.ajax_url,
                { action: 'wc_bulk_save_columns', nonce: WCB.nonce, columns: cols },
                function (r) {
                    if (r.success) {
                        WCB.columns = r.data.columns;
                        s._activeColumns = null;
                        s.showNotice('success', WCB.i18n.columns_saved);
                        $('#wc-bulk-modal-columns').hide();
                        s.page = 1;
                        s.loadProducts();
                    }
                }
            );
        },
        resetColumns: function () {
            $('#wc-bulk-columns-list .wc-bulk-column-item').each(function () {
                var key = $(this).data('column');
                $(this)
                    .find('input')
                    .prop('checked', WCB.all_columns[key] && WCB.all_columns[key]['default']);
            });
        },

        /* ------------------------------------------------------------------
           BULK EDIT MODAL
           ------------------------------------------------------------------ */
        openBulkEditModal: function () {
            var ids = Object.keys(this.selectedRows);
            $('.wc-bulk-modal-selected').text(
                ids.length > 0 ? ids.length + ' selected' : 'No selection (applies to all loaded)'
            );
            $('#wc-bulk-modal-edit').show();
            $('.wc-bulk-tab').first().trigger('click');
        },
        applyBulkEditModal: function () {
            var s = this,
                ids = Object.keys(s.selectedRows);
            if (!ids.length) {
                $('.wc-bulk-row-check').each(function () {
                    ids.push($(this).val());
                });
            }
            if (!ids.length) {
                alert(WCB.i18n.no_products_selected);
                return;
            }
            var mc = {};
            ['regular_price', 'sale_price', 'stock_quantity'].forEach(function (f) {
                var op = $('#wc-bulk-modal-edit [data-field="' + f + '_op"]').val();
                var val = $('#wc-bulk-modal-edit [data-field="' + f + '"]').val();
                if (op && op !== '' && val !== '' && val !== null)
                    mc[f] = { op: op, value: parseFloat(val) };
                else if (op === 'clear') mc[f] = { op: 'clear', value: '' };
            });
            $('#wc-bulk-modal-edit [data-field]').each(function () {
                var f = $(this).data('field');
                if (f.indexOf('_op') !== -1) return;
                if (mc[f]) return;
                var v = $(this).val();

                // A multi-select returns an array. The table cells and the
                // save payload both work in strings, so join here — the PHP
                // side splits on commas again in set_categories()/set_tags().
                if ($.isArray(v)) {
                    if (!v.length) return;
                    v = v.join(',');
                }

                if (v !== '' && v !== null && v !== undefined) mc[f] = v;
            });
            if ($.isEmptyObject(mc)) {
                alert(WCB.i18n.select_one_field);
                return;
            }
            if (!confirm(WCB.i18n.confirm_bulk_edit.replace('{count}', ids.length))) return;
            $.each(ids, function (i, pid) {
                if (!s.changes[pid]) s.changes[pid] = {};
                var orig = s.originals[pid];
                if (!orig) return;
                $.each(mc, function (f, val) {
                    if (typeof val === 'object' && val.op) {
                        var ov = s.baseVal(pid, f),
                            nv;
                        switch (val.op) {
                            case 'set':
                                nv = String(val.value);
                                break;
                            case 'clear':
                                nv = '';
                                break;
                            case 'increase':
                                nv = String(Math.max(0, ov + val.value));
                                break;
                            case 'decrease':
                                nv = String(Math.max(0, ov - val.value));
                                break;
                            case 'increase_percent':
                                nv = String(Math.max(0, ov * (1 + val.value / 100)).toFixed(2));
                                break;
                            case 'decrease_percent':
                                nv = String(Math.max(0, ov * (1 - val.value / 100)).toFixed(2));
                                break;
                            case 'increase_fixed':
                                nv = String(Math.max(0, ov + val.value).toFixed(2));
                                break;
                            case 'decrease_fixed':
                                nv = String(Math.max(0, ov - val.value).toFixed(2));
                                break;
                            case 'reduce_percent':
                                nv = String(Math.max(0, ov * (1 - val.value / 100)).toFixed(2));
                                break;
                            default:
                                return;
                        }
                        s.changes[pid][f] = nv;
                    } else {
                        s.changes[pid][f] = val;
                    }
                });
            });
            $('#wc-bulk-modal-edit').hide();
            s.loadProducts();
            s.showNotice(
                'success',
                'Changes staged for ' + ids.length + ' product(s). Click Save All to commit.'
            );
        },

        /* ------------------------------------------------------------------
           QUICK ADD PRODUCT
           ------------------------------------------------------------------ */
        /* ------------------------------------------------------------------
           NEW CATEGORY
           ------------------------------------------------------------------ */

        openCategoryModal: function () {
            var s = this;
            $('#wc-bulk-cat-name').val('');
            s.fillCategoryParents();
            $('#wc-bulk-modal-category').show();
            $('#wc-bulk-cat-name').trigger('focus');
        },

        // Parent picker is rebuilt each time so it reflects categories added
        // earlier in this session.
        fillCategoryParents: function () {
            var s = this,
                $sel = $('#wc-bulk-cat-parent'),
                keep = $sel.val();
            $sel.find('option:gt(0)').remove();
            $.each(WCB.all_cats || [], function (i, c) {
                $sel.append($('<option>', { value: c.id, text: c.name }));
            });
            if (keep) $sel.val(keep);
        },

        createCategory: function () {
            var s = this,
                name = $.trim($('#wc-bulk-cat-name').val()),
                parent = $('#wc-bulk-cat-parent').val() || 0,
                $btn = $('#wc-bulk-cat-confirm');

            if (!name) {
                s.showNotice('error', WCB.i18n.cat_name_required);
                $('#wc-bulk-cat-name').trigger('focus');
                return;
            }

            $btn.prop('disabled', true);

            $.post(
                WCB.ajax_url,
                {
                    action: 'wc_bulk_create_category',
                    nonce: WCB.nonce,
                    name: name,
                    parent: parent,
                },
                function (r) {
                    $btn.prop('disabled', false);

                    if (!r.success) {
                        s.showNotice('error', (r.data && r.data.message) || WCB.i18n.error);
                        return;
                    }

                    // Refresh the shared list, then reflect it everywhere the
                    // category list is rendered, without reloading the page.
                    WCB.all_cats = r.data.all_cats;
                    s.syncCategoryOptions(r.data.category);
                    $('#wc-bulk-modal-category').hide();
                    s.showNotice(
                        'success',
                        r.data.created ? WCB.i18n.cat_created : WCB.i18n.cat_exists
                    );
                }
            );
        },

        // Add one category to every rendered <select> plus the filter dropdown,
        // preserving each row's current selection.
        syncCategoryOptions: function (cat) {
            if (!cat) return;

            $('.wcbx-cats').each(function () {
                var $sel = $(this);
                if ($sel.find('option[value="' + cat.id + '"]').length) return;
                $sel.append($('<option>', { value: cat.id, text: cat.name }));
            });

            var $filter = $('#wc-bulk-category');
            if ($filter.length && !$filter.find('option[value="' + cat.id + '"]').length) {
                $filter.append($('<option>', { value: cat.id, text: cat.name }));
            }

            this.fillModalCategories([cat]);
            this.fillCategoryParents();
        },

        openAddModal: function () {
            $('#wc-bulk-add-name,#wc-bulk-add-sku,#wc-bulk-add-price').val('');
            $('#wc-bulk-add-status').val('publish');
            $('#wc-bulk-modal-add').show();
            $('#wc-bulk-add-name').focus();
        },
        quickAddProduct: function () {
            var s = this,
                name = $('#wc-bulk-add-name').val().trim();
            if (!name) {
                alert(WCB.i18n.product_name_req);
                return;
            }
            $('#wc-bulk-add-confirm').prop('disabled', true).text('Creating...');
            $.post(
                WCB.ajax_url,
                {
                    action: 'wc_bulk_quick_add',
                    nonce: WCB.nonce,
                    name: name,
                    sku: $('#wc-bulk-add-sku').val(),
                    regular_price: $('#wc-bulk-add-price').val(),
                    status: $('#wc-bulk-add-status').val(),
                },
                function (r) {
                    if (r.success) {
                        s.showNotice('success', WCB.i18n.product_created);
                        $('#wc-bulk-modal-add').hide();
                        s.page = 1;
                        s.loadProducts();
                    } else s.showNotice('error', r.data.message || WCB.i18n.error);
                    $('#wc-bulk-add-confirm').prop('disabled', false).text('Create Product');
                }
            );
        },

        /* ------------------------------------------------------------------
           CSV EXPORT
           ------------------------------------------------------------------ */
        exportCSV: function () {
            var s = this,
                ids = [];
            if (Object.keys(s.selectedRows).length > 0) ids = Object.keys(s.selectedRows);
            else
                $('.wc-bulk-row-check').each(function () {
                    ids.push($(this).val());
                });
            if (!ids.length) {
                alert(WCB.i18n.no_export);
                return;
            }
            $.post(
                WCB.ajax_url,
                { action: 'wc_bulk_export_csv', nonce: WCB.nonce, product_ids: ids },
                function (r) {
                    if (r.success) {
                        var blob = new Blob([r.data.csv], { type: 'text/csv;charset=utf-8;' });
                        var url = URL.createObjectURL(blob);
                        var a = document.createElement('a');
                        a.href = url;
                        a.download = r.data.filename;
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        URL.revokeObjectURL(url);
                        s.showNotice('success', WCB.i18n.csv_exported);
                    } else s.showNotice('error', r.data.message || WCB.i18n.error);
                }
            );
        },

        // Views bar is a user preference, remembered per browser.

        /* ------------------------------------------------------------------
           SAVED VIEWS
           ------------------------------------------------------------------ */
        viewsBarOpen: function () {
            return localStorage.getItem('wcbViewsBar') !== '0';
        },

        toggleViewsBar: function () {
            var s = this;
            if (!WCB.views || !WCB.views.length) {
                s.showNotice('success', WCB.i18n.no_views);
                return;
            }
            var open = !s.viewsBarOpen();
            localStorage.setItem('wcbViewsBar', open ? '1' : '0');
            $('.wc-bulk-views-bar').toggle(open);
            s.syncViewsButton();
        },

        // Keep the button's pressed state and the bar in sync.
        syncViewsButton: function () {
            var s = this,
                has = !!(WCB.views && WCB.views.length),
                open = has && s.viewsBarOpen();
            $('.wc-bulk-views-bar').toggle(open);
            $('#wc-bulk-btn-views')
                .toggleClass('active', open)
                .attr('aria-pressed', open ? 'true' : 'false');
        },

        renderViewsBar: function () {
            var s = this;
            if (!WCB.views || !WCB.views.length) {
                $('.wc-bulk-views-bar').hide();
                s.syncViewsButton();
                return;
            }
            var list = $('.wc-bulk-views-list');
            list.empty();
            $.each(WCB.views, function (i, v) {
                list.append(
                    '<span class="wc-bulk-view-chip" data-view-id="' +
                        v.id +
                        '">' +
                        s.esc(v.name) +
                        '<span class="dashicons dashicons-no-alt wc-bulk-view-chip-delete"></span></span>'
                );
            });
            s.syncViewsButton();
        },
        saveView: function () {
            var s = this,
                name = prompt(WCB.i18n.view_name_prompt);
            if (!name || !name.trim()) return;
            var filters = {
                search: $('#wc-bulk-search').val(),
                category: $('#wc-bulk-category').val(),
                type: $('#wc-bulk-type').val(),
                status: $('#wc-bulk-status').val(),
                stock_status: $('#wc-bulk-stock-status').val(),
                featured: $('#wc-bulk-featured').val(),
            };
            $.post(
                WCB.ajax_url,
                { action: 'wc_bulk_save_view', nonce: WCB.nonce, name: name, filters: filters },
                function (r) {
                    if (r.success) {
                        WCB.views = r.data.views;
                        localStorage.setItem('wcbViewsBar', '1');
                        s.renderViewsBar();
                        s.showNotice('success', WCB.i18n.view_saved);
                    } else s.showNotice('error', r.data.message || WCB.i18n.error);
                }
            );
        },
        loadView: function (viewId) {
            var s = this,
                view = $.grep(WCB.views, function (v) {
                    return v.id === viewId;
                })[0];
            if (!view) return;
            $.each(view.filters, function (k, v) {
                $('#wc-bulk-' + k).val(v || '');
            });
            s.page = 1;
            s.loadProducts();
        },
        deleteView: function (viewId) {
            var s = this;
            if (!confirm(WCB.i18n.confirm_delete_view)) return;
            $.post(
                WCB.ajax_url,
                { action: 'wc_bulk_delete_view', nonce: WCB.nonce, view_id: viewId },
                function (r) {
                    if (r.success) {
                        WCB.views = r.data.views;
                        s.renderViewsBar();
                        s.showNotice('success', WCB.i18n.view_deleted);
                    }
                }
            );
        },

        /* ------------------------------------------------------------------
           PAGINATION & NOTICES
           ------------------------------------------------------------------ */
        renderPagination: function (total) {
            var s = this,
                p = $('.wc-bulk-pagination');
            p.empty();
            if (s.pages <= 1) return;
            var add = function (txt, pg, dis, act) {
                var b = $('<button>' + txt + '</button>')
                    .prop('disabled', dis)
                    .toggleClass('active', act);
                if (!dis)
                    b.on('click', function () {
                        s.page = pg;
                        s.loadProducts();
                        $('.wc-bulk-table-scroll').scrollTop(0);
                    });
                p.append(b);
            };
            add('«', s.page - 1, s.page <= 1);
            var st = Math.max(1, s.page - 3),
                en = Math.min(s.pages, s.page + 3);
            if (st > 1) {
                add('1', 1);
                if (st > 2) p.append('<span class="wc-bulk-pagination-dots">…</span>');
            }
            for (var i = st; i <= en; i++) add(String(i), i, false, i === s.page);
            if (en < s.pages) {
                if (en < s.pages - 1) p.append('<span class="wc-bulk-pagination-dots">…</span>');
                add(String(s.pages), s.pages);
            }
            add('»', s.page + 1, s.page >= s.pages);
        },
        updateResults: function (t) {
            $('.wc-bulk-results-count').text(String(t));
        },
        showNotice: function (type, msg) {
            var n = $(
                '<div class="wc-bulk-notice ' +
                    type +
                    '"><span class="dashicons dashicons-' +
                    (type === 'success' ? 'yes-alt' : 'dismiss') +
                    '"></span><span>' +
                    this.esc(msg) +
                    '</span></div>'
            );
            $('.wc-bulk-notice-wrapper').html(n);
            clearTimeout(this._nt);
            this._nt = setTimeout(function () {
                n.fadeOut(400, function () {
                    $(this).remove();
                });
            }, 5000);
        },

        /* ------------------------------------------------------------------
           HELPERS
           ------------------------------------------------------------------ */
        fmtPrice: function (p) {
            if (!p || isNaN(p)) return '';
            return String(p);
        },
        esc: function (t) {
            if (!t) return '';
            var d = document.createElement('div');
            d.appendChild(document.createTextNode(String(t)));
            return d.innerHTML;
        },
        escAttr: function (t) {
            if (!t && t !== 0) return '';
            return String(t)
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
        },
    };
    $(document).ready(function () {
        B.init();
    });
})(jQuery, window.WCBulkEditor);
jQuery(function ($) {
    $(document).on('change', '.wc-bulk-inline-select[data-field="manage_stock"]', function () {
        var on = $(this).val() === 'yes',
            $q = $(this).closest('tr').find('.wcbx-stock');
        if (!$q.length) return;
        $q.prop('disabled', !on).attr('placeholder', on ? '0' : 'N/A');
        if (on) {
            $q.attr('title', '');
        } else {
            $q.val('').trigger('change');
        }
    });
});
