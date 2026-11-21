<?php
/**
 * What is done with a label once it exists: print it in the browser or through PrintNode,
 * refund it, or reset the order so it can be shipped again. Also the three lookups those
 * share: which shipment an action is about, which provider owns it, and the label URL to
 * print.
 *
 * Moved out of Shipping_Desk_Plugin without changing what it does. The provider, its mode, the
 * clients and the capability check are still asked of the plugin, so there is one answer to each.
 */
class Shipping_Desk_Label_Actions {

    /** @var Shipping_Desk_Plugin */
    protected $plugin;

    public function __construct( Shipping_Desk_Plugin $plugin ) {
        $this->plugin = $plugin;
    }

    /**
     * Register the AJAX actions this class answers.
     *
     * @return void
     */
    public function init() {
        foreach ( [ 'shipping_desk_print_label', 'shipping_desk_print_pdf', 'shipping_desk_refund_label', 'shipping_desk_reset_shipping', 'shipping_desk_printnode_print_label' ] as $action ) {
            add_action( 'wp_ajax_' . $action, [ $this, $action ] );
        }
    }

    /**
     * The printable URL for an order's label.
     *
     * A fresh URL is asked for first: it is authoritative, and Veeqo and Shippo sign theirs
     * with an expiry, so a stored one can go stale. EasyPost is the exception in the wrong
     * direction - it can take a few seconds to finish building the PDF after a successful
     * buy - so when the provider says it has no label, the URL recorded at purchase is used
     * instead. A label that was bought can be printed without waiting for the second try.
     *
     * Any other error (auth, network) is passed through untouched: falling back would hide
     * a broken key behind a label that only looks fine.
     *
     * @param array $target Client, reference and order from get_shipment_action_context().
     * @return string|WP_Error
     */
    protected function printable_label_url( array $target ) {
        $url = $target['client']->get_label_url( $target['reference'] );

        if ( ! is_wp_error( $url ) && '' !== trim( (string) $url ) ) {
            return $url;
        }

        if ( is_wp_error( $url ) && ! in_array( $url->get_error_code(), [ 'easypost_label_missing', 'easypost_not_found', 'shippo_no_label' ], true ) ) {
            return $url;
        }

        if ( ! empty( $target['order'] ) ) {
            $m     = Shipping_Desk_Helpers::meta_keys();
            $saved = trim( (string) $target['order']->get_meta( $m['label_url'] ) );
            if ( '' !== $saved ) {
                return $saved;
            }
        }

        return $url;
    }

    protected function get_shipment_action_context() {
        // $_REQUEST, not $_POST: the browser print endpoint is opened as a URL in a new
        // tab, so its parameters arrive as GET. Both paths still verify a nonce.
        $order_id  = isset( $_REQUEST['order_id'] ) ? intval( $_REQUEST['order_id'] ) : 0;
        $remote_id = isset( $_REQUEST['remote_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['remote_id'] ) ) : '';
        $nonce     = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
        $order     = null;
        $m         = Shipping_Desk_Helpers::meta_keys();

        if ( $remote_id !== '' ) {
            if ( ! wp_verify_nonce( $nonce, Shipping_Desk_Helpers::lookup_nonce_action( $remote_id ) ) ) {
                wp_send_json_error( [ 'message' => __( 'Invalid nonce.', 'shipping-desk' ) ], 400 );
            }
            $order = $order_id ? wc_get_order( $order_id ) : null;
            if ( ! $order && ! apply_filters( 'shipping_desk_lookup_allow_foreign_shipment', false, $remote_id, $order_id ) ) {
                wp_send_json_error(
                    [ 'message' => __( 'That shipment is not an order in this store, so label actions are not available here.', 'shipping-desk' ) ],
                    403
                );
            }
        } else {
            if ( ! wp_verify_nonce( $nonce, 'shipping_desk_order_' . $order_id ) ) {
                wp_send_json_error( [ 'message' => __( 'Invalid nonce.', 'shipping-desk' ) ], 400 );
            }
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                wp_send_json_error( [ 'message' => __( 'Order not found.', 'shipping-desk' ) ], 404 );
            }
            $remote_id = $order->get_meta( $m['remote_id'] );
            if ( empty( $remote_id ) ) {
                wp_send_json_error( [ 'message' => __( 'Remote shipment ID missing.', 'shipping-desk' ) ], 400 );
            }
        }

        // Which provider owns this label. Orders shipped before the provider
        // setting existed carry no marker and are Veeqo's by definition.
        $provider = $this->plugin->normalize_provider( $order ? $order->get_meta( $m['provider'] ) : '', 'veeqo' );
        $mode     = '';

        if ( in_array( $provider, [ 'shippo', 'easypost' ], true ) ) {
            $mode = $order ? (string) $order->get_meta( $m['provider_mode'] ) : '';
            if ( ! in_array( $mode, [ 'test', 'live' ], true ) ) {
                // Marker incomplete (partial write): fall back to the active mode
                // rather than guessing production.
                $mode = $this->plugin->provider_mode( $provider );
            }
        }

        // Veeqo addresses a label by its remote shipment id; Shippo by the
        // transaction id and EasyPost by the shipment, both stored as the label id.
        $reference = in_array( $provider, [ 'shippo', 'easypost' ], true ) && $order
            ? (string) $order->get_meta( $m['label_id'] )
            : (string) $remote_id;

        return [
            'order'           => $order,
            'remote_id'       => $remote_id,
            'provider'        => $provider,
            'provider_mode'   => $mode,
            'label_reference' => $reference,
        ];
    }

    /**
     * Client that owns a stored label, plus the identifier that client needs.
     *
     * Routing by the stored marker is what keeps old labels actionable: a Veeqo
     * label bought last month must still be refunded through Veeqo after the
     * setting has been switched to Shippo.
     *
     * @param array $context Result of get_shipment_action_context().
     * @return array|WP_Error ['client' => provider client, 'reference' => label id].
     */
    protected function label_client( array $context ) {
        $provider = $context['provider'];
        $mode     = $context['provider_mode'];

        if ( 'shippo' === $provider && '' === $this->plugin->shippo_api_key( $mode ) ) {
            return new WP_Error(
                'shippo_key_missing',
                'live' === $mode
                    ? __( 'This label was bought with Shippo in Production mode, but no Production API key is set.', 'shipping-desk' )
                    : __( 'This label was bought with Shippo in Test mode, but no Test API key is set.', 'shipping-desk' )
            );
        }

        if ( 'easypost' === $provider && '' === $this->plugin->easypost_api_key( $mode ) ) {
            return new WP_Error(
                'easypost_key_missing',
                'live' === $mode
                    ? __( 'This label was bought with EasyPost in Production mode, but no Production API key is set.', 'shipping-desk' )
                    : __( 'This label was bought with EasyPost in Test mode, but no Test API key is set.', 'shipping-desk' )
            );
        }

        if ( '' === (string) $context['label_reference'] ) {
            return new WP_Error( 'label_reference_missing', __( 'This order has no stored label id.', 'shipping-desk' ) );
        }

        return [
            'client'    => $this->plugin->api( $provider, $mode ),
            'reference' => (string) $context['label_reference'],
        ];
    }

    public function shipping_desk_print_label() {
        $this->plugin->require_cap();
        $context = $this->get_shipment_action_context();
        $target  = $this->label_client( $context );
        if ( is_wp_error( $target ) ) {
            wp_send_json_error( [ 'message' => $target->get_error_message() ], 400 );
        }

        // Always request a fresh URL: Veeqo label URLs carry expiring tokens and
        // Shippo label URLs are signed with their own expiry.
        $label_url = $this->printable_label_url( $target );
        if ( is_wp_error( $label_url ) ) {
            // Say when waiting is the right answer, so the browser can retry itself rather
            // than asking the operator to click Print again.
            wp_send_json_error(
                [
                    'message'   => $label_url->get_error_message(),
                    'retryable' => in_array( $label_url->get_error_code(), [ 'easypost_label_missing', 'easypost_not_found', 'shippo_no_label' ], true ),
                ],
                500
            );
        }
        $label_url = Shipping_Desk_Helpers::absolute_url( $label_url );
        if ( empty( $label_url ) ) {
            wp_send_json_error( [ 'message' => __( 'The shipping provider did not return a label URL.', 'shipping-desk' ) ], 500 );
        }

        // If PrintNode mode is enabled, send the job to the server printer.
        $force_printnode = ! empty( $_POST['print_node'] );
        if ( $force_printnode || 'printnode' === get_option( 'shipping_desk_print_mode', 'browser' ) ) {
            $printer_id = (int) get_option( 'shipping_desk_printnode_printer_id', 0 );
            if ( ! $printer_id ) {
                wp_send_json_error( [ 'message' => __( 'PrintNode printer not selected.', 'shipping-desk' ) ], 400 );
            }
            $title  = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : 'Shipping Label';

            // A client that can hand back label bytes gets used directly: EasyPost's UPS
            // labels arrive on a 4x7 page that is a 4x6 label plus a blank inch, and a
            // 4x6 printer "fits" the taller page and shrinks the whole label. The client
            // crops the page box, and PrintNode accepts the bytes as base64 — which also
            // removes its need to fetch the carrier's storage URL itself.
            $printable = null;
            if ( method_exists( $target['client'], 'get_printable_label_pdf' ) ) {
                $printable = $target['client']->get_printable_label_pdf( $target['reference'] );
            }

            if ( is_array( $printable ) && ! empty( $printable['bytes'] ) ) {
                $result = $this->plugin->printnode()->send_print_job(
                    $printer_id,
                    $title,
                    'pdf_base64',
                    base64_encode( $printable['bytes'] ),
                    'Shipping Desk'
                );
            } else {
                // Fall back to a URL: either a client that cannot produce bytes, or a
                // download that failed. Printing must not break because our fetch did.
                if ( method_exists( $target['client'], 'get_label_pdf_url' ) ) {
                    $pdf_url = $target['client']->get_label_pdf_url( $target['reference'] );
                    if ( is_wp_error( $pdf_url ) ) {
                        wp_send_json_error( [ 'message' => $pdf_url->get_error_message() ], 500 );
                    }
                    $label_url = Shipping_Desk_Helpers::absolute_url( $pdf_url );
                }

                $result = $this->plugin->printnode()->send_print_job( $printer_id, $title, 'pdf_uri', $label_url, 'Shipping Desk' );
            }
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
            }
            wp_send_json_success( [
                'print_job_id' => $result['id'] ?? 0,
                'message'      => __( 'Label sent to PrintNode.', 'shipping-desk' ),
            ] );
        }

        // Browser mode: return the *printable* file rather than the carrier's own. UPS
        // labels are 4x7 and FedEx labels are a US letter page, so a 4x6 printer fits
        // either and prints the label small; the endpoint serves the same 4x6 bytes
        // PrintNode gets. A provider that cannot produce bytes keeps its own URL.
        $print_url = '';
        if ( method_exists( $target['client'], 'get_printable_label_pdf' ) ) {
            $incoming     = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
            $is_lookup    = '' !== (string) $context['remote_id'] && wp_verify_nonce( $incoming, Shipping_Desk_Helpers::lookup_nonce_action( $context['remote_id'] ) );
            $context_id   = (int) ( $context['order'] ? $context['order']->get_id() : 0 );
            $print_args   = [ 'action' => 'shipping_desk_print_pdf' ];

            if ( $is_lookup ) {
                $print_args['remote_id'] = $context['remote_id'];
                $print_args['order_id']  = $context_id;
                $print_args['_wpnonce']  = wp_create_nonce( Shipping_Desk_Helpers::lookup_nonce_action( $context['remote_id'] ) );
            } elseif ( $context_id ) {
                $print_args['order_id'] = $context_id;
                $print_args['_wpnonce'] = wp_create_nonce( 'shipping_desk_order_' . $context_id );
            }

            if ( isset( $print_args['_wpnonce'] ) ) {
                $print_url = add_query_arg( $print_args, admin_url( 'admin-ajax.php' ) );
            }
        }

        wp_send_json_success( [
            'label_url' => $label_url,
            'print_url' => $print_url,
        ] );
    }

    /**
     * Whether a provider-supplied label URL is safe to redirect the browser to.
     *
     * HTTPS is required. A store can additionally restrict the hosts with the
     * `shipping_desk_label_redirect_hosts` filter; an empty list (the default) allows any
     * https host, which is what the carriers' own file stores use.
     *
     * @param string $url Provider-supplied label URL.
     * @return true|WP_Error True when the redirect may proceed.
     */
    protected function label_redirect_is_allowed( $url ) {
        $scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
        if ( 'https' !== $scheme ) {
            return new WP_Error(
                'shipping_desk_insecure_label_url',
                __( 'The provider returned a label link that is not https, so it was not opened.', 'shipping-desk' )
            );
        }

        $allowed = (array) apply_filters( 'shipping_desk_label_redirect_hosts', [] );
        if ( empty( $allowed ) ) {
            return true;
        }

        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        foreach ( $allowed as $candidate ) {
            $candidate = strtolower( ltrim( trim( (string) $candidate ), '.' ) );
            if ( '' === $candidate ) {
                continue;
            }
            if ( $host === $candidate || '.' . $candidate === substr( $host, -1 * ( strlen( $candidate ) + 1 ) ) ) {
                return true;
            }
        }

        return new WP_Error(
            'shipping_desk_disallowed_label_host',
            __( 'The provider returned a label on a host this store does not allow, so it was not opened.', 'shipping-desk' )
        );
    }

    /**
     * Stream the label as a 4x6 PDF for browser printing.
     *
     * Opened in a new tab, so this is a GET with a nonce rather than an AJAX POST. It
     * returns the same bytes PrintNode receives — the carrier's label on a 4x6 page
     * box — because the carriers' own files are the wrong page size for a 4x6 printer:
     * UPS returns 4x7 and FedEx returns a US letter page with the label drawn inside.
     * With the page box at 4x6, "actual size" fills the stock and even the default
     * "fit to page" lands at 100%, since the page and the paper are the same size.
     *
     * A provider that cannot produce bytes (Shippo's file is already 4x6) is simply
     * redirected to, so printing never breaks over this.
     *
     * @return void
     */
    public function shipping_desk_print_pdf() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'shipping-desk' ), '', [ 'response' => 403 ] );
        }

        $context = $this->get_shipment_action_context();
        $target  = $this->label_client( $context );
        if ( is_wp_error( $target ) ) {
            wp_die( esc_html( $target->get_error_message() ), '', [ 'response' => 400 ] );
        }

        $bytes = '';
        if ( method_exists( $target['client'], 'get_printable_label_pdf' ) ) {
            $printable = $target['client']->get_printable_label_pdf( $target['reference'] );
            if ( is_array( $printable ) && ! empty( $printable['bytes'] ) ) {
                $bytes = (string) $printable['bytes'];
            }
        }

        if ( '' === $bytes ) {
            // No bytes: either the provider's file is already the right size, or the
            // download failed. Either way, hand the browser the carrier's own URL
            // instead of showing an error where a label was expected.
            $label_url = $this->printable_label_url( $target );
            if ( is_wp_error( $label_url ) || '' === trim( (string) $label_url ) ) {
                wp_die(
                    esc_html__( 'The shipping provider did not return a label.', 'shipping-desk' ),
                    '',
                    [ 'response' => 502 ]
                );
            }

            // This redirect target comes from the provider, so it is vetted before use:
            // https only, and a host the store allows (any https host by default).
            $redirect_ok = $this->label_redirect_is_allowed( (string) $label_url );
            if ( is_wp_error( $redirect_ok ) ) {
                wp_die( esc_html( $redirect_ok->get_error_message() ), '', [ 'response' => 502 ] );
            }

            wp_redirect( esc_url_raw( $label_url ) );
            exit;
        }

        $context_id = (int) ( $context['order'] ? $context['order']->get_id() : 0 );
        $filename   = $context_id ? 'label-' . $context_id . '.pdf' : 'label.pdf';

        nocache_headers();
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: inline; filename="' . $filename . '"' );
        header( 'Content-Length: ' . strlen( $bytes ) );
        echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF
        exit;
    }

    public function shipping_desk_refund_label() {
        $this->plugin->require_cap();
        $context = $this->get_shipment_action_context();
        $order   = $context['order'];
        $target  = $this->label_client( $context );
        if ( is_wp_error( $target ) ) {
            wp_send_json_error( [ 'message' => $target->get_error_message() ], 400 );
        }

        $result = $target['client']->refund_shipment( $target['reference'] );
        if ( is_wp_error( $result ) ) {
            // Shippo knows something the order does not — typically a refund started
            // in their dashboard. Record it so the button stops offering a refund
            // that cannot happen.
            if ( $order ) {
                $m     = Shipping_Desk_Helpers::meta_keys();
                $codes = [ 'shippo_already_refunded', 'shippo_refund_pending', 'easypost_already_refunded', 'easypost_refund_pending' ];
                if ( in_array( $result->get_error_code(), $codes, true ) ) {
                    $order->update_meta_data( $m['refunded'], 1 );
                    $order->save();
                    // The provider says the label is already void, so the number is dead
                    // here too — otherwise it would sit beside the replacement's.
                    $this->plugin->labels()->remove_dead_tracking( $order, (string) $order->get_meta( $m['tracking'] ) );
                }
            }
            wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
        }

        if ( $order ) {
            $m = Shipping_Desk_Helpers::meta_keys();
            $order->update_meta_data( $m['refunded'], 1 );
            $order->save();
            // The carrier has given the money back, so the recorded cost goes too:
            // an empty value tells BrikPanel to fall back to what was charged.
            $this->plugin->labels()->clear_shipping_cost( $order );
            $order->add_order_note(
                'shippo' === $context['provider']
                    ? __( 'Shippo label refund requested.', 'shipping-desk' )
                    : ( 'easypost' === $context['provider']
                        ? __( 'EasyPost label refund requested.', 'shipping-desk' )
                        : __( 'Veeqo shipping label refunded/cancelled.', 'shipping-desk' ) )
            );
            // The label is void, so its number comes off the order as well: left there it
            // would sit beside the replacement's number in the next shipment email. After
            // the refund note, so the timeline reads refund, then removal.
            $this->plugin->labels()->remove_dead_tracking( $order, (string) $order->get_meta( $m['tracking'] ) );
        }

        wp_send_json_success( [ 'refunded' => true ] );
    }

    public function shipping_desk_reset_shipping() {
        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        $order    = $this->plugin->authorized_order( $order_id, 'shipping_desk_order_' . $order_id );
        // Read the dead label's number before the meta goes: after the reset there is
        // nothing left to match a tracking item against.
        $m        = Shipping_Desk_Helpers::meta_keys();
        $tracking = (string) $order->get_meta( $m['tracking'] );
        $refunded = (bool) $order->get_meta( $m['refunded'] );
        Shipping_Desk_Helpers::clear_shipping_meta( $order );
        // Reset means start over, so the cached address check goes too. Without
        // this a re-test of the same address is answered from a twelve-hour-old
        // transient and never reaches Shippo.
        $this->plugin->quote_store()->clear_address_validation_cache( $order_id );
        $this->plugin->quote_store()->delete_quote_record( $order_id );
        $order->add_order_note( __( 'Shipping Desk: shipping state reset  -  a new label can now be purchased.', 'shipping-desk' ) );
        // A reset on a live label leaves its tracking alone: that parcel may still be
        // moving. Only a refunded label's number is dead enough to remove, and by then
        // the refund has normally taken it already — forget() then finds nothing.
        if ( $refunded && '' !== $tracking ) {
            $this->plugin->labels()->remove_dead_tracking( $order, $tracking );
        }
        wp_send_json_success( [ 'reset' => true ] );
    }

    public function shipping_desk_printnode_print_label() {
        $this->plugin->require_cap();
        $context = $this->get_shipment_action_context();
        $target  = $this->label_client( $context );
        if ( is_wp_error( $target ) ) {
            wp_send_json_error( [ 'message' => $target->get_error_message() ], 400 );
        }

        $label_url = $this->printable_label_url( $target );
        if ( is_wp_error( $label_url ) ) {
            // Say when waiting is the right answer, so the browser can retry itself rather
            // than asking the operator to click Print again.
            wp_send_json_error(
                [
                    'message'   => $label_url->get_error_message(),
                    'retryable' => in_array( $label_url->get_error_code(), [ 'easypost_label_missing', 'easypost_not_found', 'shippo_no_label' ], true ),
                ],
                500
            );
        }
        $label_url = Shipping_Desk_Helpers::absolute_url( $label_url );
        if ( empty( $label_url ) ) {
            wp_send_json_error( [ 'message' => __( 'The shipping provider did not return a label URL.', 'shipping-desk' ) ], 500 );
        }

        $printer_id = (int) get_option( 'shipping_desk_printnode_printer_id', 0 );
        if ( ! $printer_id ) {
            wp_send_json_error( [ 'message' => __( 'PrintNode printer not selected.', 'shipping-desk' ) ], 400 );
        }

        $title  = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : 'Shipping Label';
        $result = $this->plugin->printnode()->send_print_job( $printer_id, $title, 'pdf_uri', $label_url, 'Shipping Desk' );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
        }

        wp_send_json_success( [
            'print_job_id' => $result['id'] ?? 0,
            'message'      => __( 'Label sent to PrintNode.', 'shipping-desk' ),
        ] );
    }
}
