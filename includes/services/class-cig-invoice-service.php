<?php
/**
 * Invoice Service Class
 * Handles invoice CRUD operations with dual storage (custom tables + postmeta)
 *
 * @package CIG
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CIG_Invoice_Service {

    /**
     * Update invoice with date synchronization logic
     *
     * @param int   $invoice_id       Invoice ID
     * @param array $data             Invoice data
     * @param array $payment_history  Payment history array
     * @return bool|WP_Error
     */
    public function update_invoice($invoice_id, $data, $payment_history = []) {
        global $wpdb;

        $invoice_status = $data['status'] ?? 'standard';
        $existing = $this->get_invoice($invoice_id);
        
        if (!$existing) {
            return new WP_Error('invalid_invoice', 'Invoice not found');
        }

        $created_at = $existing->created_at ?? current_time('mysql');
        $activation_date = $existing->activation_date ?? '';
        $update_post_date = false;

        // --- LOGIC: Sync to LATEST payment date ---
        if ($invoice_status === 'standard') {
            $latest_payment_date = null;
            
            // 1. Find latest payment date from history
            if (!empty($payment_history)) {
                foreach ($payment_history as $ph) {
                    $p_date = $ph['date'] ?? '';
                    if ($p_date && (!$latest_payment_date || $p_date > $latest_payment_date)) {
                        $latest_payment_date = $p_date;
                    }
                }
            }

            // 2. Update invoice date
            if ($latest_payment_date) {
                $time_part = current_time('H:i:s');
                $ts = strtotime($latest_payment_date);
                if ($ts !== false) {
                    $new_datetime = date('Y-m-d', $ts) . ' ' . $time_part;
                    $created_at = $new_datetime;
                    $activation_date = $new_datetime;
                    $update_post_date = true;
                }
            } 
            // Fallback
            elseif (empty($existing->activation_date)) {
                $now = current_time('mysql');
                $created_at = $now;
                $activation_date = $now;
                $update_post_date = true;
            }
        }

        // Update the invoice in custom table if exists
        $table_invoices = $wpdb->prefix . 'cig_invoices';
        if ($this->table_exists($table_invoices)) {
            $wpdb->update(
                $table_invoices,
                [
                    'created_at' => $created_at,
                    'activation_date' => $activation_date,
                    'type' => $invoice_status,
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $invoice_id],
                ['%s', '%s', '%s', '%s'],
                ['%d']
            );
        }

        // Update WordPress post date if needed
        if ($update_post_date) {
            $gmt_date = get_gmt_from_date($created_at);
            $wpdb->update(
                $wpdb->posts,
                [
                    'post_date' => $created_at,
                    'post_date_gmt' => $gmt_date,
                    'post_modified' => $created_at,
                    'post_modified_gmt' => $gmt_date
                ],
                ['ID' => $invoice_id],
                ['%s', '%s', '%s', '%s'],
                ['%d']
            );
            
            clean_post_cache($invoice_id);
        }

        return true;
    }

    /**
     * Create a new invoice
     *
     * @param array $data Invoice data
     * @return int|WP_Error Invoice ID or error
     */
    public function create_invoice($data) {
        global $wpdb;

        $invoice_number = $data['invoice_number'] ?? '';
        $type = $data['status'] ?? 'fictive';
        $buyer = $data['buyer'] ?? [];
        $items = $data['items'] ?? [];
        $payment = $data['payment'] ?? [];
        $total = floatval($data['total'] ?? 0);
        $paid = floatval($data['paid'] ?? 0);
        $balance = $total - $paid;

        // Create WordPress post first
        $post_id = wp_insert_post([
            'post_type' => 'invoice',
            'post_status' => 'publish',
            'post_title' => 'Invoice #' . $invoice_number,
            'post_author' => get_current_user_id()
        ]);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        // Insert into custom table if exists
        $table_invoices = $wpdb->prefix . 'cig_invoices';
        if ($this->table_exists($table_invoices)) {
            $now = current_time('mysql');
            $wpdb->insert(
                $table_invoices,
                [
                    'id' => $post_id,
                    'invoice_number' => $invoice_number,
                    'type' => $type,
                    'customer_name' => sanitize_text_field($buyer['name'] ?? ''),
                    'customer_tax_id' => sanitize_text_field($buyer['tax_id'] ?? ''),
                    'total' => $total,
                    'paid' => $paid,
                    'balance' => $balance,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'activation_date' => ($type === 'standard') ? $now : null
                ],
                ['%d', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s']
            );
        }

        return $post_id;
    }

    /**
     * Get invoice data from custom table
     *
     * @param int $invoice_id Invoice ID
     * @return object|null
     */
    public function get_invoice($invoice_id) {
        global $wpdb;
        
        $table_invoices = $wpdb->prefix . 'cig_invoices';
        if (!$this->table_exists($table_invoices)) {
            // Fallback to post meta
            return (object) [
                'id' => $invoice_id,
                'created_at' => get_the_date('Y-m-d H:i:s', $invoice_id),
                'activation_date' => get_post_meta($invoice_id, '_cig_activation_date', true)
            ];
        }

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_invoices WHERE id = %d",
            $invoice_id
        ));
    }

    /**
     * Sync invoice data to custom tables for statistics
     * This ensures real-time statistics by writing to custom DB tables
     *
     * @param int    $invoice_id      Invoice ID
     * @param string $invoice_number  Invoice number
     * @param string $status          Invoice status (standard/fictive)
     * @param array  $buyer           Buyer data
     * @param array  $items           Invoice items
     * @param array  $payment_history Payment history
     * @return bool
     */
    public function sync_invoice($invoice_id, $invoice_number, $status, $buyer, $items, $payment_history) {
        global $wpdb;

        $table_invoices = $wpdb->prefix . 'cig_invoices';
        $table_items    = $wpdb->prefix . 'cig_invoice_items';
        $table_payments = $wpdb->prefix . 'cig_payments';

        // Check if tables exist
        if (!$this->table_exists($table_invoices)) {
            return false; // Tables not created yet, skip sync
        }

        // Calculate totals
        $total = 0;
        $paid = 0;
        foreach ($items as $item) {
            if (($item['status'] ?? '') !== 'canceled') {
                $total += floatval($item['total'] ?? (floatval($item['qty'] ?? 0) * floatval($item['price'] ?? 0)));
            }
        }
        foreach ($payment_history as $payment) {
            $paid += floatval($payment['amount'] ?? 0);
        }
        $balance = $total - $paid;

        $post = get_post($invoice_id);
        $created_at = $post->post_date;
        $updated_at = current_time('mysql');

        // Check if invoice exists in custom table
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_invoices WHERE id = %d",
            $invoice_id
        ));

        if ($exists) {
            // Update existing record
            $wpdb->update(
                $table_invoices,
                [
                    'invoice_number' => $invoice_number,
                    'type' => $status,
                    'customer_name' => sanitize_text_field($buyer['name'] ?? ''),
                    'customer_tax_id' => sanitize_text_field($buyer['tax_id'] ?? ''),
                    'total' => $total,
                    'paid' => $paid,
                    'balance' => $balance,
                    'updated_at' => $updated_at,
                    'activation_date' => ($status === 'standard') ? $created_at : null
                ],
                ['id' => $invoice_id],
                ['%s', '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%s'],
                ['%d']
            );
        } else {
            // Insert new record
            $wpdb->insert(
                $table_invoices,
                [
                    'id' => $invoice_id,
                    'invoice_number' => $invoice_number,
                    'type' => $status,
                    'customer_name' => sanitize_text_field($buyer['name'] ?? ''),
                    'customer_tax_id' => sanitize_text_field($buyer['tax_id'] ?? ''),
                    'total' => $total,
                    'paid' => $paid,
                    'balance' => $balance,
                    'created_at' => $created_at,
                    'updated_at' => $updated_at,
                    'activation_date' => ($status === 'standard') ? $created_at : null
                ],
                ['%d', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s']
            );
        }

        // Sync items
        $wpdb->delete($table_items, ['invoice_id' => $invoice_id], ['%d']);
        foreach ($items as $item) {
            $wpdb->insert(
                $table_items,
                [
                    'invoice_id' => $invoice_id,
                    'product_id' => intval($item['product_id'] ?? 0),
                    'sku' => sanitize_text_field($item['sku'] ?? ''),
                    'name' => sanitize_text_field($item['name'] ?? ''),
                    'brand' => sanitize_text_field($item['brand'] ?? ''),
                    'description' => sanitize_textarea_field($item['desc'] ?? ''),
                    'image' => esc_url_raw($item['image'] ?? ''),
                    'qty' => floatval($item['qty'] ?? 0),
                    'price' => floatval($item['price'] ?? 0),
                    'total' => floatval($item['total'] ?? 0),
                    'warranty' => sanitize_text_field($item['warranty'] ?? ''),
                    'reservation_days' => intval($item['reservation_days'] ?? 0),
                    'status' => $item['status'] ?? 'sold',
                    'created_at' => $created_at
                ],
                ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%d', '%s', '%s']
            );
        }

        // Sync payments
        $wpdb->delete($table_payments, ['invoice_id' => $invoice_id], ['%d']);
        foreach ($payment_history as $payment) {
            $wpdb->insert(
                $table_payments,
                [
                    'invoice_id' => $invoice_id,
                    'date' => sanitize_text_field($payment['date'] ?? current_time('Y-m-d')),
                    'amount' => floatval($payment['amount'] ?? 0),
                    'payment_method' => sanitize_text_field($payment['method'] ?? 'other'),
                    'comment' => sanitize_textarea_field($payment['comment'] ?? ''),
                    'user_id' => intval($payment['user_id'] ?? 0),
                    'created_at' => current_time('mysql')
                ],
                ['%d', '%s', '%f', '%s', '%s', '%d', '%s']
            );
        }

        return true;
    }

    /**
     * Check if a table exists
     *
     * @param string $table_name Table name
     * @return bool
     */
    private function table_exists($table_name) {
        global $wpdb;
        $result = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table_name
        ));
        return $result === $table_name;
    }
}
