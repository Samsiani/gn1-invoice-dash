<?php
/**
 * Database Schema Manager
 * Creates and manages custom tables for the CIG plugin
 *
 * @package CIG
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CIG_Database {

    /**
     * Create custom tables for the plugin
     *
     * @return void
     */
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();

        // Invoices Table
        $table_invoices = $wpdb->prefix . 'cig_invoices';
        $sql_invoices = "CREATE TABLE $table_invoices (
            id bigint(20) NOT NULL,
            invoice_number varchar(50) NOT NULL,
            type varchar(20) DEFAULT 'standard',
            customer_name varchar(255) DEFAULT '',
            customer_tax_id varchar(50) DEFAULT '',
            total decimal(12,2) DEFAULT 0,
            paid decimal(12,2) DEFAULT 0,
            balance decimal(12,2) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            activation_date datetime DEFAULT NULL,
            sold_date date DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY invoice_number (invoice_number),
            KEY type (type),
            KEY activation_date (activation_date),
            KEY sold_date (sold_date)
        ) $charset_collate;";

        // Invoice Items Table
        $table_items = $wpdb->prefix . 'cig_invoice_items';
        $sql_items = "CREATE TABLE $table_items (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            invoice_id bigint(20) NOT NULL,
            product_id bigint(20) DEFAULT 0,
            sku varchar(100) DEFAULT '',
            name varchar(255) NOT NULL,
            brand varchar(100) DEFAULT '',
            description text,
            image varchar(500) DEFAULT '',
            qty decimal(10,2) DEFAULT 1,
            price decimal(12,2) DEFAULT 0,
            total decimal(12,2) DEFAULT 0,
            warranty varchar(20) DEFAULT '',
            reservation_days int(11) DEFAULT 0,
            status varchar(20) DEFAULT 'sold',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY invoice_id (invoice_id),
            KEY product_id (product_id),
            KEY status (status)
        ) $charset_collate;";

        // Payments Table
        $table_payments = $wpdb->prefix . 'cig_payments';
        $sql_payments = "CREATE TABLE $table_payments (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            invoice_id bigint(20) NOT NULL,
            date date NOT NULL,
            amount decimal(12,2) DEFAULT 0,
            payment_method varchar(50) DEFAULT 'other',
            comment text,
            user_id bigint(20) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY invoice_id (invoice_id),
            KEY date (date),
            KEY payment_method (payment_method)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        dbDelta($sql_invoices);
        dbDelta($sql_items);
        dbDelta($sql_payments);
    }

    /**
     * Check if all required tables exist
     *
     * @return bool
     */
    public static function tables_exist() {
        global $wpdb;
        
        $tables = [
            $wpdb->prefix . 'cig_invoices',
            $wpdb->prefix . 'cig_invoice_items',
            $wpdb->prefix . 'cig_payments'
        ];

        foreach ($tables as $table) {
            $result = $wpdb->get_var($wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $table
            ));
            if ($result !== $table) {
                return false;
            }
        }

        return true;
    }

    /**
     * Drop all custom tables
     *
     * @return void
     */
    public static function drop_tables() {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'cig_payments',
            $wpdb->prefix . 'cig_invoice_items',
            $wpdb->prefix . 'cig_invoices'
        ];

        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }
    }
}
