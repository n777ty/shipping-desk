<?php
/**
 * Shippo Shipping API client.
 *
 * A raw wp_remote_* implementation of the provider contract — no SDK and no
 * Composer dependencies. Mirrors Shipping_Desk_Veeqo_Client in style: public
 * contract methods first, transport helpers after.
 *
 * Shippo behaviour this client enforces (see docs/shippo-migration.md):
 *
 *  - `async: false` on shipments, transactions and refunds. Omit it and Shippo
 *    answers with a success response carrying no rates and no label.
 *  - `label_file_type: PDF_4x6`. Plain `PDF` is 8.5x11 with the label rotated
 *    inside it, which prints wrongly on a 4x6 thermal printer.
 *  - `extra.reference_1` = order number, truncated to 30 characters (the
 *    smallest carrier limit — USPS 30, UPS 35). `reference_2` stays unused.
 *  - Insurance is always XCover: send amount/content/currency and **no**
 *    `provider`; sending one is rejected with "Invalid insurance 'provider'
 *    code provided".
 *  - `signature_confirmation` limited to STANDARD and ADULT. The other enums
 *    silently restrict the rate list to a single carrier without an error.
 *  - A timeout is not a failure — the label may already be bought. Purchases
 *    are reconciled before a retry is reported as safe.
 *  - HTTP 429 is retried with backoff, and bulk purchases are throttled.
 *  - `shipment_date` is the date the parcel is tendered to the carrier. It can
 *    only be set while quoting rates (the transaction takes a rate id alone), so
 *    it is the next 4pm Eastern weekday cutoff and never a Saturday or Sunday.
 *    It must be a full ISO-8601 timestamp — Shippo rejects a bare date outright
 *    (see SHIPMENT_DATE_FORMAT).
 *  - Address validation (V2) is advisory and never fails a quote: an unreachable,
 *    throttled or unprovisioned validator answers `unavailable` and the address
 *    is used exactly as entered.
 *
 * Identifier mapping (Veeqo concept → Shippo concept):
 *
 *   remote_shipment_id → shipment `object_id`  (get_shipment())
 *   label_id           → transaction `object_id` (refund_shipment(),
 *                          get_label_url() — the label lives on the transaction,
 *                          and its signed URL expires)
 */
if ( ! interface_exists( 'Shipping_Desk_Provider_Interface' ) ) {
    require_once __DIR__ . '/class-provider-interface.php';
}
if ( ! interface_exists( 'Shipping_Desk_Address_Validator_Interface' ) ) {
    require_once __DIR__ . '/class-address-validator-interface.php';
}

class Shipping_Desk_Shippo_Client implements Shipping_Desk_Provider_Interface, Shipping_Desk_Address_Validator_Interface {

    const BASE_URL = 'https://api.goshippo.com';

    const API_VERSION = '2018-02-08';

    /** 4x6 thermal label. Never plain `PDF` — see the class docblock. */
    const LABEL_FILE_TYPE = 'PDF_4x6';

    /** Smallest carrier limit for `extra.reference_1` (USPS). UPS allows 35. */
    const REFERENCE_MAX_LENGTH = 30;

    /** The only signature enums we send. Others silently drop carriers. */
    const SIGNATURE_OPTIONS = [ 'STANDARD', 'ADULT' ];

    /**
     * Insurance `content`. Fixed text: XCover only needs a short description of
     * the insured goods, so there is no setting for it.
     */
    const INSURANCE_CONTENT = 'Merchandise';

    /** Explicit timeouts, in seconds. */
    const TIMEOUT_RATES       = 30;
    const TIMEOUT_TRANSACTION = 60;
    const TIMEOUT_REFUND      = 45;
    const TIMEOUT_READ        = 20;

    /**
     * Address validation is a side quest on the way to a quote, so it gets a
     * short leash: when it is slow the answer is "unavailable" and the address
     * is used as entered, rather than making the operator wait.
     */
    const TIMEOUT_VALIDATE = 10;

    /** Shippo V2 address validation. Query string, not a JSON body. */
    const ADDRESS_VALIDATE_PATH = '/v2/addresses/validate';

    /** HTTP 429 backoff: attempts, and the base delay in seconds. */
    const MAX_ATTEMPTS     = 3;
    const RETRY_BASE_DELAY = 1;

    /** Polling a transaction that Shippo answered as WAITING/QUEUED. */
    const POLL_ATTEMPTS = 5;
    const POLL_INTERVAL = 2;

    /** Reconciling a purchase whose HTTP response never arrived. */
    const RECONCILE_ATTEMPTS  = 2;
    const RECONCILE_INTERVAL  = 2;
    const RECONCILE_WINDOW    = 1800;
    const RECONCILE_PAGE_SIZE = 100;

    /** Shippo caps `metadata` at 100 characters. */
    const METADATA_MAX_LENGTH = 100;

    /**
     * Fallback spacing between purchases, in microseconds.
     *
     * The live value comes from `Shipping_Desk_Helpers::bulk_spacing_us()`, which
     * paces a test key to Shippo's tighter limit (50 POSTs/minute against 500 live);
     * this constant is only used when the helper is not loaded.
     */
    const BULK_THROTTLE_US = 350000;

    /**
     * Dispatch cutoff, in the store's local time. The server clock is not trusted
     * to be Eastern, so the timezone is pinned here.
     *
     * One cutoff for every carrier: a date further in the future is preferable to
     * a stale one (USPS can object to a date too far in the past), and no label
     * is ever dated to a Saturday or Sunday.
     */
    const DISPATCH_TIMEZONE = 'America/New_York';
    const DISPATCH_CUTOFF   = '16:00';

    /** Dispatch dates are sent at local noon so no timezone can shift the day. */
    const DISPATCH_HOUR = 12;

    /**
     * Wire format for `shipment_date`.
     *
     * A full ISO-8601 timestamp, and it has to be: Shippo validates this field as
     * a DRF `DateTimeField` and answers a bare `Y-m-d` with
     * "Datetime has wrong format. Use one of these formats instead:
     * YYYY-MM-DDThh:mm[:ss[.uuuuuu]][+HH:MM|-HH:MM|Z]" — a 400 on the shipment,
     * which means **no rates at all** (0.9.45 shipped exactly that mistake).
     *
     * UPS then logs `121943 Invalid Date. Changed to today's date` because it
     * wants a plain date, so UPS labels are always dated the day they are bought.
     * That is not fixable through this field: one string has to satisfy Shippo's
     * parser and UPS's, and no string satisfies both. USPS does honour the date.
     */
    const SHIPMENT_DATE_FORMAT = 'Y-m-d\TH:i:s\Z';

    /** @var string */
    protected $api_key;

    /** Spacing for this run, in microseconds; widened by 429s. Null until needed. */
    protected $spacing_us = null;

    /**
     * @param string|null $api_key Shippo API key.
     *
     * The plugin always passes the key for the active mode, so this never falls
     * back to reading an option itself — that would be a second place to get the
     * test/production choice wrong. A null key means "not set", and the first
     * request fails with shippo_no_api_key.
     */
    public function __construct( $api_key = null ) {
        $this->api_key = null === $api_key ? '' : trim( (string) $api_key );
    }

    public function get_api_key() {
        if ( '' === $this->api_key ) {
            return new WP_Error( 'shippo_no_api_key', __( 'Shippo API key is not set.', 'shipping-desk' ) );
        }
        return $this->api_key;
    }

    /**
     * Fetch rates via POST /shipments.
     *
     * Shippo has no rates endpoint: the shipment itself is priced, and the
     * returned `shipment.object_id` is the parent handle used by book_shipment().
     *
     * @param array $payload Normalised shipment request.
     * @return array|WP_Error
     */
    public function get_rates( array $payload ) {
        $body = $this->build_shipment_body( $payload );

        $shipment = $this->request( 'POST', '/shipments', $body, self::TIMEOUT_RATES );
        if ( is_wp_error( $shipment ) ) {
            return $shipment;
        }
        if ( empty( $shipment['object_id'] ) ) {
            return new WP_Error(
                'shippo_error',
                __( 'Shippo did not return a shipment object.', 'shipping-desk' )
            );
        }

        $remote_shipment_id = (string) $shipment['object_id'];
        $quotes             = [];
        if ( ! empty( $shipment['rates'] ) && is_array( $shipment['rates'] ) ) {
            foreach ( $shipment['rates'] as $rate ) {
                if ( ! is_array( $rate ) || empty( $rate['object_id'] ) ) {
                    continue;
                }
                $quotes[] = $this->normalize_rate( $rate, $remote_shipment_id );
            }
        }

        if ( empty( $quotes ) ) {
            $message = __( 'Shippo returned no rates for this shipment.', 'shipping-desk' );
            $detail  = $this->messages_text( $shipment );
            if ( '' !== $detail ) {
                $message .= ' ' . $detail;
            }
            return new WP_Error( 'shippo_no_rates', $message );
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
     * Purchase a single label via POST /transactions.
     *
     * Signature confirmation and insurance are priced on the shipment, so the
     * transaction only references the rate and `$vas` cannot change either of
     * them here — the selected rate already carries both.
     *
     * @param string $remote_shipment_id Parent shipment object_id.
     * @param string $rate_id            Chosen rate object_id.
     * @param array  $rate               Rate object as returned by get_rates().
     * @param int    $order_id           WooCommerce order ID.
     * @param array  $vas                Value-added services from the UI.
     * @return array|WP_Error
     */
    public function book_shipment( $remote_shipment_id, $rate_id, array $rate, $order_id, array $vas = [] ) {
        $rate_id = trim( (string) $rate_id );
        if ( '' === $rate_id ) {
            return new WP_Error( 'shippo_missing_rate', __( 'No Shippo rate was selected.', 'shipping-desk' ) );
        }

        $order_reference = $this->order_reference( $order_id );

        $body = [
            'rate'            => $rate_id,
            // Mandatory: without async:false Shippo answers 2xx, buys the label
            // in the background, and returns neither a label_url nor an error.
            'async'           => false,
            'label_file_type' => self::LABEL_FILE_TYPE,
        ];

        $metadata = $this->transaction_metadata( $order_reference );
        if ( '' !== $metadata ) {
            // Readable in the Shippo dashboard, and a secondary marker when a
            // purchase has to be reconciled.
            $body['metadata'] = $metadata;
        }

        $transaction = $this->request( 'POST', '/transactions', $body, self::TIMEOUT_TRANSACTION );

        if ( is_wp_error( $transaction ) ) {
            if ( $this->is_timeout( $transaction ) ) {
                // The label may well be bought. Reconcile before telling anyone
                // a retry is safe, or the retry double-charges.
                return $this->reconcile_purchase( $rate_id, $order_reference, $rate, $remote_shipment_id );
            }
            return $transaction;
        }

        if ( empty( $transaction['object_id'] ) ) {
            return new WP_Error(
                'shippo_error',
                __( 'Shippo did not return a transaction object.', 'shipping-desk' )
            );
        }

        $transaction = $this->wait_for_success( $transaction );
        if ( is_wp_error( $transaction ) ) {
            return $transaction;
        }

        // Shippo returns only a rate ID on the transaction, so the carrier comes
        // from the rate captured at quote time. When a caller did not pass one,
        // read it back from the shipment instead of recording an unknown carrier.
        if ( empty( $rate['provider'] ) ) {
            $rate = $this->hydrate_rate( $rate, $remote_shipment_id, $transaction );
        }

        $label_id = (string) $transaction['object_id'];

        return [
            'successful'         => [
                $label_id => $this->normalize_transaction( $transaction, $rate, $order_reference, $remote_shipment_id ),
            ],
            'failed'             => [],
            'remote_shipment_id' => (string) $remote_shipment_id,
        ];
    }

    /**
     * Purchase several labels, one transaction per shipment.
     *
     * Shippo's `/batches` endpoint could buy these in one call, but a batch is
     * priced from carrier account + service level tokens and re-quotes each
     * shipment, so it cannot buy the specific rate the operator approved. Booking
     * one transaction per shipment keeps the purchased price identical to the
     * quoted price, and a failure stays confined to one order.
     *
     * A burst of ~10 calls trips "Hard: Too Many Requests", hence the throttle
     * between purchases.
     *
     * @param array $shipments List of shipment descriptors.
     * @return array|WP_Error
     */
    public function book_shipments( array $shipments ) {
        if ( empty( $shipments ) ) {
            return new WP_Error( 'shippo_no_shipments', __( 'No shipments to book.', 'shipping-desk' ) );
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
     * Retrieve a shipment via GET /shipments/{id}.
     *
     * @param string $id Shipment object_id.
     * @return array|WP_Error
     */
    public function get_shipment( $id ) {
        $id = trim( (string) $id );
        if ( '' === $id ) {
            return new WP_Error( 'shippo_missing_id', __( 'No Shippo shipment ID was supplied.', 'shipping-desk' ) );
        }

        $shipment = $this->request( 'GET', '/shipments/' . rawurlencode( $id ), null, self::TIMEOUT_READ );
        if ( is_wp_error( $shipment ) ) {
            if ( 'shippo_not_found' === $shipment->get_error_code() ) {
                return new WP_Error(
                    'shippo_not_found',
                    __( 'Shipment not found in Shippo.', 'shipping-desk' ),
                    [ 'shipment_id' => $id ]
                );
            }
            return $shipment;
        }

        return $shipment;
    }

    /**
     * Request a refund for a purchased label via POST /refunds.
     *
     * The identifier is the **transaction** object_id, which is what the plugin
     * stores as the label id.
     *
     * @param string $id Transaction object_id.
     * @return array|WP_Error
     */
    public function refund_shipment( $id ) {
        $id = trim( (string) $id );
        if ( '' === $id ) {
            return new WP_Error( 'shippo_missing_id', __( 'No Shippo transaction ID was supplied.', 'shipping-desk' ) );
        }

        $transaction = $this->fetch_transaction( $id );
        if ( is_wp_error( $transaction ) ) {
            if ( 'shippo_not_found' === $transaction->get_error_code() ) {
                return new WP_Error(
                    'shippo_not_found',
                    __( 'Label not found in Shippo.', 'shipping-desk' ),
                    [ 'transaction_id' => $id ]
                );
            }
            return $transaction;
        }

        $status = $this->transaction_status( $transaction );
        if ( 'REFUNDED' === $status ) {
            return new WP_Error(
                'shippo_already_refunded',
                __( 'This Shippo label has already been refunded.', 'shipping-desk' ),
                [ 'transaction_id' => $id ]
            );
        }
        if ( 'REFUNDPENDING' === $status ) {
            // The refund request is already in flight; asking again is not just
            // pointless, it reads as an error when nothing is wrong.
            return new WP_Error(
                'shippo_refund_pending',
                __( 'A refund for this label is already pending with the carrier.', 'shipping-desk' ),
                [ 'transaction_id' => $id, 'status' => $status ]
            );
        }
        if ( 'SUCCESS' !== $status ) {
            return new WP_Error(
                'shippo_not_refundable',
                sprintf(
                    /* translators: %s: Shippo transaction status */
                    __( 'Only a purchased label can be refunded (transaction status: %s).', 'shipping-desk' ),
                    '' !== $status ? $status : 'unknown'
                ),
                [ 'transaction_id' => $id, 'status' => $status ]
            );
        }

        $refund = $this->request(
            'POST',
            '/refunds',
            [
                'transaction' => $id,
                // Same reason as transactions: without async:false Shippo answers
                // before the refund is actually registered.
                'async'       => false,
            ],
            self::TIMEOUT_REFUND
        );

        if ( is_wp_error( $refund ) ) {
            if ( $this->is_timeout( $refund ) ) {
                return $this->reconcile_refund( $id );
            }
            return $refund;
        }

        $refund_status = isset( $refund['status'] ) ? strtoupper( trim( (string) $refund['status'] ) ) : '';
        if ( 'ERROR' === $refund_status ) {
            $message = $this->messages_text( $refund );
            return new WP_Error(
                'shippo_refund_failed',
                '' !== $message ? $message : __( 'Shippo rejected the refund request.', 'shipping-desk' ),
                [ 'transaction_id' => $id, 'status' => $refund_status ]
            );
        }

        // QUEUED, PENDING and SUCCESS all mean "refund requested", not refund
        // confirmed: carrier adjudication can take 30+ days and is out of scope.
        return [
            'refund_id'      => isset( $refund['object_id'] ) ? (string) $refund['object_id'] : '',
            'status'         => $refund_status,
            'requested'      => true,
            'transaction_id' => $id,
        ];
    }

    /**
     * Retrieve a transaction by id (GET /transactions/{id}).
     *
     * Exposed for support tooling: a Shippo label has two identifiers, and the one
     * written into the order note is the transaction id, not the shipment id.
     *
     * @param string $id Transaction object_id.
     * @return array|WP_Error
     */
    public function get_transaction( $id ) {
        $id = trim( (string) $id );
        if ( '' === $id ) {
            return new WP_Error( 'shippo_missing_id', __( 'No Shippo transaction ID was supplied.', 'shipping-desk' ) );
        }

        $transaction = $this->fetch_transaction( $id );
        if ( is_wp_error( $transaction ) && 'shippo_not_found' === $transaction->get_error_code() ) {
            return new WP_Error(
                'shippo_not_found',
                __( 'Transaction not found in Shippo.', 'shipping-desk' ),
                [ 'transaction_id' => $id ]
            );
        }

        return $transaction;
    }

    /**
     * Fetch a printable label URL.
     *
     * Shippo label URLs are signed and expire, so the URL is always read from a
     * fresh GET /transactions/{id} rather than from stored meta.
     *
     * @param string $id Transaction object_id.
     * @return string|WP_Error
     */
    public function get_label_url( $id ) {
        $id = trim( (string) $id );
        if ( '' === $id ) {
            return new WP_Error( 'shippo_missing_id', __( 'No Shippo transaction ID was supplied.', 'shipping-desk' ) );
        }

        $transaction = $this->fetch_transaction( $id );
        if ( is_wp_error( $transaction ) ) {
            if ( 'shippo_not_found' === $transaction->get_error_code() ) {
                return new WP_Error(
                    'shippo_not_found',
                    __( 'Label not found in Shippo.', 'shipping-desk' ),
                    [ 'transaction_id' => $id ]
                );
            }
            return $transaction;
        }

        $status = $this->transaction_status( $transaction );
        if ( ! in_array( $status, [ 'SUCCESS', 'REFUNDPENDING', 'REFUNDED' ], true ) ) {
            return new WP_Error(
                'shippo_label_unavailable',
                sprintf(
                    /* translators: %s: Shippo transaction status */
                    __( 'Shippo has no printable label for this transaction (status: %s).', 'shipping-desk' ),
                    '' !== $status ? $status : 'unknown'
                ),
                [ 'transaction_id' => $id, 'status' => $status ]
            );
        }

        $label_url = isset( $transaction['label_url'] ) ? trim( (string) $transaction['label_url'] ) : '';
        if ( '' === $label_url ) {
            return new WP_Error(
                'shippo_no_label',
                __( 'Shippo did not return a label URL.', 'shipping-desk' ),
                [ 'transaction_id' => $id ]
            );
        }

        // The signed URL carries its own expiry. A fresh GET normally refreshes
        // it; one that is already expired means the file is gone for good
        // (Shippo drops label files after about a year), so do not loop on it.
        $expiry = $this->label_expiry( $label_url );
        if ( null !== $expiry && $expiry < time() ) {
            return new WP_Error(
                'shippo_label_expired',
                __( 'The Shippo label file has expired and can no longer be downloaded.', 'shipping-desk' ),
                [ 'transaction_id' => $id, 'expires' => $expiry ]
            );
        }

        return $label_url;
    }

    /* -- Transactions ---------------------------------------------------- */

    /**
     * Order reference used for metadata. The client never loads the WooCommerce
     * order, so this is the order ID; the human-facing order number travels as
     * `extra.reference_1` on the shipment instead.
     */
    protected function order_reference( $order_id ) {
        return trim( (string) $order_id );
    }

    /**
     * Free-text marker attached to the transaction (100 characters max).
     */
    protected function transaction_metadata( $order_reference ) {
        $order_reference = trim( (string) $order_reference );
        if ( '' === $order_reference ) {
            return '';
        }
        return substr( sprintf( 'WooCommerce order %s', $order_reference ), 0, self::METADATA_MAX_LENGTH );
    }

    /**
     * Order ID out of a bulk shipment descriptor. The plugin passes it as the
     * single entry of `custom_messages` (the Veeqo convention).
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

    protected function transaction_status( array $transaction ) {
        return isset( $transaction['status'] ) ? strtoupper( trim( (string) $transaction['status'] ) ) : '';
    }

    /**
     * True when the HTTP layer failed in a way that leaves the outcome unknown.
     *
     * A timeout is not a failure: the request reached Shippo and the label may
     * already exist.
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
     */
    protected function is_retry_safe( $error ) {
        if ( ! is_wp_error( $error ) ) {
            return false;
        }
        if ( $this->is_timeout( $error ) ) {
            return false;
        }
        $data = $error->get_error_data();
        return is_array( $data ) && ! empty( $data['retry_safe'] );
    }

    /**
     * GET /transactions/{id} — the authoritative record of a purchase.
     *
     * @param string $id Transaction object_id.
     * @return array|WP_Error
     */
    protected function fetch_transaction( $id ) {
        $id = trim( (string) $id );
        if ( '' === $id ) {
            return new WP_Error( 'shippo_missing_id', __( 'No Shippo transaction ID was supplied.', 'shipping-desk' ) );
        }
        return $this->request( 'GET', '/transactions/' . rawurlencode( $id ) );
    }

    /**
     * Fill in carrier/service/price from the shipment when the caller did not
     * supply them.
     *
     * A Shippo transaction only returns a rate ID, so anything that identifies the
     * carrier has to come from the rate the caller captured. This is the fallback
     * for callers that cannot supply it; the extra read only happens in that case.
     *
     * @param array  $rate               Rate as supplied by the caller (may be empty).
     * @param string $remote_shipment_id Parent shipment object_id.
     * @param array  $transaction        Purchased transaction.
     * @return array Rate with the missing details filled in where possible.
     */
    protected function hydrate_rate( array $rate, $remote_shipment_id, array $transaction ) {
        $shipment_id = trim( (string) $remote_shipment_id );
        if ( '' === $shipment_id && isset( $transaction['shipment'] ) && is_string( $transaction['shipment'] ) ) {
            $shipment_id = trim( $transaction['shipment'] );
        }
        if ( '' === $shipment_id ) {
            return $rate;
        }

        $shipment = $this->request( 'GET', '/shipments/' . rawurlencode( $shipment_id ), null, self::TIMEOUT_READ );
        if ( is_wp_error( $shipment ) || empty( $shipment['rates'] ) || ! is_array( $shipment['rates'] ) ) {
            return $rate;
        }

        $rate_id = isset( $transaction['rate'] ) && is_string( $transaction['rate'] ) ? $transaction['rate'] : '';

        foreach ( $shipment['rates'] as $candidate ) {
            if ( ! is_array( $candidate ) || empty( $candidate['object_id'] ) ) {
                continue;
            }
            if ( '' !== $rate_id && (string) $candidate['object_id'] !== $rate_id ) {
                continue;
            }
            // Caller-supplied values win; only the gaps are filled.
            return array_merge( $this->normalize_rate( $candidate, $shipment_id ), $rate );
        }

        return $rate;
    }

    /**
     * Poll a WAITING/QUEUED transaction until it resolves.
     *
     * Only SUCCESS means a label was bought, so anything else comes back as an
     * error carrying the transaction ID and whether a retry is safe.
     *
     * @param array $transaction Transaction from POST /transactions.
     * @return array|WP_Error Confirmed SUCCESS transaction, or WP_Error.
     */
    protected function wait_for_success( array $transaction ) {
        $status     = $this->transaction_status( $transaction );
        $id         = isset( $transaction['object_id'] ) ? (string) $transaction['object_id'] : '';
        $poll_count = 0;

        while ( in_array( $status, [ 'WAITING', 'QUEUED' ], true ) && $poll_count < self::POLL_ATTEMPTS && '' !== $id ) {
            $poll_count++;
            $this->pause( self::POLL_INTERVAL );

            $fetched = $this->fetch_transaction( $id );
            if ( is_wp_error( $fetched ) ) {
                return $this->pending_purchase_error( $id, $status );
            }

            $transaction = $fetched;
            $status      = $this->transaction_status( $transaction );
        }

        if ( in_array( $status, [ 'WAITING', 'QUEUED' ], true ) ) {
            return $this->pending_purchase_error( $id, $status );
        }

        if ( 'SUCCESS' !== $status ) {
            return new WP_Error(
                'shippo_label_' . ( '' !== $status ? strtolower( $status ) : 'failed' ),
                $this->transaction_error_message( $transaction ),
                [
                    'transaction_id' => $id,
                    'status'         => $status,
                    'retry_safe'     => in_array( $status, [ 'ERROR', 'REFUNDED' ], true ),
                ]
            );
        }

        return $transaction;
    }

    /**
     * Shippo accepted the purchase but has not confirmed it yet. Buying again
     * now would risk a second label, so this is never retry-safe.
     */
    protected function pending_purchase_error( $id, $status ) {
        return new WP_Error(
            'shippo_purchase_pending',
            sprintf(
                /* translators: %1$s: transaction id, %2$s: transaction status */
                __( 'Shippo has not confirmed the label yet (transaction %1$s is %2$s). Do not buy this order again until Shippo shows the result.', 'shipping-desk' ),
                (string) $id,
                (string) $status
            ),
            [
                'transaction_id' => (string) $id,
                'status'         => (string) $status,
                'retry_safe'     => false,
            ]
        );
    }

    /**
     * Error message for a transaction that did not reach SUCCESS.
     */
    protected function transaction_error_message( array $transaction ) {
        $message = $this->messages_text( $transaction );
        if ( '' !== $message ) {
            return $message;
        }
        $status = $this->transaction_status( $transaction );
        return sprintf(
            /* translators: %s: Shippo transaction status */
            __( 'Shippo did not buy the label (transaction status: %s).', 'shipping-desk' ),
            '' !== $status ? $status : 'unknown'
        );
    }

    /**
     * Normalise a Shippo transaction into the envelope the plugin already reads
     * from the Veeqo client (`successful[<label_id>]`).
     *
     * @param array  $transaction        Transaction from Shippo.
     * @param array  $rate               Rate object captured at get_rates() time.
     * @param string $order_reference    Order ID as a string.
     * @param string $remote_shipment_id Parent shipment object_id.
     * @return array
     */
    protected function normalize_transaction( array $transaction, array $rate = [], $order_reference = '', $remote_shipment_id = '' ) {
        $label_id  = isset( $transaction['object_id'] ) ? (string) $transaction['object_id'] : '';
        $label_url = isset( $transaction['label_url'] ) ? (string) $transaction['label_url'] : '';
        $provider  = isset( $rate['provider'] ) ? (string) $rate['provider'] : '';

        // transaction.rate is an ID string, not an expanded rate object, so the
        // provider normally comes from the captured rate. Expand it only if a
        // future API version ever inlines the object.
        if ( '' === $provider && isset( $transaction['rate'] ) && is_array( $transaction['rate'] ) && isset( $transaction['rate']['provider'] ) ) {
            $provider = (string) $transaction['rate']['provider'];
        }

        $shipment_id = (string) $remote_shipment_id;
        if ( '' === $shipment_id && isset( $transaction['shipment'] ) && is_string( $transaction['shipment'] ) ) {
            $shipment_id = (string) $transaction['shipment'];
        }

        return [
            // normalised keys first: the plugin reads id, tracking_number,
            // carrier_id and labels[0].url, and uses the array key as label id.
            'id'                 => $label_id,
            'customer_reference' => (string) $order_reference,
            'tracking_number'    => isset( $transaction['tracking_number'] ) ? (string) $transaction['tracking_number'] : '',
            'carrier_id'         => $provider,
            'labels'             => '' !== $label_url ? [ [ 'url' => $label_url ] ] : [],
            // Shippo specifics, for Phase 4 meta and for diagnostics.
            'label_id'           => $label_id,
            'transaction_id'     => $label_id,
            'remote_shipment_id' => $shipment_id,
            'label_url'          => $label_url,
            'tracking_url'       => isset( $transaction['tracking_url_provider'] ) ? (string) $transaction['tracking_url_provider'] : '',
            'status'             => $this->transaction_status( $transaction ),
            'service_name'       => isset( $rate['service_name'] ) ? (string) $rate['service_name'] : '',
            'total_charge'       => isset( $rate['total_charge'] ) ? (string) $rate['total_charge'] : '',
            'currency'           => isset( $rate['currency'] ) ? (string) $rate['currency'] : '',
            'messages'           => isset( $transaction['messages'] ) && is_array( $transaction['messages'] ) ? $transaction['messages'] : [],
        ];
    }

    /**
     * Resolve a purchase whose HTTP response was lost.
     *
     * Shippo has no idempotency key, so a timed-out POST cannot simply be
     * retried: the label may already exist and the retry would double-charge.
     * Instead, transactions created against this rate in the last 30 minutes
     * are listed, then confirmed through GET /transactions/{id}.
     *
     * @param string $rate_id            Rate object_id.
     * @param string $order_reference    Order ID as a string.
     * @param array  $rate               Rate object captured at get_rates() time.
     * @param string $remote_shipment_id Parent shipment object_id.
     * @return array|WP_Error
     */
    protected function reconcile_purchase( $rate_id, $order_reference, array $rate, $remote_shipment_id ) {
        $attempt           = 0;
        $listed            = false;
        $last_lookup_error = null;

        while ( $attempt < self::RECONCILE_ATTEMPTS ) {
            $attempt++;
            if ( $attempt > 1 ) {
                // A transaction can trail the POST that created it.
                $this->pause( self::RECONCILE_INTERVAL );
            }

            $candidates = $this->find_transactions_for_rate( $rate_id );
            if ( is_wp_error( $candidates ) ) {
                $last_lookup_error = $candidates;
                continue;
            }
            $listed = true;

            if ( empty( $candidates ) ) {
                continue;
            }

            $candidate = reset( $candidates );
            $id        = isset( $candidate['object_id'] ) ? (string) $candidate['object_id'] : '';
            $confirmed = '' !== $id ? $this->fetch_transaction( $id ) : $candidate;
            if ( is_wp_error( $confirmed ) ) {
                $confirmed = $candidate;
            }

            $transaction = $this->wait_for_success( $confirmed );
            if ( is_wp_error( $transaction ) ) {
                return $transaction;
            }

            return [
                'successful'         => [
                    (string) $transaction['object_id'] => $this->normalize_transaction(
                        $transaction,
                        $rate,
                        $order_reference,
                        $remote_shipment_id
                    ),
                ],
                'failed'             => [],
                'remote_shipment_id' => (string) $remote_shipment_id,
                'reconciled'         => true,
            ];
        }

        if ( $listed ) {
            $message = __( 'Shippo timed out and no label transaction was found for this rate, so the label does not appear to have been bought. Check the Shippo dashboard before buying again.', 'shipping-desk' );
        } else {
            $message = __( 'Shippo timed out and the purchase could not be checked automatically. Check the Shippo dashboard before buying again.', 'shipping-desk' );
            if ( is_wp_error( $last_lookup_error ) ) {
                $message .= ' ' . $last_lookup_error->get_error_message();
            }
        }

        return new WP_Error(
            'shippo_purchase_unconfirmed',
            $message,
            [
                'rate_id'    => (string) $rate_id,
                'reconciled' => $listed,
                // Only claim a retry is safe when the search actually ran and
                // found nothing against this rate.
                'retry_safe' => $listed,
            ]
        );
    }

    /**
     * Transactions created against a rate within the reconciliation window.
     *
     * The transactions list only supports date filters, so the window is
     * fetched and matched locally — `transaction.rate` is a string ID.
     *
     * @param string $rate_id Rate object_id.
     * @return array|WP_Error Matching transaction arrays.
     */
    protected function find_transactions_for_rate( $rate_id ) {
        $since = gmdate( 'Y-m-d\TH:i:s\Z', time() - self::RECONCILE_WINDOW );
        $path  = '/transactions?' . http_build_query(
            [
                'object_created_gte' => $since,
                'results'            => self::RECONCILE_PAGE_SIZE,
                'page'               => 1,
            ]
        );

        $data = $this->request( 'GET', $path, null, self::TIMEOUT_READ );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $transactions = isset( $data['results'] ) && is_array( $data['results'] ) ? $data['results'] : [];
        $matches      = [];

        foreach ( $transactions as $transaction ) {
            if ( ! is_array( $transaction ) || empty( $transaction['object_id'] ) ) {
                continue;
            }
            if ( isset( $transaction['rate'] ) && is_string( $transaction['rate'] ) && $transaction['rate'] === (string) $rate_id ) {
                $matches[] = $transaction;
            }
        }

        return $matches;
    }

    /**
     * Reconcile a refund whose response was lost.
     *
     * Shippo rejects a second refund for the same transaction, so a retry cannot
     * refund twice — but the label state is still checked before reporting.
     *
     * @param string $id Transaction object_id.
     * @return array|WP_Error
     */
    protected function reconcile_refund( $id ) {
        $transaction = $this->fetch_transaction( $id );

        if ( ! is_wp_error( $transaction ) && 'REFUNDED' === $this->transaction_status( $transaction ) ) {
            return [
                'refund_id'      => '',
                'status'         => 'REFUNDED',
                'requested'      => true,
                'transaction_id' => (string) $id,
                'reconciled'     => true,
            ];
        }

        return new WP_Error(
            'shippo_refund_unconfirmed',
            __( 'Shippo timed out while requesting the refund and the label status could not be confirmed. Check the label in Shippo before asking again.', 'shipping-desk' ),
            [
                'transaction_id' => (string) $id,
                // Shippo refuses a duplicate refund, so asking again is safe.
                'retry_safe'     => true,
            ]
        );
    }

    /**
     * Unix expiry of a signed Shippo label URL, or null when the URL does not
     * carry an `Expires` parameter.
     *
     * @param string $url Label URL.
     * @return int|null
     */
    protected function label_expiry( $url ) {
        $query = wp_parse_url( (string) $url, PHP_URL_QUERY );
        if ( ! is_string( $query ) || '' === $query ) {
            return null;
        }

        $params = [];
        parse_str( $query, $params );

        if ( ! isset( $params['Expires'] ) || ! is_numeric( $params['Expires'] ) ) {
            return null;
        }

        return (int) $params['Expires'];
    }

    /* -- Address validation ---------------------------------------------- */

    /**
     * Validate and standardise a recipient address (Shippo V2).
     *
     * Never returns WP_Error — see class-address-validator-interface.php for the
     * reasoning. A validator that is down must not stop a label being bought.
     *
     * @param array $address Plugin address array (line1, line2, town, county,
     *                       postcode, country_code, name, phone, email).
     * @return array Normalised result.
     */
    public function validate_address( array $address ) {
        $query = $this->build_validation_query( $address );
        if ( '' === $query ) {
            return $this->validation_payload( [
                'error' => __( 'The address does not have enough detail to validate.', 'shipping-desk' ),
            ] );
        }

        $response = $this->request(
            'GET',
            self::ADDRESS_VALIDATE_PATH . '?' . $query,
            null,
            self::TIMEOUT_VALIDATE
        );

        if ( is_wp_error( $response ) ) {
            return $this->validation_payload( [ 'error' => $response->get_error_message() ] );
        }

        return $this->parse_validation_response( $response );
    }

    /**
     * Query string for the validation endpoint.
     *
     * Shippo needs line 1 plus either a postal code or city and state; anything
     * less cannot be matched, so it is not worth a request, let alone a charge.
     *
     * @param array $address Plugin address array.
     * @return string Empty when the address is too sparse to validate.
     */
    protected function build_validation_query( array $address ) {
        $map = [
            'line1'        => 'address_line_1',
            'line2'        => 'address_line_2',
            'town'         => 'city_locality',
            'county'       => 'state_province',
            'postcode'     => 'postal_code',
            'country_code' => 'country_code',
            'name'         => 'name',
            'phone'        => 'phone',
            'email'        => 'email',
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

        if ( ! isset( $params['address_line_1'] ) ) {
            return '';
        }
        if ( ! isset( $params['postal_code'] )
            && ! ( isset( $params['city_locality'] ) && isset( $params['state_province'] ) ) ) {
            return '';
        }

        return http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
    }

    /**
     * Turn a 200 response into the normalised result.
     *
     * `status` is derived only from what the validator actually said:
     *
     *   invalid                                   -> invalid
     *   partially_valid, or any changed attribute  -> corrected (needs a recommendation)
     *   valid                                     -> valid
     *   anything else, e.g. an empty body          -> unavailable
     *
     * @param array $data Decoded response body.
     * @return array
     */
    protected function parse_validation_response( array $data ) {
        $analysis = isset( $data['analysis'] ) && is_array( $data['analysis'] ) ? $data['analysis'] : [];
        $result   = isset( $analysis['validation_result'] ) && is_array( $analysis['validation_result'] )
            ? $analysis['validation_result']
            : [];

        $value = strtolower( trim( (string) ( isset( $result['value'] ) ? $result['value'] : '' ) ) );

        $reasons = [];
        if ( ! empty( $result['reasons'] ) && is_array( $result['reasons'] ) ) {
            foreach ( $result['reasons'] as $reason ) {
                if ( ! is_array( $reason ) ) {
                    continue;
                }
                $reasons[] = [
                    'code'        => isset( $reason['code'] ) ? (string) $reason['code'] : '',
                    'type'        => isset( $reason['type'] ) ? (string) $reason['type'] : '',
                    'description' => isset( $reason['description'] ) ? (string) $reason['description'] : '',
                ];
            }
        }

        $changed = [];
        if ( ! empty( $analysis['changed_attributes'] ) && is_array( $analysis['changed_attributes'] ) ) {
            foreach ( $analysis['changed_attributes'] as $field ) {
                if ( is_string( $field ) && '' !== $field ) {
                    $changed[] = $field;
                }
            }
        }

        $recommended = $this->map_validated_address(
            isset( $data['recommended_address'] ) && is_array( $data['recommended_address'] )
                ? $data['recommended_address']
                : []
        );

        $confidence = [];
        if ( isset( $data['recommended_address']['confidence_result'] )
            && is_array( $data['recommended_address']['confidence_result'] ) ) {
            $raw        = $data['recommended_address']['confidence_result'];
            $confidence = [
                'score'       => isset( $raw['score'] ) ? (string) $raw['score'] : '',
                'code'        => isset( $raw['code'] ) ? (string) $raw['code'] : '',
                'description' => isset( $raw['description'] ) ? (string) $raw['description'] : '',
            ];
        }

        $type = strtolower( trim( (string) ( isset( $analysis['address_type'] ) ? $analysis['address_type'] : '' ) ) );
        if ( ! in_array( $type, [ 'commercial', 'residential' ], true ) ) {
            $type = '';
        }

        if ( 'invalid' === $value ) {
            $status = 'invalid';
        } elseif ( 'partially_valid' === $value || ! empty( $changed ) ) {
            // A correction is only actionable when there is something to apply.
            $status = empty( $recommended ) ? 'unavailable' : 'corrected';
        } elseif ( 'valid' === $value ) {
            $status = 'valid';
        } else {
            $status = 'unavailable';
        }

        return $this->validation_payload( [
            'status'       => $status,
            'is_valid'     => in_array( $status, [ 'valid', 'corrected' ], true ),
            'recommended'  => $recommended,
            'changed'      => $changed,
            'reasons'      => $reasons,
            'address_type' => $type,
            'confidence'   => $confidence,
        ] );
    }

    /**
     * Map a validator address onto plugin field names — the inverse of
     * map_address() — so the result can be dropped straight into a payload.
     *
     * The V2 endpoint answers with V2 names (`address_line_1`, `city_locality`,
     * `postal_code`) rather than the legacy shipment shape (`street1`, `city`,
     * `zip`). Both are accepted, V2 first, so a change of shape cannot silently
     * empty the correction and leave an address that looks valid but is not
     * applied.
     *
     * @param array $address Shippo or V2 address.
     * @return array Empty keys omitted.
     */
    protected function map_validated_address( array $address ) {
        $map = [
            'line1'        => [ 'address_line_1', 'street1' ],
            'line2'        => [ 'address_line_2', 'street2' ],
            'town'         => [ 'city_locality', 'city' ],
            'county'       => [ 'state_province', 'state' ],
            'postcode'     => [ 'postal_code', 'zip' ],
            'country_code' => [ 'country_code', 'country' ],
            'name'         => [ 'name' ],
            'company'      => [ 'company' ],
            'phone'        => [ 'phone' ],
            'email'        => [ 'email' ],
        ];

        $mapped = [];
        foreach ( $map as $to => $sources ) {
            foreach ( $sources as $from ) {
                if ( ! isset( $address[ $from ] ) || is_array( $address[ $from ] ) ) {
                    continue;
                }
                $value = trim( (string) $address[ $from ] );
                if ( '' !== $value ) {
                    $mapped[ $to ] = $value;
                    break;
                }
            }
        }

        return $mapped;
    }

    /**
     * Shape every result identically so callers never guess which keys exist.
     * The default is `unavailable`, i.e. the safe answer.
     *
     * @param array $overrides Keys to replace.
     * @return array
     */
    protected function validation_payload( array $overrides = [] ) {
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

    protected function headers() {
        $key = $this->get_api_key();
        if ( is_wp_error( $key ) ) {
            return $key;
        }
        return [
            'Content-Type'       => 'application/json',
            'Accept'             => 'application/json',
            'Authorization'      => 'ShippoToken ' . $key,
            'SHIPPO-API-VERSION' => self::API_VERSION,
        ];
    }

    /**
     * Perform a request, honouring the API key, JSON encoding and 429 backoff.
     *
     * @param string     $method  HTTP method.
     * @param string     $path    Path and query string, starting with "/".
     * @param array|null $body    JSON body for POST/PUT.
     * @param int        $timeout Request timeout in seconds.
     * @return array|WP_Error Decoded JSON body on success.
     */
    protected function request( $method, $path, $body = null, $timeout = self::TIMEOUT_READ ) {
        $headers = $this->headers();
        if ( is_wp_error( $headers ) ) {
            return $headers;
        }

        $args = [
            'method'  => $method,
            'headers' => $headers,
            'timeout' => (float) $timeout,
        ];
        if ( null !== $body ) {
            $encoded = wp_json_encode( $body );
            if ( false === $encoded ) {
                return new WP_Error( 'shippo_encode_error', __( 'Could not encode the Shippo request payload.', 'shipping-desk' ) );
            }
            $args['body'] = $encoded;
        }

        $url     = self::BASE_URL . $path;
        $attempt = 0;

        while ( true ) {
            $attempt++;
            $response = wp_remote_request( $url, $args );
            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            $raw  = (string) wp_remote_retrieve_body( $response );
            $data = '' === $raw ? [] : json_decode( $raw, true );

            if ( $this->should_retry( $method, $code ) ) {
                if ( 429 === $code ) {
                    // Slow the rest of the run down too, not just this call.
                    $this->widen_spacing();
                }
                if ( $attempt < self::MAX_ATTEMPTS ) {
                    $this->backoff( $attempt, $response );
                    continue;
                }
            }

            if ( 429 === $code ) {
                return new WP_Error(
                    'shippo_rate_limited',
                    __( 'Shippo is rate limiting requests (HTTP 429). Please try again in a moment.', 'shipping-desk' ),
                    [ 'status' => 429, 'attempts' => $attempt ]
                );
            }

            if ( $code < 200 || $code >= 300 ) {
                return $this->api_error( $code, $data );
            }

            if ( ! is_array( $data ) ) {
                return new WP_Error(
                    'shippo_invalid_response',
                    sprintf( __( 'Shippo returned an unreadable response (HTTP %d).', 'shipping-desk' ), $code ),
                    [ 'status' => $code ]
                );
            }

            $this->ease_spacing();

            return $data;
        }
    }

    /**
     * Whether a failed request is worth sending again.
     *
     * A 429 means "not processed", so even a POST is safe to repeat. A 5xx is
     * only retried for a read: a lost answer to a spending POST could hide a
     * purchase that really happened, so those are never repeated.
     *
     * @param string $method HTTP method.
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
     * Sleep before retrying a throttled request. Honours Retry-After, else
     * backs off exponentially: 1s, 2s.
     */
    protected function backoff( $attempt, $response ) {
        $retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
        $wait        = $retry_after > 0
            ? $retry_after
            : self::RETRY_BASE_DELAY * (int) pow( 2, max( 0, (int) $attempt - 1 ) );
        $wait        = max( 1, min( $wait, 30 ) );
        $this->pause( $wait );
    }

    /**
     * Wait between attempts.
     *
     * Kept as a method so the timing behaviour is overridable in tests without
     * the suite actually sleeping.
     */
    protected function pause( $seconds ) {
        sleep( max( 0, (int) $seconds ) );
    }

    /**
     * Base spacing for this key: a test key is paced to the tighter limit.
     *
     * @return int Microseconds.
     */
    protected function base_spacing_us() {
        if ( class_exists( 'Shipping_Desk_Helpers' ) ) {
            return (int) Shipping_Desk_Helpers::bulk_spacing_us( 'shippo', $this->api_key );
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
     * Wait between bulk purchases (microseconds).
     */
    protected function throttle( $microseconds ) {
        $microseconds = (int) $microseconds;
        if ( $microseconds > 0 ) {
            usleep( $microseconds );
        }
    }

    /**
     * Build a WP_Error from a non-2xx Shippo response.
     */
    protected function api_error( $code, $data ) {
        $message = $this->error_message( $data );
        if ( '' === $message ) {
            $message = sprintf( __( 'Shippo request failed (HTTP %d).', 'shipping-desk' ), (int) $code );
        }

        // Shippo names the missing attribute but not where it comes from. Point at
        // the source so the error is actionable instead of a scavenger hunt.
        if ( false !== strpos( $message, 'address_from.' ) ) {
            $message .= ' ' . __( 'The from address comes from Settings - From Address; blank fields fall back to the store address and admin email.', 'shipping-desk' );
        } elseif ( false !== strpos( $message, 'address_to.' ) ) {
            $message .= ' ' . __( 'The recipient details come from the order shipping address and billing phone or email.', 'shipping-desk' );
        }

        if ( 404 === (int) $code ) {
            $error_code = 'shippo_not_found';
        } elseif ( (int) $code >= 500 ) {
            $error_code = 'shippo_server_error';
        } else {
            $error_code = 'shippo_api_error';
        }

        return new WP_Error( $error_code, $message, [ 'status' => (int) $code, 'body' => $data ] );
    }

    /**
     * Message text carried by a Shippo object (`messages[].text`) plus the
     * `detail`/`error` strings of an error body.
     *
     * Unlike error_message(), this ignores the object's own string fields, so it
     * is safe to call with a shipment or a transaction.
     */
    protected function messages_text( $data ) {
        if ( ! is_array( $data ) ) {
            return '';
        }

        $parts = [];

        if ( ! empty( $data['messages'] ) && is_array( $data['messages'] ) ) {
            foreach ( $data['messages'] as $message ) {
                if ( is_array( $message ) && ! empty( $message['text'] ) ) {
                    $parts[] = trim( (string) $message['text'] );
                } elseif ( is_string( $message ) && '' !== trim( $message ) ) {
                    $parts[] = trim( $message );
                }
            }
        }

        foreach ( [ 'detail', 'error' ] as $key ) {
            if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) && '' !== trim( $data[ $key ] ) ) {
                $parts[] = trim( $data[ $key ] );
            }
        }

        $parts = array_values( array_unique( array_filter( $parts ) ) );
        return implode( ' ', $parts );
    }

    /**
     * Pull a human-readable message out of a Shippo error body.
     *
     * Shippo uses three shapes: `{"detail": "..."}` for generic errors,
     * `{"field": ["message"]}` for validation errors, and
     * `{"messages": [{"source", "code", "text"}]}` on shipments/transactions.
     */
    protected function error_message( $data ) {
        if ( is_string( $data ) ) {
            return trim( $data );
        }
        if ( ! is_array( $data ) ) {
            return '';
        }

        $skip  = [ 'detail', 'error', 'messages', 'count', 'next', 'previous', 'results' ];
        $parts = [];

        foreach ( [ 'detail', 'error' ] as $key ) {
            if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) && '' !== trim( $data[ $key ] ) ) {
                $parts[] = trim( $data[ $key ] );
            }
        }

        if ( ! empty( $data['messages'] ) && is_array( $data['messages'] ) ) {
            foreach ( $data['messages'] as $message ) {
                if ( is_array( $message ) && ! empty( $message['text'] ) ) {
                    $parts[] = trim( (string) $message['text'] );
                } elseif ( is_string( $message ) && '' !== trim( $message ) ) {
                    $parts[] = trim( $message );
                }
            }
        }

        foreach ( $data as $key => $value ) {
            if ( in_array( $key, $skip, true ) ) {
                continue;
            }
            if ( is_array( $value ) ) {
                foreach ( $value as $entry ) {
                    if ( is_string( $entry ) && '' !== trim( $entry ) ) {
                        $parts[] = trim( $entry );
                    }
                }
            } elseif ( is_string( $value ) && '' !== trim( $value ) ) {
                $parts[] = trim( $value );
            }
        }

        $parts = array_values( array_unique( array_filter( $parts ) ) );
        return implode( ' ', $parts );
    }

    /* -- Dispatch date ---------------------------------------------------- */

    /**
     * "Now" in the store's local timezone.
     *
     * Pinned to Eastern rather than trusting the server's date.timezone, and
     * overridable so the gate can evaluate a fixed moment.
     *
     * @return DateTimeImmutable
     */
    protected function now() {
        return new DateTimeImmutable( 'now', new DateTimeZone( self::DISPATCH_TIMEZONE ) );
    }

    /**
     * The `shipment_date` Shippo should record, as an ISO 8601 UTC timestamp.
     *
     * Shippo stores the tender date on the shipment, and a transaction can only
     * reference a rate — the date cannot change at purchase. It defaults to
     * "now" when omitted, which the spike showed produces the message
     * "Invalid Date. Changed to today's date", so it is always sent.
     *
     * @return string
     */
    /**
     * The dispatch day a quote taken now would carry, as `Y-m-d`.
     *
     * Public so the caller can bind a stored quote to the day it was priced for: the
     * date is baked into the shipment at quote time and USPS prints it, so a quote
     * priced for one dispatch day must never be bought on another.
     *
     * @return string
     */
    public function dispatch_day() {
        return $this->dispatch_date()->format( 'Y-m-d' );
    }

    protected function shipment_date() {
        // Local noon, so no timezone shift can move the calendar day; the wire
        // format is set by SHIPMENT_DATE_FORMAT.
        return $this->dispatch_date()
            ->setTime( self::DISPATCH_HOUR, 0, 0 )
            ->setTimezone( new DateTimeZone( 'UTC' ) )
            ->format( self::SHIPMENT_DATE_FORMAT );
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
     * @return DateTimeImmutable Date; the time of day is replaced later.
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
     * The next Monday to Friday.
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
     */
    protected function cutoff( $hhmm, DateTimeImmutable $day ) {
        $parts = explode( ':', (string) $hhmm );
        return $day->setTime( (int) $parts[0], (int) ( $parts[1] ?? 0 ), 0 );
    }

    /* -- Shipment payload ------------------------------------------------ */

    /**
     * Map the plugin's normalised rate payload onto a Shippo shipment body.
     *
     * @param array $payload Normalised shipment request.
     * @return array
     */
    protected function build_shipment_body( array $payload ) {
        $parcels = [];
        if ( ! empty( $payload['parcels'] ) && is_array( $payload['parcels'] ) ) {
            foreach ( $payload['parcels'] as $parcel ) {
                if ( ! is_array( $parcel ) ) {
                    continue;
                }
                $parcels[] = $this->map_parcel( $parcel );
            }
        }

        $body = [
            'address_from'  => $this->map_address( isset( $payload['from_address'] ) && is_array( $payload['from_address'] ) ? $payload['from_address'] : [] ),
            'address_to'    => $this->map_address( isset( $payload['to_address'] ) && is_array( $payload['to_address'] ) ? $payload['to_address'] : [] ),
            'parcels'       => $parcels,
            // Without async:false Shippo answers 2xx with no rates at all.
            'async'         => false,
            // Shippo records the tender date on the shipment (a transaction only
            // references a rate), and a future working day is safer than a stale
            // one, so the date comes from the dispatch cutoff.
            'shipment_date' => $this->shipment_date(),
        ];

        $extra = [];

        $reference = $this->truncate_reference(
            isset( $payload['customer_reference'] ) ? $payload['customer_reference'] : ''
        );
        if ( '' !== $reference ) {
            $extra['reference_1'] = $reference;
            // reference_2 is deliberately unused (see the migration notes).
        }

        $signature = $this->normalize_signature( $this->signature_source( $payload ) );
        if ( '' !== $signature ) {
            $extra['signature_confirmation'] = $signature;
        }

        $insurance = $this->insurance_extra( $payload );
        if ( ! empty( $insurance ) ) {
            $extra['insurance'] = $insurance;
        }

        if ( ! empty( $extra ) ) {
            $body['extra'] = $extra;
        }

        return $body;
    }

    /**
     * Signature may arrive either at the top level of the payload or inside a
     * nested `vas` map, depending on which phase supplies it.
     */
    protected function signature_source( array $payload ) {
        if ( isset( $payload['signature_confirmation'] ) && '' !== (string) $payload['signature_confirmation'] ) {
            return $payload['signature_confirmation'];
        }
        if ( isset( $payload['vas'] ) && is_array( $payload['vas'] ) && isset( $payload['vas']['signature_confirmation'] ) ) {
            return $payload['vas']['signature_confirmation'];
        }
        return '';
    }

    /**
     * Restrict signature confirmation to the enums we support.
     *
     * Anything else is dropped rather than forwarded: an unsupported enum makes
     * Shippo silently quote a single carrier, with no error to explain it.
     *
     * @param mixed $value Raw value from the UI or payload.
     * @return string STANDARD, ADULT, or an empty string for "none".
     */
    protected function normalize_signature( $value ) {
        $value = strtoupper( trim( (string) $value ) );
        return in_array( $value, self::SIGNATURE_OPTIONS, true ) ? $value : '';
    }

    /**
     * XCover insurance block.
     *
     * The provider field must be omitted: Shippo rejects e.g. `provider: USPS`
     * with "Invalid insurance 'provider' code provided".
     *
     * @param array $payload Normalised shipment request.
     * @return array Empty when no declared value was requested.
     */
    protected function insurance_extra( array $payload ) {
        $amount = round( (float) ( isset( $payload['liability_amount'] ) ? $payload['liability_amount'] : 0 ), 2 );
        if ( $amount <= 0 ) {
            return [];
        }

        $currency = strtoupper( trim( (string) ( isset( $payload['currency_code'] ) ? $payload['currency_code'] : '' ) ) );
        if ( '' === $currency ) {
            $currency = 'USD';
        }

        return [
            'amount'   => number_format( $amount, 2, '.', '' ),
            'currency' => $currency,
            'content'  => self::INSURANCE_CONTENT,
        ];
    }

    /**
     * Map the plugin's address onto Shippo's address fields.
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

        return $mapped;
    }

    /**
     * Map the plugin's parcel onto a Shippo parcel. Shippo accepts oz and in
     * directly, so no conversion happens; other units pass through when they
     * are ones Shippo understands.
     */
    protected function map_parcel( array $parcel ) {
        $mass_units      = [ 'oz', 'lb', 'g', 'kg' ];
        $dimension_units = [ 'in', 'cm', 'mm', 'm', 'yd', 'ft' ];

        $weight_unit    = strtolower( (string) ( isset( $parcel['weight_unit'] ) ? $parcel['weight_unit'] : 'oz' ) );
        $dimension_unit = strtolower( (string) ( isset( $parcel['dimension_unit'] ) ? $parcel['dimension_unit'] : 'in' ) );

        return [
            'length'        => $this->format_number( isset( $parcel['length'] ) ? $parcel['length'] : 0 ),
            'width'         => $this->format_number( isset( $parcel['width'] ) ? $parcel['width'] : 0 ),
            'height'        => $this->format_number( isset( $parcel['height'] ) ? $parcel['height'] : 0 ),
            'distance_unit' => in_array( $dimension_unit, $dimension_units, true ) ? $dimension_unit : 'in',
            'weight'        => $this->format_number( isset( $parcel['weight'] ) ? $parcel['weight'] : 0 ),
            'mass_unit'     => in_array( $weight_unit, $mass_units, true ) ? $weight_unit : 'oz',
        ];
    }

    /**
     * Carrier references are length-limited; 30 characters is the smallest
     * limit across UPS (35) and USPS (30).
     */
    protected function truncate_reference( $reference ) {
        $reference = trim( (string) $reference );
        if ( '' === $reference ) {
            return '';
        }
        return substr( $reference, 0, self::REFERENCE_MAX_LENGTH );
    }

    /**
     * Render a number for JSON without trailing zeros ("12.50" -> "12.5").
     */
    protected function format_number( $value ) {
        $formatted = number_format( (float) $value, 2, '.', '' );
        $formatted = rtrim( $formatted, '0' );
        $formatted = rtrim( $formatted, '.' );
        return '' === $formatted ? '0' : $formatted;
    }

    /**
     * Normalise a Shippo rate to the shape the admin JavaScript consumes.
     *
     * Rate details are captured here because `transaction.rate` comes back as a
     * string ID, not an expanded object.
     *
     * @param array  $rate      Shippo rate object.
     * @param string $remote_id Parent shipment object_id.
     * @return array
     */
    protected function normalize_rate( array $rate, $remote_id ) {
        $provider = isset( $rate['provider'] ) ? (string) $rate['provider'] : '';
        $service  = isset( $rate['servicelevel'] ) && is_array( $rate['servicelevel'] )
            ? ( isset( $rate['servicelevel']['name'] ) ? (string) $rate['servicelevel']['name'] : '' )
            : '';
        $token    = isset( $rate['servicelevel'] ) && is_array( $rate['servicelevel'] ) && isset( $rate['servicelevel']['token'] )
            ? (string) $rate['servicelevel']['token']
            : '';

        $service_name = trim( $provider . ' ' . $service );

        return [
            'rate_id'                  => (string) $rate['object_id'],
            'remote_shipment_id'       => (string) $remote_id,
            'service_name'             => '' !== $service_name ? $service_name : $token,
            'total_charge'             => isset( $rate['amount'] ) ? (string) $rate['amount'] : '',
            // Shippo has no priced extras; kept for the shared JS contract.
            'shipping_service_options' => [],
            // Preserved so book_shipment() can rebuild the receipt later without
            // a second API call.
            'provider'                 => $provider,
            'servicelevel_name'        => $service,
            'servicelevel_token'       => $token,
            'currency'                 => isset( $rate['currency'] ) ? (string) $rate['currency'] : '',
            // Transit time is a duration in days, not a delivery date: Shippo has no
            // date field, and arrives_by is a time of day. Null when the carrier
            // gave no estimate.
            'estimated_days'           => isset( $rate['estimated_days'] ) && is_numeric( $rate['estimated_days'] )
                ? (int) $rate['estimated_days']
                : null,
            'duration_terms'           => isset( $rate['duration_terms'] ) ? (string) $rate['duration_terms'] : '',
            'included_insurance_price' => isset( $rate['included_insurance_price'] ) ? $rate['included_insurance_price'] : null,
            'attributes'               => isset( $rate['attributes'] ) && is_array( $rate['attributes'] ) ? $rate['attributes'] : [],
        ];
    }

}
