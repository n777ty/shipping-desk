<?php
/**
 * Gate for Shipping_Desk_EasyPost_Client.
 *
 * Standalone: no Composer, no PHPUnit, no WordPress. Stubs the WordPress HTTP
 * functions, then checks the request shape and every verdict EasyPost can return:
 *
 *   - the request carries address[street1], both verify[] entries and Basic auth
 *   - a corrected address is reported as corrected, with the changed fields
 *   - a case-only difference is NOT a correction (EasyPost answers in upper case)
 *   - residential / commercial map onto the Shippo vocabulary
 *   - a failed delivery verification is invalid, with reasons and suggestions
 *   - a strict 422 is invalid, not an outage
 *   - a 401 / 500 / unreadable body / missing street / missing key is unavailable
 *
 * Run:  php tests/easypost-client-test.php
 * Exit: 0 when every check passes, 1 otherwise.
 */

error_reporting( E_ALL );

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {

        protected $errors = [];

        public function __construct( $code = '', $message = '', $data = '' ) {
            if ( '' === $code ) {
                return;
            }
            $this->errors[ $code ][] = $message;
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

if ( ! function_exists( 'wp_remote_post' ) ) {
    function wp_remote_post( $url, $args = [] ) {
        $GLOBALS['ep_requests'][] = [ 'url' => $url, 'args' => $args ];
        if ( empty( $GLOBALS['ep_responses'] ) ) {
            return new WP_Error( 'http_request_failed', 'No canned response queued for ' . $url );
        }
        return array_shift( $GLOBALS['ep_responses'] );
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

require_once dirname( __DIR__ ) . '/includes/class-address-validator-interface.php';
require_once dirname( __DIR__ ) . '/includes/class-easypost-client.php';

/* -- Harness -------------------------------------------------------------- */

$GLOBALS['ep_checks']   = 0;
$GLOBALS['ep_failures'] = 0;

function ep_check( $condition, $label ) {
    $GLOBALS['ep_checks']++;
    if ( $condition ) {
        echo '  ok    ' . $label . PHP_EOL;
        return true;
    }
    $GLOBALS['ep_failures']++;
    echo '  FAIL  ' . $label . PHP_EOL;
    return false;
}

function ep_same( $expected, $actual, $label ) {
    if ( $expected === $actual ) {
        return ep_check( true, $label );
    }
    return ep_check(
        false,
        $label . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')'
    );
}

function ep_section( $title ) {
    echo PHP_EOL . '[' . $title . ']' . PHP_EOL;
}

function ep_canned( $code, $body ) {
    return [
        'response' => [ 'code' => $code ],
        'body'     => is_string( $body ) ? $body : json_encode( $body ),
    ];
}

function ep_reset() {
    $GLOBALS['ep_responses'] = [];
    $GLOBALS['ep_requests']  = [];
}

function ep_client( $key = 'EZTK_test_key' ) {
    return new Shipping_Desk_EasyPost_Client( $key );
}

function ep_address( array $overrides = [] ) {
    return array_merge(
        [
            'name'         => 'Ada Lovelace',
            'phone'        => '4151234567',
            'email'        => 'ada@example.com',
            'line1'        => '504 Circle Dr.',
            'town'         => 'Wharton',
            'postcode'     => '77488',
            'country_code' => 'US',
            'county'       => 'TX',
        ],
        $overrides
    );
}

/** A verified address object as EasyPost returns it: upper case, ZIP+4 added. */
function ep_verified( array $overrides = [] ) {
    return array_merge(
        [
            'id'            => 'adr_123',
            'object'        => 'Address',
            'mode'          => 'test',
            'street1'       => '504 CIRCLE DR',
            'street2'       => '',
            'city'          => 'WHARTON',
            'state'         => 'TX',
            'zip'           => '77488-1234',
            'country'       => 'US',
            'residential'   => true,
            'verifications' => [
                'zip4'     => [ 'success' => true, 'errors' => [], 'details' => null ],
                'delivery' => [
                    'success' => true,
                    'errors'  => [],
                    'details' => [ 'latitude' => 29.3, 'longitude' => -96.07, 'time_zone' => 'America/Chicago' ],
                ],
            ],
        ],
        $overrides
    );
}

function ep_body() {
    if ( ! isset( $GLOBALS['ep_requests'][0] ) ) {
        return '';
    }
    return (string) $GLOBALS['ep_requests'][0]['args']['body'];
}

echo 'EasyPost client gate' . PHP_EOL;
echo '====================' . PHP_EOL;

/* -- Request shape -------------------------------------------------------- */

ep_section( 'request' );

ep_reset();
$GLOBALS['ep_responses'][] = ep_canned( 200, ep_verified() );
ep_client( 'EZTK_abc123' )->validate_address( ep_address() );

ep_same( 1, count( $GLOBALS['ep_requests'] ), 'one request per verification' );
ep_same( 'https://api.easypost.com/v2/addresses', $GLOBALS['ep_requests'][0]['url'], 'posts to the addresses endpoint' );
ep_same(
    'Basic ' . base64_encode( 'EZTK_abc123:' ),
    $GLOBALS['ep_requests'][0]['args']['headers']['Authorization'],
    'authenticates with the key as the Basic username'
);
ep_check( false !== strpos( ep_body(), 'address[street1]=504%20Circle%20Dr.' ), 'sends the street as address[street1]' );
ep_check( false !== strpos( ep_body(), 'verify[]=delivery' ), 'requests delivery verification' );
ep_check( false !== strpos( ep_body(), 'verify[]=zip4' ), 'requests zip4 verification' );
ep_check( false !== strpos( ep_body(), 'address[country]=US' ), 'defaults the country to US' );
ep_check( false !== strpos( ep_body(), 'address[zip]=77488' ), 'sends the postcode as address[zip]' );
ep_check( false === strpos( ep_body(), 'verify_strict' ), 'does not ask EasyPost to enforce strict mode' );

/* -- Verdicts ------------------------------------------------------------- */

ep_section( 'corrected address' );

ep_reset();
$GLOBALS['ep_responses'][] = ep_canned( 200, ep_verified() );
$corrected                 = ep_client()->validate_address( ep_address() );

ep_same( 'corrected', $corrected['status'], 'a standardised address is reported as corrected' );
ep_same( true, $corrected['is_valid'], 'a correction still counts as valid' );
ep_same( '77488-1234', $corrected['recommended']['postcode'], 'keeps the ZIP+4 EasyPost returned' );
ep_same( '504 CIRCLE DR', $corrected['recommended']['line1'], 'keeps the standardised street' );
ep_same( 'residential', $corrected['address_type'], 'maps residential to the plugin vocabulary' );
ep_check( in_array( 'postal_code', $corrected['changed'], true ), 'reports the ZIP change' );
ep_check( in_array( 'address_line_1', $corrected['changed'], true ), 'reports the street change' );
ep_same( '', $corrected['error'], 'a successful check carries no error' );

ep_section( 'matched but unchanged' );

ep_reset();
$GLOBALS['ep_responses'][] = ep_canned(
    200,
    ep_verified( [ 'street1' => '504 Circle Dr.', 'city' => 'Wharton', 'zip' => '77488' ] )
);
$matched = ep_client()->validate_address( ep_address() );

ep_same( 'valid', $matched['status'], 'an unchanged address is valid' );
ep_same( [], $matched['changed'], 'no changes are reported' );

ep_section( 'case only' );

ep_reset();
$GLOBALS['ep_responses'][] = ep_canned(
    200,
    ep_verified( [ 'street1' => '504 CIRCLE DR.', 'city' => 'WHARTON', 'zip' => '77488' ] )
);
$case_only = ep_client()->validate_address( ep_address() );
ep_same( 'valid', $case_only['status'], 'a case-only difference is not a correction' );

ep_section( 'address type' );

ep_reset();
$GLOBALS['ep_responses'][] = ep_canned( 200, ep_verified( [ 'residential' => false ] ) );
ep_same( 'commercial', ep_client()->validate_address( ep_address() )['address_type'], 'maps false to commercial' );

ep_reset();
$GLOBALS['ep_responses'][] = ep_canned( 200, ep_verified( [ 'residential' => null ] ) );
ep_same( '', ep_client()->validate_address( ep_address() )['address_type'], 'an unknown type stays empty' );

/* -- Failures ------------------------------------------------------------- */

ep_section( 'not deliverable' );

ep_reset();
$GLOBALS['ep_responses'][] = ep_canned(
    200,
    ep_verified(
        [
            'verifications' => [
                'delivery' => [
                    'success' => false,
                    'errors'  => [
                        [ 'code' => 'E.ADDRESS.NOT_FOUND', 'field' => 'address', 'message' => 'Address not found', 'suggestion' => null ],
                    ],
                    'details' => null,
                ],
            ],
        ]
    )
);
$invalid = ep_client()->validate_address( ep_address() );

ep_same( 'invalid', $invalid['status'], 'a failed delivery verification is invalid' );
ep_same( false, $invalid['is_valid'], 'and does not pass' );
ep_same( [], $invalid['recommended'], 'with nothing to apply' );
ep_same( 'E.ADDRESS.NOT_FOUND', $invalid['reasons'][0]['code'], 'carries the EasyPost error code' );

ep_reset();
$GLOBALS['ep_responses'][] = ep_canned(
    200,
    ep_verified(
        [
            'verifications' => [
                'delivery' => [
                    'success' => false,
                    'errors'  => [
                        [ 'code' => 'E.ADDRESS.INVALID', 'field' => 'zip', 'message' => 'Invalid ZIP for city and state', 'suggestion' => '77489' ],
                    ],
                    'details' => null,
                ],
            ],
        ]
    )
);
$suggested = ep_client()->validate_address( ep_address() );
ep_check(
    false !== strpos( $suggested['reasons'][0]['description'], '77489' ),
    'includes the suggested value in the reason'
);

ep_section( 'strict 422' );

ep_reset();
$GLOBALS['ep_responses'][] = ep_canned(
    422,
    [
        'error' => [
            'code'    => 'ADDRESS.VERIFY.FAILURE',
            'message' => 'Unable to verify address.',
            'errors'  => [
                [ 'code' => 'E.ADDRESS.NOT_FOUND', 'field' => 'address', 'message' => 'Address not found', 'suggestion' => null ],
            ],
        ],
    ]
);
$strict = ep_client()->validate_address( ep_address() );
ep_same( 'invalid', $strict['status'], 'a strict verification failure is invalid, not an outage' );
ep_same( 'E.ADDRESS.NOT_FOUND', $strict['reasons'][0]['code'], 'keeps its field errors' );

/* -- Fail-open ------------------------------------------------------------ */

ep_section( 'outages' );

ep_reset();
$GLOBALS['ep_responses'][] = ep_canned( 401, [ 'error' => [ 'code' => 'UNAUTHORIZED', 'message' => 'Invalid API key.' ] ] );
$unauthorised              = ep_client()->validate_address( ep_address() );
ep_same( 'unavailable', $unauthorised['status'], 'a bad key leaves the address unverified' );
ep_check( '' !== $unauthorised['error'], 'and says why' );
ep_same( false, $unauthorised['is_valid'], 'never claims validity without an answer' );

ep_reset();
$GLOBALS['ep_responses'][] = ep_canned( 500, [ 'error' => [ 'message' => 'Internal error' ] ] );
ep_same( 'unavailable', ep_client()->validate_address( ep_address() )['status'], 'a 500 is unavailable' );

ep_reset();
$GLOBALS['ep_responses'][] = ep_canned( 200, '<html>not json</html>' );
ep_same( 'unavailable', ep_client()->validate_address( ep_address() )['status'], 'unreadable JSON is unavailable' );

ep_reset();
$GLOBALS['ep_responses'][] = ep_canned( 200, ep_verified( [ 'verifications' => [] ] ) );
$no_verifications          = ep_client()->validate_address( ep_address() );
ep_same( 'unavailable', $no_verifications['status'], 'no verification block is an outage, not a pass' );
ep_check( '' !== $no_verifications['error'], 'and says so' );

ep_section( 'nothing to send' );

ep_reset();
$no_street = ep_client()->validate_address( [ 'line1' => '', 'postcode' => '77488' ] );
ep_same( 0, count( $GLOBALS['ep_requests'] ), 'does not call EasyPost without a street' );
ep_same( 'unavailable', $no_street['status'], 'reports it as unavailable' );

ep_reset();
$no_key = ep_client( '' )->validate_address( ep_address() );
ep_same( 0, count( $GLOBALS['ep_requests'] ), 'does not call EasyPost without a key' );
ep_same( 'unavailable', $no_key['status'], 'reports the missing key' );

/* -- Print immediately after buying ----------------------------------------- */

ep_section( 'label available shortly after the buy' );

/**
 * Client whose shipment read is canned, so the retry can be observed without HTTP.
 */
class EasyPost_Label_Race_Stub extends Shipping_Desk_EasyPost_Client {

	public $queue = [];
	public $reads = 0;

	public function __construct( array $queue ) {
		parent::__construct( 'EZTK_test_key' );
		$this->queue = $queue;
	}

	public function get_shipment( $id ) {
		$this->reads++;
		return empty( $this->queue ) ? [] : array_shift( $this->queue );
	}
}

$ep_labelled = [ 'postage_label' => [ 'label_url' => 'https://example.test/label.pdf' ] ];

// EasyPost assembles the PDF just after the buy: one empty read, then the label.
$ep_race  = new EasyPost_Label_Race_Stub( [ [], $ep_labelled ] );
$ep_start = microtime( true );
$ep_url   = $ep_race->get_label_url( 'shp_test' );
$ep_wait  = microtime( true ) - $ep_start;

ep_same( 'https://example.test/label.pdf', $ep_url, 'a label that appears on the second read is returned' );
ep_same( 2, $ep_race->reads, 'the shipment was read twice rather than once' );
ep_check( $ep_wait >= 0.7, 'and the retry waited before asking again' );

$ep_ready = new EasyPost_Label_Race_Stub( [ $ep_labelled ] );
ep_same( 'https://example.test/label.pdf', $ep_ready->get_label_url( 'shp_test' ), 'a label already present is returned first time' );
ep_same( 1, $ep_ready->reads, 'with no retry' );

$ep_absent = new EasyPost_Label_Race_Stub( [ [], [], [] ] );
$ep_error  = $ep_absent->get_label_url( 'shp_test' );

ep_check( $ep_error instanceof WP_Error, 'no label after three reads is an error' );
ep_same( 3, $ep_absent->reads, 'after three reads' );
ep_check(
	false === strpos( $ep_error->get_error_message(), 'Buy the label again' ),
	'the message never tells the operator to buy again - the label exists and a second buy is charged'
);
ep_check(
	false !== strpos( $ep_error->get_error_message(), 'do not buy another' ),
	'and says so plainly'
);

/* -- Result --------------------------------------------------------------- */

echo PHP_EOL . 'Checks: ' . $GLOBALS['ep_checks'] . ', failures: ' . $GLOBALS['ep_failures'] . PHP_EOL;
exit( 0 === $GLOBALS['ep_failures'] ? 0 : 1 );


