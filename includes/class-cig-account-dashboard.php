<?php
/**
 * Custom WooCommerce My Account Dashboard
 * 
 * Replaces standard WooCommerce account tabs with a custom management panel
 * for users with manage_woocommerce capability.
 *
 * @package CIG
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CIG_Account_Dashboard Class
 *
 * Customizes WooCommerce My Account page for admin users.
 */
class CIG_Account_Dashboard {

    /**
     * Primary color for the dashboard
     *
     * @var string
     */
    private $primary_color = '#50529d';

    /**
     * Constructor
     */
    public function __construct() {
        // Only run if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            return;
        }

        // Only apply customizations for users with manage_woocommerce capability
        add_action('init', [$this, 'init_hooks']);
    }

    /**
     * Initialize hooks after WordPress is ready
     *
     * @return void
     */
    public function init_hooks() {
        if (!is_user_logged_in() || !current_user_can('manage_woocommerce')) {
            return;
        }

        // Filter the My Account menu items
        add_filter('woocommerce_account_menu_items', [$this, 'customize_menu_items'], 99);

        // Replace the My Account dashboard content
        add_action('woocommerce_account_dashboard', [$this, 'render_dashboard_cards'], 5);

        // Remove the default dashboard content
        remove_action('woocommerce_account_dashboard', 'woocommerce_account_dashboard');

        // Enqueue custom styles
        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles']);
    }

    /**
     * Customize the My Account menu items
     *
     * Removes all default links except "Logout" and adds custom plugin links.
     *
     * @param array $items Default menu items
     * @return array Modified menu items
     */
    public function customize_menu_items($items) {
        // Preserve the logout item
        $logout = isset($items['customer-logout']) ? $items['customer-logout'] : __('Logout', 'cig');

        // Build new menu items
        $new_items = [
            'dashboard' => __('Dashboard', 'cig'),
        ];

        // Add custom menu items with external links
        // These will be handled by the redirect logic
        $new_items['cig-invoices']       = __('Invoices', 'cig');
        $new_items['cig-customers']      = __('Customers', 'cig');
        $new_items['cig-stock-requests'] = __('Stock Requests', 'cig');
        $new_items['cig-accountant']     = __('Accountant', 'cig');
        $new_items['cig-statistics']     = __('Statistics', 'cig');

        // Add logout at the end
        $new_items['customer-logout'] = $logout;

        return $new_items;
    }

    /**
     * Render the custom dashboard cards
     *
     * Displays a responsive grid of 5 cards with Dashicons, titles, and links.
     *
     * @return void
     */
    public function render_dashboard_cards() {
        // Define the dashboard cards
        $cards = [
            [
                'title'    => __('Invoices', 'cig'),
                'icon'     => 'dashicons-media-text',
                'link'     => home_url('/invoice-shortcode/'),
                'desc'     => __('Create and manage invoices', 'cig'),
            ],
            [
                'title'    => __('Customers', 'cig'),
                'icon'     => 'dashicons-groups',
                'link'     => admin_url('edit.php?post_type=cig_customer'),
                'desc'     => __('View customer list', 'cig'),
            ],
            [
                'title'    => __('Stock Requests', 'cig'),
                'icon'     => 'dashicons-archive',
                'link'     => admin_url('admin.php?page=cig-stock-requests'),
                'desc'     => __('Manage stock requests', 'cig'),
            ],
            [
                'title'    => __('Accountant', 'cig'),
                'icon'     => 'dashicons-calculator',
                'link'     => home_url('/accountant/'),
                'desc'     => __('Accounting dashboard', 'cig'),
            ],
            [
                'title'    => __('Statistics', 'cig'),
                'icon'     => 'dashicons-chart-bar',
                'link'     => admin_url('admin.php?page=cig-statistics'),
                'desc'     => __('View sales statistics', 'cig'),
            ],
        ];

        // Output the dashboard HTML
        ?>
        <div class="cig-account-dashboard">
            <h2 class="cig-dashboard-title"><?php esc_html_e('Management Panel', 'cig'); ?></h2>
            <div class="cig-dashboard-grid">
                <?php foreach ($cards as $card) : ?>
                    <a href="<?php echo esc_url($card['link']); ?>" class="cig-dashboard-card">
                        <span class="dashicons <?php echo esc_attr($card['icon']); ?> cig-card-icon"></span>
                        <h3 class="cig-card-title"><?php echo esc_html($card['title']); ?></h3>
                        <p class="cig-card-desc"><?php echo esc_html($card['desc']); ?></p>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Enqueue custom styles for the My Account dashboard
     *
     * @return void
     */
    public function enqueue_styles() {
        // Only on My Account page
        if (!is_account_page()) {
            return;
        }

        // Enqueue Dashicons
        wp_enqueue_style('dashicons');

        // Add inline styles
        $custom_css = $this->get_dashboard_styles();
        wp_add_inline_style('dashicons', $custom_css);
    }

    /**
     * Get the dashboard CSS styles
     *
     * @return string CSS styles
     */
    private function get_dashboard_styles() {
        $primary = esc_attr($this->primary_color);

        return "
            /* CIG Account Dashboard Styles */
            .cig-account-dashboard {
                font-family: 'FiraGO', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
                padding: 20px 0;
            }

            .cig-dashboard-title {
                font-family: 'FiraGO', sans-serif;
                font-size: 24px;
                font-weight: 600;
                color: {$primary};
                margin: 0 0 25px 0;
                padding-bottom: 10px;
                border-bottom: 2px solid {$primary};
            }

            .cig-dashboard-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                gap: 20px;
                max-width: 1200px;
            }

            .cig-dashboard-card {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                padding: 30px 20px;
                background: #fff;
                border: 2px solid #e0e0e5;
                border-radius: 12px;
                text-decoration: none;
                transition: all 0.3s ease;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
                min-height: 180px;
            }

            .cig-dashboard-card:hover {
                border-color: {$primary};
                box-shadow: 0 8px 25px rgba(80, 82, 157, 0.15);
                transform: translateY(-3px);
            }

            .cig-card-icon {
                font-size: 48px;
                width: 48px;
                height: 48px;
                color: {$primary};
                margin-bottom: 15px;
                transition: transform 0.3s ease;
            }

            .cig-dashboard-card:hover .cig-card-icon {
                transform: scale(1.1);
            }

            .cig-card-title {
                font-family: 'FiraGO', sans-serif;
                font-size: 18px;
                font-weight: 600;
                color: #333;
                margin: 0 0 8px 0;
                text-align: center;
            }

            .cig-card-desc {
                font-family: 'FiraGO', sans-serif;
                font-size: 13px;
                color: #666;
                margin: 0;
                text-align: center;
                line-height: 1.4;
            }

            /* WooCommerce My Account Menu Styling */
            .woocommerce-MyAccount-navigation ul {
                list-style: none;
                padding: 0;
                margin: 0;
            }

            .woocommerce-MyAccount-navigation ul li {
                margin-bottom: 5px;
            }

            .woocommerce-MyAccount-navigation ul li a {
                font-family: 'FiraGO', sans-serif;
                display: block;
                padding: 12px 15px;
                color: #333;
                text-decoration: none;
                border-radius: 6px;
                transition: all 0.2s ease;
            }

            .woocommerce-MyAccount-navigation ul li a:hover,
            .woocommerce-MyAccount-navigation ul li.is-active a {
                background: {$primary};
                color: #fff;
            }

            /* Responsive adjustments */
            @media (max-width: 768px) {
                .cig-dashboard-grid {
                    grid-template-columns: repeat(2, 1fr);
                    gap: 15px;
                }

                .cig-dashboard-card {
                    padding: 20px 15px;
                    min-height: 150px;
                }

                .cig-card-icon {
                    font-size: 36px;
                    width: 36px;
                    height: 36px;
                }

                .cig-card-title {
                    font-size: 16px;
                }
            }

            @media (max-width: 480px) {
                .cig-dashboard-grid {
                    grid-template-columns: 1fr;
                }
            }
        ";
    }

    /**
     * Get singleton instance
     *
     * @return CIG_Account_Dashboard
     */
    public static function instance() {
        static $instance = null;
        if (null === $instance) {
            $instance = new self();
        }
        return $instance;
    }
}
