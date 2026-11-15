<?php
/**
 * Optional address-validation contract.
 *
 * Deliberately **not** part of Shipping_Desk_Provider_Interface. Validation is
 * not quoting or buying, Veeqo has no equivalent endpoint, and a provider that
 * cannot validate should not be forced to pretend it can. Callers test for this
 * interface with instanceof, so Veeqo simply never validates.
 *
 * validate_address() must never fail a caller: an unreachable, throttled or
 * unprovisioned validator is reported as `unavailable`, never as WP_Error. A
 * validator outage must not stop a label being bought — a bad address on a
 * label is a far smaller problem than no label at all.
 *
 * Normalised result:
 *
 *   [
 *     'status'       => 'valid' | 'corrected' | 'invalid' | 'unavailable',
 *     'is_valid'     => bool,     // true for 'valid' and 'corrected'
 *     'recommended'  => array,    // plugin-shaped address, or [] when none
 *     'changed'      => string[], // Shippo field names that differ, e.g. postal_code
 *     'reasons'      => array,    // [ ['code'=>..,'type'=>..,'description'=>..] ]
 *     'address_type' => 'commercial' | 'residential' | '',
 *     'confidence'   => [ 'score' => .., 'code' => .., 'description' => .. ],
 *     'error'        => string,   // why the status is 'unavailable'
 *   ]
 *
 * `recommended` is populated whenever the validator returns one, including for
 * 'valid' addresses (it is then the standardised form). Apply it only when
 * `status` is 'corrected'.
 */
interface Shipping_Desk_Address_Validator_Interface {

    /**
     * Validate and, where possible, standardise an address.
     *
     * @param array $address Plugin address array: line1, line2, town, county,
     *                       postcode, country_code, name, phone, email.
     * @return array Normalised result, as described above.
     */
    public function validate_address( array $address );
}
