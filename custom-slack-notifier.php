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
        ),
        'tags_title' => array(
            'name' => __('Tag by department', 'custom-woocommerce-order-notifications'),
            'type' => 'title',
            'desc' => __(
                'Who gets tagged when an order contains an item from a given store.<br><br>'
                . 'One department per line, in the form <code>department = member id</code>. '
                . 'Separate several people with commas. Adding a new store is just a new line here — '
                . 'no code change needed.<br><br>'
                . 'Use Slack <strong>member IDs</strong> (e.g. <code>U01ABC2DEF</code>), not @names: open the '
                . 'person\'s profile → three dots → "Copy member ID". A Slack user group works too, '
                . 'written as <code>&lt;!subteam^S01ABC2DEF&gt;</code>.<br><br>'
                . 'The department name is matched loosely (case and accents are ignored, so '
                . '<code>kobenhavn</code> matches "København"). An order with items from several stores '
                . 'tags everyone involved.',
                'custom-woocommerce-order-notifications'
            ),
            'id' => 'custom_slack_notifications_tags_title'
        ),
        'department_tags' => array(
            'name'    => __('Department → Slack', 'custom-woocommerce-order-notifications'),
            'type'    => 'textarea',
            'css'     => 'width: 100%; height: 340px; font-family: monospace;',
            'desc'    => __('Add member IDs after the <code>=</code>, e.g. <code>aarhus = U02DEF3GHI</code>. Leave a department blank to use the fallback for it.', 'custom-woocommerce-order-notifications'),
            'id'      => 'custom_slack_notifications_department_tags',
            'default' => pantsat_slack_default_department_tags(),
        ),
        'tag_default' => array(
            'name' => __('Fallback', 'custom-woocommerce-order-notifications'),
            'type' => 'text',
            'desc' => __('Tagged when an item has no department, or one that is not listed above. Leave empty for no tag.', 'custom-woocommerce-order-notifications'),
            'id'   => 'custom_slack_notifications_tag_default'
        ),
        'tags_end' => array(
            'type' => 'sectionend',
            'id' => 'custom_slack_notifications_tags_end'
        )
    );
}

/**
 * The department values our internal system sends, pre-filled into the settings
 * box so only the member IDs need typing.
 *
 * This is a starting point, not a constraint: the box is free text, so a new
 * department is handled by adding a line — no code change. Anything not listed
 * falls back to the fallback tag.
 */
function pantsat_slack_default_department_tags()
{
    $departments = array(
        'aarhus',
        'copenhagen',
        'odense',
        'aalborg',
        'esbjerg',
        'vejle',
        'koege',
        'silkeborg',
        'slagelse',
        'hjoerring',
        'kolding',
        'hellerup',
        'hoersholm',
        'horsens',
        'herlev',
        'randers',
    );

    $width = max(array_map('strlen', $departments));
    $lines = array();
    foreach ($departments as $department) {
        $lines[] = str_pad($department, $width) . ' = ';
    }

    return implode("\n", $lines);
}

/**
 * Parse the "department = member id" settings box into
 * array( normalised department => array( member id, ... ) ).
 *
 * Blank lines and lines starting with # are ignored, so the box can be
 * commented.
 */
function pantsat_slack_department_tag_map()
{
    $raw = (string) get_option('custom_slack_notifications_department_tags');
    $map = array();

    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        // Accept "=" or ":" as the separator; people reach for both.
        $parts = preg_split('/\s*[=:]\s*/', $line, 2);
        if (count($parts) < 2) {
            continue;
        }

        $key = pantsat_slack_normalise_department($parts[0]);
        if ($key === '') {
            continue;
        }

        $ids = array_filter(array_map('trim', explode(',', $parts[1])), 'strlen');
        if (!$ids) {
            continue;
        }

        // Same department listed twice: merge rather than overwrite.
        $map[$key] = isset($map[$key]) ? array_merge($map[$key], $ids) : $ids;
    }

    return $map;
}

/**
 * Fold a department value into a comparable key.
 *
 * The attribute is free text and written inconsistently across products
 * ("København", "Kobenhavn", "KBH ", "Aarhus C"), and whoever fills in the
 * settings box will not match that spelling exactly either. Lowercasing and
 * stripping accents and non-letters means both sides meet in the middle.
 */
function pantsat_slack_normalise_department($raw)
{
    $key = strtolower(trim((string) $raw));
    if ($key === '') {
        return '';
    }

    $key = strtr($key, array(
        'ø' => 'o', 'æ' => 'ae', 'å' => 'a',
        'ö' => 'o', 'ä' => 'a', 'ü' => 'u', 'é' => 'e', 'è' => 'e',
    ));

    // Drop anything that is not a letter or digit: "Aarhus C." and "aarhus-c"
    // both become "aarhusc".
    $key = preg_replace('/[^a-z0-9]/', '', $key);

    return (string) $key;
}

/**
 * Turn stored ids into Slack mention syntax.
 *
 * A member id (U01ABC2DEF) becomes <@U01ABC2DEF>, which is what actually
 * notifies someone. Values already wrapped in <> — user groups, for example —
 * are passed through untouched.
 */
function pantsat_slack_format_mentions($ids)
{
    $out = array();
    foreach ((array) $ids as $id) {
        $id = trim($id);
        if ($id === '') {
            continue;
        }
        $out[] = ($id[0] === '<' || $id[0] === '@') ? $id : '<@' . $id . '>';
    }

    return array_values(array_unique($out));
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
        // Departments seen in this order, used to work out who to tag.
        $order_departments = array();
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

            $order_departments[] = $department;

            $product_sku = $product->get_sku();
            $message .= "• $item_quantity x $product_sku $product_name ($department) af $item_single_price_formatted\n";
        }
        $message .= "Total: *" . number_format($order_total, 2, ',', '.') . " DKK*\n";

        // Add a link to the WooCommerce order page
        $order_edit_url = admin_url("post.php?post=$order_number&action=edit");
        $message .= "\n*Order Details:* <$order_edit_url|View Order Details>\n";

        // Tag whoever covers the departments in this order. Sits above the
        // header so the mention is the first thing visible in the channel.
        $tag_map     = pantsat_slack_department_tag_map();
        $mention_ids = array();
        $unmatched   = false;

        foreach (array_unique(array_filter(array_map('pantsat_slack_normalise_department', $order_departments), 'strlen')) as $dept_key) {
            if (isset($tag_map[$dept_key])) {
                $mention_ids = array_merge($mention_ids, $tag_map[$dept_key]);
            } else {
                $unmatched = true;
            }
        }

        // An item with no department at all also counts as unmatched.
        if (count($order_departments) !== count(array_filter($order_departments, 'strlen'))) {
            $unmatched = true;
        }

        if ($unmatched || !$mention_ids) {
            $fallback = (string) get_option('custom_slack_notifications_tag_default');
            if ($fallback !== '') {
                $mention_ids = array_merge($mention_ids, array_map('trim', explode(',', $fallback)));
            }
        }

        $mentions = pantsat_slack_format_mentions($mention_ids);
        if ($mentions) {
            $message = implode(' ', $mentions) . "\n" . $message;
        }

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

add_action('woocommerce_payment_complete', 'custom_send_order_notification', 10, 1);

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
