<?php
/**
 * Data Migrator
 * Migrates invoice data from postmeta to custom tables
 *
 * @package CIG
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CIG_Migrator {

    /**
     * Migrate all invoices from postmeta to custom tables
     *
     * @return array Migration results
     */
    public function migrate_all() {
        $args = [
            'post_type' => 'invoice',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ];

        $invoice_ids = get_posts($args);
        
        $results = [
            'total' => count($invoice_ids),
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];

        foreach ($invoice_ids as $invoice_id) {
            $result = $this->migrate_single_invoice($invoice_id);
            if ($result === true) {
                $results['success']++;
            } else {
                $results['failed']++;
                $results['errors'][$invoice_id] = $result;
            }
        }

        return $results;
    }

    /**
     * Migrate a single invoice from postmeta to custom tables
     *
     * @param int $invoice_id Invoice post ID
     * @return true|string True on success, error message on failure
     */
    public function migrate_single_invoice($invoice_id) {
        global $wpdb;

        try {
            // Get invoice data from postmeta
            $invoice_number = get_post_meta($invoice_id, '_cig_invoice_number', true);
            $invoice_status = get_post_meta($invoice_id, '_cig_invoice_status', true) ?: 'standard';
            $buyer_name = get_post_meta($invoice_id, '_cig_buyer_name', true);
            $buyer_tax_id = get_post_meta($invoice_id, '_cig_buyer_tax_id', true);
            $total = floatval(get_post_meta($invoice_id, '_cig_invoice_total', true));
            $paid = floatval(get_post_meta($invoice_id, '_cig_payment_paid_amount', true));
            $balance = $total - $paid;

            $post = get_post($invoice_id);
            $created_at = $post->post_date;
            $updated_at = $post->post_modified;

            // Check if already migrated
            $table_invoices = $wpdb->prefix . 'cig_invoices';
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
                        'type' => $invoice_status,
                        'customer_name' => $buyer_name,
                        'customer_tax_id' => $buyer_tax_id,
                        'total' => $total,
                        'paid' => $paid,
                        'balance' => $balance,
                        'updated_at' => $updated_at,
                        'activation_date' => ($invoice_status === 'standard') ? $created_at : null
                    ],
                    ['id' => $invoice_id]
                );
            } else {
                // Insert new record
                $wpdb->insert(
                    $table_invoices,
                    [
                        'id' => $invoice_id,
                        'invoice_number' => $invoice_number,
                        'type' => $invoice_status,
                        'customer_name' => $buyer_name,
                        'customer_tax_id' => $buyer_tax_id,
                        'total' => $total,
                        'paid' => $paid,
                        'balance' => $balance,
                        'created_at' => $created_at,
                        'updated_at' => $updated_at,
                        'activation_date' => ($invoice_status === 'standard') ? $created_at : null
                    ]
                );
            }

            // Migrate items
            $items = get_post_meta($invoice_id, '_cig_items', true);
            if (is_array($items)) {
                $table_items = $wpdb->prefix . 'cig_invoice_items';
                
                // Delete existing items for this invoice
                $wpdb->delete($table_items, ['invoice_id' => $invoice_id]);

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
                        ]
                    );
                }
            }

            // Migrate payment history
            $payment_history = get_post_meta($invoice_id, '_cig_payment_history', true);
            if (is_array($payment_history)) {
                $table_payments = $wpdb->prefix . 'cig_payments';
                
                // Delete existing payments for this invoice
                $wpdb->delete($table_payments, ['invoice_id' => $invoice_id]);

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
                        ]
                    );
                }
            }

            return true;

        } catch (Exception $e) {
            return $e->getMessage();
        }
    }
}
