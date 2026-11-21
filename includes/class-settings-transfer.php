<?php
/**
 * Settings backup and restore: the JSON export, the import, and the notices that follow.
 *
 * Moved out of Shipping_Desk_Plugin without changing what it does. The plugin keeps what the
 * rest of the plugin shares (the provider, the key modes, the sanitisers) and this class asks
 * it, so there is still exactly one answer to each of those questions.
 */
class Shipping_Desk_Settings_Transfer {

    /** Largest settings export that will be read back in, in bytes. */
    const IMPORT_MAX_BYTES = 262144;

    /** @var Shipping_Desk_Plugin */
    protected $plugin;

    public function __construct( Shipping_Desk_Plugin $plugin ) {
        $this->plugin = $plugin;
    }

    /**
     * Register the two admin-post endpoints.
     *
     * @return void
     */
    public function init() {
        add_action( 'admin_post_shipping_desk_export_settings', [ $this, 'handle_export_settings' ] );
        add_action( 'admin_post_shipping_desk_import_settings', [ $this, 'handle_import_settings' ] );
    }

    /**
     * Export the current settings as JSON.
     */
    public function handle_export_settings() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to export settings.', 'shipping-desk' ) );
        }
        check_admin_referer( 'shipping_desk_export_settings' );

        $include_keys = ! empty( $_GET['include_keys'] );
        $boxes        = get_option( 'shipping_desk_boxes', [] );

        $settings = [
            'provider'             => $this->plugin->provider(),
            'shippo_mode'          => $this->plugin->shippo_mode(),
            'easypost_mode'        => $this->plugin->easypost_mode(),
            'from_address'         => get_option( 'shipping_desk_from_address', [] ),
            'boxes'                => is_array( $boxes ) ? array_values( $boxes ) : [],
            'print_mode'           => (string) get_option( 'shipping_desk_print_mode', 'browser' ),
            'printnode_printer_id' => (int) get_option( 'shipping_desk_printnode_printer_id', 0 ),
        ];

        // API keys are the one thing a settings file must not leak by accident: a file pasted
        // into a ticket, a support chat or a public backup otherwise carries live credentials.
        // They are written only when the export was explicitly asked to include them.
        if ( $include_keys ) {
            $settings = array_merge(
                $settings,
                [
                    'api_key'               => (string) get_option( 'shipping_desk_veeqo_api_key', '' ),
                    'shippo_test_api_key'   => (string) get_option( 'shipping_desk_shippo_test_api_key', '' ),
                    'shippo_live_api_key'   => (string) get_option( 'shipping_desk_shippo_live_api_key', '' ),
                    'easypost_test_api_key' => (string) get_option( 'shipping_desk_easypost_test_api_key', '' ),
                    'easypost_live_api_key' => (string) get_option( 'shipping_desk_easypost_live_api_key', '' ),
                    'printnode_api_key'     => (string) get_option( 'shipping_desk_printnode_api_key', '' ),
                ]
            );
        }

        $payload = [
            'plugin'      => 'shipping-desk',
            'version'     => defined( 'SHIPPING_DESK_VERSION' ) ? SHIPPING_DESK_VERSION : '',
            'exported_at' => gmdate( 'c' ),
            'secrets'     => $include_keys,
            'settings'    => $settings,
        ];

        if ( $include_keys ) {
            $payload['warning'] = 'This file contains live API keys. Store it somewhere safe, and delete it when it is no longer needed.';
        }

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

        $file = $_FILES['shipping_desk_import_file'];

        // An upload is untrusted input: confirm PHP reported no error, that the file really
        // arrived as an upload, and that it is not absurdly large, before reading it.
        if (
            ! isset( $file['error'], $file['size'] )
            || UPLOAD_ERR_OK !== (int) $file['error']
            || (int) $file['size'] > self::IMPORT_MAX_BYTES
            || ! is_uploaded_file( (string) $file['tmp_name'] )
        ) {
            wp_safe_redirect( add_query_arg( 'shipping_desk_import', 'invalid', $redirect ) );
            exit;
        }

        $raw     = (string) file_get_contents( (string) $file['tmp_name'] );
        $decoded = json_decode( $raw, true );

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
            update_option( 'shipping_desk_provider', $this->plugin->normalize_provider( $settings['provider'], 'shippo' ) );
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
            update_option( 'shipping_desk_from_address', $this->plugin->settings()->sanitize_from_address( $settings['from_address'] ) );
            $imported[] = 'from_address';
        }

        if ( isset( $settings['boxes'] ) && is_array( $settings['boxes'] ) ) {
            update_option( 'shipping_desk_boxes', $this->plugin->settings()->sanitize_boxes( $settings['boxes'] ) );
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

    /**
     * Show the result of an import, or a notice that legacy settings were
     * carried over from the pre-refactor plugin.
     */
    public function render_import_notices() {
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
}
