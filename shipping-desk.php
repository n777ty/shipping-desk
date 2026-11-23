<?php
/**
 * Plugin Name: Shipping Desk
 * Plugin URI:  https://github.com/n777ty/shipping-desk
 * Description: Buy, print and refund Veeqo, Shippo and EasyPost shipping labels from the WooCommerce orders screen, with box presets, bulk buy/print and optional PrintNode server-side printing.
 * Version:     1.8.0
 * Author:      Jailbreak777
 * Author URI:  https://t.me/Jailbreak777
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: shipping-desk
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'SHIPPING_DESK_VERSION' ) ) {
    define( 'SHIPPING_DESK_VERSION', '1.8.0' );
}

/*
 * Update checks are attached here, not inside the WooCommerce guard below: a store whose
 * WooCommerce is switched off must still be able to take an update rather than be stuck
 * on an old build until someone uploads a zip by hand.
 */
require_once __DIR__ . '/includes/class-update-checker.php';
Shipping_Desk_Update_Checker::boot();

// Tracking hand-off to AST / Shipment Tracking, or to the customer. Needs no WooCommerce at
// load time; the class only defines helpers.
require_once __DIR__ . '/includes/class-tracking-relay.php';
Shipping_Desk_Tracking_Relay::boot();

// HPOS compatibility declaration.
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
} );

add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            printf( '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>', esc_html__( 'Shipping Desk', 'shipping-desk' ), esc_html__( 'requires WooCommerce to be active.', 'shipping-desk' ) );
        } );
        return;
    }

    $base = plugin_dir_path( __FILE__ );

    require_once $base . 'includes/class-provider-interface.php';
    require_once $base . 'includes/class-address-validator-interface.php';
    require_once $base . 'includes/class-easypost-client.php';
    require_once $base . 'includes/class-api-client.php';
    require_once $base . 'includes/class-shippo-client.php';
    require_once $base . 'includes/helpers.php';
    require_once $base . 'includes/class-printnode-client.php';
    require_once $base . 'includes/class-plugin.php';
    require_once $base . 'includes/class-checkout-address.php';
    require_once $base . 'includes/class-block-checkout.php';

    $plugin = new Shipping_Desk_Plugin( __FILE__ );
    $plugin->init();

    // Checkout-side validation: the other half of the admin address gate. Inert
    // until the setting is on and a verifier resolves.
    $checkout_address = new Shipping_Desk_Checkout_Address( $plugin );
    $checkout_address->init();

    // The block checkout has no browser hook to offer the address, so it is settled
    // on the server when the Store API creates the order. See class-block-checkout.php.
    $block_checkout = new Shipping_Desk_Block_Checkout( $plugin );
    $block_checkout->init();
}, 20 );
