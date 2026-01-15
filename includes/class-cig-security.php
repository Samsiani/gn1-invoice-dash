<?php
/**
 * Security helpers for nonce/capability/XSS hardening
 *
 * @package CIG
 * @since 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CIG_Security {

    /**
     * Verify AJAX nonce and capability
     *
     * @param string $action Nonce action key
     * @param string $nonce_field Field name in request (default 'nonce')
     * @param string $cap Capability required (default 'manage_woocommerce')
     */
    public function verify_ajax_request($action, $nonce_field = 'nonce', $cap = 'manage_woocommerce') {
        $nonce = isset($_REQUEST[$nonce_field]) ? sanitize_text_field(wp_unslash($_REQUEST[$nonce_field])) : '';
        if (!wp_verify_nonce($nonce, $action)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 400);
        }
        if (!current_user_can($cap)) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }
        return true;
    }

    /**
     * Check a capability, else wp_die
     */
    public function require_cap($cap = 'manage_woocommerce') {
        if (!current_user_can($cap)) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'cig'), 403);
        }
        return true;
    }

    /**
     * Deep esc_html for output safety
     */
    public function esc_html_deep($data) {
        if (is_array($data)) {
            return array_map([$this, 'esc_html_deep'], $data);
        }
        if (is_object($data)) {
            foreach ($data as $k => $v) {
                $data->$k = $this->esc_html_deep($v);
            }
            return $data;
        }
        return is_scalar($data) ? esc_html($data) : $data;
    }

    /**
     * Deep sanitize text for input
     */
    public function sanitize_text_deep($data) {
        if (is_array($data)) {
            return array_map('sanitize_text_field', $data);
        }
        return is_scalar($data) ? sanitize_text_field($data) : $data;
    }
}