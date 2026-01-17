<?php
/**
 * Core functionality handler
 * Updated: DB Cart Logic, Archive Buttons, Cron Cleanup & SKU Restrictions
 *
 * @package CIG
 * @since 4.9.4
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CIG Core Class
 */
class CIG_Core {

    /** @var CIG_Logger */
    private $logger;

    /** @var CIG_Cache */
    private $cache;

    /**
     * Constructor
     *
     * @param CIG_Logger|null $logger
     * @param CIG_Cache|null  $cache
     */
    public function __construct($logger = null, $cache = null) {
        $this->logger = $logger ?: (function_exists('CIG') ? CIG()->logger : null);
        $this->cache  = $cache  ?: (function_exists('CIG') ? CIG()->cache  : null);

        add_action('activated_plugin', [$this, 'detect_multiple_versions']);
        add_action('admin_notices', [$this, 'show_version_notice']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_front_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // --- Cart Hooks ---
        // Single Product Page
        add_action('woocommerce_after_add_to_cart_button', [$this, 'render_single_product_btn']);
        
        // Archive / Shop / Category Pages (NEW)
        // Using before_shop_loop_item_title to inject into the product wrapper/image area
        add_action('woocommerce_before_shop_loop_item_title', [$this, 'render_archive_btn'], 10);

        // Footer Bar
        add_action('wp_footer', [$this, 'render_single_product_cart_bar']);

        // --- Cron Job for Cart Cleanup (NEW) ---
        add_action('cig_daily_cart_cleanup', [$this, 'cleanup_user_carts']);
        if (!wp_next_scheduled('cig_daily_cart_cleanup')) {
            // Schedule for 03:00 AM tomorrow
            wp_schedule_event(strtotime('tomorrow 03:00:00'), 'daily', 'cig_daily_cart_cleanup');
        }
    }

    /**
     * Cleanup Task: Delete temporary carts from User Meta
     * Runs daily at 03:00 AM
     */
    public function cleanup_user_carts() {
        global $wpdb;
        // Delete all meta keys '_cig_temp_cart' from usermeta table
        $wpdb->delete($wpdb->usermeta, ['meta_key' => '_cig_temp_cart']);
        
        if ($this->logger) {
            $this->logger->info('Daily cleanup: User carts cleared.');
        }
    }

    /**
     * Detect and deactivate multiple plugin versions
     */
    public function detect_multiple_versions() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins   = get_plugins();
        $active        = get_option('active_plugins', []);
        $cig_versions  = [];

        foreach ($active as $plugin_path) {
            if (basename($plugin_path) === 'custom-woocommerce-invoice-generator.php') {
                $cig_versions[] = $plugin_path;
            }
        }

        if (count($cig_versions) > 1) {
            $newest = $this->get_newest_version($cig_versions, $all_plugins);
            foreach ($cig_versions as $path) {
                if ($path !== $newest) {
                    deactivate_plugins($path);
                }
            }
            set_transient('cig_version_replaced', count($cig_versions) - 1, 30);

            if ($this->logger) {
                $this->logger->warning('Multiple CIG versions detected; deactivated older ones', [
                    'kept' => $newest,
                    'deactivated' => array_values(array_diff($cig_versions, [$newest])),
                ]);
            }
        }
    }

    /**
     * Get newest version from plugin paths
     */
    private function get_newest_version($plugin_paths, $all) {
        $newest = '';
        $highest = '0.0.0';

        foreach ($plugin_paths as $path) {
            if (isset($all[$path]['Version'])) {
                $version = $all[$path]['Version'];
                if (version_compare($version, $highest, '>')) {
                    $highest = $version;
                    $newest  = $path;
                }
            }
        }
        return $newest ?: $plugin_paths[0];
    }

    /**
     * Show version replacement notice
     */
    public function show_version_notice() {
        $replaced = get_transient('cig_version_replaced');
        if ($replaced) {
            /* translators: %d: count */
            $message = sprintf(
                _n(
                    'Custom WooCommerce Invoice Generator: Deactivated %d older version.',
                    'Custom WooCommerce Invoice Generator: Deactivated %d older versions.',
                    $replaced,
                    'cig'
                ),
                $replaced
            );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
            delete_transient('cig_version_replaced');
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $installs    = [];

        foreach ($all_plugins as $path => $data) {
            if (basename($path) === 'custom-woocommerce-invoice-generator.php') {
                $installs[] = dirname($path);
            }
        }

        if (count($installs) > 1) {
            $folders = implode(', ', array_map(function ($folder) {
                return '<code>' . esc_html($folder) . '</code>';
            }, $installs));

            echo '<div class="notice notice-warning"><p>';
            echo '<strong>' . esc_html__('Custom WooCommerce Invoice Generator:', 'cig') . '</strong> ';
            echo sprintf(
                esc_html__('Multiple installations detected: %s. Keep only the latest version.', 'cig'),
                $folders
            );
            echo '</p></div>';
        }
    }

    /**
     * Enqueue frontend assets
     */
    public function enqueue_front_assets() {
        $should_enqueue         = false;
        $enqueue_stock_table    = false;
        $enqueue_mini_dashboard = false;
        $enqueue_accountant     = false;

        // Added: Load stock table JS on single product pages & archives too (for the cart logic)
        if (is_product() || is_shop() || is_product_category() || is_product_taxonomy()) {
             $enqueue_stock_table = true; 
        }

        if (is_page()) {
            global $post;
            if ($post && has_shortcode($post->post_content, 'invoice_generator')) {
                $should_enqueue = true;
                $enqueue_mini_dashboard = true; 
            }
            if ($post && has_shortcode($post->post_content, 'products_stock_table')) {
                $enqueue_stock_table = true;
                $enqueue_mini_dashboard = true; 
            }
            if ($post && has_shortcode($post->post_content, 'invoice_accountant_dashboard')) {
                $enqueue_accountant = true;
            }
        }

        if (is_singular('invoice')) {
            $should_enqueue = true;
            $enqueue_mini_dashboard = true; 
            $enqueue_accountant = true; 
        }

        // Enqueue invoice assets
        if ($should_enqueue) {
            wp_enqueue_style(
                'cig-figago-font',
                'https://fonts.googleapis.com/css2?family=FiraGO:wght@400;500;700&display=swap',
                [],
                null
            );

            wp_enqueue_style(
                'cig-jquery-ui',
                'https://code.jquery.com/ui/1.13.3/themes/base/jquery-ui.css',
                [],
                '1.13.3'
            );

            wp_enqueue_style(
                'cig-invoice',
                CIG_ASSETS_URL . 'css/invoice.css',
                ['cig-figago-font'],
                CIG_VERSION
            );

            wp_enqueue_script('jquery-ui-autocomplete');

            // Enqueue the selection sync manager
            wp_enqueue_script(
                'cig-selection-sync',
                CIG_ASSETS_URL . 'js/cig-selection-sync.js',
                ['jquery'],
                CIG_VERSION,
                true
            );

            wp_enqueue_script(
                'cig-invoice',
                CIG_ASSETS_URL . 'js/invoice.js',
                ['jquery', 'jquery-ui-autocomplete', 'cig-selection-sync'],
                CIG_VERSION,
                true
            );

            $settings = $this->get_settings();
            
            // Fetch DB Cart for invoice load
            $saved_cart = [];
            if (is_user_logged_in()) {
                $saved_cart = get_user_meta(get_current_user_id(), '_cig_temp_cart', true);
            }

            $localize_data = [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('cig_nonce'),
                'site_date' => current_time('Y-m-d'),
                'i18n' => [
                    'save_error' => __('Failed to save. Please try again.', 'cig'),
                    'save_success' => __('Invoice saved successfully.', 'cig'),
                    'update_success' => __('Invoice updated successfully.', 'cig'),
                    'no_permission' => __('No permission.', 'cig'),
                    'empty_items' => __('Add at least one product.', 'cig'),
                    'stock_exceeded' => __('Quantity exceeds available stock!', 'cig'),
                    'checking_stock' => __('Checking stock...', 'cig'),
                ],
                'capable' => current_user_can('manage_woocommerce') ? 1 : 0,
                'placeholder_img' => CIG_ASSETS_URL . 'img/placeholder-80x70.png',
                'brand_attribute' => $settings['brand_attribute'] ?? 'pa_prod-brand',
                'default_reservation_days' => intval($settings['default_reservation_days'] ?? CIG_DEFAULT_RESERVATION_DAYS),
                'editMode' => 0,
                'invoiceId' => 0,
                'invoiceNumber' => '',
                'invoiceStatus' => 'standard', 
                'buyer' => [],
                'items' => [],
                'payment' => [],
                'current_user' => get_current_user_id(),
                'initialCart' => is_array($saved_cart) ? $saved_cart : [] // Pass DB Cart
            ];

            if (is_singular('invoice')) {
                global $post;
                $localize_data['invoiceId'] = $post ? (int) $post->ID : 0;
            }

            if (is_singular('invoice') && isset($_GET['edit']) && current_user_can('manage_woocommerce')) {
                global $post;
                $invoice_id = $post ? (int) $post->ID : 0;

                $localize_data['editMode']      = 1;
                $localize_data['invoiceId']     = $invoice_id;
                $localize_data['invoiceNumber'] = get_post_meta($invoice_id, '_cig_invoice_number', true);
                $localize_data['invoiceStatus'] = get_post_meta($invoice_id, '_cig_invoice_status', true) ?: 'standard';
                
                // Get sold_date from custom table or post meta
                $sold_date = get_post_meta($invoice_id, '_cig_sold_date', true);
                if (empty($sold_date)) {
                    // Try to get from custom tables
                    $invoice_manager = CIG_Invoice_Manager::instance();
                    $invoice_data = $invoice_manager->get_invoice_by_post_id($invoice_id);
                    if ($invoice_data && !empty($invoice_data['invoice']['sold_date'])) {
                        $sold_date = $invoice_data['invoice']['sold_date'];
                    }
                }
                $localize_data['sold_date'] = $sold_date ?: '';

                $localize_data['buyer'] = [
                    'name'    => get_post_meta($invoice_id, '_cig_buyer_name', true),
                    'tax_id'  => get_post_meta($invoice_id, '_cig_buyer_tax_id', true),
                    'address' => get_post_meta($invoice_id, '_cig_buyer_address', true),
                    'phone'   => get_post_meta($invoice_id, '_cig_buyer_phone', true),
                    'email'   => get_post_meta($invoice_id, '_cig_buyer_email', true),
                ];

                $items = get_post_meta($invoice_id, '_cig_items', true);
                $localize_data['items'] = is_array($items) ? $items : [];

                $payment_type = get_post_meta($invoice_id, '_cig_payment_type', true);
                $localize_data['payment'] = [
                    'type' => $payment_type ?: 'company_transfer'
                ];

                if ($payment_type === 'mixed') {
                    $localize_data['payment']['company'] = floatval(get_post_meta($invoice_id, '_cig_payment_company', true));
                    $localize_data['payment']['cash']    = floatval(get_post_meta($invoice_id, '_cig_payment_cash', true));
                } elseif ($payment_type === 'consignment') {
                    $localize_data['payment']['comment'] = get_post_meta($invoice_id, '_cig_payment_comment', true);
                }

                $history = get_post_meta($invoice_id, '_cig_payment_history', true);
                $paid_legacy = get_post_meta($invoice_id, '_cig_payment_paid_amount', true);
                
                if (empty($history) && $paid_legacy !== '' && floatval($paid_legacy) > 0) {
                    $history = [[
                        'date'    => get_the_modified_date('Y-m-d', $invoice_id),
                        'amount'  => floatval($paid_legacy),
                        'method'  => get_post_meta($invoice_id, '_cig_payment_type', true) ?: 'manual',
                        'user_id' => get_post_field('post_author', $invoice_id)
                    ]];
                }

                $localize_data['payment']['history'] = is_array($history) ? $history : [];
                $localize_data['payment']['paid_amount'] = floatval($paid_legacy); 
            }

            wp_localize_script('cig-invoice', 'cigAjax', $localize_data);
        }

        // Enqueue products stock table assets
        if ($enqueue_stock_table) {
            wp_enqueue_style(
                'cig-products-stock-table',
                CIG_ASSETS_URL . 'css/products-stock-table.css',
                [],
                CIG_VERSION
            );

            // Enqueue the selection sync manager first
            wp_enqueue_script(
                'cig-selection-sync',
                CIG_ASSETS_URL . 'js/cig-selection-sync.js',
                ['jquery'],
                CIG_VERSION,
                true
            );

            wp_enqueue_script(
                'cig-products-stock-table',
                CIG_ASSETS_URL . 'js/products-stock-table.js',
                ['jquery', 'cig-selection-sync'],
                CIG_VERSION,
                true
            );

            // Fetch DB Cart for Stock Table
            $saved_cart = [];
            if (is_user_logged_in()) {
                $saved_cart = get_user_meta(get_current_user_id(), '_cig_temp_cart', true);
            }

            wp_localize_script('cig-products-stock-table', 'cigStockTable', [
                'ajax_url'   => admin_url('admin-ajax.php'),
                'nonce'      => wp_create_nonce('cig_nonce'),
                'can_manage' => current_user_can('manage_woocommerce'),
                'is_single_product' => is_product() ? 1 : 0, 
                'initialCart' => is_array($saved_cart) ? $saved_cart : [], // Pass DB Cart
                'i18n'       => [
                    'loading' => __('Loading...', 'cig'),
                    'no_results' => __('No products found.', 'cig'),
                    'search_placeholder' => __('Search by name or SKU...', 'cig'),
                    'request_success' => __('Request submitted successfully. Waiting for approval.', 'cig'),
                    'request_fail' => __('Failed to submit request.', 'cig'),
                ]
            ]);
        }

        // Enqueue mini dashboard assets (header)
        if ($enqueue_mini_dashboard) {
            wp_enqueue_style(
                'cig-mini-dashboard',
                CIG_ASSETS_URL . 'css/mini-dashboard.css',
                [],
                CIG_VERSION
            );

            wp_enqueue_script(
                'cig-mini-dashboard',
                CIG_ASSETS_URL . 'js/mini-dashboard.js',
                ['jquery'],
                CIG_VERSION,
                true
            );

            if (!wp_script_is('cig-invoice', 'enqueued')) {
                wp_localize_script('cig-mini-dashboard', 'cigAjax', [
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce'    => wp_create_nonce('cig_nonce'),
                    'i18n'     => [
                        'loading' => __('Loading...', 'cig'),
                        'error'   => __('Error', 'cig'),
                    ]
                ]);
            }
        }

        // Enqueue Accountant assets
        if ($enqueue_accountant) {
            wp_enqueue_style(
                'cig-accountant',
                CIG_ASSETS_URL . 'css/accountant.css',
                [],
                CIG_VERSION
            );

            wp_enqueue_script(
                'cig-accountant',
                CIG_ASSETS_URL . 'js/accountant.js',
                ['jquery'],
                CIG_VERSION,
                true
            );
            
            if (!wp_script_is('cig-invoice', 'enqueued') && !wp_script_is('cig-mini-dashboard', 'enqueued')) {
                 wp_localize_script('cig-accountant', 'cigAjax', [
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce'    => wp_create_nonce('cig_nonce')
                ]);
            }
        }
    }

    /**
     * Render Add to Invoice Button on Single Product Page
     */
    public function render_single_product_btn() {
        if (!current_user_can('manage_woocommerce')) return;
        
        global $product;
        if (!$product) return;

        // --- RESTRICTION LOGIC START ---
        // 1. Check Stock
        if ( ! $product->is_in_stock() ) {
            return;
        }

        // 2. Check SKU for specific string
        $sku = $product->get_sku();
        if ( $sku && strpos($sku, 'GN20ST') !== false ) {
            return;
        }
        // --- RESTRICTION LOGIC END ---

        $is_variable = $product->is_type('variable');
        $id    = $product->get_id();
        $sku   = $product->get_sku();
        $price = $product->get_price();
        $title = $product->get_name();
        $image_id = $product->get_image_id();
        $image = $image_id ? wp_get_attachment_image_src($image_id, 'thumbnail')[0] : '';
        
        $brand = '';
        $settings = $this->get_settings();
        $brand_attr = $settings['brand_attribute'] ?? 'pa_prod-brand';
        if ($brand_attr) {
            $terms = get_the_terms($id, $brand_attr);
            if ($terms && !is_wp_error($terms)) $brand = $terms[0]->name;
        }

        $attrs = 'class="cig-add-btn cig-single-add-btn" type="button" ';
        if ($is_variable) {
            $attrs .= 'data-variable="1" disabled style="opacity:0.5; cursor:not-allowed;" title="' . esc_attr__('აირჩიეთ ვარიაცია', 'cig') . '" ';
        } else {
            $attrs .= 'data-id="' . esc_attr($id) . '" ';
            $attrs .= 'data-sku="' . esc_attr($sku) . '" ';
            $attrs .= 'data-price="' . esc_attr($price) . '" ';
            $attrs .= 'data-title="' . esc_attr($title) . '" ';
            $attrs .= 'data-image="' . esc_attr($image) . '" ';
            $attrs .= 'data-brand="' . esc_attr($brand) . '" ';
            $attrs .= 'data-desc="' . esc_attr(wp_strip_all_tags($product->get_short_description())) . '" ';
        }

        echo '<div style="display:inline-block; vertical-align:middle; margin-left:10px;">';
        echo '<button ' . $attrs . '><span class="dashicons dashicons-plus"></span></button>';
        echo '</div>';
    }

    /**
     * Render Archive Button (NEW)
     */
    public function render_archive_btn() {
        if (!current_user_can('manage_woocommerce')) return;
        
        global $product;
        if (!$product) return;

        // --- RESTRICTION LOGIC START ---
        // 1. Check Stock
        if ( ! $product->is_in_stock() ) {
            return;
        }

        // 2. Check SKU for specific string
        $sku = $product->get_sku();
        if ( $sku && strpos($sku, 'GN20ST') !== false ) {
            return;
        }
        // --- RESTRICTION LOGIC END ---

        // Skip variable products in loop for simplicity, or keep disabled logic
        $is_variable = $product->is_type('variable');
        $id    = $product->get_id();
        $sku   = $product->get_sku();
        $price = $product->get_price();
        $title = $product->get_name();
        $image_id = $product->get_image_id();
        $image = $image_id ? wp_get_attachment_image_src($image_id, 'thumbnail')[0] : '';
        
        $brand = '';
        $settings = $this->get_settings();
        $brand_attr = $settings['brand_attribute'] ?? 'pa_prod-brand';
        if ($brand_attr) {
            $terms = get_the_terms($id, $brand_attr);
            if ($terms && !is_wp_error($terms)) $brand = $terms[0]->name;
        }

        // Archive specific class for positioning
        $attrs = 'class="cig-add-btn cig-archive-add-btn" type="button" '; 
        
        if ($is_variable) {
            // Usually in loop we don't show variable controls, just link. 
            // We can hide it or show disabled. Let's show disabled for awareness.
            $attrs .= 'disabled style="opacity:0.5; cursor:not-allowed;" title="' . esc_attr__('Select options first', 'cig') . '" ';
        } else {
            $attrs .= 'data-id="' . esc_attr($id) . '" ';
            $attrs .= 'data-sku="' . esc_attr($sku) . '" ';
            $attrs .= 'data-price="' . esc_attr($price) . '" ';
            $attrs .= 'data-title="' . esc_attr($title) . '" ';
            $attrs .= 'data-image="' . esc_attr($image) . '" ';
            $attrs .= 'data-brand="' . esc_attr($brand) . '" ';
            $attrs .= 'data-desc="' . esc_attr(wp_strip_all_tags($product->get_short_description())) . '" ';
        }

        // We wrap it to ensure it sits inside the item container
        echo '<div class="cig-archive-btn-wrapper" style="position:absolute; bottom:10px; right:10px; z-index:10;">';
        echo '<button ' . $attrs . '><span class="dashicons dashicons-plus"></span></button>';
        echo '</div>';
    }

    /**
     * Render Cart Bar on Single Product Page footer (NEW)
     */
    public function render_single_product_cart_bar() {
        if (!current_user_can('manage_woocommerce')) return;
        
        // Show on shop/archives too
        $show = is_product() || is_shop() || is_product_category() || is_product_taxonomy();
        
        if (!$show && is_page()) {
            global $post;
            if (has_shortcode($post->post_content, 'products_stock_table')) {
                $show = true;
            }
        }

        if (!$show) return;

        $invoice_page_url = home_url('/invoice-shortcode/'); 
        ?>
        <div id="cig-stock-cart-bar" class="cig-stock-cart-bar" style="display:none;">
            <div class="cig-cart-info">
                <span class="dashicons dashicons-cart"></span>
                <span id="cig-cart-count">0</span> <?php esc_html_e('products selected', 'cig'); ?>
            </div>
            <a href="<?php echo esc_url($invoice_page_url); ?>" id="cig-create-invoice-btn" class="cig-create-invoice-btn">
                <?php esc_html_e('Create Invoice', 'cig'); ?> <span class="dashicons dashicons-arrow-right-alt2"></span>
            </a>
        </div>
        <?php
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if ($hook === 'settings_page_cig-invoice-settings') {
            wp_enqueue_media();
            wp_enqueue_script(
                'cig-admin-settings',
                CIG_ASSETS_URL . 'js/admin-settings.js',
                ['jquery'],
                CIG_VERSION,
                true
            );
        }
    }

    private function get_settings() {
        if ($this->cache) {
            return (array) $this->cache->remember('settings', CIG_CACHE_EXPIRY, function () {
                return (array) get_option('cig_settings', []);
            });
        }
        return (array) get_option('cig_settings', []);
    }

    public static function get_setting($key, $default = '') {
        $settings = get_option('cig_settings', []);
        return $settings[$key] ?? $default;
    }
}