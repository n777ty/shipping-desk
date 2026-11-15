<?php
/**
 * Gate for the Veeqo rate request's handling of a second address line.
 *
 * Standalone: no Composer, no PHPUnit, no WordPress. Stubs the handful of
 * WordPress functions the client calls, then drives get_rates() through every
 * answer Veeqo could give about `line2`:
 *
 *   - to_address.line2 is documented, so it is always sent
 *   - from_address.line2 is not documented, so it is dropped
 *   - a validation error retries once without line2, because a wire field must
 *     never be able to break rate shopping on its own
 *   - an auth or server failure is NOT retried (nothing to do with the field)
 *
 * Run:  php tests/shipping-desk-client-rate-test.php
 * Exit: 0 when every check passes, 1 otherwise.
 */

error_reporting( E_ALL );

/* -- WordPress stubs ------------------------------------------------------ */

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {

        protected $errors = [];

        public function __construct( $code = '', $message = '', $data = '' ) {
            if ( '' === $code ) {
                return;
            }
            $this->errors[ $code ][] = $message;
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
        return array_key_exists( $key, $GLOBALS['vq_options'] ) ? $GLOBALS['vq_options'][ $key ] : $default;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( $key, $value, $autoload = null ) {
        $GLOBALS['vq_options'][ $key ]  = $value;
        $GLOBALS['vq_autoload'][ $key ] = $autoload;
        return true;
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data ) {
        return json_encode( $data );
    }
}

if ( ! function_exists( 'wp_remote_post' ) ) {
    function wp_remote_post( $url, $args = [] ) {
        $GLOBALS['vq_requests'][] = [ 'url' => $url, 'args' => $args ];
        if ( empty( $GLOBALS['vq_responses'] ) ) {
            return new WP_Error( 'http_request_failed', 'No canned response queued for ' . $url );
        }
        return array_shift( $GLOBALS['vq_responses'] );
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

require_once dirname( __DIR__ ) . '/includes/class-provider-interface.php';
require_once dirname( __DIR__ ) . '/includes/class-api-client.php';


/* -- Harness -------------------------------------------------------------- */

$GLOBALS['vq_checks']   = 0;
$GLOBALS['vq_failures'] = 0;

function vq_check( $condition, $label ) {
    $GLOBALS['vq_checks']++;
    if ( $condition ) {
        echo '  ok    ' . $label . PHP_EOL;
        return true;
    }
    $GLOBALS['vq_failures']++;
    echo '  FAIL  ' . $label . PHP_EOL;
    return false;
}

function vq_same( $expected, $actual, $label ) {
    if ( $expected === $actual ) {
        return vq_check( true, $label );
    }
    return vq_check(
        false,
        $label . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')'
    );
}

function vq_section( $title ) {
    echo PHP_EOL . '[' . $title . ']' . PHP_EOL;
}

function vq_canned( $code, $body ) {
    return [
        'response' => [ 'code' => $code ],
        'body'     => is_string( $body ) ? $body : json_encode( $body ),
    ];
}

/**
 * Reset canned responses and recorded requests.
 */
function vq_reset() {
    $GLOBALS['vq_responses'] = [];
    $GLOBALS['vq_requests']  = [];
}

function vq_client() {
    return new Shipping_Desk_Veeqo_Client( 'vq_test_key' );
}

function vq_payload( $line2 = 'Suite 200' ) {
    return [
        'to_address'   => [
            'name'         => 'Ada Lovelace',
            'phone'        => '4151234567',
            'email'        => 'ada@example.com',
            'line1'        => '965 Mission St',
            'line2'        => $line2,
            'town'         => 'San Francisco',
            'postcode'     => '94103',
            'country_code' => 'US',
            'county'       => 'CA',
        ],
        'from_address' => [
            'name'         => 'Warehouse',
            'line1'        => '1 Depot Way',
            'town'         => 'Chicago',
            'postcode'     => '60601',
            'country_code' => 'US',
            'county'       => 'IL',
        ],
        'parcels'      => [
            [ 'weight' => 12.5, 'weight_unit' => 'oz', 'height' => 4, 'width' => 6, 'length' => 8, 'dimension_unit' => 'in' ],
        ],
    ];
}

function vq_rates_response() {
    return [ 'quotes' => [ [ 'rate_id' => 'amazon_shipping_v2-abc', 'service_name' => 'UPS Ground' ] ] ];
}

function vq_body( $index = 0 ) {
    if ( ! isset( $GLOBALS['vq_requests'][ $index ] ) ) {
        return null;
    }
    return json_decode( $GLOBALS['vq_requests'][ $index ]['args']['body'], true );
}

echo 'Veeqo rate request gate' . PHP_EOL;
echo '=======================' . PHP_EOL;

/* Documented field: always sent for the recipient. */
vq_section( 'to_address.line2' );

vq_reset();
$GLOBALS['vq_responses'][] = vq_canned( 200, vq_rates_response() );
$rates                      = vq_client()->get_rates( vq_payload() );
vq_check( ! is_wp_error( $rates ), 'rates come back with line2 sent' );
vq_same( 1, count( $GLOBALS['vq_requests'] ), 'one request when Veeqo accepts the field' );
vq_same( 'Suite 200', vq_body( 0 )['to_address']['line2'], 'sends the recipient second line' );
vq_same(
    'https://api.veeqo.com/shipping/api/v1/rates',
    $GLOBALS['vq_requests'][0]['url'],
    'posts to the documented rates endpoint'
);

/* The sender address: no second line in the documented shape. */
vq_section( 'from_address.line2' );

vq_reset();
$GLOBALS['vq_responses'][]        = vq_canned( 200, vq_rates_response() );
$with_sender_line2                = vq_payload();
$with_sender_line2['from_address']['line2'] = 'Dock 4';
vq_client()->get_rates( $with_sender_line2 );
vq_check( ! isset( vq_body( 0 )['from_address']['line2'] ), 'drops a second line from the sender address' );
vq_same( 'Suite 200', vq_body( 0 )['to_address']['line2'], 'and keeps the recipient one' );

/* Nothing to send. */
vq_section( 'no second line' );

vq_reset();
$GLOBALS['vq_responses'][] = vq_canned( 200, vq_rates_response() );
vq_client()->get_rates( vq_payload( '' ) );
vq_check( ! isset( vq_body( 0 )['to_address']['line2'] ), 'an empty second line is left out entirely' );

vq_reset();
$GLOBALS['vq_responses'][] = vq_canned( 200, vq_rates_response() );
vq_client()->get_rates( vq_payload( '   ' ) );
vq_check( ! isset( vq_body( 0 )['to_address']['line2'] ), 'whitespace is not sent as a second line' );

/* Safety net: a validation error must not take rating down on its own. */
vq_section( 'validation errors' );

vq_reset();
$GLOBALS['vq_responses'][] = vq_canned( 400, [ 'error_messages' => [ 'Invalid request parameters' ] ] );
$GLOBALS['vq_responses'][] = vq_canned( 200, vq_rates_response() );
$recovered                 = vq_client()->get_rates( vq_payload() );
vq_check( ! is_wp_error( $recovered ), 'a 400 does not fail the rate request outright' );
vq_same( 2, count( $GLOBALS['vq_requests'] ), 'retries exactly once' );
vq_same( 'Suite 200', vq_body( 0 )['to_address']['line2'], 'the first attempt carries the second line' );
vq_check( ! isset( vq_body( 1 )['to_address']['line2'] ), 'the retry drops it' );
vq_same( 'amazon_shipping_v2-abc', $recovered['quotes'][0]['rate_id'], 'returns the rates from the retry' );

vq_reset();
$GLOBALS['vq_responses'][] = vq_canned( 422, [ 'error_messages' => [ 'Invalid request parameters' ] ] );
$GLOBALS['vq_responses'][] = vq_canned( 200, vq_rates_response() );
vq_check( ! is_wp_error( vq_client()->get_rates( vq_payload() ) ), 'a 422 is handled the same way' );

/* A failure that is not about line2 still surfaces after the one fallback. */
vq_reset();
$GLOBALS['vq_responses'][] = vq_canned( 400, [ 'error_messages' => [ 'Invalid request parameters' ] ] );
$GLOBALS['vq_responses'][] = vq_canned( 400, [ 'error_messages' => [ 'Invalid request parameters' ] ] );
$still_bad                  = vq_client()->get_rates( vq_payload() );
vq_check( is_wp_error( $still_bad ), 'a real validation error is still reported' );
vq_check( false !== strpos( $still_bad->get_error_message(), '400' ), 'and names the status' );

/* Failures that have nothing to do with the field are not retried. */
vq_section( 'other failures' );

vq_reset();
$GLOBALS['vq_responses'][] = vq_canned( 401, [ 'error_messages' => [ 'Unauthorised' ] ] );
$auth                      = vq_client()->get_rates( vq_payload() );
vq_check( is_wp_error( $auth ), 'an auth failure is reported, not papered over' );
vq_same( 1, count( $GLOBALS['vq_requests'] ), 'and not retried' );

vq_reset();
$GLOBALS['vq_responses'][] = vq_canned( 500, [ 'error_messages' => [ 'Server error' ] ] );
$failed                    = vq_client()->get_rates( vq_payload() );
vq_check( is_wp_error( $failed ), 'a 500 is still an error' );
vq_same( 1, count( $GLOBALS['vq_requests'] ), 'and not retried either' );
vq_check( false !== strpos( $failed->get_error_message(), '500' ), 'names the status in the message' );

/* Unchanged behaviour the shared payload depends on. */
vq_section( 'shared payload' );

vq_reset();
$GLOBALS['vq_responses'][] = vq_canned( 200, vq_rates_response() );
vq_client()->get_rates( vq_payload() );
vq_check( ! isset( vq_body( 0 )['to_address']['email'] ), 'still strips the email Veeqo never carried' );

/* -- Result --------------------------------------------------------------- */

echo PHP_EOL . 'Checks: ' . $GLOBALS['vq_checks'] . ', failures: ' . $GLOBALS['vq_failures'] . PHP_EOL;
exit( 0 === $GLOBALS['vq_failures'] ? 0 : 1 );
