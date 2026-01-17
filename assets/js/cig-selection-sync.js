/**
 * CIG Selection Sync Manager
 * 
 * Provides a centralized, synchronized product selection mechanism
 * that works across Archive/Shop Pages, Single Product Pages, and Stock Overview.
 * 
 * Uses localStorage for immediate sync + AJAX for persistent storage in User Meta.
 * 
 * @package CIG
 * @since 5.0.0
 */
(function($, window) {
    'use strict';

    // Safety check
    if (typeof window.CIGSelection !== 'undefined') {
        return; // Already initialized
    }

    /**
     * CIGSelection - Global Selection Manager
     */
    var CIGSelection = {
        
        // Internal state
        _items: [],
        _initialized: false,
        _syncTimeout: null,
        _localStorageKey: 'cig_selection',

        /**
         * Initialize the selection manager
         */
        init: function() {
            if (this._initialized) return;
            
            // Load initial state from localStorage for instant availability
            this._loadFromLocalStorage();
            
            // If server provided initial cart data, merge/sync it
            this._syncFromServer();
            
            // Set up event listeners
            this._bindEvents();
            
            // Update all UI elements
            this._updateAllUI();
            
            this._initialized = true;
            
            // Dispatch ready event
            $(document).trigger('cigSelectionReady', [this._items]);
        },

        /**
         * Add a product to the selection
         * @param {Object} productData - Product data object
         */
        add: function(productData) {
            if (!productData || !productData.id) return false;
            
            var id = parseInt(productData.id, 10);
            if (this.has(id)) {
                // Update quantity if exists
                for (var i = 0; i < this._items.length; i++) {
                    if (parseInt(this._items[i].id, 10) === id) {
                        this._items[i].qty = (parseInt(this._items[i].qty, 10) || 1) + 1;
                        break;
                    }
                }
            } else {
                // Add new item
                this._items.push({
                    id: id,
                    sku: productData.sku || '',
                    name: productData.name || productData.title || '',
                    price: parseFloat(productData.price) || 0,
                    image: productData.image || '',
                    brand: productData.brand || '',
                    desc: productData.desc || '',
                    qty: parseInt(productData.qty, 10) || 1
                });
            }
            
            this._persist();
            this._dispatchUpdate();
            return true;
        },

        /**
         * Remove a product from the selection
         * @param {number} productId - Product ID to remove
         */
        remove: function(productId) {
            var id = parseInt(productId, 10);
            var originalLength = this._items.length;
            
            this._items = this._items.filter(function(item) {
                return parseInt(item.id, 10) !== id;
            });
            
            if (this._items.length !== originalLength) {
                this._persist();
                this._dispatchUpdate();
                return true;
            }
            return false;
        },

        /**
         * Toggle a product in the selection (add if not present, remove if present)
         * @param {Object} productData - Product data object
         */
        toggle: function(productData) {
            if (!productData || !productData.id) return false;
            
            var id = parseInt(productData.id, 10);
            if (this.has(id)) {
                return this.remove(id) ? 'removed' : false;
            } else {
                return this.add(productData) ? 'added' : false;
            }
        },

        /**
         * Check if a product is in the selection
         * @param {number} productId - Product ID to check
         */
        has: function(productId) {
            var id = parseInt(productId, 10);
            return this._items.some(function(item) {
                return parseInt(item.id, 10) === id;
            });
        },

        /**
         * Get all selected items
         */
        get: function() {
            return this._items.slice(); // Return a copy
        },

        /**
         * Get the count of selected items
         */
        count: function() {
            return this._items.length;
        },

        /**
         * Clear all selections
         * @param {boolean} syncToServer - Whether to sync the clear to server (default: true)
         */
        clear: function(syncToServer) {
            if (typeof syncToServer === 'undefined') syncToServer = true;
            
            this._items = [];
            
            // Clear localStorage
            try {
                localStorage.removeItem(this._localStorageKey);
            } catch (e) {
                // localStorage not available
            }
            
            if (syncToServer) {
                this._syncClearToServer();
            }
            
            this._dispatchUpdate();
            return true;
        },

        /**
         * Update quantity for a product
         * @param {number} productId - Product ID
         * @param {number} qty - New quantity
         */
        updateQty: function(productId, qty) {
            var id = parseInt(productId, 10);
            qty = parseInt(qty, 10) || 1;
            
            for (var i = 0; i < this._items.length; i++) {
                if (parseInt(this._items[i].id, 10) === id) {
                    this._items[i].qty = qty;
                    this._persist();
                    this._dispatchUpdate();
                    return true;
                }
            }
            return false;
        },

        // --- Private Methods ---

        /**
         * Load selection from localStorage
         */
        _loadFromLocalStorage: function() {
            try {
                var stored = localStorage.getItem(this._localStorageKey);
                if (stored) {
                    var parsed = JSON.parse(stored);
                    if (Array.isArray(parsed)) {
                        this._items = parsed;
                    }
                }
            } catch (e) {
                // localStorage not available or corrupted
                this._items = [];
            }
        },

        /**
         * Sync from server data (if available)
         */
        _syncFromServer: function() {
            var serverCart = null;
            
            // Check various global objects for initial cart data
            if (typeof cigStockTable !== 'undefined' && cigStockTable.initialCart) {
                serverCart = cigStockTable.initialCart;
            } else if (typeof cigAjax !== 'undefined' && cigAjax.initialCart) {
                serverCart = cigAjax.initialCart;
            }
            
            if (serverCart && Array.isArray(serverCart) && serverCart.length > 0) {
                // Server data takes precedence - merge with local
                var serverIds = {};
                serverCart.forEach(function(item) {
                    serverIds[parseInt(item.id, 10)] = true;
                });
                
                // Keep local items not in server data (for immediate adds before sync)
                var localOnly = this._items.filter(function(item) {
                    return !serverIds[parseInt(item.id, 10)];
                });
                
                // Merge: server items + local-only items
                this._items = serverCart.concat(localOnly);
                
                // Save merged state
                this._saveToLocalStorage();
            }
        },

        /**
         * Persist selection to localStorage and schedule server sync
         */
        _persist: function() {
            this._saveToLocalStorage();
            this._scheduleServerSync();
        },

        /**
         * Save to localStorage
         */
        _saveToLocalStorage: function() {
            try {
                localStorage.setItem(this._localStorageKey, JSON.stringify(this._items));
            } catch (e) {
                // localStorage not available
            }
        },

        /**
         * Schedule a server sync (debounced)
         */
        _scheduleServerSync: function() {
            var self = this;
            
            if (this._syncTimeout) {
                clearTimeout(this._syncTimeout);
            }
            
            // Debounce server sync by 500ms
            this._syncTimeout = setTimeout(function() {
                self._syncToServer();
            }, 500);
        },

        /**
         * Sync selection to server via AJAX
         */
        _syncToServer: function() {
            var self = this;
            var ajaxUrl = this._getAjaxUrl();
            var nonce = this._getNonce();
            
            if (!ajaxUrl || !nonce) return;
            
            // Sync entire selection to server
            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                data: {
                    action: 'cig_sync_selection',
                    nonce: nonce,
                    selection: JSON.stringify(this._items)
                },
                success: function(res) {
                    // Server sync successful
                    if (res && res.success && res.data && res.data.selection) {
                        // Update local state if server made changes
                        self._items = res.data.selection;
                        self._saveToLocalStorage();
                        self._dispatchUpdate();
                    }
                },
                error: function() {
                    // Silent fail - localStorage still has the data
                }
            });
        },

        /**
         * Send clear command to server
         */
        _syncClearToServer: function() {
            var ajaxUrl = this._getAjaxUrl();
            var nonce = this._getNonce();
            
            if (!ajaxUrl || !nonce) return;
            
            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                data: {
                    action: 'cig_clear_cart_db',
                    nonce: nonce
                }
            });
        },

        /**
         * Dispatch update event
         */
        _dispatchUpdate: function() {
            var eventData = {
                items: this._items.slice(),
                count: this._items.length
            };
            
            // jQuery event
            $(document).trigger('cigSelectionUpdated', [eventData]);
            
            // Native event for broader compatibility
            if (typeof CustomEvent === 'function') {
                var event = new CustomEvent('cigSelectionUpdated', { detail: eventData });
                document.dispatchEvent(event);
            }
            
            // Update all UI elements
            this._updateAllUI();
        },

        /**
         * Bind global event listeners
         */
        _bindEvents: function() {
            var self = this;
            
            // Listen for add/remove clicks on .cig-add-btn elements
            $(document).on('click', '.cig-add-btn', function(e) {
                e.preventDefault();
                var $btn = $(this);
                
                // Skip disabled buttons
                if ($btn.is(':disabled') || $btn.hasClass('disabled')) return;
                
                var id = $btn.data('id');
                if (!id) return;
                
                var productData = {
                    id: id,
                    sku: $btn.data('sku') || '',
                    name: $btn.data('title') || $btn.data('name') || '',
                    price: $btn.data('price') || 0,
                    image: $btn.data('image') || '',
                    brand: $btn.data('brand') || '',
                    desc: $btn.data('desc') || '',
                    qty: 1
                };
                
                var result = self.toggle(productData);
                
                // Also send individual add/remove for legacy compatibility
                if (result === 'added') {
                    self._sendAddToServer(productData);
                } else if (result === 'removed') {
                    self._sendRemoveToServer(id);
                }
            });
            
            // Listen for storage events (cross-tab sync)
            $(window).on('storage', function(e) {
                if (e.originalEvent.key === self._localStorageKey) {
                    self._loadFromLocalStorage();
                    self._dispatchUpdate();
                }
            });
        },

        /**
         * Send add to server (legacy compatibility)
         */
        _sendAddToServer: function(productData) {
            var ajaxUrl = this._getAjaxUrl();
            var nonce = this._getNonce();
            
            if (!ajaxUrl || !nonce) return;
            
            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                data: {
                    action: 'cig_add_to_cart_db',
                    nonce: nonce,
                    item: productData
                }
            });
        },

        /**
         * Send remove to server (legacy compatibility)
         */
        _sendRemoveToServer: function(productId) {
            var ajaxUrl = this._getAjaxUrl();
            var nonce = this._getNonce();
            
            if (!ajaxUrl || !nonce) return;
            
            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                data: {
                    action: 'cig_remove_from_cart_db',
                    nonce: nonce,
                    id: productId
                }
            });
        },

        /**
         * Update all UI elements to reflect current selection state
         */
        _updateAllUI: function() {
            var self = this;
            var count = this._items.length;
            
            // Update all cart count displays
            $('#cig-cart-count, .cig-selection-count').text(count);
            
            // Show/hide floating cart bar
            var $bar = $('#cig-stock-cart-bar');
            if (count > 0) {
                $bar.fadeIn(200).css('display', 'flex');
            } else {
                $bar.fadeOut(200);
            }
            
            // Update all add buttons
            $('.cig-add-btn').each(function() {
                var $btn = $(this);
                var id = $btn.data('id');
                
                if (!id) return;
                
                if (self.has(id)) {
                    $btn.addClass('added').html('<span class="dashicons dashicons-yes"></span>');
                } else {
                    $btn.removeClass('added').html('<span class="dashicons dashicons-plus"></span>');
                }
            });
        },

        /**
         * Get AJAX URL
         */
        _getAjaxUrl: function() {
            if (typeof cigStockTable !== 'undefined' && cigStockTable.ajax_url) {
                return cigStockTable.ajax_url;
            }
            if (typeof cigAjax !== 'undefined' && cigAjax.ajax_url) {
                return cigAjax.ajax_url;
            }
            if (typeof ajaxurl !== 'undefined') {
                return ajaxurl;
            }
            return null;
        },

        /**
         * Get nonce
         */
        _getNonce: function() {
            if (typeof cigStockTable !== 'undefined' && cigStockTable.nonce) {
                return cigStockTable.nonce;
            }
            if (typeof cigAjax !== 'undefined' && cigAjax.nonce) {
                return cigAjax.nonce;
            }
            return null;
        }
    };

    // Expose globally
    window.CIGSelection = CIGSelection;

    // Auto-initialize when DOM is ready
    $(document).ready(function() {
        CIGSelection.init();
    });

})(jQuery, window);
