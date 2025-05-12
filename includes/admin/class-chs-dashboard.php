<?php
require_once CHS_PLUGIN_DIR . 'includes/services/class-chs-event-sync-service.php';

if (!class_exists('CHS_Dashboard')) {
    class CHS_Dashboard {
        
        public function __construct() {
            add_action('admin_menu', [$this, 'add_dashboard_menu']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
            add_action('wp_ajax_chs_fetch_events', [$this, 'fetch_events_callback']);
        }

        public function add_dashboard_menu() {
            add_menu_page(
                'Circuit Hospitality Sync',  // Page title
                'Circuit Sync',              // Menu title
                'manage_options',            // Capability
                'chs_dashboard',             // Menu slug
                [$this, 'dashboard_page_callback'], // Callback function
                'dashicons-update',          // Icon URL
                56                           // Position
            );
            add_submenu_page(
                'chs_dashboard',             // Parent slug (same as menu slug of the main menu)
                'Dashboard',                 // Page title
                'Dashboard',                 // Sub-menu title
                'manage_options',            // Capability
                'chs_dashboard',   // Sub-menu slug
                [$this, 'dashboard_page_callback'] // Same callback function for simplicity
            );
        }

        public function dashboard_page_callback() {
            ?>
            <div class="wrap">
                <h1>Circuit Hospitality Dashboard</h1>
                <p>Welcome to the dashboard. Here you will find the main features and use cases of the plugin.</p>
                <p>
                    <strong>Webhook URL:</strong>
                    <br>
                    <code><?php echo esc_url(home_url('/chs-webhook')); ?></code>
                </p>
                <button id="fetch-events-button" class="button button-primary">Fetch Events</button>
                <div id="fetch-events-result"></div>
                <div style="margin-top: 10px;">
                    <div id="fetch-events-progress" style="background-color: #007cba; height: 20px; width: 0%;"></div>
                    <p id="fetch-events-counter"></p>
                </div>
            </div>
            <?php
        }

        public function enqueue_scripts($hook) {
            if ($hook !== 'toplevel_page_chs_dashboard') {
                return;
            }

            wp_enqueue_script('chs-dashboard-script', '/wp-content/plugins/vipfootball-plugin/assets/js/chs-dashboard.js', ['jquery'], null, true);
            
            // Pass AJAX URL and nonce to JavaScript
            wp_localize_script('chs-dashboard-script', 'chsDashboard', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('chs_fetch_events_nonce'),
            ]);
        }

        public function fetch_events_callback() {
            check_ajax_referer('chs_fetch_events_nonce', 'security');
            
            if (class_exists('CHS_EventSyncService')) {
                try {
                    $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
                    
                    // Create an instance of the sync service
                    $eventSyncService = new CHS_EventSyncService();
                    
                    // Sync a single page of events and get the total number of pages
                    $totalPages = $eventSyncService->syncEventPage($page);
                    
                    wp_send_json_success(['totalPages' => $totalPages, 'currentPage' => $page]);
                } catch (Exception $e) {
                    wp_send_json_error(['message' => $e->getMessage()]);
                }
            } else {
                wp_send_json_error(['message' => 'CHS_EventSyncService class not found.']);
            }
        }
    }
}
