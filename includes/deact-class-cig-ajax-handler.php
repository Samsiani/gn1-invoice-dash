<?php
/**
 * AJAX request handler
 *
 * @package CIG
 * @since 4.7.2
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CIG AJAX Handler Class
 */
class CIG_Ajax_Handler {

    /** @var CIG_Invoice */
    private $invoice;

    /** @var CIG_Stock_Manager */
    private $stock;

    /** @var CIG_Validator */
    private $validator;

    /** @var CIG_Security */
    private $security;

    public function __construct($invoice = null, $stock = null, $validator = null, $security = null) {
        $this->invoice   = $invoice   ?: (function_exists('CIG') ? CIG()->invoice   : null);
        $this->stock     = $stock     ?: (function_exists('CIG') ? CIG()->stock     : null);
        $this->validator = $validator ?: (function_exists('CIG') ? CIG()->validator : null);
        $this->security  = $security  ?: (function_exists('CIG') ? CIG()->security  : null);

        // Product Search & Stock
        add_action('wp_ajax_cig_search_products',        [$this, 'search_products']);
        add_action('wp_ajax_cig_check_stock',            [$this, 'check_stock']);
        
        // Invoice CRUD
        add_action('wp_ajax_cig_save_invoice',           [$this, 'save_invoice']);
        add_action('wp_ajax_cig_update_invoice',         [$this, 'update_invoice']);
        add_action('wp_ajax_cig_next_invoice_number',    [$this, 'next_invoice_number']);
        add_action('wp_ajax_cig_toggle_invoice_status',    [$this, 'toggle_invoice_status']);
        
        // NEW: Mark as Sold Action
        add_action('wp_ajax_cig_mark_as_sold',           [$this, 'mark_as_sold']);

        // Products Table (Stock Overview & Requests)
        add_action('wp_ajax_cig_search_products_table',  [$this, 'search_products_table']);
        add_action('wp_ajax_nopriv_cig_search_products_table', [$this, 'search_products_table']);
        add_action('wp_ajax_cig_submit_stock_request',   [$this, 'submit_stock_request']); 

        // Statistics & Reporting
        add_action('wp_ajax_cig_get_statistics_summary', [$this, 'get_statistics_summary']);
        add_action('wp_ajax_cig_get_users_statistics',   [$this, 'get_users_statistics']);
        add_action('wp_ajax_cig_get_user_invoices',      [$this, 'get_user_invoices']);
        add_action('wp_ajax_cig_export_statistics',      [$this, 'export_statistics']);
        add_action('wp_ajax_cig_get_product_insight',    [$this, 'get_product_insight']);
        
        // Customer Insights
        add_action('wp_ajax_cig_get_customer_insights', [$this, 'get_customer_insights']);
        add_action('wp_ajax_cig_get_customer_invoices_details', [$this, 'get_customer_invoices_details']);

        // Mini Dashboard
        add_action('wp_ajax_cig_get_my_invoices',        [$this, 'get_my_invoices']);
        add_action('wp_ajax_cig_get_expiring_reservations', [$this, 'get_expiring_reservations']);

        // Summary Dropdowns
        add_action('wp_ajax_cig_get_invoices_by_filters',  [$this, 'get_invoices_by_filters']);
        add_action('wp_ajax_cig_get_products_by_filters',  [$this, 'get_products_by_filters']);
        
        // Accountant
        add_action('wp_ajax_cig_get_accountant_invoices', [$this, 'get_invoices_ajax']);
        add_action('wp_ajax_cig_toggle_rs_status', [$this, 'toggle_rs_status']);
    }

    /**
     * Helper: Generate Meta Query for Invoice Status
     */
    private function get_status_meta_query($status) {
        if ($status === 'all') return [];
        if ($status === 'fictive') return [['key' => '_cig_invoice_status', 'value' => 'fictive', 'compare' => '=']];
        
        if ($status === 'outstanding') {
             return [
                 'relation' => 'AND',
                 ['relation' => 'OR', ['key' => '_cig_invoice_status', 'value' => 'standard', 'compare' => '='], ['key' => '_cig_invoice_status', 'compare' => 'NOT EXISTS']],
                 ['key' => '_cig_payment_remaining_amount', 'value' => 0.001, 'compare' => '>', 'type' => 'DECIMAL']
             ];
        }
        
        // Standard (Active)
        return [['relation' => 'OR', ['key' => '_cig_invoice_status', 'value' => 'standard', 'compare' => '='], ['key' => '_cig_invoice_status', 'compare' => 'NOT EXISTS']]];
    }

    /**
     * Product Search
     */
    public function search_products() {
        $this->security && $this->security->verify_ajax_request('cig_nonce', 'nonce', 'manage_woocommerce');
        
        if (!class_exists('WC_Product')) wp_send_json_success([]);
        
        $term = isset($_POST['term']) ? sanitize_text_field(wp_unslash($_POST['term'])) : '';
        if ($term === '') wp_send_json_success([]);

        $args = [
            'post_type'      => ['product', 'product_variation'],
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'fields'         => 'ids',
        ];

        $filter_handler = function($clauses) use ($term) {
            global $wpdb;
            $clauses['join'] .= " LEFT JOIN {$wpdb->postmeta} as sku_meta_search ON ({$wpdb->posts}.ID = sku_meta_search.post_id AND sku_meta_search.meta_key = '_sku') ";
            $clauses['where'] .= " AND (sku_meta_search.meta_value IS NULL OR sku_meta_search.meta_value NOT REGEXP '^GN20ST') ";
            if (!empty($term)) {
                $like = '%' . $wpdb->esc_like($term) . '%';
                $clauses['where'] .= $wpdb->prepare(" AND (({$wpdb->posts}.post_title LIKE %s) OR (sku_meta_search.meta_value LIKE %s))", $like, $like);
            }
            return $clauses;
        };

        add_filter('posts_clauses', $filter_handler);
        $query = new WP_Query($args);
        remove_filter('posts_clauses', $filter_handler);

        $results = [];
        $seen = [];

        foreach ($query->posts as $pid) {
            $prod_id = $pid;
            if (!$prod_id || isset($seen[$prod_id])) continue;
            
            $payload = $this->build_product_payload($prod_id);
            if ($payload) {
                $results[] = $payload;
                $seen[$prod_id] = true;
            }
        }
        
        wp_send_json($results);
    }

    /**
     * Helper for product payload - ატრიბუტების სწორი დამუშავება
     */
    private function build_product_payload($pid) {
        $p = wc_get_product($pid); 
        if (!$p) return null;

        $sets = get_option('cig_settings', []); 
        $br = $sets['brand_attribute'] ?? 'pa_prod-brand';
        $excludes = $sets['exclude_spec_attributes'] ?? ['pa_prod-brand', 'pa_product-condition'];
        
        // ბრენდის მიღება
        $brand = ''; 
        if ($br) { 
            $ts = wp_get_post_terms($p->get_parent_id() ?: $pid, $br, ['fields' => 'names']); 
            if (!is_wp_error($ts) && !empty($ts)) $brand = $ts[0]; 
        }

        // --- ატრიბუტების დამუშავების ლოგიკა ---
        $lines = [];
        $attributes = $p->get_attributes();
        
        if (!empty($attributes)) {
            foreach ($attributes as $attr) {
                if (!is_a($attr, 'WC_Product_Attribute')) continue;
                
                $tax_slug = taxonomy_exists($attr->get_name()) ? $attr->get_name() : sanitize_title($attr->get_name());
                
                // გამოვრიცხავთ ბრენდს და სხვა მონიშნულ ატრიბუტებს (რადგან ბრენდი ცალკე სვეტშია)
                if (in_array($tax_slug, (array)$excludes, true)) continue;

                $label = wc_attribute_label($attr->get_name());
                $values = $attr->is_taxonomy() ? 
                    wp_get_post_terms($p->get_parent_id() ?: $p->get_id(), $attr->get_name(), ['fields' => 'names']) : 
                    $attr->get_options();

                if (!is_wp_error($values) && !empty($values)) {
                    $lines[] = '• ' . $label . ': ' . implode(', ', (array)$values);
                }
            }
        }

        // თუ ატრიბუტები არსებობს, ვიყენებთ მათ სიას, თუ არა - ჩვეულებრივ ტექსტურ აღწერას
        $desc = !empty($lines) ? implode("\n", $lines) : wp_strip_all_tags(get_post($p->get_parent_id() ?: $pid)->post_content ?? '');
        // ------------------------------------

        $sq = $p->get_stock_quantity(); 
        $res = $this->stock->get_reserved($pid); 
        $av = ($sq !== null && $sq !== '') ? max(0, $sq - $res) : null;
        
        $iid = $p->get_image_id(); 
        $img = ''; 
        if ($iid) { 
            $s = wp_get_attachment_image_src($iid, 'medium'); 
            if ($s) $img = $s[0]; 
        }

        return [
            'id'        => $pid, 
            'label'     => $p->get_name() . ' (SKU: ' . ($p->get_sku() ?: 'N/A') . ')', 
            'value'     => $p->get_name(), 
            'sku'       => $p->get_sku(), 
            'brand'     => $brand, 
            'desc'      => $desc, // აქ უკვე ატრიბუტების დაფორმატებული სიაა
            'price'     => floatval($p->get_price() ?: 0), 
            'image'     => $img, 
            'stock'     => $sq, 
            'reserved'  => $res, 
            'available' => $av
        ];
    }

    public function check_stock() {
        $this->security && $this->security->verify_ajax_request('cig_nonce', 'nonce', 'manage_woocommerce');
        $pid=intval($_POST['product_id']); $q=floatval($_POST['quantity']); $inv=intval($_POST['invoice_id']);
        $av=$this->stock->get_available($pid, $inv);
        $prod = wc_get_product($pid);
        if($prod->get_stock_quantity() === null) wp_send_json_success(['available'=>9999, 'can_add'=>true]);
        $can=$q<=$av;
        wp_send_json_success(['available'=>max(0,$av), 'can_add'=>$can, 'message'=>$can?sprintf(__('%s available','cig'),$av):sprintf(__('Only %s available','cig'),$av)]);
    }

    public function save_invoice() { $this->process_invoice_save(false); }
    public function update_invoice() { $this->process_invoice_save(true); }

    private function process_invoice_save($update) {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'manage_woocommerce');
        $d = json_decode(wp_unslash($_POST['payload']??''), true);
        
        // Security Check for Completed Invoices
        if ($update) {
            $id = intval($d['invoice_id']);
            $current_lifecycle = get_post_meta($id, '_cig_lifecycle_status', true);
            if ($current_lifecycle === 'completed' && !current_user_can('administrator')) {
                wp_send_json_error(['message' => 'დასრულებული ინვოისის რედაქტირება აკრძალულია.'], 403);
            }
        }

        $buyer = $d['buyer']??[];
        if(empty($buyer['name']) || empty($buyer['tax_id']) || empty($buyer['phone'])) wp_send_json_error(['message'=>'შეავსეთ მყიდველის სახელი, ს/კ და ტელეფონი.'],400);
        $num = sanitize_text_field($d['invoice_number']??'');
        $st = sanitize_text_field($d['status']??'standard');
        $items = array_filter((array)($d['items']??[]), function($r){ return !empty($r['name']); });
        
        $hist = $d['payment']['history']??[]; $paid=0; foreach($hist as $h) $paid+=floatval($h['amount']);
        if($st==='fictive' && $paid>0.001) wp_send_json_error(['message'=>'გადახდილი ინვოისი ვერ იქნება ფიქტიური.'],400);
        if(empty($items)) wp_send_json_error(['message'=>'დაამატეთ პროდუქტები'],400);
        
        if($update) {
            $id = intval($d['invoice_id']);
            if($st==='standard'){ $err=$this->stock->validate_stock($items,$id); if($err) wp_send_json_error(['message'=>'Stock error','errors'=>$err],400); }
            $old_num=get_post_meta($id,'_cig_invoice_number',true);
            $new_num=CIG_Invoice::ensure_unique_number($num, $id);
            wp_update_post(['ID'=>$id, 'post_title'=>'Invoice #'.$new_num, 'post_modified'=>current_time('mysql')]);
            $pid=$id;
        } else {
            if($st==='standard'){ $err=$this->stock->validate_stock($items,0); if($err) wp_send_json_error(['message'=>'Stock error','errors'=>$err],400); }
            $new_num=CIG_Invoice::ensure_unique_number($num);
            $pid=wp_insert_post(['post_type'=>'invoice','post_status'=>'publish','post_title'=>'Invoice #'.$new_num, 'post_author'=>get_current_user_id()]);
        }
        
        update_post_meta($pid, '_wp_page_template', 'elementor_canvas');
        update_post_meta($pid, '_cig_invoice_status', $st);
        
        if(function_exists('CIG') && isset(CIG()->customers)){ 
            $cid=CIG()->customers->sync_customer($buyer); 
            if($cid) update_post_meta($pid, '_cig_customer_id', $cid); 
        }
        
        CIG_Invoice::save_meta($pid, $new_num, (array)($d['buyer']??[]), $items, (array)($d['payment']??[]));
        
        $old_items = $update ? (get_post_meta($pid, '_cig_items', true)?:[]) : [];
        $this->stock->update_invoice_reservations($pid, $old_items, ($st==='fictive'?[]:$items)); 
        
        wp_send_json_success(['post_id'=>$pid, 'view_url'=>get_permalink($pid), 'invoice_number'=>$new_num]);
    }

    /**
     * Mark all reserved items as sold
     */
    public function mark_as_sold() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'manage_woocommerce');
        
        $id = intval($_POST['invoice_id']);
        if (!$id || get_post_type($id) !== 'invoice') {
            wp_send_json_error(['message' => 'Invalid invoice']);
        }

        $items = get_post_meta($id, '_cig_items', true) ?: [];
        $old_items = $items; 
        $updated_items = [];
        $has_change = false;

        foreach ($items as $item) {
            if (isset($item['status']) && $item['status'] === 'reserved') {
                $item['status'] = 'sold';
                $item['reservation_days'] = 0;
                
                $prod_id = intval($item['product_id']);
                $qty = floatval($item['qty']);
                $product = wc_get_product($prod_id);
                
                if ($product && $product->managing_stock()) {
                    $current_stock = $product->get_stock_quantity();
                    $new_stock = max(0, $current_stock - $qty);
                    $product->set_stock_quantity($new_stock);
                    $product->save();
                }
                $has_change = true;
            }
            $updated_items[] = $item;
        }

        if ($has_change) {
            // !!! აუცილებელი ხაზი: განახლებული სტატუსების შენახვა ბაზაში !!!
            update_post_meta($id, '_cig_items', $updated_items);
            
            // რეზერვაციის ცხრილის განახლება
            $this->stock->update_invoice_reservations($id, $old_items, $updated_items);
            
            // ინვოისის დასრულებულად მონიშვნა
            update_post_meta($id, '_cig_lifecycle_status', 'completed');

            wp_send_json_success(['message' => 'Invoice marked as sold successfully.']);
        } else {
            wp_send_json_error(['message' => 'No reserved items found to mark as sold.']);
        }
    }

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
            if ($err) wp_send_json_error(['message' => 'Stock error', 'errors' => $err]);
        }

        $ost = get_post_meta($id, '_cig_invoice_status', true) ?: 'standard';
        
        // 1. განვაახლოთ სტატუსი ბაზაში
        update_post_meta($id, '_cig_invoice_status', $nst);
        
        // 2. წავშალოთ შესაბამისი ქეში, რომ ცვლილება მყისიერად გამოჩნდეს
        if ($this->cache) {
            $this->cache->delete('statistics_summary');
            $author_id = get_post_field('post_author', $id);
            $this->cache->delete('user_invoices_' . $author_id);
        }

        // 3. განვაახლოთ რეზერვაციები
        $this->stock->update_invoice_reservations($id, ($ost === 'fictive' ? [] : $items), ($nst === 'fictive' ? [] : $items));
        
        // 4. ვაიძულოთ პოსტის მოდიფიკაციის თარიღის განახლება
        wp_update_post([
            'ID'            => $id, 
            'post_modified' => current_time('mysql'),
            'post_modified_gmt' => current_time('mysql', 1)
        ]);
        
        wp_send_json_success();
    }

    public function next_invoice_number() { wp_send_json_success(['next'=>CIG_Invoice::get_next_number()]); }

    // --- STATISTICS SUMMARY (Restored) ---
    public function get_statistics_summary() {
        $this->security && $this->security->verify_ajax_request('cig_nonce', 'nonce', 'edit_posts');
        
        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to   = sanitize_text_field($_POST['date_to'] ?? '');
        $status    = sanitize_text_field($_POST['status'] ?? 'standard');

        $args = [
            'post_type'      => 'invoice',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids'
        ];

        if ($date_from && $date_to) {
            $args['date_query'] = [[
                'after'     => $date_from . ' 00:00:00',
                'before'    => $date_to . ' 23:59:59',
                'inclusive' => true
            ]];
        }

        $meta_query = $this->get_status_meta_query($status);
        if ($meta_query) $args['meta_query'] = $meta_query;

        $query = new WP_Query($args);
        $ids = $query->posts;

        $stats = [
            'total_invoices'         => count($ids),
            'total_revenue'          => 0.0,
            'total_paid'             => 0.0,
            'total_outstanding'      => 0.0,
            'total_company_transfer' => 0.0,
            'total_cash'             => 0.0,
            'total_consignment'      => 0.0,
            'total_credit'           => 0.0,
            'total_other'            => 0.0,
            'total_sold'             => 0,
            'total_reserved'         => 0
        ];

        foreach ($ids as $id) {
            $inv_total = (float) get_post_meta($id, '_cig_invoice_total', true);
            $inv_paid  = (float) get_post_meta($id, '_cig_payment_paid_amount', true);
            
            $stats['total_revenue'] += $inv_total;
            $stats['total_paid']    += $inv_paid;

            $history = get_post_meta($id, '_cig_payment_history', true);
            if (is_array($history)) {
                foreach ($history as $pay) {
                    $amt = (float) ($pay['amount'] ?? 0);
                    $method = $pay['method'] ?? 'other';
                    if (isset($stats['total_' . $method])) {
                        $stats['total_' . $method] += $amt;
                    } else {
                        $stats['total_other'] += $amt;
                    }
                }
            }

            $items = get_post_meta($id, '_cig_items', true) ?: [];
            foreach ($items as $it) {
                $q = floatval($it['qty'] ?? 0);
                $st = strtolower($it['status'] ?? 'sold');
                if ($st === 'sold') $stats['total_sold'] += $q; 
                elseif ($st === 'reserved') $stats['total_reserved'] += $q;
            }
        }

        $stats['total_outstanding'] = max(0, $stats['total_revenue'] - $stats['total_paid']);
        wp_send_json_success($stats);
    }

    // --- USERS STATISTICS (Restored) ---
    public function get_users_statistics() {
        $this->security && $this->security->verify_ajax_request('cig_nonce', 'nonce', 'edit_posts');
        $status = sanitize_text_field($_POST['status'] ?? 'standard');
        $search = sanitize_text_field($_POST['search'] ?? '');
        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to = sanitize_text_field($_POST['date_to'] ?? '');
        $args = ['post_type' => 'invoice', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids'];
        if ($date_from && $date_to) $args['date_query'] = [['after' => $date_from . ' 00:00:00', 'before' => $date_to . ' 23:59:59', 'inclusive' => true]];
        $mq = $this->get_status_meta_query($status);
        if ($mq) $args['meta_query'] = $mq;
        $query = new WP_Query($args);
        $ids = $query->posts;
        $users = [];
        foreach ($ids as $id) {
            $post = get_post($id); $uid = $post->post_author;
            if (!isset($users[$uid])) {
                $u = get_userdata($uid); if (!$u) continue;
                $users[$uid] = ['user_id' => $uid, 'user_name' => $u->display_name, 'user_email' => $u->user_email, 'user_avatar' => get_avatar_url($uid, ['size'=>40]), 'invoice_count' => 0, 'total_sold' => 0, 'total_reserved' => 0, 'total_canceled' => 0, 'total_revenue' => 0, 'last_invoice_date' => ''];
            }
            $users[$uid]['invoice_count']++;
            $total_inv = floatval(get_post_meta($id, '_cig_invoice_total', true));
            $is_partial = get_post_meta($id, '_cig_payment_is_partial', true) === 'yes';
            $realized = $total_inv;
            if($is_partial) $realized = floatval(get_post_meta($id, '_cig_payment_paid_amount', true));
            $users[$uid]['total_revenue'] += $realized;
            $items = get_post_meta($id, '_cig_items', true) ?: [];
            foreach ($items as $it) {
                $q = floatval($it['qty'] ?? 0); $s = strtolower($it['status'] ?? 'sold');
                if ($s === 'sold') $users[$uid]['total_sold'] += $q; elseif ($s === 'reserved') $users[$uid]['total_reserved'] += $q; elseif ($s === 'canceled') $users[$uid]['total_canceled'] += $q;
            }
            $d = get_post_field('post_date', $id);
            if ($d > $users[$uid]['last_invoice_date']) $users[$uid]['last_invoice_date'] = $d;
        }
        if ($search) $users = array_filter($users, function($u) use ($search){ return stripos($u['user_name'], $search) !== false || stripos($u['user_email'], $search) !== false; });
        $sort_by = $_POST['sort_by'] ?? 'invoice_count'; $sort_order = $_POST['sort_order'] ?? 'desc';
        usort($users, function($a, $b) use ($sort_by, $sort_order) {
            $map = ['invoices'=>'invoice_count', 'revenue'=>'total_revenue', 'sold'=>'total_sold', 'reserved'=>'total_reserved', 'date'=>'last_invoice_date'];
            $k = $map[$sort_by] ?? 'invoice_count';
            return $sort_order === 'asc' ? ($a[$k] <=> $b[$k]) : ($b[$k] <=> $a[$k]);
        });
        wp_send_json_success(['users' => array_values($users)]);
    }

    public function get_user_invoices() {
        $this->security && $this->security->verify_ajax_request('cig_nonce', 'nonce', 'edit_posts');
        $uid = intval($_POST['user_id'] ?? 0);
        $status = sanitize_text_field($_POST['status'] ?? 'standard');
        $search = sanitize_text_field($_POST['search'] ?? '');
        $args = ['post_type' => 'invoice', 'post_status' => 'publish', 'author' => $uid, 'posts_per_page' => -1, 'orderby' => 'date', 'order' => 'DESC'];
        $meta = [];
        $status_mq = $this->get_status_meta_query($status);
        if ($status_mq) $meta = array_merge($meta, $status_mq);
        if (!empty($_POST['payment_method'])) $meta[] = ['key' => '_cig_payment_type', 'value' => sanitize_text_field($_POST['payment_method']), 'compare' => '='];
        if ($search) $meta[] = ['key' => '_cig_invoice_number', 'value' => $search, 'compare' => 'LIKE'];
        if (!empty($_POST['date_from']) && !empty($_POST['date_to'])) $args['date_query'] = [['after' => $_POST['date_from'] . ' 00:00:00', 'before' => $_POST['date_to'] . ' 23:59:59', 'inclusive' => true]];
        if ($meta) $args['meta_query'] = $meta;
        $query = new WP_Query($args);
        $invoices = [];
        while ($query->have_posts()) {
            $query->the_post(); $id = get_the_ID();
            $items = get_post_meta($id, '_cig_items', true) ?: [];
            $tot = 0; $sold = 0; $res = 0; $can = 0;
            foreach ($items as $it) {
                $q = floatval($it['qty'] ?? 0); $tot += $q; $s = strtolower($it['status'] ?? 'sold');
                if ($s === 'sold') $sold += $q; elseif ($s === 'reserved') $res += $q; elseif ($s === 'canceled') $can += $q;
            }
            $pt = get_post_meta($id, '_cig_payment_type', true);
            $invoices[] = [
                'id' => $id, 'invoice_number' => get_post_meta($id, '_cig_invoice_number', true),
                'date' => get_the_date('Y-m-d H:i:s'), 'invoice_total' => floatval(get_post_meta($id, '_cig_invoice_total', true)),
                'payment_type' => $pt, 'payment_label' => CIG_Invoice::get_payment_types()[$pt] ?? $pt,
                'total_products' => $tot, 'sold_items' => $sold, 'reserved_items' => $res, 'canceled_items' => $can,
                'view_url' => get_permalink($id), 'edit_url' => add_query_arg('edit', '1', get_permalink($id))
            ];
        }
        wp_send_json_success(['invoices' => $invoices]);
    }

    public function export_statistics() {
        $this->security && $this->security->verify_ajax_request('cig_nonce', 'nonce', 'edit_posts');
        wp_send_json_success(['redirect' => true]);
    }

    public function get_my_invoices() {
        $this->security && $this->security->verify_ajax_request('cig_nonce', 'nonce');
        $uid = get_current_user_id(); if (!$uid) wp_send_json_error(['message' => 'Not logged in'], 401);
        $filter = sanitize_text_field($_POST['filter'] ?? 'all');
        $search = sanitize_text_field($_POST['search'] ?? '');
        $status = sanitize_text_field($_POST['status'] ?? 'standard');
        $args = ['post_type' => 'invoice', 'post_status' => 'publish', 'author' => $uid, 'posts_per_page' => 20, 'orderby' => 'date', 'order' => 'DESC'];
        $meta = $this->get_status_meta_query($status);
        if ($search) $meta[] = ['key' => '_cig_invoice_number', 'value' => $search, 'compare' => 'LIKE'];
        if ($meta) $args['meta_query'] = $meta;
        if ($filter === 'today') $args['date_query'] = [['after' => date('Y-m-d 00:00:00'), 'inclusive' => true]];
        elseif ($filter === 'this_week') $args['date_query'] = [['after' => date('Y-m-d 00:00:00', strtotime('monday this week')), 'inclusive' => true]];
        elseif ($filter === 'this_month') $args['date_query'] = [['after' => date('Y-m-01 00:00:00'), 'inclusive' => true]];
        $query = new WP_Query($args);
        $invoices = [];
        while ($query->have_posts()) {
            $query->the_post(); $id = get_the_ID();
            $pt = get_post_meta($id, '_cig_payment_type', true);
            $items = get_post_meta($id, '_cig_items', true) ?: [];
            $ist = get_post_meta($id, '_cig_invoice_status', true) ?: 'standard';
            $has_s = false; $has_r = false; $has_c = false;
            foreach ($items as $it) { $s = strtolower($it['status'] ?? 'sold'); if ($s === 'sold') $has_s = true; if ($s === 'reserved') $has_r = true; if ($s === 'canceled') $has_c = true; }
            $invoices[] = [
                'id' => $id, 'invoice_number' => get_post_meta($id, '_cig_invoice_number', true),
                'date' => get_the_date('Y-m-d H:i:s'), 'invoice_total' => get_post_meta($id, '_cig_invoice_total', true),
                'payment_type' => $pt, 'payment_label' => CIG_Invoice::get_payment_types()[$pt] ?? $pt,
                'has_sold' => $has_s, 'has_reserved' => $has_r, 'has_canceled' => $has_c, 'status' => $ist,
                'view_url' => get_permalink($id), 'edit_url' => add_query_arg('edit', '1', get_permalink($id))
            ];
        }
        $t_args = ['post_type' => 'invoice', 'post_status' => 'publish', 'author' => $uid, 'posts_per_page' => -1, 'fields' => 'ids'];
        $t_args['meta_query'] = $this->get_status_meta_query('standard');
        $tq = new WP_Query($t_args);
        $t_res = 0; foreach ($tq->posts as $tid) { $its = get_post_meta($tid, '_cig_items', true) ?: []; foreach ($its as $it) { if (strtolower($it['status'] ?? 'sold') === 'reserved') $t_res += floatval($it['qty'] ?? 0); } }
        wp_send_json_success(['invoices' => $invoices, 'stats' => ['total_invoices' => $tq->found_posts, 'last_invoice_date' => !empty($invoices) ? $invoices[0]['date'] : '', 'total_reserved' => $t_res]]);
    }

    public function get_expiring_reservations() {
        $this->security && $this->security->verify_ajax_request('cig_nonce', 'nonce');
        $uid = get_current_user_id(); if (!$uid) wp_send_json_error(['message' => 'Not logged in'], 401);
        $args = ['post_type' => 'invoice', 'post_status' => 'publish', 'author' => $uid, 'posts_per_page' => -1, 'fields' => 'ids'];
        $args['meta_query'] = $this->get_status_meta_query('standard');
        $query = new WP_Query($args);
        $expiring = []; $now = current_time('timestamp'); $threshold = $now + (3 * DAY_IN_SECONDS);
        foreach ($query->posts as $id) {
            $items = get_post_meta($id, '_cig_items', true) ?: [];
            $inv_date = get_post_field('post_date', $id); $inv_num = get_post_meta($id, '_cig_invoice_number', true);
            foreach ($items as $it) {
                if (strtolower($it['status'] ?? 'sold') !== 'reserved') continue;
                $days = intval($it['reservation_days'] ?? 0); if ($days <= 0) continue;
                $exp = strtotime($inv_date . ' +' . $days . ' days');
                if ($exp > $now && $exp <= $threshold) {
                    $expiring[] = ['invoice_id' => $id, 'invoice_number' => $inv_num, 'product_name' => $it['name'] ?? 'Unknown', 'product_sku' => $it['sku'] ?? '', 'quantity' => floatval($it['qty'] ?? 0), 'expires_date' => date('Y-m-d H:i:s', $exp), 'days_left' => ceil(($exp - $now) / DAY_IN_SECONDS), 'edit_url' => add_query_arg('edit', '1', get_permalink($id))];
                }
            }
        }
        usort($expiring, function($a, $b){ return $a['days_left'] <=> $b['days_left']; });
        wp_send_json_success(['expiring' => $expiring, 'count' => count($expiring)]);
    }

    // --- 2. DRILL-DOWN INVOICES ---
    public function get_invoices_by_filters() {
        $this->security && $this->security->verify_ajax_request('cig_nonce', 'nonce', 'edit_posts');
        
        $status = sanitize_text_field($_POST['status'] ?? 'standard');
        $limit  = intval($_POST['limit'] ?? 200);
        $method_filter = sanitize_text_field($_POST['payment_method'] ?? '');

        $args = [
            'post_type'      => 'invoice',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC'
        ];

        $meta = $this->get_status_meta_query($status);
        
        if (!empty($_POST['date_from']) && !empty($_POST['date_to'])) {
            $args['date_query'] = [[
                'after'     => $_POST['date_from'] . ' 00:00:00',
                'before'    => $_POST['date_to'] . ' 23:59:59',
                'inclusive' => true
            ]];
        }

        if ($meta) $args['meta_query'] = $meta;

        $query = new WP_Query($args);
        $rows = [];

        $method_labels = [
            'company_transfer' => __('კომპანიის ჩარიცხვა', 'cig'),
            'cash'             => __('ქეში', 'cig'),
            'consignment'      => __('კონსიგნაცია', 'cig'),
            'credit'           => __('განვადება', 'cig'),
            'other'            => __('სხვა', 'cig')
        ];

        foreach ($query->posts as $post) {
            $id = $post->ID;
            
            // Payment Methods Logic
            $history = get_post_meta($id, '_cig_payment_history', true);
            $inv_methods = [];
            $has_target_method = false;
            $breakdown_html = '';
            
            $method_sums = [];

            if (is_array($history)) {
                foreach ($history as $h) {
                    $m = $h['method'] ?? 'other';
                    $amt = (float)($h['amount'] ?? 0);
                    
                    if ($method_filter && $method_filter !== 'all') {
                        if ($m === $method_filter && $amt > 0.001) {
                            $has_target_method = true;
                        }
                    }

                    $label = $method_labels[$m] ?? $m;
                    if (!in_array($label, $inv_methods)) {
                        $inv_methods[] = $label;
                    }
                    
                    if (!isset($method_sums[$m])) $method_sums[$m] = 0;
                    $method_sums[$m] += $amt;
                }
            }

            if ($method_filter && $method_filter !== 'all' && !$has_target_method) {
                continue; 
            }

            if (!empty($method_sums)) {
                $breakdown_html = '<div style="font-size:10px;color:#666;margin-top:4px;line-height:1.2;">';
                foreach ($method_sums as $m => $amt) {
                    if ($amt > 0) {
                        $lbl = $method_labels[$m] ?? $m;
                        $breakdown_html .= esc_html($lbl) . ': ' . number_format($amt, 2) . ' ₾<br>';
                    }
                }
                $breakdown_html .= '</div>';
            }

            $total = (float) get_post_meta($id, '_cig_invoice_total', true);
            $paid  = (float) get_post_meta($id, '_cig_payment_paid_amount', true);
            $due   = max(0, $total - $paid);
            
            $buyer = get_post_meta($id, '_cig_buyer_name', true) ?: '—';
            $author_id = $post->post_author;
            $author_user = get_userdata($author_id);
            $author_name = $author_user ? $author_user->display_name : 'Unknown';

            $rows[] = [
                'id'             => $id,
                'invoice_number' => get_post_meta($id, '_cig_invoice_number', true),
                'customer'       => $buyer,
                'payment_methods'=> implode(', ', $inv_methods),
                'total'          => $total,
                'paid'           => $paid,
                'paid_breakdown' => $breakdown_html, 
                'due'            => $due,
                'author'         => $author_name,
                'date'           => get_the_date('Y-m-d H:i', $post),
                'status'         => get_post_meta($id, '_cig_invoice_status', true) ?: 'standard',
                'view_url'       => get_permalink($id),
                'edit_url'       => add_query_arg('edit', '1', get_permalink($id))
            ];
        }

        wp_send_json_success(['invoices' => $rows]);
    }

    public function search_products_table() {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'cig_nonce')) wp_send_json_error(['message' => 'Invalid nonce'], 400);
        if (!class_exists('WC_Product')) wp_send_json_error(['message' => 'WooCommerce not active'], 400);

        $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        $per_page = isset($_POST['limit']) ? intval($_POST['limit']) : 20;
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $sort_col = isset($_POST['sort']) ? sanitize_text_field($_POST['sort']) : 'title';
        $sort_dir = isset($_POST['order']) && strtolower($_POST['order']) === 'desc' ? 'DESC' : 'ASC';

        $args = [
            'post_type'      => ['product', 'product_variation'],
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'fields'         => 'ids',
            'meta_query'     => [
                ['key' => '_stock_status', 'value' => 'outofstock', 'compare' => '!=']
            ]
        ];

        $filter_handler = function($clauses) use ($search) {
            global $wpdb;
            $clauses['join'] .= " LEFT JOIN {$wpdb->postmeta} as sku_meta_filter ON ({$wpdb->posts}.ID = sku_meta_filter.post_id AND sku_meta_filter.meta_key = '_sku') ";
            $clauses['where'] .= " AND (sku_meta_filter.meta_value IS NULL OR sku_meta_filter.meta_value NOT REGEXP '^GN20ST') ";

            if (!empty($search)) {
                $like = '%' . $wpdb->esc_like($search) . '%';
                $clauses['where'] .= $wpdb->prepare(" AND (
                    ({$wpdb->posts}.post_title LIKE %s)
                    OR ({$wpdb->posts}.post_content LIKE %s)
                    OR (sku_meta_filter.meta_value LIKE %s)
                )", $like, $like, $like);
            }
            return $clauses;
        };

        add_filter('posts_clauses', $filter_handler);
        
        switch ($sort_col) {
            case 'price_num': case 'price': $args['meta_key'] = '_price'; $args['orderby'] = 'meta_value_num'; break;
            case 'stock_num': $args['meta_key'] = '_stock'; $args['orderby'] = 'meta_value_num'; break;
            case 'sku': $args['meta_key'] = '_sku'; $args['orderby'] = 'meta_value'; break;
            default: $args['orderby'] = 'title'; break;
        }
        $args['order'] = $sort_dir;

        $query = new WP_Query($args);
        remove_filter('posts_clauses', $filter_handler);

        $products = [];
        $stock_manager = function_exists('CIG') ? CIG()->stock : $this->stock;
        
        $page_product_ids = $query->posts;
        $pending_map = [];
        if (!empty($page_product_ids)) {
            $pending_reqs = get_posts(['post_type' => 'cig_req', 'post_status' => 'publish', 'numberposts' => -1, 'meta_query' => ['relation' => 'AND', ['key' => '_cig_req_product_id', 'value' => $page_product_ids, 'compare' => 'IN'], ['key' => '_cig_req_status', 'value' => 'pending']], 'fields' => 'ids']);
            foreach ($pending_reqs as $req_id) {
                $pid = get_post_meta($req_id, '_cig_req_product_id', true);
                $changes = get_post_meta($req_id, '_cig_req_changes', true);
                if ($pid && is_array($changes)) {
                    if (!isset($pending_map[$pid])) $pending_map[$pid] = [];
                    if (isset($changes['price'])) $pending_map[$pid]['price'] = $changes['price']['new'];
                    if (isset($changes['stock'])) $pending_map[$pid]['stock'] = $changes['stock']['new'];
                }
            }
        }

        if ($query->have_posts()) {
            foreach ($query->posts as $product_id) {
                $product = wc_get_product($product_id);
                if (!$product) continue;
                if ($product->is_type('variable')) continue;
                $stock_qty = $product->get_stock_quantity();
                $reserved  = $stock_manager ? $stock_manager->get_reserved($product_id) : 0;
                $available = ($stock_qty !== null && $stock_qty !== '') ? max(0, $stock_qty - $reserved) : null;
                $image_id = $product->get_image_id();
                if (!$image_id && $product->is_type('variation')) $image_id = $product->get_parent_id();
                $image_url = CIG_ASSETS_URL . 'img/placeholder-80x70.png'; $full_image_url = '';
                if ($image_id) { $img = wp_get_attachment_image_src($image_id, 'thumbnail'); $image_url = $img ? $img[0] : $image_url; $full = wp_get_attachment_image_src($image_id, 'full'); $full_image_url = $full ? $full[0] : ''; }
                $price = $product->get_price(); $price_html = ($price !== null && $price !== '') ? wc_price($price) : 'N/A';
                
                $products[] = [
                    'id' => $product_id, 'title' => $product->get_name(), 'sku' => $product->get_sku() ?: 'N/A',
                    'price' => $price_html, 'price_num' => floatval($price ?: 0), 'stock' => $stock_qty !== null && $stock_qty !== '' ? floatval($stock_qty) : 'Not managed', 'stock_num' => $stock_qty !== null && $stock_qty !== '' ? floatval($stock_qty) : -1,
                    'reserved' => floatval($reserved), 'available' => $available !== null ? floatval($available) : 'Not managed', 'available_num' => $available !== null ? floatval($available) : -1,
                    'image' => $image_url, 'full_image' => $full_image_url, 'product_url' => get_permalink($product_id), 'pending_data' => $pending_map[$product_id] ?? []
                ];
            }
            wp_reset_postdata();
        }
        wp_send_json_success(['products' => $products, 'total_items' => $query->found_posts, 'total_pages' => $query->max_num_pages, 'current_page'=> $page]);
    }

    public function filter_customer_search($where) {
        global $wpdb;
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        
        if ($search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= $wpdb->prepare(" AND (
                {$wpdb->posts}.post_title LIKE %s
                OR EXISTS (
                    SELECT 1 FROM {$wpdb->postmeta} 
                    WHERE post_id = {$wpdb->posts}.ID 
                    AND meta_key = '_cig_customer_tax_id' 
                    AND meta_value LIKE %s
                )
            )", $like, $like);
        }
        return $where;
    }

    public function get_customer_insights() { 
        $this->security->verify_ajax_request('cig_nonce','nonce','edit_posts');
        $s=isset($_POST['search'])?sanitize_text_field($_POST['search']):'';
        $args=['post_type'=>'cig_customer','post_status'=>'publish','posts_per_page'=>20,'paged'=>intval($_POST['paged']),'fields'=>'ids'];
        if($s) add_filter('posts_where', [$this,'filter_customer_search']);
        $q=new WP_Query($args);
        if($s) remove_filter('posts_where', [$this,'filter_customer_search']);
        $custs=[]; 
        foreach($q->posts as $cid) {
            $invs=get_posts(['post_type'=>'invoice','post_status'=>'publish','fields'=>'ids','meta_query'=>[['key'=>'_cig_customer_id','value'=>$cid],['relation'=>'OR',['key'=>'_cig_invoice_status','value'=>'standard'],['key'=>'_cig_invoice_status','compare'=>'NOT EXISTS']]]]);
            $rev=0; $pd=0; 
            foreach($invs as $iid){ $rev+=floatval(get_post_meta($iid,'_cig_invoice_total',true)); $pd+=floatval(get_post_meta($iid,'_cig_payment_paid_amount',true)); }
            $custs[]=['id'=>$cid, 'name'=>get_the_title($cid), 'tax_id'=>get_post_meta($cid,'_cig_customer_tax_id',true), 'count'=>count($invs), 'revenue'=>$rev, 'paid'=>$pd, 'due'=>$rev-$pd];
        }
        wp_send_json_success(['customers'=>$custs, 'total_pages'=>$q->max_num_pages]);
    }

    public function get_customer_invoices_details() {
        $cid=intval($_POST['customer_id']); 
        $args=['post_type'=>'invoice','meta_query'=>[['key'=>'_cig_customer_id','value'=>$cid],['relation'=>'OR',['key'=>'_cig_invoice_status','value'=>'standard'],['key'=>'_cig_invoice_status','compare'=>'NOT EXISTS']]]];
        $invs=[]; $q=new WP_Query($args);
        foreach($q->posts as $p){ $id=$p->ID; $t=floatval(get_post_meta($id,'_cig_invoice_total',true)); $pd=floatval(get_post_meta($id,'_cig_payment_paid_amount',true)); $invs[]=['number'=>get_post_meta($id,'_cig_invoice_number',true), 'date'=>get_the_date('Y-m-d',$id), 'total'=>$t, 'paid'=>$pd, 'due'=>$t-$pd, 'status'=>($t-$pd<0.01)?'Paid':'Unpaid', 'view_url'=>get_permalink($id)]; }
        wp_send_json_success(['customer_name'=>get_the_title($cid), 'invoices'=>$invs]);
    }

    public function toggle_rs_status() {
        check_ajax_referer('cig_nonce', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error(['message' => 'Permission denied']);
        $inv_id = isset($_POST['invoice_id']) ? intval($_POST['invoice_id']) : 0;
        $state  = isset($_POST['state']) && $_POST['state'] === 'true'; 
        if (!$inv_id || get_post_type($inv_id) !== 'invoice') wp_send_json_error(['message' => 'Invalid Invoice']);
        if ($state) { update_post_meta($inv_id, '_cig_rs_uploaded', 'yes'); update_post_meta($inv_id, '_cig_rs_uploaded_date', current_time('mysql')); update_post_meta($inv_id, '_cig_rs_uploaded_by', get_current_user_id()); } 
        else { delete_post_meta($inv_id, '_cig_rs_uploaded'); delete_post_meta($inv_id, '_cig_rs_uploaded_date'); delete_post_meta($inv_id, '_cig_rs_uploaded_by'); }
        wp_send_json_success(['new_state' => $state]);
    }
    
    // Alias for consistency
    public function get_invoices_ajax() {
        $this->get_accountant_invoices();
    }
    
    // --- UPDATED: get_products_by_filters ---
    public function get_products_by_filters() {
        $this->security && $this->security->verify_ajax_request('cig_nonce', 'nonce', 'edit_posts');
        $status = sanitize_text_field($_POST['invoice_status'] ?? 'standard');
        $item_status = sanitize_text_field($_POST['status'] ?? 'sold');
        $limit = intval($_POST['limit'] ?? 500);
        $args = ['post_type' => 'invoice', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'date', 'order' => 'DESC', 'fields' => 'ids'];
        $meta = []; $status_mq = $this->get_status_meta_query($status); if ($status_mq) $meta = array_merge($meta, $status_mq);
        if (!empty($_POST['date_from']) && !empty($_POST['date_to'])) $args['date_query'] = [['after' => $_POST['date_from'] . ' 00:00:00', 'before' => $_POST['date_to'] . ' 23:59:59', 'inclusive' => true]];
        if (!empty($_POST['payment_method']) && $_POST['payment_method'] !== 'all') $meta[] = ['key' => '_cig_payment_type', 'value' => sanitize_text_field($_POST['payment_method']), 'compare' => '='];
        if ($meta) $args['meta_query'] = $meta;
        $query = new WP_Query($args); $rows = [];
        foreach ($query->posts as $id) {
            $items = get_post_meta($id, '_cig_items', true) ?: [];
            foreach ($items as $it) {
                if ((strtolower($it['status'] ?? 'sold')) !== $item_status) continue;
                $rows[] = ['name' => $it['name'] ?? '', 'sku' => $it['sku'] ?? '', 'image' => $it['image'] ?? '', 'qty' => floatval($it['qty'] ?? 0), 'invoice_id' => $id, 'invoice_number' => get_post_meta($id, '_cig_invoice_number', true), 'author_name' => get_the_author_meta('display_name', get_post_field('post_author', $id)), 'date' => get_post_field('post_date', $id), 'view_url' => get_permalink($id), 'edit_url' => add_query_arg('edit', '1', get_permalink($id))];
                if (count($rows) >= $limit) break 2;
            }
        }
        wp_send_json_success(['products' => $rows]);
    }
}