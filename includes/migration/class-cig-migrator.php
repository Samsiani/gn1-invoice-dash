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
     * Option name for migration complete flag
     *
     * @var string
     */
    const MIGRATION_COMPLETE_OPTION = 'cig_v2_migration_complete';

    /**
     * Option name to store migration results for admin notice
     *
     * @var string
     */
    const MIGRATION_RESULTS_OPTION = 'cig_v2_migration_results';

    /**
     * Initialize migration hooks
     */
    public function __construct() {
        add_action('admin_notices', [$this, 'display_migration_notice']);
        add_action('admin_init', [$this, 'dismiss_migration_notice']);
    }

    /**
     * Migrate invoices from v1 (postmeta) to v2 (custom tables)
     * This is the main entry point for migration during plugin update/activation
     *
     * @return array|false Migration results array or false if already migrated
     */
    public function migrate_v1_to_v2() {
        // Check if migration has already been completed
        if (get_option(self::MIGRATION_COMPLETE_OPTION)) {
            return false;
        }

        global $wpdb;

        // Get all invoice posts
        $args = [
            'post_type'      => 'invoice',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ];

        $invoice_ids = get_posts($args);

        $results = [
            'total'     => count($invoice_ids),
            'success'   => 0,
            'failed'    => 0,
            'errors'    => [],
            'timestamp' => current_time('mysql'),
        ];

        // Table names
        $table_invoices = $wpdb->prefix . 'cig_invoices';
        $table_items    = $wpdb->prefix . 'cig_invoice_items';
        $table_payments = $wpdb->prefix . 'cig_payments';

        foreach ($invoice_ids as $invoice_id) {
            $result = $this->migrate_invoice_v1_to_v2($invoice_id, $table_invoices, $table_items, $table_payments);
            if ($result === true) {
                $results['success']++;
            } else {
                $results['failed']++;
                $results['errors'][$invoice_id] = $result;
            }
        }

        // Mark migration as complete
        update_option(self::MIGRATION_COMPLETE_OPTION, true, false);

        // Store results for admin notice
        update_option(self::MIGRATION_RESULTS_OPTION, $results, false);

        return $results;
    }

    /**
     * Migrate a single invoice from v1 to v2 schema
     *
     * @param int    $invoice_id      Invoice post ID
     * @param string $table_invoices  Invoices table name
     * @param string $table_items     Invoice items table name
     * @param string $table_payments  Payments table name
     * @return true|string True on success, error message on failure
     */
    private function migrate_invoice_v1_to_v2($invoice_id, $table_invoices, $table_items, $table_payments) {
        global $wpdb;

        try {
            $post = get_post($invoice_id);
            if (!$post) {
                return 'Invoice post not found';
            }

            // Extract meta data from postmeta
            $invoice_number   = get_post_meta($invoice_id, '_cig_invoice_number', true);
            $invoice_status   = get_post_meta($invoice_id, '_cig_invoice_status', true) ?: 'standard';
            $lifecycle_status = get_post_meta($invoice_id, '_cig_lifecycle_status', true) ?: 'unfinished';
            $buyer_name       = get_post_meta($invoice_id, '_cig_buyer_name', true);
            $buyer_tax_id     = get_post_meta($invoice_id, '_cig_buyer_tax_id', true);
            $total_amount     = floatval(get_post_meta($invoice_id, '_cig_invoice_total', true));
            $paid_amount      = floatval(get_post_meta($invoice_id, '_cig_payment_paid_amount', true));
            $general_note     = get_post_meta($invoice_id, '_cig_general_note', true);
            $is_rs_uploaded   = get_post_meta($invoice_id, '_cig_is_rs_uploaded', true) ? 1 : 0;

            // Critical Date Logic for sale_date
            $sale_date = null;
            if ($invoice_status === 'fictive') {
                // Fictive invoices have no sale_date
                $sale_date = null;
            } else {
                // Standard invoices: check _cig_sale_date, fallback to post_date
                $stored_sale_date = get_post_meta($invoice_id, '_cig_sale_date', true);
                if (!empty($stored_sale_date)) {
                    $sale_date = $stored_sale_date;
                } else {
                    $sale_date = $post->post_date;
                }
            }

            $created_at = $post->post_date;
            $author_id  = $post->post_author;

            // Check if invoice already exists in custom table
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_invoices WHERE invoice_number = %s",
                $invoice_number
            ));

            if ($exists) {
                // Update existing record
                $wpdb->update(
                    $table_invoices,
                    [
                        'invoice_number'   => $invoice_number,
                        'status'           => $invoice_status,
                        'lifecycle_status' => $lifecycle_status,
                        'total_amount'     => $total_amount,
                        'paid_amount'      => $paid_amount,
                        'sale_date'        => $sale_date,
                        'general_note'     => $general_note,
                        'is_rs_uploaded'   => $is_rs_uploaded,
                        'author_id'        => $author_id,
                    ],
                    ['id' => $exists],
                    ['%s', '%s', '%s', '%f', '%f', '%s', '%s', '%d', '%d'],
                    ['%d']
                );
                $new_invoice_id = $exists;
            } else {
                // Insert new record
                $wpdb->insert(
                    $table_invoices,
                    [
                        'invoice_number'   => $invoice_number,
                        'status'           => $invoice_status,
                        'lifecycle_status' => $lifecycle_status,
                        'total_amount'     => $total_amount,
                        'paid_amount'      => $paid_amount,
                        'created_at'       => $created_at,
                        'sale_date'        => $sale_date,
                        'general_note'     => $general_note,
                        'is_rs_uploaded'   => $is_rs_uploaded,
                        'author_id'        => $author_id,
                    ],
                    ['%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%d', '%d']
                );
                $new_invoice_id = $wpdb->insert_id;
            }

            if (!$new_invoice_id) {
                return 'Failed to insert/update invoice record';
            }

            // Migrate items from _cig_items (serialized array)
            $items = get_post_meta($invoice_id, '_cig_items', true);
            if (is_array($items) && !empty($items)) {
                // Delete existing items for this invoice to avoid duplicates
                $wpdb->delete($table_items, ['invoice_id' => $new_invoice_id], ['%d']);

                foreach ($items as $item) {
                    $wpdb->insert(
                        $table_items,
                        [
                            'invoice_id'        => $new_invoice_id,
                            'product_id'        => intval($item['product_id'] ?? 0),
                            'product_name'      => sanitize_text_field($item['name'] ?? ''),
                            'sku'               => sanitize_text_field($item['sku'] ?? ''),
                            'quantity'          => floatval($item['qty'] ?? 0),
                            'price'             => floatval($item['price'] ?? 0),
                            'item_status'       => sanitize_text_field($item['status'] ?? 'none'),
                            'warranty_duration' => sanitize_text_field($item['warranty'] ?? ''),
                            'reservation_days'  => intval($item['reservation_days'] ?? 0),
                        ],
                        ['%d', '%d', '%s', '%s', '%f', '%f', '%s', '%s', '%d']
                    );
                }
            }

            // Migrate payment history from _cig_payment_history (serialized array)
            $payment_history = get_post_meta($invoice_id, '_cig_payment_history', true);
            if (is_array($payment_history) && !empty($payment_history)) {
                // Delete existing payments for this invoice to avoid duplicates
                $wpdb->delete($table_payments, ['invoice_id' => $new_invoice_id], ['%d']);

                foreach ($payment_history as $payment) {
                    $payment_date = sanitize_text_field($payment['date'] ?? '');
                    // Ensure date is in proper datetime format
                    if (!empty($payment_date)) {
                        // Validate and format date - handle Y-m-d format
                        $date_obj = DateTime::createFromFormat('Y-m-d', $payment_date);
                        if ($date_obj !== false) {
                            $payment_date = $date_obj->format('Y-m-d H:i:s');
                        } else {
                            // Try parsing as full datetime
                            $date_obj = DateTime::createFromFormat('Y-m-d H:i:s', $payment_date);
                            if ($date_obj === false) {
                                // Invalid date, use current time
                                $payment_date = current_time('mysql');
                            }
                        }
                    } else {
                        $payment_date = current_time('mysql');
                    }

                    $wpdb->insert(
                        $table_payments,
                        [
                            'invoice_id' => $new_invoice_id,
                            'amount'     => floatval($payment['amount'] ?? 0),
                            'date'       => $payment_date,
                            'method'     => sanitize_text_field($payment['method'] ?? 'other'),
                            'user_id'    => intval($payment['user_id'] ?? 0),
                            'comment'    => sanitize_textarea_field($payment['comment'] ?? ''),
                        ],
                        ['%d', '%f', '%s', '%s', '%d', '%s']
                    );
                }
            }

            return true;

        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * Display admin notice showing migration results
     */
    public function display_migration_notice() {
        $results = get_option(self::MIGRATION_RESULTS_OPTION);

        if (empty($results) || !is_array($results)) {
            return;
        }

        // Only show to administrators
        if (!current_user_can('manage_options')) {
            return;
        }

        $class = ($results['failed'] > 0) ? 'notice-warning' : 'notice-success';
        $dismiss_url = wp_nonce_url(
            add_query_arg('cig_dismiss_migration_notice', '1'),
            'cig_dismiss_migration_notice'
        );

        ?>
        <div class="notice <?php echo esc_attr($class); ?> is-dismissible">
            <p>
                <strong><?php esc_html_e('CIG Data Migration Complete', 'cig'); ?></strong>
            </p>
            <p>
                <?php
                printf(
                    /* translators: 1: Number of successfully migrated invoices, 2: Total number of invoices */
                    esc_html__('Successfully migrated %1$d of %2$d invoices to the new database structure.', 'cig'),
                    intval($results['success']),
                    intval($results['total'])
                );
                ?>
            </p>
            <?php if ($results['failed'] > 0) : ?>
                <p>
                    <?php
                    printf(
                        /* translators: %d: Number of failed migrations */
                        esc_html__('%d invoice(s) failed to migrate. Please check the error log for details.', 'cig'),
                        intval($results['failed'])
                    );
                    ?>
                </p>
            <?php endif; ?>
            <p>
                <a href="<?php echo esc_url($dismiss_url); ?>"><?php esc_html_e('Dismiss this notice', 'cig'); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * Handle dismissing the migration notice
     */
    public function dismiss_migration_notice() {
        if (
            isset($_GET['cig_dismiss_migration_notice']) &&
            isset($_GET['_wpnonce']) &&
            wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'cig_dismiss_migration_notice')
        ) {
            delete_option(self::MIGRATION_RESULTS_OPTION);
            wp_safe_redirect(remove_query_arg(['cig_dismiss_migration_notice', '_wpnonce']));
            exit;
        }
    }

    /**
     * Check if migration has been completed
     *
     * @return bool True if migration is complete
     */
    public static function is_migration_complete() {
        return (bool) get_option(self::MIGRATION_COMPLETE_OPTION, false);
    }

    /**
     * Reset migration flag (for re-running migration if needed)
     * Use with caution - only for development/testing
     */
    public static function reset_migration_flag() {
        delete_option(self::MIGRATION_COMPLETE_OPTION);
        delete_option(self::MIGRATION_RESULTS_OPTION);
    }

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
