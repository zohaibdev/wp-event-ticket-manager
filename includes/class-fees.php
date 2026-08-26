<?php
/**
 * Additional charges applied to an order.
 *
 * A fee is either global or tied to one event, and is either a fixed amount or
 * a percentage of the order subtotal (tickets plus the roommate add-on). Fees
 * are always resolved and calculated on the server.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * CRUD and calculation for order fees.
 */
class ETM_Fees {

	public const TYPE_FIXED      = 'fixed';
	public const TYPE_PERCENTAGE = 'percentage';

	public const SCOPE_ALL   = 'all';
	public const SCOPE_EVENT = 'event';

	/**
	 * Fee types with labels.
	 *
	 * @return array<string, string>
	 */
	public static function types(): array {
		return array(
			self::TYPE_FIXED      => __( 'Fixed amount', 'event-ticket-manager' ),
			self::TYPE_PERCENTAGE => __( 'Percentage of subtotal', 'event-ticket-manager' ),
		);
	}

	/**
	 * Fetch one fee.
	 *
	 * @param int $fee_id Fee ID.
	 * @return object|null
	 */
	public static function get( int $fee_id ): ?object {
		global $wpdb;

		if ( $fee_id <= 0 ) {
			return null;
		}

		$table = ETM_Database::fees_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $fee_id ) );

		return $row ?: null;
	}

	/**
	 * Every fee, for the admin screens.
	 *
	 * @return object[]
	 */
	public static function all(): array {
		global $wpdb;

		$table = ETM_Database::fees_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY sort_order ASC, id ASC" );
	}

	/**
	 * Fees that apply to one event: the global ones plus its own.
	 *
	 * @param int  $event_id     Event ID.
	 * @param bool $only_enabled Skip disabled fees.
	 * @return object[]
	 */
	public static function for_event( int $event_id, bool $only_enabled = true ): array {
		global $wpdb;

		$table  = ETM_Database::fees_table();
		$where  = '( scope = %s OR ( scope = %s AND event_id = %d ) )';
		$params = array( self::SCOPE_ALL, self::SCOPE_EVENT, $event_id );

		if ( $only_enabled ) {
			$where   .= ' AND enabled = %d';
			$params[] = 1;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY sort_order ASC, id ASC", $params )
		);
	}

	/**
	 * Work out what each applicable fee adds to an order.
	 *
	 * Percentages are taken from the order subtotal, meaning tickets plus the
	 * roommate add-on, before any coupon is applied. Every fee is counted once.
	 *
	 * @param int   $event_id Event ID.
	 * @param float $subtotal Order subtotal.
	 * @return array{lines: array<int, array<string, mixed>>, total: float}
	 */
	public static function calculate( int $event_id, float $subtotal ): array {
		$lines = array();
		$total = 0.0;
		$seen  = array();

		foreach ( self::for_event( $event_id ) as $fee ) {
			$fee_id = (int) $fee->id;

			if ( isset( $seen[ $fee_id ] ) ) {
				continue;
			}

			$seen[ $fee_id ] = true;

			$value = max( 0, (float) $fee->fee_value );

			$amount = self::TYPE_PERCENTAGE === $fee->fee_type
				? round( $subtotal * ( $value / 100 ), 2 )
				: round( $value, 2 );

			if ( $amount <= 0 ) {
				continue;
			}

			$lines[] = array(
				'id'     => $fee_id,
				'name'   => (string) $fee->name,
				'type'   => (string) $fee->fee_type,
				'value'  => $value,
				'amount' => $amount,
			);

			$total = round( $total + $amount, 2 );
		}

		/**
		 * Filter the calculated fee lines for an order.
		 *
		 * @param array $lines    Fee lines.
		 * @param int   $event_id Event ID.
		 * @param float $subtotal Order subtotal.
		 */
		$lines = (array) apply_filters( 'etm_fee_lines', $lines, $event_id, $subtotal );

		return array(
			'lines' => $lines,
			'total' => $total,
		);
	}

	/**
	 * Sanitise a submitted fee row.
	 *
	 * @param array<string, mixed> $raw Raw values.
	 * @return array{values: array<string, mixed>, errors: array<string, string>}
	 */
	public static function sanitize( array $raw ): array {
		$errors = array();

		$name = sanitize_text_field( (string) ( $raw['name'] ?? '' ) );

		if ( '' === trim( $name ) ) {
			$errors['name'] = __( 'Fee name is required.', 'event-ticket-manager' );
		}

		$type  = ( isset( $raw['fee_type'] ) && isset( self::types()[ $raw['fee_type'] ] ) ) ? $raw['fee_type'] : self::TYPE_FIXED;
		$value = $raw['fee_value'] ?? '';

		if ( '' === trim( (string) $value ) || ! is_numeric( $value ) || (float) $value < 0 ) {
			$errors['fee_value'] = __( 'Fee value must be zero or greater.', 'event-ticket-manager' );
		}

		$scope    = ( isset( $raw['scope'] ) && self::SCOPE_EVENT === $raw['scope'] ) ? self::SCOPE_EVENT : self::SCOPE_ALL;
		$event_id = self::SCOPE_EVENT === $scope ? absint( $raw['event_id'] ?? 0 ) : 0;

		if ( self::SCOPE_EVENT === $scope && $event_id <= 0 ) {
			$errors['event_id'] = __( 'Choose the event this fee belongs to.', 'event-ticket-manager' );
		}

		return array(
			'values' => array(
				'name'       => $name,
				'fee_type'   => $type,
				'fee_value'  => round( max( 0, (float) $value ), 2 ),
				'scope'      => $scope,
				'event_id'   => $event_id,
				'enabled'    => empty( $raw['enabled'] ) ? 0 : 1,
				'sort_order' => absint( $raw['sort_order'] ?? 0 ),
			),
			'errors' => $errors,
		);
	}

	/**
	 * Insert a fee.
	 *
	 * @param array<string, mixed> $values Sanitised values.
	 * @return int|WP_Error
	 */
	public static function create( array $values ) {
		global $wpdb;

		$now = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			ETM_Database::fees_table(),
			array_merge( $values, array( 'created_at' => $now, 'updated_at' => $now ) ),
			array( '%s', '%s', '%f', '%s', '%d', '%d', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'etm_fee_insert_failed', __( 'The fee could not be saved.', 'event-ticket-manager' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a fee.
	 *
	 * @param int                  $fee_id Fee ID.
	 * @param array<string, mixed> $values Sanitised values.
	 * @return bool
	 */
	public static function update( int $fee_id, array $values ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update(
			ETM_Database::fees_table(),
			array_merge( $values, array( 'updated_at' => current_time( 'mysql' ) ) ),
			array( 'id' => $fee_id ),
			array( '%s', '%s', '%f', '%s', '%d', '%d', '%d', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Delete a fee. Bookings keep their own snapshot.
	 *
	 * @param int $fee_id Fee ID.
	 * @return bool
	 */
	public static function delete( int $fee_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->delete( ETM_Database::fees_table(), array( 'id' => $fee_id ), array( '%d' ) );
	}

	/**
	 * Remove every event-specific fee for an event.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function delete_for_event( int $event_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete(
			ETM_Database::fees_table(),
			array( 'scope' => self::SCOPE_EVENT, 'event_id' => $event_id ),
			array( '%s', '%d' )
		);
	}
}
