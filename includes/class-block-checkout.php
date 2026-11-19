<?php
/**
 * Address standardisation on the block checkout.
 *
 * The block checkout is a React app talking to the Store API. It never fires
 * `updated_checkout`, never renders `#shipping_address_1`, and has nowhere to put a
 * suggestion box without shipping a JavaScript build — so the offer the classic
 * checkout makes in the browser is made here instead, on the server, at the one
 * moment the block checkout gives us: order creation.
 *
 * The policy is the same one the other two surfaces share, applied from the weaker
 * position this one has:
 *
 *  - an *immaterial* correction (a ZIP+4, a reformatted street, a suite folded into
 *    line 1) is written to the order, with an order note saying what changed and
 *    that nobody confirmed it. It cannot move the parcel, and leaving it means the
 *    order and the label describe different addresses - the mismatch that made this
 *    plugin worth writing;
 *  - a *material* correction, or an invalid address, is left exactly as entered.
 *    That is a decision about where a parcel goes, and it stays the operator's: the
 *    orders screen asks before it will quote (see validation_needs_decision()).
 *
 * Nothing here blocks a checkout, throws, or adds a Store API error. No note is
 * written unless the address actually changed. One verifier call per order, cached
 * for 90 days under the same address-book key the classic endpoint uses, so a
 * customer who checks out twice pays once.
 *
 * Why not a real block integration: that is a React package built against
 * `@woocommerce/extend-cart-checkout-block` - a build chain this plugin deliberately
 * has none of, and one more thing to keep working every time the block checkout
 * changes. The server path buys the outcome, a good address on the order, without
 * any of it. See docs/checkout-validation.md.
 *
 * @package ShippingDesk
 */

class Shipping_Desk_Block_Checkout {

	/**
	 * Order meta: this order's address has already had its one look.
	 *
	 * The Store API fires more than one hook around order creation, and a second
	 * verification would be a second paid call for the same answer.
	 */
	const DONE_META = '_shipping_desk_block_address_checked';

	/** @var Shipping_Desk_Plugin */
	protected $plugin;

	/**
	 * @param Shipping_Desk_Plugin $plugin The plugin, for the setting and the verifier.
	 */
	public function __construct( Shipping_Desk_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hook the two Store API points where an order exists and can still be changed.
	 *
	 * `update_order_from_request` runs before payment, `order_processed` after it.
	 * Both are registered because the first is the one that matters and the second is
	 * the backstop for a WooCommerce version that does not fire it; the meta flag
	 * makes the second a no-op when the first has already acted.
	 */
	public function init() {
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'standardise_order' ], 10, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'standardise_order' ], 10, 1 );
	}

	/**
	 * Is the site's checkout page the block one?
	 *
	 * Read from the page content, because that is what decides it: a theme does not
	 * choose the checkout, and Storefront - WooCommerce's own theme - is a classic
	 * one. Used for the settings note, so a store is never told suggestions are
	 * running when its checkout has nowhere to show them.
	 *
	 * @param int|null $page_id Page to inspect; defaults to the WooCommerce checkout page.
	 * @return bool
	 */
	public static function is_block_checkout( $page_id = null ) {
		if ( ! function_exists( 'wc_get_page_id' ) || ! function_exists( 'has_block' ) || ! function_exists( 'get_post' ) ) {
			return false;
		}

		if ( null === $page_id ) {
			$page_id = (int) wc_get_page_id( 'checkout' );
		}

		if ( $page_id <= 0 ) {
			return false;
		}

		$page = get_post( (int) $page_id );

		return is_object( $page ) && has_block( 'woocommerce/checkout', $page );
	}

	/**
	 * Standardise an order's shipping address, once, without asking anybody.
	 *
	 * @param WC_Order    $order   Order the Store API has just built.
	 * @param mixed       $request The Store API request; unused, present for the hook signature.
	 * @param object|null $client  Verifier client, for tests; the plugin's own when null.
	 * @return array What happened: status, applied, changed, note.
	 */
	public function standardise_order( $order, $request = null, $client = null ) {
		$skipped = [ 'status' => 'skipped', 'applied' => false, 'changed' => [], 'note' => '' ];

		if ( ! is_object( $order ) || ! method_exists( $order, 'get_shipping_address_1' ) ) {
			return $skipped;
		}

		if ( '' !== (string) $order->get_meta( self::DONE_META ) ) {
			return [ 'status' => 'already_checked', 'applied' => false, 'changed' => [], 'note' => '' ];
		}

		if ( ! $this->plugin->checkout_validation_enabled() ) {
			return $skipped;
		}

		$address = $this->ordered_address( $order );
		if ( empty( $address ) ) {
			return $skipped;
		}

		if ( ! Shipping_Desk_Helpers::is_supported_destination( $address['country_code'] ) ) {
			return $skipped;
		}

		$validator = null === $client ? $this->plugin->validator_client() : [ 'easypost', $client ];
		if ( null === $validator ) {
			return $skipped;
		}

		list( $name, $validator_client ) = $validator;

		/**
		 * Filters whether an immaterial correction may be written to a block-checkout
		 * order without the customer seeing it. False leaves every order exactly as
		 * entered, which is the 1.4.x behaviour on this checkout.
		 *
		 * @param bool     $allow Whether to standardise.
		 * @param WC_Order $order Order being created.
		 */
		if ( true !== apply_filters( 'shipping_desk_block_checkout_standardise', true, $order ) ) {
			$order->update_meta_data( self::DONE_META, 'refused' );
			$order->save();

			return [ 'status' => 'skipped', 'applied' => false, 'changed' => [], 'note' => 'filtered' ];
		}

		$result = $this->verify( $address, $name, $validator_client );

		$out = [
			'status'  => (string) $result['status'],
			'applied' => false,
			'changed' => [],
			'note'    => '',
		];

		if ( 'corrected' === $out['status'] && ! empty( $result['recommended'] ) ) {
			if ( Shipping_Desk_Helpers::address_change_is_material( $address, $result['recommended'] ) ) {
				// Somewhere else. The orders screen will ask; a customer who cannot be
				// asked must not have their parcel sent to a guess.
				$out['status'] = 'material';
			} else {
				$after          = Shipping_Desk_Helpers::apply_address_correction( $address, $result['recommended'] );
				$out['changed'] = $this->write_address( $order, $after );

				if ( ! empty( $out['changed'] ) ) {
					$out['status']  = 'applied';
					$out['applied'] = true;
					$out['note']    = $this->note_text( $name, $address, $after, $result );
					$order->add_order_note( $out['note'] );
				}
			}
		}

		/*
		 * One look per order, whatever the answer: both hooks fire, and an answer that
		 * was wrong a millisecond ago will not be different now. The meta records what
		 * was *done*, not only what the verifier said, so an operator reading the order
		 * can tell "checked and written" from "checked and left for me".
		 */
		$order->update_meta_data( self::DONE_META, $out['status'] );

		// A decision the customer was never asked about has to be visible, or "the
		// plugin decided not to act" and "the plugin never ran" look identical on the
		// order - which is exactly how this was reported. A clean address still writes
		// nothing at all.
		if ( in_array( $out['status'], [ 'material', 'invalid' ], true ) ) {
			$order->add_order_note( $this->decision_note( $out['status'], $name, $address, $result ) );
		}

		$order->save();

		return $out;
	}

	/**
	 * The order's shipping address, in the shape the helpers and verifiers use.
	 *
	 * Empty when the order has no shipping address of its own: an order that ships to
	 * the billing address has nothing here to standardise, and inventing one would
	 * change where the parcel goes.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	protected function ordered_address( $order ) {
		$address = [
			'line1'        => trim( (string) $order->get_shipping_address_1() ),
			'line2'        => trim( (string) $order->get_shipping_address_2() ),
			'town'         => trim( (string) $order->get_shipping_city() ),
			'county'       => trim( (string) $order->get_shipping_state() ),
			'postcode'     => trim( (string) $order->get_shipping_postcode() ),
			'country_code' => strtoupper( trim( (string) $order->get_shipping_country() ) ),
		];

		if ( '' === $address['line1'] || '' === $address['postcode'] ) {
			return [];
		}

		return $address;
	}

	/**
	 * Ask the verifier, through the shared address book.
	 *
	 * The same transient key the classic endpoint uses, so an address a browser
	 * already checked is free here and vice versa. `unavailable` is never cached: an
	 * outage is not a fact about the address.
	 *
	 * @param array  $address Address as entered.
	 * @param string $name    Verifier name, for the wording.
	 * @param object $client  Client with validate_address().
	 * @return array Normalised result.
	 */
	protected function verify( array $address, $name, $client ) {
		$key    = Shipping_Desk_Checkout_Address::address_book_key( $address );
		$cached = get_transient( $key );

		if ( is_array( $cached ) && isset( $cached['status'] ) ) {
			return $this->rejudge( $cached, $address );
		}

		$result             = $client->validate_address( $address );
		$result['original'] = $address;
		$result             = Shipping_Desk_Helpers::describe_address_validation( $result, $name );

		if ( 'unavailable' !== ( isset( $result['status'] ) ? $result['status'] : '' ) ) {
			set_transient( $key, $result, Shipping_Desk_Checkout_Address::address_book_ttl() );
		}

		return $result;
	}

	/**
	 * Re-judge a cached answer against the address actually in hand.
	 *
	 * The book stores what a verifier said about a *place*; the verdict and the
	 * wording belong to the question. An entry written from the standardised form
	 * would otherwise come back `valid` for the customer's own spelling, and the
	 * correction would be skipped without anyone noticing.
	 *
	 * @param array $cached  Stored result.
	 * @param array $address Address as entered now.
	 * @return array
	 */
	protected function rejudge( array $cached, array $address ) {
		$status = isset( $cached['status'] ) ? (string) $cached['status'] : '';

		if ( ! in_array( $status, [ 'valid', 'corrected' ], true ) ) {
			return $cached;
		}

		$recommended = isset( $cached['recommended'] ) && is_array( $cached['recommended'] ) ? $cached['recommended'] : [];
		$changed     = Shipping_Desk_Helpers::address_changes_between( $address, $recommended );

		$cached['status']  = empty( $changed ) ? 'valid' : 'corrected';
		$cached['changed'] = $changed;
		$cached['original'] = $address;
		$cached['summary'] = implode( ', ', Shipping_Desk_Helpers::describe_address_changes( $changed ) );
		$cached['apply']   = empty( $recommended ) ? [] : Shipping_Desk_Helpers::apply_address_correction( $address, $recommended );
		$cached['cached']  = true;

		return $cached;
	}

	/**
	 * Write the standardised address onto the order.
	 *
	 * Only the shipping address: billing is what the customer entered and what the
	 * gateway checked, so rewriting it would break the AVS trail for no benefit.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $after Address to write.
	 * @return array Field names that changed.
	 */
	protected function write_address( $order, array $after ) {
		$properties = [
			'line1'        => 'shipping_address_1',
			'line2'        => 'shipping_address_2',
			'town'         => 'shipping_city',
			'county'       => 'shipping_state',
			'postcode'     => 'shipping_postcode',
			'country_code' => 'shipping_country',
		];

		$getters = [
			'line1'        => 'get_shipping_address_1',
			'line2'        => 'get_shipping_address_2',
			'town'         => 'get_shipping_city',
			'county'       => 'get_shipping_state',
			'postcode'     => 'get_shipping_postcode',
			'country_code' => 'get_shipping_country',
		];

		$changed = [];
		foreach ( $properties as $key => $property ) {
			$getter = $getters[ $key ];
			$setter = 'set_' . $property;

			$before = (string) $order->{ $getter }();
			$value  = isset( $after[ $key ] ) ? (string) $after[ $key ] : '';

			if ( $before === $value ) {
				continue;
			}

			$order->{ $setter }( $value );
			$changed[] = $key;
		}

		if ( ! empty( $changed ) ) {
			$order->save();
		}

		return $changed;
	}

	/**
	 * The note for a verdict that changed nothing.
	 *
	 * The block checkout cannot ask the customer, so a "left for you" decision has to
	 * say so on the order. Without it an operator cannot tell a correction that was
	 * deliberately withheld from a plugin that never ran - the two look identical.
	 *
	 * @param string $status  'material' or 'invalid'.
	 * @param string $name    Verifier name.
	 * @param array  $address Address as entered.
	 * @param array  $result  Normalised verification.
	 * @return string
	 */
	protected function decision_note( $status, $name, array $address, array $result ) {
		$source = 'easypost' === $name ? 'EasyPost' : 'Shippo';

		if ( 'invalid' === $status ) {
			return sprintf(
				/* translators: 1: verifier name, 2: address as entered */
				__( 'Address check on the block checkout: %1$s could not confirm %2$s. Nothing was changed; the orders screen shows the problem before a label is bought.', 'shipping-desk' ),
				$source,
				Shipping_Desk_Helpers::format_address( $address )
			);
		}

		$recommended = isset( $result['recommended'] ) && is_array( $result['recommended'] ) ? $result['recommended'] : [];
		$after       = empty( $recommended ) ? $address : Shipping_Desk_Helpers::apply_address_correction( $address, $recommended );
		$fields      = isset( $result['changed'] ) && is_array( $result['changed'] ) ? $result['changed'] : [];
		$summary     = implode( ', ', Shipping_Desk_Helpers::describe_address_changes( $fields ) );

		return sprintf(
			/* translators: 1: verifier name, 2: suggested address, 3: address as entered, 4: what would change */
			__( 'Address check on the block checkout: %1$s suggests %2$s instead of what was entered, %3$s (%4$s). The change could move the parcel, so nothing was applied and the customer was not asked; the orders screen asks before quoting.', 'shipping-desk' ),
			$source,
			Shipping_Desk_Helpers::format_address( $after ),
			Shipping_Desk_Helpers::format_address( $address ),
			'' === $summary ? __( 'standardised', 'shipping-desk' ) : $summary
		);
	}

	/**
	 * The note an operator reads on the order.
	 *
	 * It has to say two things a normal correction note does not: that this was not
	 * the customer's decision, and why the machine was allowed to make it.
	 *
	 * @param string $name   Verifier name.
	 * @param array  $before Address as entered.
	 * @param array  $after  Address as written.
	 * @param array  $result Normalised verification.
	 * @return string
	 */
	protected function note_text( $name, array $before, array $after, array $result ) {
		$fields = isset( $result['changed'] ) && is_array( $result['changed'] ) ? $result['changed'] : [];
		$folded = '' !== (string) $before['line2'] && '' === (string) $after['line2'];

		if ( $folded && ! in_array( 'address_line_2_folded', $fields, true ) ) {
			$fields[] = 'address_line_2_folded';
		}

		$summary = implode( ', ', Shipping_Desk_Helpers::describe_address_changes( $fields ) );
		if ( '' === $summary ) {
			$summary = __( 'standardised', 'shipping-desk' );
		}

		$source = 'easypost' === $name ? 'EasyPost' : 'Shippo';

		return sprintf(
			/* translators: 1: verifier name, 2: previous address, 3: verified address, 4: what changed */
			__( 'Shipping address standardised from %1$s verification: %2$s → %3$s (%4$s). The block checkout has no confirmation step, so this was applied automatically; it is the same delivery point, and the label now matches the order.', 'shipping-desk' ),
			$source,
			Shipping_Desk_Helpers::format_address( $before ),
			Shipping_Desk_Helpers::format_address( $after ),
			$summary
		);
	}
}