<?php
/**
 * Plugin Name: WooCommerce Event Sync
 */

class CHS_Migration {

    public function __construct() {
        global $wpdb;
        // Hook into plugin activation
        register_activation_hook(__FILE__, [$this, 'migrate']);
        
        // Check if table exists, create it if needed
      //  add_action('plugins_loaded', [$this, 'check_and_migrate']);
    }
    
    public function check_and_migrate() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'chs_sync';
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
        
        if (!$table_exists) {
            // Table doesn't exist, run migration
            $this->migrate();
        }
    }

    private function migrate() {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$wpdb->prefix}chs_sync (
            id INT AUTO_INCREMENT PRIMARY KEY,
            model_name VARCHAR(100) NOT NULL,
            source_id VARCHAR(100) NOT NULL,
            target_id VARCHAR(100) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_source_id (model_name(50), source_id(50)),
            KEY idx_target_id (model_name(50), target_id(50))
        ) $charset_collate;";

        $result = dbDelta($sql);
        
        // Add error logging
        if (!empty($wpdb->last_error)) {
            error_log('Database Error: ' . $wpdb->last_error);
            error_log('SQL Query: ' . $sql);
        }
    }
}
?>