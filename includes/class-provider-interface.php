<?php
/**
 * Label provider contract.
 *
 * Both the Veeqo and Shippo clients implement this interface so the plugin can
 * be switched between providers without touching the admin UI, the JavaScript
 * or the bulk/single order flows.
 *
 * Rate arrays returned by providers are normalised to the shape the admin
 * JavaScript already consumes:
 *
 *   [
 *     'rate_id'            => string,  // provider's rate identifier
 *     'remote_shipment_id' => string,  // parent shipment identifier
 *     'service_name'       => string,  // e.g. "UPS Ground"
 *     'total_charge'       => string,  // numeric amount, as returned
 *     'shipping_service_options' => array, // optional priced extras
 *     ...provider-specific fields may be preserved
 *   ]
 *
 * Every method returns an array on success or a WP_Error on failure.
 */
interface Shipping_Desk_Provider_Interface {

    /**
     * Fetch shipping rates for a shipment.
     *
     * @param array $payload Normalised shipment request.
     * @return array|WP_Error
     */
    public function get_rates( array $payload );

    /**
     * Purchase a single label.
     *
     * @param string $remote_shipment_id Provider's parent shipment identifier.
     * @param string $rate_id            Chosen rate identifier.
     * @param array  $rate               The rate object as returned by get_rates().
     * @param int    $order_id           WooCommerce order ID.
     * @param array  $vas                Value-added services (assoc key => value).
     * @return array|WP_Error
     */
    public function book_shipment( $remote_shipment_id, $rate_id, array $rate, $order_id, array $vas = [] );

    /**
     * Purchase several labels in one request where the provider supports it.
     *
     * @param array $shipments List of shipment descriptors.
     * @return array|WP_Error
     */
    public function book_shipments( array $shipments );

    /**
     * Retrieve a shipment by its provider identifier.
     *
     * @param string $id Provider shipment identifier.
     * @return array|WP_Error
     */
    public function get_shipment( $id );

    /**
     * Cancel or refund a previously purchased label.
     *
     * @param string $id Provider shipment identifier.
     * @return array|WP_Error
     */
    public function refund_shipment( $id );

    /**
     * Fetch a printable label URL for a shipment.
     *
     * Shippo returns signed URLs that expire, so this must always be called
     * rather than relying on a URL stored earlier.
     *
     * @param string $id Provider shipment identifier.
     * @return string|WP_Error Absolute URL to the label PDF.
     */
    public function get_label_url( $id );
}
