<?php
/**
 * Uninstall cleanup.
 *
 * Settings are preserved by default. Deleting a plugin is a single click and
 * can also mean removing a stray duplicate install, so wiping configuration
 * unconditionally has destroyed live settings before. Data is only removed when
 * the administrator has explicitly opted in via the "Delete all data on
 * uninstall" setting.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$shipping_desk_purge = get_option( 'shipping_desk_delete_data_on_uninstall', false );

if ( $shipping_desk_purge ) {
    delete_option( 'shipping_desk_boxes' );
delete_option( 'shipping_desk_daily_spend' );
delete_option( 'shipping_desk_complete_order_on_label' );
    delete_option( 'shipping_desk_veeqo_api_key' );
    delete_option( 'shipping_desk_provider' );
    delete_option( 'shipping_desk_shippo_mode' );
    delete_option( 'shipping_desk_shippo_test_api_key' );
    delete_option( 'shipping_desk_shippo_live_api_key' );
    delete_option( 'shipping_desk_easypost_mode' );
    delete_option( 'shipping_desk_easypost_test_api_key' );
    delete_option( 'shipping_desk_easypost_live_api_key' );
    delete_option( 'shipping_desk_easypost_api_key' );
    delete_option( 'shipping_desk_easypost_key_migrated' );
    delete_option( 'shipping_desk_provider_defaulted' );
    delete_option( 'shipping_desk_checkout_validation' );
    delete_option( 'shipping_desk_verifier' );
    delete_option( 'shipping_desk_from_address' );
    delete_option( 'shipping_desk_printnode_api_key' );
    delete_option( 'shipping_desk_printnode_printer_id' );
    delete_option( 'shipping_desk_print_mode' );
    delete_option( 'shipping_desk_print_reference' );
    delete_option( 'shipping_desk_print_reference_text' );
    delete_option( 'shipping_desk_reship_send_completed_email' );
    delete_option( 'shipping_desk_delete_data_on_uninstall' );
    delete_option( 'shipping_desk_legacy_migrated' );
    delete_option( 'shipping_desk_whats_new' );
    delete_option( 'shipping_desk_secret_autoload_fixed' );

    // Remove post meta for all orders (HPOS-aware not guaranteed at uninstall; use meta API).
    global $wpdb;
    $meta_keys = [
        '_shipping_desk_shipment_id', '_shipping_desk_remote_shipment_id', '_shipping_desk_label_id',
        '_shipping_desk_label_url', '_shipping_desk_tracking_number', '_shipping_desk_carrier',
        '_shipping_desk_provider', '_shipping_desk_mode',
        '_shipping_desk_final_weight_used_oz', '_shipping_desk_box_length', '_shipping_desk_box_width',
        '_shipping_desk_box_height', '_shipping_desk_box_weight_oz', '_shipping_desk_label_refunded',
    ];
    foreach ( $meta_keys as $key ) {
        $wpdb->delete( $wpdb->postmeta, [ 'meta_key' => $key ], [ '%s' ] );
    }

    // Purchase locks that were never released (a request killed mid-purchase).
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like( 'shipping_desk_buy_lock_' ) . '%'
        )
    );

    // The checkout throttle counters (one row per window, per scope).
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like( 'shipping_desk_co_count_' ) . '%'
        )
    );

    // Caches this plugin leaves in the options table: stored quotes and remembered address
    // verdicts, the checkout address book and its throttle, and the update-check cache. Each
    // is a transient, so both the value row and its _transient_timeout_ row are removed, and
    // every one starts with shipping_desk_.
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like( '_transient_shipping_desk_' ) . '%',
            $wpdb->esc_like( '_transient_timeout_shipping_desk_' ) . '%'
        )
    );

    // Which changelog each user had dismissed, for everyone who ever saw the popup.
    delete_metadata( 'user', 0, 'shipping_desk_whats_new_seen', '', true );
}
