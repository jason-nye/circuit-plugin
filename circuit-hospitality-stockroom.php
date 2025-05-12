<?php
/*
Plugin Name: Circuit Hospitality Stockroom Sync for WooCommerce
Description: Synchronizes products between Circuit Hospitality Stockroom CRM and WooCommerce
Version: 1.0
Author: Mr Pelso and Co
*/

// Define a constant for the plugin path.
define('CHS_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Include the Settings class
require_once CHS_PLUGIN_DIR . 'includes/admin/class-chs-settings.php';
require_once CHS_PLUGIN_DIR . 'includes/admin/class-chs-dashboard.php';
require_once CHS_PLUGIN_DIR . 'includes/install/class-chs-migration.php';
require_once CHS_PLUGIN_DIR . 'includes/admin/class-chs-order-status.php'; 
require_once CHS_PLUGIN_DIR . 'includes/services/class-chs-webhook-handler-service.php';
require_once CHS_PLUGIN_DIR . 'includes/services/class-chs-order-sync-service.php';
require_once CHS_PLUGIN_DIR . 'includes/admin/class-chs-admin-tools.php';

// Add this function to check and display the notice
function chs_admin_notice() {
    if (!get_option('chs_api_key')) {
        ?>
        <div class="notice notice-error">
            <p>Circuit Hospitality Sync requires configuration. Please go to 
                <a href="<?php echo admin_url('admin.php?page=chs_settings'); ?>">Settings Page</a> 
                to add your API key.
            </p>
        </div>
        <?php
    }
}
add_action('admin_notices', 'chs_admin_notice');

// Initialize the plugin components
function chs_plugin_init() {
    // Instantiate the settings class to ensure the admin menus and settings are registered
    new CHS_Dashboard();
    new CHS_Settings();
    new CHS_Migration();
    new CHS_Order_Status();
    new CHS_WebhookHandlerService();
    new CHS_EventOrderSyncService();
    
    // Perform any additional initialization tasks here
    if (is_admin()) {
        new CHS_Admin_Tools();
    }
}

// Hook into WordPress `plugins_loaded` action to trigger your initialization function
add_action('plugins_loaded', 'chs_plugin_init');