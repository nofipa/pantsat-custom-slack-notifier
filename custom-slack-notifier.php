<?php
/*
Plugin Name: Morten's custom order notifier
Description: Notify slack channel when a new WooCommerce order is created.
Version: 1.0.4
Author: Morten 🧙
*/

/**
 * 
 * HOOKS INTO ADMIN SETTINGS
 * 
 */

// Hook into the WooCommerce settings menu
add_filter('woocommerce_settings_tabs_array', 'custom_woocommerce_order_notifications_settings_tab', 50);

function custom_woocommerce_order_notifications_settings_tab($settings_tabs)
{
    $settings_tabs['custom_order_notifications'] = __('Mortens notifications', 'custom-woocommerce-order-notifications');
    return $settings_tabs;
}

// Create the settings form
add_action('woocommerce_settings_tabs_custom_order_notifications', 'custom_woocommerce_order_notifications_settings');

function custom_woocommerce_order_notifications_settings()
{
    woocommerce_admin_fields(custom_woocommerce_order_notifications_settings_fields());
}

// Define the settings fields
function custom_woocommerce_order_notifications_settings_fields()
{
    return array(
        'section_title' => array(
            'name' => __('Slack Notification Settings', 'custom-woocommerce-order-notifications'),
            'type' => 'title',
            'desc' => '',
            'id' => 'custom_slack_notifications_section_title'
        ),
        'slack_channel' => array(
            'name' => __('Slack Channel ID', 'custom-woocommerce-order-notifications'),
            'type' => 'text',
            'desc' => __('Enter the Slack channel ID where you want to receive order notifications.', 'custom-woocommerce-order-notifications'),
            'id' => 'custom_slack_notifications_channel'
        ),
        'slack_api_key' => array(
            'name' => __('Slack API Key', 'custom-woocommerce-order-notifications'),
            'type' => 'password',
            'desc' => __('Enter the Slack API key for authentication.', 'custom-woocommerce-order-notifications'),
            'id' => 'custom_slack_notifications_api_key'
        ),
        'section_end' => array(
            'type' => 'sectionend',
            'id' => 'custom_slack_notifications_section_end'
        )
    );
}

// Save the settings
add_action('woocommerce_update_options_custom_order_notifications', 'custom_woocommerce_order_notifications_save_settings');

function custom_woocommerce_order_notifications_save_settings()
{
    woocommerce_update_options(custom_woocommerce_order_notifications_settings_fields());
}

/**
 * 
 * ADMIN SECTION DONE
 * 
 */


function custom_send_order_notification($order_id, $demo = FALSE)
{
    // Get the saved Slack settings
    $slack_channel = get_option('custom_slack_notifications_channel');
    $slack_api_key = get_option('custom_slack_notifications_api_key');

    // Verify that both Slack channel and API key are set
    if (!empty($slack_channel) && !empty($slack_api_key)) {
        // Get the order object
        $order = wc_get_order($order_id);

        // Get order details
        $order_data = $order->get_data();
        $order_total = $order_data['total'];
        $order_number = $order_data['id'];
        $order_status = $order_data['status'];
        $order_items = $order->get_items();

        // Get billing details
        $billing_first_name = $order_data['billing']['first_name'];
        $billing_last_name = $order_data['billing']['last_name'];
        $billing_email = $order_data['billing']['email'];
        $billing_phone = $order_data['billing']['phone'];

        // Add shipping method information
        $shipping_method = $order->get_shipping_method();

        // Construct the Slack message
        $message = "🎉🎉 New WooCommerce Order #$order_number 🎉🎉\n\n";

        $message .= "*Billing Name:* $billing_first_name $billing_last_name\n";
        $message .= "*Billing Email:* $billing_email\n";
        $message .= "*Billing Phone:* $billing_phone\n\n";

        // Add shipping method information
        $message .= "*Shipping Method:* $shipping_method\n\n";

        $message .= "*Order Status:* $order_status\n";
        $message .= "*Order Total:* " . number_format($order_total, 2, ',', '.') . " DKK \n\n";

        $message .= "*Order Items:*\n";
        foreach ($order_items as $item) {
            $product = $item->get_product();
            $product_name = $product->get_name();
            $item_quantity = $item->get_quantity();
            $item_total = number_format($item->get_total(), 2, ',', '.') . " DKK";
            $item_single_price = $item->get_total() / $item_quantity;
            $item_single_price_formatted = number_format($item_single_price, 2, ',', '.') . "DKK";
            
            // Fixed attribute handling for custom attributes
            $department = '';
            try {
                $product_attributes = $product->get_attributes();
                if (isset($product_attributes['department']) && is_object($product_attributes['department'])) {
                    $department = $product_attributes['department']->get_options()[0] ?? '';
                } else {
                    // Get raw attributes as they might be custom attributes
                    $raw_attributes = $product->get_data()['attributes'];
                    if (isset($raw_attributes['department'])) {
                        $department = $raw_attributes['department'];
                    }
                }
            } catch (Exception $e) {
                error_log('Error getting department: ' . $e->getMessage());
            }

            $message .= "• $item_quantity x $product_name ($department) af $item_single_price_formatted\n";
        }
        $message .= "Total repo: *" . number_format($order_total, 2, ',', '.') . " DKK*\n";

        // Add a link to the WooCommerce order page
        $order_edit_url = admin_url("post.php?post=$order_number&action=edit");
        $message .= "\n*Order Details:* <$order_edit_url|View Order Details>\n";

        if ($demo) {
            $message = "*---------------DEMO---------------*\n\n\n" . $message . "\n\n*---------------DEMO---------------*";
        }

        // Send the Slack notification
        custom_send_slack_notification($slack_channel, $slack_api_key, $message);
    }
}

function custom_send_slack_notification($channel, $api_key, $message)
{
    // Construct the Slack API URL
    $slack_api_url = "https://slack.com/api/chat.postMessage";

    // Set the message parameters
    $message_data = array(
        'channel' => $channel,
        'text' => $message
    );

    // Prepare the HTTP headers with the API key
    $headers = array(
        'Authorization: Bearer ' . $api_key,
        'Content-Type: application/json; charset=utf-8'
    );

    // Send the message to Slack using cURL
    $ch = curl_init($slack_api_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message_data));

    // Execute cURL and capture the response
    $response = curl_exec($ch);
    curl_close($ch);

    // Check if the message was sent successfully
    if ($response === false) {
        error_log("Failed to send Slack notification: " . curl_error($ch));
    } else {
        $response_data = json_decode($response, true);
        if (!$response_data['ok']) {
            error_log("Failed to send Slack notification: " . $response_data['error']);
        }
    }
}

add_action('woocommerce_checkout_update_order_meta', 'custom_send_order_notification', 1000);

/**
 * 
 * TEST BUTTON
 * 
 */

function custom_add_test_notification_button()
{
    $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : '';
    if ($current_tab !== 'custom_order_notifications') {
        return;
    }
    echo '<div class="notice notice-info">';
    echo '<p>Testing WooCommerce Order Notification:</p>';
    echo '<p><a class="button button-primary" href="' . esc_url(admin_url('admin-post.php?action=custom_test_notification')) . '">Test Notification</a></p>';
    echo '</div>';
}
add_action('admin_notices', 'custom_add_test_notification_button');


function custom_test_notification_handler()
{
    if (isset($_GET['action']) && $_GET['action'] === 'custom_test_notification') {
        // get latest order
        $orders = wc_get_orders(array(
            'limit' => 1,
            'orderby' => 'date',
            'return' => 'ids',
        ));
        $order_id = $orders[0];
        custom_send_order_notification($order_id, TRUE);
        wp_redirect(admin_url('admin.php?page=wc-settings&tab=custom_order_notifications'));
        exit;
    }
}
add_action('admin_post_custom_test_notification', 'custom_test_notification_handler');
