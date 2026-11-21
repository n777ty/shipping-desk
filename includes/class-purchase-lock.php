<?php
/**
 * A per-order lock around buying a label.
 *
 * Buying a label spends money, and the provider calls take 30 seconds or more. "Label
 * already purchased" is only a read of the order's meta, written after the provider answers,
 * so two requests for one order (a double click, two tabs, two staff) both pass that read and
 * both buy. None of the providers accepts an idempotency key, so the plugin has to be the one
 * that says no.
 *
 * What the lock promises:
 *
 *  - Exactly one of two simultaneous requests gets it. The claim is a single INSERT IGNORE on
 *    the options table's unique option_name key. add_option() cannot promise that: it reads
 *    first, then writes with ON DUPLICATE KEY UPDATE, so two requests can both succeed.
 *  - It is released on every way out of the request. The buy handlers leave through
 *    wp_send_json_*(), which dies, so release runs from a shutdown function; and the fatal
 *    error guard calls release_all() itself, because PHP stops running shutdown functions once
 *    one of them calls exit.
 *  - Only the owner releases it. Each lock carries a token, so a request whose lock expired and
 *    was taken over cannot delete the new owner's lock.
 *  - A lock that was never released (the process was killed, so no shutdown function ran) is
 *    abandoned after TTL seconds and can be taken over, by a compare-and-swap so that two
 *    takers cannot both win. TTL is longer than the 180 seconds a purchase asks PHP for, so a
 *    purchase that is merely slow is never taken over.
 *
 * Only four methods touch the database, so a test can replace them with an in-memory store.
 *
 * @package ShippingDesk
 */
class Shipping_Desk_Purchase_Lock {

    /** Seconds after which a lock that was never released counts as abandoned. */
    const TTL = 300;

    /** Option-name prefix; the order id follows. */
    const PREFIX = 'shipping_desk_buy_lock_';

    /**
     * Locks this request holds: order id => the value that proves it is ours.
     *
     * @var array<int,string>
     */
    protected $held = [];

    /** @var bool Whether the shutdown release is registered. */
    protected $registered = false;

    /**
     * Try to take the lock for an order.
     *
     * @param int $order_id Order id.
     * @return bool True when this request now holds it; false when another purchase does.
     */
    public function acquire( $order_id ) {
        $order_id = absint( $order_id );
        if ( ! $order_id ) {
            return false;
        }

        if ( isset( $this->held[ $order_id ] ) ) {
            return true;
        }

        $name  = $this->name( $order_id );
        $value = $this->now() . ':' . $this->token();

        if ( $this->insert_if_absent( $name, $value ) ) {
            return $this->hold( $order_id, $value );
        }

        $current = $this->read( $name );

        if ( null === $current ) {
            // Released between our insert and our read: the lock is free, so ask once more.
            return $this->insert_if_absent( $name, $value ) ? $this->hold( $order_id, $value ) : false;
        }

        if ( $this->age( $current ) >= self::TTL && $this->swap( $name, $current, $value ) ) {
            return $this->hold( $order_id, $value );
        }

        return false;
    }

    /**
     * Whether this request holds the lock for an order.
     *
     * @param int $order_id Order id.
     * @return bool
     */
    public function is_held( $order_id ) {
        return isset( $this->held[ absint( $order_id ) ] );
    }

    /**
     * Release one order's lock, if it is still ours.
     *
     * @param int $order_id Order id.
     * @return void
     */
    public function release( $order_id ) {
        $order_id = absint( $order_id );
        if ( ! isset( $this->held[ $order_id ] ) ) {
            return;
        }

        $this->delete_if( $this->name( $order_id ), $this->held[ $order_id ] );
        unset( $this->held[ $order_id ] );
    }

    /**
     * Release every lock this request holds. Safe to call more than once.
     *
     * Public because it is the shutdown callback, and because the fatal-error guard calls it
     * before it answers.
     *
     * @return void
     */
    public function release_all() {
        foreach ( array_keys( $this->held ) as $order_id ) {
            $this->release( $order_id );
        }
    }

    /**
     * Stop releasing the locks this request holds, and leave them to expire.
     *
     * For the one case where releasing would be wrong: the request died between asking the
     * provider and saving its answer, so nobody knows whether a label was bought. The lock stays
     * until it expires, and a second click cannot buy behind a purchase that may have succeeded.
     *
     * @return void
     */
    public function keep_all() {
        $this->held = [];
    }

    /**
     * Option name for an order.
     *
     * @param int $order_id Order id.
     * @return string
     */
    protected function name( $order_id ) {
        return self::PREFIX . absint( $order_id );
    }

    /**
     * Current time, in seconds. A method so a test can move it.
     *
     * @return int
     */
    protected function now() {
        return time();
    }

    /**
     * A value no other request will pick.
     *
     * @return string
     */
    protected function token() {
        return bin2hex( random_bytes( 8 ) );
    }

    /**
     * How old a stored lock is. A value that cannot be read is treated as abandoned: it cannot
     * be ours, and a lock nobody can date would otherwise block the order for good.
     *
     * @param string $value Stored value, "<seconds>:<token>".
     * @return int Seconds.
     */
    protected function age( $value ) {
        $started = (int) strtok( (string) $value, ':' );

        return $started > 0 ? max( 0, $this->now() - $started ) : PHP_INT_MAX;
    }

    /**
     * Remember a lock this request holds, and make sure it is released however the request ends.
     *
     * @param int    $order_id Order id.
     * @param string $value    The stored value.
     * @return bool Always true, so callers can return it.
     */
    protected function hold( $order_id, $value ) {
        $this->held[ $order_id ] = $value;

        if ( ! $this->registered ) {
            register_shutdown_function( [ $this, 'release_all' ] );
            $this->registered = true;
        }

        return true;
    }

    /* -- The only four places that touch the database -- */

    /**
     * Create the lock row unless it already exists.
     *
     * @param string $name  Option name.
     * @param string $value Value to store.
     * @return bool True when this call created it.
     */
    protected function insert_if_absent( $name, $value ) {
        global $wpdb;

        $rows = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO `{$wpdb->options}` ( `option_name`, `option_value`, `autoload` ) VALUES ( %s, %s, 'no' ) /* shipping desk purchase lock */",
                $name,
                $value
            )
        );

        return 1 === (int) $rows;
    }

    /**
     * Read a lock row straight from the database, never through the options cache.
     *
     * @param string $name Option name.
     * @return string|null Stored value, or null when there is no such row.
     */
    protected function read( $name ) {
        global $wpdb;

        $value = $wpdb->get_var(
            $wpdb->prepare( "SELECT `option_value` FROM `{$wpdb->options}` WHERE `option_name` = %s", $name )
        );

        return null === $value ? null : (string) $value;
    }

    /**
     * Replace one stored value with another only if it is still the value that was read.
     *
     * @param string $name Option name.
     * @param string $old  Value that was read.
     * @param string $new  Value to store.
     * @return bool True when this call made the change.
     */
    protected function swap( $name, $old, $new ) {
        global $wpdb;

        $rows = $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$wpdb->options}` SET `option_value` = %s WHERE `option_name` = %s AND `option_value` = %s",
                $new,
                $name,
                $old
            )
        );

        return 1 === (int) $rows;
    }

    /**
     * Delete a lock row only if it still holds the given value.
     *
     * @param string $name  Option name.
     * @param string $value Value that proves the lock is ours.
     * @return bool True when a row was deleted.
     */
    protected function delete_if( $name, $value ) {
        global $wpdb;

        $rows = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$wpdb->options}` WHERE `option_name` = %s AND `option_value` = %s",
                $name,
                $value
            )
        );

        return 1 === (int) $rows;
    }
}
