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
        
        // Register admin menu for manual migration
        add_action('admin_menu', [$this, 'register_migration_menu'], 30);
        
        // Register AJAX handler for manual migration
        add_action('wp_ajax_cig_manual_migration', [$this, 'handle_manual_migration']);
    }

    /**
     * Register migration submenu page under Invoices
     */
    public function register_migration_menu() {
        add_submenu_page(
            'edit.php?post_type=invoice',
            __('DB Migration', 'cig'),
            __('DB Migration', 'cig'),
            'manage_woocommerce',
            'cig-db-migration',
            [$this, 'render_migration_page']
        );
    }

    /**
     * Render the migration admin page
     */
    public function render_migration_page() {
        $is_complete = self::is_migration_complete();
        $results = get_option(self::MIGRATION_RESULTS_OPTION);
        $nonce = wp_create_nonce('cig_manual_migration');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Database Migration', 'cig'); ?></h1>
            
            <div class="card" style="max-width: 600px; padding: 20px;">
                <h2><?php esc_html_e('Migration Status', 'cig'); ?></h2>
                
                <?php if ($is_complete) : ?>
                    <p style="color: #46b450;">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php esc_html_e('Migration has been completed.', 'cig'); ?>
                    </p>
                    <?php if ($results && is_array($results)) : ?>
                        <p>
                            <?php
                            printf(
                                /* translators: 1: success count, 2: total count, 3: failed count */
                                esc_html__('Last migration: %1$d of %2$d invoices migrated successfully. %3$d failed.', 'cig'),
                                intval($results['success'] ?? 0),
                                intval($results['total'] ?? 0),
                                intval($results['failed'] ?? 0)
                            );
                            ?>
                        </p>
                        <?php if (!empty($results['timestamp'])) : ?>
                            <p><small><?php echo esc_html(sprintf(__('Completed at: %s', 'cig'), $results['timestamp'])); ?></small></p>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php else : ?>
                    <p style="color: #d63638;">
                        <span class="dashicons dashicons-warning"></span>
                        <?php esc_html_e('Migration has not been completed yet.', 'cig'); ?>
                    </p>
                <?php endif; ?>
                
                <hr style="margin: 20px 0;">
                
                <h3><?php esc_html_e('Manual Migration', 'cig'); ?></h3>
                <p><?php esc_html_e('Use this button to manually trigger the migration from postmeta to custom tables. This will re-run the migration even if it was previously completed.', 'cig'); ?></p>
                <p class="description" style="color: #d63638;">
                    <strong><?php esc_html_e('Warning:', 'cig'); ?></strong>
                    <?php esc_html_e('Do not run this while the system is actively being used. Existing data in custom tables will be updated based on postmeta data.', 'cig'); ?>
                </p>
                
                <p>
                    <input type="hidden" id="cig-migration-nonce" value="<?php echo esc_attr($nonce); ?>">
                    <button type="button" id="cig-start-migration" class="button button-primary">
                        <?php esc_html_e('Start Migration', 'cig'); ?>
                    </button>
                    <span id="cig-migration-spinner" class="spinner" style="float: none; margin-left: 10px;"></span>
                </p>
                
                <div id="cig-migration-results" style="display: none; margin-top: 20px; padding: 15px; background: #f0f0f1; border-left: 4px solid #72aee6;">
                    <h4 style="margin-top: 0;"><?php esc_html_e('Migration Results', 'cig'); ?></h4>
                    <p id="cig-migration-message"></p>
                </div>
            </div>
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#cig-start-migration').on('click', function() {
                var $button = $(this);
                var $spinner = $('#cig-migration-spinner');
                var $results = $('#cig-migration-results');
                var $message = $('#cig-migration-message');
                var nonce = $('#cig-migration-nonce').val();
                
                var confirmMessage = '<?php echo esc_js(__('WARNING: This will reset the migration flag and re-migrate all invoices from postmeta to custom tables. Do not run this while users are actively creating or editing invoices. Are you sure you want to continue?', 'cig')); ?>';
                
                if (!confirm(confirmMessage)) {
                    return;
                }
                
                $button.prop('disabled', true);
                $spinner.addClass('is-active');
                $results.hide();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'cig_manual_migration',
                        nonce: nonce
                    },
                    success: function(response) {
                        $spinner.removeClass('is-active');
                        $button.prop('disabled', false);
                        
                        if (response.success) {
                            var data = response.data;
                            var statusColor = data.failed > 0 ? '#d63638' : '#46b450';
                            $results.css('border-left-color', statusColor);
                            $message.html(
                                '<strong><?php echo esc_js(__('Migration completed!', 'cig')); ?></strong><br>' +
                                '<?php echo esc_js(__('Total:', 'cig')); ?> ' + data.total + '<br>' +
                                '<?php echo esc_js(__('Success:', 'cig')); ?> ' + data.success + '<br>' +
                                '<?php echo esc_js(__('Failed:', 'cig')); ?> ' + data.failed
                            );
                            $results.show();
                        } else {
                            $results.css('border-left-color', '#d63638');
                            $message.html('<strong><?php echo esc_js(__('Error:', 'cig')); ?></strong> ' + (response.data.message || '<?php echo esc_js(__('Unknown error occurred.', 'cig')); ?>'));
                            $results.show();
                        }
                    },
                    error: function() {
                        $spinner.removeClass('is-active');
                        $button.prop('disabled', false);
                        $results.css('border-left-color', '#d63638');
                        $message.html('<strong><?php echo esc_js(__('Error:', 'cig')); ?></strong> <?php echo esc_js(__('AJAX request failed.', 'cig')); ?>');
                        $results.show();
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Handle manual migration AJAX request
     */
    public function handle_manual_migration() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'cig_manual_migration')) {
            wp_send_json_error(['message' => __('Security check failed.', 'cig')]);
        }
        
        // Check capability
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'cig')]);
        }
        
        // Reset migration flag to allow re-run
        self::reset_migration_flag();
        
        // Run migration
        $results = $this->migrate_v1_to_v2();
        
        if ($results === false) {
            wp_send_json_error(['message' => __('Migration could not be started.', 'cig')]);
        }
        
        wp_send_json_success([
            'total'   => intval($results['total'] ?? 0),
            'success' => intval($results['success'] ?? 0),
            'failed'  => intval($results['failed'] ?? 0),
            'errors'  => $results['errors'] ?? []
        ]);
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
                    // Get quantity and price
                    $qty   = floatval($item['qty'] ?? 0);
                    $price = floatval($item['price'] ?? 0);

                    // Calculate total if missing or zero
                    $total = floatval($item['total'] ?? 0);
                    if ($total <= 0 && $qty > 0 && $price > 0) {
                        $total = $qty * $price;
                    }

                    $wpdb->insert(
                        $table_items,
                        [
                            'invoice_id'        => $new_invoice_id,
                            'product_id'        => intval($item['product_id'] ?? 0),
                            'product_name'      => sanitize_text_field($item['name'] ?? ''),
                            'sku'               => sanitize_text_field($item['sku'] ?? ''),
                            'quantity'          => $qty,
                            'price'             => $price,
                            'total'             => $total,
                            'item_status'       => sanitize_text_field($item['status'] ?? 'none'),
                            'warranty_duration' => sanitize_text_field($item['warranty'] ?? ''),
                            'reservation_days'  => intval($item['reservation_days'] ?? 0),
                        ],
                        ['%d', '%d', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%d']
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
                    // Get quantity and price
                    $qty   = floatval($item['qty'] ?? 0);
                    $price = floatval($item['price'] ?? 0);

                    // Calculate total if missing or zero
                    $total = floatval($item['total'] ?? 0);
                    if ($total <= 0 && $qty > 0 && $price > 0) {
                        $total = $qty * $price;
                    }

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
                            'qty' => $qty,
                            'price' => $price,
                            'total' => $total,
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
