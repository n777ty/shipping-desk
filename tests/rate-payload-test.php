<?php
/**
 * Checks for the address the rate payload describes, and for the rules that
 * decide whether an address is worth verifying.
 *
 * Standalone: no Composer, no PHPUnit, no WordPress. WooCommerce is stubbed to
 * the handful of order getters the payload reads, so the destination country and
 * the phone it normalises against can be exercised without a database.
 *
 * Run:  php tests/rate-payload-test.php
 * Exit: 0 when every check passes, 1 otherwise.
 *
 * Two bugfixes live behind these checks:
 *   #1  the destination country was hard-coded to 'US'
 *   #2  a ZIP+4 was treated as proof that the address had already been verified
 */

/* -- WooCommerce, reduced to what the payload actually asks for ------------ */

class WC_Order {
    private $data;

    public function __construct( array $data = [] ) {
        $this->data = $data;
    }

    private function field( $key, $default = '' ) {
        return isset( $this->data[ $key ] ) ? $this->data[ $key ] : $default;
    }

    public function get_id() { return (int) $this->field( 'id', 1 ); }
    public function get_shipping_first_name() { return $this->field( 'shipping_first_name' ); }
    public function get_shipping_last_name() { return $this->field( 'shipping_last_name' ); }
    public function get_shipping_address_1() { return $this->field( 'shipping_address_1' ); }
    public function get_shipping_address_2() { return $this->field( 'shipping_address_2' ); }
    public function get_shipping_city() { return $this->field( 'shipping_city' ); }
    public function get_shipping_state() { return $this->field( 'shipping_state' ); }
    public function get_shipping_postcode() { return $this->field( 'shipping_postcode' ); }
    public function get_shipping_country() { return $this->field( 'shipping_country' ); }
    public function get_shipping_phone() { return $this->field( 'shipping_phone' ); }
    public function get_billing_country() { return $this->field( 'billing_country' ); }
    public function get_billing_email() { return $this->field( 'billing_email' ); }
    public function get_billing_phone() { return $this->field( 'billing_phone' ); }
    public function get_order_number() { return $this->field( 'order_number', '1001' ); }
    public function get_total() { return $this->field( 'total', '10.00' ); }
    public function get_currency() { return $this->field( 'currency', 'USD' ); }
}

$GLOBALS['payload_options'] = [];
$GLOBALS['payload_wc']      = null;

function get_option( $key, $default = false ) {
    return array_key_exists( $key, $GLOBALS['payload_options'] )
        ? $GLOBALS['payload_options'][ $key ]
        : $default;
}

function get_bloginfo( $what = '' ) {
    return 'Test Store';
}

function is_email( $email ) {
    return (bool) filter_var( (string) $email, FILTER_VALIDATE_EMAIL );
}

function __( $text, $domain = null ) {
    return $text;
}

function WC() {
    return $GLOBALS['payload_wc'];
}

require_once dirname( __DIR__ ) . '/includes/helpers.php';

/* -- Harness --------------------------------------------------------------- */

$GLOBALS['payload_checks']   = 0;
$GLOBALS['payload_failures'] = 0;

function payload_same( $expected, $actual, $label ) {
    $GLOBALS['payload_checks']++;
    if ( $expected === $actual ) {
        echo '  ok    ' . $label . PHP_EOL;
        return;
    }
    $GLOBALS['payload_failures']++;
    echo '  FAIL  ' . $label . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' . PHP_EOL;
}

function payload_section( $title ) {
    echo PHP_EOL . '[' . $title . ']' . PHP_EOL;
}

echo 'Shipping Desk rate payload checks' . PHP_EOL;
echo '==================================' . PHP_EOL;

/* -- Fixtures -------------------------------------------------------------- */

$GLOBALS['payload_options'] = [
    'admin_phone'                  => '',
    'woocommerce_store_phone'      => '704-555-1212',
    'admin_email'                  => 'store@example.com',
    'woocommerce_default_country'  => 'US:NC',
    'shipping_desk_from_address'   => [],
];

$GLOBALS['payload_wc'] = new class() {
    public $countries;

    public function __construct() {
        $this->countries = new class() {
            public function get_base_address() { return '1 Warehouse Way'; }
            public function get_base_city() { return 'Charlotte'; }
            public function get_base_postcode() { return '28202'; }
            public function get_base_country() { return 'US'; }
            public function get_base_state() { return 'NC'; }
        };
    }
};

/* -- destination_country (#1) ---------------------------------------------- */

payload_section( 'destination_country' );

payload_same( 'CA', Shipping_Desk_Helpers::destination_country( new WC_Order( [ 'shipping_country' => 'CA', 'billing_country' => 'US' ] ) ), 'the shipping country wins' );
payload_same( 'US', Shipping_Desk_Helpers::destination_country( new WC_Order( [ 'shipping_country' => '', 'billing_country' => 'US' ] ) ), 'a virtual order falls back to billing' );
payload_same( 'CA', Shipping_Desk_Helpers::destination_country( new WC_Order( [ 'shipping_country' => ' ca ', 'billing_country' => 'US' ] ) ), 'trims and upper-cases the code' );
payload_same( 'GB', Shipping_Desk_Helpers::destination_country( new WC_Order( [ 'shipping_country' => 'GB', 'billing_country' => 'GB' ] ) ), 'keeps a non-US destination intact' );

$GLOBALS['payload_options']['woocommerce_default_country'] = 'DE:BE';
payload_same( 'DE', Shipping_Desk_Helpers::destination_country( new WC_Order() ), 'falls back to the store base country' );
$GLOBALS['payload_options']['woocommerce_default_country'] = '';
payload_same( 'US', Shipping_Desk_Helpers::destination_country( new WC_Order() ), 'and to US when nothing is configured' );
$GLOBALS['payload_options']['woocommerce_default_country'] = 'US:NC';

payload_section( 'is_supported_destination' );

payload_same( true, Shipping_Desk_Helpers::is_supported_destination( 'US' ), 'the US is in scope' );
payload_same( true, Shipping_Desk_Helpers::is_supported_destination( ' us ' ), 'case and whitespace do not matter' );
payload_same( true, Shipping_Desk_Helpers::is_supported_destination( 'PR' ), 'Puerto Rico is domestic: US ZIPs, USPS rates, WC state' );
payload_same( true, Shipping_Desk_Helpers::is_supported_destination( 'GU' ), 'and so are the other territories with US ZIPs' );
payload_same( false, Shipping_Desk_Helpers::is_supported_destination( 'CA' ), 'Canada is not' );
payload_same( false, Shipping_Desk_Helpers::is_supported_destination( 'GB' ), 'neither is the UK' );
payload_same( false, Shipping_Desk_Helpers::is_supported_destination( '' ), 'and an empty country is not' );

/* -- The payload itself (#1) ----------------------------------------------- */

payload_section( 'build_shipping_desk_rate_payload' );

$us = Shipping_Desk_Helpers::build_shipping_desk_rate_payload(
    new WC_Order(
        [
            'shipping_country'   => 'US',
            'shipping_postcode'  => '33434',
            'shipping_state'     => 'FL',
            'shipping_city'      => 'West Palm Beach',
            'shipping_address_1' => '1 Example St',
            'billing_phone'      => '+1 (704) 555-1212',
            'billing_email'      => 'buyer@example.com',
        ]
    ),
    [ 'length' => 10, 'width' => 8, 'height' => 6 ],
    16.0
);
payload_same( 'US', $us['to_address']['country_code'], 'a US order is described as US' );
payload_same( '7045551212', $us['to_address']['phone'], 'a US phone loses its country code' );
payload_same( 'US', $us['from_address']['country_code'], 'the origin address is untouched' );

$ca = Shipping_Desk_Helpers::build_shipping_desk_rate_payload(
    new WC_Order(
        [
            'shipping_country'   => 'CA',
            'shipping_postcode'  => 'M5V 2T6',
            'shipping_state'     => 'ON',
            'shipping_city'      => 'Toronto',
            'shipping_address_1' => '1 Example Rd',
            'billing_phone'      => '+1 (416) 555-0199',
            'billing_email'      => 'buyer@example.com',
        ]
    ),
    [],
    16.0
);
payload_same( 'CA', $ca['to_address']['country_code'], 'a Canadian order keeps its country' );
payload_same( '4165550199', $ca['to_address']['phone'], 'and its phone is normalised as Canadian' );

$gb = Shipping_Desk_Helpers::build_shipping_desk_rate_payload(
    new WC_Order(
        [
            'shipping_country'  => 'GB',
            'shipping_postcode' => 'SW1A 1AA',
            'billing_phone'     => '+44 20 7946 0958',
            'billing_email'     => 'buyer@example.com',
        ]
    ),
    [],
    16.0
);
payload_same( 'GB', $gb['to_address']['country_code'], 'a British order keeps its country' );
payload_same( '442079460958', $gb['to_address']['phone'], 'and a non-NANP phone keeps its country code' );

/* -- Structural guarantees ------------------------------------------------- */

payload_section( 'source guarantees' );

$helpers = (string) file_get_contents( dirname( __DIR__ ) . '/includes/helpers.php' );
$plugin  = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-plugin.php' );

payload_same( 0, substr_count( $helpers, "'country_code' => 'US'" ), 'the payload no longer hard-codes a US destination' );
payload_same( 1, substr_count( $helpers, "'country_code' => \$country" ), 'it reads the destination instead' );
payload_same( false, strpos( $helpers, 'function needs_address_validation' ), 'the ZIP+4 skip is gone, not merely unused' );
payload_same( false, strpos( $plugin, 'needs_address_validation' ), 'and nothing still calls it' );
payload_same( true, substr_count( $plugin, 'is_supported_destination' ) >= 2, 'the scope is checked before quoting and before verifying' );
payload_same( true, substr_count( $plugin, 'validation_needs_decision' ) >= 2, 'the gate asks the materiality rule' );
payload_same( true, false !== strpos( $plugin, '$can_apply' ), 'a correction below that bar can still be applied on request' );
payload_same(
    true,
    false !== strpos( $plugin, '( $needs_choice || $can_apply )' ),
    'so the apply path is not gated on the materiality rule alone'
);

echo PHP_EOL . 'Checks: ' . $GLOBALS['payload_checks'] . ', failures: ' . $GLOBALS['payload_failures'] . PHP_EOL;
exit( 0 === $GLOBALS['payload_failures'] ? 0 : 1 );

