<?php
/**
 * Statistics Dashboard Template
 * Updated: Added 'External Balance' Tab & Deposit Modal
 *
 * @package CIG
 * @since 4.9.2
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap cig-statistics-wrap">
    <h1 class="cig-stats-main-title">
        <?php esc_html_e('Invoice Statistics', 'cig'); ?>
        <span>
            <button type="button" id="cig-refresh-stats" class="button button-secondary" title="<?php esc_attr_e('Refresh statistics', 'cig'); ?>">
                <span class="dashicons dashicons-update"></span> <?php esc_html_e('Refresh', 'cig'); ?>
            </button>
            <span class="auto-refresh-indicator">
                <span class="dashicons dashicons-update"></span>
                <span><?php esc_html_e('Auto refresh every 5 min', 'cig'); ?></span>
            </span>
        </span>
    </h1>

    <h2 class="nav-tab-wrapper cig-stats-tabs">
        <a href="#tab-overview" class="nav-tab nav-tab-active" data-tab="overview"><?php esc_html_e('General Overview', 'cig'); ?></a>
        <a href="#tab-product" class="nav-tab" data-tab="product"><?php esc_html_e('Product Insight', 'cig'); ?></a>
        <a href="#tab-customer" class="nav-tab" data-tab="customer"><?php esc_html_e('Customer Insight', 'cig'); ?></a>
        <a href="#tab-external" class="nav-tab" data-tab="external"><?php esc_html_e('External Balance', 'cig'); ?></a> </h2>

    <div id="cig-tab-overview" class="cig-tab-content active">
        <div class="cig-stats-filters-bar">
            <div class="cig-filters-row">
                <div class="cig-filter-group">
                    <label><?php esc_html_e('Quick Filters:', 'cig'); ?></label>
                    <div class="cig-quick-filters">
                        <button type="button" class="cig-quick-filter-btn" data-filter="today"><?php esc_html_e('Today', 'cig'); ?></button>
                        <button type="button" class="cig-quick-filter-btn" data-filter="this_week"><?php esc_html_e('This Week', 'cig'); ?></button>
                        <button type="button" class="cig-quick-filter-btn" data-filter="this_month"><?php esc_html_e('This Month', 'cig'); ?></button>
                        <button type="button" class="cig-quick-filter-btn" data-filter="last_30_days"><?php esc_html_e('Last 30 Days', 'cig'); ?></button>
                        <button type="button" class="cig-quick-filter-btn active" data-filter="all_time"><?php esc_html_e('All Time', 'cig'); ?></button>
                    </div>
                </div>

                <div class="cig-filter-group">
                    <label><?php esc_html_e('Custom Range:', 'cig'); ?></label>
                    <div class="cig-date-range">
                        <input type="date" id="cig-date-from" class="cig-date-input" placeholder="<?php esc_attr_e('From', 'cig'); ?>">
                        <span>-</span>
                        <input type="date" id="cig-date-to" class="cig-date-input" placeholder="<?php esc_attr_e('To', 'cig'); ?>">
                        <button type="button" id="cig-apply-date-range" class="button button-primary"><?php esc_html_e('Apply', 'cig'); ?></button>
                    </div>
                </div>

                <div class="cig-filter-group">
                    <label for="cig-payment-filter"><?php esc_html_e('Payment Method:', 'cig'); ?></label>
                    <select id="cig-payment-filter" class="cig-select-filter">
                        <option value="all"><?php esc_html_e('All Methods', 'cig'); ?></option>
                        <?php
                        $payment_types = CIG_Invoice::get_payment_types();
                        foreach ($payment_types as $key => $label) {
                            echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
                        }
                        ?>
                    </select>
                </div>

                <div class="cig-filter-group">
                    <label for="cig-status-filter"><?php esc_html_e('Invoice Status:', 'cig'); ?></label>
                    <select id="cig-status-filter" class="cig-select-filter" style="min-width:160px; border-color:#50529d;">
                        <option value="standard" selected="selected"><?php esc_html_e('Active Only', 'cig'); ?></option>
                        <option value="fictive"><?php esc_html_e('Fictive Only', 'cig'); ?></option>
                        <option value="all"><?php esc_html_e('All Statuses', 'cig'); ?></option>
                    </select>
                </div>

                <div class="cig-filter-group">
                    <label for="cig-overview-search"><?php esc_html_e('Search:', 'cig'); ?></label>
                    <input type="text" id="cig-overview-search" class="cig-search-input" placeholder="<?php esc_attr_e('Search Invoice #, Client Name, or Tax ID', 'cig'); ?>" style="min-width:250px;">
                </div>

                <div class="cig-filter-group">
                    <button type="button" id="cig-export-stats" class="button button-secondary">
                        <span class="dashicons dashicons-download"></span> <?php esc_html_e('Export to Excel', 'cig'); ?>
                    </button>
                </div>
            </div>
        </div>

        <div class="cig-stats-summary" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));">
            
            <div class="cig-stat-card" id="cig-card-total-invoices" data-dropdown="invoices" data-method="all">
                <div class="cig-stat-icon" style="background:#50529d;">
                    <span class="dashicons dashicons-media-spreadsheet"></span>
                </div>
                <div class="cig-stat-content">
                    <div class="cig-stat-label"><?php esc_html_e('სულ ინვოისები', 'cig'); ?></div>
                    <div class="cig-stat-value" id="stat-total-invoices"><span class="loading-stat">...</span></div>
                    <div class="cig-stat-trend" id="trend-invoices"><?php esc_html_e('Click to view invoices', 'cig'); ?></div>
                </div>
            </div>

            <div class="cig-stat-card" id="cig-card-total-reserved-invoices" data-dropdown="invoices" data-method="reserved_invoices">
                <div class="cig-stat-icon" style="background:#ffc107;">
                    <span class="dashicons dashicons-lock"></span>
                </div>
                <div class="cig-stat-content">
                    <div class="cig-stat-label"><?php esc_html_e('დარეზერვებული ინვოისები', 'cig'); ?></div>
                    <div class="cig-stat-value" id="stat-total-reserved-invoices"><span class="loading-stat">...</span></div>
                    <div class="cig-stat-trend"><?php esc_html_e('დააჭირეთ სანახავად', 'cig'); ?></div>
                </div>
            </div>

            <div class="cig-stat-card" id="cig-card-total-revenue" data-dropdown="invoices" data-method="all">
                <div class="cig-stat-icon" style="background:#17a2b8;">
                    <span class="dashicons dashicons-chart-bar"></span>
                </div>
                <div class="cig-stat-content">
                    <div class="cig-stat-label"><?php esc_html_e('სულ ნავაჭრი', 'cig'); ?></div>
                    <div class="cig-stat-value" id="stat-total-revenue"><span class="loading-stat">...</span></div>
                    <div class="cig-stat-trend" id="trend-revenue"><?php esc_html_e('Click to view invoices', 'cig'); ?></div>
                </div>
            </div>

            <div class="cig-stat-card" id="cig-card-total-paid" data-dropdown="invoices" data-method="all">
                <div class="cig-stat-icon" style="background:#28a745;">
                    <span class="dashicons dashicons-money-alt"></span>
                </div>
                <div class="cig-stat-content">
                    <div class="cig-stat-label"><?php esc_html_e('გადახდილი თანხა', 'cig'); ?></div>
                    <div class="cig-stat-value" id="stat-total-paid"><span class="loading-stat">...</span></div>
                    <div class="cig-stat-trend"><?php esc_html_e('Click to view invoices', 'cig'); ?></div>
                </div>
            </div>

            <div class="cig-stat-card" id="cig-card-total-outstanding" data-dropdown="outstanding" data-method="all">
                <div class="cig-stat-icon" style="background:#dc3545;">
                    <span class="dashicons dashicons-warning"></span>
                </div>
                <div class="cig-stat-content">
                    <div class="cig-stat-label"><?php esc_html_e('გადასახდელი თანხა', 'cig'); ?></div>
                    <div class="cig-stat-value" id="stat-total-outstanding"><span class="loading-stat">...</span></div>
                    <div class="cig-stat-trend"><?php esc_html_e('Click to view unpaid', 'cig'); ?></div>
                </div>
            </div>

            <div class="cig-stat-card" id="cig-card-cash" data-dropdown="invoices" data-method="cash">
                <div class="cig-stat-icon" style="background:#28a745;">
                    <span class="dashicons dashicons-money"></span>
                </div>
                <div class="cig-stat-content">
                    <div class="cig-stat-label"><?php esc_html_e('სულ ქეში', 'cig'); ?></div>
                    <div class="cig-stat-value" id="stat-total-cash"><span class="loading-stat">...</span></div>
                    <div class="cig-stat-trend"><?php esc_html_e('Click to view invoices', 'cig'); ?></div>
                </div>
            </div>

            <div class="cig-stat-card" id="cig-card-transfer" data-dropdown="invoices" data-method="company_transfer">
                <div class="cig-stat-icon" style="background:#17a2b8;">
                    <span class="dashicons dashicons-bank"></span>
                </div>
                <div class="cig-stat-content">
                    <div class="cig-stat-label"><?php esc_html_e('სულ ჩარიცხვა', 'cig'); ?></div>
                    <div class="cig-stat-value" id="stat-total-company_transfer"><span class="loading-stat">...</span></div>
                    <div class="cig-stat-trend"><?php esc_html_e('Click to view invoices', 'cig'); ?></div>
                </div>
            </div>

            <div class="cig-stat-card" id="cig-card-credit" data-dropdown="invoices" data-method="credit">
                <div class="cig-stat-icon" style="background:#6c757d;">
                    <span class="dashicons dashicons-calendar-alt"></span>
                </div>
                <div class="cig-stat-content">
                    <div class="cig-stat-label"><?php esc_html_e('სულ განვადება', 'cig'); ?></div>
                    <div class="cig-stat-value" id="stat-total-credit"><span class="loading-stat">...</span></div>
                    <div class="cig-stat-trend"><?php esc_html_e('Click to view invoices', 'cig'); ?></div>
                </div>
            </div>

            <div class="cig-stat-card" id="cig-card-consignment" data-dropdown="invoices" data-method="consignment">
                <div class="cig-stat-icon" style="background:#ffc107;">
                    <span class="dashicons dashicons-clipboard"></span>
                </div>
                <div class="cig-stat-content">
                    <div class="cig-stat-label"><?php esc_html_e('სულ კონსიგნაცია', 'cig'); ?></div>
                    <div class="cig-stat-value" id="stat-total-consignment"><span class="loading-stat">...</span></div>
                    <div class="cig-stat-trend"><?php esc_html_e('Click to view invoices', 'cig'); ?></div>
                </div>
            </div>

            <div class="cig-stat-card" id="cig-card-other" data-dropdown="invoices" data-method="other">
                <div class="cig-stat-icon" style="background:#343a40;">
                    <span class="dashicons dashicons-editor-help"></span>
                </div>
                <div class="cig-stat-content">
                    <div class="cig-stat-label"><?php esc_html_e('სულ სხვა', 'cig'); ?></div>
                    <div class="cig-stat-value" id="stat-total-other"><span class="loading-stat">...</span></div>
                    <div class="cig-stat-trend"><?php esc_html_e('Click to view invoices', 'cig'); ?></div>
                </div>
            </div>


            

        </div>

        <div class="cig-summary-dropdown" id="cig-summary-invoices" style="display:none;">
            <div class="cig-summary-header">
                <h3 id="cig-summary-title"><?php esc_html_e('Invoices', 'cig'); ?></h3>
                <button type="button" class="button cig-summary-close" data-target="#cig-summary-invoices">✕</button>
            </div>
            <div class="cig-summary-body">
                <div class="cig-table-container">
                    <table class="cig-stats-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Invoice #', 'cig'); ?></th>
                                <th><?php esc_html_e('Customer', 'cig'); ?></th>
                                <th><?php esc_html_e('Payment Method', 'cig'); ?></th>
                                <th><?php esc_html_e('Total', 'cig'); ?></th>
                                <th style="color:#28a745;"><?php esc_html_e('Paid', 'cig'); ?></th>
                                <th style="color:#dc3545;"><?php esc_html_e('Due', 'cig'); ?></th>
                                <th><?php esc_html_e('Date', 'cig'); ?></th>
                                <th><?php esc_html_e('Author', 'cig'); ?></th>
                                <th><?php esc_html_e('Actions', 'cig'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="cig-summary-invoices-tbody">
                            <tr class="loading-row">
                                <td colspan="9"><div class="cig-loading-spinner"><div class="spinner"></div><p><?php esc_html_e('Loading...', 'cig'); ?></p></div></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="cig-summary-dropdown" id="cig-summary-outstanding" style="display:none;">
            <div class="cig-summary-header">
                <h3><?php esc_html_e('Outstanding Invoices', 'cig'); ?></h3>
                <button type="button" class="button cig-summary-close" data-target="#cig-summary-outstanding">✕</button>
            </div>
            <div class="cig-summary-body">
                <div class="cig-table-container">
                    <table class="cig-stats-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Invoice #', 'cig'); ?></th>
                                <th><?php esc_html_e('Customer', 'cig'); ?></th>
                                <th><?php esc_html_e('Payment Method', 'cig'); ?></th>
                                <th><?php esc_html_e('Total', 'cig'); ?></th>
                                <th style="color:#28a745;"><?php esc_html_e('Paid', 'cig'); ?></th>
                                <th style="color:#dc3545;"><?php esc_html_e('Remaining', 'cig'); ?></th>
                                <th><?php esc_html_e('Date', 'cig'); ?></th>
                                <th><?php esc_html_e('Author', 'cig'); ?></th>
                                <th><?php esc_html_e('Actions', 'cig'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="cig-summary-outstanding-tbody">
                            <tr class="loading-row">
                                <td colspan="9"><div class="cig-loading-spinner"><div class="spinner"></div><p><?php esc_html_e('Loading unpaid invoices...', 'cig'); ?></p></div></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="cig-stats-grid" style="grid-template-columns: 100%;">
            <div class="cig-table-card" id="cig-users-panel" style="grid-column: 1 / -1;">
                <div class="cig-section-header cig-users-header-inline">
                    <h2><?php esc_html_e('Performance by User', 'cig'); ?></h2>
                    <div class="cig-section-controls">
                        <input type="text" id="cig-user-search" class="cig-search-input" placeholder="<?php esc_attr_e('Search users...', 'cig'); ?>">
                        <select id="cig-users-per-page" class="cig-per-page-select">
                            <option value="20">20 per page</option>
                            <option value="50">50 per page</option>
                            <option value="100">100 per page</option>
                        </select>
                    </div>
                </div>
                <div class="cig-table-container">
                    <table class="cig-stats-table" id="cig-users-table">
                        <thead>
                            <tr>
                                <th class="sortable" data-sort="user_name"><?php esc_html_e('User', 'cig'); ?></th>
                                <th class="sortable" data-sort="invoice_count"><?php esc_html_e('Invoices', 'cig'); ?></th>
                                <th class="sortable" data-sort="total_sold"><?php esc_html_e('Sold', 'cig'); ?></th>
                                <th class="sortable" data-sort="total_reserved"><?php esc_html_e('Reserved', 'cig'); ?></th>
                                <th class="sortable" data-sort="total_canceled"><?php esc_html_e('Canceled', 'cig'); ?></th>
                                <th class="sortable" data-sort="total_revenue"><?php esc_html_e('Revenue', 'cig'); ?></th>
                                <th class="sortable" data-sort="last_invoice_date"><?php esc_html_e('Last Invoice', 'cig'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="cig-users-tbody">
                            <tr class="loading-row"><td colspan="7"><div class="cig-loading-spinner"><div class="spinner"></div><p><?php esc_html_e('Loading users...', 'cig'); ?></p></div></td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="cig-table-footer">
                    <div class="cig-pagination" id="cig-users-pagination"></div>
                </div>
            </div>

            <div class="cig-table-card cig-user-detail-panel" id="cig-user-detail-panel" style="display:none;">
                <div class="cig-section-header cig-user-detail-header-inline">
                    <div class="cig-user-detail-controls">
                        <button type="button" id="cig-back-to-users" class="button">
                            <span class="dashicons dashicons-arrow-left-alt"></span> <?php esc_html_e('Back to Users', 'cig'); ?>
                        </button>
                        <h2 id="cig-user-detail-title"><?php esc_html_e('User Invoices', 'cig'); ?></h2>
                    </div>
                    <div class="cig-user-invoices-filters-inline">
                        <input type="text" id="cig-invoice-search" class="cig-search-input" placeholder="<?php esc_attr_e('Search by invoice number...', 'cig'); ?>">
                        <select id="cig-user-payment-filter" class="cig-select-filter">
                            <option value="all"><?php esc_html_e('All Payment Methods', 'cig'); ?></option>
                            <?php foreach ($payment_types as $key => $label) { echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>'; } ?>
                        </select>
                        <select id="cig-invoices-per-page" class="cig-per-page-select">
                            <option value="30">30 per page</option>
                            <option value="50">50 per page</option>
                            <option value="100">100 per page</option>
                        </select>
                    </div>
                </div>
                <div class="cig-user-info-card" id="cig-user-info"></div>
                <div class="cig-table-container">
                    <table class="cig-stats-table" id="cig-user-invoices-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Invoice #', 'cig'); ?></th>
                                <th><?php esc_html_e('Date', 'cig'); ?></th>
                                <th><?php esc_html_e('Total Products', 'cig'); ?></th>
                                <th><?php esc_html_e('Sold', 'cig'); ?></th>
                                <th><?php esc_html_e('Reserved', 'cig'); ?></th>
                                <th><?php esc_html_e('Canceled', 'cig'); ?></th>
                                <th><?php esc_html_e('Invoice Total', 'cig'); ?></th>
                                <th><?php esc_html_e('Payment Method', 'cig'); ?></th>
                                <th><?php esc_html_e('Actions', 'cig'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="cig-user-invoices-tbody">
                            <tr class="loading-row"><td colspan="9"><div class="cig-loading-spinner"><div class="spinner"></div><p><?php esc_html_e('Loading invoices...', 'cig'); ?></p></div></td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="cig-table-footer">
                    <div class="cig-pagination" id="cig-invoices-pagination"></div>
                </div>
            </div>
        </div>
    </div>

    <div id="cig-tab-product" class="cig-tab-content" style="display:none;">
        <!-- Top Selling Products Filter Bar -->
        <div class="cig-stats-filters-bar" id="cig-top-products-filters">
            <div class="cig-filters-row">
                <div class="cig-filter-group">
                    <label><?php esc_html_e('Date Range:', 'cig'); ?></label>
                    <div class="cig-date-range">
                        <input type="date" id="cig-tp-date-from" class="cig-date-input">
                        <span>-</span>
                        <input type="date" id="cig-tp-date-to" class="cig-date-input">
                    </div>
                </div>
                <div class="cig-filter-group">
                    <label><?php esc_html_e('Search:', 'cig'); ?></label>
                    <input type="text" id="cig-tp-search" class="cig-search-input" placeholder="<?php esc_attr_e('Product Name or SKU...', 'cig'); ?>" style="min-width:200px;">
                </div>
                <div class="cig-filter-group">
                    <button type="button" id="cig-tp-apply-filters" class="button button-primary"><?php esc_html_e('Apply', 'cig'); ?></button>
                </div>
            </div>
        </div>

        <!-- Top Selling Products Table -->
        <div class="cig-table-card" id="cig-top-products-panel">
            <div class="cig-section-header cig-users-header-inline">
                <h2><?php esc_html_e('Top Selling Products', 'cig'); ?></h2>
            </div>
            <div class="cig-table-container">
                <table class="cig-stats-table" id="cig-top-products-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Product Name', 'cig'); ?></th>
                            <th><?php esc_html_e('SKU', 'cig'); ?></th>
                            <th><?php esc_html_e('Price', 'cig'); ?></th>
                            <th><?php esc_html_e('Sold Qty', 'cig'); ?></th>
                            <th><?php esc_html_e('Total Revenue', 'cig'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="cig-top-products-tbody">
                        <tr class="loading-row">
                            <td colspan="5">
                                <div class="cig-loading-spinner">
                                    <div class="spinner"></div>
                                    <p><?php esc_html_e('Loading top products...', 'cig'); ?></p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Legacy Product Insight Section (Hidden by default, shown when searching specific product) -->
        <div class="cig-product-search-hero" style="margin-top: 30px;">
            <input type="text" id="cig-product-insight-search" class="cig-hero-input" placeholder="<?php esc_attr_e('Search specific product for detailed insight...', 'cig'); ?>">
            <span class="dashicons dashicons-search cig-hero-icon"></span>
        </div>

        <div class="cig-stats-filters-bar" id="cig-product-filters" style="display:none;">
            <div class="cig-filters-row">
                <div class="cig-filter-group">
                    <label><?php esc_html_e('Time Period:', 'cig'); ?></label>
                    <div class="cig-quick-filters">
                        <button type="button" class="cig-pi-filter-btn active" data-filter="all_time"><?php esc_html_e('All Time', 'cig'); ?></button>
                        <button type="button" class="cig-pi-filter-btn" data-filter="this_month"><?php esc_html_e('This Month', 'cig'); ?></button>
                        <button type="button" class="cig-pi-filter-btn" data-filter="last_30_days"><?php esc_html_e('Last 30 Days', 'cig'); ?></button>
                    </div>
                </div>
                <div class="cig-filter-group">
                    <label><?php esc_html_e('Custom Range:', 'cig'); ?></label>
                    <div class="cig-date-range">
                        <input type="date" id="cig-pi-date-from" class="cig-date-input">
                        <span>-</span>
                        <input type="date" id="cig-pi-date-to" class="cig-date-input">
                        <button type="button" id="cig-pi-apply-date" class="button button-primary"><?php esc_html_e('Apply', 'cig'); ?></button>
                    </div>
                </div>
            </div>
        </div>

        <div id="cig-product-insight-results" style="display:none;">
            <div class="cig-pi-header">
                <div class="cig-pi-image-wrapper"><img id="cig-pi-img" src="" alt=""></div>
                <div class="cig-pi-details">
                    <h2 id="cig-pi-title"></h2>
                    <div class="cig-pi-meta">
                        <span class="cig-pi-sku-wrap">SKU: <strong id="cig-pi-sku"></strong></span>
                        <span class="cig-pi-price-wrap">Price: <strong id="cig-pi-price"></strong></span>
                    </div>
                </div>
            </div>

            <div class="cig-stats-summary">
                <div class="cig-stat-card"><div class="cig-stat-icon" style="background:#28a745;"><span class="dashicons dashicons-cart"></span></div><div class="cig-stat-content"><div class="cig-stat-label"><?php esc_html_e('Total Sold', 'cig'); ?></div><div class="cig-stat-value" id="cig-pi-sold">0</div></div></div>
                <div class="cig-stat-card"><div class="cig-stat-icon" style="background:#17a2b8;"><span class="dashicons dashicons-money-alt"></span></div><div class="cig-stat-content"><div class="cig-stat-label"><?php esc_html_e('Total Revenue', 'cig'); ?></div><div class="cig-stat-value" id="cig-pi-revenue">0.00 ₾</div></div></div>
                <div class="cig-stat-card"><div class="cig-stat-icon" style="background:#50529d;"><span class="dashicons dashicons-archive"></span></div><div class="cig-stat-content"><div class="cig-stat-label"><?php esc_html_e('In Stock', 'cig'); ?></div><div class="cig-stat-value" id="cig-pi-stock">0</div></div></div>
                <div class="cig-stat-card"><div class="cig-stat-icon" style="background:#ffc107;"><span class="dashicons dashicons-lock"></span></div><div class="cig-stat-content"><div class="cig-stat-label"><?php esc_html_e('Reserved', 'cig'); ?></div><div class="cig-stat-value" id="cig-pi-reserved">0</div></div></div>
            </div>

            <div class="cig-stats-grid">
                <div class="cig-table-card">
                    <div class="cig-section-header cig-users-header-inline"><h3><?php esc_html_e('Revenue by Payment Method', 'cig'); ?></h3></div>
                    <div class="cig-table-container"><table class="cig-stats-table"><thead><tr><th>Method</th><th>Revenue</th></tr></thead><tbody id="cig-pi-payments-tbody"></tbody></table></div>
                </div>
                <div class="cig-table-card">
                    <div class="cig-section-header cig-users-header-inline"><h3><?php esc_html_e('Other Statuses', 'cig'); ?></h3></div>
                    <div class="cig-table-container"><table class="cig-stats-table"><thead><tr><th>Status</th><th>Quantity</th><th>Note</th></tr></thead><tbody id="cig-pi-statuses-tbody"></tbody></table></div>
                </div>
            </div>

            <div class="cig-table-card" style="margin-top: 20px;">
                <div class="cig-section-header cig-users-header-inline"><h3><?php esc_html_e('Product Invoices History', 'cig'); ?></h3></div>
                <div class="cig-table-container"><table class="cig-stats-table"><thead><tr><th>Date</th><th>Invoice #</th><th>Customer</th><th>Type</th><th>Status</th><th>Qty</th><th>Unit Price</th><th>Total</th><th>Author</th></tr></thead><tbody id="cig-pi-invoices-tbody"></tbody></table></div>
            </div>
        </div>
        <div id="cig-pi-loading" style="display:none; text-align:center; padding:50px;"><div class="cig-loading-spinner"><div class="spinner"></div><p>Analyzing product data...</p></div></div>
    </div>

    <div id="cig-tab-customer" class="cig-tab-content" style="display:none;">
        <div class="cig-stats-filters-bar">
            <div class="cig-filters-row">
                <div class="cig-filter-group"><label><?php esc_html_e('Search Customer:', 'cig'); ?></label><input type="text" id="cig-customer-search" class="cig-search-input" placeholder="<?php esc_attr_e('Name or Tax ID...', 'cig'); ?>" style="min-width:250px;"></div>
                <div class="cig-filter-group"><label><?php esc_html_e('Date Range:', 'cig'); ?></label><div class="cig-date-range"><input type="date" id="cig-cust-date-from" class="cig-date-input"><span>-</span><input type="date" id="cig-cust-date-to" class="cig-date-input"><button type="button" id="cig-cust-apply-date" class="button button-primary"><?php esc_html_e('Apply', 'cig'); ?></button></div></div>
            </div>
        </div>
        <div id="cig-customer-list-panel" class="cig-table-card">
            <div class="cig-table-container"><table class="cig-stats-table" id="cig-customers-table"><thead><tr><th>Customer Name</th><th>Tax ID</th><th>Invoices</th><th>Total Revenue</th><th style="color:#28a745;">Paid</th><th style="color:#dc3545;">Due</th></tr></thead><tbody id="cig-customers-tbody"><tr class="loading-row"><td colspan="6"><div class="cig-loading-spinner"><div class="spinner"></div><p>Loading...</p></div></td></tr></tbody></table></div>
            <div class="cig-table-footer"><div class="cig-pagination" id="cig-customers-pagination"></div></div>
        </div>
        <div id="cig-customer-detail-panel" class="cig-table-card" style="display:none; margin-top:20px;">
            <div class="cig-section-header cig-user-detail-header-inline"><div class="cig-user-detail-controls"><button type="button" id="cig-back-to-customers" class="button"><span class="dashicons dashicons-arrow-left-alt"></span> <?php esc_html_e('Back to List', 'cig'); ?></button><h2 id="cig-customer-detail-title"></h2></div></div>
            <div class="cig-table-container"><table class="cig-stats-table"><thead><tr><th>Invoice #</th><th>Date</th><th>Total</th><th style="color:#28a745;">Paid</th><th style="color:#dc3545;">Due</th><th>Status</th><th>Action</th></tr></thead><tbody id="cig-cust-invoices-tbody"></tbody></table></div>
        </div>
    </div>

    <div id="cig-tab-external" class="cig-tab-content" style="display:none;">
        
        <div class="cig-stats-filters-bar">
            <div class="cig-filters-row">
                <div class="cig-filter-group">
                    <label><?php esc_html_e('Date Range:', 'cig'); ?></label>
                    <div class="cig-date-range">
                        <input type="date" id="cig-ext-date-from" class="cig-date-input">
                        <span>-</span>
                        <input type="date" id="cig-ext-date-to" class="cig-date-input">
                        <button type="button" id="cig-ext-apply-date" class="button button-primary"><?php esc_html_e('Apply', 'cig'); ?></button>
                    </div>
                </div>
            </div>
        </div>

        <div class="cig-stats-summary" style="grid-template-columns: repeat(3, 1fr);">
            
            <div class="cig-stat-card">
                <div class="cig-stat-icon" style="background:#343a40;">
                    <span class="dashicons dashicons-editor-help"></span>
                </div>
                <div class="cig-stat-content">
                    <div class="cig-stat-label"><?php esc_html_e('დაგროვილი ("სხვა")', 'cig'); ?></div>
                    <div class="cig-stat-value" id="cig-ext-accumulated">...</div>
                    <div class="cig-stat-trend"><?php esc_html_e('Selected Period', 'cig'); ?></div>
                </div>
            </div>

            <div class="cig-stat-card">
                <div class="cig-stat-icon" style="background:#28a745;">
                    <span class="dashicons dashicons-download"></span>
                </div>
                <div class="cig-stat-content">
                    <div class="cig-stat-label"><?php esc_html_e('ჩაბარებული', 'cig'); ?></div>
                    <div class="cig-stat-value" id="cig-ext-deposited">...</div>
                    <div class="cig-stat-trend"><?php esc_html_e('Selected Period', 'cig'); ?></div>
                </div>
            </div>

            <div class="cig-stat-card">
                <div class="cig-stat-icon" style="background:#dc3545;">
                    <span class="dashicons dashicons-chart-pie"></span>
                </div>
                <div class="cig-stat-content">
                    <div class="cig-stat-label"><?php esc_html_e('მიმდინარე ბალანსი', 'cig'); ?></div>
                    <div class="cig-stat-value" id="cig-ext-balance">...</div>
                    <div class="cig-stat-trend" style="color:#dc3545; font-weight:bold;"><?php esc_html_e('Total Due', 'cig'); ?></div>
                </div>
            </div>

        </div>

        <div style="margin: 20px 0; text-align: right;">
            <button type="button" id="cig-btn-add-deposit" class="button button-primary button-hero" style="background:#28a745; border-color:#28a745;">
                <span class="dashicons dashicons-plus-alt2" style="vertical-align:middle;"></span> <?php esc_html_e('თანხის ჩაბარება', 'cig'); ?>
            </button>
        </div>

        <div class="cig-table-card">
            <div class="cig-section-header cig-users-header-inline">
                <h3><?php esc_html_e('ჩაბარების ისტორია', 'cig'); ?></h3>
            </div>
            <div class="cig-table-container">
                <table class="cig-stats-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('თარიღი', 'cig'); ?></th>
                            <th><?php esc_html_e('კომენტარი', 'cig'); ?></th>
                            <th style="text-align:right;"><?php esc_html_e('თანხა', 'cig'); ?></th>
                            <th style="width:50px; text-align:center;"><?php esc_html_e('მოქმედება', 'cig'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="cig-ext-history-tbody">
                        <tr class="loading-row"><td colspan="4"><div class="cig-loading-spinner"><div class="spinner"></div><p>Loading...</p></div></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

</div>

<div id="cig-deposit-modal" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.6);">
    <div style="background:#fff; width:400px; margin:100px auto; padding:30px; border-radius:8px; position:relative; box-shadow:0 5px 15px rgba(0,0,0,0.3);">
        <button type="button" id="cig-close-deposit-modal" style="position:absolute; top:10px; right:15px; border:none; background:none; font-size:20px; cursor:pointer;">&times;</button>
        
        <h2 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:15px; margin-bottom:20px; color:#333;">
            <?php esc_html_e('თანხის ჩაბარება', 'cig'); ?>
        </h2>
        
        <div style="margin-bottom:15px;">
            <label style="display:block; font-weight:bold; margin-bottom:5px;"><?php esc_html_e('თარიღი', 'cig'); ?></label>
            <input type="date" id="cig-dep-date" class="regular-text" style="width:100%;" value="<?php echo current_time('Y-m-d'); ?>">
        </div>

        <div style="margin-bottom:15px;">
            <label style="display:block; font-weight:bold; margin-bottom:5px;"><?php esc_html_e('თანხა (GEL)', 'cig'); ?></label>
            <input type="number" id="cig-dep-amount" class="regular-text" style="width:100%;" placeholder="0.00" step="0.01">
        </div>

        <div style="margin-bottom:20px;">
            <label style="display:block; font-weight:bold; margin-bottom:5px;"><?php esc_html_e('კომენტარი', 'cig'); ?></label>
            <textarea id="cig-dep-note" class="regular-text" style="width:100%; height:80px;" placeholder="<?php esc_attr_e('შენიშვნა...', 'cig'); ?>"></textarea>
        </div>

        <div style="text-align:right;">
            <button type="button" id="cig-submit-deposit" class="button button-primary button-large" style="width:100%; justify-content:center;">
                <?php esc_html_e('დადასტურება', 'cig'); ?>
            </button>
        </div>
    </div>
</div>