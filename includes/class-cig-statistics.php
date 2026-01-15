<?php
/**
 * Statistics dashboard handler
 *
 * @package CIG
 * @since 3.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CIG Statistics Class
 */
class CIG_Statistics {

    /** @var CIG_Cache */
    private $cache;

    /**
     * Constructor
     *
     * @param CIG_Cache|null $cache
     */
    public function __construct($cache = null) {
        $this->cache = $cache ?: (function_exists('CIG') ? CIG()->cache : null);

        add_action('admin_menu', [$this, 'register_menu'], 25);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_init', [$this, 'handle_export']);
    }

    /**
     * Register statistics menu page
     */
    public function register_menu() {
        add_submenu_page(
            'edit.php?post_type=invoice',
            __('Invoice Statistics', 'cig'),
            __('Statistics', 'cig'),
            'edit_posts',
            'invoice-statistics',
            [$this, 'render_page']
        );
    }

    /**
     * Enqueue assets for statistics page
     *
     * @param string $hook
     */
    public function enqueue_assets($hook) {
        if ($hook !== 'invoice_page_invoice-statistics') {
            return;
        }

        // 1. Enqueue jQuery UI Autocomplete (Core WP script)
        wp_enqueue_script('jquery-ui-autocomplete');

        // 2. Enqueue jQuery UI CSS (For the dropdown styling)
        wp_enqueue_style(
            'cig-jquery-ui',
            'https://code.jquery.com/ui/1.13.3/themes/base/jquery-ui.css',
            [],
            '1.13.3'
        );

        // 3. Enqueue Chart.js
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            [],
            '4.4.0',
            true
        );

        // 4. Enqueue custom CSS
        wp_enqueue_style(
            'cig-statistics',
            CIG_ASSETS_URL . 'css/statistics.css',
            [],
            CIG_VERSION
        );

        // 5. Enqueue custom JS (Added jquery-ui-autocomplete dependency)
        wp_enqueue_script(
            'cig-statistics',
            CIG_ASSETS_URL . 'js/statistics.js',
            ['jquery', 'chartjs', 'jquery-ui-autocomplete'], 
            CIG_VERSION,
            true
        );

        // Localize script
        wp_localize_script('cig-statistics', 'cigStats', [
            'ajax_url'      => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('cig_nonce'),
            'export_nonce'  => wp_create_nonce('cig_export_statistics'),
            'current_user'  => get_current_user_id(),
            'payment_types' => CIG_Invoice::get_payment_types(),
            'i18n' => [
                'loading'        => __('Loading...', 'cig'),
                'no_data'        => __('No data available', 'cig'),
                'error'          => __('Error loading data', 'cig'),
                'export_success' => __('Export completed successfully', 'cig'),
                'export_error'   => __('Export failed', 'cig'),
            ],
            'colors' => [
                'primary' => '#50529d',
                'success' => '#28a745',
                'warning' => '#ffc107',
                'danger'  => '#dc3545',
                'info'    => '#17a2b8',
            ]
        ]);
    }

    /**
     * Render statistics page
     */
    public function render_page() {
        include CIG_TEMPLATES_DIR . 'statistics-dashboard.php';
    }

    /**
     * Handle export request (admin side, GET)
     */
    public function handle_export() {
        if (!isset($_GET['cig_export']) || $_GET['cig_export'] !== 'statistics') {
            return;
        }

        // Capability check first
        if (!current_user_can('edit_posts')) {
            wp_die(__('Insufficient permissions', 'cig'));
        }

        // Nonce check (export-specific)
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'cig_export_statistics')) {
            // Fallback to deny with message
            wp_die(__('Security check failed', 'cig'));
        }

        $this->generate_excel_export();
    }

    /**
     * Generate CSV export (Excel-compatible)
     */
    private function generate_excel_export() {
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to   = isset($_GET['date_to'])   ? sanitize_text_field($_GET['date_to'])   : '';
        $status    = isset($_GET['status'])    ? sanitize_text_field($_GET['status'])    : 'standard';

        // Query invoices
        $args = [
            'post_type'      => 'invoice',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids'
        ];

        if ($date_from && $date_to) {
            $args['date_query'] = [[
                'after'     => $date_from . ' 00:00:00',
                'before'    => $date_to   . ' 23:59:59',
                'inclusive' => true
            ]];
        }

        // Status Filter Logic
        $meta_query = [];
        if ($status === 'fictive') {
            $meta_query[] = [
                'key'     => '_cig_invoice_status',
                'value'   => 'fictive',
                'compare' => '='
            ];
        } elseif ($status === 'standard') {
            $meta_query[] = [
                'relation' => 'OR',
                ['key' => '_cig_invoice_status', 'value' => 'standard', 'compare' => '='],
                ['key' => '_cig_invoice_status', 'compare' => 'NOT EXISTS']
            ];
        }

        if (!empty($meta_query)) {
            $args['meta_query'] = $meta_query;
        }

        $query = new WP_Query($args);
        $invoice_ids = $query->posts;

        // Collect user statistics
        $users_data = [];

        foreach ($invoice_ids as $invoice_id) {
            $post      = get_post($invoice_id);
            $author_id = $post->post_author;

            if (!isset($users_data[$author_id])) {
                $user = get_userdata($author_id);
                if (!$user) continue;

                $users_data[$author_id] = [
                    'User Name'            => $user->display_name,
                    'Email'                => $user->user_email,
                    'Total Invoices'       => 0,
                    'Total Sold Items'     => 0,
                    'Total Reserved Items' => 0,
                    'Total Canceled Items' => 0,
                    'Total Revenue'        => 0,
                    'Last Invoice Date'    => ''
                ];
            }

            $users_data[$author_id]['Total Invoices']++;

            $total = floatval(get_post_meta($invoice_id, '_cig_invoice_total', true));
            $users_data[$author_id]['Total Revenue'] += $total;

            $items = get_post_meta($invoice_id, '_cig_items', true);
            if (is_array($items)) {
                foreach ($items as $item) {
                    $qty    = floatval($item['qty'] ?? 0);
                    $status = strtolower($item['status'] ?? 'sold');

                    if ($status === 'sold')       $users_data[$author_id]['Total Sold Items']     += $qty;
                    elseif ($status === 'reserved')$users_data[$author_id]['Total Reserved Items'] += $qty;
                    elseif ($status === 'canceled')$users_data[$author_id]['Total Canceled Items'] += $qty;
                }
            }

            $invoice_date = get_post_field('post_date', $invoice_id);
            if (empty($users_data[$author_id]['Last Invoice Date']) || $invoice_date > $users_data[$author_id]['Last Invoice Date']) {
                $users_data[$author_id]['Last Invoice Date'] = date('Y-m-d H:i:s', strtotime($invoice_date));
            }
        }

        // Generate CSV (Excel-compatible)
        $filename_prefix = ($status === 'fictive') ? 'fictive-invoices-' : 'active-invoices-';
        if ($status === 'all') $filename_prefix = 'all-invoices-';
        
        $filename = $filename_prefix . date('Y-m-d-H-i-s') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        if (!empty($users_data)) {
            $headers = array_keys(reset($users_data));
            fputcsv($output, $headers);
            foreach ($users_data as $row) {
                fputcsv($output, $row);
            }
        } else {
            fputcsv($output, ['No data found for selected criteria']);
        }

        fclose($output);
        exit;
    }
}