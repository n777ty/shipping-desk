<?php
/**
 * Main plugin class.
 */
class Shipping_Desk_Plugin {

    /**
     * How long a validation result is reused, in seconds.
     *
     * Addresses do not change while a row is being worked on, and Get Rates is
     * clicked again after every box, signature or insurance change. One paid call
     * per address per half-day.
     */
    const ADDRESS_VALIDATION_TTL = 43200;

    /**
     * Hard cap on how long a stored quote may be reused, in hours.
     *
     * The dispatch day is the real limit and is checked at read time; this cap only
     * stops a quiet weekend from leaving Friday's prices on screen on Tuesday.
     */
    const QUOTE_RECORD_CAP_HOURS = 72;

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

    public function __construct( $file ) {
        $this->file = $file;
        $this->path = plugin_dir_path( $file );
        $this->url  = plugin_dir_url( $file );
    }

    public function init() {
        add_action( 'init', [ $this, 'load_textdomain' ] );
        add_action( 'admin_menu', [ $this, 'register_admin_pages' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        // Runs before settings are used so legacy options are carried over.
        add_action( 'admin_init', [ $this, 'maybe_migrate_legacy_settings' ], 1 );
        add_action( 'admin_init', [ $this, 'maybe_migrate_easypost_key' ], 2 );
        add_action( 'admin_init', [ $this, 'maybe_default_legacy_provider' ], 3 );
        add_action( 'admin_post_shipping_desk_export_settings', [ $this, 'handle_export_settings' ] );
        add_action( 'admin_post_shipping_desk_import_settings', [ $this, 'handle_import_settings' ] );

        // AJAX
        foreach ( [ 'shipping_desk_get_rates', 'shipping_desk_buy_label', 'shipping_desk_print_label', 'shipping_desk_print_pdf', 'shipping_desk_refund_label', 'shipping_desk_reset_shipping', 'shipping_desk_render_purchase_row', 'shipping_desk_printnode_refresh_printers', 'shipping_desk_printnode_print_label', 'shipping_desk_printnode_status', 'shipping_desk_bulk_buy', 'shipping_desk_switch_provider' ] as $action ) {
            add_action( 'wp_ajax_' . $action, [ $this, $action ] );
        }
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'shipping-desk', false, dirname( plugin_basename( $this->file ) ) . '/languages' );
    }

    public function register_admin_pages() {
        add_menu_page(
            __( 'Shipping Desk', 'shipping-desk' ),
            __( 'Shipping Desk', 'shipping-desk' ),
            'manage_woocommerce',
            'shipping-desk',
            [ $this, 'render_orders_page' ],
            'dashicons-admin-generic',
            56
        );

        add_submenu_page(
            'shipping-desk',
            __( 'Shipment Lookup', 'shipping-desk' ),
            __( 'Shipment Lookup', 'shipping-desk' ),
            'manage_woocommerce',
            'shipping-desk-lookup',
            [ $this, 'render_lookup_page' ]
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
            __( 'PrintNode Settings', 'shipping-desk' ),
            __( 'PrintNode Settings', 'shipping-desk' ),
            'manage_woocommerce',
            'shipping-desk-printnode',
            [ $this, 'render_printnode_settings_page' ]
        );
    }

    public function register_settings() {
        register_setting( 'shipping_desk_box_settings', 'shipping_desk_boxes', [ 'sanitize_callback' => [ $this, 'sanitize_boxes' ] ] );

        // Label provider. Defaults to Shippo; EasyPost and Veeqo are one radio
        // button away, and old labels keep their own provider marker either way.
        register_setting( 'shipping_desk_api_settings', 'shipping_desk_provider', [ 'sanitize_callback' => function ( $value ) {
            return in_array( $value, [ 'veeqo', 'shippo', 'easypost' ], true ) ? $value : 'shippo';
        } ] );
        // Shippo keeps both keys: switching between test and production must not
        // destroy the other one.
        register_setting( 'shipping_desk_api_settings', 'shipping_desk_shippo_mode', [ 'sanitize_callback' => function ( $value ) {
            return 'live' === $value ? 'live' : 'test';
        } ] );
        register_setting( 'shipping_desk_api_settings', 'shipping_desk_shippo_test_api_key' );
        register_setting( 'shipping_desk_api_settings', 'shipping_desk_shippo_live_api_key' );

        // EasyPost is both a label provider and the address verifier, so one mode
        // and one pair of keys govern both jobs. There is deliberately no third,
        // verifier-only key.
        register_setting( 'shipping_desk_api_settings', 'shipping_desk_easypost_mode', [ 'sanitize_callback' => function ( $value ) {
            return 'live' === $value ? 'live' : 'test';
        } ] );
        register_setting( 'shipping_desk_api_settings', 'shipping_desk_easypost_test_api_key' );
        register_setting( 'shipping_desk_api_settings', 'shipping_desk_easypost_live_api_key' );

        register_setting( 'shipping_desk_api_settings', 'shipping_desk_veeqo_api_key' );
        // Legacy single EasyPost key, kept registered so an upgrade never wipes it
        // before maybe_migrate_easypost_key() has copied it into its slot.
        register_setting( 'shipping_desk_api_settings', 'shipping_desk_easypost_api_key' );
        register_setting( 'shipping_desk_api_settings', 'shipping_desk_from_address', [ 'sanitize_callback' => [ $this, 'sanitize_from_address' ] ] );

        add_settings_section( 'shipping_desk_provider_section', __( 'Label Provider', 'shipping-desk' ), '__return_false', 'shipping_desk_api_settings' );
        add_settings_field( 'shipping_desk_provider_field', __( 'Active provider', 'shipping-desk' ), [ $this, 'render_provider_field' ], 'shipping_desk_api_settings', 'shipping_desk_provider_section' );
        add_settings_field( 'shipping_desk_shippo_mode_field', __( 'Shippo mode', 'shipping-desk' ), [ $this, 'render_shippo_mode_field' ], 'shipping_desk_api_settings', 'shipping_desk_provider_section' );
        add_settings_field( 'shipping_desk_shippo_keys_field', __( 'Shippo API keys', 'shipping-desk' ), [ $this, 'render_shippo_keys_field' ], 'shipping_desk_api_settings', 'shipping_desk_provider_section' );
        add_settings_field( 'shipping_desk_easypost_mode_field', __( 'EasyPost mode', 'shipping-desk' ), [ $this, 'render_easypost_mode_field' ], 'shipping_desk_api_settings', 'shipping_desk_provider_section' );
        add_settings_field( 'shipping_desk_easypost_keys_field', __( 'EasyPost API keys', 'shipping-desk' ), [ $this, 'render_easypost_keys_field' ], 'shipping_desk_api_settings', 'shipping_desk_provider_section' );

        add_settings_section( 'shipping_desk_api_section', __( 'Veeqo API Settings', 'shipping-desk' ), '__return_false', 'shipping_desk_api_settings' );
        add_settings_field( 'shipping_desk_veeqo_api_key_field', __( 'Veeqo API Key', 'shipping-desk' ), [ $this, 'render_api_key_field' ], 'shipping_desk_api_settings', 'shipping_desk_api_section' );

        add_settings_section( 'shipping_desk_from_address_section', __( 'From Address (Optional)', 'shipping-desk' ), '__return_false', 'shipping_desk_api_settings' );
        add_settings_field( 'shipping_desk_from_address_field', __( 'Shipment Origin', 'shipping-desk' ), [ $this, 'render_from_address_field' ], 'shipping_desk_api_settings', 'shipping_desk_from_address_section' );

        register_setting( 'shipping_desk_printnode_settings', 'shipping_desk_printnode_api_key' );
        register_setting( 'shipping_desk_printnode_settings', 'shipping_desk_printnode_printer_id', [ 'sanitize_callback' => 'absint' ] );
        register_setting( 'shipping_desk_printnode_settings', 'shipping_desk_print_mode', [ 'sanitize_callback' => function ( $value ) {
            return in_array( $value, [ 'browser', 'printnode' ], true ) ? $value : 'browser';
        } ] );

        register_setting( 'shipping_desk_api_settings', 'shipping_desk_delete_data_on_uninstall', [ 'sanitize_callback' => function ( $value ) {
            return $value ? 1 : 0;
        } ] );

        register_setting( 'shipping_desk_api_settings', 'shipping_desk_reship_send_completed_email', [ 'sanitize_callback' => function ( $value ) {
            return $value ? 1 : 0;
        } ] );
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
            'shipping_desk_from_address',
            'shipping_desk_boxes',
            'shipping_desk_print_mode',
            'shipping_desk_printnode_api_key',
            'shipping_desk_printnode_printer_id',
        ];
    }

    /**
     * Copy settings from the pre-refactor plugin ("Woo Veeqo Rate Shop").
     *
     * That plugin stored its options under woo_veeqo_* keys. Because this
     * plugin is a different WordPress plugin entirely, an "upgrade" leaves the
     * old options in place but invisible, which looks like settings were wiped.
     * This carries them across once, never overwriting values already set.
     */
    public function maybe_migrate_legacy_settings() {
        if ( get_option( 'shipping_desk_legacy_migrated' ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $map = [
            'shipping_desk_veeqo_api_key'              => 'woo_veeqo_api_key',
            'shipping_desk_boxes'                => 'woo_veeqo_boxes',
            'shipping_desk_from_address'         => 'woo_veeqo_from_address',
            'shipping_desk_printnode_api_key'    => 'woo_veeqo_printnode_api_key',
            'shipping_desk_printnode_printer_id' => 'woo_veeqo_printnode_printer_id',
            'shipping_desk_print_mode'           => 'woo_veeqo_print_mode',
        ];

        $migrated = [];

        foreach ( $map as $new_key => $old_key ) {
            $existing = get_option( $new_key, null );
            // Never overwrite a value that is already configured.
            if ( null !== $existing && '' !== $existing && [] !== $existing ) {
                continue;
            }
            $legacy = get_option( $old_key, null );
            if ( null === $legacy || '' === $legacy || [] === $legacy ) {
                continue;
            }
            update_option( $new_key, $legacy );
            $migrated[] = $new_key;
        }

        update_option( 'shipping_desk_legacy_migrated', 1 );

        if ( $migrated ) {
            set_transient( 'shipping_desk_migration_notice', $migrated, 60 );
        }
    }

    /**
     * Export the current settings as JSON.
     */
    public function handle_export_settings() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to export settings.', 'shipping-desk' ) );
        }
        check_admin_referer( 'shipping_desk_export_settings' );

        $boxes = get_option( 'shipping_desk_boxes', [] );
        $payload = [
            'plugin'      => 'shipping-desk',
            'version'     => defined( 'SHIPPING_DESK_VERSION' ) ? SHIPPING_DESK_VERSION : '',
            'exported_at' => gmdate( 'c' ),
            'settings'    => [
                'api_key'              => (string) get_option( 'shipping_desk_veeqo_api_key', '' ),
                'provider'             => $this->provider(),
                'shippo_mode'          => $this->shippo_mode(),
                'shippo_test_api_key'  => (string) get_option( 'shipping_desk_shippo_test_api_key', '' ),
                'shippo_live_api_key'  => (string) get_option( 'shipping_desk_shippo_live_api_key', '' ),
                'easypost_mode'        => $this->easypost_mode(),
                'easypost_test_api_key' => (string) get_option( 'shipping_desk_easypost_test_api_key', '' ),
                'easypost_live_api_key' => (string) get_option( 'shipping_desk_easypost_live_api_key', '' ),
                'from_address'         => get_option( 'shipping_desk_from_address', [] ),
                'boxes'                => is_array( $boxes ) ? array_values( $boxes ) : [],
                'print_mode'           => (string) get_option( 'shipping_desk_print_mode', 'browser' ),
                'printnode_api_key'    => (string) get_option( 'shipping_desk_printnode_api_key', '' ),
                'printnode_printer_id' => (int) get_option( 'shipping_desk_printnode_printer_id', 0 ),
            ],
        ];

        $filename = 'shipping-desk-settings-' . gmdate( 'Ymd-His' ) . '.json';

        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        exit;
    }

    /**
     * Import settings from an uploaded JSON export.
     */
    public function handle_import_settings() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to import settings.', 'shipping-desk' ) );
        }
        check_admin_referer( 'shipping_desk_import_settings' );

        $redirect = add_query_arg( 'page', 'shipping-desk-settings', admin_url( 'admin.php' ) );

        if ( empty( $_FILES['shipping_desk_import_file']['tmp_name'] ) ) {
            wp_safe_redirect( add_query_arg( 'shipping_desk_import', 'nofile', $redirect ) );
            exit;
        }

        $file    = $_FILES['shipping_desk_import_file'];
        $raw     = file_get_contents( $file['tmp_name'] );
        $decoded = json_decode( (string) $raw, true );

        if ( ! is_array( $decoded ) || empty( $decoded['settings'] ) || ! is_array( $decoded['settings'] ) ) {
            wp_safe_redirect( add_query_arg( 'shipping_desk_import', 'invalid', $redirect ) );
            exit;
        }

        $settings = $decoded['settings'];
        $imported = [];

        if ( isset( $settings['api_key'] ) ) {
            update_option( 'shipping_desk_veeqo_api_key', sanitize_text_field( (string) $settings['api_key'] ) );
            $imported[] = 'api_key';
        }

        if ( isset( $settings['provider'] ) ) {
            update_option( 'shipping_desk_provider', $this->normalize_provider( $settings['provider'], 'shippo' ) );
            $imported[] = 'provider';
        }

        if ( isset( $settings['shippo_mode'] ) ) {
            update_option( 'shipping_desk_shippo_mode', 'live' === $settings['shippo_mode'] ? 'live' : 'test' );
            $imported[] = 'shippo_mode';
        }

        if ( isset( $settings['shippo_test_api_key'] ) ) {
            update_option( 'shipping_desk_shippo_test_api_key', sanitize_text_field( (string) $settings['shippo_test_api_key'] ) );
            $imported[] = 'shippo_test_api_key';
        }

        if ( isset( $settings['shippo_live_api_key'] ) ) {
            update_option( 'shipping_desk_shippo_live_api_key', sanitize_text_field( (string) $settings['shippo_live_api_key'] ) );
            $imported[] = 'shippo_live_api_key';
        }

        if ( isset( $settings['easypost_mode'] ) ) {
            update_option( 'shipping_desk_easypost_mode', 'live' === $settings['easypost_mode'] ? 'live' : 'test' );
            $imported[] = 'easypost_mode';
        }

        if ( isset( $settings['easypost_test_api_key'] ) ) {
            update_option( 'shipping_desk_easypost_test_api_key', sanitize_text_field( (string) $settings['easypost_test_api_key'] ) );
            $imported[] = 'easypost_test_api_key';
        }

        if ( isset( $settings['easypost_live_api_key'] ) ) {
            update_option( 'shipping_desk_easypost_live_api_key', sanitize_text_field( (string) $settings['easypost_live_api_key'] ) );
            $imported[] = 'easypost_live_api_key';
        }

        if ( isset( $settings['from_address'] ) && is_array( $settings['from_address'] ) ) {
            update_option( 'shipping_desk_from_address', $this->sanitize_from_address( $settings['from_address'] ) );
            $imported[] = 'from_address';
        }

        if ( isset( $settings['boxes'] ) && is_array( $settings['boxes'] ) ) {
            update_option( 'shipping_desk_boxes', $this->sanitize_boxes( $settings['boxes'] ) );
            $imported[] = 'boxes';
        }

        if ( isset( $settings['print_mode'] ) ) {
            $mode = in_array( $settings['print_mode'], [ 'browser', 'printnode' ], true ) ? $settings['print_mode'] : 'browser';
            update_option( 'shipping_desk_print_mode', $mode );
            $imported[] = 'print_mode';
        }

        if ( isset( $settings['printnode_api_key'] ) ) {
            update_option( 'shipping_desk_printnode_api_key', sanitize_text_field( (string) $settings['printnode_api_key'] ) );
            $imported[] = 'printnode_api_key';
        }

        if ( isset( $settings['printnode_printer_id'] ) ) {
            update_option( 'shipping_desk_printnode_printer_id', absint( $settings['printnode_printer_id'] ) );
            $imported[] = 'printnode_printer_id';
        }

        set_transient( 'shipping_desk_import_notice', $imported, 60 );
        wp_safe_redirect( add_query_arg( 'shipping_desk_import', 'ok', $redirect ) );
        exit;
    }

    public function sanitize_boxes( $value ) {
        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            if ( is_array( $decoded ) ) {
                $value = $decoded;
            } else {
                return [];
            }
        }
        if ( ! is_array( $value ) ) {
            return [];
        }
        $clean = [];
        foreach ( $value as $box ) {
            if ( ! is_array( $box ) ) {
                continue;
            }
            $name   = isset( $box['name'] ) ? sanitize_text_field( $box['name'] ) : '';
            $length = isset( $box['length'] ) ? floatval( $box['length'] ) : 0;
            $width  = isset( $box['width'] ) ? floatval( $box['width'] ) : 0;
            $height = isset( $box['height'] ) ? floatval( $box['height'] ) : 0;
            $weight = isset( $box['weight'] ) ? floatval( $box['weight'] ) : 0;
            // Skip completely empty rows.
            if ( $name === '' && $length == 0 && $width == 0 && $height == 0 && $weight == 0 ) {
                continue;
            }
            $clean[] = [
                'name'   => $name,
                'length' => $length,
                'width'  => $width,
                'height' => $height,
                'weight' => $weight,
            ];
        }
        return array_values( $clean );
    }


    public function sanitize_from_address( $value ) {
        if ( ! is_array( $value ) ) {
            return [];
        }
        $value  = wp_unslash( $value );
        $clean  = [];
        $fields = [ 'name', 'company', 'phone', 'email', 'line1', 'town', 'county', 'postcode', 'country_code' ];
        foreach ( $fields as $field ) {
            $clean[ $field ] = isset( $value[ $field ] ) ? sanitize_text_field( $value[ $field ] ) : '';
        }
        $clean['email']        = sanitize_email( $clean['email'] );
        $clean['country_code'] = strtoupper( $clean['country_code'] );
        return $clean;
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
    protected function from_address_problems() {
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

    /**
     * EasyPost key mode.
     *
     * One mode governs everything EasyPost does here — buying labels *and*
     * verifying addresses — because they share a key. There is deliberately no
     * separate verifier key: the selected key is the one used for both jobs.
     */
    public function render_easypost_mode_field() {
        $mode = $this->easypost_mode();
        ?>
        <fieldset>
            <label>
                <input type="radio" name="shipping_desk_easypost_mode" value="test" <?php checked( 'test', $mode ); ?> />
                <?php esc_html_e( 'Test', 'shipping-desk' ); ?>
            </label><br />
            <label>
                <input type="radio" name="shipping_desk_easypost_mode" value="live" <?php checked( 'live', $mode ); ?> />
                <?php esc_html_e( 'Production', 'shipping-desk' ); ?>
            </label>
            <p class="description">
                <?php esc_html_e( 'Test buys test labels with no money spent; Production spends real money. Both keys are kept, so switching back and forth does not lose either. The selected key is also the one used for EasyPost address validation.', 'shipping-desk' ); ?>
            </p>
        </fieldset>
        <?php
    }

    /**
     * EasyPost keys, one per mode.
     *
     * The key itself carries the mode (`EZTK…` test, `EZAK…` production), so the
     * slot is checked against the prefix rather than trusting the paste.
     */
    public function render_easypost_keys_field() {
        $test_key = (string) get_option( 'shipping_desk_easypost_test_api_key', '' );
        $live_key = (string) get_option( 'shipping_desk_easypost_live_api_key', '' );
        ?>
        <p>
            <label for="shipping_desk_easypost_test_api_key"><strong><?php esc_html_e( 'Test API key', 'shipping-desk' ); ?></strong></label><br />
            <input type="password" id="shipping_desk_easypost_test_api_key" name="shipping_desk_easypost_test_api_key"
                   value="<?php echo esc_attr( $test_key ); ?>" class="regular-text" autocomplete="off" />
        </p>
        <p>
            <label for="shipping_desk_easypost_live_api_key"><strong><?php esc_html_e( 'Production API key', 'shipping-desk' ); ?></strong></label><br />
            <input type="password" id="shipping_desk_easypost_live_api_key" name="shipping_desk_easypost_live_api_key"
                   value="<?php echo esc_attr( $live_key ); ?>" class="regular-text" autocomplete="off" />
        </p>
        <p class="description">
            <?php esc_html_e( 'Used for both EasyPost labels and address validation. Insurance is EasyPost\'s own and is priced at purchase, not in the quote.', 'shipping-desk' ); ?>
        </p>
        <?php
        $warning = $this->easypost_key_warning( $test_key, $live_key );
        if ( '' !== $warning ) {
            printf( '<div class="notice notice-warning inline"><p>%s</p></div>', esc_html( $warning ) );
        }
    }

    /**
     * Check the stored EasyPost keys against the slot they sit in.
     *
     * Test keys begin EZTK and production keys EZAK. Pasting the production key
     * into the test field is an easy way to spend real money, so the prefix is
     * verified rather than trusted.
     *
     * @param string $test_key Stored test key.
     * @param string $live_key Stored production key.
     * @return string Warning message, or ''.
     */
    protected function easypost_key_warning( $test_key, $live_key ) {
        $test_key = trim( (string) $test_key );
        $live_key = trim( (string) $live_key );

        if ( '' !== $test_key && 0 !== strpos( $test_key, 'EZTK' ) ) {
            return __( 'The Test API key does not start with "EZTK". Check which key was pasted.', 'shipping-desk' );
        }

        if ( '' !== $live_key && 0 !== strpos( $live_key, 'EZAK' ) ) {
            return __( 'The Production API key does not start with "EZAK". Check which key was pasted.', 'shipping-desk' );
        }

        if ( 'easypost' === $this->provider() && '' === $this->easypost_api_key() ) {
            return 'live' === $this->easypost_mode()
                ? __( 'EasyPost is the active provider but no Production API key is set, so rate and label requests will fail.', 'shipping-desk' )
                : __( 'EasyPost is the active provider but no Test API key is set, so rate and label requests will fail.', 'shipping-desk' );
        }

        return '';
    }

    /**
     * Copy the pre-0.9.69 single EasyPost key into the slot its prefix implies.
     *
     * That field was labelled "Address Validation" and had no mode; leaving it
     * unread would silently switch verification off for every existing install.
     * Runs once, never overwrites a slot that is already filled.
     */
    public function maybe_migrate_easypost_key() {
        if ( get_option( 'shipping_desk_easypost_key_migrated' ) ) {
            return;
        }

        $legacy = trim( (string) get_option( 'shipping_desk_easypost_api_key', '' ) );
        if ( '' !== $legacy ) {
            $slot = 0 === strpos( $legacy, 'EZAK' ) ? 'shipping_desk_easypost_live_api_key' : 'shipping_desk_easypost_test_api_key';
            if ( '' === trim( (string) get_option( $slot, '' ) ) ) {
                update_option( $slot, $legacy );
            }
            // The key decides the mode too, otherwise the pasted key would sit in
            // a slot the plugin never reads.
            if ( ! get_option( 'shipping_desk_easypost_mode' ) ) {
                update_option( 'shipping_desk_easypost_mode', 0 === strpos( $legacy, 'EZAK' ) ? 'live' : 'test' );
            }
        }

        update_option( 'shipping_desk_easypost_key_migrated', 1 );
    }

    /**
     * Keep an install that has never saved Settings on Veeqo after the default moved.
     *
     * `shipping_desk_provider` only exists once the Settings form has been saved, so
     * a store that has been buying Veeqo labels since before the option existed has
     * none. 0.9.69 changed the default to Shippo; without this such a store would
     * switch provider on upgrade, which is the one thing a default must never do.
     *
     * Shippo and EasyPost both require keys, and keys require saving the form, so any
     * stored label without the option is Veeqo's. Runs once.
     */
    public function maybe_default_legacy_provider() {
        if ( get_option( 'shipping_desk_provider_defaulted' ) ) {
            return;
        }
        update_option( 'shipping_desk_provider_defaulted', 1 );

        if ( false !== get_option( 'shipping_desk_provider', false ) ) {
            return;
        }
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return;
        }

        $shipped = wc_get_orders( [
            'limit'      => 1,
            'return'     => 'ids',
            'meta_query' => [
                [
                    'key'     => '_shipping_desk_remote_shipment_id',
                    'compare' => 'EXISTS',
                ],
            ],
        ] );

        if ( ! empty( $shipped ) ) {
            update_option( 'shipping_desk_provider', 'veeqo' );
        }
    }

    public function render_from_address_field() {
        $value    = get_option( 'shipping_desk_from_address', [] );
        $defaults = Shipping_Desk_Helpers::get_default_from_address();
        $fields   = [
            'name'         => __( 'Name', 'shipping-desk' ),
            'company'      => __( 'Company', 'shipping-desk' ),
            'phone'        => __( 'Phone', 'shipping-desk' ),
            'email'        => __( 'Email', 'shipping-desk' ),
            'line1'        => __( 'Address', 'shipping-desk' ),
            'town'         => __( 'City', 'shipping-desk' ),
            'county'       => __( 'State / Region', 'shipping-desk' ),
            'postcode'     => __( 'ZIP / Postcode', 'shipping-desk' ),
            'country_code' => __( 'Country Code', 'shipping-desk' ),
        ];
        ?>
        <p class="description"><?php esc_html_e( 'Optional. Leave any field blank to use the existing WooCommerce store value. Phone is expected by both carriers and USPS refuses a label without it; Email is required by USPS. Blanks fall back to the admin phone (or store phone) and the WordPress admin email.', 'shipping-desk' ); ?></p>
        <?php
        $missing = $this->from_address_problems();
        if ( ! empty( $missing ) ) {
            printf(
                '<div class="notice notice-warning inline"><p>%s</p></div>',
                esc_html( sprintf(
                    /* translators: %s: list of missing fields, e.g. "phone number / email address" */
                    __( 'No %s is available from either this form or the store settings. Carriers expect a phone number and USPS requires both, so label purchases will fail until one is set here.', 'shipping-desk' ),
                    implode( ' / ', $missing )
                ) )
            );
        }
        ?>
        <?php foreach ( $fields as $key => $label ) :
            $field_id    = 'shipping-desk-from-' . $key;
            $field_value = is_array( $value ) && isset( $value[ $key ] ) ? $value[ $key ] : '';
            $placeholder = $defaults[ $key ] ?? '';
            $class       = $key === 'country_code' ? 'small-text' : 'regular-text';
            ?>
            <p>
                <label for="<?php echo esc_attr( $field_id ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label><br />
                <input type="text" id="<?php echo esc_attr( $field_id ); ?>" name="shipping_desk_from_address[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $field_value ); ?>" class="<?php echo esc_attr( $class ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" />
            </p>
        <?php endforeach; ?>
        <?php
    }

    public function render_api_key_field() {
        $api_key = get_option( 'shipping_desk_veeqo_api_key', '' );
        printf(
            '<input type="password" name="shipping_desk_api_key" value="%s" class="regular-text" /><p class="description">%s</p>',
            esc_attr( $api_key ),
            esc_html__( 'Stored in wp_options; used for all Veeqo API calls.', 'shipping-desk' )
        );
    }

    public function render_provider_field() {
        $provider = $this->provider();
        ?>
        <fieldset>
            <label>
                <input type="radio" name="shipping_desk_provider" value="veeqo" <?php checked( 'veeqo', $provider ); ?> />
                <?php esc_html_e( 'Veeqo', 'shipping-desk' ); ?>
            </label><br />
            <label>
                <input type="radio" name="shipping_desk_provider" value="shippo" <?php checked( 'shippo', $provider ); ?> />
                <?php esc_html_e( 'Shippo', 'shipping-desk' ); ?>
            </label><br />
            <label>
                <input type="radio" name="shipping_desk_provider" value="easypost" <?php checked( 'easypost', $provider ); ?> />
                <?php esc_html_e( 'EasyPost', 'shipping-desk' ); ?>
            </label>
            <p class="description">
                <?php esc_html_e( 'Used for new rate requests and new purchases. Switching back is one radio button; labels already bought keep the provider that sold them.', 'shipping-desk' ); ?>
            </p>
        </fieldset>
        <?php
    }

    public function render_shippo_mode_field() {
        $mode = $this->shippo_mode();
        ?>
        <fieldset>
            <label>
                <input type="radio" name="shipping_desk_shippo_mode" value="test" <?php checked( 'test', $mode ); ?> />
                <?php esc_html_e( 'Test', 'shipping-desk' ); ?>
            </label><br />
            <label>
                <input type="radio" name="shipping_desk_shippo_mode" value="live" <?php checked( 'live', $mode ); ?> />
                <?php esc_html_e( 'Production', 'shipping-desk' ); ?>
            </label>
            <p class="description">
                <?php esc_html_e( 'Test buys test labels with no money spent; Production spends real money. Both keys are kept, so switching back and forth does not lose either.', 'shipping-desk' ); ?>
            </p>
        </fieldset>
        <?php
    }

    public function render_shippo_keys_field() {
        $test_key = (string) get_option( 'shipping_desk_shippo_test_api_key', '' );
        $live_key = (string) get_option( 'shipping_desk_shippo_live_api_key', '' );
        ?>
        <p>
            <label for="shipping_desk_shippo_test_api_key"><strong><?php esc_html_e( 'Test API key', 'shipping-desk' ); ?></strong></label><br />
            <input type="password" id="shipping_desk_shippo_test_api_key" name="shipping_desk_shippo_test_api_key"
                   value="<?php echo esc_attr( $test_key ); ?>" class="regular-text" autocomplete="off" />
        </p>
        <p>
            <label for="shipping_desk_shippo_live_api_key"><strong><?php esc_html_e( 'Production API key', 'shipping-desk' ); ?></strong></label><br />
            <input type="password" id="shipping_desk_shippo_live_api_key" name="shipping_desk_shippo_live_api_key"
                   value="<?php echo esc_attr( $live_key ); ?>" class="regular-text" autocomplete="off" />
        </p>
        <?php
        $warning = $this->shippo_key_warning( $test_key, $live_key );
        if ( '' !== $warning ) {
            printf( '<div class="notice notice-warning inline"><p>%s</p></div>', esc_html( $warning ) );
        }
    }

    /**
     * Check the stored Shippo keys against the slot they sit in.
     *
     * Pasting the live key into the test field is an easy way to spend real
     * money, so the prefix is verified rather than trusted.
     *
     * @param string $test_key Stored test key.
     * @param string $live_key Stored production key.
     * @return string Warning message, or '' when everything looks right.
     */
    protected function shippo_key_warning( $test_key, $live_key ) {
        $test_key = trim( (string) $test_key );
        $live_key = trim( (string) $live_key );

        if ( '' !== $test_key && 0 !== strpos( $test_key, 'shippo_test_' ) ) {
            return __( 'The Test API key does not start with "shippo_test_". Check which key was pasted.', 'shipping-desk' );
        }

        if ( '' !== $live_key && 0 !== strpos( $live_key, 'shippo_live_' ) ) {
            return __( 'The Production API key does not start with "shippo_live_". Check which key was pasted.', 'shipping-desk' );
        }

        if ( 'shippo' === $this->provider() && '' === $this->shippo_api_key() ) {
            return 'live' === $this->shippo_mode()
                ? __( 'Shippo is the active provider but no Production API key is set, so rate and label requests will fail.', 'shipping-desk' )
                : __( 'Shippo is the active provider but no Test API key is set, so rate and label requests will fail.', 'shipping-desk' );
        }

        return '';
    }

    /* -- PrintNode Settings -- */

    public function render_printnode_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'shipping-desk' ) );
        }
        $api_key     = get_option( 'shipping_desk_printnode_api_key', '' );
        $printer_id  = (int) get_option( 'shipping_desk_printnode_printer_id', 0 );
        $print_mode  = get_option( 'shipping_desk_print_mode', 'browser' );

        ?>
        <div class="wrap shipping-desk-settings">
            <h1><?php esc_html_e( 'PrintNode Settings', 'shipping-desk' ); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'shipping_desk_printnode_settings' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="veeqo_ship_pn_key"><?php esc_html_e( 'PrintNode API Key', 'shipping-desk' ); ?></label></th>
                        <td><input type="text" id="veeqo_ship_pn_key" name="shipping_desk_printnode_api_key" class="regular-text" value="<?php echo esc_attr( $api_key ); ?>" /></td>
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
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <script>
        (function ($) {
            'use strict';
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

    /* -- /PrintNode Settings -- */

    public function enqueue_assets( $hook ) {
        if ( strpos( (string) $hook, 'shipping-desk' ) === false ) {
            return;
        }
        wp_enqueue_style( 'shipping-desk-admin', $this->url . 'admin/css/admin.css', [], '1.0.1' );
        wp_enqueue_script( 'shipping-desk-admin', $this->url . 'admin/js/admin.js', [ 'jquery' ], '1.0.1', true );
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
    protected function normalize_provider( $provider, $fallback = 'veeqo' ) {
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
    protected function provider_mode( $provider ) {
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
    protected function api( $provider = null, $mode = null ) {
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

    protected function require_cap() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'shipping-desk' ) ], 403 );
            exit;
        }
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
    protected function render_provider_switch() {
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
            <span class="shipping-desk-provider-msg" role="status" aria-live="polite"></span>
        </div>
        <?php
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
                <?php $this->render_provider_switch(); ?>
            </div>
            <?php if ( in_array( $this->provider(), [ 'shippo', 'easypost' ], true ) && 'test' === $this->provider_mode( $this->provider() ) ) : ?>
                <div class="notice notice-warning inline">
                    <p>
                        <?php
                        printf(
                            /* translators: %s: provider name, e.g. EasyPost */
                            esc_html__( '%s is in TEST mode: rates and labels come from your test account and no money is spent. Switch to Production under Settings when you are ready to buy real labels.', 'shipping-desk' ),
                            esc_html( 'easypost' === $this->provider() ? 'EasyPost' : 'Shippo' )
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>
            <?php $missing_from = $this->from_address_problems(); ?>
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

        // Services that are priced into the rate. Shippo and EasyPost both price
        // signature confirmation at quote time (EasyPost through shipment
        // options), so both get the dropdown; Veeqo prices VAS after the quotes.
        $provider          = $this->provider();
        $quotes_signature  = in_array( $provider, [ 'shippo', 'easypost' ], true );
        $insurance_title   = 'easypost' === $provider
            ? __( 'Declared value for EasyPost insurance. EasyPost charges 1% of this with a $1.00 minimum (maximum $5,000), prices it at purchase, and adds it to the recorded label cost rather than to the quotes.', 'shipping-desk' )
            : __( 'Declared value for insurance. Set before getting rates.', 'shipping-desk' );

        // Quotes stored by the last Get Rates, when they are still usable, so a reload
        // or another machine shows the same prices without paying for them again.
        $quote_record = $this->fresh_quote_record( $order_id );
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
                <tr data-order-id="<?php echo esc_attr( $order_id ); ?>" data-nonce="<?php echo esc_attr( $order_nonce ); ?>" data-items-weight="<?php echo esc_attr( number_format( $items_weight, 2, '.', '' ) ); ?>"<?php if ( '' !== $quote_data ) : ?> data-quotes="<?php echo esc_attr( $quote_data ); ?>"<?php endif; ?>>
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
                        </select>
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
                        </div>
                    </td>
                    <td class="shipping-desk-rates">
                        <div class="shipping-desk-rates-container"><em><?php esc_html_e( 'Rates not loaded. Select a box and choose Get Rates.', 'shipping-desk' ); ?></em></div>
                    </td>
                    <td>
                        <button type="button" class="shipping-desk-get-rates"><?php esc_html_e( 'Get Rates', 'shipping-desk' ); ?></button>
                        <button type="button" class="shipping-desk-buy-label" disabled><?php esc_html_e( 'Buy Label', 'shipping-desk' ); ?></button>
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


    public function render_lookup_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'shipping-desk' ) );
        }
        $shipment_id = '';
        $result      = null;
        $error       = '';
        $lookup_kind = 'shipment';
        $provider    = $this->provider();
        $mode        = $this->provider_mode( $provider );

        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['shipping_desk_lookup_nonce'] ) ) {
            if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['shipping_desk_lookup_nonce'] ) ), 'shipping_desk_lookup' ) ) {
                $error = __( 'Invalid nonce.', 'shipping-desk' );
            } else {
                $shipment_id = sanitize_text_field( wp_unslash( $_POST['shipment_id'] ?? '' ) );
                if ( $shipment_id === '' ) {
                    $error = __( 'Please enter a shipment ID.', 'shipping-desk' );
                } else {
                    $client = $this->api();

                    if ( 'shippo' === $provider && method_exists( $client, 'get_transaction' ) ) {
                        // The transaction id is the one a human can actually find (order
                        // note, Label ID column), so query it directly instead of
                        // probing /shipments first and paying for a 404.
                        $result      = $client->get_transaction( $shipment_id );
                        $lookup_kind = 'transaction';

                        // A shipment id can still be pasted — it is what the Shippo
                        // dashboard shows — so fall back rather than dead-ending. Costs
                        // nothing on the normal path.
                        if ( is_wp_error( $result ) && 'shippo_not_found' === $result->get_error_code() ) {
                            $as_shipment = $client->get_shipment( $shipment_id );
                            if ( ! is_wp_error( $as_shipment ) ) {
                                $result      = $as_shipment;
                                $lookup_kind = 'shipment';
                            }
                        }
                    } else {
                        $result      = $client->get_shipment( $shipment_id );
                        $lookup_kind = 'shipment';
                    }

                    if ( is_wp_error( $result ) ) {
                        $error = $result->get_error_message();
                        if ( 'shippo' === $provider && 'shippo_not_found' === $result->get_error_code() ) {
                            $error = __( 'No transaction or shipment with that id on the Shippo account and mode shown above.', 'shipping-desk' );
                        }
                        $result = null;
                    }
                }
            }
        }
        ?>
        <div class="wrap shipping-desk-wrap">
            <h1><?php esc_html_e( 'Shipment Lookup', 'shipping-desk' ); ?></h1>
            <p>
                <?php
                if ( 'shippo' === $provider ) {
                    esc_html_e( 'Enter the Shippo transaction id from the order note. A shipment id also works if you have one.', 'shipping-desk' );
                    printf(
                        ' <strong>%s</strong>',
                        esc_html( sprintf(
                            /* translators: %s: Shippo key mode */
                            __( 'Looking up on Shippo (%s).', 'shipping-desk' ),
                            'live' === $mode ? __( 'production', 'shipping-desk' ) : __( 'test', 'shipping-desk' )
                        ) )
                    );
                } elseif ( 'easypost' === $provider ) {
                    esc_html_e( 'Enter the EasyPost shipment id from the order note.', 'shipping-desk' );
                    printf(
                        ' <strong>%s</strong>',
                        esc_html( sprintf(
                            /* translators: %s: EasyPost key mode */
                            __( 'Looking up on EasyPost (%s).', 'shipping-desk' ),
                            'live' === $mode ? __( 'production', 'shipping-desk' ) : __( 'test', 'shipping-desk' )
                        ) )
                    );
                } else {
                    esc_html_e( 'Enter the Veeqo remote shipment id recorded on the order.', 'shipping-desk' );
                }
                ?>
            </p>
            <form method="post">
                <?php wp_nonce_field( 'shipping_desk_lookup', 'shipping_desk_lookup_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="shipment_id"><?php esc_html_e( 'Shipment ID', 'shipping-desk' ); ?></label></th>
                        <td><input type="text" id="shipment_id" name="shipment_id" value="<?php echo esc_attr( $shipment_id ); ?>" class="regular-text" /></td>
                    </tr>
                </table>
                <?php submit_button( __( 'Look up', 'shipping-desk' ) ); ?>
            </form>

            <?php if ( $error ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
            <?php endif; ?>

            <?php if ( is_array( $result ) ) :
                $lookup_order_id     = 0;
                $lookup_action_nonce = wp_create_nonce( 'shipping_desk_lookup' );
                $cancelled_at        = (string) ( $result['cancelled_at'] ?? '' );
                $m                   = Shipping_Desk_Helpers::meta_keys();

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

                // The order may hold this id as the parent shipment or as the label
                // itself, depending on the provider, so check both.
                foreach ( [ $m['remote_id'], $m['label_id'] ] as $lookup_meta_key ) {
                    if ( $lookup_order_id ) {
                        break;
                    }
                    $lookup_orders = wc_get_orders( [
                        'limit'      => 1,
                        'type'       => 'shop_order',
                        'meta_key'   => $lookup_meta_key,
                        'meta_value' => $shipment_id,
                    ] );
                    if ( ! empty( $lookup_orders ) ) {
                        $lookup_order = reset( $lookup_orders );
                        if ( $lookup_order ) {
                            $lookup_order_id = $lookup_order->get_id();
                        }
                    }
                }
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
                submit_button();
                ?>
            </form>

            <hr />

            <h2><?php esc_html_e( 'Backup / Restore Settings', 'shipping-desk' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Export all plugin settings (API keys, box sizes, origin address and print mode) to a JSON file. Import restores them after a reinstall or on another site.', 'shipping-desk' ); ?>
            </p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Export', 'shipping-desk' ); ?></th>
                    <td>
                        <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=shipping_desk_export_settings' ), 'shipping_desk_export_settings' ) ); ?>">
                            <?php esc_html_e( 'Download JSON', 'shipping-desk' ); ?>
                        </a>
                        <p class="description"><?php esc_html_e( 'The file contains secrets (API keys), so store it somewhere safe.', 'shipping-desk' ); ?></p>
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

            <?php $this->render_import_notices(); ?>

            <hr />

            <h2><?php esc_html_e( 'Reship Notifications', 'shipping-desk' ); ?></h2>
            <form method="post" action="options.php">
                <?php settings_fields( 'shipping_desk_api_settings' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Completed email on reship', 'shipping-desk' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="shipping_desk_reship_send_completed_email"
                                       value="1"
                                       <?php checked( 1, (int) get_option( 'shipping_desk_reship_send_completed_email', 0 ) ); ?> />
                                <?php esc_html_e( 'Send the WooCommerce "Completed order" email when a completed order is reshipped.', 'shipping-desk' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'Left unchecked (recommended). Shipment tracking plugins such as Advanced Shipment Tracking already email the customer when they detect new tracking, so ticking this as well sends the email twice. Tick it only if nothing else notifies your customers.', 'shipping-desk' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr />

            <h2><?php esc_html_e( 'Uninstall Behaviour', 'shipping-desk' ); ?></h2>
            <form method="post" action="options.php">
                <?php settings_fields( 'shipping_desk_api_settings' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Remove data on uninstall', 'shipping-desk' ); ?></th>
                        <td>
                            <label>
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

    /**
     * Show the result of an import, or a notice that legacy settings were
     * carried over from the pre-refactor plugin.
     */
    protected function render_import_notices() {
        $result = isset( $_GET['shipping_desk_import'] ) ? sanitize_key( wp_unslash( $_GET['shipping_desk_import'] ) ) : '';

        if ( 'ok' === $result ) {
            $imported = get_transient( 'shipping_desk_import_notice' );
            delete_transient( 'shipping_desk_import_notice' );
            $count = is_array( $imported ) ? count( $imported ) : 0;
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html( sprintf( _n( 'Settings imported (%d item).', 'Settings imported (%d items).', $count, 'shipping-desk' ), $count ) )
            );
        } elseif ( 'invalid' === $result ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'That file is not a valid settings export.', 'shipping-desk' ) . '</p></div>';
        } elseif ( 'nofile' === $result ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'No file was uploaded.', 'shipping-desk' ) . '</p></div>';
        }

        $migrated = get_transient( 'shipping_desk_migration_notice' );
        if ( $migrated ) {
            delete_transient( 'shipping_desk_migration_notice' );
            printf(
                '<div class="notice notice-info is-dismissible"><p>%s</p></div>',
                esc_html__( 'Settings from the previous plugin version were detected and carried over automatically. Please review them below.', 'shipping-desk' )
            );
        }
    }


    public function shipping_desk_get_rates() {
        $this->require_cap();
        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        $nonce    = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'shipping_desk_order_' . $order_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid nonce.', 'shipping-desk' ) ], 400 );
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => __( 'Order not found.', 'shipping-desk' ) ], 404 );
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

        $validator = $this->address_validator( $client );

        if ( null !== $validator ) {
            list( $validator_name, $validator_client ) = $validator;
            $to_address = isset( $payload['to_address'] ) && is_array( $payload['to_address'] ) ? $payload['to_address'] : [];
            $postcode   = isset( $to_address['postcode'] ) ? $to_address['postcode'] : '';

            // Only addresses the checkout never verified: a ZIP+4 comes back from
            // validation, so its presence means this order has already been through
            // it. Everything else would be a paid call for a known-good address.
            if ( ! empty( $to_address ) && Shipping_Desk_Helpers::needs_address_validation( $postcode ) ) {
                $address_validation = $this->validate_recipient_address( $order, $to_address, $validator_client );
                $address_validation = $this->describe_validation( $address_validation, $validator_name );
                $needs_choice       = in_array( $address_validation['status'], [ 'corrected', 'invalid' ], true );

                if ( $needs_choice && in_array( $choice, [ 'suggested', 'original' ], true ) ) {
                    $this->apply_address_choice( $payload, $address_validation, $choice );
                    $this->store_address_decision( $order, $address_validation, $address_validation['applied'], $wants_order_update );
                } elseif ( $needs_choice ) {
                    // Persist the pending decision too, so a reload or another browser
                    // still shows the panel (and the row keeps its flag in a bulk run).
                    $this->store_quote_record( $order_id, [
                        'fingerprint'        => Shipping_Desk_Helpers::rate_inputs_fingerprint( $payload, $provider, $this->provider_mode( $provider ) ),
                        'created_at'         => time(),
                        'dispatch_day'       => $this->current_dispatch_day( $provider ),
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
                // Skipped because the address already carries a ZIP+4. Reported
                // rather than silent: otherwise an intentional skip and a feature
                // that is not running look exactly the same on screen.
                $address_validation = [
                    'status'        => 'skipped',
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

        $this->store_quote_record( $order_id, [
            'fingerprint'         => Shipping_Desk_Helpers::rate_inputs_fingerprint( $payload, $provider, $this->provider_mode( $provider ) ),
            'created_at'          => time(),
            'provider_expires_at' => $provider_expiry,
            'dispatch_day'        => $this->current_dispatch_day( $provider ),
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
     * Validate a recipient address, reusing a recent answer.
     *
     * The result is cached per order and address: Get Rates is clicked again after
     * every box, signature and insurance change, and validation is billed per call.
     * An outage is deliberately not cached — a validator that was down for one
     * click should not leave the address unchecked for twelve hours.
     *
     * @param WC_Order $order   Order being quoted.
     * @param array    $address Recipient address from the payload.
     * @param object   $client  Provider client implementing the validator contract.
     * @return array Normalised result plus the original address.
     */
    protected function validate_recipient_address( WC_Order $order, array $address, $client ) {
        $m    = Shipping_Desk_Helpers::meta_keys();
        $hash = md5( implode( '|', [
            strtolower( trim( (string) ( isset( $address['line1'] ) ? $address['line1'] : '' ) ) ),
            strtolower( trim( (string) ( isset( $address['line2'] ) ? $address['line2'] : '' ) ) ),
            strtolower( trim( (string) ( isset( $address['town'] ) ? $address['town'] : '' ) ) ),
            strtolower( trim( (string) ( isset( $address['county'] ) ? $address['county'] : '' ) ) ),
            strtolower( trim( (string) ( isset( $address['postcode'] ) ? $address['postcode'] : '' ) ) ),
        ] ) );
        $key = 'shipping_desk_addr_' . (int) $order->get_id() . '_' . $hash;

        // Already verified as clean at this exact address: a valid address never
        // gains a ZIP+4, so the "no +4" trigger would otherwise keep paying for the
        // same answer every day the order sits unshipped. Only clean verdicts are
        // remembered — a correction the operator declined should be able to appear
        // again rather than being suppressed for good.
        $verdict = $order->get_meta( $m['address_checked'] );
        if ( is_array( $verdict )
            && isset( $verdict['hash'], $verdict['status'] )
            && $verdict['hash'] === $hash
            && 'valid' === $verdict['status'] ) {
            return [
                'status'       => 'valid',
                'is_valid'     => true,
                'recommended'  => [],
                'changed'      => [],
                'reasons'      => [],
                'address_type' => isset( $verdict['address_type'] ) ? (string) $verdict['address_type'] : '',
                'confidence'   => [],
                'error'        => '',
                'original'     => $address,
                'remembered'   => true,
            ];
        }

        $cached = get_transient( $key );
        if ( is_array( $cached ) && isset( $cached['status'] ) ) {
            return $cached;
        }

        $result             = $client->validate_address( $address );
        $result['original'] = $address;

        if ( 'unavailable' !== ( isset( $result['status'] ) ? $result['status'] : '' ) ) {
            set_transient( $key, $result, self::ADDRESS_VALIDATION_TTL );
        }

        // Clean verdict: remember it against this address so the next quote is free.
        if ( 'valid' === ( isset( $result['status'] ) ? $result['status'] : '' ) ) {
            $order->update_meta_data( $m['address_checked'], [
                'hash'         => $hash,
                'status'       => 'valid',
                'address_type' => isset( $result['address_type'] ) ? (string) $result['address_type'] : '',
            ] );
            $order->save();
        }

        return $result;
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
        $changed     = isset( $validation['changed'] ) && is_array( $validation['changed'] ) ? $validation['changed'] : [];
        $original    = isset( $validation['original'] ) && is_array( $validation['original'] ) ? $validation['original'] : [];
        $recommended = isset( $validation['recommended'] ) && is_array( $validation['recommended'] ) ? $validation['recommended'] : [];

        $validation['summary']          = implode( ', ', Shipping_Desk_Helpers::describe_address_changes( $changed ) );
        $validation['original_line']    = Shipping_Desk_Helpers::format_address( $original );
        $validation['recommended_line'] = Shipping_Desk_Helpers::format_address( $recommended );

        // Which verifier answered. Two can be configured, and "test mode has no
        // validator" and "the address is clean" would otherwise look identical.
        $validation['validator'] = 'easypost' === $source ? 'easypost' : 'shippo';

        return $validation;
    }

    /**
     * The address validator to use, with a name for display, or null for none.
     *
     * EasyPost wins when the selected EasyPost key is set: it is independent of the
     * label provider and returns the standardised address, which the provider's own
     * validator does not always do. One mode and one key govern EasyPost here, so
     * the verifier uses exactly the key the operator selected — there is no separate
     * verification key and no cross-slot fallback. Otherwise the provider validates
     * itself if it can — Shippo can, Veeqo cannot.
     *
     * @param object $provider_client Client for the active label provider.
     * @return array|null [ name, validator client ].
     */
    protected function address_validator( $provider_client ) {
        $key = $this->easypost_api_key();
        if ( '' !== $key && class_exists( 'Shipping_Desk_EasyPost_Client' ) ) {
            return [ 'easypost', new Shipping_Desk_EasyPost_Client( $key ) ];
        }

        if ( $provider_client instanceof Shipping_Desk_Address_Validator_Interface ) {
            return [ 'shippo', $provider_client ];
        }

        return null;
    }

    /**
     * Transient key holding the last set of quotes for an order.
     *
     * @param int $order_id Order ID.
     * @return string
     */
    protected function quote_record_key( $order_id ) {
        return 'shipping_desk_quotes_' . (int) $order_id;
    }

    /**
     * The stored quotes for an order, or [] when there are none.
     *
     * @param int $order_id Order ID.
     * @return array
     */
    protected function get_quote_record( $order_id ) {
        $record = get_transient( $this->quote_record_key( $order_id ) );
        return is_array( $record ) ? $record : [];
    }

    /**
     * The stored quotes, but only while they are still usable.
     *
     * @param int         $order_id Order ID.
     * @param string|null $provider Provider to judge the dispatch day by.
     * @return array Empty when missing, expired or priced for another dispatch day.
     */
    protected function fresh_quote_record( $order_id, $provider = null ) {
        $record = $this->get_quote_record( $order_id );
        if ( empty( $record ) ) {
            return [];
        }

        return Shipping_Desk_Helpers::quote_record_is_fresh( $record, $this->current_dispatch_day( $provider ) )
            ? $record
            : [];
    }

    /**
     * Drop the stored quotes for an order.
     *
     * @param int $order_id Order ID.
     * @return void
     */
    protected function delete_quote_record( $order_id ) {
        delete_transient( $this->quote_record_key( $order_id ) );
    }

    /**
     * The dispatch day a quote taken right now would carry.
     *
     * Shippo bakes the date into the shipment at quote time and USPS prints it;
     * EasyPost carries it as `options.label_date`. Either way a quote priced for
     * one dispatch day must not be bought on another. Veeqo has no equivalent, so
     * it answers '' and only the carrier expiry and the cap apply.
     *
     * @param string|null $provider Provider to ask.
     * @return string `Y-m-d`, or ''.
     */
    protected function current_dispatch_day( $provider = null ) {
        $provider = null === $provider ? $this->provider() : $provider;
        if ( ! in_array( $provider, [ 'shippo', 'easypost' ], true ) ) {
            return '';
        }

        $client = $this->api( $provider );
        return method_exists( $client, 'dispatch_day' ) ? (string) $client->dispatch_day() : '';
    }

    /**
     * Persist a set of quotes so a reload, another browser or an order arriving
     * mid-batch does not throw the work away.
     *
     * Stored as an expiring transient rather than order meta: it is a cache with a
     * lifetime measured in hours, not a fact about the order.
     *
     * @param int   $order_id Order ID.
     * @param array $record   Record; `provider_expires_at` is folded into `expires_at`.
     * @return void
     */
    protected function store_quote_record( $order_id, array $record ) {
        $now = time();

        $record['expires_at'] = Shipping_Desk_Helpers::quote_record_expiry(
            $now,
            isset( $record['provider_expires_at'] ) ? $record['provider_expires_at'] : null,
            self::QUOTE_RECORD_CAP_HOURS
        );
        unset( $record['provider_expires_at'] );

        $ttl = max( 60, (int) $record['expires_at'] - $now );
        set_transient( $this->quote_record_key( $order_id ), $record, $ttl );
    }

    /**
     * BrikPanel's shipping-cost meta key, or '' when that plugin is not installed.
     *
     * Read from their constant rather than hardcoded: no configuration here, and no
     * chance of writing a key that nothing reads.
     *
     * @return string
     */
    protected function brikpanel_cost_meta() {
        return defined( 'BRIKPANEL_SHIPPING_COST_META' ) ? (string) BRIKPANEL_SHIPPING_COST_META : '';
    }

    /**
     * Record what the label cost, so BrikPanel can use it for profit.
     *
     * Optional by design: with no BrikPanel installed nothing is attempted, written
     * or called. The value is overwritten on every purchase — the carrier's figure is
     * the actual cost, and a reship legitimately costs something new — and a test
     * label writes nothing, because a test label costs nothing.
     *
     * @param WC_Order $order    Order being shipped.
     * @param string   $provider Provider that sold the label.
     * @param string   $mode     Key mode: 'test' or 'live' ('' for Veeqo).
     * @param array    $shipment Bought shipment.
     * @return void
     */
    protected function record_shipping_cost( WC_Order $order, $provider, $mode, array $shipment ) {
        $key = $this->brikpanel_cost_meta();
        if ( '' === $key ) {
            return;
        }
        if ( function_exists( 'brikpanel_order_shipping_cost_active' ) && ! brikpanel_order_shipping_cost_active() ) {
            return;
        }
        if ( 'test' === $mode ) {
            return;
        }
        if ( ! function_exists( 'wc_format_decimal' ) ) {
            return;
        }

        $cost = Shipping_Desk_Helpers::label_cost( $provider, $shipment );
        if ( empty( $cost ) ) {
            return;
        }

        $decimals  = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
        $formatted = wc_format_decimal( $cost['amount'], $decimals );
        $before    = (string) $order->get_meta( $key );
        $m         = Shipping_Desk_Helpers::meta_keys();

        $order->update_meta_data( $key, $formatted );
        $order->update_meta_data( $m['cost_paid'], [
            'amount'   => $formatted,
            'currency' => $cost['currency'],
            'provider' => $provider,
            'at'       => time(),
        ] );
        $order->save();

        // BrikPanel caches its dashboard payload against order EVENTS, and a label
        // purchase is not one, so without this the new cost would not show until the
        // transient expired. Only on a real change, exactly as their own save does.
        if ( $before !== $formatted && function_exists( 'brikpanel_bust_data_caches' ) ) {
            brikpanel_bust_data_caches();
        }
    }

    /**
     * Remove the recorded cost after a refund.
     *
     * BrikPanel reads an empty value as "use what was charged", which is the right
     * answer once the carrier has given the money back.
     *
     * @param WC_Order $order Order whose label was refunded.
     * @return void
     */
    protected function clear_shipping_cost( WC_Order $order ) {
        $key = $this->brikpanel_cost_meta();
        if ( '' === $key ) {
            return;
        }

        $m        = Shipping_Desk_Helpers::meta_keys();
        $recorded = $order->get_meta( $m['cost_paid'] );
        $had_cost = '' !== (string) $order->get_meta( $key );

        if ( ! $had_cost && ! is_array( $recorded ) ) {
            return;
        }

        $order->delete_meta_data( $key );
        $order->delete_meta_data( $m['cost_paid'] );
        $order->save();

        if ( function_exists( 'brikpanel_bust_data_caches' ) ) {
            brikpanel_bust_data_caches();
        }
    }

    /**
     * Put the chosen address into the rate payload.
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

        foreach ( [ 'line1', 'line2', 'town', 'county', 'postcode', 'country_code' ] as $field ) {
            if ( ! empty( $recommended[ $field ] ) ) {
                $payload['to_address'][ $field ] = $recommended[ $field ];
            }
        }

        $validation['applied'] = 'suggested';
    }

    /**
     * Remember the decision so the purchase can act on it.
     *
     * Quoting is not buying: nothing is written to the order's address here, only
     * the intent, so an abandoned quote leaves no trace. Choosing the original
     * address clears any earlier decision.
     *
     * @param WC_Order $order         Order being quoted.
     * @param array    $validation    Normalised validation result.
     * @param string   $applied       'suggested' or 'original'.
     * @param bool     $update_order  Whether the operator asked to correct the order.
     * @return void
     */
    protected function store_address_decision( WC_Order $order, array $validation, $applied, $update_order ) {
        $m = Shipping_Desk_Helpers::meta_keys();

        if ( 'suggested' !== $applied ) {
            if ( '' !== (string) $order->get_meta( $m['address_decision'] ) ) {
                $order->delete_meta_data( $m['address_decision'] );
                $order->save();
            }
            return;
        }

        $order->update_meta_data( $m['address_decision'], [
            'original'     => isset( $validation['original'] ) && is_array( $validation['original'] ) ? $validation['original'] : [],
            'recommended'  => isset( $validation['recommended'] ) && is_array( $validation['recommended'] ) ? $validation['recommended'] : [],
            'changed'      => isset( $validation['changed'] ) && is_array( $validation['changed'] ) ? $validation['changed'] : [],
            'update_order' => (bool) $update_order,
        ] );
        $order->save();
    }

    /**
     * Act on the address decision at purchase time.
     *
     * Must be called before the order is saved, so a requested correction lands in
     * the same write as the label meta. Returns the order note to add afterwards,
     * or '' when the label was bought with the address as entered.
     *
     * Only the *shipping* address is ever touched: the billing address is the one
     * the customer entered and the one the payment gateway checked, so rewriting it
     * would break the AVS trail for no benefit.
     *
     * @param WC_Order $order Order being shipped.
     * @return string Note text, or ''.
     */
    protected function apply_address_decision( WC_Order $order ) {
        $m        = Shipping_Desk_Helpers::meta_keys();
        $decision = $order->get_meta( $m['address_decision'] );

        if ( ! is_array( $decision ) || empty( $decision['recommended'] ) ) {
            return '';
        }

        $recommended = $decision['recommended'];
        $original    = isset( $decision['original'] ) && is_array( $decision['original'] ) ? $decision['original'] : [];
        $changed     = isset( $decision['changed'] ) && is_array( $decision['changed'] ) ? $decision['changed'] : [];

        $summary = implode( ', ', Shipping_Desk_Helpers::describe_address_changes( $changed ) );
        if ( '' === $summary ) {
            $summary = __( 'standardised', 'shipping-desk' );
        }

        $updated = false;
        if ( ! empty( $decision['update_order'] ) ) {
            if ( ! empty( $recommended['line1'] ) ) {
                $order->set_shipping_address_1( $recommended['line1'] );
            }
            $order->set_shipping_address_2( isset( $recommended['line2'] ) ? $recommended['line2'] : '' );
            if ( ! empty( $recommended['town'] ) ) {
                $order->set_shipping_city( $recommended['town'] );
            }
            if ( ! empty( $recommended['county'] ) ) {
                $order->set_shipping_state( $recommended['county'] );
            }
            if ( ! empty( $recommended['postcode'] ) ) {
                $order->set_shipping_postcode( $recommended['postcode'] );
            }
            $updated = true;
        }

        $before = Shipping_Desk_Helpers::format_address( $original );
        $after  = Shipping_Desk_Helpers::format_address( $recommended );

        if ( $updated ) {
            return sprintf(
                /* translators: 1: previous address, 2: verified address, 3: what changed */
                __( 'Shipping address updated from Shippo verification: %1$s → %2$s (%3$s). The label uses the verified address.', 'shipping-desk' ),
                $before,
                $after,
                $summary
            );
        }

        return sprintf(
            /* translators: 1: verified address, 2: what changed */
            __( 'Address verified with Shippo: %1$s (%2$s). The label uses the verified address; the saved order address was left as entered.', 'shipping-desk' ),
            $after,
            $summary
        );
    }


    /**
     * Rate object supplied by the client: either an array or a JSON string.
     *
     * The rate is where the carrier, service and price live — a Shippo
     * transaction only returns a rate ID — and it is sent as JSON because nested
     * POST arrays are rejected by some WAF rules before PHP runs.
     *
     * @param mixed $value Raw request value.
     * @return array
     */
    protected function parse_rate_input( $value ) {
        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            return is_array( $decoded ) ? $decoded : [];
        }
        return is_array( $value ) ? $value : [];
    }

    /**
     * Turn a fatal error into a JSON response.
     *
     * A label purchase can outlive the PHP execution limit. Without this the
     * browser only sees a failed request and reports "please try again", while a
     * label may already exist in Shippo — the one case where retrying can
     * double-charge. At least now the operator gets a statement of what happened.
     */
    protected function guard_fatal_errors() {
        static $registered = false;
        if ( $registered ) {
            return;
        }
        $registered = true;

        register_shutdown_function( function () {
            $error = error_get_last();
            if ( ! is_array( $error ) ) {
                return;
            }
            $fatal = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ];
            if ( ! in_array( (int) $error['type'], $fatal, true ) || headers_sent() ) {
                return;
            }
            wp_send_json_error(
                [
                    'message' => sprintf(
                        /* translators: 1: PHP error message, 2: file name, 3: line number */
                        __( 'The request was interrupted by a PHP error: %1$s (%2$s:%3$d). Check the Shippo dashboard before buying again.', 'shipping-desk' ),
                        $error['message'],
                        basename( (string) $error['file'] ),
                        (int) $error['line']
                    ),
                ],
                500
            );
        } );
    }

    /**
     * Give a purchase request room to finish.
     *
     * A Shippo transaction can take 30s+ and the reconciliation that follows a
     * timeout needs longer still; with the default 30s limit PHP would kill the
     * request mid-purchase, which is exactly when a retry is dangerous.
     */
    protected function extend_time_limit() {
        @set_time_limit( 180 );
    }

    public function shipping_desk_buy_label() {
        $this->require_cap();
        $this->extend_time_limit();
        $this->guard_fatal_errors();

        $order_id     = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        $rate_id      = isset( $_POST['rate_id'] ) ? sanitize_text_field( wp_unslash( $_POST['rate_id'] ) ) : '';
        $remote_id    = isset( $_POST['remote_id'] ) ? sanitize_text_field( wp_unslash( $_POST['remote_id'] ) ) : '';
        $rate         = isset( $_POST['rate'] ) ? $this->parse_rate_input( wp_unslash( $_POST['rate'] ) ) : [];
        $vas_input    = ( isset( $_POST['vas'] ) && is_array( $_POST['vas'] ) ) ? wp_unslash( $_POST['vas'] ) : [];
        $total_weight = isset( $_POST['total_weight'] ) ? floatval( $_POST['total_weight'] ) : 0.0;
        $box          = ( isset( $_POST['box'] ) && is_array( $_POST['box'] ) ) ? $_POST['box'] : [];

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => __( 'Order not found.', 'shipping-desk' ) ], 404 );
        }
        if ( empty( $rate_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Rate ID missing.', 'shipping-desk' ) ], 400 );
        }

        $m = Shipping_Desk_Helpers::meta_keys();
        if ( $order->get_meta( $m['remote_id'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Label already purchased.', 'shipping-desk' ) ] );
        }

        // Resolve once: the marker written below must name the provider that
        // actually bought the label, not whatever the setting says later.
        $provider = $this->provider();
        $mode     = $this->provider_mode( $provider );

        // A stored quote may only be bought while it is still valid. An expired carrier
        // quote, or one priced for a dispatch day that has since moved on, would fail
        // at the carrier or print the wrong date — ask for fresh rates instead.
        $quote_record = $this->get_quote_record( $order_id );
        if ( ! empty( $quote_record ) ) {
            if ( ! Shipping_Desk_Helpers::quote_record_is_fresh( $quote_record, $this->current_dispatch_day( $provider ) ) ) {
                $this->delete_quote_record( $order_id );
                wp_send_json_error( [
                    'message' => __( 'These rates have expired: the dispatch day has moved on or the carrier quote timed out. Get rates again before buying.', 'shipping-desk' ),
                ], 409 );
            }

            $known_rate = false;
            foreach ( (array) ( isset( $quote_record['rates'] ) ? $quote_record['rates'] : [] ) as $stored_rate ) {
                if ( isset( $stored_rate['rate_id'] ) && (string) $stored_rate['rate_id'] === (string) $rate_id ) {
                    $known_rate = true;
                    break;
                }
            }
            if ( ! $known_rate ) {
                $this->delete_quote_record( $order_id );
                wp_send_json_error( [
                    'message' => __( 'That rate is no longer part of the stored quotes. Get rates again before buying.', 'shipping-desk' ),
                ], 409 );
            }
        }

        $result = $this->api( $provider, $mode )->book_shipment( $remote_id, $rate_id, $rate, $order_id, $vas_input );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
        }

        $successful = $result['successful'] ?? [];
        if ( empty( $successful ) || ! is_array( $successful ) ) {
            wp_send_json_error( [ 'message' => __( 'The shipping provider did not return a successful shipment.', 'shipping-desk' ) ], 500 );
        }

        $first_key = array_key_first( $successful );
        $shipment  = $successful[ $first_key ];
        $label_id  = $first_key;
        $label_url = $shipment['labels'][0]['url'] ?? '';
        $label_url = Shipping_Desk_Helpers::absolute_url( $label_url );
        $tracking  = $shipment['tracking_number'] ?? '';
        $carrier_id = $shipment['carrier_id'] ?? '';

        // Persist meta via CRUD (HPOS-safe).
        $order->update_meta_data( $m['shipment_id'], $shipment['id'] ?? '' );
        $order->update_meta_data( $m['remote_id'], $remote_id );
        $order->update_meta_data( $m['label_id'], $label_id );
        $order->update_meta_data( $m['tracking'], $tracking );
        $order->update_meta_data( $m['provider'], $provider );
        $order->update_meta_data( $m['provider_mode'], $mode );
        $order->update_meta_data( $m['final_weight'], $total_weight > 0 ? $total_weight : null );
        $order->update_meta_data( $m['box_l'], isset( $box['length'] ) ? floatval( $box['length'] ) : 0 );
        $order->update_meta_data( $m['box_w'], isset( $box['width'] ) ? floatval( $box['width'] ) : 0 );
        $order->update_meta_data( $m['box_h'], isset( $box['height'] ) ? floatval( $box['height'] ) : 0 );
        $order->update_meta_data( $m['box_weight'], isset( $box['weight'] ) ? floatval( $box['weight'] ) : 0 );
        if ( ! empty( $carrier_id ) ) {
            $order->update_meta_data( $m['carrier'], $carrier_id );
        }

        // Insurance actually attached to this label. EasyPost reports the declared
        // value on the shipment and the premium as a fee; storing both is what makes
        // "was this order covered, and what did it cost?" answerable afterwards.
        $insured     = isset( $shipment['insurance'] ) ? trim( (string) $shipment['insurance'] ) : '';
        $insured_fee = isset( $shipment['insurance_fee'] ) ? trim( (string) $shipment['insurance_fee'] ) : '';
        if ( '' !== $insured ) {
            $order->update_meta_data( $m['insurance'], $insured );
        }
        if ( '' !== $insured_fee ) {
            $order->update_meta_data( $m['insurance_fee'], $insured_fee );
        }

        // Applies an accepted address correction (and the optional order update)
        // before this save, so the label meta and the address land together.
        $address_note = $this->apply_address_decision( $order );
        $order->save();

        // Order note recording tracking + remote shipment id.
        $carrier_lc   = strtolower( (string) $carrier_id );
        $carrier_name = $carrier_id ?: 'Carrier';
        if ( strpos( $carrier_lc, 'usps' ) !== false )   $carrier_name = 'USPS';
        elseif ( strpos( $carrier_lc, 'ups' ) !== false )  $carrier_name = 'UPS';
        elseif ( strpos( $carrier_lc, 'fedex' ) !== false ) $carrier_name = 'FedEx';

        $tracking_url = $tracking;
        if ( $tracking ) {
            if ( strpos( $carrier_lc, 'usps' ) !== false ) {
                $tracking_url = 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . urlencode( $tracking );
            } elseif ( strpos( $carrier_lc, 'ups' ) !== false ) {
                $tracking_url = 'https://www.ups.com/track?loc=en_US&tracknum=' . urlencode( $tracking );
            } elseif ( strpos( $carrier_lc, 'fedex' ) !== false ) {
                $tracking_url = 'https://www.fedex.com/fedextrack/?tracknumbers=' . urlencode( $tracking );
            }
        }

        if ( $tracking ) {
            // Name the provider that actually bought the label, and the id that
            // provider uses, so the note stays useful after a switch.
            if ( 'shippo' === $provider ) {
                $reference_label = __( 'Shippo Transaction ID:', 'shipping-desk' );
                $reference_value = $label_id;
            } elseif ( 'easypost' === $provider ) {
                // EasyPost addresses the label by the shipment it was bought on.
                $reference_label = __( 'EasyPost Shipment ID:', 'shipping-desk' );
                $reference_value = $label_id;
            } else {
                $reference_label = __( 'Veeqo Remote Shipment ID:', 'shipping-desk' );
                $reference_value = $remote_id;
            }

            $note = sprintf(
                __( 'Order shipped via %1$s with tracking number <a href="%2$s" target="_blank">%3$s</a><br><strong>%4$s</strong> %5$s', 'shipping-desk' ),
                $carrier_name,
                esc_url( $tracking_url ),
                esc_html( $tracking ),
                esc_html( $reference_label ),
                esc_html( $reference_value )
            );

            // Say the cover out loud. The quote cannot show it (the provider prices
            // insurance at purchase), so without this line the only evidence that a
            // shipment was insured is its cost being higher than the quote.
            if ( '' !== $insured ) {
                $cover = sprintf(
                    /* translators: %s: insured value, formatted with currency. */
                    __( 'Insured for %s', 'shipping-desk' ),
                    wc_price( (float) $insured, [ 'currency' => $order->get_currency() ] )
                );
                if ( '' !== $insured_fee ) {
                    $cover .= ' ' . sprintf(
                        /* translators: %s: insurance premium, formatted with currency. */
                        __( '(premium %s)', 'shipping-desk' ),
                        wc_price( (float) $insured_fee, [ 'currency' => $order->get_currency() ] )
                    );
                }
                $note .= '<br>' . $cover . '.';
            }

            $order->add_order_note( $note );
        }

        if ( '' !== $address_note ) {
            $order->add_order_note( $address_note );
        }

        // The quotes are now spent: they must not be reused for a reship or shown
        // next to a row that already has a label.
        $this->delete_quote_record( $order_id );

        // What the carrier actually charged, for BrikPanel's profit figures. No-op
        // when BrikPanel is absent or the label came from the test account.
        $this->record_shipping_cost( $order, $provider, $mode, $shipment );

        // Reshipping a completed order does not change its status, so the normal
        // status-change notification never fires. Re-trigger the WooCommerce
        // "Completed order" email so the customer receives the new tracking
        // number.
        //
        // OFF by default: shipment tracking plugins such as Advanced Shipment
        // Tracking already send a customer email when they detect new tracking,
        // so enabling this alongside one of those sends the email twice. Turn it
        // on only if nothing else notifies the customer.
        $reship_notified = false;
        if ( $order->has_status( 'completed' ) && get_option( 'shipping_desk_reship_send_completed_email', 0 ) ) {
            do_action( 'woocommerce_order_status_completed_notification', $order_id, $order );
            $reship_notified = true;
            $order->add_order_note( __( 'Shipping Desk: reshipped completed order; customer "Completed order" email re-triggered.', 'shipping-desk' ) );
        } elseif ( $order->has_status( 'completed' ) ) {
            $order->add_order_note( __( 'Shipping Desk: reshipped completed order. No customer email sent (disabled in Settings).', 'shipping-desk' ) );
        }

        wp_send_json_success( [
            'label_id'  => $label_id,
            'label_url' => $label_url,
            'tracking'  => $tracking,
            // So the confirmation can name the cover rather than leaving the operator
            // to infer it from a total that is larger than the quote.
            'insurance'     => $insured,
            'insurance_fee' => $insured_fee,
            'reship_notified' => $reship_notified,
        ] );
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
            if ( ! wp_verify_nonce( $nonce, 'shipping_desk_lookup' ) ) {
                wp_send_json_error( [ 'message' => __( 'Invalid nonce.', 'shipping-desk' ) ], 400 );
            }
            if ( $order_id ) {
                $order = wc_get_order( $order_id );
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
        $provider = $this->normalize_provider( $order ? $order->get_meta( $m['provider'] ) : '', 'veeqo' );
        $mode     = '';

        if ( in_array( $provider, [ 'shippo', 'easypost' ], true ) ) {
            $mode = $order ? (string) $order->get_meta( $m['provider_mode'] ) : '';
            if ( ! in_array( $mode, [ 'test', 'live' ], true ) ) {
                // Marker incomplete (partial write): fall back to the active mode
                // rather than guessing production.
                $mode = $this->provider_mode( $provider );
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

        if ( 'shippo' === $provider && '' === $this->shippo_api_key( $mode ) ) {
            return new WP_Error(
                'shippo_key_missing',
                'live' === $mode
                    ? __( 'This label was bought with Shippo in Production mode, but no Production API key is set.', 'shipping-desk' )
                    : __( 'This label was bought with Shippo in Test mode, but no Test API key is set.', 'shipping-desk' )
            );
        }

        if ( 'easypost' === $provider && '' === $this->easypost_api_key( $mode ) ) {
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
            'client'    => $this->api( $provider, $mode ),
            'reference' => (string) $context['label_reference'],
        ];
    }

    /** -- Bulk Buy Label (single Veeqo request for multiple shipments) -- */

    public function shipping_desk_bulk_buy() {
        $this->require_cap();
        $this->extend_time_limit();
        $this->guard_fatal_errors();
        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        $nonce_valid = $nonce && (
            wp_verify_nonce( $nonce, 'shipping_desk_bulk_buy' ) ||
            wp_verify_nonce( $nonce, 'shipping_desk_order_0' )
        );
        // Also accept any per-order nonce supplied with the payload (the same
        // action used by single-order purchases).
        if ( ! $nonce_valid ) {
            $nonce_entries = [];
            if ( isset( $_POST['orders_json'] ) ) {
                $decoded_nonce = json_decode( wp_unslash( $_POST['orders_json'] ), true );
                if ( is_array( $decoded_nonce ) ) {
                    $nonce_entries = $decoded_nonce;
                }
            } elseif ( isset( $_POST['orders'] ) && is_array( $_POST['orders'] ) ) {
                $nonce_entries = wp_unslash( $_POST['orders'] );
            }
            foreach ( $nonce_entries as $entry ) {
                if ( ! is_array( $entry ) || empty( $entry['order_id'] ) ) {
                    continue;
                }
                if ( $nonce && wp_verify_nonce( $nonce, 'shipping_desk_order_' . intval( $entry['order_id'] ) ) ) {
                    $nonce_valid = true;
                    break;
                }
            }
        }
        if ( ! $nonce_valid ) {
            wp_send_json_error( [
                'message'  => __( 'Invalid nonce.', 'shipping-desk' ),
                'source'   => 'shipping_desk_bulk_buy',
                'received' => $nonce ? 'present' : 'missing',
                'length'   => strlen( (string) $nonce ),
            ], 400 );
        }

        $orders_input = [];
        if ( isset( $_POST['orders_json'] ) ) {
            $decoded = json_decode( wp_unslash( $_POST['orders_json'] ), true );
            if ( is_array( $decoded ) ) {
                $orders_input = $decoded;
            }
        }
        if ( empty( $orders_input ) && isset( $_POST['orders'] ) && is_array( $_POST['orders'] ) ) {
            $orders_input = wp_unslash( $_POST['orders'] );
        }
        if ( empty( $orders_input ) ) {
            wp_send_json_error( [ 'message' => __( 'No shipments to book.', 'shipping-desk' ) ], 400 );
        }

        $m               = Shipping_Desk_Helpers::meta_keys();
        $shipments       = [];
        $order_meta      = [];
        $skipped         = [];

        foreach ( $orders_input as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $order_id     = isset( $entry['order_id'] ) ? intval( $entry['order_id'] ) : 0;
            $rate_id      = isset( $entry['rate_id'] ) ? sanitize_text_field( $entry['rate_id'] ) : '';
            $remote_id    = isset( $entry['remote_id'] ) ? sanitize_text_field( $entry['remote_id'] ) : '';
            $total_weight = isset( $entry['total_weight'] ) ? floatval( $entry['total_weight'] ) : 0.0;
            $box          = isset( $entry['box'] ) && is_array( $entry['box'] ) ? $entry['box'] : [];
            $rate         = isset( $entry['rate'] ) ? $this->parse_rate_input( $entry['rate'] ) : [];

            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                $skipped[ $order_id ] = __( 'Order not found.', 'shipping-desk' );
                continue;
            }
            if ( $order->get_meta( $m['remote_id'] ) ) {
                $skipped[ $order_id ] = __( 'Label already purchased.', 'shipping-desk' );
                continue;
            }
            if ( empty( $rate_id ) || empty( $remote_id ) ) {
                $skipped[ $order_id ] = __( 'Rate or shipment ID missing.', 'shipping-desk' );
                continue;
            }

            $vas_fields = [];
            if ( ! empty( $entry['vas'] ) && is_array( $entry['vas'] ) ) {
                foreach ( $entry['vas'] as $vas_key => $vas_val ) {
                    if ( ! is_string( $vas_key ) || $vas_key === '' ) {
                        continue;
                    }
                    $vas_fields[ sanitize_text_field( $vas_key ) ] = is_scalar( $vas_val )
                        ? sanitize_text_field( (string) $vas_val )
                        : '';
                }
            }

            $ship = [
                'remote_shipment_id' => $remote_id,
                'rate_id'            => $rate_id,
                // The rate carries the carrier, service and price: a Shippo
                // transaction returns only a rate ID, so without this the order
                // note cannot name the carrier (and AST cannot complete the order).
                'rate'               => $rate,
                'order_id'           => (string) $order_id,
                'custom_messages'    => [ (string) $order_id ],
            ];
            foreach ( $vas_fields as $key => $val ) {
                $ship[ $key ] = $val;
            }

            $shipments[ $order_id ] = $ship;
            $order_meta[ $order_id ] = [
                'box'          => $box,
                'total_weight' => $total_weight,
            ];
        }

        // TO BE CONTINUED  -  response handling

        $purchased = 0;
        $failed    = 0;
        $errors    = [];

        if ( ! empty( $shipments ) ) {
            // One request, one provider: stamp the same marker on every order it
            // buys for, so print and refund can find the right client later.
            $provider = $this->provider();
            $mode     = $this->provider_mode( $provider );

            $result = $this->api( $provider, $mode )->book_shipments( $shipments );
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
            }

            $successful = $result['successful'] ?? [];
            foreach ( $successful as $label_id => $shipment ) {
                $order_id = (int) ( $shipment['customer_reference'] ?? 0 );
                if ( ! $order_id || ! isset( $shipments[ $order_id ] ) ) {
                    continue;
                }
                $order = wc_get_order( $order_id );
                if ( ! $order ) {
                    continue;
                }
                $order_data = $order_meta[ $order_id ];

                $tracking   = $shipment['tracking_number'] ?? '';
                $carrier_id = $shipment['carrier_id'] ?? '';

                $order->update_meta_data( $m['shipment_id'], $shipment['id'] ?? '' );
                $order->update_meta_data( $m['remote_id'],   $shipments[ $order_id ]['remote_shipment_id'] );
                $order->update_meta_data( $m['label_id'],    $label_id );
                $order->update_meta_data( $m['tracking'],    $tracking );
                $order->update_meta_data( $m['provider'],      $provider );
                $order->update_meta_data( $m['provider_mode'], $mode );
                $order->update_meta_data( $m['final_weight'], $order_data['total_weight'] > 0 ? $order_data['total_weight'] : null );
                $box = $order_data['box'];
                $order->update_meta_data( $m['box_l'],       isset( $box['length'] ) ? floatval( $box['length'] ) : 0 );
                $order->update_meta_data( $m['box_w'],       isset( $box['width'] )  ? floatval( $box['width'] )  : 0 );
                $order->update_meta_data( $m['box_h'],       isset( $box['height'] ) ? floatval( $box['height'] ) : 0 );
                $order->update_meta_data( $m['box_weight'],  isset( $box['weight'] ) ? floatval( $box['weight'] ) : 0 );
                if ( ! empty( $carrier_id ) ) {
                    $order->update_meta_data( $m['carrier'], $carrier_id );
                }
                // Same as a single purchase: an accepted correction is applied
                // before the save so the address and the label meta land together.
                $address_note = $this->apply_address_decision( $order );
                $order->save();

                $carrier_lc   = strtolower( (string) $carrier_id );
                $carrier_name = $carrier_id ?: 'Carrier';
                if ( strpos( $carrier_lc, 'usps' ) !== false )   $carrier_name = 'USPS';
                elseif ( strpos( $carrier_lc, 'ups' )  !== false )  $carrier_name = 'UPS';
                elseif ( strpos( $carrier_lc, 'fedex' ) !== false ) $carrier_name = 'FedEx';
                $tracking_url = Shipping_Desk_Helpers::tracking_url( $carrier_id, $tracking );

                $reference_label = 'shippo' === $provider
                    ? __( 'Shippo Transaction ID:', 'shipping-desk' )
                    : ( 'easypost' === $provider
                        ? __( 'EasyPost Shipment ID:', 'shipping-desk' )
                        : __( 'Veeqo Remote Shipment ID:', 'shipping-desk' ) );
                $reference_value = in_array( $provider, [ 'shippo', 'easypost' ], true )
                    ? $label_id
                    : $shipments[ $order_id ]['remote_shipment_id'];

                $notee = sprintf(
                    __( 'Order shipped via %1$s with tracking number <a href="%2$s" target="_blank">%3$s</a><br><strong>%4$s</strong> %5$s', 'shipping-desk' ),
                    $carrier_name,
                    esc_url( $tracking_url ),
                    esc_html( $tracking ),
                    esc_html( $reference_label ),
                    esc_html( $reference_value )
                );
                $order->add_order_note( $notee );
                if ( '' !== $address_note ) {
                    $order->add_order_note( $address_note );
                }
                $this->delete_quote_record( $order_id );
                $this->record_shipping_cost( $order, $provider, $mode, $shipment );
                $purchased++;
                unset( $skipped[ $order_id ] );
            }

            $failed_shipments = $result['failed'] ?? [];
            foreach ( $failed_shipments as $failed_remote_id => $failed_data ) {
                $order_id = (int) ( $failed_data['customer_reference'] ?? 0 );
                if ( $order_id && isset( $shipments[ $order_id ] ) ) {
                    $failed++;
                    $error_msg = isset( $failed_data['error_messages'] ) && is_array( $failed_data['error_messages'] )
                        ? implode( '; ', $failed_data['error_messages'] )
                        : __( 'Label purchase failed.', 'shipping-desk' );
                    $errors[]             = $error_msg;
                    $skipped[ $order_id ] = $error_msg;
                }
            }
        }

        wp_send_json_success( [
            'purchased'  => $purchased,
            'failed'     => $failed + count( $skipped ),
            'errors'     => array_merge( $errors, array_values( $skipped ) ),
            'order_ids'   => array_merge(
                array_keys( $shipments ),
                array_keys( $skipped )
            ),
        ] );
    }

    public function shipping_desk_print_label() {
        $this->require_cap();
        $context = $this->get_shipment_action_context();
        $target  = $this->label_client( $context );
        if ( is_wp_error( $target ) ) {
            wp_send_json_error( [ 'message' => $target->get_error_message() ], 400 );
        }

        // Always request a fresh URL: Veeqo label URLs carry expiring tokens and
        // Shippo label URLs are signed with their own expiry.
        $label_url = $target['client']->get_label_url( $target['reference'] );
        if ( is_wp_error( $label_url ) ) {
            wp_send_json_error( [ 'message' => $label_url->get_error_message() ], 500 );
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
                $result = $this->printnode()->send_print_job(
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

                $result = $this->printnode()->send_print_job( $printer_id, $title, 'pdf_uri', $label_url, 'Shipping Desk' );
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
            $is_lookup    = '' !== (string) $context['remote_id'] && wp_verify_nonce( $incoming, 'shipping_desk_lookup' );
            $context_id   = (int) ( $context['order'] ? $context['order']->get_id() : 0 );
            $print_args   = [ 'action' => 'shipping_desk_print_pdf' ];

            if ( $is_lookup ) {
                $print_args['remote_id'] = $context['remote_id'];
                $print_args['order_id']  = $context_id;
                $print_args['_wpnonce']  = wp_create_nonce( 'shipping_desk_lookup' );
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
            $label_url = $target['client']->get_label_url( $target['reference'] );
            if ( is_wp_error( $label_url ) || '' === trim( (string) $label_url ) ) {
                wp_die(
                    esc_html__( 'The shipping provider did not return a label.', 'shipping-desk' ),
                    '',
                    [ 'response' => 502 ]
                );
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
        $this->require_cap();
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
            $this->clear_shipping_cost( $order );
            $order->add_order_note(
                'shippo' === $context['provider']
                    ? __( 'Shippo label refund requested.', 'shipping-desk' )
                    : ( 'easypost' === $context['provider']
                        ? __( 'EasyPost label refund requested.', 'shipping-desk' )
                        : __( 'Veeqo shipping label refunded/cancelled.', 'shipping-desk' ) )
            );
        }

        wp_send_json_success( [ 'refunded' => true ] );
    }

    public function shipping_desk_reset_shipping() {
        $this->require_cap();
        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        $nonce    = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'shipping_desk_order_' . $order_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid nonce.', 'shipping-desk' ) ], 400 );
        }
        $order    = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => __( 'Order not found.', 'shipping-desk' ) ], 404 );
        }
        Shipping_Desk_Helpers::clear_shipping_meta( $order );
        // Reset means start over, so the cached address check goes too. Without
        // this a re-test of the same address is answered from a twelve-hour-old
        // transient and never reaches Shippo.
        $this->clear_address_validation_cache( $order_id );
        $this->delete_quote_record( $order_id );
        $order->add_order_note( __( 'Shipping Desk: shipping state reset  -  a new label can now be purchased.', 'shipping-desk' ) );
        wp_send_json_success( [ 'reset' => true ] );
    }

    /**
     * Drop the cached address validation results for one order.
     *
     * The transient name is `shipping_desk_addr_<order>_<address hash>`, so the
     * order's entries are removed by prefix. Direct SQL is the only way to do that
     * — there is no core API for wildcard transient deletion — and it is scoped to
     * this plugin's option names only.
     *
     * @param int $order_id Order ID.
     * @return void
     */
    protected function clear_address_validation_cache( $order_id ) {
        global $wpdb;

        $order_id = (int) $order_id;
        if ( $order_id <= 0 ) {
            return;
        }

        foreach ( [ '_transient_', '_transient_timeout_' ] as $prefix ) {
            $like = $prefix . 'shipping_desk_addr_' . $order_id . '_%';
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $wpdb->esc_like( $like )
                )
            );
        }
    }

    /**
     * Return the purchasable row markup for an order.
     *
     * Used by the Completed tab so a reset order can be shipped again in place,
     * without changing its status.
     */
    public function shipping_desk_render_purchase_row() {
        $this->require_cap();
        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        $nonce    = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'shipping_desk_order_' . $order_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid nonce.', 'shipping-desk' ) ], 400 );
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => __( 'Order not found.', 'shipping-desk' ) ], 404 );
        }

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

    /**
     * Lazy instantiation of the PrintNode API client.
     *
     * @return Shipping_Desk_PrintNode_Client
     */
    protected function printnode() {
        if ( ! isset( $this->_printnode_client ) ) {
            require_once $this->path . 'includes/class-printnode-client.php';
            $this->_printnode_client = new Shipping_Desk_PrintNode_Client();
        }
        return $this->_printnode_client;
    }

    /** -- PrintNode AJAX handlers -- */

    /**
     * No'cap'check status endpoint so the client can verify PrintNode
     * is configured without requiring a per'order nonce.
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

    public function shipping_desk_printnode_print_label() {
        $this->require_cap();
        $context = $this->get_shipment_action_context();
        $target  = $this->label_client( $context );
        if ( is_wp_error( $target ) ) {
            wp_send_json_error( [ 'message' => $target->get_error_message() ], 400 );
        }

        $label_url = $target['client']->get_label_url( $target['reference'] );
        if ( is_wp_error( $label_url ) ) {
            wp_send_json_error( [ 'message' => $label_url->get_error_message() ], 500 );
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
        $result = $this->printnode()->send_print_job( $printer_id, $title, 'pdf_uri', $label_url, 'Shipping Desk' );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
        }

        wp_send_json_success( [
            'print_job_id' => $result['id'] ?? 0,
            'message'      => __( 'Label sent to PrintNode.', 'shipping-desk' ),
        ] );
    }

}
