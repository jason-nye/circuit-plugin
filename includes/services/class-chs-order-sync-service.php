<?php
class CHS_EventOrderSyncService {
    private CHS_API $api;
    private CHS_Sync $orderToBasketSync;
    private CHS_Sync $eventPackageToProductVariation;
    private CHS_Sync $simpleEventPackageToProduct;
    private ?string $basketToken = null;

    public function __construct() {
        $this->api = new CHS_API('product_variation');
        $this->orderToBasketSync = new CHS_Sync('order');
        $this->eventPackageToProductVariation = new CHS_Sync('product_variation', false, null, true);
        $this->simpleEventPackageToProduct = new CHS_Sync('simple_product');
        add_action('woocommerce_checkout_create_order', [$this, 'handleOrderProcessed'], 11); // After WC validation, before order creation
        add_action('woocommerce_checkout_order_created', [$this, 'attachBasketTokenToOrder'], 10, 1);
        add_action('woocommerce_payment_complete', [$this, 'handlePaymentComplete'], 11);
        add_action('woocommerce_order_status_pending-invoice', [$this, 'handleAdminOrderCreated'], 10, 1);
        add_action('woocommerce_order_status_completed', [$this, 'handleAdminOrderCreated'], 10, 1);
        add_action('woocommerce_order_action_chs_send_to_stockroom', [$this, 'handleAdminOrderCreated']); 
    }

    private function prepareOrderItems($items) {
        $orderItems = [];
        
        foreach ($items as $item) {
            $product_id = $item['product_id'];
            $variation_id = $item['variation_id'];
            $quantity = $item['quantity'];
            $note = isset($item['note']) ? $item['note'] : null;
            $attendees = [];

            // Handle simple product
            if(empty($variation_id)) {
                // Get event package ID from simpleEventPackageToProduct sync table
                $event_package_id = $this->simpleEventPackageToProduct->get($product_id);
                if (empty($event_package_id)) {
                    // If the mapping doesn't exist, log error
                    $this->stopCheckout(__('There was an issue with your order. Please contact support.', 'vipfootball'), 'Event package ID not found for the simple product (' . $product_id . '). The system is out of sync.');
                    return;
                }
            } else {
                // Handle variable product
                $event_package_id = $this->eventPackageToProductVariation->get($variation_id);
                if(empty($event_package_id)){
                    $this->stopCheckout(__('There was an issue with your order. Please contact support.', 'vipfootball'), 'Event package ID not found for the product variation (' . $variation_id . '). The system is out of sync.');
                    return;
                }
            }

            $orderItems[] = [
                'event_package_id' => (int) $event_package_id,
                'quantity' => (int) $quantity,
                'note' => is_string($note) ? $note : null,
                'order_attendees' => $attendees
            ];
        }
        
        return $orderItems;
    }

    private function prepareAddress($address_data) {
        
        return [
            'country' => $address_data['country'],
            'post_code' => $address_data['postcode'],
            'county' => !empty($address_data['state']) ? $address_data['state'] : null,
            'city' => $address_data['city'],
            'address_line_1' => $address_data['address_1'] ?? null,
            'address_line_2' => $address_data['address_2'] ?? null,
        ];
    }

    private function formatApiErrors($response): string {
        if (!isset($response->errors) || !is_object($response->errors)) {
            return $response->message ?? 'Unknown error occurred';
        }

        $errors = [];
        foreach ($response->errors as $field => $fieldErrors) {
            if (is_array($fieldErrors)) {
                $errors[] = implode(', ', $fieldErrors);
            }
        }

        return empty($errors) ? ($response->message ?? 'Unknown error occurred') : implode('. ', $errors);
    }

    private function createBasket($data) {
        $response = $this->api->post('basket', $data);
        
        if (isset($response->errors) || isset($response->message)) {
            $errorMessage = $this->formatApiErrors($response);
            $this->stopCheckout($errorMessage, 'Basket creation failed: ' . $errorMessage);
            return null;
        }
        
        return $response->data->id;
    }

    public function handleOrderProcessed() {
        echo 'handleOrderProcessed';
        $cart = WC()->cart;
        if (!$cart) {
            error_log('Cart not found');
            return;
        }

        $postdata = $_POST;
        
        try {
            $orderItems = $this->prepareOrderItems($cart->get_cart());
            
            $data = [
                'order_items' => $orderItems,
                'name' => trim($postdata['billing_first_name'] . ' ' . $postdata['billing_last_name']),
                'company_name' => !empty($postdata['billing_company']) ? $postdata['billing_company'] : null,
                'email' => $postdata['billing_email'],
                'billing_address' => $this->prepareAddress([
                    'address_1' => $postdata['billing_address_1'],
                    'address_2' => $postdata['billing_address_2'] ?? null,
                    'city' => $postdata['billing_city'],
                    'state' => $postdata['billing_state'],
                    'postcode' => $postdata['billing_postcode'],
                    'country' => $postdata['billing_country']
                ])
            ];
            
            $this->basketToken = $this->createBasket($data);
            
        } catch (Exception $e) {
            $this->stopCheckout(__('There was an issue with your order. Please check the details and try again.', 'vipfootball'), $e->getMessage());
        }
    }

    private function isFloatEqual($float1, $float2, $tolerance = 0.009) {
        return abs($float1 - $float2) < $tolerance;
    }

    public function handleAdminOrderCreated($order_id_or_order) {
        $order = $order_id_or_order instanceof WC_Order 
            ? $order_id_or_order 
            : wc_get_order($order_id_or_order);

        if (!$order) {
            error_log('Order not found for admin order creation: ' . $order_id_or_order);
            return;
        }

        // Check if already sent to CRM
        $existing_token = $order->get_meta('_chs_basket_token');
        if (!empty($existing_token)) {
            $order->add_order_note('Order already sent to CRM');
            return;
        }

        $order_id = $order->get_id();
        // Get invoice number instead of order ID
        $invoice_number = $order->get_meta('_order_number', true);
        // Fallback to order ID if invoice number is not available
        $reference = !empty($invoice_number) ? $invoice_number : ('post_id:' . $order_id);

        try {
            $orderItems = $this->prepareOrderItems($order->get_items());
            $index = 0;
            
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                
                // Get default prices
                $regular_price_net = (float) $product->get_regular_price();
                $regular_price_gross = (float) wc_get_price_including_tax($product, array('price' => $regular_price_net));
                
                // Get actual prices from order item
                $price_net = $item->get_total();
                $price_gross = $item->get_total() + $item->get_total_tax();
                $unit_price_net = (float) ($price_net / $item->get_quantity());
                $unit_price_gross = (float) ($price_gross / $item->get_quantity());
                
                // Check if either net or gross price is different from default
                if (!$this->isFloatEqual($unit_price_net, $regular_price_net) || 
                    !$this->isFloatEqual($unit_price_gross, $regular_price_gross)) {
                    
                    $orderItems[$index]['net_price'] = $price_net;
                    $orderItems[$index]['gross_price'] = $price_gross;
                }
                $index++;
            }

            $data = [
                'order_items' => $orderItems,
                'name' => $order->get_formatted_billing_full_name(),
                'company_name' => $order->get_billing_company(),
                'email' => $order->get_billing_email(),
                'billing_address' => $this->prepareAddress([
                    'address_1' => $order->get_billing_address_1(),
                    'address_2' => $order->get_billing_address_2(),
                    'city' => $order->get_billing_city(),
                    'state' => $order->get_billing_state(),
                    'postcode' => $order->get_billing_postcode(),
                    'country' => $order->get_billing_country()
                ]),
                'reference' => $reference,
                'status' => 'completed'
            ];

            if ($note = $order->get_customer_note()) {
                $data['note'] = $note;
            }

            // Direct call to orders endpoint
            $response = $this->api->post('orders', $data);
            
            if (isset($response->errors) || isset($response->message)) {
                $errorMessage = $this->formatApiErrors($response);
                $order->add_order_note(sprintf('CHS Order creation failed: %s', $errorMessage));
                // error_log('CHS Order creation failed for order ' . $order_id . ': ' . $errorMessage);
                $order->update_status('failed', 'CHS Order creation failed');
                return;
            }

            // Store the CHS order ID as basket token for consistency
            $order->update_meta_data('_chs_basket_token', $response->data->id);
            $order->save();

            // Store the relationship
            $this->orderToBasketSync->set($response->data->id, $order_id);
            $order->add_order_note('CHS Order created successfully');
            
        } catch (Exception $e) {
            $order->add_order_note(sprintf('CHS Order creation error: %s', $e->getMessage()));
            error_log('CHS Order creation error for order ' . $order_id . ': ' . $e->getMessage());
            $order->update_status('failed', 'CHS Order creation error:');
        }
    }

    public function attachBasketTokenToOrder($order) {
        if ($this->basketToken) {
            $order->update_meta_data('_chs_basket_token', $this->basketToken);
            $order->save();
        } else {
            // error_log('No basket token found for order: ' . $order->get_id());
        }
    }

    public function handlePaymentComplete($order_id) {
        
        $order = wc_get_order($order_id);
        if (!$order) {
            // error_log('Order not found for payment completion: ' . $order_id);
            return;
        }

        $basket_token = get_post_meta($order_id, '_chs_basket_token', true);
        if (empty($basket_token)) {
            // error_log('Basket token not found for order: ' . $order_id);
            return;
        }

        try {
            // Get invoice number instead of order ID
            $invoice_number = $order->get_meta('_order_number', true);
            // Fallback to order ID if invoice number is not available
            $reference = !empty($invoice_number) ? $invoice_number : ('post_id:' . $order_id);

            $data = [
                'reference' => $reference,
                'status' => 'completed'
            ];

            $note = $order->get_customer_note();

            if (!empty($note)) {
                $data['note'] = $note;
            }

            $response = $this->api->post("basket/{$basket_token}/place-order", $data);
            
            if (isset($response->errors) || isset($response->message)) {
                $errorMessage = $this->formatApiErrors($response);
                $order->add_order_note(sprintf(
                    'CHS Order creation failed, please contact Stockroom: %s',
                    $errorMessage
                ));
                // error_log('CHS Checkout failed for order ' . $order_id . ': ' . $errorMessage);
                return;
            }

            // Store the relationship between order and basket
            $this->orderToBasketSync->set($basket_token, $order_id);

            $order->add_order_note('CHS Checkout completed successfully');
            
        } catch (Exception $e) {
            $order->add_order_note(sprintf(
                'CHS Checkout error: %s',
                $e->getMessage()
            ));
            error_log('CHS Checkout error for order ' . $order_id . ': ' . $e->getMessage());
        }
    }

    public function stopCheckout($message, $logMessage = null) {
        if (defined('WP_DEBUG') && WP_DEBUG && $logMessage) {
            $message .= ' Debug: ' . $logMessage;
        }
        
        if($logMessage) {
            error_log($logMessage);
        }
        throw new Exception($message);
    }
}
