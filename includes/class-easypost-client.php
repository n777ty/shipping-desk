<?php
/**
 * EasyPost API client — address verifier and third label provider.
 *
 * One class, two contracts: EasyPost verifies addresses *and* buys labels, so it
 * implements both Shipping_Desk_Provider_Interface and the optional validator
 * interface. Raw wp_remote_* throughout — no SDK, no Composer.
 *
 * Label provider behaviour this client enforces (see docs/easypost-provider.md):
 *
 *  - Auth is **Basic** with the key as username and an empty password. The key
 *    carries the mode: `EZTK…` is test, `EZAK…` spends real money.
 *  - There is no `async` flag to forget, but a 2xx is still not proof of a
 *    purchase: a bought shipment always carries `postage_label`, and anything
 *    else is reported as an error rather than as a success with no label.
 *  - The label is bought as **PDF 4x6**. Measured on the live test API: USPS returns a
 *    true 4.00 x 6.00 in PDF, and UPS returns 4.00 x 7.00 in — which EasyPost documents
 *    as a 4x6 label plus 1 inch of blank space (4x8 is 2 inches). Both print correctly
 *    on 4x6 stock at actual size, and PrintNode receives a real PDF with no conversion.
 *  - The conversion endpoint is **not** a 4x6 producer: it wraps the PNG in an
 *    11.00 x 8.50 in letter page, so it survives only as a fallback for a legacy PNG
 *    label, never as the normal print path.
 *  - Insurance is EasyPost's own: `insurance` on the buy call, USD, maximum
 *    $5,000. It is priced at purchase, not at quote, so EasyPost rate totals are
 *    postage only and the real cost is summed from `fees[]` after the buy. There
 *    is no XCover here, and no standalone `/v2/insurances` call — that endpoint
 *    exists for shipments bought outside EasyPost.
 *  - Signature confirmation is a shipment option: `STANDARD` → `SIGNATURE`,
 *    `ADULT` → `ADULT_SIGNATURE`. The plugin keeps Shippo's vocabulary in the UI
 *    and this client translates it, so no provider's enums leak outwards.
 *  - Shipment option `label_date` carries the 4pm Eastern dispatch day, so a
 *    quote priced for one day cannot be bought on another. If EasyPost rejects
 *    that option the rating call is retried once without it rather than failing
 *    the whole quote — the same fallback discipline Shippo's `shipment_date`
 *    needed.
 *  - Refunds are adjudicated asynchronously: `refund_status` moves
 *    `submitted` → `refunded`, so a second request is refused the way a Shippo
 *    `REFUNDPENDING` transaction is. Insurance bought with the label is not
 *    separately refundable.
 *  - A timeout is **not** a failure. The purchase is reconciled through
 *    `GET /v2/shipments/{id}` before a retry is reported as safe.
 *  - HTTP 429 is retried with backoff honouring `Retry-After`, and bulk
 *    purchases are throttled into a loop (the Batch API is asynchronous and the
 *    admin UI has no state for "purchased later").
 *
 * Identifier mapping (Veeqo concept → EasyPost concept):
 *
 *   remote_shipment_id → shipment `id` (`shp_…`)
 *   label_id           → the shipment `id` as well: buying, labelling and
 *                        refunding are all addressed by the shipment, and the
 *                        PostageLabel id (`pl_…`) is not actionable.
 *
 * Address verification is the original job of this class and is unchanged:
 * optional, advisory, and it never returns WP_Error — an outage answers
 * `unavailable` and the address is used as entered.
 *
 * Why a second verifier when Shippo has one:
 *
 *  - Shippo's V2 validator answers `valid` for an address it merely *matched*, and
 *    returns no `recommended_address` when the differences are cosmetic. A label
 *    printed for `504 Circle Dr.` came back as `504 Circle Dr` with a ZIP+4 the
 *    validator never mentioned.
 *  - EasyPost rewrites the address it matched and returns it, ZIP+4 included, so the
 *    operator can see and accept the standardised form.
 *
 * EasyPost behaviour this client relies on:
 *
 *  - `verify[]=delivery` checks deliverability, corrects minor spelling and format,
 *    and sets residential status for US addresses.
 *  - `verify[]=zip4` fills in the ZIP+4 for US addresses.
 *  - Without `verify_strict` a failure is still HTTP 200 — the verdict lives in
 *    `verifications.delivery.success`, so the status code is not the answer.
 *  - With `verify_strict` a failure is HTTP 422 with `error.code`
 *    `ADDRESS.VERIFY.FAILURE` and per-field `errors[]`. We do not send it (our own
 *    gate decides whether to block), but a 422 is mapped to `invalid`, never to an
 *    outage, or a bad address would slip through as "validator down".
 *  - Corrected addresses come back in USPS upper case, and a unit at the end of a
 *    long street1 is moved into street2.
 *
 * Like the Shippo validator this never returns WP_Error: a verifier that is down,
 * unprovisioned or throttled yields `unavailable` and the address is used as entered.
 */
if ( ! interface_exists( 'Shipping_Desk_Provider_Interface' ) ) {
    require_once __DIR__ . '/class-provider-interface.php';
}
if ( ! interface_exists( 'Shipping_Desk_Address_Validator_Interface' ) ) {
    require_once __DIR__ . '/class-address-validator-interface.php';
}

class Shipping_Desk_EasyPost_Client implements Shipping_Desk_Provider_Interface, Shipping_Desk_Address_Validator_Interface {

    const BASE_URL = 'https://api.easypost.com';

    const ADDRESSES_PATH = '/v2/addresses';

    const SHIPMENTS_PATH = '/v2/shipments';

    /** Verification is a side quest on the way to a quote; keep it short. */
    const TIMEOUT = 10;

    /**
     * Both verifications are always requested: `delivery` gives the verdict and the
     * residential flag, `zip4` gives the route code Shippo's validator omits.
     */
    const VERIFICATIONS = [ 'delivery', 'zip4' ];

    /** HTTP status EasyPost uses for a strict verification failure. */
    const STRICT_FAILURE_CODE = 422;

    /**
     * Labels are bought as PDF 4x6.
     *
     * Measured on the test API: USPS honours 4x6 (page 4.00 x 6.00 in); UPS returns
     * 4.00 x 7.00 in, documented by EasyPost as a 4x6 label plus 1 inch of blank space
     * (4x8 is 2 inches), so it prints on 4x6 stock at actual size. PDF keeps the
     * carrier's own artwork — the PNG->PDF conversion endpoint instead puts the label
     * on an 11 x 8.5 in letter page.
     */
    const LABEL_FORMAT = 'PDF';
    const LABEL_SIZE   = '4x6';

    /**
     * Page and label geometry, in points (72 pt = 1 in).
     *
     * A 4x6 label is 288x432. EasyPost's FedEx labels come back on a US letter page
     * (612x792) with the label drawn at true size near the top-left, even though the
     * API echoes the requested `label_size` back — measured, see
     * `docs/easypost-provider.md`.
     */
    const LETTER_WIDTH  = 612;
    const LETTER_HEIGHT = 792;
    const LABEL_WIDTH   = 288;
    const LABEL_HEIGHT  = 432;

    /**
     * Longest order number printed on the label.
     *
     * USPS is the tightest carrier and the same cap the Shippo client applies to
     * `extra.reference_1`, so an order number that prints on one provider prints
     * on the other rather than being rejected by one of them.
     */
    const REFERENCE_MAX_LENGTH = 30;

    /** Format requested for PrintNode; only a PNG label can be converted. */
    const CONVERTIBLE_FORMAT = 'PDF';

    /** EasyPost's own insurance is capped at $5,000. */
    const MAX_INSURANCE_AMOUNT = 5000;

    /**
     * The plugin's signature allowlist (Shippo's enums) → EasyPost's shipment
     * option values. Anything not in here is not sent at all.
     */
    const SIGNATURE_OPTIONS = [
        'STANDARD' => 'SIGNATURE',
        'ADULT'    => 'ADULT_SIGNATURE',
    ];

    /**
     * EasyPost carrier values → the brand the shop calls the carrier.
     *
     * `UPSDAP` (UPS Digital Access Program) and `FedExDefault` (EasyPost's
     * no-account fallback) are account types, not brands. Left raw they read as
     * internal jargon on the rate row, and they defeat rate matching: the plugin
     * compares the name with the WooCommerce shipping method, and `UPSDAP Ground`
     * can never equal `UPS Ground`.
     */
    const CARRIER_NAMES = [
        'USPS'               => 'USPS',
        'UPS'                => 'UPS',
        'UPSDAP'             => 'UPS',
        'UPSMailInnovations' => 'UPS Mail Innovations',
        'FedEx'              => 'FedEx',
        'FedExDefault'       => 'FedEx',
        'FedExSmartPost'     => 'FedEx',
    ];

    /**
     * Rate service codes → the names the carriers print on their own labels.
     *
     * Keyed by carrier family because every carrier spells its codes its own way
     * (`Ground` vs `FEDEX_GROUND` vs `GroundAdvantage`). Anything absent is
     * humanised rather than shown raw, so a new service still reads as words.
     */
    const SERVICE_NAMES = [
        'USPS'  => [
            'GroundAdvantage'     => 'Ground Advantage',
            'GroundAdvantagePlus' => 'Ground Advantage Plus',
            'Priority'            => 'Priority Mail',
            'PriorityExpress'     => 'Priority Mail Express',
            'Express'             => 'Priority Mail Express',
            'ParcelSelect'        => 'Parcel Select',
            'MediaMail'           => 'Media Mail',
            'LibraryMail'         => 'Library Mail',
            'First'               => 'First-Class Mail',
            'BoundPrintedMatter'  => 'Bound Printed Matter',
        ],
        'UPS'   => [
            'Ground'                    => 'Ground',
            'GroundSaver'               => 'Ground Saver',
            'UPSGroundsaverLessThan1lb' => 'Ground Saver',
            '3DaySelect'                => '3 Day Select',
            '2ndDayAir'                 => '2nd Day Air',
            '2ndDayAirAM'               => '2nd Day Air A.M.',
            'NextDayAir'                => 'Next Day Air',
            'NextDayAirSaver'           => 'Next Day Air Saver',
            'NextDayAirEarlyAM'         => 'Next Day Air Early A.M.',
            'Express'                   => 'Express Critical',
            'Standard'                  => 'Standard',
        ],
        'FedEx' => [
            'FEDEX_GROUND'           => 'Ground',
            'GROUND_HOME_DELIVERY'   => 'Home Delivery',
            'FEDEX_HOME_DELIVERY'    => 'Home Delivery',
            'FEDEX_EXPRESS_SAVER'    => 'Express Saver',
            'FEDEX_2_DAY'            => '2Day',
            'FEDEX_2_DAY_AM'         => '2Day A.M.',
            'STANDARD_OVERNIGHT'     => 'Standard Overnight',
            'PRIORITY_OVERNIGHT'     => 'Priority Overnight',
            'FIRST_OVERNIGHT'        => 'First Overnight',
            'SMART_POST'             => 'SmartPost',
            'FEDEX_3_DAY_FREIGHT'    => '3 Day Freight',
            'FEDEX_FIRST_FREIGHT'    => 'First Freight',
            'FEDEX_FREIGHT_ECONOMY'  => 'Freight Economy',
            'FEDEX_FREIGHT_PRIORITY' => 'Freight Priority',
        ],
    ];

    /** Explicit timeouts, in seconds. */
    const TIMEOUT_RATES       = 30;
    const TIMEOUT_TRANSACTION = 60;
    const TIMEOUT_REFUND      = 45;
    const TIMEOUT_READ        = 20;
    /** Downloading the bought label file for printing. */
    const TIMEOUT_TRANSFER    = 60;

    /** HTTP 429 backoff: attempts, and the base delay in seconds. */
    const MAX_ATTEMPTS     = 3;
    const RETRY_BASE_DELAY = 1;

    /**
     * Fallback spacing between purchases, in microseconds.
     *
     * The live value comes from `Shipping_Desk_Helpers::bulk_spacing_us()`; this is
     * only used when the helper is not loaded (a client included on its own).
     */
    const BULK_THROTTLE_US = 350000;

    /**
     * Dispatch cutoff, in the store's local time. Pinned rather than trusting the
     * server's timezone, exactly as the Shippo client does.
     */
    const DISPATCH_TIMEZONE = 'America/New_York';
    const DISPATCH_CUTOFF   = '16:00';

    /** The dispatch date is sent at local noon so no timezone can shift the day. */
    const DISPATCH_HOUR = 12;

    /**
     * Wire format for `options.label_date`.
     *
     * ISO 8601 with an explicit offset, because the date must not be reinterpreted
     * in the server's timezone on its way to the carrier.
     */
    const LABEL_DATE_FORMAT = 'Y-m-d\TH:i:sP';

    /** @var string */
    protected $api_key;

    /** Spacing for this run, in microseconds; widened by 429s. Null until needed. */
    protected $spacing_us = null;

    /**
     * @param string|null $api_key EasyPost API key (test or production).
     */
    public function __construct( $api_key = null ) {
        $this->api_key = null === $api_key ? '' : trim( (string) $api_key );
    }

    /* -- Label provider contract ----------------------------------------- */

    /**
     * Fetch rates via POST /v2/shipments.
     *
     * EasyPost has no rates endpoint: creating the shipment prices it, and the
     * returned `id` (`shp_…`) is the handle the buy call needs.
     *
     * @param array $payload Normalised shipment request.
     * @return array|WP_Error
     */
    public function get_rates( array $payload ) {
        if ( '' === $this->api_key ) {
            return new WP_Error( 'easypost_no_api_key', __( 'EasyPost API key is not set.', 'shipping-desk' ) );
        }

        $shipment_body = $this->build_shipment_body( $payload );
        if ( empty( $shipment_body['to_address']['street1'] ) || empty( $shipment_body['parcel']['weight'] ) ) {
            return new WP_Error(
                'easypost_incomplete_shipment',
                __( 'EasyPost needs a street address and a weight before it can rate a shipment.', 'shipping-desk' )
            );
        }

        // EasyPost wraps every created resource: the body is {"shipment": {...}}.
        // Sending the fields at the top level is not rejected outright — it answers 422
        // "SHIPMENT.INVALID_PARAMS" listing to_address/from_address/parcel as required
        // and missing, which reads like a payload bug rather than a missing envelope.
        // The spike (tools/easypost-label-spike.php) is what caught this.
        $body = [ 'shipment' => $shipment_body ];

        $shipment = $this->request( 'POST', self::SHIPMENTS_PATH, $body, self::TIMEOUT_RATES );

        // EasyPost validates shipment options strictly. `label_date` is worth
        // sending (it is the only way to honour the dispatch cutoff), but it must
        // never be able to take rating down with it: on a rejection that names an
        // option, retry once. Same discipline as Shippo's shipment_date fallback.
        if ( is_wp_error( $shipment ) && $this->is_option_rejection( $shipment ) ) {
            // The printed reference is dropped on its own first. It is the only
            // option carrying text the shop controls, and giving up the dispatch
            // date because an order number was rejected would print the wrong
            // postmark day on every label in the batch.
            if ( false !== strpos( strtolower( $shipment->get_error_message() ), 'print_custom' ) ) {
                unset( $body['shipment']['options']['print_custom_1'] );
                $shipment = $this->request( 'POST', self::SHIPMENTS_PATH, $body, self::TIMEOUT_RATES );
            }
        }

        if ( is_wp_error( $shipment ) && $this->is_option_rejection( $shipment ) ) {
            unset(
                $body['shipment']['options']['label_date'],
                $body['shipment']['options']['label_format'],
                $body['shipment']['options']['label_size']
            );
            if ( empty( $body['shipment']['options'] ) ) {
                unset( $body['shipment']['options'] );
            }
            $shipment = $this->request( 'POST', self::SHIPMENTS_PATH, $body, self::TIMEOUT_RATES );
        }

        if ( is_wp_error( $shipment ) ) {
            return $shipment;
        }

        if ( empty( $shipment['id'] ) ) {
            return new WP_Error( 'easypost_no_shipment', __( 'EasyPost did not return a shipment object.', 'shipping-desk' ) );
        }

        $remote_shipment_id = (string) $shipment['id'];
        $quotes             = [];

        if ( ! empty( $shipment['rates'] ) && is_array( $shipment['rates'] ) ) {
            foreach ( $shipment['rates'] as $rate ) {
                if ( ! is_array( $rate ) || empty( $rate['id'] ) ) {
                    continue;
                }
                $quotes[] = $this->normalize_rate( $rate, $remote_shipment_id );
            }
        }

        if ( empty( $quotes ) ) {
            // Never trust a 2xx without rates: the carrier errors live in
            // `messages`, and an empty list would render as "no rates" with no
            // reason anywhere.
            $message = __( 'EasyPost returned no rates for this shipment.', 'shipping-desk' );
            $detail  = $this->messages_text( $shipment );
            if ( '' !== $detail ) {
                $message .= ' ' . $detail;
            }
            return new WP_Error( 'easypost_no_rates', $message );
        }

        return [
            'remote_shipment_id' => $remote_shipment_id,
            'quotes'             => $quotes,
            'messages'           => isset( $shipment['messages'] ) && is_array( $shipment['messages'] )
                ? $shipment['messages']
                : [],
        ];
    }

    /**
     * Purchase a single label via POST /v2/shipments/{id}/buy.
     *
     * @param string $remote_shipment_id Shipment id (`shp_…`).
     * @param string $rate_id            Rate id (`rate_…`).
     * @param array  $rate               Rate as returned by get_rates().
     * @param int    $order_id           WooCommerce order ID.
     * @param array  $vas                Value-added services; `liability_amount` is insurance.
     * @return array|WP_Error
     */
    public function book_shipment( $remote_shipment_id, $rate_id, array $rate, $order_id, array $vas = [] ) {
        $remote_shipment_id = trim( (string) $remote_shipment_id );
        $rate_id            = trim( (string) $rate_id );

        if ( '' === $remote_shipment_id ) {
            return new WP_Error( 'easypost_missing_shipment', __( 'No EasyPost shipment ID was supplied.', 'shipping-desk' ) );
        }
        if ( '' === $rate_id ) {
            return new WP_Error( 'easypost_missing_rate', __( 'No EasyPost rate was selected.', 'shipping-desk' ) );
        }

        $insurance = $this->insurance_amount( $vas );
        if ( is_wp_error( $insurance ) ) {
            return $insurance;
        }

        $body = [ 'rate' => [ 'id' => $rate_id ] ];
        if ( '' !== $insurance ) {
            // EasyPost's own cover, priced at purchase. Not XCover, and not a
            // second call: the Shipment buy creates the Insurance object.
            $body['insurance'] = $insurance;
        }

        $order_reference = $this->order_reference( $order_id );

        $shipment = $this->request(
            'POST',
            self::SHIPMENTS_PATH . '/' . rawurlencode( $remote_shipment_id ) . '/buy',
            $body,
            self::TIMEOUT_TRANSACTION
        );

        if ( is_wp_error( $shipment ) ) {
            if ( $this->is_timeout( $shipment ) ) {
                // The label may already be bought. Reconcile before telling anyone
                // a retry is safe, or the retry double-charges.
                return $this->reconcile_purchase( $remote_shipment_id, $rate, $order_reference );
            }
            return $shipment;
        }

        return $this->purchase_result( $shipment, $rate, $order_reference, $remote_shipment_id );
    }

    /**
     * Purchase several labels, one buy call per shipment.
     *
     * EasyPost's `/batches` endpoint is asynchronous — a batch moves through
     * creating → created → purchasing → purchased and answers through webhooks —
     * so it cannot report a per-order result inside this request. The plugin's
     * bulk UI needs that result now, so this degrades to a throttled loop.
     *
     * @param array $shipments List of shipment descriptors.
     * @return array|WP_Error
     */
    public function book_shipments( array $shipments ) {
        if ( empty( $shipments ) ) {
            return new WP_Error( 'easypost_no_shipments', __( 'No shipments to book.', 'shipping-desk' ) );
        }

        $successful = [];
        $failed     = [];
        $booked     = 0;

        foreach ( $shipments as $shipment ) {
            if ( ! is_array( $shipment ) ) {
                continue;
            }

            if ( $booked > 0 ) {
                $this->throttle( $this->spacing_us() );
            }
            $booked++;

            $rate_id   = isset( $shipment['rate_id'] ) ? trim( (string) $shipment['rate_id'] ) : '';
            $remote_id = isset( $shipment['remote_shipment_id'] ) ? (string) $shipment['remote_shipment_id'] : '';
            $order_id  = $this->shipment_order_id( $shipment );
            $rate      = isset( $shipment['rate'] ) && is_array( $shipment['rate'] ) ? $shipment['rate'] : [];
            $vas       = $this->shipment_vas( $shipment );

            $result = $this->book_shipment( $remote_id, $rate_id, $rate, $order_id, $vas );

            if ( is_wp_error( $result ) ) {
                $key            = '' !== $remote_id ? $remote_id : $rate_id;
                $failed[ $key ] = [
                    'customer_reference' => (string) $order_id,
                    'error_messages'     => [ $result->get_error_message() ],
                    'error_code'         => $result->get_error_code(),
                    'retry_safe'         => $this->is_retry_safe( $result ),
                ];
                continue;
            }

            if ( ! empty( $result['successful'] ) && is_array( $result['successful'] ) ) {
                foreach ( $result['successful'] as $label_id => $entry ) {
                    $successful[ $label_id ] = $entry;
                }
            }
        }

        return [
            'successful' => $successful,
            'failed'     => $failed,
        ];
    }

    /**
     * Retrieve a shipment via GET /v2/shipments/{id}.
     *
     * @param string $id Shipment id.
     * @return array|WP_Error
     */
    public function get_shipment( $id ) {
        $id = trim( (string) $id );
        if ( '' === $id ) {
            return new WP_Error( 'easypost_missing_id', __( 'No EasyPost shipment ID was supplied.', 'shipping-desk' ) );
        }

        $shipment = $this->request( 'GET', self::SHIPMENTS_PATH . '/' . rawurlencode( $id ), null, self::TIMEOUT_READ );
        if ( is_wp_error( $shipment ) ) {
            if ( 'easypost_not_found' === $shipment->get_error_code() ) {
                return new WP_Error(
                    'easypost_not_found',
                    __( 'Shipment not found in EasyPost.', 'shipping-desk' ),
                    [ 'shipment_id' => $id ]
                );
            }
            return $shipment;
        }

        return $shipment;
    }

    /**
     * Request a refund via POST /v2/shipments/{id}/refund.
     *
     * Refunds are adjudicated asynchronously: `submitted` first, `refunded` later.
     * Asking twice is refused the same way a Shippo REFUNDPENDING transaction is,
     * so the caller can hide the button instead of showing an error.
     *
     * @param string $id Shipment id.
     * @return array|WP_Error
     */
    public function refund_shipment( $id ) {
        $id = trim( (string) $id );
        if ( '' === $id ) {
            return new WP_Error( 'easypost_missing_id', __( 'No EasyPost shipment ID was supplied.', 'shipping-desk' ) );
        }

        $shipment = $this->get_shipment( $id );
        if ( is_wp_error( $shipment ) ) {
            if ( 'easypost_not_found' === $shipment->get_error_code() ) {
                return new WP_Error(
                    'easypost_not_found',
                    __( 'Label not found in EasyPost.', 'shipping-desk' ),
                    [ 'shipment_id' => $id ]
                );
            }
            return $shipment;
        }

        $status = $this->refund_status( $shipment );
        if ( 'refunded' === $status ) {
            return new WP_Error(
                'easypost_already_refunded',
                __( 'This EasyPost label has already been refunded.', 'shipping-desk' ),
                [ 'shipment_id' => $id ]
            );
        }
        if ( 'submitted' === $status ) {
            return new WP_Error(
                'easypost_refund_pending',
                __( 'A refund for this label is already pending with the carrier.', 'shipping-desk' ),
                [ 'shipment_id' => $id ]
            );
        }

        return $this->request(
            'POST',
            self::SHIPMENTS_PATH . '/' . rawurlencode( $id ) . '/refund',
            null,
            self::TIMEOUT_REFUND
        );
    }

    /**
     * Printable label URL: the 4x6 PNG, which is what a label printer wants.
     *
     * @param string $id Shipment id.
     * @return string|WP_Error
     */
    public function get_label_url( $id ) {
        /*
         * EasyPost finishes assembling the label just after the buy response, so a shipment
         * fetched in the following second or two can still report no `postage_label`. Print
         * is precisely what an operator clicks next, which is why this failed
         * intermittently right after a purchase and worked on the second try.
         *
         * Ask again before concluding anything: four reads, backing off 0.5s/1s/1.5s. Only a
         * label that is still absent after that is reported missing - and the message never
         * suggests buying again, because the label exists and a second buy would be charged.
         */
        $attempts = 4;

        for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
            $shipment = $this->get_shipment( $id );

            if ( is_wp_error( $shipment ) ) {
                // A shipment bought a moment ago can answer 404 while EasyPost catches up,
                // and "Not Found" is an unhelpful thing to show an operator holding a
                // parcel. Retry it like a missing label; every other error is returned.
                if ( 'easypost_not_found' === $shipment->get_error_code() && $attempt < $attempts ) {
                    usleep( 500000 * $attempt );
                    continue;
                }

                return $shipment;
            }

            $url = $this->postage_label_field( $shipment, 'label_url' );
            if ( '' !== $url ) {
                return $url;
            }

            if ( $attempt < $attempts ) {
                // 0.5s, 1s, 1.5s: three seconds of patience in total. EasyPost has been
                // seen taking more than two, and a printing operator will wait three
                // seconds without noticing.
                usleep( 500000 * $attempt );
            }
        }

        return new WP_Error(
            'easypost_label_missing',
            __( 'EasyPost has not returned a label for this shipment yet. Wait a few seconds and click Print again - the label was bought, so do not buy another. If Print keeps failing, open the shipment in the EasyPost dashboard.', 'shipping-desk' ),
            [ 'shipment_id' => (string) $id ]
        );
    }

    /**
     * PDF label URL, for PrintNode.
     *
     * Labels are bought as PDF, so this normally returns `label_url` unchanged: no
     * conversion, no letter page. The conversion call is kept for a legacy PNG label
     * (bought before the format change) — measured, it puts the image on an
     * 11.00 x 8.50 in letter page, so it is a last resort, not the print path.
     *
     * Not part of the provider interface: an optional capability, exactly like
     * the validator contract. Callers check with method_exists().
     *
     * @param string $id Shipment id.
     * @return string|WP_Error
     */
    public function get_label_pdf_url( $id ) {
        $id = trim( (string) $id );
        if ( '' === $id ) {
            return new WP_Error( 'easypost_missing_id', __( 'No EasyPost shipment ID was supplied.', 'shipping-desk' ) );
        }

        $shipment = $this->get_shipment( $id );
        if ( is_wp_error( $shipment ) ) {
            return $shipment;
        }

        $pdf = $this->postage_label_field( $shipment, 'label_pdf_url' );
        if ( '' !== $pdf ) {
            return $pdf;
        }

        // Already a PDF (only possible for a shipment rated by something other
        // than this client): no conversion needed.
        if ( $this->is_pdf_url( $this->postage_label_field( $shipment, 'label_url' ) ) ) {
            return $this->postage_label_field( $shipment, 'label_url' );
        }

        $converted = $this->request(
            'GET',
            self::SHIPMENTS_PATH . '/' . rawurlencode( $id ) . '/label?file_format=' . rawurlencode( self::CONVERTIBLE_FORMAT ),
            null,
            self::TIMEOUT_READ
        );

        if ( is_wp_error( $converted ) ) {
            return $converted;
        }

        $url = $this->postage_label_field( $converted, 'label_pdf_url' );
        if ( '' === $url && $this->is_pdf_url( $this->postage_label_field( $converted, 'label_url' ) ) ) {
            $url = $this->postage_label_field( $converted, 'label_url' );
        }

        if ( '' === $url ) {
            return new WP_Error(
                'easypost_pdf_missing',
                __( 'EasyPost did not return a PDF version of this label.', 'shipping-desk' ),
                [ 'shipment_id' => $id ]
            );
        }

        return $url;
    }

    /**
     * The dispatch day a quote taken now would carry, as `Y-m-d`.
     *
     * Public so the caller can bind a stored quote to the day it was priced for:
     * `label_date` is baked into the shipment at quote time, so a quote priced
     * for one dispatch day must never be bought on another.
     *
     * @return string
     */
    public function dispatch_day() {
        return $this->dispatch_date()->format( 'Y-m-d' );
    }

    /* -- Address verification (unchanged) -------------------------------- */

    /**
     * Verify and standardise an address.
     *
     * @param array $address Plugin address array.
     * @return array Normalised result — see class-address-validator-interface.php.
     */
    public function validate_address( array $address ) {
        if ( '' === $this->api_key ) {
            return $this->result( [ 'error' => __( 'EasyPost API key is not set.', 'shipping-desk' ) ] );
        }

        $body = $this->build_body( $address );
        if ( '' === $body ) {
            return $this->result( [
                'error' => __( 'The address does not have enough detail to verify.', 'shipping-desk' ),
            ] );
        }

        $response = wp_remote_post(
            self::BASE_URL . self::ADDRESSES_PATH,
            [
                'headers' => [
                    // Basic auth: the key is the username and the password is empty.
                    'Authorization' => 'Basic ' . base64_encode( $this->api_key . ':' ),
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                    'Accept'        => 'application/json',
                ],
                'body'    => $body,
                'timeout' => (float) self::TIMEOUT,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $this->result( [ 'error' => $response->get_error_message() ] );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $data ) ) {
            return $this->result( [
                'error' => sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'EasyPost returned an unreadable response (HTTP %d).', 'shipping-desk' ),
                    $code
                ),
            ] );
        }

        if ( $code < 200 || $code >= 300 ) {
            return $this->error_result( $data, $code );
        }

        return $this->parse_address( $address, $data );
    }


    /**
     * Form body for `POST /v2/addresses`.
     *
     * Built by hand rather than from an array: EasyPost wants repeated `verify[]`
     * keys, and `http_build_query()` would emit numbered indexes instead. Returns ''
     * when the address cannot be verified at all, so no request is paid for.
     *
     * @param array $address Plugin address array.
     * @return string
     */
    protected function build_body( array $address ) {
        $map = [
            'name'         => 'name',
            'company'      => 'company',
            'phone'        => 'phone',
            'email'        => 'email',
            'line1'        => 'street1',
            'line2'        => 'street2',
            'town'         => 'city',
            'county'       => 'state',
            'postcode'     => 'zip',
            'country_code' => 'country',
        ];

        $params = [];
        foreach ( $map as $from => $to ) {
            if ( ! isset( $address[ $from ] ) || is_array( $address[ $from ] ) ) {
                continue;
            }
            $value = trim( (string) $address[ $from ] );
            if ( '' !== $value ) {
                $params[ $to ] = $value;
            }
        }

        // A street is mandatory, plus either a ZIP or city and state.
        if ( ! isset( $params['street1'] ) ) {
            return '';
        }
        if ( ! isset( $params['zip'] ) && ! ( isset( $params['city'] ) && isset( $params['state'] ) ) ) {
            return '';
        }
        if ( ! isset( $params['country'] ) ) {
            $params['country'] = 'US';
        }

        $pairs = [];
        foreach ( $params as $key => $value ) {
            $pairs[] = 'address[' . $key . ']=' . rawurlencode( $value );
        }
        foreach ( self::VERIFICATIONS as $verification ) {
            $pairs[] = 'verify[]=' . rawurlencode( $verification );
        }

        return implode( '&', $pairs );
    }

    /**
     * Turn a verified address object into the normalised result.
     *
     * @param array $original Address as submitted.
     * @param array $data     Decoded EasyPost address object.
     * @return array
     */
    protected function parse_address( array $original, array $data ) {
        $verifications = isset( $data['verifications'] ) && is_array( $data['verifications'] )
            ? $data['verifications']
            : [];

        if ( empty( $verifications ) ) {
            // Answered, but said nothing: an outage from our point of view, not a
            // pass. The address is used as entered.
            return $this->result( [
                'error' => __( 'EasyPost returned no verification result.', 'shipping-desk' ),
            ] );
        }

        $delivery = isset( $verifications['delivery'] ) && is_array( $verifications['delivery'] )
            ? $verifications['delivery']
            : [];

        $recommended = $this->map_verified_address( $data );
        $changed     = $this->compare( $original, $recommended );
        $type        = $this->address_type( $data );

        if ( ! empty( $delivery['success'] ) ) {
            return $this->result( [
                'status'       => empty( $changed ) ? 'valid' : 'corrected',
                'is_valid'     => true,
                'recommended'  => $recommended,
                'changed'      => $changed,
                'reasons'      => $this->map_errors( isset( $delivery['errors'] ) ? $delivery['errors'] : [] ),
                'address_type' => $type,
            ] );
        }

        // Delivery verification failed: not shippable as entered.
        return $this->result( [
            'status'       => 'invalid',
            'is_valid'     => false,
            'recommended'  => [],
            'changed'      => [],
            'reasons'      => $this->map_errors( isset( $delivery['errors'] ) ? $delivery['errors'] : [] ),
            'address_type' => $type,
        ] );
    }

    /**
     * Map a non-2xx EasyPost response.
     *
     * @param array $data Decoded body.
     * @param int   $code HTTP status.
     * @return array
     */
    protected function error_result( array $data, $code ) {
        $error     = isset( $data['error'] ) && is_array( $data['error'] ) ? $data['error'] : [];
        $code_name = isset( $error['code'] ) ? (string) $error['code'] : '';
        $reasons   = $this->map_errors( isset( $error['errors'] ) ? $error['errors'] : [] );

        if ( self::STRICT_FAILURE_CODE === (int) $code && 'ADDRESS.VERIFY.FAILURE' === $code_name ) {
            return $this->result( [
                'status'   => 'invalid',
                'is_valid' => false,
                'reasons'  => $reasons,
            ] );
        }

        // Everything else — bad key, throttling, server error — leaves the address
        // unverified rather than blocking a quote.
        $message = isset( $error['message'] ) ? (string) $error['message'] : '';
        if ( '' === $message ) {
            $message = sprintf(
                /* translators: %d: HTTP status code */
                __( 'EasyPost request failed (HTTP %d).', 'shipping-desk' ),
                (int) $code
            );
        }

        return $this->result( [
            'reasons' => $reasons,
            'error'   => $message,
        ] );
    }

    /**
     * Map the returned address onto plugin field names.
     *
     * @param array $data EasyPost address object.
     * @return array
     */
    protected function map_verified_address( array $data ) {
        $map = [
            'street1' => 'line1',
            'street2' => 'line2',
            'city'    => 'town',
            'state'   => 'county',
            'zip'     => 'postcode',
            'country' => 'country_code',
            'name'    => 'name',
            'company' => 'company',
            'phone'   => 'phone',
            'email'   => 'email',
        ];

        $mapped = [];
        foreach ( $map as $from => $to ) {
            if ( ! isset( $data[ $from ] ) || is_array( $data[ $from ] ) ) {
                continue;
            }
            $value = trim( (string) $data[ $from ] );
            if ( '' !== $value ) {
                $mapped[ $to ] = $value;
            }
        }

        return $mapped;
    }

    /**
     * Which fields differ between what was sent and what was verified.
     *
     * Compared case-insensitively because EasyPost returns USPS upper case: an
     * address that only changed case has not been corrected, and reporting it would
     * offer a suggestion that reads as shouting.
     *
     * @param array $original    Address as submitted.
     * @param array $recommended Verified address.
     * @return string[] EasyPost field names, using the same vocabulary as Shippo's.
     */
    protected function compare( array $original, array $recommended ) {
        $pairs = [
            'line1'    => 'address_line_1',
            'line2'    => 'address_line_2',
            'town'     => 'city_locality',
            'county'   => 'state_province',
            'postcode' => 'postal_code',
        ];

        $changed = [];
        foreach ( $pairs as $field => $name ) {
            $before = isset( $original[ $field ] ) ? strtolower( trim( (string) $original[ $field ] ) ) : '';
            $after  = isset( $recommended[ $field ] ) ? strtolower( trim( (string) $recommended[ $field ] ) ) : '';
            if ( $before !== $after ) {
                $changed[] = $name;
            }
        }

        return $changed;
    }

    /**
     * Residential flag → the vocabulary Shippo uses, so the UI needs no changes.
     *
     * @param array $data EasyPost address object.
     * @return string 'residential', 'commercial', or '' when unknown.
     */
    protected function address_type( array $data ) {
        if ( ! array_key_exists( 'residential', $data ) || null === $data['residential'] ) {
            return '';
        }
        return $data['residential'] ? 'residential' : 'commercial';
    }

    /**
     * EasyPost field errors → the plugin's reason shape.
     *
     * @param mixed $errors `errors[]` array.
     * @return array
     */
    protected function map_errors( $errors ) {
        if ( ! is_array( $errors ) ) {
            return [];
        }

        $mapped = [];
        foreach ( $errors as $error ) {
            if ( ! is_array( $error ) ) {
                continue;
            }
            $message = isset( $error['message'] ) ? (string) $error['message'] : '';
            if ( ! empty( $error['suggestion'] ) ) {
                $message .= ' ' . sprintf(
                    /* translators: %s: the correction EasyPost suggests */
                    __( 'Suggested: %s', 'shipping-desk' ),
                    (string) $error['suggestion']
                );
            }
            $mapped[] = [
                'code'        => isset( $error['code'] ) ? (string) $error['code'] : '',
                'type'        => 'error',
                'description' => $message,
            ];
        }

        return $mapped;
    }

    /**
     * Shape every result identically. The default is the safe answer.
     *
     * @param array $overrides Keys to replace.
     * @return array
     */
    protected function result( array $overrides = [] ) {
        return array_merge(
            [
                'status'       => 'unavailable',
                'is_valid'     => false,
                'recommended'  => [],
                'changed'      => [],
                'reasons'      => [],
                'address_type' => '',
                'confidence'   => [],
                'error'        => '',
            ],
            $overrides
        );
    }

    /* -- Transport ------------------------------------------------------- */

    /**
     * One JSON request, with a 429 backoff that honours Retry-After.
     *
     * EasyPost answers JSON everywhere except the verifier's form-encoded call,
     * so this is shared by rates, buy, read, refund and label conversion.
     *
     * @param string     $method  'GET' or 'POST'.
     * @param string     $path    Path, optionally with a query string.
     * @param array|null $body    JSON body; null sends none.
     * @param int        $timeout Seconds.
     * @param int        $attempt Attempt number, for the retry budget.
     * @return array|WP_Error
     */
    protected function request( $method, $path, $body = null, $timeout = self::TIMEOUT_READ, $attempt = 1 ) {
        if ( '' === $this->api_key ) {
            return new WP_Error( 'easypost_no_api_key', __( 'EasyPost API key is not set.', 'shipping-desk' ) );
        }

        $args = [
            'headers' => [
                // Basic auth: the key is the username and the password is empty.
                'Authorization' => 'Basic ' . base64_encode( $this->api_key . ':' ),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'timeout' => (float) $timeout,
        ];

        if ( null !== $body ) {
            $encoded      = function_exists( 'wp_json_encode' ) ? wp_json_encode( $body ) : json_encode( $body );
            $args['body'] = false === $encoded ? '' : $encoded;
        }

        $url      = self::BASE_URL . $path;
        $response = 'GET' === $method ? wp_remote_get( $url, $args ) : wp_remote_post( $url, $args );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'easypost_http_error',
                $response->get_error_message(),
                [ 'retry_safe' => false ]
            );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $raw  = (string) wp_remote_retrieve_body( $response );

        if ( $this->should_retry( $method, $code ) ) {
            if ( 429 === $code ) {
                // Slow the rest of the run down as well: retrying this one call at the
                // same pace is how a limiter becomes a loop of 429s.
                $this->widen_spacing();
            }
            if ( $attempt < self::MAX_ATTEMPTS ) {
                $this->pause( $this->retry_delay( $response, $attempt ) );
                return $this->request( $method, $path, $body, $timeout, $attempt + 1 );
            }
        }

        $data = json_decode( $raw, true );

        if ( $code < 200 || $code >= 300 ) {
            return $this->error_from_response( is_array( $data ) ? $data : [], $code );
        }

        if ( ! is_array( $data ) ) {
            return new WP_Error(
                'easypost_unreadable_response',
                sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'EasyPost returned an unreadable response (HTTP %d).', 'shipping-desk' ),
                    $code
                )
            );
        }

        $this->ease_spacing();

        return $data;
    }

    /**
     * Whether a failed request is worth sending again.
     *
     * A 429 means "not processed", so even a POST is safe to repeat. A 5xx is
     * only retried for a read: a lost answer to a spending POST could hide a
     * purchase that really happened, so those are never repeated.
     *
     * @param string $method 'GET' or 'POST'.
     * @param int    $code   HTTP status code.
     * @return bool
     */
    protected function should_retry( $method, $code ) {
        if ( 429 === (int) $code ) {
            return true;
        }

        return 'GET' === strtoupper( (string) $method ) && (int) $code >= 500;
    }

    /**
     * Seconds to wait before retrying a throttled request.
     *
     * `Retry-After` wins when EasyPost sends one — waiting less than it asks is
     * how a soft throttle turns into a hard ban.
     *
     * @param array $response HTTP response.
     * @param int   $attempt  Attempt number.
     * @return int
     */
    protected function retry_delay( $response, $attempt ) {
        $header = function_exists( 'wp_remote_retrieve_header' )
            ? wp_remote_retrieve_header( $response, 'retry-after' )
            : '';

        if ( is_array( $header ) ) {
            $header = reset( $header );
        }
        if ( is_numeric( $header ) ) {
            return max( 1, min( 10, (int) $header ) );
        }

        return self::RETRY_BASE_DELAY * max( 1, (int) $attempt );
    }

    /**
     * Map a non-2xx EasyPost response onto a WP_Error.
     *
     * @param array $data Decoded body.
     * @param int   $code HTTP status.
     * @return WP_Error
     */
    protected function error_from_response( array $data, $code ) {
        $error   = isset( $data['error'] ) && is_array( $data['error'] ) ? $data['error'] : [];
        $name    = isset( $error['code'] ) ? (string) $error['code'] : '';
        $message = isset( $error['message'] ) ? trim( (string) $error['message'] ) : '';

        $details = [];
        if ( ! empty( $error['errors'] ) && is_array( $error['errors'] ) ) {
            foreach ( $error['errors'] as $detail ) {
                if ( is_array( $detail ) && ! empty( $detail['message'] ) ) {
                    $details[] = (string) $detail['message'];
                } elseif ( is_string( $detail ) && '' !== trim( $detail ) ) {
                    $details[] = trim( $detail );
                }
            }
        }
        if ( ! empty( $details ) ) {
            $message = trim( $message . ' ' . implode( '; ', $details ) );
        }
        if ( '' === $message ) {
            $message = sprintf(
                /* translators: %d: HTTP status code */
                __( 'EasyPost request failed (HTTP %d).', 'shipping-desk' ),
                (int) $code
            );
        }

        if ( 401 === (int) $code || 403 === (int) $code ) {
            $error_code = 'easypost_auth_error';
        } elseif ( 404 === (int) $code ) {
            $error_code = 'easypost_not_found';
        } elseif ( 422 === (int) $code ) {
            $error_code = 'easypost_invalid_request';
        } elseif ( 429 === (int) $code ) {
            $error_code = 'easypost_rate_limited';
        } else {
            $error_code = 'easypost_error';
        }

        return new WP_Error(
            $error_code,
            $message,
            [
                'status'     => (int) $code,
                'error_code' => $name,
                'retry_safe' => false,
            ]
        );
    }

    /**
     * Carrier errors out of a shipment's `messages[]`, as one sentence.
     *
     * @param array $shipment Shipment object.
     * @return string
     */
    protected function messages_text( array $shipment ) {
        if ( empty( $shipment['messages'] ) || ! is_array( $shipment['messages'] ) ) {
            return '';
        }

        $parts = [];
        foreach ( $shipment['messages'] as $message ) {
            if ( is_string( $message ) ) {
                $parts[] = trim( $message );
            } elseif ( is_array( $message ) ) {
                if ( ! empty( $message['message'] ) ) {
                    $parts[] = trim( (string) $message['message'] );
                } elseif ( ! empty( $message['carrier'] ) ) {
                    $parts[] = trim( (string) $message['carrier'] );
                }
            }
        }

        return implode( ' ', array_filter( $parts ) );
    }

    /* -- Shipment payload ------------------------------------------------ */

    /**
     * Map the plugin's normalised rate payload onto an EasyPost shipment body.
     *
     * The plugin's payload is normalised: one entry in `parcels`, addresses
     * using the plugin's field names, plus `customer_reference`.
     *
     * @param array $payload Normalised shipment request.
     * @return array
     */
    protected function build_shipment_body( array $payload ) {
        $parcels = isset( $payload['parcels'] ) && is_array( $payload['parcels'] ) ? $payload['parcels'] : [];
        $first   = ! empty( $parcels[0] ) && is_array( $parcels[0] ) ? $parcels[0] : [];

        $body = [
            'to_address'   => $this->map_address(
                isset( $payload['to_address'] ) && is_array( $payload['to_address'] ) ? $payload['to_address'] : []
            ),
            'from_address' => $this->map_address(
                isset( $payload['from_address'] ) && is_array( $payload['from_address'] ) ? $payload['from_address'] : []
            ),
            'parcel'       => $this->map_parcel( $first ),
            'options'      => $this->shipment_options( $payload ),
        ];

        if ( isset( $payload['customer_reference'] ) && '' !== trim( (string) $payload['customer_reference'] ) ) {
            // Visible in the EasyPost dashboard, and a secondary marker when a
            // purchase has to be reconciled by hand. This is the API handle, not
            // label text: what prints on the label is `options.print_custom_1`,
            // set in shipment_options().
            $body['reference'] = trim( (string) $payload['customer_reference'] );
        }

        return $body;
    }

    /**
     * Plugin address array → EasyPost address object.
     *
     * @param array $address Address.
     * @return array
     */
    protected function map_address( array $address ) {
        $map = [
            'name'         => 'name',
            'company'      => 'company',
            'phone'        => 'phone',
            'email'        => 'email',
            'line1'        => 'street1',
            'line2'        => 'street2',
            'town'         => 'city',
            'county'       => 'state',
            'postcode'     => 'zip',
            'country_code' => 'country',
        ];

        $mapped = [];
        foreach ( $map as $from => $to ) {
            if ( ! isset( $address[ $from ] ) || is_array( $address[ $from ] ) ) {
                continue;
            }
            $value = trim( (string) $address[ $from ] );
            if ( '' !== $value ) {
                $mapped[ $to ] = $value;
            }
        }

        if ( ! isset( $mapped['country'] ) ) {
            $mapped['country'] = 'US';
        }

        return $mapped;
    }

    /**
     * Plugin parcel → EasyPost parcel, in ounces and inches.
     *
     * Zero dimensions are omitted rather than sent as 0: EasyPost treats a
     * zero-sized side as invalid and refuses to rate the shipment.
     *
     * @param array $parcel Parcel from the payload.
     * @return array
     */
    protected function map_parcel( array $parcel ) {
        $mapped = [];

        if ( isset( $parcel['weight'] ) && is_numeric( $parcel['weight'] ) ) {
            $weight = round( (float) $parcel['weight'], 2 );
            if ( $weight > 0 ) {
                $mapped['weight'] = $weight;
            }
        }

        foreach ( [ 'length', 'width', 'height' ] as $dimension ) {
            if ( isset( $parcel[ $dimension ] ) && is_numeric( $parcel[ $dimension ] ) ) {
                $value = round( (float) $parcel[ $dimension ], 2 );
                if ( $value > 0 ) {
                    $mapped[ $dimension ] = $value;
                }
            }
        }

        return $mapped;
    }

    /**
     * Shipment options: label format, dispatch date, printed reference and
     * signature.
     *
     * @param array $payload Normalised shipment request.
     * @return array
     */
    protected function shipment_options( array $payload ) {
        $options = [
            'label_format' => self::LABEL_FORMAT,
            'label_size'   => self::LABEL_SIZE,
            // The date the parcel is tendered. Baked into the shipment now, so a
            // quote priced for one dispatch day cannot be bought on another.
            'label_date'   => $this->label_date(),
        ];

        $reference = $this->print_reference( $payload );
        if ( '' !== $reference ) {
            // The order number as text on the label. `shipment.reference` is
            // EasyPost's API handle: it appears in the dashboard and in
            // endpoints, but it is never printed — which is why labels bought
            // before this option existed came out with no order number on them.
            //
            // No print_custom_1_code is sent: the code names the field's caption
            // per carrier (UPS wants ON/PO/IK, FedEx PO/DP/RMA), and the shipment
            // is created before a carrier is chosen, so a carrier-specific code
            // would be wrong for every other rate in the same quote.
            $options['print_custom_1'] = $reference;
        }

        $signature = isset( $payload['signature_confirmation'] )
            ? strtoupper( trim( (string) $payload['signature_confirmation'] ) )
            : '';
        if ( '' !== $signature && isset( self::SIGNATURE_OPTIONS[ $signature ] ) ) {
            // Translation happens here, not in the UI: the plugin's allowlist is
            // Shippo's, and no provider's vocabulary leaks outwards.
            $options['delivery_confirmation'] = self::SIGNATURE_OPTIONS[ $signature ];
        }

        return $options;
    }

    /**
     * The order number as one printable line.
     *
     * Order numbers are usually plain digits, but a custom numbering plugin can
     * put anything in them. Control characters and newlines are the only values
     * that reliably upset carrier label fields, and the label prints a single
     * line regardless, so both are flattened here instead of being sent and
     * rejected. Truncation matches the Shippo client's cap on `extra.reference_1`.
     *
     * @param array $payload Normalised shipment request.
     * @return string Empty when there is nothing worth printing.
     */
    protected function print_reference( array $payload ) {
        $reference = isset( $payload['customer_reference'] ) ? (string) $payload['customer_reference'] : '';
        $reference = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $reference );
        $reference = trim( preg_replace( '/\s+/', ' ', (string) $reference ) );

        if ( '' === $reference ) {
            return '';
        }

        return substr( $reference, 0, self::REFERENCE_MAX_LENGTH );
    }

    /* -- Purchase and normalisation -------------------------------------- */

    /**
     * Shape a bought shipment the way the plugin expects, or report that the
     * 2xx carried no label.
     *
     * @param array      $shipment           Shipment object after the buy.
     * @param array      $rate               Rate captured at quote time.
     * @param string     $order_reference    Order ID as a string.
     * @param string|int $remote_shipment_id Shipment id.
     * @return array|WP_Error
     */
    protected function purchase_result( array $shipment, array $rate, $order_reference, $remote_shipment_id ) {
        $label_url = $this->postage_label_field( $shipment, 'label_url' );

        if ( '' === $label_url ) {
            // Never trust a 2xx without a label. EasyPost only returns postage_label
            // on a purchased shipment, so this is either a failed carrier purchase
            // or a response that lost its body — both need a human, not a retry.
            return new WP_Error(
                'easypost_no_label',
                __( 'EasyPost accepted the request but returned no label. Check the EasyPost dashboard before trying again; the label may already have been bought.', 'shipping-desk' ),
                [
                    'shipment_id' => (string) $remote_shipment_id,
                    'retry_safe'  => false,
                ]
            );
        }

        $id = isset( $shipment['id'] ) && '' !== trim( (string) $shipment['id'] )
            ? (string) $shipment['id']
            : (string) $remote_shipment_id;

        return [
            'successful'         => [
                $id => $this->normalize_transaction( $shipment, $rate, $order_reference, $remote_shipment_id ),
            ],
            'failed'             => [],
            'remote_shipment_id' => (string) $remote_shipment_id,
        ];
    }

    /**
     * Resolve a purchase whose HTTP response never arrived.
     *
     * There is no idempotency key, so a timed-out POST cannot simply be retried:
     * the label may already exist and the retry would double-charge. The shipment
     * itself is the evidence — a bought one carries a PostageLabel.
     *
     * @param string $remote_shipment_id Shipment id.
     * @param array  $rate               Rate captured at quote time.
     * @param string $order_reference    Order ID as a string.
     * @return array|WP_Error
     */
    protected function reconcile_purchase( $remote_shipment_id, array $rate, $order_reference ) {
        $shipment = $this->get_shipment( $remote_shipment_id );

        if ( is_wp_error( $shipment ) ) {
            return new WP_Error(
                'easypost_purchase_unconfirmed',
                __( 'The EasyPost request timed out and the shipment could not be read back. Check the EasyPost dashboard before retrying; the label may already exist.', 'shipping-desk' ),
                [
                    'shipment_id' => (string) $remote_shipment_id,
                    'retry_safe'  => false,
                ]
            );
        }

        if ( '' !== $this->postage_label_field( $shipment, 'label_url' ) ) {
            return $this->purchase_result( $shipment, $rate, $order_reference, $remote_shipment_id );
        }

        return new WP_Error(
            'easypost_not_bought',
            __( 'The EasyPost request timed out and the shipment shows no label, so nothing was charged. It is safe to try again.', 'shipping-desk' ),
            [
                'shipment_id' => (string) $remote_shipment_id,
                'retry_safe'  => true,
            ]
        );
    }

    /**
     * Carrier family a raw EasyPost carrier value belongs to.
     *
     * Only used to look names up; the raw value stays in `provider`/`carrier`,
     * which the receipt, the order meta and the tracking link read.
     *
     * @param string $carrier Raw carrier value.
     * @return string `USPS`, `UPS`, `FedEx`, or an empty string when unknown.
     */
    protected function carrier_family( $carrier ) {
        $carrier = strtolower( trim( (string) $carrier ) );
        if ( false !== strpos( $carrier, 'usps' ) ) {
            return 'USPS';
        }
        if ( false !== strpos( $carrier, 'ups' ) ) {
            return 'UPS';
        }
        if ( false !== strpos( $carrier, 'fedex' ) ) {
            return 'FedEx';
        }

        return '';
    }

    /**
     * The carrier as the shop names it.
     *
     * @param string $carrier Raw carrier value.
     * @return string
     */
    protected function display_carrier( $carrier ) {
        $carrier = trim( (string) $carrier );
        if ( isset( self::CARRIER_NAMES[ $carrier ] ) ) {
            return self::CARRIER_NAMES[ $carrier ];
        }

        $family = $this->carrier_family( $carrier );

        return '' !== $family ? $family : $this->title_case_words( $this->split_words( $carrier ) );
    }

    /**
     * The service as the carrier names it, e.g. `FEDEX_2_DAY` → `2Day`.
     *
     * @param string $carrier Raw carrier value.
     * @param string $service Raw service value.
     * @return string
     */
    protected function display_service( $carrier, $service ) {
        $service = trim( (string) $service );
        $family  = $this->carrier_family( $carrier );

        if ( '' !== $family && isset( self::SERVICE_NAMES[ $family ][ $service ] ) ) {
            return self::SERVICE_NAMES[ $family ][ $service ];
        }

        // FedEx prefixes every code with its own name, which the carrier name
        // already says: `FEDEX_3_DAY_FREIGHT` would otherwise read "FedEx FedEx".
        if ( 'FedEx' === $family ) {
            $service = preg_replace( '/^FEDEX[_\s-]*/i', '', $service );
        }

        return $this->title_case_words( $this->split_words( $service ) );
    }

    /**
     * One display name for a rate: carrier plus service.
     *
     * @param string $carrier Raw carrier value.
     * @param string $service Raw service value.
     * @return string Empty only when both halves are empty.
     */
    protected function display_name( $carrier, $service ) {
        return trim( $this->display_carrier( $carrier ) . ' ' . $this->display_service( $carrier, $service ) );
    }

    /**
     * Break a carrier or service code into words.
     *
     * Handles the three shapes EasyPost mixes: `NextDayAirEarlyAM` (camel case),
     * `FEDEX_2_DAY` (underscores) and `DHLExpress` (a run of capitals before a
     * capitalised word).
     *
     * @param string $code Raw code.
     * @return string Lower-case words separated by single spaces.
     */
    protected function split_words( $code ) {
        $text = trim( (string) $code );
        if ( '' === $text ) {
            return '';
        }

        $text = str_replace( [ '_', '-' ], ' ', $text );
        // `NextDay` → `Next Day`, and `DHLExpress` → `DHL Express`.
        $text = preg_replace( '/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', ' ', $text );
        $text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );

        return strtolower( $text );
    }

    /**
     * Capitalise words that `split_words()` lower-cased, keeping known acronyms.
     *
     * @param string $words Space-separated lower-case words.
     * @return string
     */
    protected function title_case_words( $words ) {
        if ( '' === $words ) {
            return '';
        }

        $acronyms = [ 'dhl', 'ups', 'usps' ];
        $parts    = explode( ' ', $words );
        foreach ( $parts as $index => $word ) {
            $parts[ $index ] = in_array( $word, $acronyms, true ) ? strtoupper( $word ) : ucfirst( $word );
        }

        return implode( ' ', $parts );
    }

    /**
     * EasyPost rate → the normalised rate the admin JavaScript consumes.
     *
     * @param array  $rate      Rate object.
     * @param string $remote_id Parent shipment id.
     * @return array
     */
    protected function normalize_rate( array $rate, $remote_id ) {
        $carrier = isset( $rate['carrier'] ) ? trim( (string) $rate['carrier'] ) : '';
        $service = isset( $rate['service'] ) ? trim( (string) $rate['service'] ) : '';
        // The shop-facing name, not the wire value: `UPSDAP` + `Ground` would
        // otherwise read "UPSDAP Ground" and match no WooCommerce shipping
        // method. `provider`/`carrier`/`service` below keep the raw values for
        // the receipt, the order meta and the tracking link.
        $name    = $this->display_name( $carrier, $service );

        $days = null;
        foreach ( [ 'delivery_days', 'est_delivery_days' ] as $key ) {
            if ( isset( $rate[ $key ] ) && is_numeric( $rate[ $key ] ) ) {
                $days = (int) $rate[ $key ];
                break;
            }
        }

        return [
            'rate_id'                  => (string) $rate['id'],
            'remote_shipment_id'       => (string) $remote_id,
            'service_name'             => '' !== $name ? $name : (string) $rate['id'],
            'total_charge'             => isset( $rate['rate'] ) ? (string) $rate['rate'] : '',
            // EasyPost has no priced extras on a rate; kept for the shared JS.
            'shipping_service_options' => [],
            'provider'                 => $carrier,
            // Preserved so the receipt and the order note do not need a second call.
            'carrier'                  => $carrier,
            'service'                  => $service,
            'currency'                 => isset( $rate['currency'] ) ? (string) $rate['currency'] : '',
            // Transit time is a duration; EasyPost has no wording for it, so
            // duration_terms stays empty rather than being invented.
            'estimated_days'           => $days,
            'duration_terms'           => '',
            'delivery_date_guaranteed' => ! empty( $rate['delivery_date_guaranteed'] ),
            'retail_rate'              => isset( $rate['retail_rate'] ) ? (string) $rate['retail_rate'] : '',
            'list_rate'                => isset( $rate['list_rate'] ) ? (string) $rate['list_rate'] : '',
        ];
    }

    /**
     * Bought shipment → the normalised transaction the plugin stores.
     *
     * `total_charge` is the sum of the charged fees, so it includes EasyPost's
     * insurance — that is the real cost, and it is what BrikPanel records.
     *
     * @param array  $shipment           Shipment object after the buy.
     * @param array  $rate               Rate captured at quote time.
     * @param string $order_reference    Order ID as a string.
     * @param string $remote_shipment_id Shipment id.
     * @return array
     */
    protected function normalize_transaction( array $shipment, array $rate = [], $order_reference = '', $remote_shipment_id = '' ) {
        $selected = isset( $shipment['selected_rate'] ) && is_array( $shipment['selected_rate'] )
            ? $shipment['selected_rate']
            : [];

        $id = isset( $shipment['id'] ) && '' !== trim( (string) $shipment['id'] )
            ? (string) $shipment['id']
            : (string) $remote_shipment_id;

        $carrier  = isset( $selected['carrier'] ) ? (string) $selected['carrier'] : (string) ( $rate['provider'] ?? '' );
        $service  = isset( $selected['service'] ) ? (string) $selected['service'] : (string) ( $rate['service'] ?? '' );
        $currency = isset( $selected['currency'] ) ? (string) $selected['currency'] : (string) ( $rate['currency'] ?? 'USD' );
        $label    = $this->postage_label_field( $shipment, 'label_url' );
        $name     = $this->display_name( $carrier, $service );

        $charge = $this->fees_total( $shipment );
        if ( '' === $charge && isset( $selected['rate'] ) && is_numeric( $selected['rate'] ) ) {
            $charge = (string) $selected['rate'];
        }
        if ( '' === $charge && isset( $rate['total_charge'] ) ) {
            $charge = (string) $rate['total_charge'];
        }

        $tracking_url = '';
        if ( ! empty( $shipment['tracker']['public_url'] ) ) {
            $tracking_url = (string) $shipment['tracker']['public_url'];
        }

        return [
            // normalised keys first: the plugin reads id, tracking_number,
            // carrier_id and labels[0].url, and uses the array key as label id.
            'id'                 => $id,
            'customer_reference' => (string) $order_reference,
            'tracking_number'    => isset( $shipment['tracking_code'] ) ? (string) $shipment['tracking_code'] : '',
            'carrier_id'         => $carrier,
            'labels'             => '' !== $label ? [ [ 'url' => $label ] ] : [],
            // EasyPost specifics, for meta and diagnostics.
            'label_id'           => $id,
            'transaction_id'     => $id,
            'remote_shipment_id' => (string) $remote_shipment_id,
            'label_url'          => $label,
            'label_pdf_url'      => $this->postage_label_field( $shipment, 'label_pdf_url' ),
            'postage_label_id'   => isset( $shipment['postage_label']['id'] ) ? (string) $shipment['postage_label']['id'] : '',
            'label_size'         => isset( $shipment['postage_label']['label_size'] ) ? (string) $shipment['postage_label']['label_size'] : '',
            'tracking_url'       => $tracking_url,
            // EasyPost's own status vocabulary, upper-cased to match Shippo's.
            'status'             => isset( $shipment['status'] ) ? strtoupper( trim( (string) $shipment['status'] ) ) : '',
            'refund_status'      => $this->refund_status( $shipment ),
            'service_name'       => '' !== $name ? $name : (string) ( $rate['service_name'] ?? '' ),
            'total_charge'       => $charge,
            'currency'           => $currency,
            'insurance'          => isset( $shipment['insurance'] ) ? (string) $shipment['insurance'] : '',
            // What the cover itself cost. Empty when no insurance was bought; on its
            // own so the order can say "insured for X, fee Y" instead of only a total.
            'insurance_fee'      => $this->fee_by_type( $shipment, 'InsuranceFee' ),
            'fees'               => isset( $shipment['fees'] ) && is_array( $shipment['fees'] ) ? $shipment['fees'] : [],
        ];
    }

    /**
     * What the buy actually charged, from `fees[]` — postage plus insurance.
     *
     * Returns '' (not 0.00) when nothing usable came back, so a caller can never
     * record a misleading zero.
     *
     * @param array $shipment Shipment object.
     * @return string Amount, 2dp, or ''.
     */
    protected function fees_total( array $shipment ) {
        if ( empty( $shipment['fees'] ) || ! is_array( $shipment['fees'] ) ) {
            return '';
        }

        $charged = 0.0;
        $seen    = false;
        foreach ( $shipment['fees'] as $fee ) {
            if ( ! is_array( $fee ) || ! isset( $fee['amount'] ) || ! is_numeric( $fee['amount'] ) ) {
                continue;
            }
            if ( isset( $fee['charged'] ) && false === $fee['charged'] ) {
                continue;
            }
            $type = isset( $fee['type'] ) ? (string) $fee['type'] : '';
            // Only the label and its insurance; EasyPost can list unrelated fees
            // on the account, which are not part of this label's cost.
            //
            // EasyPost's fee docs enumerate exactly two types, `PostageFee` and
            // `InsuranceFee` (docs.easypost.com/docs/fees). Some responses spell the
            // postage one `PostageLabelFee`. Both are accepted because getting this
            // wrong is expensive in a quiet way: an insured buy whose postage fee is
            // not recognised records *only the premium* as the label's total cost.
            if ( ! in_array( $type, [ 'PostageFee', 'PostageLabelFee', 'InsuranceFee' ], true ) ) {
                continue;
            }
            $charged += (float) $fee['amount'];
            $seen     = true;
        }

        if ( ! $seen || $charged <= 0 ) {
            return '';
        }

        return number_format( round( $charged, 2 ), 2, '.', '' );
    }

    /**
     * Total for one fee type, or '' when that fee is absent.
     *
     * Kept separate from fees_total() so the premium can be shown on its own: the
     * operator needs to see that cover was bought and what it cost, not just a
     * combined figure.
     *
     * @param array  $shipment Shipment object after the buy.
     * @param string $type     Fee type, e.g. InsuranceFee.
     * @return string Amount, 2dp, or ''.
     */
    protected function fee_by_type( array $shipment, $type ) {
        if ( empty( $shipment['fees'] ) || ! is_array( $shipment['fees'] ) ) {
            return '';
        }

        $sum  = 0.0;
        $seen = false;
        foreach ( $shipment['fees'] as $fee ) {
            if ( ! is_array( $fee ) || ! isset( $fee['amount'] ) || ! is_numeric( $fee['amount'] ) ) {
                continue;
            }
            if ( isset( $fee['charged'] ) && false === $fee['charged'] ) {
                continue;
            }
            if ( $type !== ( isset( $fee['type'] ) ? (string) $fee['type'] : '' ) ) {
                continue;
            }
            $sum += (float) $fee['amount'];
            $seen = true;
        }

        if ( ! $seen ) {
            return '';
        }

        return number_format( round( $sum, 2 ), 2, '.', '' );
    }

    /**
     * A field on the shipment's `postage_label`, as a trimmed string.
     *
     * @param array  $shipment Shipment object.
     * @param string $field    Field name.
     * @return string
     */
    protected function postage_label_field( array $shipment, $field ) {
        if ( empty( $shipment['postage_label'] ) || ! is_array( $shipment['postage_label'] ) ) {
            return '';
        }
        if ( ! isset( $shipment['postage_label'][ $field ] ) || ! is_string( $shipment['postage_label'][ $field ] ) ) {
            return '';
        }

        return trim( $shipment['postage_label'][ $field ] );
    }

    /**
     * Does this URL point at a PDF?
     *
     * @param string $url URL.
     * @return bool
     */
    protected function is_pdf_url( $url ) {
        return '' !== (string) $url && false !== stripos( (string) $url, '.pdf' );
    }

    /**
     * The shipment's refund state, lower-cased: '', submitted, refunded, rejected.
     *
     * @param array $shipment Shipment object.
     * @return string
     */
    protected function refund_status( array $shipment ) {
        return isset( $shipment['refund_status'] ) ? strtolower( trim( (string) $shipment['refund_status'] ) ) : '';
    }

    /* -- Printing --------------------------------------------------------- */

    /**
     * Label PDF bytes, cropped to a 4x6 page when the carrier's page is taller.
     *
     * EasyPost returns UPS labels on a 288 x 504 pt page (4x7) whose bottom 72 pt is
     * blank — their own docs: "just a 4x6 with 1 or 2 inches of blank space". A printer
     * with 4x6 media "fits" the 7-inch page into 6 inches, which shrinks the whole
     * label by 14%. Moving the page box up by the blank amount makes it a true 4x6 at
     * 100%, with no re-encoding and no image resampling.
     *
     * Anything unexpected in the PDF means the original bytes are returned unchanged:
     * printing must never depend on this succeeding.
     *
     * @param string $id Shipment id.
     * @return array|WP_Error ['bytes' => string, 'cropped' => bool]
     */
    public function get_printable_label_pdf( $id ) {
        $url = $this->get_label_pdf_url( $id );
        if ( is_wp_error( $url ) ) {
            return $url;
        }

        $response = wp_remote_get(
            $url,
            [
                'headers' => [ 'Accept' => 'application/pdf' ],
                'timeout' => (float) self::TIMEOUT_TRANSFER,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'easypost_label_download_failed', $response->get_error_message() );
        }

        $code  = (int) wp_remote_retrieve_response_code( $response );
        $bytes = (string) wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 || '' === $bytes ) {
            return new WP_Error(
                'easypost_label_download_failed',
                sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'Could not download the label PDF from EasyPost (HTTP %d).', 'shipping-desk' ),
                    $code
                )
            );
        }

        list( $cropped, $changed ) = $this->crop_label_page( $bytes );

        return [
            'bytes'   => $cropped,
            'cropped' => $changed,
        ];
    }

    /**
     * Crop a label PDF's page box to 4x6, in place.
     *
     * Two shapes need this:
     *
     * - **4x7 / 4x8 (UPS)**: the artwork is a true 4x6 with blank space below, so
     *   lifting the bottom edge by the blank height is exact.
     * - **US letter (FedEx)**: `label_size=4x6` is echoed back but ignored — the page
     *   is 612x792 with the label drawn at true size, inset near the top-left. A
     *   letter page handed to a 4x6 printer is fitted, which shrinks the label to
     *   roughly a quarter of the stock. The artwork's extent is read out of the
     *   content stream and a 4x6 window is centred on it, so it prints at 100%.
     *
     * Any other page is returned untouched: cropping on a guess could cut a barcode
     * in half, while an uncropped label still prints.
     *
     * @param string $bytes PDF bytes.
     * @return array [ bytes, cropped? ]
     */
    protected function crop_label_page( $bytes ) {
        $bytes = (string) $bytes;

        if ( 0 !== strpos( $bytes, '%PDF' ) ) {
            return [ $bytes, false ];
        }
        // One page only: with several pages the box change would apply to all of them.
        if ( 1 !== preg_match_all( '#/Type\s*/Page[^s]#', $bytes ) ) {
            return [ $bytes, false ];
        }
        // A CropBox would take precedence over the MediaBox in most renderers.
        if ( false !== strpos( $bytes, '/CropBox' ) ) {
            return [ $bytes, false ];
        }

        if ( ! preg_match( '#/MediaBox\s*\[\s*(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s*\]#', $bytes, $m, PREG_OFFSET_CAPTURE ) ) {
            return [ $bytes, false ];
        }

        $x0 = (float) $m[1][0];
        $y0 = (float) $m[2][0];
        $x1 = (float) $m[3][0];
        $y1 = (float) $m[4][0];

        $width  = $x1 - $x0;
        $height = $y1 - $y0;

        if ( (float) self::LABEL_WIDTH === $width && $height > self::LABEL_HEIGHT && $height <= ( 8 * 72 ) ) {
            // The blank strip is at the bottom and PDF's origin is bottom-left, so
            // the label keeps the top edge: 72 pt of blank for 4x7, 144 pt for 4x8.
            $target = [ $x0, $y1 - self::LABEL_HEIGHT, $x1, $y1 ];
        } elseif ( (float) self::LETTER_WIDTH === $width && (float) self::LETTER_HEIGHT === $height ) {
            $target = $this->letter_label_window( $bytes, $width, $height );
            if ( null === $target ) {
                return [ $bytes, false ];
            }
        } else {
            return [ $bytes, false ];
        }

        $old = $m[0][0];
        $new = '/MediaBox [' . $this->pdf_number( $target[0] ) . ' ' . $this->pdf_number( $target[1] )
            . ' ' . $this->pdf_number( $target[2] ) . ' ' . $this->pdf_number( $target[3] ) . ']';

        if ( strlen( $new ) <= strlen( $old ) ) {
            // Pad inside the brackets: PDF arrays ignore whitespace. Keeping the
            // length means every xref offset stays correct.
            $replacement = strlen( $new ) === strlen( $old )
                ? $new
                : substr( $new, 0, -1 ) . str_repeat( ' ', strlen( $old ) - strlen( $new ) ) . ']';
            $cropped     = substr( $bytes, 0, $m[0][1] ) . $replacement . substr( $bytes, $m[0][1] + strlen( $old ) );
        } else {
            // A window on a letter page needs more bytes than "0 0 612 792", so the
            // cross-reference table is rebuilt instead of padded.
            $cropped = $this->pdf_replace_mediabox( $bytes, $m[0][1], strlen( $old ), $new );
            if ( null === $cropped ) {
                return [ $bytes, false ];
            }
        }

        if ( ! $this->pdf_xref_is_valid( $cropped ) ) {
            return [ $bytes, false ];
        }

        return [ $cropped, true ];
    }

    /**
     * The 4x6 window to keep on a US letter page, or null when it cannot be placed
     * safely.
     *
     * @param string $bytes  PDF bytes.
     * @param float  $width  Page width.
     * @param float  $height Page height.
     * @return array|null [ x0, y0, x1, y1 ]
     */
    protected function letter_label_window( $bytes, $width, $height ) {
        $box = $this->pdf_artwork_box( $bytes );
        if ( null === $box ) {
            return null;
        }

        list( $min_x, $min_y, $max_x, $max_y ) = $box;

        $art_width  = $max_x - $min_x;
        $art_height = $max_y - $min_y;

        // Wider than the stock would have to be clipped; much smaller than a label
        // means the parse missed most of the artwork. Either way, do nothing.
        if ( $art_width > self::LABEL_WIDTH || $art_height > self::LABEL_HEIGHT ) {
            return null;
        }
        if ( $art_width < 200 || $art_height < 300 ) {
            return null;
        }

        $window_x = $min_x + ( $art_width - self::LABEL_WIDTH ) / 2;
        $window_y = $min_y + ( $art_height - self::LABEL_HEIGHT ) / 2;

        // Keep the window on the page.
        $window_x = max( 0.0, min( $window_x, $width - self::LABEL_WIDTH ) );
        $window_y = max( 0.0, min( $window_y, $height - self::LABEL_HEIGHT ) );

        $window_x = round( $window_x );
        $window_y = round( $window_y );

        return [ $window_x, $window_y, $window_x + self::LABEL_WIDTH, $window_y + self::LABEL_HEIGHT ];
    }

    /**
     * The extent of everything drawn on the page, read from the content stream.
     *
     * Rectangles, text origins and placed-object offsets are enough for a carrier
     * label: they are what puts ink on the page. A PDF that hides its content in an
     * object stream, or draws with operators not covered here, yields an extent that
     * is too small — which the caller rejects.
     *
     * @param string $bytes PDF bytes.
     * @return array|null [ min_x, min_y, max_x, max_y ]
     */
    protected function pdf_artwork_box( $bytes ) {
        preg_match_all( '#(\d+)\s+0\s+obj(.*?)endobj#s', $bytes, $objects, PREG_SET_ORDER );

        $min_x = null;
        $min_y = null;
        $max_x = null;
        $max_y = null;

        foreach ( $objects as $object ) {
            $dict = $object[2];

            if ( false !== strpos( $dict, '/Image' ) || false !== strpos( $dict, '/Font' ) || false !== strpos( $dict, '/ObjStm' ) ) {
                continue;
            }
            if ( ! preg_match( '#stream\r?\n(.*?)\r?\nendstream#s', $dict, $stream ) ) {
                continue;
            }

            $content = $this->pdf_stream_text( $stream[1], $dict );
            if ( '' === $content ) {
                continue;
            }

            $points = [];

            // `x y w h re` — rules, boxes, the barcode's white background.
            if ( preg_match_all( '#(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+re\b#', $content, $rects, PREG_SET_ORDER ) ) {
                foreach ( $rects as $rect ) {
                    $rx       = (float) $rect[1];
                    $ry       = (float) $rect[2];
                    $points[] = [ $rx, $ry ];
                    $points[] = [ $rx + (float) $rect[3], $ry + (float) $rect[4] ];
                }
            }

            // `a b c d e f Tm` — where a text run starts. Glyphs extend up and right
            // from there; the window's margin absorbs the difference.
            if ( preg_match_all( '#(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+Tm\b#', $content, $texts, PREG_SET_ORDER ) ) {
                foreach ( $texts as $text ) {
                    $points[] = [ (float) $text[5], (float) $text[6] ];
                }
            }

            // `a b c d e f cm` — where a placed object (the barcode image) sits.
            if ( preg_match_all( '#(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+cm\b#', $content, $transforms, PREG_SET_ORDER ) ) {
                foreach ( $transforms as $transform ) {
                    $points[] = [ (float) $transform[5], (float) $transform[6] ];
                }
            }

            foreach ( $points as $point ) {
                $min_x = null === $min_x ? $point[0] : min( $min_x, $point[0] );
                $min_y = null === $min_y ? $point[1] : min( $min_y, $point[1] );
                $max_x = null === $max_x ? $point[0] : max( $max_x, $point[0] );
                $max_y = null === $max_y ? $point[1] : max( $max_y, $point[1] );
            }
        }

        if ( null === $min_x ) {
            return null;
        }

        return [ $min_x, $min_y, $max_x, $max_y ];
    }

    /**
     * Decode a PDF stream with the filters it declares (ASCII85, then Flate).
     *
     * @param string $body Stream bytes.
     * @param string $dict Object dictionary.
     * @return string Empty when the filters are unsupported or the data is corrupt.
     */
    protected function pdf_stream_text( $body, $dict ) {
        $body = (string) $body;

        if ( false !== strpos( $dict, '/ASCII85Decode' ) ) {
            // ASCII85 data is wrapped across lines and may contain whitespace.
            $body = preg_replace( '/\s+/', '', $body );
            if ( '~>' === substr( $body, -2 ) ) {
                $body = substr( $body, 0, -2 );
            }

            $decoded = '';
            $length  = strlen( $body );
            $index   = 0;

            while ( $index < $length ) {
                if ( 'z' === $body[ $index ] ) {
                    $decoded .= "\0\0\0\0";
                    $index++;
                    continue;
                }

                $chunk = substr( $body, $index, 5 );
                $pad   = 5 - strlen( $chunk );
                $chunk = str_pad( $chunk, 5, 'u' );

                $value = 0;
                for ( $j = 0; $j < 5; $j++ ) {
                    $value = ( $value * 85 ) + ( ord( $chunk[ $j ] ) - 33 );
                }

                $quad     = chr( ( $value >> 24 ) & 0xFF ) . chr( ( $value >> 16 ) & 0xFF )
                    . chr( ( $value >> 8 ) & 0xFF ) . chr( $value & 0xFF );
                $decoded .= 0 === $pad ? $quad : substr( $quad, 0, 4 - $pad );

                $index += 5;
            }

            $body = $decoded;
        }

        if ( false !== strpos( $dict, '/FlateDecode' ) ) {
            $inflated = @gzuncompress( $body );
            if ( false === $inflated ) {
                $inflated = @gzinflate( $body );
            }
            if ( false === $inflated ) {
                return '';
            }
            $body = $inflated;
        }

        // Anything that is not text would put binary into the regexes below.
        if ( ! preg_match( '#^[\x09\x0A\x0D\x20-\x7E]*$#', $body ) ) {
            return '';
        }

        return $body;
    }

    /**
     * Every in-use xref entry still points at its object.
     *
     * The length is preserved by construction, so this should never fail — it is here
     * because a corrupt PDF is worse than an uncropped one.
     *
     * @param string $pdf PDF bytes.
     * @return bool
     */
    protected function pdf_xref_is_valid( $pdf ) {
        if ( ! preg_match( '#startxref\s+(\d+)#', $pdf, $m ) ) {
            return false;
        }

        $offset = (int) $m[1];
        if ( 4 !== strlen( substr( $pdf, $offset, 4 ) ) || 'xref' !== substr( $pdf, $offset, 4 ) ) {
            return false;
        }
        if ( ! preg_match( '#xref\s+(\d+)\s+(\d+)\s*#', substr( $pdf, $offset, 64 ), $header ) ) {
            return false;
        }

        $first = (int) $header[1];
        $count = (int) $header[2];
        $table = substr( $pdf, $offset + strlen( $header[0] ), $count * 20 );

        if ( strlen( $table ) < $count * 20 ) {
            return false;
        }

        for ( $i = 0; $i < $count; $i++ ) {
            $entry = substr( $table, $i * 20, 20 );
            if ( ! preg_match( '#^(\d{10}) (\d{5}) ([nf])#', $entry, $e ) ) {
                return false;
            }
            if ( 'n' !== $e[3] ) {
                continue;
            }
            $number = $first + $i;
            $target = (int) $e[1];
            if ( substr( $pdf, $target, strlen( $number . ' 0 obj' ) ) !== $number . ' 0 obj' ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Replace the page box and rebuild the cross-reference table.
     *
     * A PDF's xref stores absolute byte offsets, so a replacement of a different
     * length moves every object after it. Objects before the patch keep their
     * offset; everything after shifts by the length difference — which is exact,
     * unlike re-scanning the body for `N 0 obj`.
     *
     * @param string $bytes   PDF bytes.
     * @param int    $offset  Offset of the old page box.
     * @param int    $length  Length of the old page box.
     * @param string $new     Replacement text.
     * @return string|null Null when the file cannot be rebuilt safely.
     */
    protected function pdf_replace_mediabox( $bytes, $offset, $length, $new ) {
        $patched = substr( $bytes, 0, $offset ) . $new . substr( $bytes, $offset + $length );

        return $this->pdf_rebuild_xref( $patched, $offset, strlen( $new ) - $length );
    }

    /**
     * Rewrite a classic xref table after the body changed length.
     *
     * @param string $pdf    PDF bytes.
     * @param int    $patch  Offset where the body changed.
     * @param int    $delta  How many bytes the change added (may be negative).
     * @return string|null Null for anything but a classic table: an xref stream is
     *                     left alone rather than half-rewritten.
     */
    protected function pdf_rebuild_xref( $pdf, $patch = 0, $delta = 0 ) {
        // The stored startxref offset cannot be trusted here: the caller has already
        // changed the body length, so it points into the middle of the table. The
        // table always begins on its own line.
        $marker = strrpos( $pdf, "\nxref" );
        if ( false === $marker ) {
            return null;
        }

        $start = $marker + 1;
        if ( 'xref' !== substr( $pdf, $start, 4 ) ) {
            return null;
        }

        $tail = substr( $pdf, $start );
        if ( ! preg_match( '#^xref\s+(\d+)\s+(\d+)\s*#', $tail, $header ) ) {
            return null;
        }

        $first = (int) $header[1];
        $count = (int) $header[2];
        $body  = substr( $pdf, 0, $start );

        // Do not compute entry width: entries are 20 bytes only with single-character
        // line endings. Find the trailer, then read the entries as a sequence.
        $trailer_at = strpos( $tail, 'trailer', strlen( $header[0] ) );
        if ( false === $trailer_at ) {
            return null;
        }
        if ( ! preg_match( '#^trailer\s*<<(.*?)>>#s', substr( $tail, $trailer_at ), $trailer ) ) {
            return null;
        }

        $entries_at = strlen( $header[0] );
        $entries    = substr( $tail, $entries_at, $trailer_at - $entries_at );
        if ( ! preg_match_all( '#(\d{10})\s(\d{5})\s([nf])#', $entries, $rows, PREG_SET_ORDER ) ) {
            return null;
        }
        if ( count( $rows ) !== $count ) {
            return null;
        }

        $out = $body . 'xref' . PHP_EOL . $first . ' ' . $count . PHP_EOL;

        foreach ( $rows as $index => $row ) {
            $number = $first + $index;
            if ( 'n' !== $row[3] ) {
                $out .= "0000000000 65535 f \n";
                continue;
            }
            $object_at = (int) $row[1];
            if ( $object_at >= $patch ) {
                $object_at += $delta;
            }
            if ( $object_at < 0 ) {
                return null;
            }
            $out .= sprintf( "%010d 00000 n \n", $object_at );
        }

        $out .= 'trailer' . PHP_EOL . '<<' . $trailer[1] . '>>' . PHP_EOL
            . 'startxref' . PHP_EOL . strlen( $body ) . PHP_EOL . '%%EOF' . PHP_EOL;

        return $out;
    }

    /**
     * A PDF number without trailing zeroes: 0.0 -> "0", 288.0 -> "288".
     *
     * @param float $value Value.
     * @return string
     */
    protected function pdf_number( $value ) {
        $value = (float) $value;
        if ( (float) (int) $value === $value ) {
            return (string) (int) $value;
        }

        return rtrim( rtrim( sprintf( '%.4F', $value ), '0' ), '.' );
    }

    /* -- VAS, retries and timing ----------------------------------------- */

    /**
     * Declared value from the UI → the `insurance` string EasyPost wants.
     *
     * Returns '' when no cover was asked for, a WP_Error when the amount is
     * outside EasyPost's own limits — checked here, so the operator gets a plain
     * sentence instead of a 422 after the label was otherwise ready.
     *
     * @param array $vas Value-added services from the UI.
     * @return string|WP_Error
     */
    protected function insurance_amount( array $vas ) {
        $raw = null;
        if ( isset( $vas['liability_amount'] ) ) {
            $raw = $vas['liability_amount'];
        } elseif ( isset( $vas['insurance'] ) ) {
            $raw = $vas['insurance'];
        }

        if ( null === $raw || ! is_numeric( $raw ) ) {
            return '';
        }

        $amount = round( (float) $raw, 2 );
        if ( $amount <= 0 ) {
            return '';
        }

        if ( $amount > self::MAX_INSURANCE_AMOUNT ) {
            return new WP_Error(
                'easypost_insurance_too_high',
                sprintf(
                    /* translators: %s: maximum insured amount, e.g. 5,000.00 */
                    __( 'EasyPost insures a maximum of $%s per shipment. Lower the declared value or leave insurance off.', 'shipping-desk' ),
                    number_format( self::MAX_INSURANCE_AMOUNT, 2, '.', '' )
                )
            );
        }

        return number_format( $amount, 2, '.', '' );
    }

    /**
     * Order reference. The client never loads the WooCommerce order, so this is
     * the order ID the caller passed.
     *
     * @param int|string $order_id Order ID.
     * @return string
     */
    protected function order_reference( $order_id ) {
        return trim( (string) $order_id );
    }

    /**
     * Order ID out of a bulk shipment descriptor. The plugin passes it as the
     * single entry of `custom_messages` (the Veeqo convention).
     *
     * @param array $shipment Descriptor.
     * @return string
     */
    protected function shipment_order_id( array $shipment ) {
        if ( isset( $shipment['order_id'] ) && is_scalar( $shipment['order_id'] ) ) {
            return (string) $shipment['order_id'];
        }
        if ( isset( $shipment['custom_messages'] ) && is_array( $shipment['custom_messages'] ) && ! empty( $shipment['custom_messages'] ) ) {
            $first = reset( $shipment['custom_messages'] );
            if ( is_scalar( $first ) ) {
                return (string) $first;
            }
        }
        if ( isset( $shipment['custom_messages'] ) && is_scalar( $shipment['custom_messages'] ) ) {
            return (string) $shipment['custom_messages'];
        }
        return '';
    }

    /**
     * Value-added services out of a bulk shipment descriptor. The plugin
     * flattens them alongside the reserved keys rather than nesting them.
     *
     * @param array $shipment Descriptor.
     * @return array
     */
    protected function shipment_vas( array $shipment ) {
        $reserved = [ 'remote_shipment_id', 'rate_id', 'rate', 'order_id', 'custom_messages' ];
        $vas      = [];

        foreach ( $shipment as $key => $value ) {
            if ( ! is_string( $key ) || in_array( $key, $reserved, true ) ) {
                continue;
            }
            $vas[ $key ] = $value;
        }

        return $vas;
    }

    /**
     * True when the HTTP layer failed in a way that leaves the outcome unknown.
     *
     * A timeout is not a failure: the request reached EasyPost and the label may
     * already exist.
     *
     * @param mixed $error WP_Error.
     * @return bool
     */
    protected function is_timeout( $error ) {
        if ( ! is_wp_error( $error ) ) {
            return false;
        }
        $message = strtolower( $error->get_error_message() );
        return strpos( $message, 'timed out' ) !== false
            || strpos( $message, 'timeout' ) !== false
            || strpos( $message, 'curl error 28' ) !== false;
    }

    /**
     * Whether the caller may buy again without risking a double charge.
     *
     * @param mixed $error WP_Error.
     * @return bool
     */
    protected function is_retry_safe( $error ) {
        if ( ! is_wp_error( $error ) || $this->is_timeout( $error ) ) {
            return false;
        }
        if ( method_exists( $error, 'get_error_data' ) ) {
            $data = $error->get_error_data();
            if ( is_array( $data ) && array_key_exists( 'retry_safe', $data ) ) {
                return (bool) $data['retry_safe'];
            }
        }

        return false;
    }

    /**
     * Did EasyPost reject a shipment option rather than the shipment itself?
     *
     * Only that case is worth a retry without the options: a bad address or an
     * unconfigured carrier would fail identically a second time.
     *
     * @param mixed $error Error from the first attempt.
     * @return bool
     */
    protected function is_option_rejection( $error ) {
        if ( ! is_wp_error( $error ) || 'easypost_invalid_request' !== $error->get_error_code() ) {
            return false;
        }
        $message = strtolower( $error->get_error_message() );
        foreach ( [ 'label_date', 'label_size', 'label_format', 'print_custom' ] as $option ) {
            if ( false !== strpos( $message, $option ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Base spacing for this key: a test key is paced to the tighter limit.
     *
     * @return int Microseconds.
     */
    protected function base_spacing_us() {
        if ( class_exists( 'Shipping_Desk_Helpers' ) ) {
            return (int) Shipping_Desk_Helpers::bulk_spacing_us( 'easypost', $this->api_key );
        }

        return self::BULK_THROTTLE_US;
    }

    /**
     * Spacing to leave before the next purchase.
     *
     * @return int Microseconds.
     */
    protected function spacing_us() {
        if ( null === $this->spacing_us ) {
            $this->spacing_us = $this->base_spacing_us();
        }

        return (int) $this->spacing_us;
    }

    /**
     * A 429 means the current pace is too fast: double it for the rest of the run.
     *
     * Retrying the one failed call at the same pace is how a limiter becomes a loop
     * of 429s, so the whole run slows down, not just the request that was refused.
     *
     * @return void
     */
    protected function widen_spacing() {
        $cap = class_exists( 'Shipping_Desk_Helpers' )
            ? (int) Shipping_Desk_Helpers::bulk_spacing_cap_us()
            : ( 8 * 1000000 );

        $this->spacing_us = min( $cap, max( $this->base_spacing_us(), $this->spacing_us() * 2 ) );
    }

    /**
     * A success means there is room again: ease back towards the base spacing.
     *
     * @return void
     */
    protected function ease_spacing() {
        $current = $this->spacing_us();
        $base    = $this->base_spacing_us();

        if ( $current > $base ) {
            $this->spacing_us = max( $base, (int) ( $current * 0.9 ) );
        }
    }

    /**
     * Space out bulk purchases.
     *
     * @param int $microseconds Delay.
     * @return void
     */
    protected function throttle( $microseconds ) {
        if ( $microseconds > 0 ) {
            usleep( (int) $microseconds );
        }
    }

    /**
     * Sleep between retries. Separate from throttle() so the gate can override it.
     *
     * @param int $seconds Delay.
     * @return void
     */
    protected function pause( $seconds ) {
        $seconds = (int) ceil( (float) $seconds );
        if ( $seconds > 0 ) {
            sleep( $seconds );
        }
    }

    /* -- Dispatch date --------------------------------------------------- */

    /**
     * The dispatch date EasyPost should print, as an ISO 8601 timestamp with its
     * offset.
     *
     * Sent as `options.label_date`. At local noon so no timezone conversion can
     * move the calendar day.
     *
     * @return string
     */
    protected function label_date() {
        return $this->dispatch_date()
            ->setTime( self::DISPATCH_HOUR, 0, 0 )
            ->format( self::LABEL_DATE_FORMAT );
    }

    /**
     * Date a parcel handed over right now would be tendered.
     *
     * One rule for every carrier: before the 4pm Eastern cutoff on a weekday the
     * label is dated today, otherwise the next weekday. Weekends always roll to
     * Monday, so a Saturday or Sunday date is never produced — the date is only
     * ever pushed forward, never back.
     *
     * @param DateTimeImmutable|null $now Moment to evaluate.
     * @return DateTimeImmutable
     */
    protected function dispatch_date( $now = null ) {
        $now = $now instanceof DateTimeImmutable ? $now : $this->now();

        // 1 = Monday … 6 = Saturday, 7 = Sunday.
        if ( (int) $now->format( 'N' ) >= 6 ) {
            return $this->next_workday( $now );
        }

        return $now > $this->cutoff( self::DISPATCH_CUTOFF, $now ) ? $this->next_workday( $now ) : $now;
    }

    /**
     * Now, pinned to Eastern rather than trusting the server's date.timezone.
     * Overridable so the gate can evaluate a fixed moment.
     *
     * @return DateTimeImmutable
     */
    protected function now() {
        return new DateTimeImmutable( 'now', new DateTimeZone( self::DISPATCH_TIMEZONE ) );
    }

    /**
     * The next Monday to Friday.
     *
     * @param DateTimeImmutable $now Moment.
     * @return DateTimeImmutable
     */
    protected function next_workday( DateTimeImmutable $now ) {
        $next = $now->modify( '+1 day' );
        while ( (int) $next->format( 'N' ) >= 6 ) {
            $next = $next->modify( '+1 day' );
        }
        return $next;
    }

    /**
     * A cutoff on the given day, e.g. '16:00'.
     *
     * @param string            $hhmm Cutoff.
     * @param DateTimeImmutable $day  Day.
     * @return DateTimeImmutable
     */
    protected function cutoff( $hhmm, DateTimeImmutable $day ) {
        $parts = explode( ':', (string) $hhmm );
        return $day->setTime( (int) $parts[0], (int) ( $parts[1] ?? 0 ), 0 );
    }
}
