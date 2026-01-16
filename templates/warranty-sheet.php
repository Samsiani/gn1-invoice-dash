<?php
/**
 * Warranty Sheet Template
 *
 * @package CIG
 * @since 3.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

global $post;
$invoice_id = $post->ID;

// Settings
$settings = get_option('cig_settings', []);
$company_logo = $settings['company_logo'] ?? '';
$warranty_text = $settings['warranty_text'] ?? ''; // From new setting

// Get invoice data from CIG_Invoice_Manager (uses custom tables with fallback to post meta)
$invoice_manager = CIG_Invoice_Manager::instance();
$invoice_data = $invoice_manager->get_invoice_by_post_id($invoice_id);
$invoice = $invoice_data['invoice'] ?? [];
$items_raw = $invoice_data['items'] ?? [];
$customer = $invoice_data['customer'] ?? [];

$invoice_number = $invoice['invoice_number'] ?? '';
$buyer_name = $customer['name'] ?? '';

// Use sold_date for warranty sheet display, fallback to sale_date, then created_at or post date
$sold_date = $invoice['sold_date'] ?? '';
if (!empty($sold_date)) {
    $date = $sold_date; // sold_date is already in Y-m-d format
} elseif (!empty($invoice['sale_date'])) {
    $date = date('Y-m-d', strtotime($invoice['sale_date']));
} else {
    $date = get_the_date('Y-m-d', $invoice_id);
}

// Filter items (Optional: remove empty rows)
$items = array_filter($items_raw, function($item) {
    // Support both custom table field names and legacy field names
    $name = $item['product_name'] ?? $item['name'] ?? '';
    return !empty($name); 
});

// Warranty Map
$warranty_map = [
    '6m' => __('6 თვე', 'cig'),
    '1y' => __('1 წელი', 'cig'),
    '2y' => __('2 წელი', 'cig'),
    '3y' => __('3 წელი', 'cig')
];
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php esc_html_e('Warranty Sheet', 'cig'); ?> - <?php echo esc_html($invoice_number); ?></title>
    <link rel="stylesheet" href="<?php echo esc_url(CIG_ASSETS_URL . 'css/invoice.css'); ?>">
    <style>
        /* Specific overrides for Warranty Sheet */
        body { background: #f0f0f0; padding: 20px; font-family: 'FiraGO', sans-serif; }
        .warranty-page { max-width: 900px; margin: 0 auto; background: #fff; padding: 40px; box-shadow: 0 0 10px rgba(0,0,0,0.1); min-height: 297mm; }
        
        .w-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; border-bottom: 2px solid #50529d; padding-bottom: 20px; }
        .w-logo img { max-height: 60px; width: auto; }
        .w-title { font-size: 24px; font-weight: bold; text-transform: uppercase; color: #333; }
        
        .w-info { margin-bottom: 30px; }
        .w-info-row { display: flex; margin-bottom: 10px; font-size: 14px; }
        .w-info-label { font-weight: bold; width: 150px; color: #555; }
        .w-info-val { color: #000; font-weight: 500; }
        
        /* Table overrides */
        #invoice-table { margin-bottom: 30px; width: 100%; border-collapse: collapse; }
        #invoice-table th { background: #f8f9fa; color: #333; font-weight: bold; border: 1px solid #ddd; padding: 10px; }
        #invoice-table td { border: 1px solid #ddd; padding: 10px; vertical-align: middle; }
        
        .w-terms { margin-top: 40px; font-size: 12px; line-height: 1.6; color: #444; border-top: 1px solid #eee; padding-top: 20px; text-align: justify; }
        
        .w-signatures { margin-top: 80px; display: flex; justify-content: space-between; page-break-inside: avoid; }
        .w-sign-box { width: 40%; border-top: 1px solid #000; padding-top: 10px; text-align: center; font-size: 13px; font-weight: bold; }
        
        @media print {
            body { background: #fff; padding: 0; }
            .warranty-page { box-shadow: none; padding: 0; max-width: 100%; margin: 0; width: 100%; border: none; }
            .no-print { display: none !important; }
            .w-header { border-bottom-color: #000; }
        }
    </style>
</head>
<body>
    
    <div class="no-print" style="max-width:900px; margin:0 auto 20px; text-align:right;">
        <button onclick="window.print()" class="button" style="padding:10px 20px; cursor:pointer; background:#50529d; color:#fff; border:none; border-radius:4px; font-weight:bold; font-family:inherit;">
            <?php esc_html_e('ბეჭდვა', 'cig'); ?>
        </button>
    </div>

    <div class="warranty-page">
        <div class="w-header">
            <div class="w-logo">
                <?php if ($company_logo): ?>
                    <img src="<?php echo esc_url($company_logo); ?>" alt="Logo">
                <?php endif; ?>
            </div>
            <div class="w-title"><?php esc_html_e('საგარანტიო', 'cig'); ?></div>
        </div>

        <div class="w-info">
            <div class="w-info-row">
                <span class="w-info-label"><?php esc_html_e('მყიდველი:', 'cig'); ?></span>
                <span class="w-info-val"><?php echo esc_html($buyer_name ?: '________________'); ?></span>
            </div>
            <div class="w-info-row">
                <span class="w-info-label"><?php esc_html_e('ინვოისის N:', 'cig'); ?></span>
                <span class="w-info-val"><?php echo esc_html($invoice_number); ?></span>
            </div>
            <div class="w-info-row">
                <span class="w-info-label"><?php esc_html_e('თარიღი:', 'cig'); ?></span>
                <span class="w-info-val"><?php echo esc_html($date); ?></span>
            </div>
        </div>

        <table id="invoice-table">
            <thead>
                <tr>
                    <th class="col-n" style="width:50px; text-align:center;">#</th>
                    <th class="col-name"><?php esc_html_e('პროდუქტის დასახელება', 'cig'); ?></th>
                    <th class="col-sku" style="width:120px;"><?php esc_html_e('კოდი (SKU)', 'cig'); ?></th>
                    <th class="col-qty" style="width:80px; text-align:center;"><?php esc_html_e('რაოდენობა', 'cig'); ?></th>
                    <th style="width:120px; text-align:center;"><?php esc_html_e('გარანტია', 'cig'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $n = 1;
                foreach ($items as $item): 
                    // Support both custom table field names and legacy field names
                    $w_key = $item['warranty_duration'] ?? $item['warranty'] ?? '';
                    $w_label = isset($warranty_map[$w_key]) ? $warranty_map[$w_key] : '---';
                    $item_name = $item['product_name'] ?? $item['name'] ?? '';
                    $item_sku = $item['sku'] ?? '';
                    $item_qty = $item['quantity'] ?? $item['qty'] ?? 0;
                ?>
                <tr>
                    <td class="col-n" style="text-align:center;"><?php echo $n++; ?></td>
                    <td class="col-name"><?php echo esc_html($item_name); ?></td>
                    <td class="col-sku" style="font-family:monospace;"><?php echo esc_html($item_sku); ?></td>
                    <td class="col-qty" style="text-align:center;"><?php echo esc_html($item_qty); ?></td>
                    <td style="text-align:center; font-weight:bold;"><?php echo esc_html($w_label); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($warranty_text): ?>
        <div class="w-terms">
            <?php echo wp_kses_post(wpautop($warranty_text)); ?>
        </div>
        <?php endif; ?>

        
    </div>

</body>
</html>