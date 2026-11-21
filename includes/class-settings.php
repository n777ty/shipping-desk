<?php
/**
 * Settings: registration, sanitisers, one-time migrations and the field renderers.
 *
 * Moved out of Shipping_Desk_Plugin without changing what it does. What the rest of the
 * plugin shares (the provider, the key modes, the verifier) is still asked of the plugin, so
 * there is one answer to each of those questions. The settings pages still render in the
 * plugin and call the field renderers here.
 */
class Shipping_Desk_Settings {

    /** @var Shipping_Desk_Plugin */
    protected $plugin;

    public function __construct( Shipping_Desk_Plugin $plugin ) {
        $this->plugin = $plugin;
    }

    /**
     * Register the settings and the one-time migrations.
     *
     * @return void
     */
    public function init() {
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        // Runs before settings are used so legacy options are carried over.
        add_action( 'admin_init', [ $this, 'maybe_migrate_legacy_settings' ], 1 );
        add_action( 'admin_init', [ $this, 'maybe_migrate_easypost_key' ], 2 );
        add_action( 'admin_init', [ $this, 'maybe_default_legacy_provider' ], 3 );
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

        // Checkout address validation: one switch and one verifier choice, both in
        // the provider form because they decide who sees the customer's address,
        // not how a label is bought.
        register_setting( 'shipping_desk_api_settings', 'shipping_desk_checkout_validation', [ 'sanitize_callback' => function ( $value ) {
            return $value ? 1 : 0;
        } ] );
        register_setting( 'shipping_desk_api_settings', 'shipping_desk_verifier', [ 'sanitize_callback' => function ( $value ) {
            return in_array( $value, [ 'auto', 'easypost', 'provider', 'off' ], true ) ? $value : 'auto';
        } ] );

        register_setting( 'shipping_desk_api_settings', 'shipping_desk_veeqo_api_key' );
        // The legacy single EasyPost key is deliberately NOT registered. It has no field on
        // this page, and any option registered to a settings group is set to null whenever a
        // form in that group is saved without it - which would erase the key before
        // maybe_migrate_easypost_key() could copy it into its slot. Unregistered, nothing
        // here can touch it.
        // Its own group, not the keys' group: WordPress saves a group, so sharing one would
        // mean saving an address re-posts every key - and any field absent from that POST
        // is nulled. The gate in tests/settings-forms-test.php enforces the split.
        register_setting( 'shipping_desk_from_settings', 'shipping_desk_from_address', [ 'sanitize_callback' => [ $this, 'sanitize_from_address' ] ] );

        add_settings_section( 'shipping_desk_provider_section', __( 'Label Provider', 'shipping-desk' ), '__return_false', 'shipping_desk_api_settings' );
        add_settings_field( 'shipping_desk_provider_field', __( 'Active provider', 'shipping-desk' ), [ $this, 'render_provider_field' ], 'shipping_desk_api_settings', 'shipping_desk_provider_section' );
        add_settings_field( 'shipping_desk_shippo_mode_field', __( 'Shippo mode', 'shipping-desk' ), [ $this, 'render_shippo_mode_field' ], 'shipping_desk_api_settings', 'shipping_desk_provider_section' );
        add_settings_field( 'shipping_desk_shippo_keys_field', __( 'Shippo API keys', 'shipping-desk' ), [ $this, 'render_shippo_keys_field' ], 'shipping_desk_api_settings', 'shipping_desk_provider_section' );
        add_settings_field( 'shipping_desk_easypost_mode_field', __( 'EasyPost mode', 'shipping-desk' ), [ $this, 'render_easypost_mode_field' ], 'shipping_desk_api_settings', 'shipping_desk_provider_section' );
        add_settings_field( 'shipping_desk_easypost_keys_field', __( 'EasyPost API keys', 'shipping-desk' ), [ $this, 'render_easypost_keys_field' ], 'shipping_desk_api_settings', 'shipping_desk_provider_section' );

        // One provider's key is not more special than another's, and Veeqo has no
        // mode of its own to group with: it belongs beside the Shippo and EasyPost
        // keys, not under a heading that makes it look like a separate feature.
        add_settings_field( 'shipping_desk_veeqo_api_key_field', __( 'Veeqo API Key', 'shipping-desk' ), [ $this, 'render_api_key_field' ], 'shipping_desk_api_settings', 'shipping_desk_provider_section' );

        // Its own section, last on the form. Verification serves the admin address
        // gate and the checkout whichever provider buys the labels, so sitting
        // between the EasyPost keys and the Veeqo key made it read as an EasyPost
        // option - which it is not.
        add_settings_section( 'shipping_desk_verification_section', __( 'Address verification', 'shipping-desk' ), [ $this, 'render_verification_section' ], 'shipping_desk_api_settings' );
        add_settings_field( 'shipping_desk_checkout_validation_field', __( 'Address verification', 'shipping-desk' ), [ $this, 'render_checkout_validation_field' ], 'shipping_desk_api_settings', 'shipping_desk_verification_section' );

        add_settings_section( 'shipping_desk_from_address_section', __( 'From Address (Optional)', 'shipping-desk' ), '__return_false', 'shipping_desk_from_settings' );
        add_settings_field( 'shipping_desk_from_address_field', __( 'Shipment Origin', 'shipping-desk' ), [ $this, 'render_from_address_field' ], 'shipping_desk_from_settings', 'shipping_desk_from_address_section' );

        register_setting( 'shipping_desk_printnode_settings', 'shipping_desk_printnode_api_key' );
        register_setting( 'shipping_desk_printnode_settings', 'shipping_desk_printnode_printer_id', [ 'sanitize_callback' => 'absint' ] );
        register_setting( 'shipping_desk_printnode_settings', 'shipping_desk_print_mode', [ 'sanitize_callback' => function ( $value ) {
            return in_array( $value, [ 'browser', 'printnode' ], true ) ? $value : 'browser';
        } ] );

        // Each of these lives in its own settings group, because WordPress saves a *group*,
        // not a form: every option registered to the submitted group is overwritten, and
        // ones missing from the POST are set to null. Sharing one group across several
        // forms meant saving the reship checkbox wiped the API keys and the provider.
        register_setting( 'shipping_desk_uninstall_settings', 'shipping_desk_delete_data_on_uninstall', [ 'sanitize_callback' => function ( $value ) {
            return $value ? 1 : 0;
        } ] );

        register_setting( 'shipping_desk_reship_settings', 'shipping_desk_reship_send_completed_email', [ 'sanitize_callback' => function ( $value ) {
            return $value ? 1 : 0;
        } ] );

        register_setting( 'shipping_desk_tracking_settings', 'shipping_desk_complete_order_on_label', [ 'sanitize_callback' => function ( $value ) {
            return $value ? 1 : 0;
        } ] );
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

    /**
     * EasyPost key mode.
     *
     * One mode governs everything EasyPost does here — buying labels *and*
     * verifying addresses — because they share a key. There is deliberately no
     * separate verifier key: the selected key is the one used for both jobs.
     */
    public function render_easypost_mode_field() {
        $mode = $this->plugin->easypost_mode();
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
                   value="<?php echo esc_attr( $test_key ); ?>" <?php echo $this->api_key_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput -- static attribute string ?> />
        </p>
        <p>
            <label for="shipping_desk_easypost_live_api_key"><strong><?php esc_html_e( 'Production API key', 'shipping-desk' ); ?></strong></label><br />
            <input type="password" id="shipping_desk_easypost_live_api_key" name="shipping_desk_easypost_live_api_key"
                   value="<?php echo esc_attr( $live_key ); ?>" <?php echo $this->api_key_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput -- static attribute string ?> />
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
     * Section intro for the verification block.
     *
     * The scope belongs here rather than beside the checkbox: it describes what
     * the whole block does and does not cover.
     */
    public function render_verification_section() {
        ?>
        <p class="description">
            <?php esc_html_e( 'US destinations only, including Puerto Rico, Guam, the US Virgin Islands, American Samoa and the Northern Mariana Islands; other countries are left untouched. Checkout is never blocked, and an unreachable verifier costs the sale nothing. One call per unique address per 90 days.', 'shipping-desk' ); ?>
        </p>
        <?php
    }

    /**
     * Who verifies, and whether customers see it.
     *
     * Two questions, not one: "does anything get verified, and by whom" above,
     * "does the customer see it at checkout" below. Verifier Off is not the same
     * as unticking the checkbox — Off also stops the admin address gate, and is
     * the only way to switch verification off without deleting the key. Hence the
     * wording, and the disabled checkbox when Off is selected: a ticked box that
     * can never do anything is a lie on screen.
     */
    public function render_checkout_validation_field() {
        $enabled  = $this->plugin->checkout_validation_enabled();
        $choice   = $this->plugin->verifier_choice();
        $summary  = $this->plugin->verifier_summary();
        $disabled = 'off' === $choice;

        $options = [
            'auto'     => __( 'Automatic — EasyPost when a key is set, otherwise the label provider', 'shipping-desk' ),
            'easypost' => __( 'EasyPost only', 'shipping-desk' ),
            'provider' => __( 'The active label provider only', 'shipping-desk' ),
            'off'      => __( 'Off — no address is checked anywhere', 'shipping-desk' ),
        ];
        ?>
        <p>
            <label for="shipping_desk_verifier"><strong><?php esc_html_e( 'Verifier', 'shipping-desk' ); ?></strong></label><br />
            <select class="shipping-desk-verifier-select" id="shipping_desk_verifier" name="shipping_desk_verifier">
                <?php foreach ( $options as $value => $label ) : ?>
                    <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $choice, $value ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p class="description"><?php echo esc_html( $summary['note'] ); ?></p>
        <?php if ( '' !== $summary['warning'] ) : ?>
            <div class="notice notice-warning inline"><p><?php echo esc_html( $summary['warning'] ); ?></p></div>
        <?php endif; ?>
        <p>
            <label<?php echo $disabled ? ' class="shipping-desk-disabled"' : ''; ?>>
                <input type="checkbox" name="shipping_desk_checkout_validation" value="1" <?php checked( $enabled ); ?> <?php disabled( $disabled ); ?> />
                <?php esc_html_e( 'Also offer suggestions on the checkout page', 'shipping-desk' ); ?>
            </label>
        </p>
        <?php if ( $disabled ) : ?>
            <p class="description"><?php esc_html_e( 'No verification happens while the verifier is Off, so there is nothing to offer at checkout.', 'shipping-desk' ); ?></p>
        <?php endif; ?>
        <?php
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

        if ( 'easypost' === $this->plugin->provider() && '' === $this->plugin->easypost_api_key() ) {
            return 'live' === $this->plugin->easypost_mode()
                ? __( 'EasyPost is the active provider but no Production API key is set, so rate and label requests will fail.', 'shipping-desk' )
                : __( 'EasyPost is the active provider but no Test API key is set, so rate and label requests will fail.', 'shipping-desk' );
        }

        return '';
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
        $missing = $this->plugin->from_address_problems();
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

    /**
     * Attributes that keep password managers out of an API key field.
     *
     * `autocomplete="off"` is the one they ignore, which is how a manager filled a key
     * while the operator was editing the origin address. `new-password` is the signal
     * browsers honour, and the data-* attributes are the vendor equivalents - LastPass,
     * 1Password, Bitwarden, Dashlane.
     *
     * Static string, no user input: echo it directly, or `esc_attr()` would mangle the
     * quotes into one useless attribute.
     *
     * @return string
     */
    protected function api_key_attributes() {
        return 'class="regular-text shipping-desk-api-key" autocomplete="new-password" data-lpignore="true" data-1p-ignore data-bwignore data-form-type="other"';
    }

    public function render_api_key_field() {
        $api_key = get_option( 'shipping_desk_veeqo_api_key', '' );
        printf(
            '<input type="password" name="shipping_desk_veeqo_api_key" value="%s" %s /><p class="description">%s</p>',
            esc_attr( $api_key ), $this->api_key_attributes(),
            esc_html__( 'Stored in wp_options; used for all Veeqo API calls.', 'shipping-desk' )
        );
    }

    public function render_provider_field() {
        $provider = $this->plugin->provider();
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
        $mode = $this->plugin->shippo_mode();
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
                   value="<?php echo esc_attr( $test_key ); ?>" <?php echo $this->api_key_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput -- static attribute string ?> />
        </p>
        <p>
            <label for="shipping_desk_shippo_live_api_key"><strong><?php esc_html_e( 'Production API key', 'shipping-desk' ); ?></strong></label><br />
            <input type="password" id="shipping_desk_shippo_live_api_key" name="shipping_desk_shippo_live_api_key"
                   value="<?php echo esc_attr( $live_key ); ?>" <?php echo $this->api_key_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput -- static attribute string ?> />
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

        if ( 'shippo' === $this->plugin->provider() && '' === $this->plugin->shippo_api_key() ) {
            return 'live' === $this->plugin->shippo_mode()
                ? __( 'Shippo is the active provider but no Production API key is set, so rate and label requests will fail.', 'shipping-desk' )
                : __( 'Shippo is the active provider but no Test API key is set, so rate and label requests will fail.', 'shipping-desk' );
        }

        return '';
    }
}
