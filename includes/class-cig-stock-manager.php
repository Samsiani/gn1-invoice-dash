<?php
/**
 * Stock reservation and deduction management
 * Updated: Protection against 'none' status (Fictive items)
 *
 * @package CIG
 * @since 4.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CIG Stock Manager Class
 */
class CIG_Stock_Manager {

    /** @var CIG_Logger */
    private $logger;

    /** @var CIG_Cache */
    private $cache;

    /** @var CIG_Validator */
    private $validator;

    /**
     * Constructor
     */
    public function __construct($logger = null, $cache = null, $validator = null) {
        $this->logger    = $logger    ?: (function_exists('CIG') ? CIG()->logger    : null);
        $this->cache     = $cache     ?: (function_exists('CIG') ? CIG()->cache     : null);
        $this->validator = $validator ?: (function_exists('CIG') ? CIG()->validator : null);

        // Stock filters (Virtual deduction for reserved items)
        add_filter('woocommerce_product_is_in_stock', [$this, 'filter_stock_status'], 10, 2);
        add_filter('woocommerce_product_get_stock_quantity', [$this, 'filter_stock_quantity'], 10, 2);
        add_filter('woocommerce_product_variation_get_stock_quantity', [$this, 'filter_stock_quantity'], 10, 2);

        // Admin display
        add_action('woocommerce_product_options_stock_status', [$this, 'display_reserved_admin']);
        add_action('woocommerce_variation_options_inventory', [$this, 'display_reserved_variation'], 10, 3);

        // Cron for expiring reservations
        add_action('cig_check_expired_reservations', [$this, 'check_expired_reservations']);
    }

    /**
     * Get total reserved stock for product (ignores expired)
     */
    public function get_reserved($product_id) {
        $product_id = (int) $product_id;
        if ($product_id <= 0) return 0;

        $reserved_meta = get_post_meta($product_id, '_cig_reserved_stock', true);
        if (!is_array($reserved_meta)) return 0;

        $total = 0;
        $now = current_time('mysql');

        foreach ($reserved_meta as $invoice_id => $data) {
            if (!empty($data['expires']) && $data['expires'] < $now) continue; // expired
            $total += floatval($data['qty'] ?? 0);
        }

        return $total;
    }

    /**
     * Get available stock for product
     */
    public function get_available($product_id, $exclude_invoice_id = 0) {
        $product = wc_get_product($product_id);
        if (!$product) return null;

        $stock_qty = $product->get_stock_quantity();
        if ($stock_qty === null || $stock_qty === '') return null; // Not managed

        $reserved_total = $this->get_reserved($product_id);

        // Exclude current invoice's reservation if editing
        if ($exclude_invoice_id) {
            $reserved_meta = get_post_meta($product_id, '_cig_reserved_stock', true);
            if (is_array($reserved_meta) && isset($reserved_meta[$exclude_invoice_id])) {
                $current_reserved = floatval($reserved_meta[$exclude_invoice_id]['qty'] ?? 0);
                $reserved_total  -= $current_reserved;
            }
        }

        return max(0, $stock_qty - $reserved_total);
    }

    /**
     * Update reservation map entry for product/invoice (Meta only)
     */
    public function update_reservation_meta($product_id, $invoice_id, $quantity, $reservation_days = 0, $invoice_date = '') {
        $product_id = (int) $product_id;
        $invoice_id = (int) $invoice_id;
        $quantity   = floatval($quantity);

        $reserved_meta = get_post_meta($product_id, '_cig_reserved_stock', true);
        if (!is_array($reserved_meta)) $reserved_meta = [];

        if ($quantity > 0) {
            $expiry_date = '';
            if ($reservation_days > 0 && !empty($invoice_date)) {
                $expiry_date = date('Y-m-d H:i:s', strtotime($invoice_date . ' +' . intval($reservation_days) . ' days'));
            }

            $reserved_meta[$invoice_id] = [
                'qty'          => $quantity,
                'expires'      => $expiry_date,
                'invoice_date' => $invoice_date
            ];
        } else {
            unset($reserved_meta[$invoice_id]);
        }

        update_post_meta($product_id, '_cig_reserved_stock', $reserved_meta);
    }

    /**
     * Main Sync Function: Updates Reservations AND Actual Stock
     */
    public function update_invoice_reservations($invoice_id, $old_items, $new_items) {
        $invoice_id  = (int) $invoice_id;
        $invoice_date = get_post_field('post_date', $invoice_id) ?: current_time('mysql');

        // 1. Map Old State
        $old_reserved_map = [];
        $old_sold_map     = [];

        foreach ((array) $old_items as $item) {
            $pid    = intval($item['product_id'] ?? 0);
            $status = strtolower($item['status'] ?? ''); 
            $qty    = floatval($item['qty'] ?? 0);

            if (!$pid) continue;
            if ($status === 'none') continue; // PROTECTION: Ignore 'none' status (Fictive)

            if ($status === 'reserved') {
                $old_reserved_map[$pid] = ($old_reserved_map[$pid] ?? 0) + $qty;
            } elseif ($status === 'sold') {
                $old_sold_map[$pid] = ($old_sold_map[$pid] ?? 0) + $qty;
            }
        }

        // 2. Map New State
        $new_reserved_map = [];
        $new_sold_map     = [];
        $new_days_map     = [];

        foreach ((array) $new_items as $item) {
            $pid    = intval($item['product_id'] ?? 0);
            $status = strtolower($item['status'] ?? ''); 
            $qty    = floatval($item['qty'] ?? 0);
            $days   = intval($item['reservation_days'] ?? 0);

            if (!$pid) continue;
            if ($status === 'none') continue; // PROTECTION: Ignore 'none' status (Fictive)

            if ($status === 'reserved') {
                $new_reserved_map[$pid] = ($new_reserved_map[$pid] ?? 0) + $qty;
                $new_days_map[$pid] = $days;
            } elseif ($status === 'sold') {
                $new_sold_map[$pid] = ($new_sold_map[$pid] ?? 0) + $qty;
            }
        }

        // 3. Process Reservations (Meta Updates)
        $all_reserved_products = array_unique(array_merge(array_keys($old_reserved_map), array_keys($new_reserved_map)));
        foreach ($all_reserved_products as $pid) {
            $old_qty = $old_reserved_map[$pid] ?? 0;
            $new_qty = $new_reserved_map[$pid] ?? 0;
            $days    = $new_days_map[$pid] ?? 0;

            if ($old_qty != $new_qty || $new_qty > 0) {
                $this->update_reservation_meta($pid, $invoice_id, $new_qty, $days, $invoice_date);
            }
        }

        // 4. Process Sold Items (Real Stock Deduction/Refund)
        $all_sold_products = array_unique(array_merge(array_keys($old_sold_map), array_keys($new_sold_map)));
        foreach ($all_sold_products as $pid) {
            $old_qty = $old_sold_map[$pid] ?? 0;
            $new_qty = $new_sold_map[$pid] ?? 0;
            $diff    = $new_qty - $old_qty; // positive = decrease stock, negative = increase stock

            if ($diff !== 0) {
                $product = wc_get_product($pid);
                if ($product && $product->managing_stock()) {
                    $current_stock = $product->get_stock_quantity();
                    $new_stock     = $current_stock - $diff;
                    
                    $product->set_stock_quantity($new_stock);
                    $product->save();
                    
                    if ($this->logger) {
                        $this->logger->info("Stock adjusted for Product #{$pid}", [
                            'invoice' => $invoice_id,
                            'old_sold' => $old_qty,
                            'new_sold' => $new_qty,
                            'diff' => $diff
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Validate stock availability
     */
    public function validate_stock($items, $exclude_invoice_id = 0) {
        $errors = [];

        foreach ((array) $items as $item) {
            // Skip items with 'none' status (fictive or ignored)
            if (($item['status'] ?? '') === 'none') continue;

            $product_id = intval($item['product_id'] ?? 0);
            $qty        = floatval($item['qty'] ?? 0);

            if (!$product_id || $qty <= 0) continue;

            $product = wc_get_product($product_id);
            if (!$product) continue;

            $stock_qty = $product->get_stock_quantity();
            if ($stock_qty === null || $stock_qty === '') continue; // Not managed

            $available = $this->get_available($product_id, $exclude_invoice_id);

            // Handle case where user is increasing Sold quantity on existing invoice
            if ($exclude_invoice_id) {
                $old_items = get_post_meta($exclude_invoice_id, '_cig_items', true);
                if (is_array($old_items)) {
                    foreach ($old_items as $old_item) {
                        if (intval($old_item['product_id'] ?? 0) === $product_id && ($old_item['status'] ?? 'sold') === 'sold') {
                            $available += floatval($old_item['qty'] ?? 0);
                        }
                    }
                }
            }

            if ($qty > $available) {
                $errors[] = sprintf(
                    __('Product "%s" (SKU: %s): Requested %s, but only %s available', 'cig'),
                    sanitize_text_field($item['name'] ?? ''),
                    sanitize_text_field($item['sku'] ?? ''),
                    $qty,
                    $available
                );
            }
        }

        return $errors;
    }

    public function filter_stock_quantity($stock_qty, $product) { return $stock_qty; }

    public function filter_stock_status($in_stock, $product) {
        $stock_qty = $product->get_stock_quantity();
        if ($stock_qty !== null && $stock_qty !== '') {
            $product_id = $product->get_id();
            $reserved   = $this->get_reserved($product_id);
            $available  = $stock_qty - $reserved;
            if ($available <= 0) return false;
        }
        return $in_stock;
    }

    public function display_reserved_admin() {
        global $post; if (!$post) return;
        $product_id = (int) $post->ID;
        $reserved   = $this->get_reserved($product_id);
        if ($reserved <= 0) return;

        echo '<div class="options_group"><p class="form-field"><label>Reserved Stock</label><span style="display:block;padding:5px 0;color:#d63638;font-weight:bold;">' . sprintf('%s units reserved', number_format($reserved, 0)) . '</span></p></div>';
    }

    public function display_reserved_variation($loop, $variation_data, $variation) {
        $product_id = (int) $variation->ID;
        $reserved   = $this->get_reserved($product_id);
        if ($reserved <= 0) return;
        echo '<div style="padding:10px;background:#fff3cd;border:1px solid #ffc107;margin:10px 0;"><strong>Reserved:</strong> ' . sprintf('%s units', number_format($reserved, 0)) . '</div>';
    }

    public function check_expired_reservations() {
        global $wpdb;
        $product_ids = $wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_cig_reserved_stock'");
        if (empty($product_ids)) return;

        $now = current_time('mysql');
        foreach ($product_ids as $product_id) {
            $reserved_meta = get_post_meta($product_id, '_cig_reserved_stock', true);
            if (!is_array($reserved_meta)) continue;
            $changed = false;
            foreach ($reserved_meta as $invoice_id => $data) {
                if (!empty($data['expires']) && $data['expires'] < $now) {
                    $invoice_items = get_post_meta($invoice_id, '_cig_items', true);
                    if (is_array($invoice_items)) {
                        foreach ($invoice_items as &$item) {
                            if ((int) ($item['product_id'] ?? 0) === (int)$product_id && ($item['status'] ?? '') === 'reserved') {
                                $item['status'] = 'canceled';
                            }
                        }
                        update_post_meta($invoice_id, '_cig_items', $invoice_items);
                    }
                    unset($reserved_meta[$invoice_id]);
                    $changed = true;
                }
            }
            if ($changed) update_post_meta($product_id, '_cig_reserved_stock', $reserved_meta);
        }
    }
}