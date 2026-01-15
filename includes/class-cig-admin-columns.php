<?php
/**
 * Admin columns customization
 *
 * @package CIG
 * @since 4.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CIG Admin Columns Class
 */
class CIG_Admin_Columns {

    /** @var CIG_Stock_Manager */
    private $stock;

    /**
     * Constructor
     *
     * @param CIG_Stock_Manager|null $stock
     */
    public function __construct($stock = null) {
        $this->stock = $stock ?: (function_exists('CIG') ? CIG()->stock : null);

        // --- Invoice columns ---
        add_filter('manage_invoice_posts_columns', [$this, 'invoice_columns']);
        add_action('manage_invoice_posts_custom_column', [$this, 'invoice_column_content'], 10, 2);
        add_filter('post_row_actions', [$this, 'invoice_row_actions'], 10, 2);
        add_filter('manage_edit-invoice_sortable_columns', [$this, 'invoice_sortable_columns']);

        // --- Product columns ---
        add_filter('manage_edit-product_columns', [$this, 'product_columns'], 15);
        add_action('manage_product_posts_custom_column', [$this, 'product_column_content'], 10, 2);

        // --- Customer (Business Users) columns (NEW) ---
        add_filter('manage_cig_customer_posts_columns', [$this, 'customer_columns']);
        add_action('manage_cig_customer_posts_custom_column', [$this, 'customer_column_content'], 10, 2);

        // --- Filters ---
        add_action('restrict_manage_posts', [$this, 'add_invoice_filters']);
        add_filter('parse_query', [$this, 'apply_invoice_filters']);
    }

    /**
     * 1. INVOICE COLUMNS
     */
    public function invoice_columns($columns) {
        $new_columns = [];
        $new_columns['cb'] = $columns['cb'];
        $new_columns['title'] = __('Title', 'cig');
        $new_columns['cig_invoice_number'] = __('Invoice #', 'cig');
        $new_columns['cig_type'] = __('Type', 'cig'); // Status Badge (Active/Fictive)
        $new_columns['cig_state'] = __('State', 'cig'); // NEW: Lifecycle Status
        $new_columns['cig_buyer_name'] = __('Buyer', 'cig');
        $new_columns['cig_total'] = __('Total', 'cig');
        $new_columns['cig_paid'] = __('Paid', 'cig');
        $new_columns['cig_products'] = __('Products', 'cig');
        $new_columns['cig_rs_status'] = __('RS.ge', 'cig'); 
        $new_columns['cig_author'] = __('Created By', 'cig');
        $new_columns['date'] = __('Date', 'cig');

        return $new_columns;
    }

    public function invoice_sortable_columns($columns) {
        $columns['cig_total'] = 'cig_total';
        return $columns;
    }

    public function invoice_column_content($column, $post_id) {
        switch ($column) {
            case 'cig_invoice_number':
                echo esc_html(get_post_meta($post_id, '_cig_invoice_number', true));
                break;

            case 'cig_type':
                $status = get_post_meta($post_id, '_cig_invoice_status', true) ?: 'standard';
                if ($status === 'fictive') {
                    echo '<span style="background:#dc3545; color:#fff; padding:2px 6px; border-radius:3px; font-size:10px; font-weight:bold;">FICTIVE</span>';
                } else {
                    echo '<span style="background:#28a745; color:#fff; padding:2px 6px; border-radius:3px; font-size:10px; font-weight:bold;">ACTIVE</span>';
                }
                break;

            case 'cig_state': // NEW: Lifecycle Status Display
                $state = get_post_meta($post_id, '_cig_lifecycle_status', true) ?: 'unfinished';
                
                $labels = [
                    'completed'  => ['label' => __('Completed', 'cig'),  'color' => '#155724', 'bg' => '#d4edda'],
                    'reserved'   => ['label' => __('Reserved', 'cig'),   'color' => '#856404', 'bg' => '#fff3cd'],
                    'unfinished' => ['label' => __('Unfinished', 'cig'), 'color' => '#383d41', 'bg' => '#e2e3e5'],
                ];

                $s = isset($labels[$state]) ? $labels[$state] : $labels['unfinished'];

                printf(
                    '<span style="background:%s; color:%s; padding:3px 8px; border-radius:3px; font-size:11px; font-weight:600;">%s</span>',
                    esc_attr($s['bg']),
                    esc_attr($s['color']),
                    esc_html($s['label'])
                );
                break;

            case 'cig_buyer_name':
                $name = get_post_meta($post_id, '_cig_buyer_name', true);
                $tax = get_post_meta($post_id, '_cig_buyer_tax_id', true);
                echo '<strong>' . esc_html($name ?: '—') . '</strong>';
                if ($tax) echo '<br><small style="color:#777;">ID: ' . esc_html($tax) . '</small>';
                break;

            case 'cig_total':
                $total = get_post_meta($post_id, '_cig_invoice_total', true);
                echo '<strong>' . number_format(floatval($total), 2) . ' ₾</strong>';
                break;

            case 'cig_paid': 
                $paid = (float) get_post_meta($post_id, '_cig_payment_paid_amount', true);
                $total = (float) get_post_meta($post_id, '_cig_invoice_total', true);
                $remaining = max(0, $total - $paid);
                
                if ($paid <= 0) {
                    echo '<span style="color:#999;">-</span>';
                } elseif ($remaining < 0.01) {
                    echo '<span style="color:#28a745; font-weight:bold;">' . number_format($paid, 2) . ' ₾</span>';
                } else {
                    echo '<span style="color:#e0a800;">' . number_format($paid, 2) . ' ₾</span>';
                    echo '<div style="font-size:10px; color:#dc3545;">Due: ' . number_format($remaining, 2) . '</div>';
                }
                break;

            case 'cig_rs_status':
                $is_uploaded = get_post_meta($post_id, '_cig_rs_uploaded', true) === 'yes';
                if ($is_uploaded) {
                    echo '<span class="dashicons dashicons-cloud-saved" style="color:#28a745;" title="Uploaded to RS"></span>';
                } else {
                    echo '<span class="dashicons dashicons-cloud" style="color:#ddd;" title="Not Uploaded"></span>';
                }
                break;

            case 'cig_products':
                $items = get_post_meta($post_id, '_cig_items', true);
                echo is_array($items) ? esc_html((string) count($items)) : '0';
                break;

            case 'cig_author':
                $post   = get_post($post_id);
                $author = $post ? get_userdata($post->post_author) : null;
                if ($author) {
                    echo esc_html($author->display_name);
                }
                break;
        }
    }

    /**
     * 2. CUSTOMER COLUMNS
     */
    public function customer_columns($columns) {
        $new = [];
        $new['cb'] = $columns['cb'];
        $new['title'] = __('Company Name', 'cig');
        $new['cig_cust_tax'] = __('Tax ID (ს/კ)', 'cig');
        $new['cig_cust_phone'] = __('Phone', 'cig');
        $new['cig_cust_count'] = __('Invoices', 'cig');
        $new['cig_cust_revenue'] = __('Total Revenue', 'cig');
        $new['cig_cust_paid'] = __('Total Paid', 'cig');
        $new['cig_cust_balance'] = __('Balance (Due)', 'cig');
        $new['date'] = __('Created', 'cig');
        return $new;
    }

    public function customer_column_content($column, $post_id) {
        switch ($column) {
            case 'cig_cust_tax':
                echo esc_html(get_post_meta($post_id, '_cig_customer_tax_id', true) ?: '—');
                break;

            case 'cig_cust_phone':
                echo esc_html(get_post_meta($post_id, '_cig_customer_phone', true) ?: '—');
                break;

            case 'cig_cust_count':
            case 'cig_cust_revenue':
            case 'cig_cust_paid':
            case 'cig_cust_balance':
                $stats = $this->get_customer_stats($post_id);
                
                if ($column === 'cig_cust_count') {
                    echo '<strong>' . intval($stats['count']) . '</strong>';
                } elseif ($column === 'cig_cust_revenue') {
                    echo number_format($stats['revenue'], 2) . ' ₾';
                } elseif ($column === 'cig_cust_paid') {
                    echo '<span style="color:#28a745;">' . number_format($stats['paid'], 2) . ' ₾</span>';
                } elseif ($column === 'cig_cust_balance') {
                    $bal = $stats['revenue'] - $stats['paid'];
                    if ($bal > 0.01) {
                        echo '<strong style="color:#dc3545;">' . number_format($bal, 2) . ' ₾</strong>';
                    } else {
                        echo '<span style="color:#999;">0.00</span>';
                    }
                }
                break;
        }
    }

    /**
     * Helper: Calculate stats for a customer (Cached per request)
     */
    private function get_customer_stats($customer_id) {
        static $cache = [];
        if (isset($cache[$customer_id])) {
            return $cache[$customer_id];
        }

        $args = [
            'post_type'      => 'invoice',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_cig_customer_id',
                    'value'   => $customer_id,
                    'compare' => '='
                ],
                [
                    'relation' => 'OR',
                    ['key' => '_cig_invoice_status', 'value' => 'standard', 'compare' => '='],
                    ['key' => '_cig_invoice_status', 'compare' => 'NOT EXISTS']
                ]
            ]
        ];

        $query = new WP_Query($args);
        
        $stats = [
            'count'   => 0,
            'revenue' => 0,
            'paid'    => 0
        ];

        if ($query->have_posts()) {
            $stats['count'] = count($query->posts);
            foreach ($query->posts as $inv_id) {
                $total = (float) get_post_meta($inv_id, '_cig_invoice_total', true);
                $paid  = (float) get_post_meta($inv_id, '_cig_payment_paid_amount', true);
                
                $stats['revenue'] += $total;
                $stats['paid']    += $paid;
            }
        }

        $cache[$customer_id] = $stats;
        return $stats;
    }

    /**
     * 3. INVOICE ROW ACTIONS
     */
    public function invoice_row_actions($actions, $post) {
        if ($post->post_type !== 'invoice') {
            return $actions;
        }

        $view_url = get_permalink($post);

        $actions['cig_view'] = sprintf(
            '<a href="%s" target="_blank">%s</a>',
            esc_url($view_url),
            esc_html__('View', 'cig')
        );

        $actions['cig_edit_front'] = sprintf(
            '<a href="%s" target="_blank">%s</a>',
            esc_url(add_query_arg('edit', '1', $view_url)),
            esc_html__('Front Edit', 'cig')
        );

        return $actions;
    }

    /**
     * 4. PRODUCT COLUMNS (Reserved Stock)
     */
    public function product_columns($columns) {
        $new_columns = [];
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'is_in_stock') {
                $new_columns['reserved_stock'] = __('Reserved', 'cig');
            }
        }
        return $new_columns;
    }

    public function product_column_content($column, $post_id) {
        if ($column !== 'reserved_stock') {
            return;
        }

        $stock_manager = $this->stock ?: (function_exists('CIG') ? CIG()->stock : null);
        $reserved      = $stock_manager ? $stock_manager->get_reserved($post_id) : 0;

        if ($reserved > 0) {
            echo '<span style="color:#d63638;font-weight:bold;">' .
                 esc_html(number_format($reserved, 0)) .
                 '</span>';

            $reserved_meta = get_post_meta($post_id, '_cig_reserved_stock', true);
            if (is_array($reserved_meta)) {
                $now  = current_time('timestamp');
                $soon = $now + (7 * DAY_IN_SECONDS);

                foreach ($reserved_meta as $data) {
                    if (!empty($data['expires'])) {
                        $exp_time = strtotime($data['expires']);
                        if ($exp_time < $soon && $exp_time > $now) {
                            echo '<br><small style="color:#ff9800;">' . esc_html__('⚠ Expiring soon', 'cig') . '</small>';
                            break;
                        }
                    }
                }
            }
        } else {
            echo '—';
        }
    }

    /**
     * 5. FILTERS (Invoices)
     */
    public function add_invoice_filters($post_type) {
        if ($post_type !== 'invoice') {
            return;
        }

        // Payment Filter
        $payment_types = CIG_Invoice::get_payment_types();
        $current_pay = isset($_GET['payment_type']) ? sanitize_text_field(wp_unslash($_GET['payment_type'])) : '';

        echo '<select name="payment_type">';
        echo '<option value="">' . esc_html__('All Payment Types', 'cig') . '</option>';
        foreach ($payment_types as $key => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($key), selected($current_pay, $key, false), esc_html($label));
        }
        echo '</select>';

        // Type Filter (Standard/Fictive)
        $current_status = isset($_GET['cig_status_filter']) ? sanitize_text_field(wp_unslash($_GET['cig_status_filter'])) : '';
        echo '<select name="cig_status_filter">';
        echo '<option value="">' . esc_html__('All Types', 'cig') . '</option>';
        echo '<option value="standard" ' . selected($current_status, 'standard', false) . '>' . esc_html__('Active Only', 'cig') . '</option>';
        echo '<option value="fictive" ' . selected($current_status, 'fictive', false) . '>' . esc_html__('Fictive Only', 'cig') . '</option>';
        echo '</select>';

        // NEW: State Filter (Lifecycle)
        $current_state = isset($_GET['cig_lifecycle_filter']) ? sanitize_text_field(wp_unslash($_GET['cig_lifecycle_filter'])) : '';
        echo '<select name="cig_lifecycle_filter">';
        echo '<option value="">' . esc_html__('All States', 'cig') . '</option>';
        echo '<option value="completed" ' . selected($current_state, 'completed', false) . '>' . esc_html__('Completed', 'cig') . '</option>';
        echo '<option value="reserved" ' . selected($current_state, 'reserved', false) . '>' . esc_html__('Reserved', 'cig') . '</option>';
        echo '<option value="unfinished" ' . selected($current_state, 'unfinished', false) . '>' . esc_html__('Unfinished', 'cig') . '</option>';
        echo '</select>';
    }

    public function apply_invoice_filters($query) {
        global $pagenow, $post_type;

        if ($pagenow !== 'edit.php' || $post_type !== 'invoice' || !is_admin()) {
            return;
        }

        $meta_query = [];

        if (isset($_GET['payment_type']) && !empty($_GET['payment_type'])) {
            $meta_query[] = [
                'key'     => '_cig_payment_type',
                'value'   => sanitize_text_field(wp_unslash($_GET['payment_type'])),
                'compare' => '='
            ];
        }

        if (isset($_GET['cig_status_filter']) && !empty($_GET['cig_status_filter'])) {
            $meta_query[] = [
                'key'     => '_cig_invoice_status',
                'value'   => sanitize_text_field(wp_unslash($_GET['cig_status_filter'])),
                'compare' => '='
            ];
        }

        // NEW: State Filter Logic
        if (isset($_GET['cig_lifecycle_filter']) && !empty($_GET['cig_lifecycle_filter'])) {
            $meta_query[] = [
                'key'     => '_cig_lifecycle_status',
                'value'   => sanitize_text_field(wp_unslash($_GET['cig_lifecycle_filter'])),
                'compare' => '='
            ];
        }

        if (!empty($meta_query)) {
            $current_meta = $query->get('meta_query');
            if (!is_array($current_meta)) {
                $current_meta = [];
            }
            $query->set('meta_query', array_merge($current_meta, $meta_query));
        }
    }
}