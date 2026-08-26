<?php
/**
 * In-flight checkout orders.
 *
 * A booking row is only ever written once money has been taken. Until then the
 * validated order lives here: it holds the seats so the event cannot be
 * oversold while the customer is typing their card, and it carries the details
 * needed to build the booking the moment the payment is verified.
 *
 * Rows are short lived and are purged by the hourly cleanup task.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Storage for orders that have not been paid for yet.
 */
class ETM_Pending_Orders {

	/**
	 * Create a holding record and return its token.
	 *
	 * @param int                  $event_id Event ID.
	 * @param int                  $seats    Capacity units this order consumes.
	 * @param array<string, mixed> $payload  Validated order data.
	 * @param float                $total    Order total.
	 * @param string               $currency Currency code.
	 * @return string|WP_Error Token.
	 */
	public static function create( int $event_id, int $seats, array $payload, float $total, string $currency ) {
		global $wpdb;

		$token = self::generate_token();
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			ETM_Database::pending_orders_table(),
			array(
				'token'      => $token,
				'event_id'   => $event_id,
				'seats'      => max( 0, $seats ),
				'payload'    => (string) wp_json_encode( $payload ),
				'total'      => round( $total, 2 ),
				'currency'   => strtoupper( $currency ),
				'created_at' => $now,
				'expires_at' => self::expiry_datetime(),
			),
			array( '%s', '%d', '%d', '%s', '%f', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'etm_pending_insert_failed', __( 'The order could not be started.', 'event-ticket-manager' ) );
		}

		return $token;
	}

	/**
	 * Update a holding record, refreshing its expiry.
	 *
	 * @param string               $token    Token.
	 * @param int                  $seats    Capacity units.
	 * @param array<string, mixed> $payload  Validated order data.
	 * @param float                $total    Order total.
	 * @param string               $currency Currency code.
	 * @return bool
	 */
	public static function update( string $token, int $seats, array $payload, float $total, string $currency ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update(
			ETM_Database::pending_orders_table(),
			array(
				'seats'      => max( 0, $seats ),
				'payload'    => (string) wp_json_encode( $payload ),
				'total'      => round( $total, 2 ),
				'currency'   => strtoupper( $currency ),
				'expires_at' => self::expiry_datetime(),
			),
			array( 'token' => $token ),
			array( '%d', '%s', '%f', '%s', '%s' ),
			array( '%s' )
		);

		return false !== $result;
	}

	/**
	 * Attach the Stripe PaymentIntent to a holding record.
	 *
	 * @param string $token     Token.
	 * @param string $intent_id PaymentIntent ID.
	 * @return bool
	 */
	public static function set_payment_intent( string $token, string $intent_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update(
			ETM_Database::pending_orders_table(),
			array( 'stripe_payment_intent_id' => sanitize_text_field( $intent_id ) ),
			array( 'token' => $token ),
			array( '%s' ),
			array( '%s' )
		);

		return false !== $result;
	}

	/**
	 * Fetch a holding record by token.
	 *
	 * @param string $token Token.
	 * @return object|null
	 */
	public static function get( string $token ): ?object {
		global $wpdb;

		$token = self::sanitize_token( $token );

		if ( '' === $token ) {
			return null;
		}

		$table = ETM_Database::pending_orders_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s LIMIT 1", $token ) );

		return $row ?: null;
	}

	/**
	 * Fetch a holding record by PaymentIntent.
	 *
	 * @param string $intent_id PaymentIntent ID.
	 * @return object|null
	 */
	public static function get_by_payment_intent( string $intent_id ): ?object {
		global $wpdb;

		$intent_id = sanitize_text_field( $intent_id );

		if ( '' === $intent_id ) {
			return null;
		}

		$table = ETM_Database::pending_orders_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE stripe_payment_intent_id = %s ORDER BY id DESC LIMIT 1", $intent_id )
		);

		return $row ?: null;
	}

	/**
	 * Decode the stored order payload.
	 *
	 * @param object $pending Holding record.
	 * @return array<string, mixed>
	 */
	public static function payload( object $pending ): array {
		$decoded = json_decode( (string) $pending->payload, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Delete a holding record.
	 *
	 * @param string $token Token.
	 * @return bool
	 */
	public static function delete( string $token ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->delete( ETM_Database::pending_orders_table(), array( 'token' => $token ), array( '%s' ) );
	}

	/**
	 * Seats currently held by unexpired orders for an event.
	 *
	 * @param int    $event_id      Event ID.
	 * @param string $exclude_token Token to leave out, when amending an order.
	 * @return int
	 */
	public static function seats_held( int $event_id, string $exclude_token = '' ): int {
		global $wpdb;

		$table  = ETM_Database::pending_orders_table();
		$now    = current_time( 'mysql' );
		$params = array( $event_id, $now );
		$clause = '';

		$exclude_token = self::sanitize_token( $exclude_token );

		if ( '' !== $exclude_token ) {
			$clause   = ' AND token <> %s';
			$params[] = $exclude_token;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(seats), 0) FROM {$table} WHERE event_id = %d AND expires_at > %s{$clause}",
				$params
			)
		);
	}

	/**
	 * Quantity of one ticket type currently held by unexpired checkouts.
	 *
	 * @param int    $event_id       Event ID.
	 * @param int    $ticket_type_id Ticket type ID.
	 * @param string $exclude_token  Hold to leave out, when amending an order.
	 * @return int
	 */
	public static function quantity_held_for_type( int $event_id, int $ticket_type_id, string $exclude_token = '' ): int {
		global $wpdb;

		$table         = ETM_Database::pending_orders_table();
		$now           = current_time( 'mysql' );
		$exclude_token = self::sanitize_token( $exclude_token );

		$params = array( $event_id, $now );
		$clause = '';

		if ( '' !== $exclude_token ) {
			$clause   = ' AND token <> %s';
			$params[] = $exclude_token;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$payloads = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT payload FROM {$table} WHERE event_id = %d AND expires_at > %s{$clause}",
				$params
			)
		);

		$held = 0;

		foreach ( $payloads as $payload ) {
			$decoded = json_decode( (string) $payload, true );

			if ( ! is_array( $decoded ) || empty( $decoded['lines'] ) ) {
				continue;
			}

			foreach ( (array) $decoded['lines'] as $line ) {
				if ( (int) ( $line['ticket_type_id'] ?? 0 ) === $ticket_type_id ) {
					$held += (int) ( $line['quantity'] ?? 0 );
				}
			}
		}

		return $held;
	}

	/**
	 * Remove expired holds, cancelling any unpaid Stripe intent behind them.
	 *
	 * @return int Number of holds released.
	 */
	public static function purge_expired(): int {
		global $wpdb;

		$table = ETM_Database::pending_orders_table();
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT token, stripe_payment_intent_id FROM {$table} WHERE expires_at < %s LIMIT 500", $now )
		);

		foreach ( $rows as $row ) {
			/**
			 * Filter whether an expired hold also cancels its Stripe intent.
			 *
			 * @param bool $cancel Defaults to true.
			 */
			if ( ! empty( $row->stripe_payment_intent_id ) && apply_filters( 'etm_cancel_expired_intents', true ) ) {
				// Releasing the seats without voiding the intent would let a
				// stale payment succeed for a place that is no longer held.
				ETM_Stripe::cancel_payment_intent( (string) $row->stripe_payment_intent_id );
			}

			self::delete( (string) $row->token );
		}

		return count( $rows );
	}

	/**
	 * When a hold created now should expire.
	 *
	 * @return string
	 */
	private static function expiry_datetime(): string {
		return gmdate(
			'Y-m-d H:i:s',
			strtotime( current_time( 'mysql' ) ) + ( ETM_Capacity::hold_minutes() * MINUTE_IN_SECONDS )
		);
	}

	/**
	 * Generate an unguessable token.
	 *
	 * @return string
	 */
	private static function generate_token(): string {
		return wp_generate_password( 40, false, false );
	}

	/**
	 * Restrict a token to the character set we generate.
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	public static function sanitize_token( string $token ): string {
		$token = preg_replace( '/[^A-Za-z0-9]/', '', $token );

		return ( is_string( $token ) && strlen( $token ) >= 20 ) ? substr( $token, 0, 64 ) : '';
	}

	/**
	 * Constant-time token comparison.
	 *
	 * @param string $stored    Stored token.
	 * @param string $candidate Supplied token.
	 * @return bool
	 */
	public static function token_matches( string $stored, string $candidate ): bool {
		if ( '' === $stored || '' === $candidate ) {
			return false;
		}

		return hash_equals( $stored, $candidate );
	}
}
