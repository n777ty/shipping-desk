<?php
/**
 * Checkout-side address validation.
 *
 * The other half of the admin address gate: same verifier, same normalised
 * result, same wording. What happens here is an offer to the customer, never a
 * condition of checkout — no checkout error is ever added, there is no
 * submit-time re-validation, and no second paid call. See
 * docs/checkout-validation.md.
 *
 * Server side only for now: the enqueue and the browser UI land separately, so
 * the gate order and the cache can be exercised before anything appears on a
 * page.
 */

class Shipping_Desk_Checkout_Address {

    /** Nonce action for the endpoint. */
    const NONCE = 'shipping_desk_checkout_validation';

    /** How long a verified address is reused before it is checked again. */
    const ADDRESS_BOOK_TTL = 7776000; // 90 days.

    /** Validations one browser session may spend per hour. */
    const SESSION_LIMIT = 15;

    /** Validations one IP may spend per hour, across sessions. */
    const IP_LIMIT = 60;

    /** Seconds in one throttle bucket. */
    const BUCKET_SECONDS = 3600;

    /** @var Shipping_Desk_Plugin */
    protected $plugin;

    /**
     * @param Shipping_Desk_Plugin $plugin The plugin, for options and the verifier.
     */
    public function __construct( Shipping_Desk_Plugin $plugin ) {
        $this->plugin = $plugin;
    }

    /**
     * Register the endpoint and the privacy note.
     *
     * Both the logged-in and anonymous actions: a guest checkout is the normal
     * case, and a nonce alone does not bound what the endpoint can cost — the
     * throttle in validate() is what does that.
     */
    public function init() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
        add_action( 'wp_ajax_shipping_desk_validate_checkout_address', [ $this, 'validate' ] );
        add_action( 'wp_ajax_nopriv_shipping_desk_validate_checkout_address', [ $this, 'validate' ] );
        add_action( 'wp_add_privacy_policy_content', [ $this, 'privacy_policy' ] );
    }

    /**
     * Load the suggestion box on the checkout page, and nowhere else.
     *
     * Three conditions, all of them cheap: this is a checkout, the feature is on,
     * and a verifier actually resolves. A store with the switch on but no key
     * gets no script at all rather than a box that can only fail.
     */
    public function enqueue() {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-received' ) ) {
            return;
        }

        if ( ! $this->plugin->checkout_validation_enabled() ) {
            return;
        }

        if ( null === $this->plugin->validator_client() ) {
            return;
        }

        $version = defined( 'SHIPPING_DESK_VERSION' ) ? SHIPPING_DESK_VERSION : '1.0.0';

        wp_enqueue_style(
            'shipping-desk-checkout',
            plugins_url( '../public/css/checkout-address.css', __FILE__ ),
            [],
            $version
        );

        wp_enqueue_script(
            'shipping-desk-checkout',
            plugins_url( '../public/js/checkout-address.js', __FILE__ ),
            [ 'jquery' ],
            $version,
            true
        );

        wp_localize_script(
            'shipping-desk-checkout',
            'ShippingDeskCheckout',
            [
                'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
                'action'    => 'shipping_desk_validate_checkout_address',
                'nonce'     => wp_create_nonce( self::NONCE ),
                'countries' => [ 'US', 'PR', 'GU', 'VI', 'AS', 'MP' ],
                'messages'  => [
                    'title'     => __( 'We found a more precise version of your address.', 'shipping-desk' ),
                    'original'  => __( 'As you entered', 'shipping-desk' ),
                    'suggested' => __( 'Suggested', 'shipping-desk' ),
                    'accept'    => __( 'Use suggested address', 'shipping-desk' ),
                    'keep'      => __( 'Keep my address as entered', 'shipping-desk' ),
                    'updated'   => __( 'Address updated to the suggested version.', 'shipping-desk' ),
                    'undo'      => __( 'Undo', 'shipping-desk' ),
                    'unconfirmed' => __( 'We couldn\'t confirm this address. Please check the street name and ZIP code.', 'shipping-desk' ),
                ],
            ]
        );
    }

    /**
     * Tell the customer's privacy policy who sees their address before they buy.
     */
    public function privacy_policy() {
        if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
            return;
        }

        $validator = $this->plugin->validator_client();
        if ( null === $validator ) {
            return;
        }

        list( $name ) = $validator;
        $vendor       = 'easypost' === $name ? 'EasyPost' : 'Shippo';

        wp_add_privacy_policy_content(
            __( 'Shipping Desk', 'shipping-desk' ),
            wp_kses_post(
                sprintf(
                    '<p>%s</p>',
                    sprintf(
                        /* translators: %s: name of the address verification service. */
                        __( 'When address suggestions are enabled on checkout, the shipping address a customer enters is sent to %s to check that it is deliverable and to offer a standardised version. Only address fields are sent — never the customer\'s name, email or phone number. A verified address is remembered for up to 90 days so the same address is not checked twice.', 'shipping-desk' ),
                        $vendor
                    )
                )
            )
        );
    }

    /**
     * AJAX: validate one checkout address.
     *
     * Every early exit is cheaper than the one below it, and none of them is an
     * error the customer can see: an address that cannot be checked is simply
     * used as entered.
     */
    public function validate() {
        check_ajax_referer( self::NONCE, 'nonce' );

        if ( ! $this->plugin->checkout_validation_enabled() ) {
            wp_send_json_error( [ 'message' => __( 'Checkout address validation is not enabled.', 'shipping-desk' ) ], 403 );
        }

        $address = $this->posted_address();
        $reason  = self::skip_reason( $address );
        if ( '' !== $reason ) {
            wp_send_json_success( self::skipped( $reason, $address ) );
        }

        $validator = $this->plugin->validator_client();
        if ( null === $validator ) {
            wp_send_json_success( self::skipped( 'no_verifier', $address ) );
        }

        list( $name, $client ) = $validator;

        $key    = self::address_book_key( $address );
        $cached = get_transient( $key );
        if ( is_array( $cached ) && isset( $cached['status'] ) ) {
            wp_send_json_success( self::replay_cached( $cached, $address ) );
        }

        if ( ! $this->spend() ) {
            // Silent on purpose: the customer is never told why the offer is
            // missing, and a throttled visitor is not a person worth blocking.
            wp_send_json_success( self::unavailable() );
        }

        $result             = $client->validate_address( $address );
        $result['original'] = $address;
        $result             = Shipping_Desk_Helpers::describe_address_validation( $result, $name );

        if ( 'unavailable' !== ( isset( $result['status'] ) ? $result['status'] : '' ) ) {
            set_transient( $key, $result, self::address_book_ttl() );
        }

        $result['cached'] = false;
        wp_send_json_success( $result );
    }

    /**
     * Answer a cache hit for the address that is actually being checked.
     *
     * The book stores what a verifier said about a **place**. The verdict and the
     * wording belong to the **question**: "501 east maple avenue" and
     * "501 E MAPLE AVE" are the same entry, but only the first differs from what
     * the verifier matched.
     *
     * Replaying the stored response verbatim made the second caller inherit the
     * first caller's comparison — an entry written from the already-standardised
     * form came back `valid` for the verbose spelling, so the suggestion appeared
     * once and then never again for that address.
     *
     * @param array $cached  Stored result.
     * @param array $address Address as entered now.
     * @return array
     */
    protected static function replay_cached( array $cached, array $address ) {
        $status = isset( $cached['status'] ) ? (string) $cached['status'] : '';

        // invalid and unavailable describe the place rather than the spelling,
        // and neither renders anything at checkout: replay them as they stand.
        if ( ! in_array( $status, [ 'valid', 'corrected' ], true ) ) {
            $cached['cached'] = true;

            return $cached;
        }

        $recommended = isset( $cached['recommended'] ) && is_array( $cached['recommended'] )
            ? $cached['recommended']
            : [];

        $changed = Shipping_Desk_Helpers::address_changes_between( $address, $recommended );

        $result = [
            'status'       => empty( $changed ) ? 'valid' : 'corrected',
            'is_valid'     => true,
            'recommended'  => $recommended,
            'changed'      => $changed,
            'reasons'      => isset( $cached['reasons'] ) && is_array( $cached['reasons'] ) ? $cached['reasons'] : [],
            'address_type' => isset( $cached['address_type'] ) ? (string) $cached['address_type'] : '',
            'confidence'   => [],
            'error'        => '',
            'original'     => $address,
        ];

        $result = Shipping_Desk_Helpers::describe_address_validation(
            $result,
            isset( $cached['validator'] ) ? (string) $cached['validator'] : 'easypost'
        );

        $result['cached'] = true;

        return $result;
    }

    /**
     * The address as posted, in the vocabulary the validator interface takes.
     *
     * Only address fields: name, company, phone and email are not needed to check
     * a delivery point, so they are never collected here and never leave the site.
     *
     * @return array
     */
    protected function posted_address() {
        $address = [];
        foreach ( [ 'line1', 'line2', 'town', 'county', 'postcode' ] as $field ) {
            $address[ $field ] = isset( $_POST[ $field ] )
                ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) )
                : '';
        }

        $address['country_code'] = isset( $_POST['country_code'] )
            ? strtoupper( sanitize_text_field( wp_unslash( $_POST['country_code'] ) ) )
            : '';

        return $address;
    }

    /**
     * Why this address should not be sent, or '' when it should.
     *
     * Public and pure so the gate order can be tested without WordPress, and so
     * it cannot quietly drift from the documented one.
     *
     * @param array $address Plugin-shaped address.
     * @return string Reason slug, or ''.
     */
    public static function skip_reason( array $address ) {
        $field = function ( array $source, $key ) {
            return isset( $source[ $key ] ) && is_scalar( $source[ $key ] )
                ? trim( (string) $source[ $key ] )
                : '';
        };

        if ( ! Shipping_Desk_Helpers::is_supported_destination( $field( $address, 'country_code' ) ) ) {
            return 'country_not_supported';
        }

        if ( '' === $field( $address, 'line1' ) ) {
            return 'not_worth_checking';
        }

        // A street plus a five-digit ZIP. A half-typed ZIP is not a postcode: the
        // request used to fire on a single digit, spending a call and raising a
        // suggestion while the customer was still on the field. City and state
        // alone were accepted before that, which cost a call that had to be paid
        // again the moment the ZIP arrived.
        if ( '' === Shipping_Desk_Helpers::postcode_route_base( $field( $address, 'postcode' ) ) ) {
            return 'not_worth_checking';
        }

        return '';
    }

    /**
     * Cache key for a verified address.
     *
     * Keyed on the normalised address with the postcode reduced to its routing
     * base, so "123 Main St, 77488" and "123 MAIN ST, 77488-1234" share one entry:
     * they are the same place, and the +4 is exactly the refinement the cache is
     * there to remember. The house number is part of the key, so two neighbours on
     * the same street never share a verdict, and so is the suite's position: a
     * verifier that moves a unit between lines costs one extra check, once, which
     * is cheaper than risking two units sharing one answer.
     *
     * @param array $address Plugin-shaped address.
     * @return string
     */
    public static function address_book_key( array $address ) {
        $field = function ( array $source, $key ) {
            return isset( $source[ $key ] ) && is_scalar( $source[ $key ] )
                ? (string) $source[ $key ]
                : '';
        };

        $postcode = $field( $address, 'postcode' );
        $base     = Shipping_Desk_Helpers::postcode_route_base( $postcode );

        return 'shipping_desk_co_addr_' . md5(
            implode(
                '|',
                [
                    Shipping_Desk_Helpers::normalise_address_text( $field( $address, 'line1' ) ),
                    Shipping_Desk_Helpers::normalise_address_text( $field( $address, 'line2' ) ),
                    Shipping_Desk_Helpers::normalise_address_text( $field( $address, 'town' ) ),
                    strtoupper( trim( $field( $address, 'county' ) ) ),
                    '' !== $base ? $base : strtoupper( preg_replace( '/\s+/', '', $postcode ) ),
                    strtoupper( trim( $field( $address, 'country_code' ) ) ),
                ]
            )
        );
    }

    /**
     * How long a verdict is kept, in seconds.
     *
     * A cache, not a record: 90 days captures the repeat traffic, and the filter
     * exists so a store's own retention policy can shorten it without a patch.
     *
     * @return int
     */
    public static function address_book_ttl() {
        $ttl = (int) apply_filters( 'shipping_desk_address_book_ttl', self::ADDRESS_BOOK_TTL );

        return $ttl > 0 ? $ttl : self::ADDRESS_BOOK_TTL;
    }

    /**
     * Nothing to check, and nothing to say.
     *
     * @param string $reason  Slug: country_not_supported, not_worth_checking, no_verifier.
     * @param array  $address Address as entered, for the record.
     * @return array
     */
    public static function skipped( $reason, array $address = [] ) {
        return [
            'status'           => 'skipped',
            'reason'           => (string) $reason,
            'is_valid'         => false,
            'recommended'      => [],
            'changed'          => [],
            'reasons'          => [],
            'address_type'     => '',
            'error'            => '',
            'summary'          => '',
            'original_line'    => Shipping_Desk_Helpers::format_address( $address ),
            'recommended_line' => '',
            'cached'           => false,
        ];
    }

    /**
     * The verifier could not answer, or was not asked to.
     *
     * @param string $error Why, for whoever is reading the response.
     * @return array
     */
    public static function unavailable( $error = '' ) {
        return [
            'status'           => 'unavailable',
            'reason'           => 'throttled',
            'is_valid'         => false,
            'recommended'      => [],
            'changed'          => [],
            'reasons'          => [],
            'address_type'     => '',
            'error'            => (string) $error,
            'summary'          => '',
            'original_line'    => '',
            'recommended_line' => '',
            'cached'           => false,
        ];
    }

    /**
     * Charge one validation against this session and this IP.
     *
     * @return bool False when either budget is already spent.
     */
    protected function spend() {
        if ( $this->spent( 'session' ) >= self::SESSION_LIMIT ) {
            return false;
        }
        if ( $this->spent( 'ip' ) >= self::IP_LIMIT ) {
            return false;
        }

        $this->count_up( 'session' );
        $this->count_up( 'ip' );

        return true;
    }

    /**
     * Current throttle window.
     *
     * @return int
     */
    protected function throttle_bucket() {
        return (int) floor( time() / self::BUCKET_SECONDS );
    }

    /**
     * The WooCommerce session, when there is one.
     *
     * @return object|null
     */
    protected function session() {
        if ( ! function_exists( 'WC' ) ) {
            return null;
        }

        $wc = WC();

        return ( is_object( $wc ) && isset( $wc->session ) && $wc->session ) ? $wc->session : null;
    }

    /**
     * How many validations this scope has already spent in the current window.
     *
     * @param string $scope 'session' or 'ip'.
     * @return int
     */
    protected function spent( $scope ) {
        if ( 'session' === $scope ) {
            $session = $this->session();
            if ( null === $session ) {
                return 0;
            }

            $data = $session->get( 'shipping_desk_co_throttle' );
            if ( ! is_array( $data ) || ! isset( $data['bucket'], $data['count'] ) ) {
                return 0;
            }

            return (int) $data['bucket'] === $this->throttle_bucket() ? (int) $data['count'] : 0;
        }

        return (int) get_transient( $this->ip_throttle_key() );
    }

    /**
     * Add one to a scope's count for the current window.
     *
     * @param string $scope 'session' or 'ip'.
     * @return void
     */
    protected function count_up( $scope ) {
        if ( 'session' === $scope ) {
            $session = $this->session();
            if ( null === $session ) {
                return;
            }

            $session->set(
                'shipping_desk_co_throttle',
                [
                    'bucket' => $this->throttle_bucket(),
                    'count'  => $this->spent( 'session' ) + 1,
                ]
            );

            return;
        }

        set_transient( $this->ip_throttle_key(), $this->spent( 'ip' ) + 1, self::BUCKET_SECONDS );
    }

    /**
     * Throttle key for the visitor's IP in the current window.
     *
     * Proxies are not unwound: a store behind one sees the proxy's address for
     * every visitor, so IP_LIMIT throttles the whole shop rather than one abuser.
     * That is the safe direction for a cost guard.
     *
     * @return string
     */
    protected function ip_throttle_key() {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

        return 'shipping_desk_co_ip_' . md5( $ip . '|' . $this->throttle_bucket() );
    }
}
