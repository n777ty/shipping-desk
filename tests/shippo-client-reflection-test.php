<?php
/**
 * Reflection gate for Shipping_Desk_Shippo_Client.
 *
 * Standalone: no Composer, no PHPUnit, no WordPress install. It stubs the
 * handful of WordPress functions the client calls, then checks the class
 * against the provider contract and against the hard constraints recorded in
 * docs/shippo-migration.md (async:false, PDF_4x6, reference truncation,
 * XCover insurance without a provider, STANDARD/ADULT signatures, 429 retry).
 *
 * Run:  php tests/shippo-client-reflection-test.php
 * Exit: 0 when every check passes, 1 otherwise.
 */

error_reporting( E_ALL );

/* -- WordPress stubs ------------------------------------------------------ */

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {

        protected $errors     = [];
        protected $error_data = [];

        public function __construct( $code = '', $message = '', $data = '' ) {
            if ( '' === $code ) {
                return;
            }
            $this->errors[ $code ][] = $message;
            if ( '' !== $data ) {
                $this->error_data[ $code ] = $data;
            }
        }

        public function get_error_code() {
            $codes = array_keys( $this->errors );
            return empty( $codes ) ? '' : $codes[0];
        }

        public function get_error_message() {
            $messages = $this->get_error_messages();
            return empty( $messages ) ? '' : $messages[0];
        }

        public function get_error_messages() {
            $messages = [];
            foreach ( $this->errors as $list ) {
                foreach ( $list as $message ) {
                    $messages[] = $message;
                }
            }
            return $messages;
        }

        public function get_error_data() {
            $code = $this->get_error_code();
            return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
        }
    }
}

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = null ) {
        return $text;
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) {
        return $thing instanceof WP_Error;
    }
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $key, $default = false ) {
        return array_key_exists( $key, $GLOBALS['gate_options'] ) ? $GLOBALS['gate_options'][ $key ] : $default;
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data ) {
        return json_encode( $data );
    }
}

if ( ! function_exists( 'wp_remote_request' ) ) {
    function wp_remote_request( $url, $args = [] ) {
        $GLOBALS['gate_requests'][] = [ 'url' => $url, 'args' => $args ];
        if ( empty( $GLOBALS['gate_responses'] ) ) {
            return new WP_Error( 'http_request_failed', 'No canned response queued for ' . $url );
        }
        return array_shift( $GLOBALS['gate_responses'] );
    }
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $response ) {
        return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
    }
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $response ) {
        return isset( $response['body'] ) ? (string) $response['body'] : '';
    }
}

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
    function wp_remote_retrieve_header( $response, $name ) {
        $name = strtolower( (string) $name );
        return isset( $response['headers'][ $name ] ) ? $response['headers'][ $name ] : '';
    }
}

if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) {
        return parse_url( $url, $component );
    }
}

/* -- Subject -------------------------------------------------------------- */

$GLOBALS['gate_options']   = [];
$GLOBALS['gate_responses'] = [];
$GLOBALS['gate_requests']  = [];

require_once dirname( __DIR__ ) . '/includes/class-provider-interface.php';
require_once dirname( __DIR__ ) . '/includes/helpers.php';
require_once dirname( __DIR__ ) . '/includes/class-shippo-client.php';

/**
 * Records the client's timing seams instead of sleeping, so the gate runs in
 * milliseconds while still proving that backoff and bulk throttling happen.
 */
class Gate_Shippo_Client extends Shipping_Desk_Shippo_Client {

    public $pauses    = [];
    public $throttles = [];

    /** @var DateTimeImmutable|null Pinned "now" for dispatch-date checks. */
    public $fixed_now = null;

    protected function now() {
        return $this->fixed_now instanceof DateTimeImmutable ? $this->fixed_now : parent::now();
    }

    protected function pause( $seconds ) {
        $this->pauses[] = (int) $seconds;
    }

    protected function throttle( $microseconds ) {
        $this->throttles[] = (int) $microseconds;
    }

    /** Expose the pacing state for the gate. */
    public function spacing_for_test() {
        return $this->spacing_us();
    }

    public function widen_for_test() {
        $this->widen_spacing();
    }
}

/* -- Harness -------------------------------------------------------------- */

$GLOBALS['gate_failures'] = 0;
$GLOBALS['gate_checks']   = 0;

function gate_check( $condition, $label ) {
    $GLOBALS['gate_checks']++;
    if ( $condition ) {
        echo '  ok    ' . $label . PHP_EOL;
        return true;
    }
    $GLOBALS['gate_failures']++;
    echo '  FAIL  ' . $label . PHP_EOL;
    return false;
}

function gate_same( $expected, $actual, $label ) {
    if ( $expected === $actual ) {
        return gate_check( true, $label );
    }
    return gate_check(
        false,
        $label . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')'
    );
}

function gate_section( $title ) {
    echo PHP_EOL . '[' . $title . ']' . PHP_EOL;
}

function gate_canned( $code, $body, array $headers = [] ) {
    return [
        'response' => [ 'code' => $code ],
        'body'     => is_string( $body ) ? $body : json_encode( $body ),
        'headers'  => array_change_key_case( $headers, CASE_LOWER ),
    ];
}

function gate_reset_http() {
    $GLOBALS['gate_responses'] = [];
    $GLOBALS['gate_requests']  = [];
}

function gate_body( $index = 0 ) {
    if ( ! isset( $GLOBALS['gate_requests'][ $index ] ) ) {
        return null;
    }
    $args = $GLOBALS['gate_requests'][ $index ]['args'];
    if ( ! isset( $args['body'] ) ) {
        return null;
    }
    return json_decode( $args['body'], true );
}

function gate_url( $index = 0 ) {
    return isset( $GLOBALS['gate_requests'][ $index ] ) ? $GLOBALS['gate_requests'][ $index ]['url'] : '';
}

function gate_client( $key = 'shippo_test_abc123' ) {
    return new Gate_Shippo_Client( $key );
}

function gate_rate_payload( array $overrides = [] ) {
    return array_merge(
        [
            'to_address'         => [
                'name'         => 'Ada Lovelace',
                'phone'        => '4151234567',
                'email'        => 'ada@example.com',
                'line1'        => '965 Mission St',
                'town'         => 'San Francisco',
                'postcode'     => '94103',
                'country_code' => 'US',
                'county'       => 'CA',
            ],
            'from_address'       => [
                'name'         => 'Warehouse',
                'company'      => '',
                'phone'        => '3125550000',
                'email'        => 'store@example.com',
                'line1'        => '1 Depot Way',
                'town'         => 'Chicago',
                'postcode'     => '60601',
                'country_code' => 'US',
                'county'       => 'IL',
            ],
            'parcels'            => [
                [
                    'weight'         => 12.5,
                    'weight_unit'    => 'oz',
                    'height'         => 4,
                    'width'          => 6,
                    'length'         => 8,
                    'dimension_unit' => 'in',
                ],
            ],
            'customer_reference' => 'WC-123456789012345678901234567890',
            'estimated_value'    => '42.00',
            'currency_code'      => 'USD',
        ],
        $overrides
    );
}

function gate_rates_response() {
    return gate_canned(
        200,
        [
            'object_id' => 'shipment0123456789abcdef01234567',
            'rates'     => [
                [
                    'object_id'                => 'rate0000000000000000000000000001',
                    'amount'                   => '18.56',
                    'currency'                 => 'USD',
                    'provider'                 => 'USPS',
                    'estimated_days'           => 2,
                    'duration_terms'           => 'Delivery in 1 to 3 business days',
                    'included_insurance_price' => null,
                    'attributes'               => [ 'FASTEST' ],
                    'servicelevel'             => [ 'name' => 'Priority Mail', 'token' => 'usps_priority' ],
                ],
                [
                    'object_id'    => 'rate0000000000000000000000000002',
                    'amount'       => '12.38',
                    'currency'     => 'USD',
                    'provider'     => 'UPS',
                    'attributes'   => [],
                    'servicelevel' => [ 'name' => 'Ground', 'token' => 'ups_ground' ],
                ],
            ],
            'messages'  => [],
        ]
    );
}

function gate_timeout() {
    return new WP_Error(
        'http_request_failed',
        'cURL error 28: Operation timed out after 60001 milliseconds with 0 bytes received'
    );
}

function gate_transaction( array $overrides = [] ) {
    return gate_canned(
        200,
        array_merge(
            [
                'object_id'           => 'transaction0000000000000000000001',
                'status'              => 'SUCCESS',
                'rate'                => 'rate0000000000000000000000000001',
                'tracking_number'     => '9400111899223197428490',
                'tracking_url_provider' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=9400111899223197428490',
                'label_url'           => 'https://shippo-delivery.s3.amazonaws.com/label.pdf?Expires=9999999999',
                'label_file_type'     => 'PDF_4x6',
                'messages'            => [],
            ],
            $overrides
        )
    );
}

function gate_bookable_rate() {
    return [
        'rate_id'            => 'rate0000000000000000000000000001',
        'remote_shipment_id' => 'shipment0123456789abcdef01234567',
        'service_name'       => 'USPS Priority Mail',
        'total_charge'       => '18.56',
        'provider'           => 'USPS',
        'currency'           => 'USD',
    ];
}

/**
 * Rate the client for a fixed Eastern moment and return the calendar day it
 * would send, so the dispatch cutoffs can be asserted without waiting.
 *
 * Only the date part is returned: the wire value is a full ISO-8601 timestamp,
 * and its format is asserted with the shipment payload above. Asserting the day
 * here is what matters, because the day is what a carrier prints.
 */
function gate_dispatch_date( $moment, array $payload = [] ) {
    $client            = gate_client();
    $client->fixed_now = new DateTimeImmutable( $moment, new DateTimeZone( Gate_Shippo_Client::DISPATCH_TIMEZONE ) );

    gate_reset_http();
    $GLOBALS['gate_responses'][] = gate_rates_response();
    $client->get_rates( gate_rate_payload( $payload ) );

    $body  = gate_body( 0 );
    $value = isset( $body['shipment_date'] ) ? (string) $body['shipment_date'] : '';
    return substr( $value, 0, 10 );
}

echo 'Shippo client reflection gate' . PHP_EOL;
echo '=============================' . PHP_EOL;

/* -- Contract ------------------------------------------------------------- */

gate_section( 'contract' );

gate_check( interface_exists( 'Shipping_Desk_Provider_Interface' ), 'provider interface is loaded' );
gate_check( class_exists( 'Shipping_Desk_Shippo_Client' ), 'Shipping_Desk_Shippo_Client is defined' );
gate_check(
    in_array( 'Shipping_Desk_Provider_Interface', class_implements( 'Shipping_Desk_Shippo_Client' ), true ),
    'client implements Shipping_Desk_Provider_Interface'
);

$reflection = new ReflectionClass( 'Shipping_Desk_Shippo_Client' );
gate_check( $reflection->isInstantiable(), 'client is instantiable' );

$constructor = $reflection->getConstructor();
gate_check(
    null !== $constructor && 0 === $constructor->getNumberOfRequiredParameters(),
    'constructor takes no required arguments'
);

$contract = new ReflectionClass( 'Shipping_Desk_Provider_Interface' );

// Interface methods delivered so far. This list grows with each stage so every
// commit ends with a green gate; here it is the whole contract.
$gate_delivered = [];
foreach ( $contract->getMethods() as $contract_method ) {
    $gate_delivered[] = $contract_method->getName();
}

foreach ( $gate_delivered as $name ) {
    gate_check( $contract->hasMethod( $name ), $name . '() belongs to the provider contract' );
    if ( ! $reflection->hasMethod( $name ) ) {
        gate_check( false, $name . '() is implemented' );
        continue;
    }

    $impl = $reflection->getMethod( $name );
    gate_check(
        $impl->isPublic() && ! $impl->isStatic() && ! $impl->isAbstract(),
        $name . '() is public, non-static and concrete'
    );

    $expected_params = $contract->getMethod( $name )->getParameters();
    $actual_params   = $impl->getParameters();
    if ( count( $expected_params ) !== count( $actual_params ) ) {
        gate_check( false, $name . '() takes the same number of parameters as the contract' );
        continue;
    }

    $signature_ok = true;
    foreach ( $expected_params as $index => $expected ) {
        $actual = $actual_params[ $index ];
        if ( $expected->getName() !== $actual->getName() ) {
            $signature_ok = false;
        }
        if ( (string) $expected->getType() !== (string) $actual->getType() ) {
            $signature_ok = false;
        }
        if ( $expected->isDefaultValueAvailable() !== $actual->isDefaultValueAvailable() ) {
            $signature_ok = false;
        } elseif ( $expected->isDefaultValueAvailable() && $expected->getDefaultValue() !== $actual->getDefaultValue() ) {
            $signature_ok = false;
        }
    }
    gate_check( $signature_ok, $name . '() signature matches the contract' );
}

/* -- Constants that encode migration constraints -------------------------- */

gate_section( 'constants' );

gate_same( 'https://api.goshippo.com', $reflection->getConstant( 'BASE_URL' ), 'BASE_URL points at the Shippo API' );
gate_same( 'PDF_4x6', $reflection->getConstant( 'LABEL_FILE_TYPE' ), 'LABEL_FILE_TYPE is PDF_4x6' );
gate_check(
    ! method_exists( 'Shipping_Desk_Shippo_Client', 'get_printable_label_pdf' ),
    'no crop needed: Shippo files are already 4x6, so browser printing uses its URL'
);
gate_same( 30, $reflection->getConstant( 'REFERENCE_MAX_LENGTH' ), 'references are truncated to 30 characters' );
gate_same( [ 'STANDARD', 'ADULT' ], $reflection->getConstant( 'SIGNATURE_OPTIONS' ), 'signature set is STANDARD + ADULT only' );

/* -- get_rates ------------------------------------------------------------ */

gate_section( 'get_rates' );

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_rates_response();
$result                      = gate_client()->get_rates(
    gate_rate_payload(
        [
            'liability_amount'       => 100,
            'signature_confirmation' => 'ADULT',
        ]
    )
);

gate_check( ! is_wp_error( $result ), 'returns an array, not WP_Error' );
gate_same( 'https://api.goshippo.com/shipments', gate_url( 0 ), 'posts to /shipments' );
gate_same( 'POST', $GLOBALS['gate_requests'][0]['args']['method'], 'uses POST' );
gate_same(
    'ShippoToken shippo_test_abc123',
    $GLOBALS['gate_requests'][0]['args']['headers']['Authorization'],
    'sends ShippoToken authorization'
);
gate_same( 30, (int) $GLOBALS['gate_requests'][0]['args']['timeout'], 'uses the ~30s rates timeout' );

$body = gate_body( 0 );
gate_same( false, $body['async'], 'async is false on the rates request' );
gate_same( '965 Mission St', $body['address_to']['street1'], 'maps address line1 -> street1' );
gate_same( 'San Francisco', $body['address_to']['city'], 'maps town -> city' );
gate_same( 'CA', $body['address_to']['state'], 'maps county -> state' );
gate_same( '94103', $body['address_to']['zip'], 'maps postcode -> zip' );
gate_same( 'US', $body['address_to']['country'], 'maps country_code -> country' );
gate_same( 'store@example.com', $body['address_from']['email'], 'sends the from-address email USPS requires' );
gate_same( 'ada@example.com', $body['address_to']['email'], 'sends the recipient email' );
gate_same( '3125550000', $body['address_from']['phone'], 'sends the from-address phone USPS requires' );
gate_same( '4151234567', $body['address_to']['phone'], 'sends the recipient phone' );
gate_same( 'oz', $body['parcels'][0]['mass_unit'], 'keeps oz as the parcel mass unit' );
gate_same( 'in', $body['parcels'][0]['distance_unit'], 'keeps in as the parcel distance unit' );
gate_same( '12.5', $body['parcels'][0]['weight'], 'formats parcel weight without trailing zeros' );
gate_check(
    isset( $body['shipment_date'] )
        && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $body['shipment_date'] ),
    'sends shipment_date as an ISO-8601 timestamp, the only form Shippo accepts'
);

gate_same( 30, strlen( $body['extra']['reference_1'] ), 'reference_1 is truncated to 30 characters' );
gate_check( ! isset( $body['extra']['reference_2'] ), 'reference_2 is not sent' );
gate_same( 'ADULT', $body['extra']['signature_confirmation'], 'passes an allowed signature through' );

gate_same( '100.00', $body['extra']['insurance']['amount'], 'sends the declared value as the insurance amount' );
gate_same( 'USD', $body['extra']['insurance']['currency'], 'sends the insurance currency' );
gate_check(
    isset( $body['extra']['insurance']['content'] ) && 'Merchandise' === $body['extra']['insurance']['content'],
    'sends a fixed insurance content of Merchandise'
);
gate_check( ! isset( $body['extra']['insurance']['provider'] ), 'omits the XCover provider field' );

// A shop may print something other than the order number. reference_1 follows
// the chosen printed reference; customer_reference stays the order number.
gate_reset_http();
$GLOBALS['gate_responses'][] = gate_rates_response();
gate_client()->get_rates( gate_rate_payload( [ 'print_reference' => 'GIFT' ] ) );
gate_same( 'GIFT', gate_body( 0 )['extra']['reference_1'], 'reference_1 follows the chosen printed reference' );

gate_same(
    'shipment0123456789abcdef01234567',
    $result['remote_shipment_id'],
    'returns the shipment object_id as the parent handle'
);
gate_same( 2, count( $result['quotes'] ), 'returns one quote per Shippo rate' );
gate_same( 'rate0000000000000000000000000001', $result['quotes'][0]['rate_id'], 'rate_id comes from rate.object_id' );
gate_same( 'USPS Priority Mail', $result['quotes'][0]['service_name'], 'service_name is provider + servicelevel name' );
gate_same( '18.56', $result['quotes'][0]['total_charge'], 'total_charge comes from rate.amount' );
gate_same( 2, $result['quotes'][0]['estimated_days'], 'carries transit time as an integer day count' );
gate_same(
    'Delivery in 1 to 3 business days',
    $result['quotes'][0]['duration_terms'],
    'carries the carrier wording for that transit time'
);
gate_same( null, $result['quotes'][1]['estimated_days'], 'leaves the day count null when the carrier gave none' );
gate_same(
    'shipment0123456789abcdef01234567',
    $result['quotes'][0]['remote_shipment_id'],
    'each quote carries the parent handle'
);
gate_check( isset( $result['quotes'][0]['shipping_service_options'] ), 'quotes carry the shared service-options key' );

/* -- Constraint guards ---------------------------------------------------- */

gate_section( 'constraint guards' );

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_rates_response();
gate_client()->get_rates( gate_rate_payload( [ 'signature_confirmation' => 'CERTIFIED' ] ) );
$body = gate_body( 0 );
gate_check(
    ! isset( $body['extra']['signature_confirmation'] ),
    'drops unsupported signature enums instead of sending them'
);

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_rates_response();
gate_client()->get_rates( gate_rate_payload() );
$body = gate_body( 0 );
gate_check( ! isset( $body['extra']['insurance'] ), 'omits insurance when no declared value is set' );

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_canned( 429, [ 'detail' => 'Too Many Requests' ], [ 'retry-after' => '1' ] );
$GLOBALS['gate_responses'][] = gate_rates_response();
$throttled                   = gate_client();
$retried                     = $throttled->get_rates( gate_rate_payload() );
gate_check( ! is_wp_error( $retried ), 'retries a throttled request instead of failing' );
gate_same( 2, count( $GLOBALS['gate_requests'] ), 'HTTP 429 triggers one backoff retry' );
gate_same( [ 1 ], $throttled->pauses, 'waits for the Retry-After delay before retrying' );

// A transient 5xx on a read is retried on the same budget and backoff as a 429.
gate_reset_http();
$GLOBALS['gate_responses'][] = gate_canned( 503, [ 'detail' => 'Service Unavailable' ] );
$GLOBALS['gate_responses'][] = gate_transaction();
$server_error                = gate_client();
$recovered                   = $server_error->get_transaction( 'transaction0000000000000000000001' );
gate_check( ! is_wp_error( $recovered ), 'retries a read that failed with a server error' );
gate_same( 2, count( $GLOBALS['gate_requests'] ), 'HTTP 503 triggers one retry on a GET' );
gate_same( [ 1 ], $server_error->pauses, 'the retry waits the same backoff delay a 429 does' );

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_canned( 503, [ 'detail' => 'Service Unavailable' ] );
$GLOBALS['gate_responses'][] = gate_canned( 503, [ 'detail' => 'Service Unavailable' ] );
$GLOBALS['gate_responses'][] = gate_canned( 503, [ 'detail' => 'Service Unavailable' ] );
$never_up                    = gate_client()->get_transaction( 'transaction0000000000000000000001' );
gate_check( is_wp_error( $never_up ), 'a server error that never clears stays an error' );
gate_same(
    'shippo_server_error',
    is_wp_error( $never_up ) ? $never_up->get_error_code() : '',
    'reports the server error once the read budget is spent'
);
gate_same( 3, count( $GLOBALS['gate_requests'] ), 'a GET gets three attempts in total' );

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_canned( 422, [ 'detail' => 'Invalid address' ] );
$invalid                     = gate_client()->get_rates( gate_rate_payload() );
gate_check( is_wp_error( $invalid ), 'returns WP_Error for a rejected request' );
gate_same( 'shippo_api_error', is_wp_error( $invalid ) ? $invalid->get_error_code() : '', 'uses shippo_api_error for 4xx' );
gate_same(
    'Invalid address',
    is_wp_error( $invalid ) ? $invalid->get_error_message() : '',
    'passes the Shippo error detail through'
);

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_canned( 200, [ 'object_id' => 'shipment_empty', 'rates' => [], 'messages' => [] ] );
$no_rates                    = gate_client()->get_rates( gate_rate_payload() );
gate_same(
    'shippo_no_rates',
    is_wp_error( $no_rates ) ? $no_rates->get_error_code() : '',
    'reports a shipment without rates instead of returning an empty list'
);

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_canned( 422, [ 'detail' => 'Attribute "address_from.phone" must not be empty.' ] );
$attribute_error             = gate_client()->get_rates( gate_rate_payload() );
$attribute_message           = is_wp_error( $attribute_error ) ? $attribute_error->get_error_message() : '';
gate_check( false !== strpos( $attribute_message, 'Settings - From Address' ), 'names where a missing from-address value is set' );

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_canned( 422, [ 'detail' => 'Attribute "address_to.zip" must not be empty.' ] );
$to_error                    = gate_client()->get_rates( gate_rate_payload() );
$to_message                  = is_wp_error( $to_error ) ? $to_error->get_error_message() : '';
gate_check( false !== strpos( $to_message, 'come from the order' ), 'names where a missing recipient value comes from' );

$no_key = gate_client( '' )->get_rates( gate_rate_payload() );
gate_same(
    'shippo_no_api_key',
    is_wp_error( $no_key ) ? $no_key->get_error_code() : '',
    'refuses to call the API without an API key'
);

/* -- book_shipment -------------------------------------------------------- */

gate_section( 'book_shipment' );

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_transaction();
$booking                     = gate_client()->book_shipment(
    'shipment0123456789abcdef01234567',
    'rate0000000000000000000000000001',
    gate_bookable_rate(),
    1234
);

gate_check( ! is_wp_error( $booking ), 'returns an array, not WP_Error' );
gate_same( 'https://api.goshippo.com/transactions', gate_url( 0 ), 'posts to /transactions' );
gate_same( 'POST', $GLOBALS['gate_requests'][0]['args']['method'], 'uses POST' );
gate_same( 60, (int) $GLOBALS['gate_requests'][0]['args']['timeout'], 'uses the ~60s transaction timeout' );

$body = gate_body( 0 );
gate_same( false, $body['async'], 'async is false on the transaction request' );
gate_same( 'PDF_4x6', $body['label_file_type'], 'requests a PDF_4x6 label' );
gate_same( 'rate0000000000000000000000000001', $body['rate'], 'purchases the selected rate' );
gate_check(
    isset( $body['metadata'] ) && strlen( $body['metadata'] ) <= 100,
    'attaches a metadata marker within the 100-character limit'
);

$entry = $booking['successful']['transaction0000000000000000000001'];
gate_check( null !== $entry, 'keys successful[] by the transaction id' );
gate_same( 'transaction0000000000000000000001', $entry['id'], 'reports the transaction id as the label id' );
gate_same( 'shipment0123456789abcdef01234567', $entry['remote_shipment_id'], 'reports the parent shipment id' );
gate_same( '9400111899223197428490', $entry['tracking_number'], 'returns the tracking number' );
gate_same( 'USPS', $entry['carrier_id'], 'uses the captured rate provider as the carrier' );
gate_same( '18.56', $entry['total_charge'], 'carries the captured rate amount' );
gate_same(
    'https://shippo-delivery.s3.amazonaws.com/label.pdf?Expires=9999999999',
    $entry['labels'][0]['url'],
    'exposes the label URL in the shape the plugin reads'
);

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_transaction( [ 'status' => 'WAITING', 'label_url' => '' ] );
$GLOBALS['gate_responses'][] = gate_transaction();
$waited                      = gate_client()->book_shipment(
    'shipment0123456789abcdef01234567',
    'rate0000000000000000000000000001',
    gate_bookable_rate(),
    1234
);
gate_check( ! is_wp_error( $waited ), 'polls a WAITING transaction instead of treating it as bought' );
gate_same(
    'https://api.goshippo.com/transactions/transaction0000000000000000000001',
    gate_url( 1 ),
    'polls GET /transactions/{id}'
);

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_transaction(
    [
        'status'   => 'ERROR',
        'messages' => [ [ 'source' => 'USPS', 'code' => 'address', 'text' => 'Address invalid' ] ],
    ]
);
$rejected = gate_client()->book_shipment(
    'shipment0123456789abcdef01234567',
    'rate0000000000000000000000000001',
    gate_bookable_rate(),
    1234
);
gate_check( is_wp_error( $rejected ), 'refuses to treat a non-SUCCESS transaction as a bought label' );
gate_same( 'shippo_label_error', is_wp_error( $rejected ) ? $rejected->get_error_code() : '', 'reports the failing status' );
gate_same( 'Address invalid', is_wp_error( $rejected ) ? $rejected->get_error_message() : '', 'surfaces the carrier message' );
$rejected_data = is_wp_error( $rejected ) ? $rejected->get_error_data() : [];
gate_check( ! empty( $rejected_data['retry_safe'] ), 'allows a retry when the transaction ended in ERROR' );

// A 5xx on the spending POST is never repeated: the label may already be bought.
gate_reset_http();
$GLOBALS['gate_responses'][] = gate_canned( 503, [ 'detail' => 'Service Unavailable' ] );
$unavailable_buy             = gate_client()->book_shipment(
    'shipment0123456789abcdef01234567',
    'rate0000000000000000000000000001',
    gate_bookable_rate(),
    1234
);
gate_check( is_wp_error( $unavailable_buy ), 'a server error on a purchase is reported, not hidden' );
gate_same( 1, count( $GLOBALS['gate_requests'] ), 'a spending POST is never retried on a server error' );

/* -- Timeout is not failure ----------------------------------------------- */

gate_section( 'timeout reconciliation' );

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_timeout();
$GLOBALS['gate_responses'][] = gate_canned(
    200,
    [
        'count'   => 1,
        'results' => [
            [
                'object_id' => 'transaction0000000000000000000001',
                'status'    => 'SUCCESS',
                'rate'      => 'rate0000000000000000000000000001',
            ],
        ],
    ]
);
$GLOBALS['gate_responses'][] = gate_transaction();

$recovered = gate_client()->book_shipment(
    'shipment0123456789abcdef01234567',
    'rate0000000000000000000000000001',
    gate_bookable_rate(),
    1234
);

gate_check( ! is_wp_error( $recovered ), 'reconciles a timed-out purchase into the existing label' );
gate_check( ! empty( $recovered['reconciled'] ), 'flags the purchase as reconciled' );
gate_same( 'GET', $GLOBALS['gate_requests'][1]['args']['method'], 'reconciliation reads before it buys' );
gate_check( false !== strpos( gate_url( 1 ), 'object_created_gte=' ), 'lists transactions created in the recent window' );
gate_same(
    'https://api.goshippo.com/transactions/transaction0000000000000000000001',
    gate_url( 2 ),
    'confirms the purchase with GET /transactions/{id}'
);

$post_count = 0;
foreach ( $GLOBALS['gate_requests'] as $request ) {
    if ( 'POST' === $request['args']['method'] ) {
        $post_count++;
    }
}
gate_same( 1, $post_count, 'does not buy a second label while reconciling' );

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_timeout();
$GLOBALS['gate_responses'][] = gate_canned( 200, [ 'count' => 0, 'results' => [] ] );
$GLOBALS['gate_responses'][] = gate_canned( 200, [ 'count' => 0, 'results' => [] ] );

$unconfirmed      = gate_client()->book_shipment(
    'shipment0123456789abcdef01234567',
    'rate0000000000000000000000000001',
    gate_bookable_rate(),
    1234
);
$unconfirmed_data = is_wp_error( $unconfirmed ) ? $unconfirmed->get_error_data() : [];

gate_check( is_wp_error( $unconfirmed ), 'reports an unconfirmed purchase instead of failing silently' );
gate_same(
    'shippo_purchase_unconfirmed',
    is_wp_error( $unconfirmed ) ? $unconfirmed->get_error_code() : '',
    'uses shippo_purchase_unconfirmed'
);
gate_check( ! empty( $unconfirmed_data['retry_safe'] ), 'permits a retry only after reconciliation found nothing' );

/* -- book_shipments ------------------------------------------------------- */

gate_section( 'book_shipments' );

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_transaction( [ 'object_id' => 'transaction000000000000000000000A' ] );
$GLOBALS['gate_responses'][] = gate_canned( 422, [ 'detail' => 'This rate has already been used' ] );

$bulk_client = gate_client();
$bulk        = $bulk_client->book_shipments(
    [
        [
            'remote_shipment_id' => 'shipmentA',
            'rate_id'            => 'rate0000000000000000000000000001',
            'custom_messages'    => [ '555' ],
            'rate'               => gate_bookable_rate(),
        ],
        [
            'remote_shipment_id' => 'shipmentB',
            'rate_id'            => 'rate0000000000000000000000000002',
            'custom_messages'    => [ '556' ],
            'rate'               => gate_bookable_rate(),
        ],
    ]
);

gate_check( ! is_wp_error( $bulk ), 'books several labels one transaction at a time' );
gate_check( isset( $bulk['successful']['transaction000000000000000000000A'] ), 'collects the labels it bought' );
gate_same(
    '555',
    $bulk['successful']['transaction000000000000000000000A']['customer_reference'],
    'maps a bought label back to its order'
);
gate_check( isset( $bulk['failed']['shipmentB'] ), 'records a failure against its shipment' );
gate_same( '556', $bulk['failed']['shipmentB']['customer_reference'], 'keeps the order id on a failure' );
gate_same( 1, count( $bulk['failed']['shipmentB']['error_messages'] ), 'reports one message per failed shipment' );
gate_same( 2, count( $GLOBALS['gate_requests'] ), 'issues one purchase request per shipment' );
gate_same( [ 1400000 ], $bulk_client->throttles, 'a test key is paced to 1.4 s between purchases' );

// Shippo's documented ceilings are 500 POSTs/minute live and 50 test, so the key
// decides the pace; a live run keeps the 350 ms it always had.
gate_same( 1400000, gate_client()->spacing_for_test(), 'a test key is paced at 1.4 s' );
gate_same( 350000, gate_client( 'shippo_live_abc123' )->spacing_for_test(), 'a live key keeps 350 ms' );

$widened = gate_client();
$widened->widen_for_test();
gate_same( 2800000, $widened->spacing_for_test(), 'a 429 doubles the spacing for the rest of the run' );

$empty_bulk = gate_client()->book_shipments( [] );
gate_same(
    'shippo_no_shipments',
    is_wp_error( $empty_bulk ) ? $empty_bulk->get_error_code() : '',
    'rejects an empty bulk request'
);

/* -- get_shipment --------------------------------------------------------- */

gate_section( 'get_shipment' );

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_canned(
    200,
    [
        'object_id' => 'shipment0123456789abcdef01234567',
        'rates'     => [],
        'messages'  => [],
    ]
);
$shipment = gate_client()->get_shipment( 'shipment0123456789abcdef01234567' );

gate_check( ! is_wp_error( $shipment ), 'returns the shipment object' );
gate_same( 'GET', $GLOBALS['gate_requests'][0]['args']['method'], 'looks a shipment up with GET' );
gate_same(
    'https://api.goshippo.com/shipments/shipment0123456789abcdef01234567',
    gate_url( 0 ),
    'calls /shipments/{id}'
);

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_canned( 404, [ 'detail' => 'Not found.' ] );
$missing_shipment            = gate_client()->get_shipment( 'shipmentmissing' );
gate_same(
    'shippo_not_found',
    is_wp_error( $missing_shipment ) ? $missing_shipment->get_error_code() : '',
    'maps a 404 to shippo_not_found'
);
gate_same(
    'Shipment not found in Shippo.',
    is_wp_error( $missing_shipment ) ? $missing_shipment->get_error_message() : '',
    'explains a missing shipment'
);

/* -- get_transaction ------------------------------------------------------ */

gate_section( 'get_transaction' );

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_transaction();
$transaction                 = gate_client()->get_transaction( 'transaction0000000000000000000001' );

gate_check( ! is_wp_error( $transaction ), 'returns the transaction object' );
gate_same(
    'https://api.goshippo.com/transactions/transaction0000000000000000000001',
    gate_url( 0 ),
    'calls /transactions/{id}'
);

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_canned( 404, [ 'detail' => 'Not found.' ] );
$missing_transaction         = gate_client()->get_transaction( 'nope' );
gate_same(
    'shippo_not_found',
    is_wp_error( $missing_transaction ) ? $missing_transaction->get_error_code() : '',
    'maps a 404 to shippo_not_found'
);
gate_same(
    'Transaction not found in Shippo.',
    is_wp_error( $missing_transaction ) ? $missing_transaction->get_error_message() : '',
    'explains a missing transaction'
);

/* -- get_label_url -------------------------------------------------------- */

gate_section( 'get_label_url' );

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_transaction();
$label_url                   = gate_client()->get_label_url( 'transaction0000000000000000000001' );

gate_same(
    'https://shippo-delivery.s3.amazonaws.com/label.pdf?Expires=9999999999',
    $label_url,
    'returns the signed label URL'
);
gate_same(
    'https://api.goshippo.com/transactions/transaction0000000000000000000001',
    gate_url( 0 ),
    'refreshes the URL from GET /transactions/{id}'
);

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_transaction( [ 'label_url' => 'https://shippo-delivery.s3.amazonaws.com/label.pdf?Expires=1' ] );
$expired_label               = gate_client()->get_label_url( 'transaction0000000000000000000001' );
gate_same(
    'shippo_label_expired',
    is_wp_error( $expired_label ) ? $expired_label->get_error_code() : '',
    'reports an expired label file instead of a dead URL'
);

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_transaction( [ 'status' => 'REFUNDPENDING', 'label_url' => 'https://shippo-delivery.s3.amazonaws.com/label.pdf?Expires=9999999999' ] );
$pending_label_url           = gate_client()->get_label_url( 'transaction0000000000000000000001' );
gate_same(
    'https://shippo-delivery.s3.amazonaws.com/label.pdf?Expires=9999999999',
    $pending_label_url,
    'still prints a label whose refund is only pending'
);

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_transaction( [ 'status' => 'WAITING', 'label_url' => '' ] );
$unbought_label              = gate_client()->get_label_url( 'transaction0000000000000000000001' );
gate_same(
    'shippo_label_unavailable',
    is_wp_error( $unbought_label ) ? $unbought_label->get_error_code() : '',
    'refuses to hand out a label that was never bought'
);

/* -- refund_shipment ------------------------------------------------------ */

gate_section( 'refund_shipment' );

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_transaction();
$GLOBALS['gate_responses'][] = gate_canned( 200, [ 'object_id' => 'refund0000000000000000000001', 'status' => 'QUEUED' ] );
$refund                      = gate_client()->refund_shipment( 'transaction0000000000000000000001' );

gate_check( ! is_wp_error( $refund ), 'requests a refund for a purchased label' );
gate_same( 'https://api.goshippo.com/refunds', gate_url( 1 ), 'posts to /refunds' );
gate_same( 45, (int) $GLOBALS['gate_requests'][1]['args']['timeout'], 'uses the refund timeout' );

$refund_body = gate_body( 1 );
gate_same( false, $refund_body['async'], 'async is false on the refund request' );
gate_same( 'transaction0000000000000000000001', $refund_body['transaction'], 'refunds the transaction id' );
gate_same( true, $refund['requested'], 'marks the refund as requested, not confirmed' );
gate_same( 'QUEUED', $refund['status'], 'reports the refund status Shippo returned' );

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_transaction( [ 'status' => 'REFUNDPENDING' ] );
$pending_refund              = gate_client()->refund_shipment( 'transaction0000000000000000000001' );
gate_same(
    'shippo_refund_pending',
    is_wp_error( $pending_refund ) ? $pending_refund->get_error_code() : '',
    'reports a refund that is already pending instead of "only a purchased label"'
);
gate_same(
    'A refund for this label is already pending with the carrier.',
    is_wp_error( $pending_refund ) ? $pending_refund->get_error_message() : '',
    'explains the pending refund in plain language'
);

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_transaction( [ 'status' => 'REFUNDED' ] );
$repeat_refund               = gate_client()->refund_shipment( 'transaction0000000000000000000001' );
gate_same(
    'shippo_already_refunded',
    is_wp_error( $repeat_refund ) ? $repeat_refund->get_error_code() : '',
    'refuses to refund the same label twice'
);
gate_same( 1, count( $GLOBALS['gate_requests'] ), 'does not call /refunds for an already refunded label' );

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_transaction( [ 'status' => 'ERROR' ] );
$unbought_refund             = gate_client()->refund_shipment( 'transaction0000000000000000000001' );
gate_same(
    'shippo_not_refundable',
    is_wp_error( $unbought_refund ) ? $unbought_refund->get_error_code() : '',
    'only refunds a label that was actually bought'
);

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_transaction();
$GLOBALS['gate_responses'][] = gate_canned(
    200,
    [
        'object_id' => 'refund0000000000000000000002',
        'status'    => 'ERROR',
        'messages'  => [ [ 'source' => 'USPS', 'code' => 'window', 'text' => 'Refund window expired' ] ],
    ]
);
$rejected_refund             = gate_client()->refund_shipment( 'transaction0000000000000000000001' );
gate_same(
    'shippo_refund_failed',
    is_wp_error( $rejected_refund ) ? $rejected_refund->get_error_code() : '',
    'reports a refund Shippo refused'
);
gate_same(
    'Refund window expired',
    is_wp_error( $rejected_refund ) ? $rejected_refund->get_error_message() : '',
    'surfaces the refund error message'
);

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_transaction();
$GLOBALS['gate_responses'][] = gate_timeout();
$GLOBALS['gate_responses'][] = gate_transaction( [ 'status' => 'REFUNDED' ] );
$reconciled_refund           = gate_client()->refund_shipment( 'transaction0000000000000000000001' );

gate_check( ! is_wp_error( $reconciled_refund ), 'reconciles a timed-out refund into the refunded label' );
gate_check( ! empty( $reconciled_refund['reconciled'] ), 'flags the refund as reconciled' );
gate_same( 3, count( $GLOBALS['gate_requests'] ), 'checks the label again after a refund timeout' );

/* -- Dispatch date -------------------------------------------------------- */

gate_section( 'dispatch date' );

// 2026-11-16 is a Monday in EST (UTC-5), so local noon is 17:00Z.
gate_same( '2026-11-16', gate_dispatch_date( '2026-11-16 15:59:00' ), 'before the 4pm cutoff the label is dated today' );
gate_same( '2026-11-16', gate_dispatch_date( '2026-11-16 16:00:00' ), 'exactly at 4pm still ships today' );
gate_same( '2026-11-17', gate_dispatch_date( '2026-11-16 16:01:00' ), 'after 4pm rolls to the next day' );
gate_same( '2026-11-17', gate_dispatch_date( '2026-11-16 23:30:00' ), 'late at night still rolls to the next day' );
gate_same( '2026-11-17', gate_dispatch_date( '2026-11-16 16:30:00', [ 'carrier' => 'UPS' ] ), 'the cutoff is the same for every carrier' );

// 2026-11-20 is a Friday, 2026-11-21 Saturday, 2026-11-22 Sunday.
gate_same( '2026-11-20', gate_dispatch_date( '2026-11-20 15:00:00' ), 'Friday before the cutoff ships Friday' );
gate_same( '2026-11-23', gate_dispatch_date( '2026-11-20 16:30:00' ), 'Friday after 4pm ships Monday' );
gate_same( '2026-11-23', gate_dispatch_date( '2026-11-20 23:00:00' ), 'Friday night ships Monday' );
gate_same( '2026-11-23', gate_dispatch_date( '2026-11-21 09:00:00' ), 'Saturday morning ships Monday, never Saturday' );
gate_same( '2026-11-23', gate_dispatch_date( '2026-11-21 13:00:00' ), 'no Saturday labels even before a cutoff' );
gate_same( '2026-11-23', gate_dispatch_date( '2026-11-22 10:00:00' ), 'Sunday ships Monday' );

// 2026-07-13 is a Monday in EDT (UTC-4), so local noon is 16:00Z.
gate_same( '2026-07-13', gate_dispatch_date( '2026-07-13 10:00:00' ), 'summer date keeps the local calendar day' );
gate_same( '2026-07-14', gate_dispatch_date( '2026-07-13 16:30:00' ), 'the 4pm cutoff follows daylight saving too' );

/* -- Key wiring ----------------------------------------------------------- */

gate_section( 'key wiring' );

$GLOBALS['gate_options']['shipping_desk_shippo_test_api_key'] = 'shippo_test_from_option';
$GLOBALS['gate_options']['shipping_desk_shippo_live_api_key'] = 'shippo_live_from_option';

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_rates_response();
$explicit                    = new Gate_Shippo_Client( 'shippo_test_explicit' );
$explicit->get_rates( gate_rate_payload() );
gate_same(
    'ShippoToken shippo_test_explicit',
    $GLOBALS['gate_requests'][0]['args']['headers']['Authorization'],
    'uses the key it is constructed with'
);

gate_reset_http();
$kept_options            = $GLOBALS['gate_options'];
$GLOBALS['gate_options'] = [
    // Names here are arbitrary: the point is that the client reads none of them.
    'shipping_desk_shippo_api_key'      => 'shippo_test_legacy_option',
    'shipping_desk_shippo_test_api_key' => 'shippo_test_from_option',
];
$unkeyed                 = ( new Gate_Shippo_Client() )->get_rates( gate_rate_payload() );
gate_same(
    'shippo_no_api_key',
    is_wp_error( $unkeyed ) ? $unkeyed->get_error_code() : '',
    'never reads a key from an option by itself'
);
gate_same( 0, count( $GLOBALS['gate_requests'] ), 'makes no request without a key' );

$GLOBALS['gate_options'] = $kept_options;

/* -- Carrier recovery ----------------------------------------------------- */

gate_section( 'carrier recovery' );

// Shippo's transaction only returns a rate ID, so the carrier must come from the
// rate. With no rate supplied the client has to read it back from the shipment,
// otherwise the order note says "Carrier" and tracking plugins cannot complete
// the order.
gate_reset_http();
$GLOBALS['gate_responses'][] = gate_transaction();
$GLOBALS['gate_responses'][] = gate_canned(
    200,
    [
        'object_id' => 'shipment0123456789abcdef01234567',
        'rates'     => [
            [
                'object_id'    => 'rate0000000000000000000000000001',
                'amount'       => '12.38',
                'currency'     => 'USD',
                'provider'     => 'UPS',
                'servicelevel' => [ 'name' => 'Ground', 'token' => 'ups_ground' ],
            ],
        ],
    ]
);

$hydrated = gate_client()->book_shipment(
    'shipment0123456789abcdef01234567',
    'rate0000000000000000000000000001',
    [],
    1234
);

gate_check( ! is_wp_error( $hydrated ), 'books without a caller-supplied rate' );
gate_same(
    'https://api.goshippo.com/shipments/shipment0123456789abcdef01234567',
    gate_url( 1 ),
    'reads the shipment back to identify the carrier'
);
gate_same(
    'UPS',
    $hydrated['successful']['transaction0000000000000000000001']['carrier_id'],
    'recovers the carrier when the rate was not supplied'
);
gate_same(
    'UPS Ground',
    $hydrated['successful']['transaction0000000000000000000001']['service_name'],
    'recovers the service name too'
);

// A supplied rate must be used as-is, with no extra request.
gate_reset_http();
$GLOBALS['gate_responses'][] = gate_transaction();
gate_client()->book_shipment(
    'shipment0123456789abcdef01234567',
    'rate0000000000000000000000000001',
    gate_bookable_rate(),
    1234
);
gate_same( 1, count( $GLOBALS['gate_requests'] ), 'does not re-read the shipment when the rate is supplied' );

/* -- Address validation ---------------------------------------------------- */

gate_section( 'address validation' );

gate_check(
    in_array( 'Shipping_Desk_Address_Validator_Interface', class_implements( 'Shipping_Desk_Shippo_Client' ), true ),
    'the client advertises the optional validator contract'
);

function gate_validation_address( array $overrides = [] ) {
    return array_merge(
        [
            'name'         => 'Ada Lovelace',
            'phone'        => '4151234567',
            'email'        => 'ada@example.com',
            'line1'        => '320J Outerbelt Street',
            'town'         => 'Columbus',
            'postcode'     => '43213',
            'country_code' => 'US',
            'county'       => 'OH',
        ],
        $overrides
    );
}

/**
 * The response Shippo actually returned for that address in live testing, cut
 * down to the fields this client reads. Keep it verbatim: the parser is being
 * checked against a real body, not an invented one.
 */
function gate_validation_response() {
    return [
        'original_address'    => [
            'address_line_1' => '320J Outerbelt Street',
            'city_locality'  => 'Columbus',
            'state_province' => 'OH',
            'postal_code'    => '43213',
            'country_code'   => 'US',
        ],
        'recommended_address' => [
            'address_line_1'    => '320 Outerbelt St',
            'address_line_2'    => 'Ste J',
            'city_locality'     => 'Columbus',
            'state_province'    => 'OH',
            'postal_code'       => '43213-1537',
            'country_code'      => 'US',
            'complete_address'  => '320 Outerbelt St Ste J; Columbus OH 43213-1537; US',
            'confidence_result' => [
                'score'       => 'high',
                'code'        => 'postal_data_match',
                'description' => 'The address has been completely verified to the most granular level possible.',
            ],
        ],
        'analysis'            => [
            'validation_result'  => [
                'value'   => 'partially_valid',
                'reasons' => [
                    [
                        'code'        => 'address_abbreviation_fixed',
                        'type'        => 'correction',
                        'description' => 'Address inputted was standardized for abbreviations according to postal authority rules.',
                    ],
                ],
            ],
            'address_type'       => 'commercial',
            'changed_attributes' => [ 'address_line_2', 'address_line_1', 'postal_code' ],
        ],
        'geo'                 => [ 'latitude' => 39.98725, 'longitude' => -82.83042 ],
    ];
}

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_canned( 200, gate_validation_response() );
$validation                  = gate_client()->validate_address( gate_validation_address() );

gate_same( 1, count( $GLOBALS['gate_requests'] ), 'validation costs exactly one request' );
gate_same(
    'https://api.goshippo.com/v2/addresses/validate',
    strtok( gate_url( 0 ), '?' ),
    'calls the V2 validation endpoint'
);
gate_same( 'GET', $GLOBALS['gate_requests'][0]['args']['method'], 'validation is a GET, not a POST' );
gate_same( null, gate_body( 0 ), 'sends a query string rather than a JSON body' );

parse_str( (string) wp_parse_url( gate_url( 0 ), PHP_URL_QUERY ), $validation_query );
gate_same( '320J Outerbelt Street', $validation_query['address_line_1'], 'sends line1 as address_line_1' );
gate_same( 'Columbus', $validation_query['city_locality'], 'sends town as city_locality' );
gate_same( 'OH', $validation_query['state_province'], 'sends county as state_province' );
gate_same( '43213', $validation_query['postal_code'], 'sends postcode as postal_code' );
gate_same( 'US', $validation_query['country_code'], 'sends the country code' );
gate_check( ! isset( $validation_query['address_line_2'] ), 'omits an empty second line instead of sending a blank' );

gate_same( 'corrected', $validation['status'], 'a standardised address is reported as corrected' );
gate_same( true, $validation['is_valid'], 'a correction still counts as a valid address' );
gate_same( '320 Outerbelt St', $validation['recommended']['line1'], 'maps street1 back to line1' );
gate_same( 'Ste J', $validation['recommended']['line2'], 'keeps the second line the validator invented' );
gate_same( '43213-1537', $validation['recommended']['postcode'], 'maps zip back to postcode' );
gate_same( 'Columbus', $validation['recommended']['town'], 'maps city back to town' );
gate_same( 'OH', $validation['recommended']['county'], 'maps state back to county' );
gate_same(
    [ 'address_line_2', 'address_line_1', 'postal_code' ],
    $validation['changed'],
    'reports which attributes changed, in Shippo order'
);
gate_same( 'commercial', $validation['address_type'], 'reports the address type' );
gate_same( 'high', $validation['confidence']['score'], 'reports validator confidence' );
gate_same( 'postal_data_match', $validation['confidence']['code'], 'reports the confidence code' );
gate_same( 'address_abbreviation_fixed', $validation['reasons'][0]['code'], 'keeps the reason codes' );

/* A clean address: valid, with nothing to apply. */
gate_reset_http();
$GLOBALS['gate_responses'][] = gate_canned(
    200,
    [
        'recommended_address' => [
            'street1' => '1 Depot Way',
            'city'    => 'Chicago',
            'state'   => 'IL',
            'zip'     => '60601',
            'country' => 'US',
        ],
        'analysis'            => [
            'validation_result'  => [ 'value' => 'valid', 'reasons' => [] ],
            'changed_attributes' => [],
        ],
    ]
);
$clean = gate_client()->validate_address( gate_validation_address( [ 'postcode' => '60601' ] ) );
gate_same( 'valid', $clean['status'], 'a clean address is valid' );
gate_same( true, $clean['is_valid'], 'a clean address passes' );
gate_same( [], $clean['changed'], 'nothing changed for a clean address' );

/* An address that cannot be matched: block, and say why. */
gate_reset_http();
$GLOBALS['gate_responses'][] = gate_canned(
    200,
    [
        'analysis' => [
            'validation_result'  => [
                'value'   => 'invalid',
                'reasons' => [
                    [
                        'code'        => 'address_house_number_missing',
                        'type'        => 'error',
                        'description' => 'House number is missing.',
                    ],
                ],
            ],
            'changed_attributes' => [],
        ],
    ]
);
$invalid = gate_client()->validate_address( gate_validation_address( [ 'line1' => 'Outerbelt Street' ] ) );
gate_same( 'invalid', $invalid['status'], 'an unmatched address is invalid' );
gate_same( false, $invalid['is_valid'], 'an invalid address does not pass' );
gate_same( [], $invalid['recommended'], 'offers no correction when there is none' );
gate_same( 'address_house_number_missing', $invalid['reasons'][0]['code'], 'carries the reason an address failed' );

/*
 * The fail-open rule. Validation is an optimisation: a validator that is down,
 * unprovisioned or throttled out must never stop a label being bought.
 */
gate_reset_http();
$GLOBALS['gate_responses'][] = gate_canned( 500, [ 'detail' => 'Internal Server Error' ] );
$unavailable                 = gate_client()->validate_address( gate_validation_address() );
gate_same( 'unavailable', $unavailable['status'], 'a 500 is unavailable, not a failure' );
gate_check( '' !== $unavailable['error'], 'explains why validation could not run' );
gate_same( false, $unavailable['is_valid'], 'never claims validity when the validator did not answer' );

gate_reset_http();
$GLOBALS['gate_responses'][] = gate_canned( 403, [ 'detail' => 'Forbidden' ] );
gate_same(
    'unavailable',
    gate_client()->validate_address( gate_validation_address() )['status'],
    'a 403 leaves the address unvalidated rather than blocking the quote'
);

/* Nothing worth paying for: no street, or no postcode and no city/state. */
gate_reset_http();
$sparse = gate_client()->validate_address( [ 'line1' => '', 'postcode' => '' ] );
gate_same( 0, count( $GLOBALS['gate_requests'] ), 'does not call Shippo for an address with no street' );
gate_same( 'unavailable', $sparse['status'], 'reports an unusable address as unavailable' );

/* Shippo accepts city plus state when the postcode is missing. */
gate_reset_http();
$GLOBALS['gate_responses'][] = gate_canned( 200, [ 'analysis' => [ 'validation_result' => [ 'value' => 'valid' ] ] ] );
gate_client()->validate_address(
    [
        'line1'  => '320J Outerbelt Street',
        'town'   => 'Columbus',
        'county' => 'OH',
    ]
);
gate_same( 1, count( $GLOBALS['gate_requests'] ), 'accepts city and state when the postcode is missing' );

/* Throttling is handled by the shared transport, not skipped for validation. */
gate_reset_http();
$GLOBALS['gate_responses'][] = gate_canned( 429, [ 'detail' => 'Too Many Requests' ], [ 'retry-after' => '1' ] );
$GLOBALS['gate_responses'][] = gate_canned( 200, [ 'analysis' => [ 'validation_result' => [ 'value' => 'valid' ] ] ] );
$retried_validation          = gate_client()->validate_address( gate_validation_address() );
gate_same( 2, count( $GLOBALS['gate_requests'] ), 'retries a throttled validation call' );
gate_same( 'valid', $retried_validation['status'], 'uses the retried answer' );

/* -- Retry rule (structural) ---------------------------------------------- */

gate_section( 'retry rule' );

// The rule lives in one helper and is method-aware, so a later edit cannot
// quietly start repeating a spending POST.
$shippo_source = str_replace(
    "\r\n",
    "\n",
    (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-shippo-client.php' )
);

gate_check(
    false !== strpos( $shippo_source, 'function should_retry( $method, $code )' ),
    'the retry decision is stated in one should_retry() helper'
);
gate_check(
    false !== strpos( $shippo_source, "\$this->should_retry( \$method, \$code )" ),
    'request() decides through that helper, passing the method'
);
gate_check(
    false !== strpos( $shippo_source, "'GET' === strtoupper( (string) \$method )" ),
    'the helper only retries a server error for GET, never for POST'
);
gate_check(
    false !== strpos( $shippo_source, '$code >= 500' ),
    'the 5xx threshold is >= 500'
);

/* -- Result --------------------------------------------------------------- */

echo PHP_EOL . 'Checks: ' . $GLOBALS['gate_checks'] . ', failures: ' . $GLOBALS['gate_failures'] . PHP_EOL;
exit( 0 === $GLOBALS['gate_failures'] ? 0 : 1 );
