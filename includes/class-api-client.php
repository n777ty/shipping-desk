<?php
/**
 * Veeqo Shipping API client.
 */
if ( ! interface_exists( 'Shipping_Desk_Provider_Interface' ) ) {
    require_once __DIR__ . '/class-provider-interface.php';
}

class Shipping_Desk_Veeqo_Client implements Shipping_Desk_Provider_Interface {

    const BASE_URL = 'https://api.veeqo.com';

    protected $api_key;

    public function __construct( $api_key = null ) {
        $this->api_key = $api_key !== null ? (string) $api_key : (string) get_option( 'shipping_desk_veeqo_api_key', '' );
    }

    public function get_api_key() {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'no_api_key', __( 'Veeqo API key is not set.', 'shipping-desk' ) );
        }
        return $this->api_key;
    }

    protected function headers() {
        $key = $this->get_api_key();
        if ( is_wp_error( $key ) ) {
            return $key;
        }
        return [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
            'x-api-key'    => $key,
        ];
    }

    public function get_rates( array $payload ) {
        $headers = $this->headers();
        if ( is_wp_error( $headers ) ) {
            return $headers;
        }

        // The email address was added to the shared payload because USPS (via
        // Shippo) requires address_from.email. Veeqo's rate request has never
        // carried it and its schema has no such field, so it is always stripped.
        // The phone was always part of this payload; it simply can no longer be
        // empty.
        foreach ( [ 'from_address', 'to_address' ] as $address_key ) {
            if ( isset( $payload[ $address_key ]['email'] ) ) {
                unset( $payload[ $address_key ]['email'] );
            }
        }

        // Veeqo prints `customer_reference` on the label and has no separate API
        // handle, so the chosen printed reference replaces it here. The
        // `print_reference` key itself is stripped: Veeqo's schema has no such
        // field. A blank or absent value leaves the order number in place.
        if ( isset( $payload['print_reference'] ) ) {
            $printed = trim( (string) $payload['print_reference'] );
            if ( '' !== $printed ) {
                $payload['customer_reference'] = $printed;
            }
        }
        unset( $payload['print_reference'] );

        // `to_address.line2` is a documented field and is sent: without it a
        // suite or apartment number never reaches the label, because the order's
        // second address line had been dropped before the payload was built.
        //
        // `from_address.line2` is not part of the documented shape — and the store
        // address has no second line to give — so it is dropped rather than risk an
        // unknown field there for no benefit.
        unset( $payload['from_address']['line2'] );
        if ( isset( $payload['to_address']['line2'] )
            && '' === trim( (string) $payload['to_address']['line2'] ) ) {
            unset( $payload['to_address']['line2'] );
        }

        $sent_line2 = isset( $payload['to_address']['line2'] );
        $response   = $this->post_rates( $headers, $payload );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );

        // Safety net, not a negotiation. line2 is documented, so a validation
        // error here is almost certainly something else — but a wire field must
        // never be able to take rate shopping down on its own (see the 0.9.45
        // shipment_date regression), so ask once more without it.
        if ( $sent_line2 && in_array( $code, [ 400, 422 ], true ) ) {
            $retry = $this->post_rates( $headers, $this->without_line2( $payload ) );
            if ( ! is_wp_error( $retry ) && 200 === (int) wp_remote_retrieve_response_code( $retry ) ) {
                $response = $retry;
                $code     = 200;
            }
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 || ! is_array( $data ) ) {
            return new WP_Error( 'veeqo_error', sprintf( __( 'Unexpected Veeqo rates response: %d', 'shipping-desk' ), $code ) );
        }

        return $data;
    }

    /**
     * @param array $headers Request headers.
     * @param array $payload Rate payload.
     * @return array|WP_Error Raw HTTP response.
     */
    protected function post_rates( array $headers, array $payload ) {
        return wp_remote_post( self::BASE_URL . '/shipping/api/v1/rates', [
            'headers' => $headers,
            'body'    => wp_json_encode( $payload ),
            'timeout' => 20,
        ] );
    }

    /**
     * Copy of the payload with every `line2` removed, for the fallback attempt.
     */
    protected function without_line2( array $payload ) {
        foreach ( [ 'from_address', 'to_address' ] as $address_key ) {
            if ( isset( $payload[ $address_key ]['line2'] ) ) {
                unset( $payload[ $address_key ]['line2'] );
            }
        }
        return $payload;
    }


    public function book_shipment( $remote_shipment_id, $rate_id, array $rate, $order_id, array $vas = [] ) {
        $headers = $this->headers();
        if ( is_wp_error( $headers ) ) {
            return $headers;
        }
        // Prefer explicit VAS selections supplied by the operator; fall back to
        // the free option from the rate when none were provided.
        $vas_fields = [];
        if ( ! empty( $vas ) ) {
            foreach ( $vas as $key => $val ) {
                if ( ! is_string( $key ) || $key === '' ) {
                    continue;
                }
                $vas_fields[ sanitize_text_field( $key ) ] = is_scalar( $val ) ? sanitize_text_field( (string) $val ) : '';
            }
        } elseif ( ! empty( $rate['shipping_service_options'] ) && is_array( $rate['shipping_service_options'] ) ) {
            foreach ( $rate['shipping_service_options'] as $opt ) {
                if ( ! is_array( $opt ) ) { continue; }
                if ( ( $opt['type'] ?? '' ) === 'select' && ! empty( $opt['values'] ) ) {
                    $zero     = null;
                    $explicit = null;
                    foreach ( $opt['values'] as $v ) {
                        if ( ! isset( $v['price'] ) || 0.0 !== (float) $v['price'] ) {
                            continue;
                        }
                        if ( $zero === null ) {
                            $zero = $v['value'] ?? null;
                        }
                        if ( $explicit === null && isset( $v['label'] ) && preg_match( '/^no\b/i', (string) $v['label'] ) ) {
                            $explicit = $v['value'] ?? null;
                        }
                    }
                    $pick = $explicit !== null ? $explicit : $zero;
                    if ( $pick !== null ) {
                        $vas_fields[ $opt['key'] ] = $pick;
                    }
                } elseif ( ( $opt['type'] ?? '' ) === 'number' ) {
                    $vas_fields[ $opt['key'] ] = 0;
                }
            }
        }
        $shipment = [
            'remote_shipment_id' => $remote_shipment_id,
            'rate_id'            => $rate_id,
            'custom_messages'    => [ (string) $order_id ],
        ];
        foreach ( $vas_fields as $key => $val ) {
            $shipment[ $key ] = $val;
        }
        $payload = [
            'label_format' => 'PDF',
            'shipments'    => [ $shipment ],
        ];
        $response = wp_remote_post( self::BASE_URL . '/shipping/api/v1/shipments', [
            'headers' => $headers,
            'body'    => wp_json_encode( $payload ),
            'timeout' => 20,
        ] );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
            return new WP_Error( 'veeqo_error', sprintf( __( 'Unexpected Veeqo book response: %d', 'shipping-desk' ), $code ) );
        }
        $data['remote_shipment_id'] = $remote_shipment_id;
        return $data;
    }


    public function book_shipments( array $shipments ) {
        $headers = $this->headers();
        if ( is_wp_error( $headers ) ) {
            return $headers;
        }
        $payload = [
            'label_format' => 'PDF',
            'shipments'    => array_values( $shipments ),
        ];
        $response = wp_remote_post( self::BASE_URL . '/shipping/api/v1/shipments', [
            'headers' => $headers,
            'body'    => wp_json_encode( $payload ),
            'timeout' => 30,
        ] );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
            return new WP_Error( 'veeqo_error', sprintf( __( 'Unexpected Veeqo book response: %d', 'shipping-desk' ), $code ) );
        }
        return $data;
    }

    public function get_shipment( $id ) {
        $headers = $this->headers();
        if ( is_wp_error( $headers ) ) {
            return $headers;
        }
        $url = self::BASE_URL . '/shipping/api/v1/shipments/' . rawurlencode( (string) $id );
        $response = wp_remote_get( $url, [ 'headers' => $headers, 'timeout' => 20 ] );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code === 404 ) {
            return new WP_Error( 'not_found', __( 'Shipment not found in Veeqo.', 'shipping-desk' ) );
        }
        if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
            $msg = is_array( $data ) && ! empty( $data['error_messages'] )
                ? implode( '; ', (array) $data['error_messages'] )
                : sprintf( __( 'Unexpected Veeqo response: %d', 'shipping-desk' ), $code );
            return new WP_Error( 'veeqo_error', $msg );
        }
        return $data;
    }

    public function refund_shipment( $id ) {
        $headers = $this->headers();
        if ( is_wp_error( $headers ) ) {
            return $headers;
        }
        $url = self::BASE_URL . '/shipping/api/v1/shipments/' . rawurlencode( (string) $id );
        $response = wp_remote_request( $url, [
            'method'  => 'DELETE',
            'headers' => $headers,
            'timeout' => 20,
        ] );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'veeqo_error', sprintf( __( 'Veeqo refund failed: %d', 'shipping-desk' ), $code ) );
        }
        return true;
    }

    public function get_label_url( $id ) {
        $headers = $this->headers();
        if ( is_wp_error( $headers ) ) {
            return $headers;
        }
        $endpoint = '/shipping/api/v1/shipments/' . rawurlencode( (string) $id ) . '/label.pdf?redirect=true';
        $url      = self::BASE_URL . strtolower( $endpoint );
        $response = wp_remote_get( $url, [
            'headers'     => $headers,
            'redirection' => 0,
            'timeout'     => 20,
        ] );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $location = wp_remote_retrieve_header( $response, 'location' );
        if ( empty( $location ) ) {
            return new WP_Error( 'no_label', __( 'Veeqo did not return a label URL.', 'shipping-desk' ) );
        }
        return Shipping_Desk_Helpers::absolute_url( $location );
    }

    }
