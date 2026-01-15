<?php
/**
 * Customer Management Handler
 *
 * @package CIG
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CIG_Customers {

    private $post_type = 'cig_customer';

    public function __construct() {
        add_action('init', [$this, 'register_post_type']);
        
        // AJAX Search for Autocomplete
        add_action('wp_ajax_cig_search_customers', [$this, 'ajax_search_customers']);
    }

    /**
     * Register Customer Post Type
     */
    public function register_post_type() {
        register_post_type($this->post_type, [
            'labels' => [
                'name'               => __('Customers', 'cig'),
                'singular_name'      => __('Customer', 'cig'),
                'menu_name'          => __('Customers (CIG)', 'cig'),
                'add_new_item'       => __('Add New Customer', 'cig'),
                'edit_item'          => __('Edit Customer', 'cig'),
                'search_items'       => __('Search Customers', 'cig'),
                'not_found'          => __('No customers found', 'cig'),
            ],
            'public'             => false,
            'show_ui'            => true, // Show in Admin Menu
            'show_in_menu'       => 'edit.php?post_type=invoice', // Submenu of Invoices
            'supports'           => ['title'], // Title = Buyer Name
            'capability_type'    => 'post',
            'map_meta_cap'       => true,
            'menu_icon'          => 'dashicons-businessperson',
        ]);
    }

    /**
     * Create or Update Customer from Invoice Data
     * * @param array $buyer_data [name, tax_id, address, phone, email]
     * @return int|false Customer Post ID or false
     */
    public function sync_customer($buyer_data) {
        $tax_id = sanitize_text_field($buyer_data['tax_id'] ?? '');
        $name   = sanitize_text_field($buyer_data['name'] ?? '');

        // Validation: Tax ID and Name are mandatory
        if (empty($tax_id) || empty($name)) {
            return false;
        }

        // 1. Check if customer exists by Tax ID
        $existing_id = $this->get_customer_id_by_tax_id($tax_id);

        $post_args = [
            'post_type'   => $this->post_type,
            'post_title'  => $name,
            'post_status' => 'publish',
        ];

        if ($existing_id) {
            // UPDATE existing
            $post_args['ID'] = $existing_id;
            $customer_id = wp_update_post($post_args);
        } else {
            // CREATE new
            $customer_id = wp_insert_post($post_args);
        }

        if (is_wp_error($customer_id) || !$customer_id) {
            return false;
        }

        // 2. Save/Update Meta Data
        update_post_meta($customer_id, '_cig_customer_tax_id', $tax_id);
        update_post_meta($customer_id, '_cig_customer_address', sanitize_text_field($buyer_data['address'] ?? ''));
        update_post_meta($customer_id, '_cig_customer_phone', sanitize_text_field($buyer_data['phone'] ?? ''));
        update_post_meta($customer_id, '_cig_customer_email', sanitize_email($buyer_data['email'] ?? ''));

        return $customer_id;
    }

    /**
     * Find customer ID by Tax ID
     */
    public function get_customer_id_by_tax_id($tax_id) {
        $query = new WP_Query([
            'post_type'      => $this->post_type,
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'     => '_cig_customer_tax_id',
                    'value'   => $tax_id,
                    'compare' => '='
                ]
            ],
            'fields' => 'ids'
        ]);

        return $query->have_posts() ? $query->posts[0] : false;
    }

    /**
     * AJAX: Search Customers (Name or Tax ID)
     */
    public function ajax_search_customers() {
        check_ajax_referer('cig_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $term = isset($_POST['term']) ? sanitize_text_field($_POST['term']) : '';
        if (strlen($term) < 2) wp_send_json_success([]);

        // Search by Title (Name) OR Meta (Tax ID)
        $args = [
            'post_type'      => $this->post_type,
            'post_status'    => 'publish',
            'posts_per_page' => 10,
            's'              => $term // Standard title/content search
        ];

        // Also search in Tax ID via meta query if 's' doesn't catch it well, 
        // OR better: use custom SQL filter for "Title OR TaxID" like we did for products.
        // For simplicity & performance, let's try a direct approach:
        
        add_filter('posts_where', [$this, 'filter_search_where']);
        $query = new WP_Query($args);
        remove_filter('posts_where', [$this, 'filter_search_where']);

        $results = [];
        foreach ($query->posts as $post) {
            $id = $post->ID;
            $tax_id = get_post_meta($id, '_cig_customer_tax_id', true);
            $phone  = get_post_meta($id, '_cig_customer_phone', true);
            
            $results[] = [
                'id'      => $id,
                'label'   => $post->post_title . ' (ს/კ: ' . $tax_id . ')',
                'value'   => $post->post_title, // Input gets the name
                'tax_id'  => $tax_id,
                'address' => get_post_meta($id, '_cig_customer_address', true),
                'phone'   => $phone,
                'email'   => get_post_meta($id, '_cig_customer_email', true),
            ];
        }

        wp_send_json($results);
    }

    /**
     * Custom SQL filter to search in Tax ID too
     */
    public function filter_search_where($where) {
        global $wpdb;
        // This relies on the 's' parameter being present in the query
        if (strpos($where, 'post_title LIKE') !== false) {
            $search_term = $_POST['term'] ?? ''; // Direct access as context is known
            if ($search_term) {
                $like = '%' . $wpdb->esc_like($search_term) . '%';
                // Extend WHERE to include meta_value for tax_id
                $where .= $wpdb->prepare(" OR (
                    EXISTS (
                        SELECT 1 FROM {$wpdb->postmeta} 
                        WHERE post_id = {$wpdb->posts}.ID 
                        AND meta_key = '_cig_customer_tax_id' 
                        AND meta_value LIKE %s
                    )
                )", $like);
            }
        }
        return $where;
    }
}