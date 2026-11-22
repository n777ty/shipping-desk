<?php
/**
 * Gate for EasyPost as a label provider.
 *
 * Standalone: no Composer, no PHPUnit, no WordPress. Stubs the WordPress HTTP
 * functions, then checks the provider half of Shipping_Desk_EasyPost_Client:
 *
 *   - the shipment body: addresses, parcel in oz/inches, reference, options
 *   - the options: PNG 4x6, label_date on the 4pm Eastern dispatch rule,
 *     print_custom_1 for the order number, STANDARD/ADULT mapped to
 *     SIGNATURE/ADULT_SIGNATURE
 *   - rate names: `UPSDAP`/`FedExDefault` and raw codes turned into shop-facing
 *     names, with the raw values kept for meta
 *   - every response branch: rates, 2xx without rates, 2xx without a label,
 *     401, 422 on an option (retried without it), unrelated 422 (not retried)
 *   - the buy: rate id, insurance (and its $5,000 cap), fees-based cost
 *   - a timeout is reconciled before a retry is called safe
 *   - refunds: already refunded, pending, submitted, 429 with Retry-After
 *   - labels: PNG for browser printing, converted PDF for PrintNode
 *   - bulk: throttled loop, per-order failure isolation
 *   - the dispatch date, including weekends and the cutoff
 *
 * Run:  php tests/easypost-provider-test.php
 * Exit: 0 when every check passes, 1 otherwise.
 */

error_reporting( E_ALL );

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {

        protected $errors = [];
        protected $data   = [];

        public function __construct( $code = '', $message = '', $data = '' ) {
            if ( '' === $code ) {
                return;
            }
            $this->errors[ $code ][] = $message;
            $this->data[ $code ]     = $data;
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

        public function get_error_data( $code = '' ) {
            $code = '' === $code ? $this->get_error_code() : $code;
            return isset( $this->data[ $code ] ) ? $this->data[ $code ] : null;
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

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $value ) {
        return json_encode( $value );
    }
}

if ( ! function_exists( 'wp_remote_get' ) ) {
    function wp_remote_get( $url, $args = [] ) {
        return gate_http( 'GET', $url, $args );
    }
}

if ( ! function_exists( 'wp_remote_post' ) ) {
    function wp_remote_post( $url, $args = [] ) {
        return gate_http( 'POST', $url, $args );
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
    function wp_remote_retrieve_header( $response, $header ) {
        $header = strtolower( (string) $header );
        return isset( $response['headers'][ $header ] ) ? $response['headers'][ $header ] : '';
    }
}

/**
 * Queue-aware HTTP stub. Every call is recorded so the request shape can be
 * asserted, and an empty queue is a failure rather than a silent success.
 */
function gate_http( $method, $url, $args ) {
    $GLOBALS['gate_requests'][] = [ 'method' => $method, 'url' => $url, 'args' => $args ];

    if ( empty( $GLOBALS['gate_responses'] ) ) {
        return new WP_Error( 'http_request_failed', 'No canned response queued for ' . $method . ' ' . $url );
    }

    return array_shift( $GLOBALS['gate_responses'] );
}

require_once dirname( __DIR__ ) . '/includes/class-provider-interface.php';
require_once dirname( __DIR__ ) . '/includes/class-address-validator-interface.php';
require_once dirname( __DIR__ ) . '/includes/helpers.php';
require_once dirname( __DIR__ ) . '/includes/class-easypost-client.php';

/* -- Harness -------------------------------------------------------------- */

$GLOBALS['gate_checks']   = 0;
$GLOBALS['gate_failures'] = 0;

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
        'headers'  => $headers,
        'body'     => is_string( $body ) ? $body : json_encode( $body ),
    ];
}

function gate_reset() {
    $GLOBALS['gate_responses'] = [];
    $GLOBALS['gate_requests']  = [];
}

/** Client with a frozen clock and a no-op sleeper, so the gate runs instantly. */
class Gate_EasyPost_Client extends Shipping_Desk_EasyPost_Client {

    public $now_string = '2026-11-11 10:00:00';

    public $pauses    = [];
    public $throttles = 0;

    /** Every spacing value passed to throttle(), so pacing can be asserted. */
    public $throttle_values = [];

    protected function now() {
        return new DateTimeImmutable( $this->now_string, new DateTimeZone( self::DISPATCH_TIMEZONE ) );
    }

    protected function pause( $seconds ) {
        $this->pauses[] = $seconds;
    }

    protected function throttle( $microseconds ) {
        $this->throttles++;
        $this->throttle_values[] = (int) $microseconds;
    }

    /** Expose the pacing state for the gate. */
    public function spacing_for_test() {
        return $this->spacing_us();
    }

    public function widen_for_test() {
        $this->widen_spacing();
    }

    public function ease_for_test() {
        $this->ease_spacing();
    }

    /** Expose the protected page-box crop for the gate. */
    public function crop_for_test( $bytes ) {
        return $this->crop_label_page( $bytes );
    }
}

function gate_client( $key = 'EZTK_test_key' ) {
    return new Gate_EasyPost_Client( $key );
}

/* -- Fixtures ------------------------------------------------------------- */

function gate_rate( array $overrides = [] ) {
    return array_merge(
        [
            'id'                      => 'rate_1',
            'object'                  => 'Rate',
            'carrier'                 => 'USPS',
            'service'                 => 'GroundAdvantage',
            'rate'                    => '8.20',
            'currency'                => 'USD',
            'retail_rate'             => '13.85',
            'list_rate'               => '9.74',
            'delivery_days'           => 3,
            'est_delivery_days'       => 3,
            'delivery_date_guaranteed' => false,
        ],
        $overrides
    );
}

function gate_shipment( array $overrides = [] ) {
    return array_merge(
        [
            'id'       => 'shp_123',
            'object'   => 'Shipment',
            'mode'     => 'test',
            'status'   => 'unknown',
            'rates'    => [ gate_rate(), gate_rate( [ 'id' => 'rate_2', 'service' => 'Priority', 'rate' => '12.40', 'delivery_days' => 1 ] ) ],
            'messages' => [],
        ],
        $overrides
    );
}

function gate_bought( array $overrides = [] ) {
    return array_merge(
        [
            'id'            => 'shp_123',
            'object'        => 'Shipment',
            'mode'          => 'test',
            'status'        => 'unknown',
            'tracking_code' => '9400100000000000000001',
            'selected_rate' => gate_rate(),
            'postage_label' => [
                'id'              => 'pl_1',
                'label_url'       => 'https://easypost-files.example/label.png',
                'label_pdf_url'   => null,
                'label_size'      => '4x6',
                'label_file_type' => 'image/png',
            ],
            'fees'          => [
                // The spelling EasyPost's fee docs enumerate.
                [ 'object' => 'Fee', 'type' => 'PostageFee', 'amount' => '8.20000', 'charged' => true ],
                [ 'object' => 'Fee', 'type' => 'InsuranceFee', 'amount' => '0.50000', 'charged' => true ],
            ],
            'insurance'     => '249.99',
            'refund_status' => null,
        ],
        $overrides
    );
}

function gate_payload( array $overrides = [] ) {
    return array_merge(
        [
            'to_address'         => [
                'name'         => 'Ada Lovelace',
                'phone'        => '4151234567',
                'email'        => 'ada@example.com',
                'line1'        => '504 Circle Dr',
                'line2'        => 'Apt 2',
                'town'         => 'Wharton',
                'county'       => 'TX',
                'postcode'     => '77488',
                'country_code' => 'US',
            ],
            'from_address'       => [
                'name'         => 'Shipping Desk',
                'line1'        => '1 Main St',
                'town'         => 'Austin',
                'county'       => 'TX',
                'postcode'     => '78701',
                'country_code' => 'US',
            ],
            'parcels'            => [
                [ 'weight' => 12.5, 'length' => 6, 'width' => 4, 'height' => 2, 'weight_unit' => 'oz', 'dimension_unit' => 'in' ],
            ],
            'customer_reference' => '1042',
        ],
        $overrides
    );
}

/**
 * Minimal, structurally valid PDF with a real xref table, for the crop tests.
 *
 * The page box is passed in so the same builder can make a 4x6, 4x7, 4x8 or letter
 * page; the crop only touches a 4-inch-wide page taller than 4x6.
 *
 * @param string $media_box MediaBox array, brackets included.
 * @param int    $pages     Page count.
 * @return string
 */
function gate_pdf( $media_box = '[ 0 0 288 504 ]', $pages = 1 ) {
    $objects = [];
    $kids    = [];
    for ( $i = 0; $i < $pages; $i++ ) {
        $kids[] = ( 3 + $i ) . ' 0 R';
    }

    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[2] = '<< /Type /Pages /Kids [ ' . implode( ' ', $kids ) . ' ] /Count ' . $pages . ' >>';
    for ( $i = 0; $i < $pages; $i++ ) {
        $objects[ 3 + $i ] = '<< /Type /Page /Parent 2 0 R /MediaBox ' . $media_box . ' >>';
    }

    $out     = "%PDF-1.4\n";
    $offsets = [];
    foreach ( $objects as $number => $body ) {
        $offsets[ $number ] = strlen( $out );
        $out               .= $number . " 0 obj\n" . $body . "\nendobj\n";
    }

    $xref  = strlen( $out );
    $count = count( $objects ) + 1;
    $out  .= "xref\n0 " . $count . "\n0000000000 65535 f \n";
    for ( $i = 1; $i < $count; $i++ ) {
        $out .= sprintf( "%010d 00000 n \n", $offsets[ $i ] );
    }
    $out .= "trailer\n<< /Size " . $count . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";

    return $out;
}

/**
 * A US letter page carrying vector artwork, the way EasyPost builds FedEx labels:
 * page 612x792, one Flate content stream, no CropBox.
 *
 * @param string $content Content stream text, uncompressed.
 * @return string
 */
function gate_pdf_letter( $content = '' ) {
    $stream  = gzcompress( $content );
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [ 3 0 R ] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [ 0 0 612 792 ] /Contents 4 0 R >>',
        4 => '<< /Length ' . strlen( $stream ) . ' /Filter /FlateDecode >>' . "\nstream\n" . $stream . "\nendstream",
    ];

    $out     = "%PDF-1.4\n";
    $offsets = [];
    foreach ( $objects as $number => $body ) {
        $offsets[ $number ] = strlen( $out );
        $out               .= $number . " 0 obj\n" . $body . "\nendobj\n";
    }

    $xref  = strlen( $out );
    $count = count( $objects ) + 1;
    $out  .= "xref\n0 " . $count . "\n0000000000 65535 f \n";
    for ( $i = 1; $i < $count; $i++ ) {
        $out .= sprintf( "%010d 00000 n \n", $offsets[ $i ] );
    }
    $out .= "trailer\n<< /Size " . $count . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";

    return $out;
}

function gate_body( $index = 0 ) {
    $request = isset( $GLOBALS['gate_requests'][ $index ] ) ? $GLOBALS['gate_requests'][ $index ] : [];
    if ( empty( $request['args']['body'] ) ) {
        return [];
    }
    return json_decode( $request['args']['body'], true );
}

/**
 * The inner shipment object. EasyPost wraps created resources: {"shipment": {...}},
 * and sending the fields at the top level earns a 422 that names all three of them as
 * "required and missing" — the mistake this helper exists to keep out.
 *
 * @param int $index Request index.
 * @return array
 */
function gate_shipment_body( $index = 0 ) {
    $decoded = gate_body( $index );
    return isset( $decoded['shipment'] ) && is_array( $decoded['shipment'] ) ? $decoded['shipment'] : [];
}

/* -- Contract ------------------------------------------------------------- */

gate_section( 'contract' );

$implements = class_implements( 'Shipping_Desk_EasyPost_Client' );
gate_check( in_array( 'Shipping_Desk_Provider_Interface', $implements, true ), 'implements the provider contract' );
gate_check( in_array( 'Shipping_Desk_Address_Validator_Interface', $implements, true ), 'still implements the validator contract' );
gate_same( 'PDF', Shipping_Desk_EasyPost_Client::LABEL_FORMAT, 'labels are bought as PDF' );
gate_same( '4x6', Shipping_Desk_EasyPost_Client::LABEL_SIZE, 'labels are requested at 4x6' );
gate_same( 30, Shipping_Desk_EasyPost_Client::REFERENCE_MAX_LENGTH, 'references are capped at 30 characters, as on Shippo' );
gate_same( 5000, Shipping_Desk_EasyPost_Client::MAX_INSURANCE_AMOUNT, 'insurance cap matches EasyPost' );
gate_same( 'SIGNATURE', Shipping_Desk_EasyPost_Client::SIGNATURE_OPTIONS['STANDARD'], 'STANDARD maps to SIGNATURE' );
gate_same( 'ADULT_SIGNATURE', Shipping_Desk_EasyPost_Client::SIGNATURE_OPTIONS['ADULT'], 'ADULT maps to ADULT_SIGNATURE' );
gate_check(
    method_exists( 'Shipping_Desk_EasyPost_Client', 'get_printable_label_pdf' ),
    'the browser print endpoint can ask for the cropped 4x6 PDF'
);

/* -- Rating --------------------------------------------------------------- */

gate_section( 'rates' );

gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 201, gate_shipment() );
$client = gate_client();
$result = $client->get_rates( gate_payload() );

gate_check( ! is_wp_error( $result ), 'a priced shipment is not an error' );
gate_same( 'shp_123', $result['remote_shipment_id'], 'the shipment id is the remote id' );
gate_same( 2, count( $result['quotes'] ), 'every rate becomes a quote' );
gate_same( 'https://api.easypost.com/v2/shipments', $GLOBALS['gate_requests'][0]['url'], 'rates are POSTed to /v2/shipments' );
gate_same( 'POST', $GLOBALS['gate_requests'][0]['method'], 'rating uses POST' );
gate_same(
    'Basic ' . base64_encode( 'EZTK_test_key:' ),
    $GLOBALS['gate_requests'][0]['args']['headers']['Authorization'],
    'Basic auth carries the key and an empty password'
);
gate_same( 'application/json', $GLOBALS['gate_requests'][0]['args']['headers']['Content-Type'], 'the body is JSON' );
gate_check( isset( gate_body()['shipment'] ), 'the body is wrapped in a "shipment" resource envelope' );
gate_check( ! isset( gate_body()['to_address'] ), 'and the fields are not duplicated at the top level' );

$quote = $result['quotes'][0];
gate_same( 'rate_1', $quote['rate_id'], 'rate_id is the EasyPost rate id' );
gate_same( 'shp_123', $quote['remote_shipment_id'], 'every quote carries the parent shipment' );
gate_same( 'USPS Ground Advantage', $quote['service_name'], 'service_name is the shop-facing carrier + service' );
gate_same( '8.20', $quote['total_charge'], 'total_charge is the rate amount' );
gate_same( 'USD', $quote['currency'], 'currency is preserved' );
gate_same( 'USPS', $quote['provider'], 'provider is the carrier, as with Shippo' );
gate_same( [], $quote['shipping_service_options'], 'no priced extras, but the key exists for the JS' );
gate_same( 3, $quote['estimated_days'], 'transit days come from delivery_days' );
gate_same( '', $quote['duration_terms'], 'no wording is invented for duration_terms' );

/* -- Rate names ----------------------------------------------------------- */

gate_section( 'rate names' );

// The exact rate list an EasyPost test account returned: carrier *account* values
// (UPSDAP, FedExDefault) and raw carrier codes. Each row has to read as a service
// a shop recognises, not "UPSDAP Ground".
$names = [
    [ 'UPSDAP', 'UPSGroundsaverLessThan1lb', 'UPS Ground Saver' ],
    [ 'UPSDAP', 'Ground', 'UPS Ground' ],
    [ 'UPSDAP', '3DaySelect', 'UPS 3 Day Select' ],
    [ 'UPSDAP', '2ndDayAir', 'UPS 2nd Day Air' ],
    [ 'UPSDAP', 'NextDayAir', 'UPS Next Day Air' ],
    [ 'UPSDAP', 'NextDayAirSaver', 'UPS Next Day Air Saver' ],
    [ 'UPSDAP', 'NextDayAirEarlyAM', 'UPS Next Day Air Early A.M.' ],
    [ 'FedExDefault', 'SMART_POST', 'FedEx SmartPost' ],
    [ 'FedExDefault', 'FEDEX_GROUND', 'FedEx Ground' ],
    [ 'FedExDefault', 'FEDEX_EXPRESS_SAVER', 'FedEx Express Saver' ],
    [ 'FedExDefault', 'FEDEX_2_DAY', 'FedEx 2Day' ],
    [ 'FedExDefault', 'FEDEX_2_DAY_AM', 'FedEx 2Day A.M.' ],
    [ 'FedExDefault', 'STANDARD_OVERNIGHT', 'FedEx Standard Overnight' ],
    [ 'FedExDefault', 'PRIORITY_OVERNIGHT', 'FedEx Priority Overnight' ],
    [ 'FedExDefault', 'FIRST_OVERNIGHT', 'FedEx First Overnight' ],
    [ 'USPS', 'GroundAdvantage', 'USPS Ground Advantage' ],
    [ 'USPS', 'Priority', 'USPS Priority Mail' ],
    [ 'USPS', 'Express', 'USPS Priority Mail Express' ],
    // Not in the maps: still words, and a FedEx code does not repeat the brand.
    [ 'FedExDefault', 'FEDEX_3_DAY_FREIGHT', 'FedEx 3 Day Freight' ],
    [ 'Purolator', 'ExpressEarly', 'Purolator Express Early' ],
];

foreach ( $names as $case ) {
    gate_reset();
    $GLOBALS['gate_responses'][] = gate_canned( 201, gate_shipment( [
        'rates' => [ gate_rate( [ 'carrier' => $case[0], 'service' => $case[1] ] ) ],
    ] ) );
    $quoted = gate_client()->get_rates( gate_payload() );
    gate_same( $case[2], $quoted['quotes'][0]['service_name'], 'the rate reads "' . $case[2] . '"' );
}

// Renaming is display-only: the wire values stay on the quote, because the
// receipt, the order meta and the tracking link read them.
gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 201, gate_shipment( [
    'rates' => [ gate_rate( [ 'carrier' => 'UPSDAP', 'service' => 'Ground' ] ) ],
] ) );
$quoted = gate_client()->get_rates( gate_payload() );
gate_same( 'UPS Ground', $quoted['quotes'][0]['service_name'], 'the rate is named for the shop' );
gate_same( 'UPSDAP', $quoted['quotes'][0]['provider'], 'the raw carrier value is kept' );
gate_same( 'UPSDAP', $quoted['quotes'][0]['carrier'], 'on the carrier key too' );
gate_same( 'Ground', $quoted['quotes'][0]['service'], 'and the raw service value' );

// Why the rename matters: "Best match" compares this name with the WooCommerce
// shipping method through normalize_service_name(), so "UPSDAP Ground" could
// never match an "UPS Ground" method.
gate_same(
    Shipping_Desk_Helpers::normalize_service_name( 'UPS Ground' ),
    Shipping_Desk_Helpers::normalize_service_name( $quoted['quotes'][0]['service_name'] ),
    'an "UPS Ground" method now matches its UPS Ground rate'
);

$body = gate_shipment_body();
gate_same( '504 Circle Dr', $body['to_address']['street1'], 'the recipient street is mapped' );
gate_same( 'Apt 2', $body['to_address']['street2'], 'line2 travels as street2' );
gate_same( 'Wharton', $body['to_address']['city'], 'town becomes city' );
gate_same( 'TX', $body['to_address']['state'], 'county becomes state' );
gate_same( '77488', $body['to_address']['zip'], 'postcode becomes zip' );
gate_same( 'US', $body['to_address']['country'], 'country_code becomes country' );
gate_same( '1 Main St', $body['from_address']['street1'], 'the origin address is mapped' );
gate_same( 12.5, (float) $body['parcel']['weight'], 'weight is sent in ounces' );
gate_same( 6.0, (float) $body['parcel']['length'], 'length is sent in inches' );
gate_same( '1042', $body['reference'], 'the order number travels as the reference' );
gate_same( 'PDF', $body['options']['label_format'], 'the label is bought as PDF' );
gate_same( '4x6', $body['options']['label_size'], 'at 4x6' );
gate_same( '2026-11-11T12:00:00-05:00', $body['options']['label_date'], 'label_date is local noon on the dispatch day' );
gate_check( ! isset( $body['options']['delivery_confirmation'] ), 'no signature option is sent when none was chosen' );

// The order number has to be *printed*, not merely stored. `reference` is
// EasyPost's API handle and never appears on the label; the printed text is an
// option. This is the assertion that keeps the two from being confused again.
gate_same( '1042', $body['options']['print_custom_1'], 'the order number is sent as printed label text' );
gate_check(
    ! isset( $body['options']['print_custom_1_code'] ),
    'no carrier-specific print code is sent: the carrier is not chosen yet'
);

// A long or multi-line order number is flattened and capped here, not left for a
// carrier to reject at buy time.
gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 201, gate_shipment() );
gate_client()->get_rates( gate_payload( [ 'customer_reference' => "WC-123456789012345678901234567890123\n  4567" ] ) );
$printed = gate_shipment_body()['options']['print_custom_1'];
gate_same( 'WC-123456789012345678901234567', $printed, 'a reference is capped at the carrier limit' );
gate_same( 30, strlen( $printed ), 'the cap is the same 30 characters Shippo uses' );
gate_check( false === strpos( $printed, "\n" ), 'a newline in an order number is flattened' );

gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 201, gate_shipment() );
gate_client()->get_rates( gate_payload( [ 'customer_reference' => "  \n  " ] ) );
gate_check( ! isset( gate_shipment_body()['options']['print_custom_1'] ), 'a blank reference prints nothing' );

// A shop may print something other than the order number. print_custom_1
// follows the chosen printed reference; the API handle stays the order number.
gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 201, gate_shipment() );
gate_client()->get_rates( gate_payload( [ 'print_reference' => 'GIFT' ] ) );
$chosen = gate_shipment_body();
gate_same( 'GIFT', $chosen['options']['print_custom_1'], 'print_custom_1 follows the chosen printed reference' );
gate_same( '1042', $chosen['reference'], 'while the API handle stays the order number' );

// Signature mapping — the UI keeps Shippo's enums.
gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 201, gate_shipment() );
gate_client()->get_rates( gate_payload( [ 'signature_confirmation' => 'STANDARD' ] ) );
gate_same( 'SIGNATURE', gate_shipment_body()['options']['delivery_confirmation'], 'STANDARD is translated to SIGNATURE' );

gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 201, gate_shipment() );
gate_client()->get_rates( gate_payload( [ 'signature_confirmation' => 'ADULT' ] ) );
gate_same( 'ADULT_SIGNATURE', gate_shipment_body()['options']['delivery_confirmation'], 'ADULT is translated to ADULT_SIGNATURE' );

gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 201, gate_shipment() );
gate_client()->get_rates( gate_payload( [ 'signature_confirmation' => 'NOPE' ] ) );
gate_check( ! isset( gate_shipment_body()['options']['delivery_confirmation'] ), 'an unknown signature enum is simply not sent' );

// A 2xx with no rates is the failure Shippo taught us to expect.
gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 201, gate_shipment( [
    'rates'    => [],
    'messages' => [ [ 'carrier' => 'USPS', 'message' => 'Address is invalid' ] ],
] ) );
$result = gate_client()->get_rates( gate_payload() );
gate_check( is_wp_error( $result ), 'a 2xx with no rates is an error' );
gate_same( 'easypost_no_rates', $result->get_error_code(), 'the error code names the problem' );
gate_check( false !== strpos( $result->get_error_message(), 'Address is invalid' ), 'the carrier message is carried through' );

gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 201, [ 'rates' => [ gate_rate() ] ] );
$result = gate_client()->get_rates( gate_payload() );
gate_same( 'easypost_no_shipment', $result->get_error_code(), 'a 2xx without a shipment id is an error' );

gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 401, [ 'error' => [ 'code' => 'AUTHENTICATION_FAILURE', 'message' => 'Invalid API key.' ] ] );
$result = gate_client()->get_rates( gate_payload() );
gate_same( 'easypost_auth_error', $result->get_error_code(), 'a bad key is reported as an auth error' );
gate_check( false !== strpos( $result->get_error_message(), 'Invalid API key' ), 'the API message survives' );

// Option rejection: retried once without the option, never fatal.
gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 422, [ 'error' => [ 'code' => 'UNPROCESSABLE_ENTITY', 'message' => 'Invalid option label_date.' ] ] );
$GLOBALS['gate_responses'][] = gate_canned( 201, gate_shipment() );
$result = gate_client()->get_rates( gate_payload( [ 'signature_confirmation' => 'STANDARD' ] ) );
gate_check( ! is_wp_error( $result ), 'a rejected option does not fail the quote' );
gate_same( 2, count( $GLOBALS['gate_requests'] ), 'the rating call is retried exactly once' );
$retry = gate_shipment_body( 1 );
gate_check( ! isset( $retry['options']['label_date'] ), 'the retry drops label_date' );
gate_check( ! isset( $retry['options']['label_size'] ), 'the retry drops label_size too' );
gate_same( 'SIGNATURE', $retry['options']['delivery_confirmation'], 'the signature option is kept' );

// A rejected print option must not cost the dispatch date: that would print the
// wrong postmark day on every label in the batch.
gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 422, [ 'error' => [ 'code' => 'UNPROCESSABLE_ENTITY', 'message' => 'Invalid option print_custom_1.' ] ] );
$GLOBALS['gate_responses'][] = gate_canned( 201, gate_shipment() );
$result = gate_client()->get_rates( gate_payload() );
gate_check( ! is_wp_error( $result ), 'a rejected print option does not fail the quote' );
gate_same( 2, count( $GLOBALS['gate_requests'] ), 'one retry is enough for a print rejection' );
$retry = gate_shipment_body( 1 );
gate_check( ! isset( $retry['options']['print_custom_1'] ), 'the retry drops the printed reference' );
gate_check( isset( $retry['options']['label_date'] ), 'the dispatch date survives' );
gate_check( isset( $retry['options']['label_size'] ), 'and so does label_size' );

gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 422, [ 'error' => [ 'code' => 'UNPROCESSABLE_ENTITY', 'message' => 'Address is invalid.' ] ] );
$result = gate_client()->get_rates( gate_payload() );
gate_check( is_wp_error( $result ), 'an unrelated 422 is an error' );
gate_same( 1, count( $GLOBALS['gate_requests'] ), 'an unrelated 422 is not retried' );

gate_reset();
$result = gate_client( '' )->get_rates( gate_payload() );
gate_same( 'easypost_no_api_key', $result->get_error_code(), 'no key is refused before any request' );
gate_same( 0, count( $GLOBALS['gate_requests'] ), 'no request is paid for without a key' );

gate_reset();
$result = gate_client()->get_rates( gate_payload( [ 'parcels' => [ [] ] ] ) );
gate_same( 'easypost_incomplete_shipment', $result->get_error_code(), 'a shipment without a weight is refused locally' );
gate_same( 0, count( $GLOBALS['gate_requests'] ), 'and nothing is sent' );

/* -- Buying --------------------------------------------------------------- */

gate_section( 'buy' );

gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 201, gate_bought() );
$client = gate_client();
$result = $client->book_shipment( 'shp_123', 'rate_1', [ 'rate_id' => 'rate_1', 'provider' => 'USPS' ], 1042 );

gate_check( ! is_wp_error( $result ), 'a bought shipment is not an error' );
gate_same( 'https://api.easypost.com/v2/shipments/shp_123/buy', $GLOBALS['gate_requests'][0]['url'], 'the buy is POSTed to the shipment' );
gate_same( [ 'id' => 'rate_1' ], gate_body()['rate'], 'the rate travels as an object with its id' );
gate_check( ! isset( gate_body()['insurance'] ), 'no insurance is sent when none was asked for' );

$transaction = $result['successful']['shp_123'];
gate_same( 'shp_123', $transaction['id'], 'the label id is the shipment id' );
gate_same( 'shp_123', $transaction['remote_shipment_id'], 'the remote id is the same shipment' );
gate_same( '9400100000000000000001', $transaction['tracking_number'], 'the tracking code is read' );
gate_same( 'USPS', $transaction['carrier_id'], 'the carrier comes from the selected rate' );
gate_same( 'https://easypost-files.example/label.png', $transaction['labels'][0]['url'], 'the label URL is the PNG' );
gate_same( '8.70', $transaction['total_charge'], 'the cost is postage plus the insurance fee' );
gate_same( 'USD', $transaction['currency'], 'the currency is the rate currency' );
gate_same( '4x6', $transaction['label_size'], 'the bought label is 4x6' );
gate_same( 'UNKNOWN', $transaction['status'], 'the shipment status is upper-cased' );

// Insurance.
gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 201, gate_bought() );
gate_client()->book_shipment( 'shp_123', 'rate_1', [], 1042, [ 'liability_amount' => 249.99 ] );
gate_same( '249.99', gate_body()['insurance'], 'the declared value is sent as the insurance string' );

// The premium is reported on its own, so the order can say what the cover cost.
gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 201, gate_bought() );
$insured = gate_client()->book_shipment( 'shp_123', 'rate_1', [], 1042, [ 'liability_amount' => 249.99 ] );
gate_same( '0.50', $insured['successful']['shp_123']['insurance_fee'], 'the insurance premium is reported separately' );
gate_same( '249.99', $insured['successful']['shp_123']['insurance'], 'the declared value is kept on the transaction' );

// EasyPost's docs say `PostageFee`; some responses say `PostageLabelFee`. Either has
// to count, because an insured buy whose postage fee is unrecognised records only the
// premium as the label's cost - a silently wrong figure in the profit figures.
gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 201, gate_bought( [
	'fees' => [
		[ 'object' => 'Fee', 'type' => 'PostageLabelFee', 'amount' => '8.20000', 'charged' => true ],
		[ 'object' => 'Fee', 'type' => 'InsuranceFee', 'amount' => '0.50000', 'charged' => true ],
	],
] ) );
$old_spelling = gate_client()->book_shipment( 'shp_123', 'rate_1', [], 1042 );
gate_same( '8.70', $old_spelling['successful']['shp_123']['total_charge'], 'the older PostageLabelFee spelling also counts' );

// A fee EasyPost did not charge is not part of the cost.
gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 201, gate_bought( [
	'fees' => [
		[ 'object' => 'Fee', 'type' => 'PostageFee', 'amount' => '8.20000', 'charged' => true ],
		[ 'object' => 'Fee', 'type' => 'InsuranceFee', 'amount' => '0.50000', 'charged' => false ],
	],
] ) );
$uncharged = gate_client()->book_shipment( 'shp_123', 'rate_1', [], 1042 );
gate_same( '8.20', $uncharged['successful']['shp_123']['total_charge'], 'an uncharged insurance fee is excluded from the cost' );
gate_same( '', $uncharged['successful']['shp_123']['insurance_fee'], 'and no premium is reported' );

gate_reset();
$result = gate_client()->book_shipment( 'shp_123', 'rate_1', [], 1042, [ 'liability_amount' => 5000.01 ] );
gate_same( 'easypost_insurance_too_high', $result->get_error_code(), 'insurance above $5,000 is refused locally' );
gate_same( 0, count( $GLOBALS['gate_requests'] ), 'and no label is bought' );

gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 201, gate_bought() );
gate_client()->book_shipment( 'shp_123', 'rate_1', [], 1042, [ 'liability_amount' => '' ] );
gate_check( ! isset( gate_body()['insurance'] ), 'an empty declared value sends no insurance' );

gate_reset();
$result = gate_client()->book_shipment( 'shp_123', '', [], 1042 );
gate_same( 'easypost_missing_rate', $result->get_error_code(), 'a missing rate is refused locally' );
gate_same( 0, count( $GLOBALS['gate_requests'] ), 'and nothing is sent' );

gate_reset();
$result = gate_client()->book_shipment( '', 'rate_1', [], 1042 );
gate_same( 'easypost_missing_shipment', $result->get_error_code(), 'a missing shipment is refused locally' );

// A 2xx without a label is the non-negotiable: never success.
gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 200, gate_bought( [ 'postage_label' => null ] ) );
$result = gate_client()->book_shipment( 'shp_123', 'rate_1', [], 1042 );
gate_same( 'easypost_no_label', $result->get_error_code(), 'a 2xx without a label is an error' );
gate_same( false, $result->get_error_data()['retry_safe'], 'and it is not advertised as safe to retry' );

// A timeout is not a failure: reconcile before saying anything.
gate_reset();
$GLOBALS['gate_responses'][] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 60001 milliseconds' );
$GLOBALS['gate_responses'][] = gate_canned( 200, gate_bought() );
$result = gate_client()->book_shipment( 'shp_123', 'rate_1', [], 1042 );
gate_check( ! is_wp_error( $result ), 'a timeout whose label exists is a success after reconciliation' );
gate_same( 2, count( $GLOBALS['gate_requests'] ), 'the purchase is reconciled with one read' );
gate_same( 'https://api.easypost.com/v2/shipments/shp_123', $GLOBALS['gate_requests'][1]['url'], 'reconciliation reads the shipment' );

gate_reset();
$GLOBALS['gate_responses'][] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
$GLOBALS['gate_responses'][] = gate_canned( 200, gate_bought( [ 'postage_label' => null ] ) );
$result = gate_client()->book_shipment( 'shp_123', 'rate_1', [], 1042 );
gate_same( 'easypost_not_bought', $result->get_error_code(), 'a timeout with no label is reported as not bought' );
gate_same( true, $result->get_error_data()['retry_safe'], 'and only then is a retry called safe' );

gate_reset();
$GLOBALS['gate_responses'][] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
$GLOBALS['gate_responses'][] = gate_canned( 500, [ 'error' => [ 'message' => 'Server error' ] ] );
$result = gate_client()->book_shipment( 'shp_123', 'rate_1', [], 1042 );
gate_same( 'easypost_purchase_unconfirmed', $result->get_error_code(), 'a timeout that cannot be read back is unconfirmed' );
gate_same( false, $result->get_error_data()['retry_safe'], 'and a retry is not encouraged' );

/* -- Reading, refunding, labels ------------------------------------------- */

gate_section( 'read, refund and labels' );

gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 404, [ 'error' => [ 'code' => 'NOT_FOUND', 'message' => 'Not found.' ] ] );
$result = gate_client()->get_shipment( 'shp_missing' );
gate_same( 'easypost_not_found', $result->get_error_code(), 'a 404 becomes a not-found error' );
gate_check( false !== strpos( $result->get_error_message(), 'not found in EasyPost' ), 'with a sentence fit for the admin screen' );

gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 200, gate_bought( [ 'refund_status' => 'refunded' ] ) );
$result = gate_client()->refund_shipment( 'shp_123' );
gate_same( 'easypost_already_refunded', $result->get_error_code(), 'an already refunded label is refused' );
gate_same( 1, count( $GLOBALS['gate_requests'] ), 'and no refund call is made' );

gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 200, gate_bought( [ 'refund_status' => 'submitted' ] ) );
$result = gate_client()->refund_shipment( 'shp_123' );
gate_same( 'easypost_refund_pending', $result->get_error_code(), 'a refund already in flight is reported as pending' );
gate_same( 1, count( $GLOBALS['gate_requests'] ), 'and asking again is not sent' );

gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 200, gate_bought() );
$GLOBALS['gate_responses'][] = gate_canned( 200, gate_bought( [ 'refund_status' => 'submitted' ] ) );
$result = gate_client()->refund_shipment( 'shp_123' );
gate_check( ! is_wp_error( $result ), 'a first refund request succeeds' );
gate_same( 'https://api.easypost.com/v2/shipments/shp_123/refund', $GLOBALS['gate_requests'][1]['url'], 'the refund is POSTed to the shipment' );
gate_same( 'submitted', $result['refund_status'], 'and EasyPost answers as submitted' );

// 429 with Retry-After: waited the requested time, then retried.
gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 200, gate_bought() );
$GLOBALS['gate_responses'][] = gate_canned( 429, [ 'error' => [ 'message' => 'Too many requests' ] ], [ 'retry-after' => '2' ] );
$GLOBALS['gate_responses'][] = gate_canned( 200, gate_bought( [ 'refund_status' => 'submitted' ] ) );
$client = gate_client();
$result = $client->refund_shipment( 'shp_123' );
gate_check( ! is_wp_error( $result ), 'a throttled refund succeeds on the retry' );
gate_same( [ 2 ], $client->pauses, 'the Retry-After value is honoured' );

// A transient 5xx on a read is retried on the same budget, pause and
// Retry-After handling as a 429.
gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 503, [ 'error' => [ 'message' => 'Service unavailable' ] ] );
$GLOBALS['gate_responses'][] = gate_canned( 200, gate_bought() );
$server_error                = gate_client();
$result                      = $server_error->get_shipment( 'shp_123' );
gate_check( ! is_wp_error( $result ), 'a read that fails with a server error is retried' );
gate_same( 2, count( $GLOBALS['gate_requests'] ), 'HTTP 503 triggers one retry on a GET' );
gate_same( [ 1 ], $server_error->pauses, 'the retry waits the backoff delay' );

gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 503, [ 'error' => [ 'message' => 'Service unavailable' ] ] );
$GLOBALS['gate_responses'][] = gate_canned( 503, [ 'error' => [ 'message' => 'Service unavailable' ] ] );
$GLOBALS['gate_responses'][] = gate_canned( 503, [ 'error' => [ 'message' => 'Service unavailable' ] ] );
$result                      = gate_client()->get_shipment( 'shp_123' );
gate_same( 'easypost_error', $result->get_error_code(), 'a server error that never clears is an error' );
gate_same( 3, count( $GLOBALS['gate_requests'] ), 'a GET is attempted three times' );

// A purchase is never repeated on a 5xx: the label may already be bought.
gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 503, [ 'error' => [ 'message' => 'Service unavailable' ] ] );
$result                      = gate_client()->book_shipment( 'shp_123', 'rate_1', [], 1042 );
gate_check( is_wp_error( $result ), 'a server error on a purchase is reported, not hidden' );
gate_same( 1, count( $GLOBALS['gate_requests'] ), 'a spending POST is never retried on a server error' );

gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 200, gate_bought() );
$GLOBALS['gate_responses'][] = gate_canned( 429, [], [] );
$GLOBALS['gate_responses'][] = gate_canned( 429, [], [] );
$GLOBALS['gate_responses'][] = gate_canned( 429, [], [] );
$result = gate_client()->refund_shipment( 'shp_123' );
gate_same( 'easypost_rate_limited', $result->get_error_code(), 'a throttle that never clears is an error after three attempts' );
gate_same( 4, count( $GLOBALS['gate_requests'] ), 'the refund was attempted three times' );

// Labels: PNG for the browser, converted PDF for PrintNode.
gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 200, gate_bought() );
gate_same( 'https://easypost-files.example/label.png', gate_client()->get_label_url( 'shp_123' ), 'get_label_url returns the label URL' );

gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 200, gate_bought( [ 'postage_label' => null ] ) );
$GLOBALS['gate_responses'][] = gate_canned( 200, gate_bought( [ 'postage_label' => null ] ) );
$GLOBALS['gate_responses'][] = gate_canned( 200, gate_bought( [ 'postage_label' => null ] ) );
$GLOBALS['gate_responses'][] = gate_canned( 200, gate_bought( [ 'postage_label' => null ] ) );
$result = gate_client()->get_label_url( 'shp_123' );
gate_same( 'easypost_label_missing', $result->get_error_code(), 'a shipment with no label is not a URL' );
gate_same( 4, count( $GLOBALS['gate_requests'] ), 'the shipment is read four times before the label is called missing' );

gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 200, gate_bought( [ 'postage_label' => [ 'label_url' => 'https://files/label.png', 'label_pdf_url' => 'https://files/label.pdf' ] ] ) );
gate_same( 'https://files/label.pdf', gate_client()->get_label_pdf_url( 'shp_123' ), 'an existing label_pdf_url is used as-is' );
gate_same( 1, count( $GLOBALS['gate_requests'] ), 'without a conversion call' );

gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 200, gate_bought() );
$GLOBALS['gate_responses'][] = gate_canned( 200, gate_bought( [ 'postage_label' => [
    'label_url'     => 'https://files/label.png',
    'label_pdf_url' => 'https://files/label-converted.pdf',
] ] ) );
$result = gate_client()->get_label_pdf_url( 'shp_123' );
gate_same( 'https://files/label-converted.pdf', $result, 'a legacy PNG label is converted and the PDF URL returned' );
gate_check(
    false !== strpos( $GLOBALS['gate_requests'][1]['url'], '/v2/shipments/shp_123/label?file_format=PDF' ),
    'the conversion uses the documented label endpoint'
);

// The normal case now: the bought label is already a PDF, so nothing is converted.
gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 200, gate_bought( [ 'postage_label' => [
    'id'              => 'pl_2',
    'label_url'       => 'https://files/label.pdf',
    'label_pdf_url'   => null,
    'label_size'      => '4x6',
    'label_file_type' => 'application/pdf',
] ] ) );
gate_same( 'https://files/label.pdf', gate_client()->get_label_pdf_url( 'shp_123' ), 'a PDF label is returned as-is' );
gate_same( 1, count( $GLOBALS['gate_requests'] ), 'with a single read and no conversion call' );

gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 200, gate_bought() );
$GLOBALS['gate_responses'][] = gate_canned( 200, gate_bought() );
$result = gate_client()->get_label_pdf_url( 'shp_123' );
gate_same( 'easypost_pdf_missing', $result->get_error_code(), 'a conversion that returns no PDF is an error' );

/* -- Label page crop ------------------------------------------------------ */

gate_section( 'label page crop (4x7 -> 4x6)' );

$client = gate_client();

$original = gate_pdf( '[ 0 0 288 504 ]' );
list( $cropped, $changed ) = $client->crop_for_test( $original );
gate_check( $changed, 'a 4x7 page is cropped' );
gate_check( false !== strpos( $cropped, '/MediaBox [0 72 288 504 ]' ), 'the page box becomes 4x6 (y0 raised 72 pt)' );
gate_same( strlen( $original ), strlen( $cropped ), 'the byte length is preserved, so xref offsets stay valid' );

list( $again, $changed_again ) = $client->crop_for_test( $cropped );
gate_check( ! $changed_again, 'cropping the result again is a no-op' );

$four_by_six = gate_pdf( '[ 0 0 288 432 ]' );
list( $untouched, $changed_432 ) = $client->crop_for_test( $four_by_six );
gate_check( ! $changed_432 && $untouched === $four_by_six, 'a 4x6 page is left alone' );

$letter = gate_pdf( '[ 0 0 612 792 ]' );
list( $letter_out, $changed_letter ) = $client->crop_for_test( $letter );
gate_check( ! $changed_letter && $letter_out === $letter, 'a letter page with no artwork is left alone' );

$four_by_eight = gate_pdf( '[ 0 0 288 576 ]' );
list( $eight, $changed_8 ) = $client->crop_for_test( $four_by_eight );
gate_check( $changed_8 && false !== strpos( $eight, '/MediaBox [0 144 288 576]' ), 'a 4x8 page is cropped by 144 pt' );

$two_pages = gate_pdf( '[ 0 0 288 504 ]', 2 );
list( $multi, $changed_multi ) = $client->crop_for_test( $two_pages );
gate_check( ! $changed_multi && $multi === $two_pages, 'a multi-page PDF is left alone' );

$with_cropbox = str_replace( '/MediaBox [ 0 0 288 504 ]', '/MediaBox [ 0 0 288 504 ] /CropBox [ 0 0 288 504 ]', gate_pdf() );
list( $cropbox_out, $changed_cropbox ) = $client->crop_for_test( $with_cropbox );
gate_check( ! $changed_cropbox, 'a page that already has a CropBox is left alone' );

list( $junk, $changed_junk ) = $client->crop_for_test( 'not a pdf at all' );
gate_check( ! $changed_junk && 'not a pdf at all' === $junk, 'non-PDF bytes are passed through unchanged' );

// FedEx: EasyPost echoes label_size=4X6 and returns a US letter page anyway, with the
// label drawn at true size near the top-left. Measured on the test API: ink spans
// x 41.52..314.40, y 331..754 on a 612x792 page. 272.88x423 pt is a 4x6 label.
$fedex_content = "41.52 331 272.88 423 re\nB\nBT /F1 7 Tf 1 0 0 1 41.52 750 Tm (FROM:) Tj ET\n";
$fedex         = gate_pdf_letter( $fedex_content );
list( $fedex_out, $changed_fedex ) = $client->crop_for_test( $fedex );
gate_check( $changed_fedex, 'a letter page carrying label artwork is cropped' );
gate_check( 1 === preg_match( '#/MediaBox \[34 327 322 759\]#', $fedex_out ), 'the window is centred on the artwork' );
gate_check( strlen( $fedex_out ) > strlen( $fedex ), 'a longer page box forces an xref rebuild' );
gate_check( false !== strpos( $fedex_out, 'startxref' ), 'the rebuilt file still ends in a startxref' );

preg_match( '#/MediaBox \[([^\]]+)\]#', $fedex_out, $box );
$coords = array_map( 'floatval', preg_split( '/\s+/', trim( $box[1] ) ) );
gate_same( 288.0, $coords[2] - $coords[0], 'the window is 4 inches wide' );
gate_same( 432.0, $coords[3] - $coords[1], 'and 6 inches tall' );
gate_check(
    $coords[0] < 41.52 && $coords[1] < 331 && $coords[2] > 314.4 && $coords[3] > 754,
    'with the whole artwork inside it'
);

list( $fedex_again, $changed_twice ) = $client->crop_for_test( $fedex_out );
gate_check( ! $changed_twice, 'the cropped letter page is a no-op the second time' );

// Never clip: artwork wider or taller than the stock leaves the page as it is.
list( $wide_out, $changed_wide ) = $client->crop_for_test( gate_pdf_letter( "20 300 400 400 re\n" ) );
gate_check( ! $changed_wide, 'artwork wider than 4 inches is never clipped' );

// Nor trust a parse that clearly did not find the label.
list( $small_out, $changed_small ) = $client->crop_for_test( gate_pdf_letter( "100 400 50 50 re\n" ) );
gate_check( ! $changed_small, 'artwork far smaller than a label is not trusted' );

/* -- Pacing ---------------------------------------------------------------- */

gate_section( 'pacing' );

// EasyPost publishes no writes-per-second figure — its buy and rate calls sit behind
// an unpublished load-based limiter, and the fixed 5/s applies to Index reads only —
// so both keys keep the same spacing.
gate_same( 350000, gate_client()->spacing_for_test(), 'a test key is paced at 350 ms' );
gate_same( 350000, gate_client( 'EZAK_live_key' )->spacing_for_test(), 'and a live key the same' );

// A 429 slows the whole run down, then successes ease it back.
$paced = gate_client();
$paced->widen_for_test();
gate_same( 700000, $paced->spacing_for_test(), 'a 429 doubles the spacing' );
for ( $i = 0; $i < 10; $i++ ) {
    $paced->widen_for_test();
}
gate_same( 8000000, $paced->spacing_for_test(), 'the spacing is capped at 8 s' );
$paced->ease_for_test();
gate_same( 7200000, $paced->spacing_for_test(), 'a success eases it back' );
gate_same( 350000, gate_client( 'EZAK_live_key' )->spacing_for_test(), 'a fresh client starts at its base again' );

/* -- Bulk ----------------------------------------------------------------- */

gate_section( 'bulk' );

gate_reset();
$result = gate_client()->book_shipments( [] );
gate_same( 'easypost_no_shipments', $result->get_error_code(), 'an empty bulk request is refused' );
gate_same( 0, count( $GLOBALS['gate_requests'] ), 'and nothing is sent' );

gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 201, gate_bought( [ 'id' => 'shp_111' ] ) );
$GLOBALS['gate_responses'][] = gate_canned( 500, [ 'error' => [ 'message' => 'Carrier rejected the label' ] ] );
$client = gate_client();
$result = $client->book_shipments( [
    'shp_111' => [ 'remote_shipment_id' => 'shp_111', 'rate_id' => 'rate_1', 'rate' => [ 'rate_id' => 'rate_1' ], 'custom_messages' => [ '1042' ] ],
    'shp_222' => [ 'remote_shipment_id' => 'shp_222', 'rate_id' => 'rate_2', 'rate' => [ 'rate_id' => 'rate_2' ], 'custom_messages' => [ '1043' ] ],
] );

gate_same( 1, count( $result['successful'] ), 'one label is reported bought' );
gate_check( isset( $result['successful']['shp_111'] ), 'under the shipment id' );
gate_same( 1, count( $result['failed'] ), 'the failure stays confined to its order' );
gate_same( '1043', $result['failed']['shp_222']['customer_reference'], 'the failed order is named by its order id' );
gate_same( false, $result['failed']['shp_222']['retry_safe'], 'a 500 is not advertised as safe to retry' );
gate_same( 1, $client->throttles, 'the second purchase is throttled' );
gate_same( 2, count( $GLOBALS['gate_requests'] ), 'two buy calls, not a batch' );

// A 429 on one purchase has to slow the gap before the *next* one, not just retry
// itself: base 350 ms -> 700 ms on the refusal -> 630 ms once the retry succeeds.
gate_reset();
$GLOBALS['gate_responses'][] = gate_canned( 429, [ 'error' => [ 'message' => 'Too many requests' ] ] );
$GLOBALS['gate_responses'][] = gate_canned( 201, gate_bought( [ 'id' => 'shp_111' ] ) );
$GLOBALS['gate_responses'][] = gate_canned( 201, gate_bought( [ 'id' => 'shp_222' ] ) );
$retried = gate_client();
$result  = $retried->book_shipments( [
    'shp_111' => [ 'remote_shipment_id' => 'shp_111', 'rate_id' => 'rate_1', 'rate' => [ 'rate_id' => 'rate_1' ], 'custom_messages' => [ '1042' ] ],
    'shp_222' => [ 'remote_shipment_id' => 'shp_222', 'rate_id' => 'rate_2', 'rate' => [ 'rate_id' => 'rate_2' ], 'custom_messages' => [ '1043' ] ],
] );
gate_same( 2, count( $result['successful'] ), 'both labels are bought after the retry' );
gate_same( 3, count( $GLOBALS['gate_requests'] ), 'the throttled purchase is retried once' );
gate_same( [ 630000 ], $retried->throttle_values, 'the next purchase waits longer than the base spacing' );

/* -- Dispatch date -------------------------------------------------------- */

gate_section( 'dispatch date' );

$cases = [
    '2026-11-11 15:00:00' => '2026-11-11', // Wednesday, before the cutoff.
    '2026-11-11 16:30:00' => '2026-11-12', // Wednesday, after it.
    '2026-11-13 17:00:00' => '2026-11-16', // Friday evening rolls to Monday.
    '2026-11-14 09:00:00' => '2026-11-16', // Saturday.
    '2026-11-15 09:00:00' => '2026-11-16', // Sunday.
];

foreach ( $cases as $now => $expected ) {
    $client             = gate_client();
    $client->now_string = $now;
    gate_same( $expected, $client->dispatch_day(), 'dispatch day for ' . $now );
}

gate_reset();
$client             = gate_client();
$client->now_string = '2026-11-13 17:00:00';
$GLOBALS['gate_responses'][] = gate_canned( 201, gate_shipment() );
$client->get_rates( gate_payload() );
gate_same(
    '2026-11-16T12:00:00-05:00',
    gate_shipment_body()['options']['label_date'],
    'a Friday-evening quote carries Monday, never a weekend'
);

/* -- Retry rule (structural) ---------------------------------------------- */

gate_section( 'retry rule' );

// The rule lives in one helper and is method-aware, so a later edit cannot
// quietly start repeating a spending POST.
$ep_source = str_replace(
    "\r\n",
    "\n",
    (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-easypost-client.php' )
);

gate_check(
    false !== strpos( $ep_source, 'function should_retry( $method, $code )' ),
    'the retry decision is stated in one should_retry() helper'
);
gate_check(
    false !== strpos( $ep_source, "\$this->should_retry( \$method, \$code )" ),
    'request() decides through that helper, passing the method'
);
gate_check(
    false !== strpos( $ep_source, "'GET' === strtoupper( (string) \$method )" ),
    'the helper only retries a server error for GET, never for POST'
);
gate_check(
    false !== strpos( $ep_source, '$code >= 500' ),
    'the 5xx threshold is >= 500'
);

/* -- Summary -------------------------------------------------------------- */

echo PHP_EOL . 'Checks: ' . $GLOBALS['gate_checks'] . ', failures: ' . $GLOBALS['gate_failures'] . PHP_EOL;
exit( $GLOBALS['gate_failures'] > 0 ? 1 : 0 );