<?php
/**
 * AJAX Handler for Invoice Operations
 * Updated: Auto-Status Logic, General Note Saving, Date Correction & ULTRA AGGRESSIVE Cache Purge
 *
 * @package CIG
 * @since 4.9.9
 */

if (!defined('ABSPATH')) {
    exit;
}

class CIG_Ajax_Invoices {

    /** @var CIG_Invoice */
    private $invoice;

    /** @var CIG_Stock_Manager */
    private $stock;

    /** @var CIG_Validator */
    private $validator;

    /** @var CIG_Security */
    private $security;

    /** @var CIG_Cache */
    private $cache;

    /**
     * Constructor
     */
    public function __construct($invoice, $stock, $validator, $security, $cache = null) {
        $this->invoice   = $invoice;
        $this->stock     = $stock;
        $this->validator = $validator;
        $this->security  = $security;
        $this->cache     = $cache;

        // Invoice CRUD
        add_action('wp_ajax_cig_save_invoice',           [$this, 'save_invoice']);
        add_action('wp_ajax_cig_update_invoice',         [$this, 'update_invoice']);
        add_action('wp_ajax_cig_next_invoice_number',    [$this, 'next_invoice_number']);
        add_action('wp_ajax_cig_toggle_invoice_status',  [$this, 'toggle_invoice_status']);
        add_action('wp_ajax_cig_mark_as_sold',           [$this, 'mark_as_sold']);
    }

    /**
     * Helper to process payment history array
     */
    private function process_payment_history($raw_history) {
        $clean_history = [];
        if (is_array($raw_history)) {
            foreach ($raw_history as $h) {
                $clean_history[] = [
                    'date'    => sanitize_text_field($h['date'] ?? ''),
                    'amount'  => floatval($h['amount'] ?? 0),
                    'method'  => sanitize_text_field($h['method'] ?? ''),
                    'comment' => sanitize_text_field($h['comment'] ?? ''),
                    'user_id' => intval($h['user_id'] ?? get_current_user_id())
                ];
            }
        }
        return $clean_history;
    }

    /**
     * Save new invoice
     */
    public function save_invoice() {
        $this->process_invoice_save(false);
    }

    /**
     * Update existing invoice
     */
    public function update_invoice() {
        $this->process_invoice_save(true);
    }

    /**
     * Main logic for saving/updating invoice
     */
    private function process_invoice_save($update) {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'manage_woocommerce');
        
        $raw_payload = wp_unslash($_POST['payload'] ?? '');
        $d = json_decode($raw_payload, true);

        if (!is_array($d)) {
            wp_send_json_error(['message' => 'Invalid data format']);
        }
        
        // Security Check for Completed Invoices
        if ($update) {
            $id = intval($d['invoice_id'] ?? 0);
            $current_lifecycle = get_post_meta($id, '_cig_lifecycle_status', true);
            if ($current_lifecycle === 'completed' && !current_user_can('administrator')) {
                wp_send_json_error(['message' => 'დასრულებული ინვოისის რედაქტირება აკრძალულია.'], 403);
            }
        }

        // Validation
        $buyer = $d['buyer'] ?? [];
        if (empty($buyer['name']) || empty($buyer['tax_id']) || empty($buyer['phone'])) {
            wp_send_json_error(['message' => 'შეავსეთ მყიდველის სახელი, ს/კ და ტელეფონი.'], 400);
        }

        $num = sanitize_text_field($d['invoice_number'] ?? '');
        
        // NEW: General Note
        $general_note = sanitize_textarea_field($d['general_note'] ?? '');
        
        // 1. Determine Status based on Payment
        $hist = $this->process_payment_history($d['payment']['history'] ?? []);
        $paid = 0; 
        foreach ($hist as $h) {
            $paid += floatval($h['amount'] ?? 0);
        }

        // AUTO-STATUS LOGIC:
        $st = ($paid > 0) ? 'standard' : 'fictive';

        // 2. Process Items & Enforce Item Statuses
        $items = array_filter((array)($d['items'] ?? []), function($r) { 
            return !empty($r['name']); 
        });

        if (empty($items)) {
            wp_send_json_error(['message' => 'დაამატეთ პროდუქტები'], 400);
        }

        $processed_items = [];
        foreach ($items as $item) {
            $current_item_status = $item['status'] ?? 'none';
            
            if ($st === 'fictive') {
                $item['status'] = 'none'; 
                $item['reservation_days'] = 0;
            } else {
                if ($current_item_status === 'none' || empty($current_item_status)) {
                    $item['status'] = 'reserved';
                }
            }
            $processed_items[] = $item;
        }
        $items = $processed_items; 
        
        $pid = 0;

        if ($update) {
            $id = intval($d['invoice_id']);
            if ($st === 'standard') { 
                $err = $this->stock->validate_stock($items, $id); 
                if ($err) {
                    wp_send_json_error(['message' => 'Stock error', 'errors' => $err], 400);
                }
            }
            
            $new_num = CIG_Invoice::ensure_unique_number($num, $id);
            
            wp_update_post([
                'ID'            => $id, 
                'post_title'    => 'Invoice #' . $new_num, 
                'post_modified' => current_time('mysql')
            ]);
            $pid = $id;
        } else {
            if ($st === 'standard') { 
                $err = $this->stock->validate_stock($items, 0); 
                if ($err) {
                    wp_send_json_error(['message' => 'Stock error', 'errors' => $err], 400); 
                }
            }
            $new_num = CIG_Invoice::ensure_unique_number($num);
            $pid = wp_insert_post([
                'post_type'   => 'invoice',
                'post_status' => 'publish',
                'post_title'  => 'Invoice #' . $new_num, 
                'post_author' => get_current_user_id()
            ]);
        }
        
        // Capture OLD items BEFORE saving new metadata
        $old_items = [];
        if ($update) {
            $old_items = get_post_meta($pid, '_cig_items', true);
            if (!is_array($old_items)) {
                $old_items = [];
            }
        }

        update_post_meta($pid, '_wp_page_template', 'elementor_canvas');
        update_post_meta($pid, '_cig_invoice_status', $st);
        
        // NEW: Save General Note
        update_post_meta($pid, '_cig_general_note', $general_note);
        
        // Update Customer
        if (function_exists('CIG') && isset(CIG()->customers)) { 
            $cid = CIG()->customers->sync_customer($buyer); 
            if ($cid) {
                update_post_meta($pid, '_cig_customer_id', $cid); 
            }
        }
        
        // Prepare Payment Data for saving
        $payment_data = (array)($d['payment'] ?? []);
        $payment_data['history'] = $hist;

        // Save NEW items and metadata
        CIG_Invoice::save_meta($pid, $new_num, (array)($d['buyer'] ?? []), $items, $payment_data);
        
        // Update Stock
        $items_for_stock = ($st === 'fictive') ? [] : $items;
        $this->stock->update_invoice_reservations($pid, $old_items, $items_for_stock); 
        
        // Clear caches (Plugin Internal)
        if ($this->cache) {
            $this->cache->delete('statistics_summary');
            $author_id = get_post_field('post_author', $pid);
            $this->cache->delete('user_invoices_' . $author_id);
        }

        // --- DATE UPDATE LOGIC ---
        if ($st === 'standard') {
            $payment_date = '';
            if (!empty($hist) && is_array($hist)) {
                $first_payment = reset($hist);
                $payment_date = $first_payment['date'] ?? '';
            }
            $this->force_update_invoice_date($pid, $payment_date);
        }

        // --- FULL CACHE PURGE (WP + LiteSpeed) ---
        $this->purge_cache($pid);

        wp_send_json_success([
            'post_id'        => $pid, 
            'view_url'       => get_permalink($pid), 
            'invoice_number' => $new_num,
            'status'         => $st
        ]);
    }

    /**
     * Mark reserved items as sold (Finalize Invoice)
     */
    public function mark_as_sold() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'manage_woocommerce');
        
        $id = intval($_POST['invoice_id']);
        if (!$id || get_post_type($id) !== 'invoice') {
            wp_send_json_error(['message' => 'Invalid invoice']);
        }

        // Get Status
        $invoice_status = get_post_meta($id, '_cig_invoice_status', true);
        $is_fictive = ($invoice_status === 'fictive');

        // 1. Get Current Items (DB state)
        $items = get_post_meta($id, '_cig_items', true);
        if (!is_array($items)) $items = [];

        $old_items = $items; 
        $updated_items = [];
        $has_change = false;

        // 2. Prepare Updated Items (Memory state)
        foreach ($items as $item) {
            $st = $item['status'] ?? 'none';
            if ($st === 'reserved') {
                $item['status'] = 'sold';
                $item['reservation_days'] = 0;
                $has_change = true;
            }
            $updated_items[] = $item;
        }

        if ($has_change) {
            // 3. Save updated items to DB
            update_post_meta($id, '_cig_items', $updated_items);
            
            // 4. Sync with Stock Manager
            if (!$is_fictive) {
                $this->stock->update_invoice_reservations($id, $old_items, $updated_items);
            } else {
                 $this->stock->update_invoice_reservations($id, [], $updated_items);
            }
            
            // 5. Mark invoice as completed & standard
            update_post_meta($id, '_cig_lifecycle_status', 'completed');
            update_post_meta($id, '_cig_invoice_status', 'standard');

            // Clear cache
            if ($this->cache) {
                $this->cache->delete('statistics_summary');
            }

            // --- DATE UPDATE LOGIC ---
            $history = get_post_meta($id, '_cig_payment_history', true);
            $payment_date = '';
            if (is_array($history) && !empty($history)) {
                $first_payment = reset($history);
                $payment_date = $first_payment['date'] ?? '';
            }
            $this->force_update_invoice_date($id, $payment_date);

            // --- FULL CACHE PURGE (WP + LiteSpeed) ---
            $this->purge_cache($id);

            wp_send_json_success(['message' => 'Invoice marked as sold successfully.']);
        } else {
            wp_send_json_error(['message' => 'No reserved items found to mark as sold.']);
        }
    }

    /**
     * Toggle invoice status (Standard <-> Fictive)
     */
    public function toggle_invoice_status() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'manage_woocommerce');
        
        $id = intval($_POST['invoice_id']); 
        $nst = sanitize_text_field($_POST['status']);
        
        $paid = floatval(get_post_meta($id, '_cig_payment_paid_amount', true));
        if ($nst === 'fictive' && $paid > 0.001) {
            wp_send_json_error(['message' => 'გადახდილი ვერ იქნება ფიქტიური']);
        }

        $items = get_post_meta($id, '_cig_items', true) ?: [];
        if ($nst === 'standard') {
            $err = $this->stock->validate_stock($items, $id);
            if ($err) {
                wp_send_json_error(['message' => 'Stock error', 'errors' => $err]);
            }
        }

        $ost = get_post_meta($id, '_cig_invoice_status', true) ?: 'standard';
        
        // 1. Update status in DB
        update_post_meta($id, '_cig_invoice_status', $nst);
        
        // 2. Clear Cache
        if ($this->cache) {
            $this->cache->delete('statistics_summary');
            $author_id = get_post_field('post_author', $id);
            $this->cache->delete('user_invoices_' . $author_id);
        }

        // 3. Update Reservations / Stock
        $items_old = ($ost === 'fictive') ? [] : $items;
        $items_new = ($nst === 'fictive') ? [] : $items;

        $this->stock->update_invoice_reservations($id, $items_old, $items_new);
        
        // 4. Force post update timestamp
        wp_update_post([
            'ID'            => $id, 
            'post_modified' => current_time('mysql'),
            'post_modified_gmt' => current_time('mysql', 1)
        ]);
        
        // --- FULL CACHE PURGE (WP + LiteSpeed) ---
        $this->purge_cache($id);

        wp_send_json_success();
    }

    /**
     * Get next invoice number
     */
    public function next_invoice_number() { 
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'manage_woocommerce');
        wp_send_json_success(['next' => CIG_Invoice::get_next_number()]); 
    }

    /**
     * FORCE UPDATE DATE: Direct SQL Update using Site Time
     */
    private function force_update_invoice_date($post_id, $payment_date_ymd = '') {
        global $wpdb;

        // საიტის დრო
        $current_time_his = current_time('H:i:s'); 
        
        if (!empty($payment_date_ymd)) {
            $final_date = $payment_date_ymd . ' ' . $current_time_his;
        } else {
            $final_date = current_time('mysql');
        }

        $final_date_gmt = get_gmt_from_date($final_date);

        $wpdb->update(
            $wpdb->posts,
            [
                'post_date'         => $final_date,
                'post_date_gmt'     => $final_date_gmt,
                'post_modified'     => $final_date,
                'post_modified_gmt' => $final_date_gmt
            ],
            ['ID' => $post_id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );
    }

    /**
     * ROBUST PURGE: Purge ALL caches for a specific post
     */
    private function purge_cache($post_id) {
        // 1. WordPress Object Cache (Internal WP Cache)
        clean_post_cache($post_id);

        // 2. LiteSpeed Cache (API Method - Most reliable for LSCWP)
        if (class_exists('LiteSpeed_Cache_API')) {
            // Purge by Post ID
            LiteSpeed_Cache_API::purge_post($post_id);
            
            // Purge by URL explicitly (Just in case)
            $url = get_permalink($post_id);
            if ($url) {
                LiteSpeed_Cache_API::purge_url($url);
            }
        } 
        
        // 3. HTTP Header Method (Immediate Browser/Server response purge)
        // This tells LiteSpeed Server to purge the tag associated with this Post ID immediately
        if (defined('LSCWP_V')) {
            $tag = 'PO.' . $post_id;
            @header('X-LiteSpeed-Purge: ' . $tag);
        }
    }
}