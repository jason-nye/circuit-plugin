<?php
/**
 * CHS Order Status Class
 * 
 * Handles custom order statuses for WooCommerce
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CHS_Order_Status {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Register the custom order status on init hook for proper timing
        add_action('init', array($this, 'register_pending_invoice_status'), 10);
        
        // Add the filter for order actions
        add_filter('woocommerce_order_actions', array($this, 'add_order_action'));
    }
    
    /**
     * Register the custom pending invoice status
     */
    public function register_pending_invoice_status() {
        // Check if the status doesn't already exist
        if (
            !get_term_by('slug', 'pending-invoice', 'shop_order_status') &&
            !in_array('wc-pending-invoice', array_keys(wc_get_order_statuses()))
        ) {
            // Register new status with WooCommerce
            register_post_status('wc-pending-invoice', array(
                'label'                     => 'Pending Invoice Payment',
                'public'                    => true,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                'label_count'               => _n_noop(
                    'Pending Invoice Payment <span class="count">(%s)</span>',
                    'Pending Invoice Payment <span class="count">(%s)</span>'
                )
            ));
            
            // Add to order statuses list
            add_filter('wc_order_statuses', array($this, 'add_pending_invoice_to_order_statuses'));
        }
    }
    
    /**
     * Add the custom status to WooCommerce order statuses
     */
    public function add_pending_invoice_to_order_statuses($order_statuses) {
        // Create a new array with our status as the first item
        $new_statuses = array(
            'wc-pending-invoice' => 'Pending Invoice Payment'
        );
        
        // Add all the existing statuses after our custom one
        foreach ($order_statuses as $key => $status) {
            $new_statuses[$key] = $status;
        }
        
        return $new_statuses;
    }
    
    /**
     * Add custom order action to the dropdown
     */
    public function add_order_action($actions) {
        // Add our custom action
        $actions['chs_send_to_stockroom'] = __('Resend to Stockroom', 'circuit-hospitality-sync');
        return $actions;
    }
}