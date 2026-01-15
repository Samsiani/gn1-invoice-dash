<?php
if (!defined('ABSPATH')) exit;

class CIG_Ajax_Customers {
    private $security;

    public function __construct($security) {
        $this->security = $security;
        add_action('wp_ajax_cig_get_customer_insights', [$this, 'get_customer_insights']);
        add_action('wp_ajax_cig_get_customer_invoices_details', [$this, 'get_customer_invoices_details']);
    }

    public function filter_customer_search($where) {
        global $wpdb;
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        if ($search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= $wpdb->prepare(" AND ({$wpdb->posts}.post_title LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} WHERE post_id={$wpdb->posts}.ID AND meta_key='_cig_customer_tax_id' AND meta_value LIKE %s))", $like, $like);
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
}