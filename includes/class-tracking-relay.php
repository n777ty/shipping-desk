<?php
/**
 * Hands a tracking number to whichever plugin owns tracking on this site.
 *
 * Order of preference, and why:
 *
 *  1. **Advanced Shipment Tracking** — `ast_insert_tracking_number()`. It stores the item in
 *     its own order meta, which feeds its tracking widget in emails and My Account.
 *  2. **WooCommerce Shipment Tracking** — `wc_st_add_tracking_number()`. Same idea, and it
 *     accepts a custom tracking URL for carriers outside its provider list.
 *  3. **Neither** — a *customer* note, the only one of the three that emails the customer.
 *
 * Only one handler is used. Running both would put two items on the order and could email
 * the customer twice; AST wins because it is the more capable of the two.
 *
 * The relay never writes a tracking number into an order note of its own: AST PRO's EasyPost
 * integration parses notes for carrier + tracking and imports what it finds, so a note
 * containing both would add a duplicate item on top of the one just inserted. That is why the
 * private note carries the provider reference only.
 *
 * @package ShippingDesk
 */

defined( 'ABSPATH' ) || exit;

class Shipping_Desk_Tracking_Relay {

	/** Advanced Shipment Tracking took it. */
	const AST = 'ast';

	/** WooCommerce Shipment Tracking took it. */
	const ST = 'st';

	/** No tracking plugin: the customer was told by note. */
	const NOTE = 'note';

	/** Set the order to Completed after a label is bought (AST only). */
	const OPTION_COMPLETE = 'shipping_desk_complete_order_on_label';

	/** Where Shipment Tracking keeps its items. Read-only here. */
	const ST_META = '_wc_shipment_tracking_items';

	/**
	 * True while our shipping note is being saved and its email composed.
	 *
	 * The customer-note email is sent from inside add_order_note(), so this is what tells
	 * the subject/heading filters below that the note going out is ours.
	 */
	protected static $writing_shipping_note = false;

	/**
	 * Attach the email filters. Called once from the main plugin file.
	 *
	 * WooCommerce's customer-note email is titled "A note has been added to your order",
	 * which reads badly for a shipping notice. Only the note we write is renamed; every
	 * other customer note on the site keeps its normal subject.
	 *
	 * @return void
	 */
	public static function boot() {
		add_filter( 'woocommerce_email_subject_customer_note', [ __CLASS__, 'filter_note_subject' ], 10, 3 );
		add_filter( 'woocommerce_email_heading_customer_note', [ __CLASS__, 'filter_note_heading' ], 10, 3 );
	}

	/**
	 * Mark whether the shipping note is being written. Used by record() and by tests.
	 *
	 * @param bool $active State.
	 * @return void
	 */
	public static function mark_shipping_note( $active ) {
		self::$writing_shipping_note = (bool) $active;
	}

	/**
	 * Subject for the shipping note's email.
	 *
	 * @param string   $subject Default subject.
	 * @param WC_Order $order   Order (unused).
	 * @param mixed    $email   Email object (unused).
	 * @return string
	 */
	public static function filter_note_subject( $subject, $order = null, $email = null ) {
		if ( ! self::$writing_shipping_note ) {
			return $subject;
		}

		return __( 'Your order has shipped', 'shipping-desk' );
	}

	/**
	 * Heading for the shipping note's email.
	 *
	 * @param string   $heading Default heading.
	 * @param WC_Order $order   Order (unused).
	 * @param mixed    $email   Email object (unused).
	 * @return string
	 */
	public static function filter_note_heading( $heading, $order = null, $email = null ) {
		if ( ! self::$writing_shipping_note ) {
			return $heading;
		}

		return __( 'Good news: your order is on its way', 'shipping-desk' );
	}

	/**
	 * Which handler would take a tracking number on this site.
	 *
	 * @return string ast|st|note
	 */
	public static function handler() {
		return (string) apply_filters( 'shipping_desk_tracking_handler', self::detect() );
	}

	/**
	 * Detection with no side effects, injectable so the priority order can be tested.
	 *
	 * @param bool|null $has_ast True/false to override detection; null to probe.
	 * @param bool|null $has_st  True/false to override detection; null to probe.
	 * @return string ast|st|note
	 */
	public static function detect( $has_ast = null, $has_st = null ) {
		if ( null === $has_ast ) {
			$has_ast = function_exists( 'ast_insert_tracking_number' );
		}
		if ( null === $has_st ) {
			$has_st = function_exists( 'wc_st_add_tracking_number' );
		}

		if ( $has_ast ) {
			return self::AST;
		}
		if ( $has_st ) {
			return self::ST;
		}

		return self::NOTE;
	}

	/**
	 * The carrier name both tracking plugins match against.
	 *
	 * Providers report carrier *accounts* and raw codes — EasyPost says `UPSDAP` for UPS — so
	 * map the common ones to the names those plugins use, and pass anything else through
	 * untouched. AST resolves a recognised name to its slug; ST does the same.
	 *
	 * @param string $carrier_id Carrier id from the provider.
	 * @return string
	 */
	public static function carrier_name( $carrier_id ) {
		$carrier_id = trim( (string) $carrier_id );
		$lower      = strtolower( $carrier_id );

		if ( '' === $carrier_id ) {
			return 'Carrier';
		}
		if ( false !== strpos( $lower, 'usps' ) ) {
			return 'USPS';
		}
		if ( false !== strpos( $lower, 'ups' ) ) {
			return 'UPS';
		}
		if ( false !== strpos( $lower, 'fedex' ) ) {
			return 'FedEx';
		}
		if ( false !== strpos( $lower, 'dhl' ) ) {
			return 'DHL';
		}

		return $carrier_id;
	}

	/**
	 * Human name for a provider slug, used in the private note.
	 *
	 * @param string $provider shippo|easypost|veeqo
	 * @return string
	 */
	public static function provider_label( $provider ) {
		$labels = [
			'shippo'   => 'Shippo',
			'easypost' => 'EasyPost',
			'veeqo'    => 'Veeqo',
		];

		return isset( $labels[ $provider ] ) ? $labels[ $provider ] : ucfirst( (string) $provider );
	}

	/**
	 * Is this number already on the order, as far as the handler is concerned?
	 *
	 * Stops a reship or a retried request from adding the same tracking twice: both plugins
	 * append rather than replace, so a duplicate is not harmless.
	 *
	 * @param string   $handler ast|st.
	 * @param WC_Order $order   Order.
	 * @param string   $number  Tracking number.
	 * @return bool
	 */
	public static function already_tracked( $handler, WC_Order $order, $number ) {
		$number = strtoupper( trim( (string) $number ) );
		if ( '' === $number ) {
			return false;
		}

		if ( self::AST === $handler && function_exists( 'ast_get_tracking_items' ) ) {
			foreach ( (array) ast_get_tracking_items( $order->get_id() ) as $item ) {
				if ( isset( $item['tracking_number'] ) && strtoupper( trim( (string) $item['tracking_number'] ) ) === $number ) {
					return true;
				}
			}
			return false;
		}

		if ( self::ST === $handler ) {
			// Reading their meta is fine; writing it would bypass their validation.
			foreach ( (array) $order->get_meta( self::ST_META ) as $item ) {
				if ( is_array( $item ) && isset( $item['tracking_number'] ) && strtoupper( trim( (string) $item['tracking_number'] ) ) === $number ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Hand one label's tracking to the site's tracking plugin, or to the customer.
	 *
	 * @param WC_Order $order Order that was shipped.
	 * @param array    $args  number, carrier, url, complete.
	 * @return string The handler that took it, or '' when there was nothing to do.
	 */
	public static function record( WC_Order $order, array $args ) {
		$args = array_merge(
			[
				'number'   => '',
				'carrier'  => '',
				'url'      => '',
				'complete' => (bool) get_option( self::OPTION_COMPLETE, 1 ),
			],
			$args
		);

		$number = trim( (string) $args['number'] );
		if ( '' === $number ) {
			return '';
		}

		$handler = self::handler();
		$carrier = self::carrier_name( $args['carrier'] );

		if ( in_array( $handler, [ self::AST, self::ST ], true ) && self::already_tracked( $handler, $order, $number ) ) {
			// Already there: adding again would duplicate the item, and the customer has
			// been told once already.
			return $handler;
		}

		if ( self::AST === $handler ) {
			// status_shipped 0, with the status change done below instead: the tracking item
			// has to exist before the email renders, and passing 1 leaves that ordering to AST.
			ast_insert_tracking_number( $order->get_id(), $number, $carrier, null, 0 );

			self::maybe_complete( $order, ! empty( $args['complete'] ) );

			return self::AST;
		}

		if ( self::ST === $handler ) {
			// The fifth argument is only used when the provider is not in their list, which
			// is exactly when our own carrier URL saves the link.
			wc_st_add_tracking_number(
				$order->get_id(),
				$number,
				$carrier,
				null,
				'' !== (string) $args['url'] ? (string) $args['url'] : false
			);

			// Shipment Tracking has no status parameter and does not email when a number is
			// added, so completing the order is the only notification an ST shop receives.
			self::maybe_complete( $order, ! empty( $args['complete'] ) );

			return self::ST;
		}

		// No tracking plugin: the customer note is the only way the tracking number reaches
		// the customer, so it is always written. Completing the order afterwards sends a
		// second email — WooCommerce's "Completed order" — which is the price of having no
		// tracking plugin. The note's own subject is renamed by the filters in boot().
		self::mark_shipping_note( true );
		$order->add_order_note( self::public_note( $number, $carrier, (string) $args['url'] ), 1 );
		self::mark_shipping_note( false );

		self::maybe_complete( $order, ! empty( $args['complete'] ) );

		return self::NOTE;
	}

	/**
	 * Take a dead tracking number back out of the tracking plugin.
	 *
	 * A refunded label's number has to go: both plugins append rather than replace, so
	 * the next shipment would leave the void number sitting beside the live one — and
	 * the customer's email lists every item on the order.
	 *
	 * Only the number passed in is removed, matched case-insensitively. An item an
	 * operator added by hand is never touched.
	 *
	 * @param WC_Order   $order   Order.
	 * @param string     $number  Tracking number of the dead label.
	 * @param string|null $handler ast|st|note to override detection; null to probe.
	 * @return array{removed:int,label:string} How many items went, and which plugin held them.
	 */
	public static function forget( WC_Order $order, $number, $handler = null ) {
		$number = strtoupper( trim( (string) $number ) );
		if ( '' === $number ) {
			return [
				'removed' => 0,
				'label'   => '',
			];
		}

		$handler = null === $handler ? self::handler() : (string) $handler;

		if ( self::AST === $handler ) {
			return [
				'removed' => self::forget_ast( $order, $number ),
				'label'   => 'Advanced Shipment Tracking',
			];
		}

		if ( self::ST === $handler ) {
			return [
				'removed' => self::forget_st( $order, $number ),
				'label'   => 'WooCommerce Shipment Tracking',
			];
		}

		// No tracking plugin: the customer was told by order note, and a note cannot be
		// edited. There is nothing to remove.
		return [
			'removed' => 0,
			'label'   => '',
		];
	}

	/**
	 * Remove AST's copy of a tracking number.
	 *
	 * AST has no delete function. Its delete is a method on the actions class — the
	 * same `delete_tracking_item( $order_id, $tracking_id )` its own screens call,
	 * reached through the `get_instance()` its templates use.
	 *
	 * The order is read back afterwards: that method is undocumented here, and
	 * "returned true" is not proof the item left. If the number is still there,
	 * nothing is reported as removed.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $number Uppercased tracking number.
	 * @return int Items removed.
	 */
	protected static function forget_ast( WC_Order $order, $number ) {
		if ( ! function_exists( 'ast_get_tracking_items' ) ) {
			return 0;
		}

		$ids = [];
		foreach ( (array) ast_get_tracking_items( $order->get_id() ) as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['tracking_id'], $item['tracking_number'] ) ) {
				continue;
			}
			if ( strtoupper( trim( (string) $item['tracking_number'] ) ) === $number ) {
				$ids[] = $item['tracking_id'];
			}
		}

		if ( empty( $ids ) ) {
			return 0;
		}

		$removed = 0;
		foreach ( $ids as $tracking_id ) {
			if ( self::ast_delete_item( $order->get_id(), $tracking_id ) ) {
				++$removed;
			}
		}

		return self::ast_still_tracked( $order, $number ) ? 0 : $removed;
	}

	/**
	 * Ask AST to delete one item.
	 *
	 * @param int    $order_id    Order ID.
	 * @param string $tracking_id AST's own id for the item.
	 * @return bool Their method ran and reported success.
	 */
	protected static function ast_delete_item( $order_id, $tracking_id ) {
		$class = 'WC_Advanced_Shipment_Tracking_Actions';
		if ( ! class_exists( $class ) ) {
			return false;
		}

		// Its templates use get_instance(); a build without it is instantiated directly,
		// which is also what those templates do.
		$actions = method_exists( $class, 'get_instance' ) ? call_user_func( [ $class, 'get_instance' ] ) : new $class();
		if ( ! is_object( $actions ) || ! method_exists( $actions, 'delete_tracking_item' ) ) {
			return false;
		}

		return (bool) $actions->delete_tracking_item( (int) $order_id, $tracking_id );
	}

	/**
	 * Is that number still on the order, according to AST's own getter?
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $number Uppercased tracking number.
	 * @return bool
	 */
	protected static function ast_still_tracked( WC_Order $order, $number ) {
		foreach ( (array) ast_get_tracking_items( $order->get_id() ) as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['tracking_number'] ) ) {
				continue;
			}
			if ( strtoupper( trim( (string) $item['tracking_number'] ) ) === $number ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Remove Shipment Tracking's copy of a tracking number.
	 *
	 * Goes through their own class so their bookkeeping stays consistent — this plugin
	 * reads their meta elsewhere precisely because writing it would bypass their
	 * validation. When the method is not there, nothing is removed.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $number Uppercased tracking number.
	 * @return int Items removed.
	 */
	protected static function forget_st( WC_Order $order, $number ) {
		$class = 'WC_Shipment_Tracking_Actions';
		if ( ! class_exists( $class ) ) {
			return 0;
		}

		$actions = null;
		foreach ( [ 'instance', 'get_instance' ] as $factory ) {
			if ( is_callable( [ $class, $factory ] ) ) {
				$actions = call_user_func( [ $class, $factory ] );
				break;
			}
		}

		if ( ! is_object( $actions ) || ! method_exists( $actions, 'delete_tracking_item' ) ) {
			return 0;
		}

		$removed = 0;
		foreach ( (array) $order->get_meta( self::ST_META ) as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['tracking_id'], $item['tracking_number'] ) ) {
				continue;
			}
			if ( strtoupper( trim( (string) $item['tracking_number'] ) ) !== $number ) {
				continue;
			}
			$actions->delete_tracking_item( $order->get_id(), $item['tracking_id'] );
			++$removed;
		}

		return $removed;
	}

	/**
	 * Move an order to Completed, unless the setting is off or it is already there.
	 *
	 * @param WC_Order $order    Order.
	 * @param bool     $complete Whether the setting allows it.
	 * @return void
	 */
	protected static function maybe_complete( WC_Order $order, $complete ) {
		if ( $complete && ! $order->has_status( 'completed' ) ) {
			$order->update_status( 'completed' );
		}
	}

	/**
	 * Customer-facing message, used only when no tracking plugin is installed.
	 *
	 * @param string $number  Tracking number.
	 * @param string $carrier Carrier name.
	 * @param string $url     Tracking URL, may be empty.
	 * @return string Safe HTML.
	 */
	public static function public_note( $number, $carrier, $url ) {
		$number  = (string) $number;
		$carrier = '' !== trim( (string) $carrier ) ? (string) $carrier : __( 'the carrier', 'shipping-desk' );

		// Only http(s) becomes a link. esc_url() would strip the rest on a real site, but a
		// note is stored HTML that other output paths may render, so refuse it here too.
		$url = preg_match( '#^https?://#i', (string) $url ) ? (string) $url : '';

		if ( '' !== $url ) {
			return sprintf(
				/* translators: 1: carrier name, 2: tracking URL, 3: tracking number */
				__( 'Your order has shipped via %1$s. Track it here: <a href="%2$s" target="_blank">%3$s</a>', 'shipping-desk' ),
				esc_html( $carrier ),
				esc_url( $url ),
				esc_html( $number )
			);
		}

		return sprintf(
			/* translators: 1: carrier name, 2: tracking number */
			__( 'Your order has shipped via %1$s. Tracking number: %2$s', 'shipping-desk' ),
			esc_html( $carrier ),
			esc_html( $number )
		);
	}

	/**
	 * Private note: the provider reference and anything else internal, never the tracking
	 * number — see the note at the top of this file about note parsing.
	 *
	 * @param string   $provider_label  EasyPost, Shippo, Veeqo.
	 * @param string   $reference_label e.g. "EasyPost Shipment ID:".
	 * @param string   $reference_value The id itself.
	 * @param string[] $extra           Extra lines, already escaped/HTML.
	 * @return string Safe HTML.
	 */
	public static function private_note( $provider_label, $reference_label, $reference_value, array $extra = [] ) {
		$lines = [
			sprintf(
				/* translators: %s: provider name, e.g. EasyPost */
				esc_html__( 'Label bought via %s.', 'shipping-desk' ),
				esc_html( (string) $provider_label )
			),
		];

		if ( '' !== trim( (string) $reference_value ) ) {
			$lines[] = sprintf( '<strong>%s</strong> %s', esc_html( (string) $reference_label ), esc_html( (string) $reference_value ) );
		}

		foreach ( $extra as $line ) {
			if ( '' !== (string) $line ) {
				$lines[] = (string) $line;
			}
		}

		return implode( '<br>', $lines );
	}
}
