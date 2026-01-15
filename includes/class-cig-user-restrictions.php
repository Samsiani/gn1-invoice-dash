<?php
/**
 * User Access Restrictions
 *
 * @package CIG
 */

if (!defined('ABSPATH')) {
    exit;
}

class CIG_User_Restrictions {

    public function __construct() {
        // 1. ველის დამატება იუზერის პროფილში
        add_action('show_user_profile', [$this, 'add_disable_admin_field']);
        add_action('edit_user_profile', [$this, 'add_disable_admin_field']);

        // 2. ველის შენახვა
        add_action('personal_options_update', [$this, 'save_disable_admin_field']);
        add_action('edit_user_profile_update', [$this, 'save_disable_admin_field']);

        // 3. შეზღუდვის აღსრულება (რედირექტი)
        add_action('admin_init', [$this, 'block_admin_access']);
    }

    /**
     * Add Checkbox to User Profile
     */
    public function add_disable_admin_field($user) {
        // მხოლოდ ადმინისტრატორს შეეძლოს ამის ნახვა და შეცვლა
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $is_disabled = get_user_meta($user->ID, 'cig_disable_wp_admin', true);
        ?>
        <h3><?php esc_html_e('CIG Access Control', 'cig'); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="cig_disable_wp_admin"><?php esc_html_e('Block Admin Access', 'cig'); ?></label></th>
                <td>
                    <label for="cig_disable_wp_admin">
                        <input type="checkbox" name="cig_disable_wp_admin" id="cig_disable_wp_admin" value="yes" <?php checked($is_disabled, 'yes'); ?>>
                        <?php esc_html_e('Disable WP-Admin access for this user (Redirect to Homepage).', 'cig'); ?>
                    </label>
                    <p class="description"><?php esc_html_e('If checked, this user cannot access the WordPress Dashboard.', 'cig'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save the Field
     */
    public function save_disable_admin_field($user_id) {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        if (isset($_POST['cig_disable_wp_admin']) && $_POST['cig_disable_wp_admin'] === 'yes') {
            update_user_meta($user_id, 'cig_disable_wp_admin', 'yes');
        } else {
            delete_user_meta($user_id, 'cig_disable_wp_admin');
        }
    }

    /**
     * Redirect restricted users
     */
    public function block_admin_access() {
        // არ დაბლოკო AJAX მოთხოვნები (მნიშვნელოვანია!)
        if (wp_doing_ajax()) {
            return;
        }

        $user_id = get_current_user_id();
        
        // უსაფრთხოება: ადმინისტრატორს არასდროს შეეზღუდოს წვდომა (რომ საკუთარი თავი არ დაბლოკოთ)
        if (user_can($user_id, 'administrator')) {
            return;
        }

        $is_disabled = get_user_meta($user_id, 'cig_disable_wp_admin', true);

        if ($is_disabled === 'yes') {
            wp_redirect(home_url()); // გადაამისამართე მთავარ გვერდზე
            exit;
        }
    }
}