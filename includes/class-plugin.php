<?php
/**
 * Main plugin class.
 */
class Shipping_Desk_Plugin {

    /**
     * Orders per bulk-buy request.
     *
     * A purchase is paced (~350 ms live, 1.4 s on a Shippo test key) plus a round trip,
     * so ten orders is roughly 14 seconds of work — inside PHP's default execution
     * window as well as the 180 s this plugin asks for. One request for the whole
     * selection would die part-way on a large run with the batch state unknown.
     */
    const BULK_CHUNK_SIZE = 10;

    /** @var string */
    protected $file;

    /** @var string */
    protected $url;

    /** @var string */
    protected $path;

    /** @var Shipping_Desk_PrintNode_Client|null */
    protected $_printnode_client = null;

    /** @var Shipping_Desk_Settings|null */
    protected $_settings = null;

    /** @var Shipping_Desk_Lookup_Page|null */
    protected $_lookup_page = null;

    /** @var Shipping_Desk_Orders_Screen|null */
    protected $_orders_screen = null;

    /** @var Shipping_Desk_Label_Actions|null */
    protected $_label_actions = null;

    /** @var Shipping_Desk_Labels|null */
    protected $_labels = null;

    /** @var Shipping_Desk_Quote_Store|null */
    protected $_quote_store = null;

    /** @var Shipping_Desk_Settings_Transfer|null */
    protected $_settings_transfer = null;

    public function __construct( $file ) {
        $this->file = $file;
        $this->path = plugin_dir_path( $file );
        $this->url  = plugin_dir_url( $file );
    }

    public function init() {
        add_action( 'init', [ $this, 'load_textdomain' ] );
        add_action( 'admin_menu', [ $this, 'register_admin_pages' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_footer', [ $this, 'render_whats_new_modal' ] );
        // One body class for every screen of the plugin, so styling does not have to
        // know which page wrapper a given page happens to use.
        add_filter( 'admin_body_class', [ $this, 'add_screen_body_class' ] );
        $this->settings()->init();
        $this->settings_transfer()->init();
        $this->labels()->init();
        $this->label_actions()->init();
        $this->orders_screen()->init();
        add_filter( 'plugin_action_links_' . plugin_basename( $this->file ), [ $this, 'filter_action_links' ] );

        // AJAX
        foreach ( [ 'shipping_desk_get_rates', 'shipping_desk_printnode_refresh_printers', 'shipping_desk_printnode_status', 'shipping_desk_switch_provider', 'shipping_desk_dismiss_whats_new' ] as $action ) {
            add_action( 'wp_ajax_' . $action, [ $this, $action ] );
        }
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'shipping-desk', false, dirname( plugin_basename( $this->file ) ) . '/languages' );
    }

    /**
     * "Settings" beside Deactivate on the Plugins screen, going straight to our page.
     *
     * WordPress adds that link by itself only for plugins that register a page through
     * the Settings API; this plugin lives under its own menu, so the link is added here.
     * First in the list, where WordPress puts it for everyone else.
     *
     * @param string[] $links Action links, as HTML.
     * @return string[]
     */
    public function filter_action_links( $links ) {
        if ( ! is_array( $links ) || ! current_user_can( 'manage_woocommerce' ) ) {
            return $links;
        }

        array_unshift(
            $links,
            '<a href="' . esc_url( admin_url( 'admin.php?page=shipping-desk-settings' ) ) . '">'
                . esc_html__( 'Settings', 'shipping-desk' ) . '</a>'
        );

        return $links;
    }

    /**
     * Marks every screen of the plugin with a body class: `shipping-desk-screen`.
     *
     * The page wrappers are not uniform - four screens use `shipping-desk-wrap` and the
     * PrintNode screen uses `shipping-desk-settings` - and the what's-new dialog is printed
     * in admin_footer, outside every wrapper. Styling that keys off a wrapper therefore
     * misses whichever surface it was not told about: that is how "Save Boxes", whose markup
     * comes from submit_button() and carries no plugin class of its own, kept WordPress
     * core's 3px corners while the buttons beside it were 6px.
     *
     * The body class is the one thing all of those surfaces share, and this is the same
     * page test enqueue_assets() uses, so the stylesheet and the hook cannot disagree.
     *
     * @param string $classes Space-separated body classes.
     * @return string
     */
    public function add_screen_body_class( $classes ) {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( 0 !== strpos( $page, 'shipping-desk' ) ) {
            return $classes;
        }

        return trim( $classes . ' shipping-desk-screen' );
    }

    /**
     * "Today $42.10 · 7 labels": what was bought since midnight, in the header.
     *
     * Display only, and deliberately cheap — a single option read, no queries, no
     * transient. Nothing renders on a day with no purchases, so the header stays quiet.
     * The figure is the charged total (postage plus insurance where the provider reports
     * it); refunds made later in the day are not deducted.
     *
     * @return void
     */
    public function render_daily_spend() {
        $spend = Shipping_Desk_Helpers::daily_spend();
        if ( empty( $spend ) ) {
            return;
        }

        $labels = (int) $spend['labels'];
        $money  = function_exists( 'wc_price' )
            ? wp_kses_post( wc_price( $spend['amount'], [ 'currency' => $spend['currency'] ] ) )
            : esc_html( number_format_i18n( $spend['amount'], 2 ) );

        // One label makes the average identical to the total, which is noise. From two
        // labels on it is the figure worth comparing between days.
        $average = Shipping_Desk_Helpers::daily_average( $spend );
        $average = ( null !== $average && $labels > 1 )
            ? ( function_exists( 'wc_price' )
                ? wp_kses_post( wc_price( $average, [ 'currency' => $spend['currency'] ] ) )
                : esc_html( number_format_i18n( $average, 2 ) ) )
            : '';

        // Two layouts, one markup: the class decides whether the supporting figures sit
        // beside the amount or stack against it. Filterable so they can be compared on a
        // live site with one line rather than by reinstalling.
        $layout = (string) apply_filters( 'shipping_desk_spend_layout', 'stacked' );
        $class  = 'inline' === $layout ? 'shipping-desk-spend' : 'shipping-desk-spend shipping-desk-spend--stacked';

        // Which of the two small figures sits on top. Only added when both exist: a lone
        // figure would otherwise carry a rule with nothing beneath it.
        if ( 'inline' !== $layout && '' !== $average
            && 'count-first' === (string) apply_filters( 'shipping_desk_spend_order', 'average-first' ) ) {
            $class .= ' shipping-desk-spend--count-first';
        }

        $title = __( 'Postage bought since midnight, site time. Refunds are not deducted.', 'shipping-desk' );
        ?>
        <div class="<?php echo esc_attr( $class ); ?>" title="<?php echo esc_attr( $title ); ?>">
            <span class="shipping-desk-spend-label"><?php esc_html_e( 'Today', 'shipping-desk' ); ?></span>
            <strong><?php echo $money; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_price output, escaped above. ?></strong>
            <span class="shipping-desk-spend-count">
                <?php
                printf(
                    /* translators: %s: number of labels bought today. */
                    esc_html( _n( '%s label', '%s labels', $labels, 'shipping-desk' ) ),
                    esc_html( number_format_i18n( $labels ) )
                );
                ?>
            </span>
            <?php if ( '' !== $average ) : ?>
                <span
                    class="shipping-desk-spend-average"
                    title="<?php esc_attr_e( 'Average postage per label today.', 'shipping-desk' ); ?>"
                >
                    <?php echo $average; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_price output, escaped above. ?>
                    <span class="shipping-desk-spend-average-label"><?php esc_html_e( 'avg', 'shipping-desk' ); ?></span>
                </span>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * One-time "what's new" popup after an update, on this plugin's screens only.
     *
     * Rendered in the footer so it sits above whatever the page already drew, and only
     * for the version that is actually running — an update that failed halfway must not
     * announce a release the site does not have. Dismissal is per user, per version.
     */
    public function render_whats_new_modal() {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( 0 !== strpos( $page, 'shipping-desk' ) || ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $pending = Shipping_Desk_Update_Checker::pending_whats_new();
        if ( empty( $pending ) ) {
            return;
        }
        ?>
        <div class="shipping-desk-modal" id="shipping-desk-whats-new" role="dialog" aria-modal="true" aria-labelledby="shipping-desk-whats-new-title" data-version="<?php echo esc_attr( $pending['version'] ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'shipping_desk_whats_new' ) ); ?>">
            <div class="shipping-desk-modal-panel">
                <h2 id="shipping-desk-whats-new-title">
                    <?php
                    /* translators: %s: plugin version, e.g. 1.1.0 */
                    echo esc_html( sprintf( __( "What's new in Shipping Desk %s", 'shipping-desk' ), $pending['version'] ) );
                    ?>
                </h2>
                <ul class="shipping-desk-whats-new-list">
                    <?php foreach ( $pending['items'] as $item ) : ?>
                        <li><?php echo esc_html( $item ); ?></li>
                    <?php endforeach; ?>
                </ul>
                <p class="shipping-desk-modal-actions">
                    <button type="button" class="button button-primary shipping-desk-whats-new-close"><?php esc_html_e( 'Got it', 'shipping-desk' ); ?></button>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: remember that this user has read the changelog.
     *
     * @return void
     */
    public function shipping_desk_dismiss_whats_new() {
        check_ajax_referer( 'shipping_desk_whats_new', '_wpnonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'You do not have permission to do that.', 'shipping-desk' ) ], 403 );
        }

        $version = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '';
        Shipping_Desk_Update_Checker::dismiss_whats_new( $version );

        wp_send_json_success();
    }

    public function register_admin_pages() {
        add_menu_page(
            __( 'Shipping Desk', 'shipping-desk' ),
            __( 'Shipping Desk', 'shipping-desk' ),
            'manage_woocommerce',
            'shipping-desk',
            [ $this->orders_screen(), 'render_orders_page' ],
            'dashicons-admin-generic',
            56
        );

        add_submenu_page(
            'shipping-desk',
            __( 'Shipment Lookup', 'shipping-desk' ),
            __( 'Shipment Lookup', 'shipping-desk' ),
            'manage_woocommerce',
            'shipping-desk-lookup',
            [ $this->lookup_page(), 'render_lookup_page' ]
        );

        add_submenu_page(
            'shipping-desk',
            __( 'Box Settings', 'shipping-desk' ),
            __( 'Box Settings', 'shipping-desk' ),
            'manage_woocommerce',
            'shipping-desk-box-settings',
            [ $this, 'render_box_settings_page' ]
        );

        add_submenu_page(
            'shipping-desk',
            __( 'Settings', 'shipping-desk' ),
            __( 'Settings', 'shipping-desk' ),
            'manage_woocommerce',
            'shipping-desk-settings',
            [ $this, 'render_shipping_desk_settings_page' ]
        );

        add_submenu_page(
            'shipping-desk',
            __( 'Print Settings', 'shipping-desk' ),
            __( 'Print Settings', 'shipping-desk' ),
            'manage_woocommerce',
            'shipping-desk-print',
            [ $this, 'render_print_settings_page' ]
        );
    }

    /**
     * Option keys owned by this plugin.
     *
     * @return string[]
     */
    protected function option_keys() {
        return [
            'shipping_desk_veeqo_api_key',
            'shipping_desk_provider',
            'shipping_desk_shippo_mode',
            'shipping_desk_shippo_test_api_key',
            'shipping_desk_shippo_live_api_key',
            'shipping_desk_easypost_mode',
            'shipping_desk_easypost_test_api_key',
            'shipping_desk_easypost_live_api_key',
            'shipping_desk_easypost_api_key',
            'shipping_desk_easypost_key_migrated',
            'shipping_desk_provider_defaulted',
            'shipping_desk_checkout_validation',
            'shipping_desk_verifier',
            'shipping_desk_from_address',
            'shipping_desk_boxes',
            'shipping_desk_print_mode',
            'shipping_desk_printnode_api_key',
            'shipping_desk_printnode_printer_id',
        ];
    }


    /**
     * From-address values the carriers need but the store does not supply.
     *
     * USPS refuses a label without a phone and email; UPS accepted a blank phone
     * in testing but expects one, so a blank is never safe. Resolved through the
     * same helper the payload uses, so this can never disagree with what is sent.
     *
     * @return string[] Human-readable field names, empty when everything resolves.
     */
    public function from_address_problems() {
        $from   = Shipping_Desk_Helpers::get_from_address();
        $fields = [
            'phone' => __( 'phone number', 'shipping-desk' ),
            'email' => __( 'email address', 'shipping-desk' ),
        ];

        $missing = [];
        foreach ( $fields as $key => $label ) {
            if ( '' === trim( (string) ( isset( $from[ $key ] ) ? $from[ $key ] : '' ) ) ) {
                $missing[] = $label;
            }
        }

        return $missing;
    }


    /* -- Print Settings -- */

    public function render_print_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'shipping-desk' ) );
        }
        $api_key         = get_option( 'shipping_desk_printnode_api_key', '' );
        $printer_id      = (int) get_option( 'shipping_desk_printnode_printer_id', 0 );
        $print_mode      = get_option( 'shipping_desk_print_mode', 'browser' );
        $print_reference = get_option( 'shipping_desk_print_reference', 'order_number' );
        $reference_text  = get_option( 'shipping_desk_print_reference_text', '' );

        ?>
        <div class="wrap shipping-desk-settings">
            <h1><?php esc_html_e( 'Print Settings', 'shipping-desk' ); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'shipping_desk_print_settings' ); ?>

                <h2><?php esc_html_e( 'Printing', 'shipping-desk' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Print Mode', 'shipping-desk' ); ?></th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text"><?php esc_html_e( 'Print Mode', 'shipping-desk' ); ?></legend>
                                <label><input type="radio" name="shipping_desk_print_mode" value="browser" <?php checked( $print_mode, 'browser' ); ?> /> <?php esc_html_e( 'Browser Print  -  open label in browser for the user to print', 'shipping-desk' ); ?></label><br />
                                <label><input type="radio" name="shipping_desk_print_mode" value="printnode" <?php checked( $print_mode, 'printnode' ); ?> /> <?php esc_html_e( 'Server Print (PrintNode)  -  send directly to a printer', 'shipping-desk' ); ?></label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Printed reference', 'shipping-desk' ); ?></th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text"><?php esc_html_e( 'Printed reference', 'shipping-desk' ); ?></legend>
                                <label><input type="radio" name="shipping_desk_print_reference" value="order_number" <?php checked( $print_reference, 'order_number' ); ?> /> <?php esc_html_e( 'Order number  -  the WooCommerce order number (default)', 'shipping-desk' ); ?></label><br />
                                <label><input type="radio" name="shipping_desk_print_reference" value="customer_name" <?php checked( $print_reference, 'customer_name' ); ?> /> <?php esc_html_e( 'Customer name  -  the ship-to name', 'shipping-desk' ); ?></label><br />
                                <label><input type="radio" name="shipping_desk_print_reference" value="custom" <?php checked( $print_reference, 'custom' ); ?> /> <?php esc_html_e( 'Custom text', 'shipping-desk' ); ?></label>
                                <p id="shipping-desk-print-reference-text-row"<?php echo 'custom' === $print_reference ? '' : ' style="display:none;"'; ?>>
                                    <label for="shipping-desk-print-reference-text" class="screen-reader-text"><?php esc_html_e( 'Custom text to print on the label', 'shipping-desk' ); ?></label>
                                    <input type="text" id="shipping-desk-print-reference-text" name="shipping_desk_print_reference_text" class="regular-text" maxlength="30" value="<?php echo esc_attr( $reference_text ); ?>" />
                                </p>
                            </fieldset>
                            <p class="description">
                                <?php esc_html_e( 'The text printed in the carrier\'s reference field on the label, for browser and server printing alike. Up to 30 characters - the tightest carrier limit.', 'shipping-desk' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <hr />

                <h2><?php esc_html_e( 'PrintNode (server printing)', 'shipping-desk' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="shipping-desk-printnode-api-key"><?php esc_html_e( 'PrintNode API Key', 'shipping-desk' ); ?></label></th>
                        <td><input type="text" id="shipping-desk-printnode-api-key" name="shipping_desk_printnode_api_key" class="regular-text" value="<?php echo esc_attr( $api_key ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Printers', 'shipping-desk' ); ?></th>
                        <td>
                            <div id="shipping-desk-printnode-printer-list">
                                <?php $this->render_printnode_printer_list( $printer_id ); ?>
                            </div>
                            <button type="button" id="shipping-desk-printnode-refresh-printers" class="shipping-desk-get-rates"><?php esc_html_e( 'Refresh Printers', 'shipping-desk' ); ?></button>
                            <p class="description"><?php esc_html_e( 'Select the printer that will receive labels from this plugin.', 'shipping-desk' ); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <script>
        (function ($) {
            'use strict';

            // The custom text field is hidden, not disabled, when another choice
            // is selected: a disabled field posts nothing, which would erase the
            // saved text on every save.
            function shippingDeskToggleReferenceText() {
                var custom = $('input[name="shipping_desk_print_reference"]:checked').val() === 'custom';
                $('#shipping-desk-print-reference-text-row').toggle(custom);
            }
            $(document).on('change', 'input[name="shipping_desk_print_reference"]', shippingDeskToggleReferenceText);
            shippingDeskToggleReferenceText();

            $(document).on('click', '#shipping-desk-printnode-refresh-printers', function () {
                var $btn = $(this);
                $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Loading...', 'shipping-desk' ) ); ?>');
                $.post(ShippingDesk.ajaxurl, { action: 'shipping_desk_printnode_refresh_printers', _wpnonce: '<?php echo esc_js( wp_create_nonce( 'shipping_desk_printnode' ) ); ?>' }, function (resp) {
                    $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Refresh Printers', 'shipping-desk' ) ); ?>');
                    if (resp && resp.success) { $('#shipping-desk-printnode-printer-list').html(resp.data.html || ''); }
                    else { alert((resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js( __( 'Error refreshing printers.', 'shipping-desk' ) ); ?>'); }
                }).fail(function () {
                    $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Refresh Printers', 'shipping-desk' ) ); ?>');
                    alert('<?php echo esc_js( __( 'Error refreshing printers.', 'shipping-desk' ) ); ?>');
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    protected function render_printnode_printer_list( $selected_printer_id = 0 ) {
        $api_key = get_option( 'shipping_desk_printnode_api_key', '' );
        if ( empty( $api_key ) ) {
            echo '<p class="description">' . esc_html__( 'Enter an API key and save, then refresh printers.', 'shipping-desk' ) . '</p>';
            return;
        }
        $client = $this->printnode();
        $printers = $client->get_printers();
        if ( is_wp_error( $printers ) ) {
            echo '<p class="description">' . esc_html( $printers->get_error_message() ) . '</p>';
            return;
        }
        if ( empty( $printers ) || ! is_array( $printers ) ) {
            echo '<p class="description">' . esc_html__( 'No printers found.', 'shipping-desk' ) . '</p>';
            return;
        }

        $computers = [];
        foreach ( $printers as $printer ) {
            if ( ! is_array( $printer ) || empty( $printer['id'] ) ) {
                continue;
            }
            $computer_id   = $printer['computer']['id'] ?? 0;
            $computer_name = $printer['computer']['name'] ?? __( 'Unknown Computer', 'shipping-desk' );
            $computers[ $computer_id ]['name']     = $computer_name;
            $computers[ $computer_id ]['printers'][] = $printer;
        }

        if ( empty( $computers ) ) {
            echo '<p class="description">' . esc_html__( 'No printers found.', 'shipping-desk' ) . '</p>';
            return;
        }

        ?>
        <div class="shipping-desk-printnode-printer-list">
            <?php foreach ( $computers as $computer_id => $group ) : ?>
                <div class="shipping-desk-printnode-computer">
                    <strong><?php echo esc_html( $group['name'] ); ?></strong>
                    <div class="shipping-desk-printnode-printer-options" style="margin:4px 0 12px 12px;">
                        <?php foreach ( $group['printers'] as $printer ) :
                            $printer_id   = (int) $printer['id'];
                            $printer_name = $printer['name'] ?? __( 'Unnamed Printer', 'shipping-desk' );
                            $online       = ( $printer['state'] ?? '' ) === 'online';
                            $label        = $online ? $printer_name : sprintf( __( '%s (offline)', 'shipping-desk' ), $printer_name );
                            ?>
                            <label style="display:block;">
                                <input type="radio" name="shipping_desk_printnode_printer_id" value="<?php echo esc_attr( $printer_id ); ?>" <?php checked( $selected_printer_id, $printer_id ); ?> />
                                <?php echo esc_html( $label ); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /* -- /Print Settings -- */

    public function enqueue_assets( $hook ) {
        if ( strpos( (string) $hook, 'shipping-desk' ) === false ) {
            return;
        }
        wp_enqueue_style( 'shipping-desk-admin', $this->url . 'admin/css/admin.css', [], '1.7.0' );
        wp_enqueue_script( 'shipping-desk-admin', $this->url . 'admin/js/admin.js', [ 'jquery' ], '1.7.0', true );
        wp_localize_script( 'shipping-desk-admin', 'ShippingDesk', [
            'ajaxurl'     => admin_url( 'admin-ajax.php' ),
            'bulkNonce'   => wp_create_nonce( 'shipping_desk_bulk_buy' ),
            'bulkAction'  => 'shipping_desk_bulk_buy',
            // The bulk UIs issue one AJAX request per order, so the page has to pace
            // the provider too — with the same rule the server uses for purchases.
            'bulkDelayMs' => $this->bulk_delay_ms(),
            'bulkChunkSize' => self::BULK_CHUNK_SIZE,
        ] );
    }

    /**
     * Milliseconds to leave between rows in a bulk run.
     *
     * Shippo's test keys allow a tenth of the live traffic, and bulk get-rates is one
     * `POST /shipments` per order with no server-side pacing of its own: without this
     * the page would push ~60 writes a minute into a 50-per-minute budget.
     *
     * @return int
     */
    protected function bulk_delay_ms() {
        $provider = $this->provider();
        $key      = '';
        if ( 'shippo' === $provider ) {
            $key = $this->shippo_api_key();
        } elseif ( 'easypost' === $provider ) {
            $key = $this->easypost_api_key();
        }

        return (int) round( Shipping_Desk_Helpers::bulk_spacing_us( $provider, $key ) / 1000 );
    }

    /**
     * Active label provider. Defaults to Shippo; an unknown value falls back to
     * it rather than to Veeqo, so a corrupt option cannot silently repoint buying.
     *
     * @return string 'veeqo', 'shippo' or 'easypost'.
     */
    public function provider() {
        return $this->normalize_provider( get_option( 'shipping_desk_provider', 'shippo' ), 'shippo' );
    }

    /**
     * Three-way allowlist. Old order markers are judged with a 'veeqo' default,
     * because an order shipped before markers existed was shipped by Veeqo.
     *
     * @param mixed  $provider Raw value.
     * @param string $fallback Value to use when it is not a known provider.
     * @return string
     */
    public function normalize_provider( $provider, $fallback = 'veeqo' ) {
        $provider = (string) $provider;
        return in_array( $provider, [ 'veeqo', 'shippo', 'easypost' ], true ) ? $provider : $fallback;
    }

    /**
     * Shippo key mode: 'test' or 'live'.
     *
     * @return string
     */
    public function shippo_mode() {
        return 'live' === (string) get_option( 'shipping_desk_shippo_mode', 'test' ) ? 'live' : 'test';
    }

    /**
     * Shippo API key for a mode.
     *
     * @param string|null $mode Mode to read; null uses the active mode.
     * @return string Empty when the key is not set.
     */
    public function shippo_api_key( $mode = null ) {
        $mode = null === $mode ? $this->shippo_mode() : ( 'live' === $mode ? 'live' : 'test' );
        $key  = 'live' === $mode
            ? get_option( 'shipping_desk_shippo_live_api_key', '' )
            : get_option( 'shipping_desk_shippo_test_api_key', '' );

        return trim( (string) $key );
    }

    /**
     * EasyPost key mode: 'test' or 'live'.
     *
     * @return string
     */
    public function easypost_mode() {
        return 'live' === (string) get_option( 'shipping_desk_easypost_mode', 'test' ) ? 'live' : 'test';
    }

    /**
     * EasyPost API key for a mode.
     *
     * The same key buys labels and verifies addresses. No cross-slot fallback: a
     * mode with no key is a configuration error the operator should see, not
     * something to paper over by quietly spending the other key's money.
     *
     * @param string|null $mode Mode to read; null uses the active mode.
     * @return string Empty when the key is not set.
     */
    public function easypost_api_key( $mode = null ) {
        $mode = null === $mode ? $this->easypost_mode() : ( 'live' === $mode ? 'live' : 'test' );
        $key  = 'live' === $mode
            ? get_option( 'shipping_desk_easypost_live_api_key', '' )
            : get_option( 'shipping_desk_easypost_test_api_key', '' );

        return trim( (string) $key );
    }

    /**
     * Key mode for a provider, or '' for Veeqo, which has none.
     *
     * @param string $provider Provider slug.
     * @return string 'test', 'live' or ''.
     */
    public function provider_mode( $provider ) {
        if ( 'shippo' === $provider ) {
            return $this->shippo_mode();
        }
        if ( 'easypost' === $provider ) {
            return $this->easypost_mode();
        }

        return '';
    }

    /**
     * Client for a provider.
     *
     * @param string|null $provider Provider slug; null uses the active provider.
     * @param string|null $mode     Key mode; null uses the provider's active mode.
     * @return Shipping_Desk_Provider_Interface
     */
    public function api( $provider = null, $mode = null ) {
        $provider = null === $provider ? $this->provider() : $this->normalize_provider( $provider );

        if ( 'shippo' === $provider && class_exists( 'Shipping_Desk_Shippo_Client' ) ) {
            // The key is resolved here and passed in, so the client has one
            // source of truth and can never pick the wrong mode by itself.
            return new Shipping_Desk_Shippo_Client( $this->shippo_api_key( $mode ) );
        }

        if ( 'easypost' === $provider && class_exists( 'Shipping_Desk_EasyPost_Client' ) ) {
            return new Shipping_Desk_EasyPost_Client( $this->easypost_api_key( $mode ) );
        }

        return new Shipping_Desk_Veeqo_Client();
    }

    public function require_cap() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'shipping-desk' ) ], 403 );
            exit;
        }
    }

    /**
     * The guard an order-scoped AJAX handler starts with: capability, nonce, order.
     *
     * One answer instead of the same three checks in every handler, so a handler cannot
     * forget the nonce or check it against the wrong action. Anything but success ends
     * the request here: the caller only ever sees an order.
     *
     * @param int    $order_id     Order id from the request.
     * @param string $nonce_action Action the request's nonce must be for.
     * @return WC_Order The order the request is about.
     */
    public function authorized_order( $order_id, $nonce_action ) {
        $this->require_cap();

        $nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid nonce.', 'shipping-desk' ) ], 403 );
        }

        $order = wc_get_order( absint( $order_id ) );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => __( 'Order not found.', 'shipping-desk' ) ], 404 );
        }

        return $order;
    }

    /**
     * Switch the active label provider, or toggle its Test/Production mode.
     *
     * Two gestures, one endpoint:
     *
     *  - a provider button changes which provider buys the next label;
     *  - the test/live chip changes that provider's key mode, because the chip is where
     *    the mode is displayed. Toggling *to* production is confirmed in the browser and
     *    the server refuses a mode whose key slot is empty, so a single stray click can
     *    never start spending money.
     *
     * Old labels are unaffected either way: print and refund follow the per-order
     * provider marker, not this setting.
     */
    public function shipping_desk_switch_provider() {
        $this->require_cap();
        if ( ! check_ajax_referer( 'shipping_desk_provider_switch', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid nonce.', 'shipping-desk' ) ], 400 );
        }

        $target = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
        if ( ! in_array( $target, [ 'veeqo', 'shippo', 'easypost' ], true ) ) {
            wp_send_json_error( [ 'message' => __( 'Unknown provider.', 'shipping-desk' ) ], 400 );
        }

        // The chip toggles the mode of the provider that is already active.
        $toggle_mode = ! empty( $_POST['toggle_mode'] );

        if ( $toggle_mode ) {
            if ( $target !== $this->provider() ) {
                wp_send_json_error( [ 'message' => __( 'Switch provider first, then change its mode.', 'shipping-desk' ) ], 409 );
            }
            if ( 'veeqo' === $target ) {
                wp_send_json_error( [ 'message' => __( 'Veeqo has no test or production mode.', 'shipping-desk' ) ], 400 );
            }

            $mode    = 'live' === $this->provider_mode( $target ) ? 'test' : 'live';
            $problem = $this->mode_key_problem( $target, $mode );
            if ( '' !== $problem ) {
                wp_send_json_error( [ 'message' => $problem ], 409 );
            }

            update_option( 'shipping_desk_' . $target . '_mode', $mode );
            $this->quote_store()->clear_cached_quotes();

            wp_send_json_success( [
                'provider' => $target,
                'mode'     => $mode,
                'changed'  => true,
            ] );
        }

        if ( $target === $this->provider() ) {
            wp_send_json_success( [ 'provider' => $target, 'changed' => false ] );
        }

        $problem = $this->provider_key_problem( $target );
        if ( '' !== $problem ) {
            wp_send_json_error( [ 'message' => $problem ], 409 );
        }

        update_option( 'shipping_desk_provider', $target );
        $this->quote_store()->clear_cached_quotes();

        wp_send_json_success( [
            'provider' => $target,
            'mode'     => $this->provider_mode( $target ),
            'changed'  => true,
        ] );
    }

    /**
     * Why a specific mode cannot be used for a provider, or '' when it can.
     *
     * Separate from provider_key_problem() because a toggle has to judge the mode it is
     * moving *to*, not the one currently selected.
     *
     * @param string $provider Provider slug.
     * @param string $mode     'test' or 'live'.
     * @return string Translated message, or ''.
     */
    protected function mode_key_problem( $provider, $mode ) {
        if ( 'shippo' === $provider ) {
            $name = 'Shippo';
            $key  = $this->shippo_api_key( $mode );
        } elseif ( 'easypost' === $provider ) {
            $name = 'EasyPost';
            $key  = $this->easypost_api_key( $mode );
        } else {
            return __( 'Veeqo has no test or production mode.', 'shipping-desk' );
        }

        if ( '' !== trim( (string) $key ) ) {
            return '';
        }

        return 'live' === $mode
            ? sprintf(
                /* translators: %s: provider name, e.g. EasyPost */
                __( '%s has no Production API key. Add one under Settings → Label Provider first.', 'shipping-desk' ),
                $name
            )
            : sprintf(
                /* translators: %s: provider name, e.g. EasyPost */
                __( '%s has no Test API key. Add one under Settings → Label Provider first.', 'shipping-desk' ),
                $name
            );
    }

    /**
     * Why the given provider could not be used right now, or '' when it can.
     *
     * @param string $provider Provider slug.
     * @return string Translated message, or ''.
     */
    protected function provider_key_problem( $provider ) {
        if ( 'shippo' === $provider ) {
            if ( '' !== trim( (string) $this->shippo_api_key() ) ) {
                return '';
            }
            return 'live' === $this->shippo_mode()
                ? __( 'Shippo is set to Production but has no Production API key. Add one under Settings → Label Provider first.', 'shipping-desk' )
                : __( 'Shippo has no Test API key. Add one under Settings → Label Provider first.', 'shipping-desk' );
        }

        if ( 'easypost' === $provider ) {
            if ( '' !== trim( (string) $this->easypost_api_key() ) ) {
                return '';
            }
            return 'live' === $this->easypost_mode()
                ? __( 'EasyPost is set to Production but has no Production API key. Add one under Settings → Label Provider first.', 'shipping-desk' )
                : __( 'EasyPost has no Test API key. Add one under Settings → Label Provider first.', 'shipping-desk' );
        }

        if ( '' === trim( (string) get_option( 'shipping_desk_veeqo_api_key', '' ) ) ) {
            return __( 'Veeqo has no API key. Add one under Settings → Label Provider first.', 'shipping-desk' );
        }

        return '';
    }

    /**
     * Compact provider indicator and switch for the orders page header.
     *
     * Small on purpose — it answers "who am I buying from" at a glance and allows
     * the switch without a trip to Settings. The test/live chip sits next to Shippo
     * because that is the part that decides whether money is spent.
     */
    public function render_provider_switch() {
        $provider = $this->provider();
        $mode     = $this->provider_mode( $provider );
        ?>
        <div class="shipping-desk-provider-switch"
             role="group"
             aria-label="<?php esc_attr_e( 'Label provider', 'shipping-desk' ); ?>"
             data-nonce="<?php echo esc_attr( wp_create_nonce( 'shipping_desk_provider_switch' ) ); ?>">
            <?php foreach ( [ 'veeqo' => 'Veeqo', 'shippo' => 'Shippo', 'easypost' => 'EasyPost' ] as $slug => $label ) : ?>
                <button type="button"
                        class="shipping-desk-provider-btn<?php echo $provider === $slug ? ' is-active' : ''; ?>"
                        data-provider="<?php echo esc_attr( $slug ); ?>"
                        aria-pressed="<?php echo $provider === $slug ? 'true' : 'false'; ?>"><?php echo esc_html( $label ); ?></button>
            <?php endforeach; ?>
            <?php if ( '' !== $mode ) : ?>
                <?php
                $is_live = 'live' === $mode;
                // The chip is the control, not just an indicator: it shows the mode and
                // clicking it flips the mode. The browser confirms before production,
                // because that is the direction where labels start costing money.
                $chip_title = $is_live
                    ? __( 'Production: labels are bought with real money. Click to switch to Test.', 'shipping-desk' )
                    : __( 'Test: labels cost nothing. Click to switch to Production.', 'shipping-desk' );
                ?>
                <button type="button"
                        class="shipping-desk-mode-chip <?php echo $is_live ? 'is-live' : 'is-test'; ?>"
                        title="<?php echo esc_attr( $chip_title ); ?>"
                        aria-label="<?php
                        echo esc_attr(
                            sprintf(
                                /* translators: %s: the mode a click would switch to, e.g. Production */
                                __( 'Switch to %s', 'shipping-desk' ),
                                $is_live ? __( 'Test', 'shipping-desk' ) : __( 'Production', 'shipping-desk' )
                            )
                        );
                        ?>"><?php echo esc_html( $is_live ? __( 'live', 'shipping-desk' ) : __( 'test', 'shipping-desk' ) ); ?></button>
            <?php endif; ?>
        </div>
        <?php
    }



    public function render_box_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'shipping-desk' ) );
        }
        $boxes = get_option( 'shipping_desk_boxes', [] );
        if ( ! is_array( $boxes ) ) {
            $boxes = [];
        }
        ?>
        <div class="wrap shipping-desk-wrap">
            <h1><?php esc_html_e( 'Box Settings', 'shipping-desk' ); ?></h1>
            <form method="post" action="options.php" id="shipping-desk-boxes-form">
                <?php settings_fields( 'shipping_desk_box_settings' ); ?>
                <table class="widefat fixed striped" id="shipping-desk-boxes-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Name', 'shipping-desk' ); ?></th>
                            <th><?php esc_html_e( 'Length (in)', 'shipping-desk' ); ?></th>
                            <th><?php esc_html_e( 'Width (in)', 'shipping-desk' ); ?></th>
                            <th><?php esc_html_e( 'Height (in)', 'shipping-desk' ); ?></th>
                            <th><?php esc_html_e( 'Weight (oz)', 'shipping-desk' ); ?></th>
                            <th><?php esc_html_e( 'Remove', 'shipping-desk' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $i = 0;
                        foreach ( $boxes as $box ) :
                            $name   = $box['name'] ?? '';
                            $length = $box['length'] ?? '';
                            $width  = $box['width'] ?? '';
                            $height = $box['height'] ?? '';
                            $weight = $box['weight'] ?? '';
                        ?>
                        <tr class="shipping-desk-box-row">
                            <td><input type="text" name="shipping_desk_boxes[<?php echo esc_attr( $i ); ?>][name]" value="<?php echo esc_attr( $name ); ?>" class="regular-text" /></td>
                            <td><input type="number" step="0.01" name="shipping_desk_boxes[<?php echo esc_attr( $i ); ?>][length]" value="<?php echo esc_attr( $length ); ?>" /></td>
                            <td><input type="number" step="0.01" name="shipping_desk_boxes[<?php echo esc_attr( $i ); ?>][width]" value="<?php echo esc_attr( $width ); ?>" /></td>
                            <td><input type="number" step="0.01" name="shipping_desk_boxes[<?php echo esc_attr( $i ); ?>][height]" value="<?php echo esc_attr( $height ); ?>" /></td>
                            <td><input type="number" step="0.01" name="shipping_desk_boxes[<?php echo esc_attr( $i ); ?>][weight]" value="<?php echo esc_attr( $weight ); ?>" /></td>
                            <td><button type="button" class="button shipping-desk-remove-box"><?php esc_html_e( 'Remove', 'shipping-desk' ); ?></button></td>
                        </tr>
                        <?php
                            $i++;
                        endforeach;
                        ?>
                    </tbody>
                </table>
                <p>
                    <button type="button" class="button" id="shipping-desk-add-box"><?php esc_html_e( 'Add Box', 'shipping-desk' ); ?></button>
                    <?php submit_button( __( 'Save Boxes', 'shipping-desk' ), 'primary', 'submit', false ); ?>
                </p>
            </form>
            <script>
            (function(){
                var idx = <?php echo (int) $i; ?>;
                var tbody = document.querySelector('#shipping-desk-boxes-table tbody');
                document.getElementById('shipping-desk-add-box').addEventListener('click', function(){
                    var tr = document.createElement('tr');
                    tr.className = 'shipping-desk-box-row';
                    tr.innerHTML =
                        '<td><input type="text" name="shipping_desk_boxes['+idx+'][name]" class="regular-text" /></td>' +
                        '<td><input type="number" step="0.01" name="shipping_desk_boxes['+idx+'][length]" /></td>' +
                        '<td><input type="number" step="0.01" name="shipping_desk_boxes['+idx+'][width]" /></td>' +
                        '<td><input type="number" step="0.01" name="shipping_desk_boxes['+idx+'][height]" /></td>' +
                        '<td><input type="number" step="0.01" name="shipping_desk_boxes['+idx+'][weight]" /></td>' +
                        '<td><button type="button" class="button shipping-desk-remove-box">Remove</button></td>';
                    tbody.appendChild(tr);
                    idx++;
                });
                document.addEventListener('click', function(e){
                    if (e.target && e.target.classList.contains('shipping-desk-remove-box')) {
                        e.target.closest('tr').remove();
                    }
                });
            })();
            </script>
        </div>
        <?php
    }

    public function render_shipping_desk_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'shipping-desk' ) );
        }
        ?>
        <div class="wrap shipping-desk-wrap">
            <h1><?php esc_html_e( 'Settings', 'shipping-desk' ); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'shipping_desk_api_settings' );
                do_settings_sections( 'shipping_desk_api_settings' );
                submit_button( __( 'Save API settings', 'shipping-desk' ) );
                ?>
            </form>

            <hr />

            <?php
            /*
             * A second form, not a second section in the first one. Changing the origin
             * address is routine; re-posting every credential to do it is not, and a
             * password field is exactly where a stray edit or an autofill goes unnoticed.
             * Separate groups keep the save scoped to the address.
             */
            ?>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'shipping_desk_from_settings' );
                do_settings_sections( 'shipping_desk_from_settings' );
                submit_button( __( 'Save origin address', 'shipping-desk' ) );
                ?>
            </form>

            <hr />

            <h2><?php esc_html_e( 'Backup / Restore Settings', 'shipping-desk' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Export the plugin settings (box sizes, origin address, print mode) to a JSON file, and import them after a reinstall or on another site. API keys are left out unless you ask for them.', 'shipping-desk' ); ?>
            </p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Export', 'shipping-desk' ); ?></th>
                    <td>
                        <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=shipping_desk_export_settings' ), 'shipping_desk_export_settings' ) ); ?>">
                            <?php esc_html_e( 'Download JSON', 'shipping-desk' ); ?>
                        </a>
                        <p class="description"><?php esc_html_e( 'Settings only, no API keys: safe to keep or hand to someone else.', 'shipping-desk' ); ?></p>
                        <p>
                            <a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'include_keys', '1', admin_url( 'admin-post.php?action=shipping_desk_export_settings' ) ), 'shipping_desk_export_settings' ) ); ?>">
                                <?php esc_html_e( 'Download JSON including API keys', 'shipping-desk' ); ?>
                            </a>
                        </p>
                        <p class="description"><?php esc_html_e( 'Use this to move the store to another site. The file then contains live API keys: store it somewhere safe and delete it when you are done.', 'shipping-desk' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Import', 'shipping-desk' ); ?></th>
                    <td>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                            <?php wp_nonce_field( 'shipping_desk_import_settings' ); ?>
                            <input type="hidden" name="action" value="shipping_desk_import_settings" />
                            <input type="file" name="shipping_desk_import_file" accept=".json,application/json" required />
                            <?php submit_button( __( 'Upload and Restore', 'shipping-desk' ), 'secondary', 'submit', false ); ?>
                        </form>
                        <p class="description"><?php esc_html_e( 'Imported values replace the current settings.', 'shipping-desk' ); ?></p>
                    </td>
                </tr>
            </table>

            <?php $this->settings_transfer()->render_import_notices(); ?>

            <hr />

            <h2><?php esc_html_e( 'Tracking', 'shipping-desk' ); ?></h2>
            <p class="description">
                <?php
                switch ( Shipping_Desk_Tracking_Relay::handler() ) {
                    case Shipping_Desk_Tracking_Relay::AST:
                        esc_html_e( 'Advanced Shipment Tracking is installed: tracking numbers are written to its shipment tracking fields, which feed its emails and the customer account page.', 'shipping-desk' );
                        break;
                    case Shipping_Desk_Tracking_Relay::ST:
                        esc_html_e( 'WooCommerce Shipment Tracking is installed: tracking numbers are written to its shipment tracking panel.', 'shipping-desk' );
                        break;
                    default:
                        esc_html_e( 'No shipment tracking plugin was found: the customer is emailed a note with the tracking number when a label is bought.', 'shipping-desk' );
                        break;
                }
                ?>
            </p>
            <form method="post" action="options.php">
                <?php settings_fields( 'shipping_desk_tracking_settings' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Complete on purchase', 'shipping-desk' ); ?></th>
                        <td>
                            <label>
                                <?php // Hidden zero first: an unticked box posts nothing at all. ?>
                                <input type="hidden" name="shipping_desk_complete_order_on_label" value="0" />
                                <input type="checkbox"
                                       name="shipping_desk_complete_order_on_label"
                                       value="1"
                                       <?php checked( 1, (int) get_option( 'shipping_desk_complete_order_on_label', 1 ) ); ?> />
                                <?php esc_html_e( 'Mark the order Completed when a label is bought.', 'shipping-desk' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'Applies to every purchase, whichever tracking plugin is installed: the order moves to Completed and WooCommerce sends its "Completed order" email. Orders that are already Completed are left alone. With no tracking plugin the customer also gets a note with the tracking number, so that shop sees two emails. Untick this if you complete orders yourself.', 'shipping-desk' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr />

            <h2><?php esc_html_e( 'Reship Notifications', 'shipping-desk' ); ?></h2>
            <form method="post" action="options.php">
                <?php settings_fields( 'shipping_desk_reship_settings' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Completed email on reship', 'shipping-desk' ); ?></th>
                        <td>
                            <label>
                                <?php // Hidden zero first: an unticked box posts nothing at all. ?>
                                <input type="hidden" name="shipping_desk_reship_send_completed_email" value="0" />
                                <input type="checkbox"
                                       name="shipping_desk_reship_send_completed_email"
                                       value="1"
                                       <?php checked( 1, (int) get_option( 'shipping_desk_reship_send_completed_email', 0 ) ); ?> />
                                <?php esc_html_e( 'Send the WooCommerce "Completed order" email when a completed order is reshipped.', 'shipping-desk' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'Off by default: a tracking plugin may email the customer itself when it sees new tracking, and two emails are worse than one. Do not assume it does, though - a reship of a completed order never changes status, and Advanced Shipment Tracking sent nothing in exactly that case when tested. Do one test reship; if the customer hears nothing, tick this box.', 'shipping-desk' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr />

            <h2><?php esc_html_e( 'Uninstall Behaviour', 'shipping-desk' ); ?></h2>
            <form method="post" action="options.php">
                <?php settings_fields( 'shipping_desk_uninstall_settings' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Remove data on uninstall', 'shipping-desk' ); ?></th>
                        <td>
                            <label>
                                <?php // Hidden zero first: an unticked box posts nothing at all. ?>
                                <input type="hidden" name="shipping_desk_delete_data_on_uninstall" value="0" />
                                <input type="checkbox"
                                       name="shipping_desk_delete_data_on_uninstall"
                                       value="1"
                                       <?php checked( 1, (int) get_option( 'shipping_desk_delete_data_on_uninstall', 0 ) ); ?> />
                                <?php esc_html_e( 'Delete all plugin settings and order shipping meta when this plugin is deleted.', 'shipping-desk' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'Left unchecked (recommended), deleting the plugin keeps your API keys, box presets and settings so they survive a reinstall. Deleting a stray duplicate copy of the plugin will also leave your live settings untouched.', 'shipping-desk' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }


    public function shipping_desk_get_rates() {
        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        $order    = $this->authorized_order( $order_id, 'shipping_desk_order_' . $order_id );
        // Shipping Desk ships within the US. Refusing is the honest answer: a
        // foreign address described as a US one quoted a rate that could not be
        // bought, and then tripped an address gate over an address that was valid
        // for its own country. See issue #1.
        $destination = Shipping_Desk_Helpers::destination_country( $order );
        if ( ! Shipping_Desk_Helpers::is_supported_destination( $destination ) ) {
            wp_send_json_error(
                [
                    'message' => sprintf(
                        /* translators: %s: ISO country code of the order's destination. */
                        __( 'Shipping Desk supports US destinations only. This order ships to %s, so no rates were requested; use another tool for international labels.', 'shipping-desk' ),
                        $destination
                    ),
                ],
                400
            );
        }

        $box = ( isset( $_POST['box'] ) && is_array( $_POST['box'] ) ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['box'] ) ) : [];
        $total_weight = isset( $_POST['total_weight'] ) ? floatval( $_POST['total_weight'] ) : 0.0;
        if ( $total_weight <= 0 ) {
            $total_weight = Shipping_Desk_Helpers::get_order_items_weight_oz( $order );
        }
        $liability_amount = isset( $_POST['liability_amount'] ) ? floatval( $_POST['liability_amount'] ) : 0.0;
        $payload = Shipping_Desk_Helpers::build_shipping_desk_rate_payload( $order, $box, $total_weight, $liability_amount );

        // Shippo and EasyPost price signature confirmation into the rate, so it
        // has to travel with the rate request. Veeqo prices it as an option
        // *after* the quotes come back and is strict about its payload, so it
        // never sees this key.
        $provider = $this->provider();
        if ( in_array( $provider, [ 'shippo', 'easypost' ], true ) ) {
            $signature = isset( $_POST['signature_confirmation'] )
                ? strtoupper( sanitize_text_field( wp_unslash( $_POST['signature_confirmation'] ) ) )
                : '';
            if ( in_array( $signature, [ 'STANDARD', 'ADULT' ], true ) ) {
                $payload['signature_confirmation'] = $signature;
            }
        }

        $client = $this->api( $provider );

        /*
         * Address validation is a gate, not a nudge.
         *
         * A shipment carries the address, and the purchase only references its rate
         * id, so the address chosen here is the address on the label and in the
         * carrier's record. That is why a correction stops the rate request instead
         * of being flagged next to the quotes: quoting first and fixing afterwards
         * would price one address and ship another.
         */
        $address_validation = null;
        $choice             = isset( $_POST['address_choice'] ) ? sanitize_key( wp_unslash( $_POST['address_choice'] ) ) : '';
        $wants_order_update = ! empty( $_POST['update_order_address'] );

        $validator = $this->validator_client( $client );

        if ( null !== $validator ) {
            list( $validator_name, $validator_client ) = $validator;
            $to_address = isset( $payload['to_address'] ) && is_array( $payload['to_address'] ) ? $payload['to_address'] : [];
            $country    = isset( $to_address['country_code'] ) ? (string) $to_address['country_code'] : '';

            // Always verify. A ZIP+4 is not evidence that anything checked this
            // address (issue #2) — what keeps the bill down is the cache inside
            // validate_recipient_address(), not the shape of the postcode.
            //
            // Verification is a US-only capability: `zip4` is US-only at EasyPost,
            // international AVS is a separate premium service, and this plugin does
            // not ship internationally anyway. Anything else is reported below,
            // never sent to a paid endpoint and never guessed at.
            if ( ! empty( $to_address ) && Shipping_Desk_Helpers::is_supported_destination( $country ) ) {
                $address_validation = $this->quote_store()->validate_recipient_address( $order, $to_address, $validator_client );
                $address_validation = $this->describe_validation( $address_validation, $validator_name );
                $needs_choice       = $this->validation_needs_decision( $address_validation );

                // Carried into the stored record so a reload decides the same way
                // the live request did: a correction nobody had to act on must not
                // reopen as a pending decision hours later.
                $address_validation['needs_decision'] = $needs_choice;

                // A minor correction does not stop the quote, but it is still an
                // address worth having. Checkout offers the same standardisation to
                // the customer, so a customer who kept their own spelling leaves the
                // order reading that way while the label prints the standardised
                // form - the two disagree on every such order. Honouring a choice
                // whenever there is something to apply is what lets the one-click
                // offer in the badge reach the order. Nothing is applied unasked: a
                // choice only arrives when somebody clicked.
                $can_apply = ! empty( $address_validation['apply'] );

                if ( in_array( $choice, [ 'suggested', 'original' ], true ) && ( $needs_choice || $can_apply ) ) {
                    $this->apply_address_choice( $payload, $address_validation, $choice );

                    // The order is corrected here, not at purchase: the operator has
                    // just decided where this parcel goes, and an intent stored for a
                    // later request stays invisible until that request acts on it -
                    // exactly the gap that left the order address untouched with no
                    // note. Only when the box was ticked; the label alone may be all
                    // that was asked for.
                    if ( 'suggested' === $address_validation['applied'] && $wants_order_update ) {
                        $address_note = $this->apply_verified_address( $order, $address_validation );
                        if ( '' !== $address_note ) {
                            $address_validation['order_updated'] = true;
                            $order->add_order_note( $address_note );
                        }
                    }
                } elseif ( $needs_choice ) {
                    // Persist the pending decision too, so a reload or another browser
                    // still shows the panel (and the row keeps its flag in a bulk run).
                    $this->quote_store()->store_quote_record( $order_id, [
                        'fingerprint'        => Shipping_Desk_Helpers::rate_inputs_fingerprint( $payload, $provider, $this->provider_mode( $provider ) ),
                        'created_at'         => time(),
                        'dispatch_day'       => $this->quote_store()->current_dispatch_day( $provider ),
                        'rates'              => [],
                        'matched_rate_id'    => null,
                        'remote_id'          => null,
                        'address_validation' => $address_validation,
                        'inputs'             => [
                            'box_key'        => isset( $_POST['box_key'] ) ? sanitize_text_field( wp_unslash( $_POST['box_key'] ) ) : '',
                            'box'            => $box,
                            'weight'         => $total_weight,
                            'liability'      => $liability_amount,
                            'signature'      => isset( $payload['signature_confirmation'] ) ? $payload['signature_confirmation'] : '',
                            'address_choice' => '',
                            'update_order'   => false,
                        ],
                    ] );

                    wp_send_json_success( [
                        'order_id'             => $order_id,
                        'total_weight'         => $total_weight,
                        'rates'                => [],
                        'matched_rate_id'      => null,
                        'remote_id'            => null,
                        'needs_address_choice' => true,
                        'address_validation'   => $address_validation,
                    ] );
                }
            } elseif ( ! empty( $to_address ) ) {
                // Destination outside the plugin's scope. Reported rather than
                // silent: an address that was never checked must not look checked.
                $address_validation = [
                    'status'        => 'skipped',
                    'reason'        => 'country_not_supported',
                    'original_line' => Shipping_Desk_Helpers::format_address( $to_address ),
                ];
            }
        }

        $result = $client->get_rates( $payload );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
        }
        $remote_id = $result['remote_shipment_id'] ?? ( $result['remote_id'] ?? ( $result['shipment_id'] ?? null ) );
        if ( ! empty( $result['quotes'] ) && is_array( $result['quotes'] ) ) {
            foreach ( $result['quotes'] as &$quote ) {
                $quote['remote_shipment_id'] = $remote_id;
            }
            unset( $quote );
        }
        $available = $result['quotes'] ?? [];
        usort( $available, function ( $a, $b ) {
            return (float) ( $a['total_charge'] ?? 0 ) <=> (float) ( $b['total_charge'] ?? 0 );
        } );
        // Rates are sorted by price, so start with the cheapest as the fallback.
        $matched_rate_id = ! empty( $available ) ? ( $available[0]['rate_id'] ?? null ) : null;
        $best_score      = -1.0;
        $wc_service      = Shipping_Desk_Helpers::normalize_service_name( Shipping_Desk_Helpers::get_order_shipping_method_label( $order ) );
        foreach ( $available as $rate ) {
            $veeqo_service = Shipping_Desk_Helpers::normalize_service_name( $rate['service_name'] ?? '' );
            if ( $wc_service !== '' && $wc_service === $veeqo_service ) {
                $matched_rate_id = $rate['rate_id'] ?? null;
                break;
            }
            if ( $wc_service === '' || $veeqo_service === '' ) {
                continue;
            }

            if ( strpos( $wc_service, $veeqo_service ) !== false || strpos( $veeqo_service, $wc_service ) !== false ) {
                $score = 100.0;
            } else {
                $score = 0.0;
                similar_text( $wc_service, $veeqo_service, $score );
                $score = min( 99.0, $score );
            }
            if ( $score > $best_score ) {
                $best_score      = $score;
                $matched_rate_id = $rate['rate_id'] ?? null;
            }
        }
        // Persist the quotes before answering, so the next page load can show them
        // without paying for them again. `expires_at` folds the carrier's own expiry
        // into the hard cap; the dispatch day is checked separately at read time.
        $provider_expiry = null;
        foreach ( [ 'expires_at', 'rates_expire_at' ] as $expiry_key ) {
            if ( ! empty( $result[ $expiry_key ] ) && is_string( $result[ $expiry_key ] ) ) {
                $parsed = strtotime( $result[ $expiry_key ] );
                if ( false !== $parsed ) {
                    $provider_expiry = $parsed;
                }
            }
        }

        $this->quote_store()->store_quote_record( $order_id, [
            'fingerprint'         => Shipping_Desk_Helpers::rate_inputs_fingerprint( $payload, $provider, $this->provider_mode( $provider ) ),
            'created_at'          => time(),
            'provider_expires_at' => $provider_expiry,
            'dispatch_day'        => $this->quote_store()->current_dispatch_day( $provider ),
            'rates'               => $available,
            'matched_rate_id'     => $matched_rate_id,
            'remote_id'           => $remote_id,
            'address_validation'  => $address_validation,
            'inputs'              => [
                'box_key'        => isset( $_POST['box_key'] ) ? sanitize_text_field( wp_unslash( $_POST['box_key'] ) ) : '',
                'box'            => $box,
                'weight'         => $total_weight,
                'liability'      => $liability_amount,
                'signature'      => isset( $payload['signature_confirmation'] ) ? $payload['signature_confirmation'] : '',
                'address_choice' => $choice,
                'update_order'   => $wants_order_update,
            ],
        ] );

        wp_send_json_success( [
            'order_id'        => $order_id,
            'total_weight'    => $total_weight,
            'rates'           => $available,
            'matched_rate_id' => $matched_rate_id,
            'remote_id'       => $remote_id,
            'address_validation' => $address_validation,
        ] );
    }

    /**
     * Add the display strings the decision panel needs.
     *
     * Formatting is done once, server-side, so the browser has nothing to
     * reconstruct and the wording matches the order notes.
     *
     * @param array $validation Normalised validation result.
     * @return array
     */
    protected function describe_validation( array $validation, $source = 'shippo' ) {
        return Shipping_Desk_Helpers::describe_address_validation( $validation, $source );
    }

    /**
     * Does this verdict need the operator to choose?
     *
     * Not every correction is worth stopping a quote for. A verifier standardises
     * everything it matches — upper case, abbreviation, ZIP+4 — so treating
     * "corrected" as "ask the operator" put a mandatory click on every order whose
     * address had never been through checkout validation. That is the shape the
     * old ZIP+4 skip was hiding, and it is why the skip now has to be a real rule
     * rather than a postcode test (issue #2).
     *
     * A decision is required only when ignoring the suggestion could send the
     * parcel somewhere else: an invalid address, or a material change. Everything
     * else is reported in the badge and the address is used as entered.
     *
     * @param array $validation Normalised result carrying `original` and `recommended`.
     * @return bool
     */
    protected function validation_needs_decision( array $validation ) {
        $status = isset( $validation['status'] ) ? (string) $validation['status'] : '';

        if ( 'invalid' === $status ) {
            return true;
        }

        if ( 'corrected' !== $status ) {
            return false;
        }

        $original    = isset( $validation['original'] ) && is_array( $validation['original'] ) ? $validation['original'] : [];
        $recommended = isset( $validation['recommended'] ) && is_array( $validation['recommended'] ) ? $validation['recommended'] : [];

        if ( empty( $recommended ) ) {
            return false;
        }

        return Shipping_Desk_Helpers::address_change_is_material( $original, $recommended );
    }


    /**
     * Which verifier the operator chose.
     *
     * @return string 'auto', 'easypost', 'provider' or 'off'.
     */
    public function verifier_choice() {
        $choice = (string) get_option( 'shipping_desk_verifier', 'auto' );

        return in_array( $choice, [ 'auto', 'easypost', 'provider', 'off' ], true ) ? $choice : 'auto';
    }

    /**
     * The address validator to use, with a name for display, or null for none.
     *
     * One rule for both surfaces: the admin gate and the checkout endpoint both
     * call this, so a customer and an operator can never be told different things
     * about the same address.
     *
     * On `auto` EasyPost wins when a key is set: it is independent of the label
     * provider and returns the standardised address, which the provider's own
     * validator does not always do. Otherwise the provider validates itself if it
     * can — Shippo can, Veeqo cannot. `provider` forces that order, `easypost`
     * refuses to fall back to it, and `off` means never ask.
     *
     * There is no separate verifier key and no cross-slot fallback: the verifier
     * uses exactly the key the operator selected for the current mode.
     *
     * @param object|null $provider_client Client already built for the active
     *                                    provider, when the caller has one.
     * @return array|null [ name, validator client ].
     */
    public function validator_client( $provider_client = null ) {
        $choice = $this->verifier_choice();

        if ( 'off' === $choice ) {
            return null;
        }

        if ( in_array( $choice, [ 'auto', 'easypost' ], true ) ) {
            $key = $this->easypost_api_key();
            if ( '' !== $key && class_exists( 'Shipping_Desk_EasyPost_Client' ) ) {
                return [ 'easypost', new Shipping_Desk_EasyPost_Client( $key ) ];
            }
            if ( 'easypost' === $choice ) {
                return null;
            }
        }

        $client = null === $provider_client ? $this->api() : $provider_client;

        if ( $client instanceof Shipping_Desk_Address_Validator_Interface ) {
            return [ $this->provider(), $client ];
        }

        return null;
    }

    /**
     * Is checkout-side validation switched on?
     *
     * @return bool
     */
    public function checkout_validation_enabled() {
        return 1 === (int) get_option( 'shipping_desk_checkout_validation', 0 );
    }

    /**
     * What the settings screen says about verification, in words.
     *
     * "No verifier configured" and "the address is clean" look identical on a
     * panel that only shows a checkbox, so this states which verifier and which
     * mode would actually answer — and warns when EasyPost's mode disagrees with
     * the label provider's, which would otherwise show customers corrections
     * produced by a test key.
     *
     * @return array name, mode, note, warning.
     */
    public function verifier_summary() {
        $enabled  = $this->checkout_validation_enabled();
        $provider = $this->provider();

        if ( 'off' === $this->verifier_choice() ) {
            return [
                'name'    => '',
                'mode'    => '',
                'note'    => __( 'Verification is turned off, so neither checkout nor the address gate will ask.', 'shipping-desk' ),
                'warning' => '',
            ];
        }

        $validator = $this->validator_client();
        if ( null === $validator ) {
            return [
                'name'    => '',
                'mode'    => '',
                'note'    => __( 'No verifier is available yet: add an EasyPost key, or select a provider that can verify (Shippo can, Veeqo cannot).', 'shipping-desk' ),
                'warning' => '',
            ];
        }

        list( $name ) = $validator;
        $mode  = 'easypost' === $name ? $this->easypost_mode() : $this->shippo_mode();
        $label = 'easypost' === $name ? 'EasyPost' : 'Shippo';

        $note = $enabled
            ? sprintf(
                /* translators: 1: verifier name, 2: key mode (test or production). */
                __( 'Addresses are checked at checkout by %1$s, using the %2$s key.', 'shipping-desk' ),
                $label,
                'live' === $mode ? __( 'production', 'shipping-desk' ) : __( 'test', 'shipping-desk' )
            )
            : sprintf(
                /* translators: 1: verifier name, 2: key mode (test or production). */
                __( 'When enabled, addresses would be checked by %1$s, using the %2$s key.', 'shipping-desk' ),
                $label,
                'live' === $mode ? __( 'production', 'shipping-desk' ) : __( 'test', 'shipping-desk' )
            );

        // A store on the block checkout is never told suggestions are running when
        // its checkout has nowhere to show them: the server standardises the order
        // instead, and material corrections wait for the orders screen.
        if ( $enabled && class_exists( 'Shipping_Desk_Block_Checkout' ) && Shipping_Desk_Block_Checkout::is_block_checkout() ) {
            $note .= ' ' . __( 'This store uses the block checkout, which has nowhere to show a suggestion: a correction that cannot move the parcel is applied to the order automatically and noted, while a material one waits for the orders screen.', 'shipping-desk' );
        }

        $warning = '';
        if ( 'easypost' === $name && 'easypost' !== $provider ) {
            $provider_mode = $this->provider_mode( $provider );
            if ( '' !== $provider_mode && $provider_mode !== $mode ) {
                $warning = sprintf(
                    /* translators: 1: EasyPost key mode, 2: label provider name, 3: that provider's key mode. */
                    __( 'EasyPost is in %1$s mode while %2$s is in %3$s mode: customers would be shown corrections produced by that EasyPost key.', 'shipping-desk' ),
                    'live' === $mode ? __( 'production', 'shipping-desk' ) : __( 'test', 'shipping-desk' ),
                    'shippo' === $provider ? 'Shippo' : ucfirst( $provider ),
                    'live' === $provider_mode ? __( 'production', 'shipping-desk' ) : __( 'test', 'shipping-desk' )
                );
            }
        }

        return [
            'name'    => $name,
            'mode'    => $mode,
            'note'    => $note,
            'warning' => $warning,
        ];
    }

    /**
     * Put the chosen address into the rate payload.
     *
     * The payload is what the provider turns into a shipment, and the label is bought on
     * that shipment - so this is the address that prints. It therefore goes through the
     * same correction as the order (see Shipping_Desk_Helpers::apply_address_correction):
     * a suite the verifier folded into line 1 has to leave line 2 empty here too, or the
     * label carries the apartment twice.
     *
     * @param array  $payload    Rate payload, by reference.
     * @param array  $validation Normalised validation result, by reference.
     * @param string $choice     'suggested' or 'original'.
     * @return void
     */
    protected function apply_address_choice( array &$payload, array &$validation, $choice ) {
        $recommended = isset( $validation['recommended'] ) && is_array( $validation['recommended'] )
            ? $validation['recommended']
            : [];

        if ( 'suggested' !== $choice || empty( $recommended ) ) {
            // Nothing to apply, or the operator kept their address: quote as
            // entered. "Suggested" with an empty recommendation is still a valid
            // choice, it just cannot change anything.
            $validation['applied'] = 'original';
            return;
        }

        $current = isset( $payload['to_address'] ) && is_array( $payload['to_address'] )
            ? $payload['to_address']
            : [];

        $payload['to_address'] = Shipping_Desk_Helpers::apply_address_correction( $current, $recommended );

        $validation['applied'] = 'suggested';
    }

    /**
     * Write a verified address onto the order's shipping address, now.
     *
     * Called the moment the operator accepts the suggestion with the box ticked -
     * not stored for the purchase to act on later. A deferred intent is invisible
     * until something acts on it, which is how "ticked the box, address never
     * changed, no note appeared" happened: the quote was priced from the corrected
     * address while the order kept the old one. Writing it here means the order,
     * the quotes and the label all describe one address from the same moment.
     *
     * Only the *shipping* address is ever touched: the billing address is the one the
     * customer entered and the one the payment gateway checked, so rewriting it would
     * break the AVS trail for no benefit. A field the verifier did not return is left
     * alone - blanking line 2 because the verifier omitted it would delete a real
     * suite number.
     *
     * @param WC_Order $order      Order being quoted.
     * @param array    $validation Normalised validation result.
     * @return string Note text, or '' when the order already read that way.
     */
    protected function apply_verified_address( WC_Order $order, array $validation ) {
        $recommended = isset( $validation['recommended'] ) && is_array( $validation['recommended'] )
            ? $validation['recommended']
            : [];

        if ( empty( $recommended ) ) {
            return '';
        }

        $before = [
            'line1'        => (string) $order->get_shipping_address_1(),
            'line2'        => (string) $order->get_shipping_address_2(),
            'town'         => (string) $order->get_shipping_city(),
            'county'       => (string) $order->get_shipping_state(),
            'postcode'     => (string) $order->get_shipping_postcode(),
            'country_code' => (string) $order->get_shipping_country(),
        ];

        $properties = [
            'line1'        => 'shipping_address_1',
            'line2'        => 'shipping_address_2',
            'town'         => 'shipping_city',
            'county'       => 'shipping_state',
            'postcode'     => 'shipping_postcode',
            'country_code' => 'shipping_country',
        ];

        // One correction, shared with the rate payload: a field the verifier did not
        // return keeps its value, and a second line the new first line already contains is
        // dropped rather than printed twice.
        $after  = Shipping_Desk_Helpers::apply_address_correction( $before, $recommended );
        $folded = '' !== $before['line2'] && '' === $after['line2'];

        $changed = 0;
        foreach ( $properties as $key => $property ) {
            if ( $before[ $key ] === $after[ $key ] ) {
                continue;
            }
            $order->{ 'set_' . $property }( $after[ $key ] );
            $changed++;
        }

        if ( 0 === $changed ) {
            // The order already reads that way: no note, nothing to report.
            return '';
        }

        $order->save();

        $fields = isset( $validation['changed'] ) && is_array( $validation['changed'] )
            ? $validation['changed']
            : [];
        if ( $folded ) {
            // The verifier reports a moved suite as a changed line 1; what the order
            // needs to say is that line 2 went with it.
            $fields[] = 'address_line_2_folded';
        }

        $summary = implode( ', ', Shipping_Desk_Helpers::describe_address_changes( $fields ) );
        if ( '' === $summary ) {
            $summary = __( 'standardised', 'shipping-desk' );
        }

        $source = ( isset( $validation['validator'] ) && 'easypost' === $validation['validator'] )
            ? 'EasyPost'
            : 'Shippo';

        return sprintf(
            /* translators: 1: validator name, 2: previous address, 3: verified address, 4: what changed */
            __( 'Shipping address updated from %1$s verification: %2$s → %3$s (%4$s). The label uses the verified address.', 'shipping-desk' ),
            $source,
            Shipping_Desk_Helpers::format_address( $before ),
            Shipping_Desk_Helpers::format_address( $after ),
            $summary
        );
    }


    /** -- Bulk Buy Label (single Veeqo request for multiple shipments) -- */

    /**
     * Lazy instantiation of the settings registration and field renderers.
     *
     * Public: the pages render its fields, and the backup / restore handler asks it for
     * the sanitisers.
     *
     * @return Shipping_Desk_Settings
     */
    public function settings() {
        if ( ! isset( $this->_settings ) ) {
            require_once $this->path . 'includes/class-settings.php';
            $this->_settings = new Shipping_Desk_Settings( $this );
        }
        return $this->_settings;
    }

    /**
     * Lazy instantiation of the Shipment Lookup page.
     *
     * @return Shipping_Desk_Lookup_Page
     */
    public function lookup_page() {
        if ( ! isset( $this->_lookup_page ) ) {
            require_once $this->path . 'includes/class-lookup-page.php';
            $this->_lookup_page = new Shipping_Desk_Lookup_Page( $this );
        }
        return $this->_lookup_page;
    }

    /**
     * Lazy instantiation of the orders screen.
     *
     * @return Shipping_Desk_Orders_Screen
     */
    public function orders_screen() {
        if ( ! isset( $this->_orders_screen ) ) {
            require_once $this->path . 'includes/class-orders-screen.php';
            $this->_orders_screen = new Shipping_Desk_Orders_Screen( $this );
        }
        return $this->_orders_screen;
    }

    /**
     * Lazy instantiation of the label actions (print, refund, reset).
     *
     * @return Shipping_Desk_Label_Actions
     */
    public function label_actions() {
        if ( ! isset( $this->_label_actions ) ) {
            require_once $this->path . 'includes/class-label-actions.php';
            $this->_label_actions = new Shipping_Desk_Label_Actions( $this );
        }
        return $this->_label_actions;
    }

    /**
     * Lazy instantiation of the label purchase handler.
     *
     * Public: refund and reset, still in the plugin, ask it to clear tracking and cost.
     *
     * @return Shipping_Desk_Labels
     */
    public function labels() {
        if ( ! isset( $this->_labels ) ) {
            require_once $this->path . 'includes/class-labels.php';
            $this->_labels = new Shipping_Desk_Labels( $this );
        }
        return $this->_labels;
    }

    /**
     * Lazy instantiation of the quote store.
     *
     * Public: the purchase code asks it for the stored quotes.
     *
     * @return Shipping_Desk_Quote_Store
     */
    public function quote_store() {
        if ( ! isset( $this->_quote_store ) ) {
            require_once $this->path . 'includes/class-quote-store.php';
            $this->_quote_store = new Shipping_Desk_Quote_Store( $this );
        }
        return $this->_quote_store;
    }

    /**
     * Lazy instantiation of the settings backup / restore handler.
     *
     * @return Shipping_Desk_Settings_Transfer
     */
    protected function settings_transfer() {
        if ( ! isset( $this->_settings_transfer ) ) {
            require_once $this->path . 'includes/class-settings-transfer.php';
            $this->_settings_transfer = new Shipping_Desk_Settings_Transfer( $this );
        }
        return $this->_settings_transfer;
    }

    /**
     * Lazy instantiation of the PrintNode API client.
     *
     * @return Shipping_Desk_PrintNode_Client
     */
    public function printnode() {
        if ( ! isset( $this->_printnode_client ) ) {
            require_once $this->path . 'includes/class-printnode-client.php';
            $this->_printnode_client = new Shipping_Desk_PrintNode_Client();
        }
        return $this->_printnode_client;
    }

    /** -- PrintNode AJAX handlers -- */

    /**
     * Status endpoint so the client can verify PrintNode is configured without a per-order
     * nonce. It still requires the manage_woocommerce capability (see require_cap() below).
     */
    public function shipping_desk_printnode_status() {
        $this->require_cap();

        $api_key    = get_option( 'shipping_desk_printnode_api_key', '' );
        $printer_id = (int) get_option( 'shipping_desk_printnode_printer_id', 0 );

        if ( empty( $api_key ) ) {
            wp_send_json_success( [
                'configured' => false,
                'message'    => __( 'PrintNode API key is not set.', 'shipping-desk' ),
            ] );
        }
        if ( ! $printer_id ) {
            wp_send_json_success( [
                'configured' => false,
                'message'    => __( 'PrintNode printer not selected.', 'shipping-desk' ),
            ] );
        }

        wp_send_json_success( [ 'configured' => true ] );
    }

    public function shipping_desk_printnode_refresh_printers() {
        $this->require_cap();
        if ( ! check_ajax_referer( 'shipping_desk_printnode', '_wpnonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid nonce.', 'shipping-desk' ) ], 400 );
        }

        $client = $this->printnode();
        ob_start();
        $this->render_printnode_printer_list( 0 );
        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html ] );
    }

}
