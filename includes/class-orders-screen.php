<?php
/**
 * The orders screen: the Processing and Completed lists, their rows, the bulk-action bar and
 * the pagination, and the AJAX action that renders one purchase row for a reship panel.
 *
 * Moved out of Shipping_Desk_Plugin without changing what it does. The provider, its mode, the
 * clients and the capability check are still asked of the plugin, so there is one answer to each.
 */
class Shipping_Desk_Orders_Screen {

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
        foreach ( [ 'shipping_desk_render_purchase_row' ] as $action ) {
            add_action( 'wp_ajax_' . $action, [ $this, $action ] );
        }
    }

    public function render_orders_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'shipping-desk' ) );
        }
        $view     = isset( $_GET['view'] ) && $_GET['view'] === 'completed' ? 'completed' : 'processing';
        $statuses = $view === 'completed' ? [ 'wc-completed' ] : [ 'wc-processing' ];
        $page     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $per_page = 20;
        $query_args = [
            'status'   => $statuses,
            'type'     => 'shop_order',
            'limit'    => $per_page,
            'paged'    => $page,
            'orderby'  => 'date',
            'order'    => 'DESC',
            'paginate' => true,
        ];
        $results      = wc_get_orders( $query_args );
        $total_orders = (int) $results->total;
        $total_pages  = max( 1, (int) $results->max_num_pages );

        if ( $page > $total_pages ) {
            $page                 = $total_pages;
            $query_args['paged']  = $page;
            $results              = wc_get_orders( $query_args );
        }
        // Tab counts. The active view's total is already known, so only the
        // other status needs a query — limit 1 row, since only the total matters.
        $other_status = ( 'completed' === $view ) ? 'wc-processing' : 'wc-completed';
        $other_result = wc_get_orders( [
            'status'   => [ $other_status ],
            'type'     => 'shop_order',
            'limit'    => 1,
            'paginate' => true,
        ] );
        $other_total = (int) $other_result->total;

        $processing_total = ( 'processing' === $view ) ? $total_orders : $other_total;
        $completed_total  = ( 'completed' === $view ) ? $total_orders : $other_total;

        $orders = $results->orders;
        $boxes  = get_option( 'shipping_desk_boxes', [] );
        if ( ! is_array( $boxes ) ) {
            $boxes = [];
        }
        // Sort only the dropdown copy; keep the saved Box Settings order unchanged.
        usort( $boxes, function ( $a, $b ) {
            $name_a = trim( (string) ( $a['name'] ?? '' ) );
            $name_b = trim( (string) ( $b['name'] ?? '' ) );
            if ( $name_a === '' && $name_b !== '' ) {
                return 1;
            }
            if ( $name_a !== '' && $name_b === '' ) {
                return -1;
            }
            $name_compare = strnatcasecmp( $name_a, $name_b );
            if ( $name_compare !== 0 ) {
                return $name_compare;
            }
            return (float) ( $a['width'] ?? 0 ) <=> (float) ( $b['width'] ?? 0 );
        } );
        ?>
        <div class="wrap shipping-desk-wrap">
            <div class="shipping-desk-header">
                <h1><?php esc_html_e( 'Shipping Desk', 'shipping-desk' ); ?></h1>
                <?php
                /*
                 * Two rows on purpose: the title gets the width of the page to itself, and
                 * the two working things sit under it - where labels come from on the left,
                 * what they have cost on the right. In one row the 22px total competed with
                 * the title and won, because the title inherited core's 23px/400.
                 */
                ?>
                <div class="shipping-desk-controls">
                    <?php $this->plugin->render_provider_switch(); ?>
                    <span class="shipping-desk-provider-msg" role="status" aria-live="polite"></span>
                    <?php $this->plugin->render_daily_spend(); ?>
                </div>
            </div>
            <?php if ( in_array( $this->plugin->provider(), [ 'shippo', 'easypost' ], true ) && 'test' === $this->plugin->provider_mode( $this->plugin->provider() ) ) : ?>
                <div class="notice notice-warning inline">
                    <p>
                        <?php
                        printf(
                            /* translators: %s: provider name, e.g. EasyPost */
                            esc_html__( '%s is in TEST mode: rates and labels come from your test account and no money is spent. Switch to Production under Settings when you are ready to buy real labels.', 'shipping-desk' ),
                            esc_html( 'easypost' === $this->plugin->provider() ? 'EasyPost' : 'Shippo' )
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>
            <?php $missing_from = $this->plugin->from_address_problems(); ?>
            <?php if ( ! empty( $missing_from ) ) : ?>
                <div class="notice notice-warning inline">
                    <p>
                        <?php
                        printf(
                            wp_kses_post(
                                /* translators: 1: missing from-address fields, 2: settings link */
                                __( 'The shipment origin has no %1$s. Carriers expect a phone number, and USPS refuses a label without a phone and email, so some label purchases will fail until it is set in <a href="%2$s">Settings → From Address</a>.', 'shipping-desk' )
                            ),
                            esc_html( implode( ' / ', $missing_from ) ),
                            esc_url( admin_url( 'admin.php?page=shipping-desk-settings' ) )
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>
            <nav class="shipping-desk-tabs">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=shipping-desk&view=processing' ) ); ?>" class="shipping-desk-tab <?php echo $view === 'processing' ? 'active' : ''; ?>"><?php esc_html_e( 'Processing', 'shipping-desk' ); ?> <span class="shipping-desk-tab-count">(<?php echo esc_html( number_format_i18n( $processing_total ) ); ?>)</span></a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=shipping-desk&view=completed' ) ); ?>" class="shipping-desk-tab <?php echo $view === 'completed' ? 'active' : ''; ?>"><?php esc_html_e( 'Completed', 'shipping-desk' ); ?> <span class="shipping-desk-tab-count">(<?php echo esc_html( number_format_i18n( $completed_total ) ); ?>)</span></a>
            </nav>
            <?php if ( ! empty( $orders ) ) : ?>
                <?php $this->render_bulk_actions( $view ); ?>
            <?php endif; ?>
            <?php if ( $view === 'processing' ) : ?>
                <?php $this->render_processing_table( $orders, $boxes ); ?>
            <?php else : ?>
                <?php $this->render_completed_table( $orders ); ?>
            <?php endif; ?>
            <?php $this->render_pagination( $page, $total_pages, $total_orders, $view ); ?>
        </div>
        <?php
    }

    protected function render_bulk_actions( $view ) {
        $select_id = 'shipping-desk-bulk-action-' . $view;
        ?>
        <div class="shipping-desk-bulk-actions" data-bulk-nonce="<?php echo esc_attr( wp_create_nonce( 'shipping_desk_bulk_buy' ) ); ?>">
            <label for="<?php echo esc_attr( $select_id ); ?>" class="screen-reader-text"><?php esc_html_e( 'Bulk Actions', 'shipping-desk' ); ?></label>
            <select id="<?php echo esc_attr( $select_id ); ?>" class="shipping-desk-bulk-action-select">
                <option value=""><?php esc_html_e( 'Bulk Actions', 'shipping-desk' ); ?></option>
                <?php if ( $view === 'processing' ) : ?>
                    <option value="bulk-get-rates"><?php esc_html_e( 'Bulk Get Rates', 'shipping-desk' ); ?></option>
                    <option value="bulk-buy-print"><?php esc_html_e( 'Bulk Buy + Print', 'shipping-desk' ); ?></option>
                    <option value="bulk-buy-label"><?php esc_html_e( 'Bulk Buy Label', 'shipping-desk' ); ?></option>
                <?php endif; ?>
                <option value="bulk-print"><?php esc_html_e( 'Bulk Print', 'shipping-desk' ); ?></option>
            </select>
            <button type="button" class="shipping-desk-bulk-apply"><?php esc_html_e( 'Apply', 'shipping-desk' ); ?></button>
            <span class="shipping-desk-bulk-status" role="status" aria-live="polite"></span>
        </div>
        <?php
    }

    protected function render_pagination( $current_page, $total_pages, $total_orders, $view ) {
        if ( $total_pages <= 1 ) {
            return;
        }

        $base_url   = admin_url( 'admin.php?page=shipping-desk&view=' . $view );
        $page_links = paginate_links( [
            'base'      => str_replace( 999999999, '%#%', add_query_arg( 'paged', 999999999, $base_url ) ),
            'format'    => '',
            'current'   => $current_page,
            'total'     => $total_pages,
            'prev_text' => __( '&laquo; Previous', 'shipping-desk' ),
            'next_text' => __( 'Next &raquo;', 'shipping-desk' ),
            'end_size'  => 1,
            'mid_size'  => 2,
            'add_args'  => false,
            'type'      => 'array',
        ] );

        if ( empty( $page_links ) || ! is_array( $page_links ) ) {
            return;
        }
        ?>
        <div class="tablenav bottom shipping-desk-pagination">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo esc_html( sprintf( _n( '%s order', '%s orders', $total_orders, 'shipping-desk' ), number_format_i18n( $total_orders ) ) ); ?></span>
                <?php foreach ( $page_links as $page_link ) : ?>
                    <?php echo wp_kses_post( $page_link ); ?>
                <?php endforeach; ?>
                <span class="shipping-desk-page-summary"><?php echo esc_html( sprintf( __( 'Page %1$s of %2$s', 'shipping-desk' ), number_format_i18n( $current_page ), number_format_i18n( $total_pages ) ) ); ?></span>
                <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="shipping-desk-page-form">
                    <input type="hidden" name="page" value="shipping-desk" />
                    <input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>" />
                    <label class="screen-reader-text" for="shipping-desk-page-jump"><?php esc_html_e( 'Page number', 'shipping-desk' ); ?></label>
                    <input id="shipping-desk-page-jump" type="number" name="paged" class="shipping-desk-page-jump" min="1" max="<?php echo esc_attr( $total_pages ); ?>" value="<?php echo esc_attr( $current_page ); ?>" required />
                    <button type="submit" class="shipping-desk-page-go"><?php esc_html_e( 'Go', 'shipping-desk' ); ?></button>
                </form>
            </div>
        </div>
        <?php
    }

    protected function render_processing_table( $orders, $boxes ) {
        ?>
        <table class="widefat fixed striped shipping-desk-table">
            <thead><tr>
                <th class="shipping-desk-select-column"><input type="checkbox" class="shipping-desk-select-all" aria-label="<?php esc_attr_e( 'Select all orders on this page', 'shipping-desk' ); ?>" /></th>
                <th class="shipping-desk-order-customer"><?php esc_html_e( 'Order / Customer', 'shipping-desk' ); ?></th>
                <th class="shipping-desk-order-totals"><?php esc_html_e( 'Total Spent', 'shipping-desk' ); ?></th>
                <th class="shipping-desk-method"><?php esc_html_e( 'Shipping Method', 'shipping-desk' ); ?></th>
                <th class="shipping-desk-box-column"><?php esc_html_e( 'Box / Weight (oz)', 'shipping-desk' ); ?></th>
                <th class="shipping-desk-services-column"><?php esc_html_e( 'Services', 'shipping-desk' ); ?></th>
                <th class="shipping-desk-rates"><?php esc_html_e( 'Rates', 'shipping-desk' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'shipping-desk' ); ?></th>
            </tr></thead>
            <tbody>
            <?php if ( empty( $orders ) ) : ?>
                <tr><td colspan="8"><?php esc_html_e( 'No processing orders found.', 'shipping-desk' ); ?></td></tr>
            <?php else : foreach ( $orders as $order ) :
                $this->render_order_row( $order, $boxes );
            endforeach; endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render one purchasable order row (rate shopping, services, insurance).
     *
     * Shared by the Processing table and the Completed tab's Reship expansion so
     * both stay in step. Markup is intentionally identical in either context.
     *
     * @param WC_Order $order Order to render.
     * @param array    $boxes Box presets for the dropdown.
     */
    protected function render_order_row( $order, $boxes ) {
        $order_id      = $order->get_id();
        $items_weight  = Shipping_Desk_Helpers::get_order_items_weight_oz( $order );
        $method        = Shipping_Desk_Helpers::get_order_shipping_method_label( $order );
        $order_nonce   = wp_create_nonce( 'shipping_desk_order_' . $order_id );
        $customer      = method_exists( $order, 'get_formatted_billing_full_name' ) ? $order->get_formatted_billing_full_name() : trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $currency      = $order->get_currency();
        $shipping_paid = max( 0, (float) $order->get_shipping_total() );

        // A row can carry an order that already has a label but is not Completed: the Completed
        // transition was skipped, or the store turned "mark Completed on purchase" off. Such an
        // order is never bought again — the buy guard refuses on the label meta — so its actions
        // are the label's, not rates. Without this the order is a dead end (RW-7).
        $m         = Shipping_Desk_Helpers::meta_keys();
        $label_id  = (string) $order->get_meta( $m['label_id'] );
        $has_label = '' !== $label_id || '' !== (string) $order->get_meta( $m['remote_id'] );
        $refunded  = (bool) $order->get_meta( $m['refunded'] );

        // Services that are priced into the rate. Shippo and EasyPost both price
        // signature confirmation at quote time (EasyPost through shipment
        // options), so both get the dropdown; Veeqo prices VAS after the quotes.
        $provider          = $this->plugin->provider();
        $quotes_signature  = in_array( $provider, [ 'shippo', 'easypost' ], true );
        $insurance_title   = 'easypost' === $provider
            ? __( 'Declared value for EasyPost insurance. EasyPost charges 1% of this with a $1.00 minimum (maximum $5,000), prices it at purchase, and adds it to the recorded label cost rather than to the quotes.', 'shipping-desk' )
            : __( 'Declared value for insurance. Set before getting rates.', 'shipping-desk' );

        // Quotes stored by the last Get Rates, when they are still usable, so a reload
        // or another machine shows the same prices without paying for them again.
        $quote_record = $this->plugin->quote_store()->fresh_quote_record( $order_id );
        $quote_data   = '';
        if ( ! empty( $quote_record ) ) {
            $quote_data = wp_json_encode( [
                'rates'              => isset( $quote_record['rates'] ) ? $quote_record['rates'] : [],
                'matched_rate_id'    => isset( $quote_record['matched_rate_id'] ) ? $quote_record['matched_rate_id'] : null,
                'address_validation' => isset( $quote_record['address_validation'] ) ? $quote_record['address_validation'] : null,
                'inputs'             => isset( $quote_record['inputs'] ) ? $quote_record['inputs'] : [],
                'created_at'         => isset( $quote_record['created_at'] ) ? (int) $quote_record['created_at'] : 0,
            ] );
        }
        ?>
                <tr data-order-id="<?php echo esc_attr( $order_id ); ?>" data-nonce="<?php echo esc_attr( $order_nonce ); ?>" data-provider="<?php echo esc_attr( $provider ); ?>" data-items-weight="<?php echo esc_attr( number_format( $items_weight, 2, '.', '' ) ); ?>"<?php if ( '' !== $quote_data ) : ?> data-quotes="<?php echo esc_attr( $quote_data ); ?>"<?php endif; ?>>
                    <td class="shipping-desk-select-column"><input type="checkbox" class="shipping-desk-order-select" name="shipping_desk_order_ids[]" value="<?php echo esc_attr( $order_id ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Select order #%s', 'shipping-desk' ), $order->get_order_number() ) ); ?>" /></td>
                    <td class="shipping-desk-order-customer">
                        <a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>" target="_blank" rel="noopener noreferrer" class="shipping-desk-order-link">
                            <strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong> <strong><?php echo esc_html( $customer ); ?></strong>
                        </a>
                        <div class="shipping-desk-address">
                            <?php echo esc_html( trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ) ); ?><br />
                            <?php echo esc_html( $order->get_shipping_address_1() ); ?><br />
                            <?php if ( $order->get_shipping_address_2() ) : ?>
                                <?php echo esc_html( $order->get_shipping_address_2() ); ?><br />
                            <?php endif; ?>
                            <?php echo esc_html( $order->get_shipping_city() ); ?>, <?php echo esc_html( $order->get_shipping_state() ); ?> <?php echo esc_html( $order->get_shipping_postcode() ); ?>
                        </div>
                    </td>
                    <td class="shipping-desk-order-totals">
                        <div class="shipping-desk-order-total"><strong><?php echo wp_kses_post( wc_price( $order->get_total(), [ 'currency' => $currency ] ) ); ?></strong></div>
                        <div class="shipping-desk-shipping-paid"><?php esc_html_e( 'Shipping:', 'shipping-desk' ); ?> <?php echo wp_kses_post( wc_price( $shipping_paid, [ 'currency' => $currency ] ) ); ?></div>
                    </td>
                    <td class="shipping-desk-method"><?php echo esc_html( $method ); ?></td>
                    <td class="shipping-desk-box-column">
                        <label class="shipping-desk-service-name" for="shipping-desk-box-<?php echo esc_attr( $order_id ); ?>"><?php esc_html_e( 'Box', 'shipping-desk' ); ?></label>
                        <select class="shipping-desk-box-select" id="shipping-desk-box-<?php echo esc_attr( $order_id ); ?>">
                            <option value=""><?php esc_html_e( 'Select box...', 'shipping-desk' ); ?></option>
                            <?php foreach ( $boxes as $idx => $box ) :
                                printf( '<option value="%1$s" data-length="%2$s" data-width="%3$s" data-height="%4$s" data-weight="%5$s">%6$s</option>',
                                    esc_attr( $idx ), esc_attr( $box['length'] ?? 0 ), esc_attr( $box['width'] ?? 0 ), esc_attr( $box['height'] ?? 0 ), esc_attr( $box['weight'] ?? 0 ),
                                    esc_html( $box['name'] ?? ( 'Box ' . ( $idx + 1 ) ) ) );
                            endforeach; ?>
                            <?php // Last on purpose: the one-off case, below every saved box. ?>
                            <option value="manual"><?php esc_html_e( 'Manual...', 'shipping-desk' ); ?></option>
                        </select>
                        <?php
                        /*
                         * Shown only while "Manual..." is chosen (see .is-visible in admin.css).
                         * Three inputs, not a stored box: one-off dimensions are typed here and
                         * never saved to Box Settings. The weight row below stays the whole total.
                         */
                        ?>
                        <div class="shipping-desk-manual-dimensions">
                            <input type="number" step="0.01" min="0" class="shipping-desk-manual-dimension" data-dimension="length" placeholder="L" aria-label="<?php esc_attr_e( 'Length (in)', 'shipping-desk' ); ?>" title="<?php esc_attr_e( 'Length (in)', 'shipping-desk' ); ?>" />
                            <input type="number" step="0.01" min="0" class="shipping-desk-manual-dimension" data-dimension="width" placeholder="W" aria-label="<?php esc_attr_e( 'Width (in)', 'shipping-desk' ); ?>" title="<?php esc_attr_e( 'Width (in)', 'shipping-desk' ); ?>" />
                            <input type="number" step="0.01" min="0" class="shipping-desk-manual-dimension" data-dimension="height" placeholder="H" aria-label="<?php esc_attr_e( 'Height (in)', 'shipping-desk' ); ?>" title="<?php esc_attr_e( 'Height (in)', 'shipping-desk' ); ?>" />
                        </div>
                        <div class="shipping-desk-box-weight-row">
                            <label class="shipping-desk-weight-label" for="shipping-desk-weight-<?php echo esc_attr( $order_id ); ?>"><?php esc_html_e( 'Weight', 'shipping-desk' ); ?></label>
                            <input type="number" step="0.01" min="0" id="shipping-desk-weight-<?php echo esc_attr( $order_id ); ?>" class="shipping-desk-total-weight" value="<?php echo esc_attr( number_format( $items_weight, 2, '.', '' ) ); ?>" title="<?php esc_attr_e( 'Calculated from items and box weight; edit to override', 'shipping-desk' ); ?>" />
                        </div>
                    </td>
                    <td class="shipping-desk-services-column">
                        <?php if ( $quotes_signature ) : ?>
                            <?php
                            // Shippo and EasyPost have no post-rate services, so the
                            // Veeqo VAS container would only ever hold placeholder
                            // text. The signature choice takes its place: same
                            // label-above, full-width select as Veeqo's confirmation
                            // dropdown. EasyPost's enums are mapped inside the client.
                            ?>
                            <div class="shipping-desk-service-group">
                                <label class="shipping-desk-service-name" for="shipping-desk-signature-<?php echo esc_attr( $order_id ); ?>"><?php esc_html_e( 'Signature', 'shipping-desk' ); ?></label>
                                <select id="shipping-desk-signature-<?php echo esc_attr( $order_id ); ?>" class="shipping-desk-signature-confirmation" title="<?php esc_attr_e( 'Signature confirmation is priced into the quote, so it is chosen before getting rates.', 'shipping-desk' ); ?>">
                                    <option value=""><?php esc_html_e( 'None', 'shipping-desk' ); ?></option>
                                    <option value="STANDARD"><?php esc_html_e( 'Signature required', 'shipping-desk' ); ?></option>
                                    <option value="ADULT"><?php esc_html_e( 'Adult signature', 'shipping-desk' ); ?></option>
                                </select>
                            </div>
                        <?php else : ?>
                            <div class="shipping-desk-services"><em><?php esc_html_e( 'Get rates first', 'shipping-desk' ); ?></em></div>
                        <?php endif; ?>
                        <div class="shipping-desk-service-amount-row">
                            <label class="shipping-desk-weight-label" for="shipping-desk-liability-<?php echo esc_attr( $order_id ); ?>"><?php esc_html_e( 'Insurance', 'shipping-desk' ); ?></label>
                            <input type="number" step="0.01" min="0" id="shipping-desk-liability-<?php echo esc_attr( $order_id ); ?>" class="shipping-desk-liability-amount" value="" placeholder="<?php esc_attr_e( 'None', 'shipping-desk' ); ?>" title="<?php echo esc_attr( $insurance_title ); ?>" />
                    <?php // States the premium, or that there is none, before any quote is fetched. ?>
                    <span class="shipping-desk-liability-estimate" aria-live="polite"></span>
                        </div>
                    </td>
                    <td class="shipping-desk-rates">
                        <div class="shipping-desk-rates-container"><em><?php esc_html_e( 'Rates not loaded. Select a box and choose Get Rates.', 'shipping-desk' ); ?></em></div>
                    </td>
                    <td>
                        <?php if ( $has_label ) : ?>
                            <?php // Refund and Reset render on the Completed tab, which this order is not on.
                            // They are offered here too, so a labelled order is never stranded (RW-7). ?>
                            <?php if ( $refunded ) : ?>
                                <p class="description"><?php esc_html_e( 'This label has been refunded. Reset Shipping to buy a replacement.', 'shipping-desk' ); ?></p>
                            <?php else : ?>
                                <p class="description"><?php esc_html_e( 'A label is already purchased for this order. Refund it, or reset the shipping state before buying another.', 'shipping-desk' ); ?></p>
                            <?php endif; ?>
                            <?php if ( '' !== $label_id && ! $refunded ) : ?>
                                <button type="button" class="shipping-desk-print-label"><?php esc_html_e( 'Print', 'shipping-desk' ); ?></button>
                                <button type="button" class="shipping-desk-refund-label"><?php esc_html_e( 'Refund', 'shipping-desk' ); ?></button>
                            <?php endif; ?>
                            <button type="button" class="shipping-desk-reset-shipping"><?php esc_html_e( 'Reset Shipping', 'shipping-desk' ); ?></button>
                        <?php else : ?>
                            <button type="button" class="shipping-desk-get-rates"><?php esc_html_e( 'Get Rates', 'shipping-desk' ); ?></button>
                            <button type="button" class="shipping-desk-buy-label" disabled><?php esc_html_e( 'Buy Label', 'shipping-desk' ); ?></button>
                        <?php endif; ?>
                    </td>
                </tr>
        <?php
    }

    protected function render_completed_table( $orders ) {
        $m = Shipping_Desk_Helpers::meta_keys();
        ?>
        <table class="widefat fixed striped shipping-desk-table">
            <thead><tr>
                <th class="shipping-desk-select-column"><input type="checkbox" class="shipping-desk-select-all" aria-label="<?php esc_attr_e( 'Select all orders on this page', 'shipping-desk' ); ?>" /></th>
                <th><?php esc_html_e( 'Order', 'shipping-desk' ); ?></th>
                <th><?php esc_html_e( 'Customer', 'shipping-desk' ); ?></th>
                <th><?php esc_html_e( 'Carrier', 'shipping-desk' ); ?></th>
                <th><?php esc_html_e( 'Tracking', 'shipping-desk' ); ?></th>
                <th><?php esc_html_e( 'Box', 'shipping-desk' ); ?></th>
                <th><?php esc_html_e( 'Weight Used (oz)', 'shipping-desk' ); ?></th>
                <th><?php esc_html_e( 'Label ID', 'shipping-desk' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'shipping-desk' ); ?></th>
            </tr></thead>
            <tbody>
            <?php if ( empty( $orders ) ) : ?>
                <tr><td colspan="9"><?php esc_html_e( 'No completed orders found.', 'shipping-desk' ); ?></td></tr>
            <?php else : foreach ( $orders as $order ) :
                $order_id       = $order->get_id();
                $customer       = method_exists( $order, 'get_formatted_billing_full_name' ) ? $order->get_formatted_billing_full_name() : trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
                $carrier        = $order->get_meta( $m['carrier'] );
                $tracking       = $order->get_meta( $m['tracking'] );
                $label_id       = $order->get_meta( $m['label_id'] );
                $refunded       = (bool) $order->get_meta( $m['refunded'] );
                $final_w        = $order->get_meta( $m['final_weight'] );
                $box_l          = $order->get_meta( $m['box_l'] );
                $box_w          = $order->get_meta( $m['box_w'] );
                $box_h          = $order->get_meta( $m['box_h'] );
                $has_label      = ! empty( $label_id );
                $box_compact    = ( $box_l && $box_w && $box_h ) ? sprintf( '%s x %s x %s in', $box_l, $box_w, $box_h ) : ' - ';
                $order_nonce    = wp_create_nonce( 'shipping_desk_order_' . $order_id );
                $items_weight   = Shipping_Desk_Helpers::get_order_items_weight_oz( $order );
                $tracking_display = ' - ';
                $carrier_display  = $carrier ?: ' - ';
                if ( $tracking ) {
                    $url             = Shipping_Desk_Helpers::tracking_url( $carrier, $tracking );
                    $carrier_display  = esc_html( $carrier ?: __( 'Carrier', 'shipping-desk' ) );
                    $tracking_display = sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( $url ), esc_html( $tracking ) );
                }
                $has_shipping_meta = $has_label
                    || ! empty( $order->get_meta( $m['tracking'] ) )
                    || ! empty( $order->get_meta( $m['remote_id'] ) );
            ?>
                <tr data-order-id="<?php echo esc_attr( $order_id ); ?>" data-nonce="<?php echo esc_attr( $order_nonce ); ?>"
                    data-items-weight="<?php echo esc_attr( number_format( $items_weight, 2, '.', '' ) ); ?>"
                    data-has-label="<?php echo $has_label ? '1' : '0'; ?>" data-label-refunded="<?php echo $refunded ? '1' : '0'; ?>">
                    <td class="shipping-desk-select-column"><input type="checkbox" class="shipping-desk-order-select" name="shipping_desk_order_ids[]" value="<?php echo esc_attr( $order_id ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Select order #%s', 'shipping-desk' ), $order->get_order_number() ) ); ?>" /></td>
                    <td><a href="<?php echo esc_url( get_edit_post_link( $order_id ) ); ?>" target="_blank" rel="noopener">#<?php echo esc_html( $order->get_order_number() ); ?></a></td>
                    <td><?php echo esc_html( $customer ); ?></td>
                    <td><?php echo $carrier_display; ?></td>
                    <td><?php echo $tracking_display; ?></td>
                    <td><?php echo esc_html( $box_compact ); ?></td>
                    <td><?php echo esc_html( $final_w ); ?></td>
                    <td><?php echo esc_html( $label_id ?: ' - ' ); ?></td>
                    <td>
                        <?php if ( $refunded ) : ?>
                            <?php // Said here as well as by the script that runs at the moment of refund: after a reload only this is left, and a row with nothing but Reset Shipping does not say why. ?>
                            <span class="shipping-desk-label-refunded"><?php esc_html_e( 'Label refunded. To buy a replacement, click Reset Shipping, then Reship.', 'shipping-desk' ); ?></span>
                        <?php endif; ?>
                        <?php if ( $has_label && ! $refunded ) : ?>
                            <button type="button" class="shipping-desk-print-label"><?php esc_html_e( 'Print', 'shipping-desk' ); ?></button>
                            <button type="button" class="shipping-desk-refund-label"><?php esc_html_e( 'Refund', 'shipping-desk' ); ?></button>
                        <?php endif; ?>
                        <button type="button" class="shipping-desk-reset-shipping"><?php esc_html_e( 'Reset Shipping', 'shipping-desk' ); ?></button>
                        <?php if ( ! $has_shipping_meta ) : ?>
                            <button type="button" class="shipping-desk-reship"><?php esc_html_e( 'Reship', 'shipping-desk' ); ?></button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Return the purchasable row markup for an order.
     *
     * Used by the Completed tab so a reset order can be shipped again in place,
     * without changing its status.
     */
    public function shipping_desk_render_purchase_row() {
        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        $order    = $this->plugin->authorized_order( $order_id, 'shipping_desk_order_' . $order_id );

        $boxes = get_option( 'shipping_desk_boxes', [] );
        if ( ! is_array( $boxes ) ) {
            $boxes = [];
        }
        usort( $boxes, function ( $a, $b ) {
            $name_a = trim( (string) ( $a['name'] ?? '' ) );
            $name_b = trim( (string) ( $b['name'] ?? '' ) );
            if ( $name_a === '' && $name_b !== '' ) {
                return 1;
            }
            if ( $name_a !== '' && $name_b === '' ) {
                return -1;
            }
            $name_compare = strnatcasecmp( $name_a, $name_b );
            if ( $name_compare !== 0 ) {
                return $name_compare;
            }
            return (float) ( $a['width'] ?? 0 ) <=> (float) ( $b['width'] ?? 0 );
        } );

        ob_start();
        $this->render_order_row( $order, $boxes );
        $html = trim( (string) ob_get_clean() );

        // render_order_row() emits a complete <tr>. The detail row is placed in
        // its own <td colspan>, so strip the outer row wrapper and keep only the
        // inner cells; the data-* attributes are re-attached to a wrapper.
        $attrs = '';
        if ( preg_match( '/^<tr([^>]*)>/', $html, $matches ) ) {
            $attrs = trim( $matches[1] );
            $html  = preg_replace( '/^<tr[^>]*>/', '', $html, 1 );
            $html  = preg_replace( '/<\/tr>\s*$/', '', $html, 1 );
        }

        wp_send_json_success( [
            'html'   => trim( $html ),
            'attrs'  => $attrs,
            'is_new' => true,
        ] );
    }
}
