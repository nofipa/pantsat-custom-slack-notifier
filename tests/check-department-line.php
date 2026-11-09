<?php
// Run: php tests/check-department-line.php
//
// The order message names the department on its own bold line between the
// order total and the items, each store once. The plugin is loaded inside this
// namespace so its unqualified WordPress, WooCommerce and curl calls hit the
// stubs below. The plugin passes pantsat_slack_normalise_department to
// array_map as a string, and string callbacks always resolve in the global
// namespace, so that one gets a global forwarder at the bottom of this file.

namespace NotifierCheck {

PHP_SAPI === 'cli' or exit;

$posted = array();
$order  = null;

function add_action() {}
function add_filter() {}
function plugin_dir_path($file) { return __DIR__ . '/no-vendor/'; }
function get_option($key) { return 'test'; }
function admin_url($path) { return "https://example.test/wp-admin/$path"; }
function wc_get_order($id) { global $order; return $order; }
function curl_init($url) { return 'ch'; }
function curl_setopt($ch, $opt, $value) { global $posted; if ($opt === \CURLOPT_POSTFIELDS) { $posted[] = json_decode($value, true)['text']; } }
function curl_exec($ch) { return '{"ok":true}'; }
function curl_close($ch) {}

class Product
{
    private $sku, $department;
    public function __construct($sku, $department) { $this->sku = $sku; $this->department = $department; }
    public function get_name() { return 'PH Kuglelamel'; }
    public function get_sku() { return $this->sku; }
    public function get_attributes() { return array(); }
    public function get_data() { return array('attributes' => $this->department === '' ? array() : array('department' => $this->department)); }
}

class Item
{
    private $product;
    public function __construct(Product $product) { $this->product = $product; }
    public function get_quantity() { return 1; }
    public function get_total() { return 100.0; }
    public function get_product() { return $this->product; }
}

class Order
{
    private $items;
    public function __construct(array $items) { $this->items = $items; }
    public function get_data()
    {
        return array(
            'total' => 100.0 * count($this->items), 'id' => 83709, 'status' => 'processing',
            'billing' => array('first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.test', 'phone' => '1'),
        );
    }
    public function get_items() { return $this->items; }
    public function get_shipping_method() { return 'Fri levering'; }
    public function get_payment_method() { return 'card'; }
}

// eval of the plugin's own file, only to put it in this namespace. It reads
// our own checked-in source, never outside input.
eval('namespace NotifierCheck; ' . substr(file_get_contents(__DIR__ . '/../custom-slack-notifier.php'), strlen('<?php')));

function message_for(array $departments)
{
    global $order, $posted;
    $items = array();
    foreach ($departments as $i => $department) {
        $items[] = new Item(new Product("G$i", $department));
    }
    $order  = new Order($items);
    $posted = array();
    custom_send_order_notification(83709);
    return $posted[0];
}

function check($label, $ok)
{
    echo ($ok ? 'ok   ' : 'FAIL ') . "$label\n";
    $ok or exit(1);
}

$one = message_for(array('aalborg'));
check('one item: department line present', strpos($one, "*Department: Aalborg*\n") !== false);
check(
    'one item: line sits between the total and the items',
    strpos($one, '*Order Total:*') < strpos($one, '*Department:') && strpos($one, '*Department:') < strpos($one, '*Order Items:*')
);
check('one item: item line keeps its own department', strpos($one, '(aalborg) af') !== false);

$several = message_for(array('aalborg', 'Copenhagen', 'Aalborg', ''));
check('several stores: each listed once, in item order', strpos($several, "*Department: Aalborg, Copenhagen*\n") !== false);

$none = message_for(array(''));
check('no department: no empty line', strpos($none, '*Department:') === false);
check('no department: items still posted', strpos($none, "*Order Items:*\n• 1 x G0") !== false);

}

namespace {
    function pantsat_slack_normalise_department($raw) { return \NotifierCheck\pantsat_slack_normalise_department($raw); }
}
