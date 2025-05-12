<?php

if (!class_exists('CHS_Settings')) {
    class CHS_Settings {
        
        public function __construct() {
            add_action('admin_menu', [$this, 'add_admin_menus']);
            add_action('admin_init', [$this, 'register_settings']);
        }

        public function add_admin_menus() {
            // Add a submenu under the top-level menu
            add_submenu_page(
                'chs_dashboard',             // Parent slug
                'Settings',                  // Page title
                'Settings',                  // Submenu title
                'manage_options',            // Capability
                'chs_settings',              // Menu slug
                [$this, 'settings_page_callback'] // Callback function
            );
        }

        public function register_settings() {
            register_setting('chs_options_group', 'chs_api_key', ['sanitize_callback' => 'sanitize_text_field']);
            register_setting('chs_options_group', 'chs_api_url', ['sanitize_callback' => 'esc_url_raw']);
            
            add_settings_section('chs_main_section', 'Main Settings', null, 'chs_settings');
            
            add_settings_field('chs_api_key', 'API Key', [$this, 'api_key_field_callback'], 'chs_settings', 'chs_main_section');
            add_settings_field('chs_api_url', 'API URL', [$this, 'api_url_field_callback'], 'chs_settings', 'chs_main_section');
        }

        public function settings_page_callback() {
            ?>
            <div class="wrap">
                <h1>Circuit Hospitality Sync Settings</h1>
                <form method="post" action="options.php">
                    <?php
                    settings_fields('chs_options_group');
                    do_settings_sections('chs_settings');
                    submit_button();
                    ?>
                </form>
            </div>
            <?php
        }

        public function api_key_field_callback() {
            $apiKey = get_option('chs_api_key');
            echo '<input type="text" name="chs_api_key" value="' . esc_attr($apiKey) . '" />';
        }

        public function api_url_field_callback() {
            $apiUrl = get_option('chs_api_url');
            echo '<input type="text" name="chs_api_url" value="' . esc_attr($apiUrl) . '" placeholder="https://example.com" />';
        }
    }
}