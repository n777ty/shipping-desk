<?php
/**
 * The Shipment Lookup page: ask a provider about a shipment id, work out which provider and
 * mode to ask, find the order that owns the shipment, and draw the answer with its print and
 * refund controls.
 *
 * Moved out of Shipping_Desk_Plugin without changing what it does. The provider, its mode, the
 * clients and the capability check are still asked of the plugin, so there is one answer to each.
 */
class Shipping_Desk_Lookup_Page {

    /** @var Shipping_Desk_Plugin */
    protected $plugin;

    public function __construct( Shipping_Desk_Plugin $plugin ) {
        $this->plugin = $plugin;
    }

    /**
     * The order that owns a provider id, if we have one.
     *
     * Both keys are checked: some providers record the parent shipment and others the
     * label itself. Returns 0 when nothing matches - which is exactly the case the
     * manual provider and mode selects exist for.
     *
     * @param string $shipment_id Id from the form.
     * @return int
     */
    protected function find_order_for_shipment( $shipment_id ) {
        if ( '' === (string) $shipment_id || ! function_exists( 'wc_get_orders' ) ) {
            return 0;
        }

        $m = Shipping_Desk_Helpers::meta_keys();

        foreach ( [ $m['remote_id'], $m['label_id'] ] as $key ) {
            $orders = wc_get_orders( [
                'limit'      => 1,
                'type'       => 'shop_order',
                'meta_key'   => $key,
                'meta_value' => $shipment_id,
            ] );

            if ( empty( $orders ) ) {
                continue;
            }

            $order = reset( $orders );

            if ( $order ) {
                return (int) $order->get_id();
            }
        }

        return 0;
    }

    /**
     * Which provider and mode to ask for one lookup.
     *
     * Order of preference: what the operator chose for this lookup, then what the order
     * recorded when its label was bought, then the provider setting. The mode travels
     * with the provider it was recorded with - a test label is not on the live account,
     * and that mismatch reads as "id not found" every time.
     *
     * Nothing here writes the provider setting: switching provider for a lookup must
     * never change which provider buys the next label.
     *
     * @param string $wanted_provider 'auto' or a provider slug.
     * @param string $wanted_mode     'auto', 'test' or 'live'.
     * @param int    $order_id        Order owning the id, or 0.
     * @return array [ provider, mode, source ] - source is order, chosen or setting.
     */
    protected function resolve_lookup_target( $wanted_provider, $wanted_mode, $order_id = 0 ) {
        $valid = [ 'veeqo', 'shippo', 'easypost' ];

        $recorded_provider = '';
        $recorded_mode     = '';

        if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) {
            $order = wc_get_order( (int) $order_id );

            if ( $order ) {
                $m                 = Shipping_Desk_Helpers::meta_keys();
                $recorded_provider = $this->plugin->normalize_provider( (string) $order->get_meta( $m['provider'] ), '' );
                $recorded_mode     = strtolower( (string) $order->get_meta( $m['provider_mode'] ) );
            }
        }

        if ( in_array( $wanted_provider, $valid, true ) ) {
            $provider = $wanted_provider;
            $source   = 'chosen';
        } elseif ( in_array( $recorded_provider, $valid, true ) ) {
            $provider = $recorded_provider;
            $source   = 'order';
        } else {
            $provider = $this->plugin->provider();
            $source   = 'setting';
        }

        // Veeqo has no key modes at all.
        if ( 'veeqo' === $provider ) {
            return [ $provider, '', $source ];
        }

        if ( in_array( $wanted_mode, [ 'test', 'live' ], true ) ) {
            return [ $provider, $wanted_mode, $source ];
        }

        if ( 'order' === $source && in_array( $recorded_mode, [ 'test', 'live' ], true ) ) {
            return [ $provider, $recorded_mode, $source ];
        }

        return [ $provider, $this->plugin->provider_mode( $provider ), $source ];
    }

    /**
     * Ask a provider for one shipment, the way that provider wants to be asked.
     *
     * Shippo is queried by transaction id first - the id in the order note - and falls
     * back to the shipment id its dashboard shows. EasyPost and Veeqo only know
     * shipments. Failures come back as WP_Error for the page to explain.
     *
     * @param string $provider    veeqo, shippo or easypost.
     * @param string $mode        test or live; '' for Veeqo.
     * @param string $shipment_id Id pasted by the operator.
     * @return array [ array|WP_Error, 'shipment'|'transaction' ]
     */
    protected function lookup_shipment( $provider, $mode, $shipment_id ) {
        $client = $this->plugin->api( $provider, 'veeqo' === $provider ? null : $mode );

        if ( 'shippo' !== $provider || ! method_exists( $client, 'get_transaction' ) ) {
            return [ $client->get_shipment( $shipment_id ), 'shipment' ];
        }

        $result = $client->get_transaction( $shipment_id );

        if ( ! is_wp_error( $result ) || 'shippo_not_found' !== $result->get_error_code() ) {
            return [ $result, 'transaction' ];
        }

        // A shipment id can still be pasted: it is what the Shippo dashboard shows.
        $as_shipment = $client->get_shipment( $shipment_id );

        return [ $as_shipment, is_wp_error( $as_shipment ) ? 'transaction' : 'shipment' ];
    }

    public function render_lookup_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'shipping-desk' ) );
        }
        $shipment_id      = '';
        $result           = null;
        $error            = '';
        $lookup_kind      = 'shipment';
        $matched_order_id = 0;

        // What the operator asked for, for this lookup only. Deliberately separate from
        // the label provider setting: choosing a provider here must never change which
        // provider buys the next label (that gesture is shipping_desk_switch_provider()).
        //
        // Both start on Auto and are not remembered between visits - no user meta, no
        // option. A one-off Shippo lookup must not become the silent default for the next
        // one, and Auto is the answer that is right until an id says otherwise. The page
        // says so in as many words above the chips.
        $wanted_provider = 'auto';
        $wanted_mode     = 'auto';

        $provider      = $this->plugin->provider();
        $mode          = $this->plugin->provider_mode( $provider );
        $lookup_source = 'setting';

        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['shipping_desk_lookup_nonce'] ) ) {
            if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['shipping_desk_lookup_nonce'] ) ), 'shipping_desk_lookup' ) ) {
                $error = __( 'Invalid nonce.', 'shipping-desk' );
            } else {
                $shipment_id     = sanitize_text_field( wp_unslash( $_POST['shipment_id'] ?? '' ) );
                $wanted_provider = sanitize_key( wp_unslash( $_POST['lookup_provider'] ?? 'auto' ) );
                $wanted_mode     = sanitize_key( wp_unslash( $_POST['lookup_mode'] ?? 'auto' ) );

                if ( $shipment_id === '' ) {
                    $error = __( 'Please enter a shipment ID.', 'shipping-desk' );
                } else {
                    // The order first: it records which provider and mode bought this
                    // label, which is the only trustworthy answer for an id that is ours.
                    $matched_order_id = $this->find_order_for_shipment( $shipment_id );

                    list( $provider, $mode, $lookup_source ) = $this->resolve_lookup_target( $wanted_provider, $wanted_mode, $matched_order_id );

                    // A key for the chosen mode, or a plain reason instead of "not found"
                    // for an id that simply lives on the other account.
                    $mode_key = '';
                    if ( 'shippo' === $provider ) {
                        $mode_key = (string) $this->plugin->shippo_api_key( $mode );
                    } elseif ( 'easypost' === $provider ) {
                        $mode_key = (string) $this->plugin->easypost_api_key( $mode );
                    }

                    if ( 'veeqo' !== $provider && '' === $mode_key ) {
                        $error = sprintf(
                            /* translators: 1: provider name, 2: key mode */
                            __( 'No %1$s %2$s key is configured, so this id cannot be looked up there. Choose the provider and mode the label was bought with.', 'shipping-desk' ),
                            'shippo' === $provider ? 'Shippo' : 'EasyPost',
                            'live' === $mode ? __( 'production', 'shipping-desk' ) : __( 'test', 'shipping-desk' )
                        );
                    } else {
                        list( $result, $lookup_kind ) = $this->lookup_shipment( $provider, $mode, $shipment_id );

                        if ( is_wp_error( $result ) ) {
                            $error = $result->get_error_message();

                            if ( 'shippo' === $provider && 'shippo_not_found' === $result->get_error_code() ) {
                                $error = sprintf(
                                    /* translators: 1: provider name, 2: key mode */
                                    __( 'No transaction or shipment with that id on %1$s (%2$s).', 'shipping-desk' ),
                                    'Shippo',
                                    'live' === $mode ? __( 'production', 'shipping-desk' ) : __( 'test', 'shipping-desk' )
                                );
                            }

                            $result = null;
                        }
                    }
                }
            }
        }

        // The line above the form.
        //
        // Before a lookup it can only say what is going to happen, and on Auto that is not a
        // provider yet: Auto means "ask the order, then the setting". Reading the setting
        // aloud before an id arrives presented a default as a fact.
        $mode_text = function ( $key ) {
            return 'live' === $key ? __( 'production', 'shipping-desk' ) : __( 'test', 'shipping-desk' );
        };
        $provider_text = function ( $key ) {
            return 'easypost' === $key ? 'EasyPost' : ( 'shippo' === $key ? 'Shippo' : 'Veeqo' );
        };

        $default_provider = $this->plugin->provider();
        $default_name     = $provider_text( $default_provider );
        $default_mode_key = $this->plugin->provider_mode( $default_provider );
        $default_mode     = '' === $default_mode_key ? '' : $mode_text( $default_mode_key );

        if ( 'order' === $lookup_source && $matched_order_id > 0 ) {
            /* translators: %d: order id */
            $source_text = sprintf( __( 'recorded on order #%d.', 'shipping-desk' ), (int) $matched_order_id );
        } elseif ( 'chosen' === $lookup_source ) {
            $source_text = __( 'chosen for this lookup.', 'shipping-desk' );
        } else {
            $source_text = __( 'from the provider setting.', 'shipping-desk' );
        }

        if ( '' !== $shipment_id && is_array( $result ) ) {
            $target_line = '' === $mode
                ? sprintf( __( 'Looked up on %1$s — %2$s', 'shipping-desk' ), $provider_text( $provider ), $source_text )
                : sprintf( __( 'Looked up on %1$s (%2$s) — %3$s', 'shipping-desk' ), $provider_text( $provider ), $mode_text( $mode ), $source_text );
        } elseif ( '' !== $shipment_id ) {
            $target_line = '' === $mode
                ? sprintf( __( 'Tried %1$s — %2$s', 'shipping-desk' ), $provider_text( $provider ), $source_text )
                : sprintf( __( 'Tried %1$s (%2$s) — %3$s', 'shipping-desk' ), $provider_text( $provider ), $mode_text( $mode ), $source_text );
        } elseif ( 'auto' === $wanted_provider ) {
            $target_line = sprintf(
                /* translators: %s: the label setting, e.g. EasyPost, test */
                __( 'Will look up on Auto — the provider and mode recorded on the order, else the label setting (%s).', 'shipping-desk' ),
                '' === $default_mode ? $default_name : sprintf( '%1$s, %2$s', $default_name, $default_mode )
            );
        } else {
            $chosen_mode = in_array( $wanted_mode, [ 'test', 'live' ], true ) ? $wanted_mode : $this->plugin->provider_mode( $wanted_provider );
            $target_line = '' === $chosen_mode
                ? sprintf( __( 'Will look up on %s.', 'shipping-desk' ), $provider_text( $wanted_provider ) )
                : sprintf(
                    /* translators: 1: provider name, 2: key mode */
                    __( 'Will look up on %1$s (%2$s).', 'shipping-desk' ),
                    $provider_text( $wanted_provider ),
                    $mode_text( $chosen_mode )
                );
        }
        ?>
        <div class="wrap shipping-desk-wrap">
            <h1><?php esc_html_e( 'Shipment Lookup', 'shipping-desk' ); ?></h1>
            <p>
                <?php
                if ( 'auto' === $wanted_provider ) {
                    esc_html_e( 'Paste the shipment or transaction id from the order note. On Auto the provider and mode come from the order that owns that id, and from the label setting when no order matches.', 'shipping-desk' );
                } elseif ( 'shippo' === $wanted_provider ) {
                    esc_html_e( 'Enter the Shippo transaction id from the order note. A shipment id also works if you have one.', 'shipping-desk' );
                } elseif ( 'easypost' === $wanted_provider ) {
                    esc_html_e( 'Enter the EasyPost shipment id from the order note.', 'shipping-desk' );
                } else {
                    esc_html_e( 'Enter the Veeqo remote shipment id recorded on the order.', 'shipping-desk' );
                }
                ?>
            </p>
            <p>
                <strong id="lookup-target-line"><?php echo esc_html( $target_line ); ?></strong>
            </p>
            <form method="post">
                <?php wp_nonce_field( 'shipping_desk_lookup', 'shipping_desk_lookup_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="shipment_id"><?php esc_html_e( 'Shipment ID', 'shipping-desk' ); ?></label></th>
                        <td><input type="text" id="shipment_id" name="shipment_id" value="<?php echo esc_attr( $shipment_id ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Look up on', 'shipping-desk' ); ?></th>
                        <td>
                            <?php
                            // Veeqo has one account and no test mode, so there is nothing for the mode
                            // chip to cycle: while it is selected the chip is pinned to Live and used
                            // as a disabled button, rather than offering two modes it does not have.
                            // The field still carries live, because that is what the lookup will do.
                            $mode_locked       = 'veeqo' === $wanted_provider;
                            $mode_locked_title = __( 'Veeqo has one account and no test mode, so it always runs live. Choose another provider to pick a mode.', 'shipping-desk' );
                            ?>
                            <input type="hidden" id="lookup_provider" name="lookup_provider" value="<?php echo esc_attr( $wanted_provider ); ?>" />
                            <input type="hidden" id="lookup_mode" name="lookup_mode" value="<?php echo esc_attr( $mode_locked ? 'live' : $wanted_mode ); ?>" />
                            <?php
                            $mode_labels = [
                                'auto' => __( 'Auto', 'shipping-desk' ),
                                'test' => __( 'Test', 'shipping-desk' ),
                                'live' => __( 'Live', 'shipping-desk' ),
                            ];
                            $mode_titles = [
                                'auto' => __( 'Auto: the mode the label was bought in. Click for Test.', 'shipping-desk' ),
                                'test' => __( 'Test: labels cost nothing. Click for Live.', 'shipping-desk' ),
                                'live' => __( 'Live: real labels. Click to go back to Auto.', 'shipping-desk' ),
                            ];
                            ?>
                            <div class="shipping-desk-provider-switch"
                                 id="shipping-desk-lookup-track"
                                 role="group"
                                 data-default-provider="<?php echo esc_attr( $default_name ); ?>"
                                 data-default-mode="<?php echo esc_attr( $default_mode ); ?>"
                                 data-mode-test="<?php echo esc_attr( $mode_text( 'test' ) ); ?>"
                                 data-mode-live="<?php echo esc_attr( $mode_text( 'live' ) ); ?>"
                                 aria-label="<?php esc_attr_e( 'Where to look the shipment up', 'shipping-desk' ); ?>">
                                <?php foreach ( [ 'auto' => __( 'Auto', 'shipping-desk' ), 'easypost' => 'EasyPost', 'shippo' => 'Shippo', 'veeqo' => 'Veeqo' ] as $slug => $label ) : ?>
                                    <button type="button"
                                            class="shipping-desk-provider-btn shipping-desk-lookup-chip<?php echo $wanted_provider === $slug ? ' is-active' : ''; ?>"
                                            data-target="lookup_provider"
                                            data-value="<?php echo esc_attr( $slug ); ?>"
                                            aria-pressed="<?php echo $wanted_provider === $slug ? 'true' : 'false'; ?>"><?php echo esc_html( $label ); ?></button>
                                <?php endforeach; ?>
                                <button type="button"
                                        class="shipping-desk-mode-chip is-<?php echo esc_attr( $mode_locked ? 'live' : $wanted_mode ); ?> shipping-desk-lookup-mode"
                                        data-mode="<?php echo esc_attr( $mode_locked ? 'live' : $wanted_mode ); ?>"
                                        data-labels="<?php echo esc_attr( (string) wp_json_encode( $mode_labels ) ); ?>"
                                        data-titles="<?php echo esc_attr( (string) wp_json_encode( $mode_titles ) ); ?>"
                                        data-locked-title="<?php echo esc_attr( $mode_locked_title ); ?>"
                                        <?php if ( $mode_locked ) : ?>disabled="disabled"<?php endif; ?>
                                        title="<?php echo esc_attr( $mode_locked ? $mode_locked_title : $mode_titles[ $wanted_mode ] ); ?>"
                                        aria-label="<?php echo esc_attr( $mode_locked ? $mode_locked_title : $mode_titles[ $wanted_mode ] ); ?>"><?php echo esc_html( $mode_labels[ $mode_locked ? 'live' : $wanted_mode ] ); ?></button>
                            </div>
                            <p class="description">
                                <?php esc_html_e( 'Auto uses the provider and mode recorded on the order, then the label setting. The mode chip cycles Auto, Test and Live. Veeqo has one account, so its chip pins to Live and cannot be cycled.', 'shipping-desk' ); ?>
                                <strong><?php esc_html_e( 'This page starts on Auto every time: the choice is not remembered between visits.', 'shipping-desk' ); ?></strong>
                                <?php esc_html_e( 'None of this changes which provider buys the next label.', 'shipping-desk' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Look up', 'shipping-desk' ) ); ?>
            </form>

            <?php if ( $error ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
            <?php endif; ?>

            <?php if ( is_array( $result ) ) :
                $lookup_order_id     = $matched_order_id;
                $lookup_action_nonce = wp_create_nonce( 'shipping_desk_lookup' );
                $cancelled_at        = (string) ( $result['cancelled_at'] ?? '' );

                // Refund state comes from the provider's own status where it has one.
                // Veeqo reports cancellation through `cancelled`; Shippo reports a
                // transaction status. EasyPost reports the shipment's own status and
                // the refund separately, so its refund_status is folded into the same
                // vocabulary here. A refund that is pending with the carrier must
                // not offer the button again.
                $label_status = strtoupper( (string) ( isset( $result['status'] ) ? $result['status'] : '' ) );

                if ( 'easypost' === $provider ) {
                    $refund_status = strtolower( (string) ( isset( $result['refund_status'] ) ? $result['refund_status'] : '' ) );
                    if ( 'refunded' === $refund_status ) {
                        $label_status = 'REFUNDED';
                    } elseif ( 'submitted' === $refund_status ) {
                        $label_status = 'REFUNDPENDING';
                    } else {
                        // Any other state is a live label as far as the UI is concerned.
                        $label_status = 'SUCCESS';
                    }
                }

                $is_refunded  = 'REFUNDED' === $label_status || ! empty( $result['cancelled'] );
                $is_pending   = 'REFUNDPENDING' === $label_status;
                $can_refund   = '' === $label_status
                    ? ! $is_refunded
                    : ( 'SUCCESS' === $label_status );
            ?>
                <h2>
                    <?php
                    echo esc_html(
                        'transaction' === $lookup_kind
                            ? __( 'Transaction details', 'shipping-desk' )
                            : __( 'Shipment details', 'shipping-desk' )
                    );
                    ?>
                </h2>
                <?php if ( 'transaction' === $lookup_kind ) : ?>
                    <p class="description"><?php esc_html_e( 'Matched a transaction id. The parent shipment id is listed below if you need it.', 'shipping-desk' ); ?></p>
                <?php elseif ( 'shippo' === $provider ) : ?>
                    <p class="description"><?php esc_html_e( 'Matched a shipment id rather than a transaction id.', 'shipping-desk' ); ?></p>
                <?php endif; ?>
                <table class="widefat fixed striped">
                    <tbody>
                        <tr data-order-id="<?php echo esc_attr( $lookup_order_id ); ?>" data-nonce="<?php echo esc_attr( $lookup_action_nonce ); ?>" data-remote-id="<?php echo esc_attr( $shipment_id ); ?>" data-label-refunded="<?php echo ( $is_refunded || $is_pending ) ? '1' : '0'; ?>">
                            <th style="width:220px;"><?php esc_html_e( 'Label Actions', 'shipping-desk' ); ?></th>
                            <td>
                                <button type="button" class="shipping-desk-print-label"><?php esc_html_e( 'Print', 'shipping-desk' ); ?></button>
                                <?php if ( $can_refund ) : ?>
                                    <button type="button" class="shipping-desk-refund-label"><?php esc_html_e( 'Refund', 'shipping-desk' ); ?></button>
                                <?php elseif ( $is_pending ) : ?>
                                    <span class="shipping-desk-label-refunded"><?php esc_html_e( 'Refund pending with the carrier.', 'shipping-desk' ); ?></span>
                                <?php elseif ( $is_refunded ) : ?>
                                    <span class="shipping-desk-label-refunded"><?php echo esc_html( 'REFUNDED' === $label_status ? __( 'Label refunded.', 'shipping-desk' ) : __( 'Label cancelled.', 'shipping-desk' ) ); ?></span>
                                <?php else : ?>
                                    <span class="shipping-desk-label-refunded">
                                        <?php
                                        printf(
                                            /* translators: %s: provider status, e.g. WAITING */
                                            esc_html__( 'No refund available (status: %s).', 'shipping-desk' ),
                                            esc_html( $label_status )
                                        );
                                        ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ( $cancelled_at ) : ?>
                                    <small><?php echo esc_html( sprintf( __( 'Cancelled at: %s', 'shipping-desk' ), $cancelled_at ) ); ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php foreach ( $result as $key => $value ) :
                        if ( is_array( $value ) ) {
                            $display = '<pre>' . esc_html( wp_json_encode( $value, JSON_PRETTY_PRINT ) ) . '</pre>';
                        } else {
                            $display = esc_html( (string) $value );
                        }
                    ?>
                        <tr>
                            <th style="width:220px;"><?php echo esc_html( $key ); ?></th>
                            <td><?php echo $display; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <h3><?php esc_html_e( 'Raw response', 'shipping-desk' ); ?></h3>
                <pre><?php echo esc_html( wp_json_encode( $result, JSON_PRETTY_PRINT ) ); ?></pre>
            <?php endif; ?>
        </div>
        <?php
    }
}
