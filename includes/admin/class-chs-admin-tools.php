<?php
class CHS_Admin_Tools {
    public function __construct() {
        add_action('admin_menu', [$this, 'register_admin_page']);
    }
    
    public function register_admin_page() {
        add_submenu_page(
            'tools.php',                // Parent slug
            'CHS Order Testing Tools',  // Page title
            'CHS Order Tools',          // Menu title
            'manage_options',           // Capability
            'chs-order-tools',          // Menu slug
            [$this, 'render_admin_page'] // Callback
        );
    }
    
    public function render_admin_page() {
        // Handle form submission
        if (isset($_POST['chs_complete_order']) && isset($_POST['order_id']) && 
            wp_verify_nonce($_POST['chs_nonce'], 'chs_complete_order')) {
            
            $order_id = intval($_POST['order_id']);
            $this->trigger_payment_complete($order_id);
        }
        
        // Display the form
        ?>
        <div class="wrap">
            <h1>CHS Order Testing Tools</h1>
            
            <div class="card">
                <h2>Trigger Payment Complete</h2>
                <p>This will trigger the woocommerce_payment_complete hook for the specified order.</p>
                
                <form method="post">
                    <?php wp_nonce_field('chs_complete_order', 'chs_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="order_id">Order ID</label></th>
                            <td>
                                <input type="number" name="order_id" id="order_id" value="1854" class="regular-text">
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" name="chs_complete_order" class="button button-primary" value="Trigger Payment Complete">
                    </p>
                </form>
            </div>
            
            <?php if (isset($_POST['chs_complete_order'])): ?>
                <div class="card" style="margin-top: 20px;">
                    <h2>Results</h2>
                    <pre><?php echo $this->output; ?></pre>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    private $output = '';
    
    private function trigger_payment_complete($order_id) {
        // Buffer output for display
        ob_start();
        
        try {
            // Get order
            $order = wc_get_order($order_id);
            
            if ($order) {
                // Check current status
                $old_status = $order->get_status();
                echo "Current order status: $old_status\n";
                
                // Force order to "on-hold" if needed
                if (in_array($old_status, ['completed', 'processing'])) {
                    $order->update_status('on-hold', 'Temporarily changing status to test payment_complete hook');
                    echo "Status temporarily changed to on-hold\n";
                }
                
                // Check/set basket token if needed
                $basket_token = get_post_meta($order_id, '_chs_basket_token', true);
                if (empty($basket_token)) {
                    $test_token = 'test_basket_' . time();
                    update_post_meta($order_id, '_chs_basket_token', $test_token);
                    echo "Added test basket token: $test_token\n";
                } else {
                    echo "Existing basket token found: $basket_token\n";
                }
                
                // Call payment_complete() to trigger the hook
                $order->payment_complete();
                echo "payment_complete() called - hook should have triggered\n";
                
                // Get new status
                $new_status = $order->get_status();
                echo "New order status: $new_status\n";
                
            } else {
                echo "Order #$order_id not found!\n";
                
                // List some available orders
                $orders = wc_get_orders(['limit' => 5, 'orderby' => 'date', 'order' => 'DESC']);
                
                if (!empty($orders)) {
                    echo "Available recent orders:\n";
                    foreach ($orders as $o) {
                        echo "- Order #{$o->get_id()} ({$o->get_status()})\n";
                    }
                }
            }
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
        }
        
        $this->output = ob_get_clean();
    }
}
