jQuery(function($) {
    'use strict';

    // Safety check
    if (typeof cigStockTable === 'undefined') return;

    // --- 1. SELECTION LOGIC (Delegated to CIGSelection when available) ---
    
    // Wait for CIGSelection to be available (handles script load order)
    var useCIGSelection = false;
    var initTimeout = null;
    
    function checkCIGSelection() {
        if (typeof window.CIGSelection !== 'undefined') {
            useCIGSelection = true;
            // Re-initialize with CIGSelection
            initCart();
            return true;
        }
        return false;
    }
    
    // Legacy cart for backward compatibility (only used if CIGSelection not available)
    var cart = cigStockTable.initialCart || [];

    function initCart() {
        if (useCIGSelection) {
            // CIGSelection handles its own initialization
            // Just listen for its events to update UI
            $(document).off('cigSelectionUpdated.stockTable').on('cigSelectionUpdated.stockTable', function(e, data) {
                // The CIGSelection manager handles all UI updates
            });
            // Remove legacy click handler if it was bound
            $(document).off('click.cigLegacyCart', '.cig-add-btn');
        } else {
            // Legacy fallback
            updateCartUI();
            bindLegacyClickHandler();
        }
    }
    
    function bindLegacyClickHandler() {
        // Only bind once
        $(document).off('click.cigLegacyCart', '.cig-add-btn').on('click.cigLegacyCart', '.cig-add-btn', function(e) {
            // Re-check if CIGSelection became available
            if (checkCIGSelection()) {
                // Let CIGSelection handle it
                return;
            }
            
            e.preventDefault();
            var $btn = $(this);
            
            // Prevent disabled buttons
            if ($btn.is(':disabled')) return;

            var id = $btn.data('id');

            if (isInCart(id)) {
                removeFromCart(id);
            } else {
                var data = {
                    id: id,
                    sku: $btn.data('sku'),
                    name: $btn.data('title'), 
                    price: $btn.data('price'),
                    image: $btn.data('image'),
                    brand: $btn.data('brand'),
                    desc: $btn.data('desc'),
                    qty: 1
                };
                addToCart(data);
            }
        });
    }

    function updateCartUI() {
        // This is now handled by CIGSelection._updateAllUI()
        // Only run this if CIGSelection is not available (legacy mode)
        if (useCIGSelection) return;
        
        var count = cart.length;
        $('#cig-cart-count').text(count);
        
        var $bar = $('#cig-stock-cart-bar');
        if (count > 0) {
            $bar.fadeIn(200).css('display', 'flex');
        } else {
            $bar.fadeOut(200);
        }

        // Sync buttons state
        $('.cig-add-btn').each(function() {
            var id = $(this).data('id');
            if (isInCart(id)) {
                $(this).addClass('added').html('<span class="dashicons dashicons-yes"></span>');
            } else {
                $(this).removeClass('added').html('<span class="dashicons dashicons-plus"></span>');
            }
        });
    }

    function isInCart(id) {
        if (useCIGSelection) {
            return window.CIGSelection.has(id);
        }
        return cart.some(function(item) { return item.id == id; });
    }

    function addToCart(productData) {
        if (useCIGSelection) {
            // Delegate to CIGSelection
            window.CIGSelection.add(productData);
            return;
        }
        
        // Legacy fallback
        if (!isInCart(productData.id)) {
            // Optimistic update
            cart.push(productData);
            updateCartUI();

            // Send to DB
            $.post(cigStockTable.ajax_url, {
                action: 'cig_add_to_cart_db',
                nonce: cigStockTable.nonce,
                item: productData
            }, function(res) {
                if (!res.success) {
                    // Revert on error
                    cart = cart.filter(function(item) { return item.id != productData.id; });
                    updateCartUI();
                    alert('Error saving to cart');
                }
            });
        }
    }

    function removeFromCart(id) {
        if (useCIGSelection) {
            // Delegate to CIGSelection
            window.CIGSelection.remove(id);
            return;
        }
        
        // Legacy fallback
        // Optimistic update
        cart = cart.filter(function(item) { return item.id != id; });
        updateCartUI();

        // Send to DB
        $.post(cigStockTable.ajax_url, {
            action: 'cig_remove_from_cart_db',
            nonce: cigStockTable.nonce,
            id: id
        });
    }

    // Initialize - check for CIGSelection or use legacy
    checkCIGSelection();
    initCart();

    // --- 2. STOCK TABLE LOGIC ---
    if ($('#cig-stock-table').length) {
        var currentPage = 1;
        var currentSearch = '';
        var currentSort = { column: 'title', order: 'asc' };
        var searchTimeout = null;
        var canManage = (cigStockTable && parseInt(cigStockTable.can_manage, 10) === 1);

        loadProducts(1);
        updateSortArrows();
        bindTableEvents();

        function loadProducts(page) {
            currentPage = page || 1;
            $('#cig-stock-tbody').html(
                '<tr class="loading-row"><td colspan="8" style="text-align:center;padding:30px;">' + 
                '<div class="cig-loading-spinner"><div class="spinner"></div><p>' + cigStockTable.i18n.loading + '</p></div>' + 
                '</td></tr>'
            );

            $.ajax({
                url: cigStockTable.ajax_url,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'cig_search_products_table',
                    nonce: cigStockTable.nonce,
                    page: currentPage,
                    limit: 20,
                    search: currentSearch,
                    sort: currentSort.column,
                    order: currentSort.order
                },
                success: function (res) {
                    if (res && res.success && res.data) {
                        renderProducts(res.data.products || []);
                        renderPagination(currentPage, parseInt(res.data.total_pages, 10) || 0);
                        updateResultsInfo(currentPage, 20, parseInt(res.data.total_items, 10) || 0);
                    } else {
                        showNoResults();
                    }
                },
                error: function () {
                    $('#cig-stock-tbody').html('<tr class="no-results-row"><td colspan="8" style="text-align:center;color:#dc3545;">Error loading data.</td></tr>');
                }
            });
        }

        function showNoResults() {
            $('#cig-stock-tbody').html(
                '<tr class="no-results-row"><td colspan="8" style="text-align:center;padding:30px;">' + 
                cigStockTable.i18n.no_results + 
                '</td></tr>'
            );
            $('#cig-stock-pagination').empty();
            $('#cig-stock-info').empty();
        }

        function renderProducts(products) {
            if (!products || products.length === 0) { showNoResults(); return; }
            
            var html = '';
            products.forEach(function (product) {
                var stockClass = (product.stock_num > 0) ? (product.stock_num <= 5 ? 'low-stock' : 'in-stock') : 'out-of-stock';
                if (product.stock_num === -1) stockClass = '';

                var imageHtml = product.image 
                    ? '<img src="' + product.image + '" alt="" class="cig-product-thumb" data-full="' + product.full_image + '">' 
                    : '<div class="cig-no-image">No Image</div>';

                var availClass = (product.available_num > 0) ? 'available-value' : 'out-of-stock';
                if (product.available_num === -1) availClass = '';

                var pendingPriceHtml = '';
                if (product.pending_data && product.pending_data.price) pendingPriceHtml = '<span class="cig-pending-val">(' + parseFloat(product.pending_data.price).toFixed(2) + ' <span class="dashicons dashicons-clock"></span>)</span>';
                var pendingStockHtml = '';
                if (product.pending_data && product.pending_data.stock) pendingStockHtml = '<span class="cig-pending-val">(' + parseInt(product.pending_data.stock) + ' <span class="dashicons dashicons-clock"></span>)</span>';

                var priceCell = product.price + pendingPriceHtml;
                var stockCell = '<span class="stock-value ' + stockClass + '">' + product.stock + '</span>' + pendingStockHtml;

                if (canManage) {
                    var pRaw = (product.price_num || 0).toFixed(2);
                    priceCell = '<div class="cig-cell-container"><div class="cig-view-wrap"><div>' + product.price + pendingPriceHtml + '</div><span class="dashicons dashicons-edit cig-edit-icon"></span></div><div class="cig-edit-wrap"><input type="number" class="cig-edit-input cig-edit-price" data-id="'+product.id+'" value="'+pRaw+'" step="0.01" data-original="'+pRaw+'"><div class="cig-edit-actions"><button type="button" class="cig-action-btn cig-save-btn">✓</button><button type="button" class="cig-action-btn cig-cancel-btn">✕</button></div></div></div>';
                    
                    if (product.stock_num !== -1) {
                        stockCell = '<div class="cig-cell-container"><div class="cig-view-wrap"><div><span class="stock-value ' + stockClass + '">' + product.stock + '</span>' + pendingStockHtml + '</div><span class="dashicons dashicons-edit cig-edit-icon"></span></div><div class="cig-edit-wrap"><input type="number" class="cig-edit-input cig-edit-stock" data-id="'+product.id+'" value="'+product.stock_num+'" step="1" data-original="'+product.stock_num+'"><div class="cig-edit-actions"><button type="button" class="cig-action-btn cig-save-btn">✓</button><button type="button" class="cig-action-btn cig-cancel-btn">✕</button></div></div></div>';
                    }
                }

                var inCart = isInCart(product.id);
                var btnClass = inCart ? 'cig-add-btn added' : 'cig-add-btn';
                var btnIcon = inCart ? '<span class="dashicons dashicons-yes"></span>' : '<span class="dashicons dashicons-plus"></span>';
                
                var actionBtn = '<button type="button" class="' + btnClass + '" ' +
                    'data-id="' + product.id + '" ' +
                    'data-sku="' + escapeHtml(product.sku) + '" ' +
                    'data-title="' + escapeHtml(product.title) + '" ' +
                    'data-price="' + (product.price_num || 0) + '" ' +
                    'data-image="' + (product.image || '') + '" ' +
                    'data-brand="' + (product.brand || '') + '" ' + 
                    'data-desc="' + (product.desc || '') + '">' + 
                    btnIcon + '</button>';

                var titleHtml = '<a href="' + product.product_url + '" target="_blank" style="font-weight:600;color:inherit;text-decoration:none;">' + escapeHtml(product.title) + ' <span class="dashicons dashicons-external" style="font-size:12px;color:#999;"></span></a>';
                
                // --- INSERT DIMENSIONS HERE (FIX) ---
                if (product.dimensions && typeof product.dimensions === 'string' && product.dimensions !== '') {
                    titleHtml += '<div style="font-size:11px; color:#888; margin-top:4px;">' + escapeHtml(product.dimensions) + '</div>';
                }

                html += '<tr>';
                html += '<td class="col-image">' + imageHtml + '</td>';
                html += '<td class="col-title">' + titleHtml + '</td>';
                html += '<td class="col-sku">' + escapeHtml(product.sku) + '</td>';
                html += '<td class="col-price">' + priceCell + '</td>';
                html += '<td class="col-stock">' + stockCell + '</td>';
                html += '<td class="col-reserved"><span class="reserved-value">' + product.reserved + '</span></td>';
                html += '<td class="col-available"><span class="' + availClass + '">' + product.available + '</span></td>';
                html += '<td class="col-actions" style="text-align:center;">' + actionBtn + '</td>';
                html += '</tr>';
            });
            $('#cig-stock-tbody').html(html);
        }

        function renderPagination(curr, total) {
            if (total <= 1) { $('#cig-stock-pagination').empty(); return; }
            var html = '<button class="cig-page-btn" data-page="'+(curr-1)+'" '+(curr<=1?'disabled':'')+'>« Prev</button>';
            var start = Math.max(1, curr - 2), end = Math.min(total, curr + 2);
            if(start > 1) html += '<button class="cig-page-btn" data-page="1">1</button><span class="cig-page-info">...</span>';
            for(var i=start; i<=end; i++) html += '<button class="cig-page-btn '+(i===curr?'active':'')+'" data-page="'+i+'">'+i+'</button>';
            if(end < total) html += '<span class="cig-page-info">...</span><button class="cig-page-btn" data-page="'+total+'">'+total+'</button>';
            html += '<button class="cig-page-btn" data-page="'+(curr+1)+'" '+(curr>=total?'disabled':'')+'>Next »</button>';
            $('#cig-stock-pagination').html(html);
        }

        function updateResultsInfo(p, per, tot) {
            var s = (p-1)*per+1, e = Math.min(p*per, tot);
            $('#cig-stock-info').text(tot > 0 ? 'Showing ' + s + '-' + e + ' of ' + tot + ' products' : '');
        }

        function bindTableEvents() {
            $(document).on('input', '#cig-stock-search', function() {
                var val = $(this).val().trim();
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function(){ currentSearch = val; loadProducts(1); }, 400);
            });
            $(document).on('click', '#cig-stock-clear-btn', function(){ $('#cig-stock-search').val(''); currentSearch=''; loadProducts(1); });

            $(document).on('click', '.sortable', function() {
                var col = $(this).data('sort');
                currentSort.order = (currentSort.column === col && currentSort.order === 'asc') ? 'desc' : 'asc';
                currentSort.column = col;
                updateSortArrows(); loadProducts(currentPage);
            });

            $(document).on('click', '.cig-page-btn', function() {
                if($(this).is(':disabled') || $(this).hasClass('active')) return;
                loadProducts(parseInt($(this).data('page')));
                $('html,body').animate({scrollTop: $('.cig-products-stock-table-wrapper').offset().top - 100}, 300);
            });

            $(document).on('click', '.cig-view-wrap', function() {
                $('.cig-cell-editing').removeClass('cig-cell-editing'); 
                $(this).closest('td').addClass('cig-cell-editing').find('.cig-edit-input').focus().select();
            });
            $(document).on('click', '.cig-cancel-btn', function(e) {
                e.stopPropagation();
                var $td = $(this).closest('td');
                $td.removeClass('cig-cell-editing');
                var $inp = $td.find('.cig-edit-input');
                $inp.val($inp.data('original'));
            });
            $(document).on('click', '.cig-save-btn', function(e) {
                e.stopPropagation();
                var $td = $(this).closest('td');
                var $inp = $td.find('.cig-edit-input');
                processSave($inp, $td);
            });
            $(document).on('keydown', '.cig-edit-input', function(e) {
                if(e.which===13) { e.preventDefault(); processSave($(this), $(this).closest('td')); }
                if(e.which===27) { e.preventDefault(); $(this).closest('td').find('.cig-cancel-btn').click(); }
            });

            $(document).on('click', '.cig-product-thumb', function() {
                $('#cig-lightbox-img').attr('src', $(this).data('full')); $('#cig-lightbox').fadeIn(200);
            });
            $(document).on('click', '#cig-lightbox, .cig-lightbox-close', function(e) {
                if(e.target !== $('#cig-lightbox-img')[0]) $('#cig-lightbox').fadeOut(200);
            });
        }

        function updateSortArrows() {
            $('.sortable .sort-arrow').text('▲▼'); 
            $('.sortable').removeClass('active-sort');
            var $th = $('.sortable[data-sort="'+currentSort.column+'"]');
            $th.addClass('active-sort').attr('data-order', currentSort.order);
        }

        function processSave($input, $td) {
            var val = $input.val(), old = $input.data('original');
            if(val == old) { $td.removeClass('cig-cell-editing'); return; }
            var pid = $input.data('id'), type = $input.hasClass('cig-edit-price') ? 'price' : 'stock';
            
            $input.prop('disabled',true); $td.find('.cig-action-btn').prop('disabled',true);
            var data = { action: 'cig_submit_stock_request', nonce: cigStockTable.nonce, product_id: pid };
            data[type] = val;

            $.post(cigStockTable.ajax_url, data, function(res) {
                $input.prop('disabled',false); $td.find('.cig-action-btn').prop('disabled',false);
                if(res.success) {
                    showToast(res.data.message || 'Saved', 'success');
                    $td.removeClass('cig-cell-editing');
                    loadProducts(currentPage);
                } else {
                    showToast(res.data.message || 'Error', 'error');
                }
            }, 'json').fail(function() {
                $input.prop('disabled',false); $td.find('.cig-action-btn').prop('disabled',false);
                showToast('Server error', 'error');
            });
        }
    }

    function showToast(msg, type) {
        $('.cig-toast').remove();
        $('body').append('<div class="cig-toast cig-toast-'+type+'">'+msg+'</div>');
        setTimeout(function(){ $('.cig-toast').addClass('show'); }, 10);
        setTimeout(function(){ $('.cig-toast').removeClass('show'); setTimeout(function(){ $('.cig-toast').remove(); },300); }, 3000);
    }

    function escapeHtml(text) {
        if(!text) return '';
        return String(text).replace(/[&<>"']/g, function(m) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]; });
    }
});