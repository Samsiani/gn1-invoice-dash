<?php
/**
 * Products Stock Table Shortcode Template
 *
 * @package CIG
 * @since 3.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}
$current_user = wp_get_current_user();
?>

<?php include CIG_TEMPLATES_DIR . 'partials/mini-dashboard.php'; ?>

<div class="cig-products-stock-table-wrapper">
    <div class="cig-stock-header">
        <h3 class="cig-stock-title"><?php esc_html_e('Products Stock Overview', 'cig'); ?></h3>
        <div class="cig-stock-search-bar">
            <input 
                type="text" 
                id="cig-stock-search" 
                class="cig-stock-search-input" 
                placeholder="<?php esc_attr_e('Search by name or SKU...', 'cig'); ?>"
                autocomplete="off"
            >
            <button type="button" id="cig-stock-clear-btn" class="cig-stock-clear-btn" title="<?php esc_attr_e('Clear search', 'cig'); ?>">
                <span>✕</span>
            </button>
        </div>
    </div>

    <div class="cig-stock-table-container">
        <table class="cig-stock-table" id="cig-stock-table">
            <thead>
                <tr>
                    <th class="col-image"><?php esc_html_e('Image', 'cig'); ?></th>
                    <th class="col-title sortable" data-sort="title" data-order="asc">
                        <span class="sort-label"><?php esc_html_e('Product Title', 'cig'); ?></span>
                        <span class="sort-arrows">
                            <span class="arrow-up">▲</span>
                            <span class="arrow-down">▼</span>
                        </span>
                    </th>
                    <th class="col-sku sortable" data-sort="sku" data-order="asc">
                        <span class="sort-label"><?php esc_html_e('SKU', 'cig'); ?></span>
                        <span class="sort-arrows">
                            <span class="arrow-up">▲</span>
                            <span class="arrow-down">▼</span>
                        </span>
                    </th>
                    <th class="col-price sortable" data-sort="price_num" data-order="asc">
                        <span class="sort-label"><?php esc_html_e('Price', 'cig'); ?></span>
                        <span class="sort-arrows">
                            <span class="arrow-up">▲</span>
                            <span class="arrow-down">▼</span>
                        </span>
                    </th>
                    <th class="col-stock sortable" data-sort="stock_num" data-order="asc">
                        <span class="sort-label"><?php esc_html_e('Stock', 'cig'); ?></span>
                        <span class="sort-arrows">
                            <span class="arrow-up">▲</span>
                            <span class="arrow-down">▼</span>
                        </span>
                    </th>
                    <th class="col-reserved sortable" data-sort="reserved" data-order="asc">
                        <span class="sort-label"><?php esc_html_e('Reserved', 'cig'); ?></span>
                        <span class="sort-arrows">
                            <span class="arrow-up">▲</span>
                            <span class="arrow-down">▼</span>
                        </span>
                    </th>
                    <th class="col-available sortable" data-sort="available_num" data-order="asc">
                        <span class="sort-label"><?php esc_html_e('Available', 'cig'); ?></span>
                        <span class="sort-arrows">
                            <span class="arrow-up">▲</span>
                            <span class="arrow-down">▼</span>
                        </span>
                    </th>
                    <th class="col-actions"><?php esc_html_e('Add', 'cig'); ?></th>
                </tr>
            </thead>
            <tbody id="cig-stock-tbody">
                <tr class="loading-row">
                    <td colspan="8">
                        <div class="cig-loading-spinner">
                            <div class="spinner"></div>
                            <p><?php esc_html_e('Loading products...', 'cig'); ?></p>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="cig-stock-footer">
        <div class="cig-stock-info" id="cig-stock-info"></div>
        <div class="cig-stock-pagination" id="cig-stock-pagination"></div>
    </div>
</div>

<div id="cig-lightbox" class="cig-lightbox" style="display:none;">
    <span class="cig-lightbox-close">&times;</span>
    <img class="cig-lightbox-content" id="cig-lightbox-img" alt="">
</div>