<?php
/**
 * Discount coupons.
 *
 * Codes are matched case-insensitively and validated entirely on the server:
 * the browser only ever sends a code, never a discount amount.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * CRUD, validation and discount calculation for coupons.
 */
class ETM_Coupons {

	public const TYPE_PERCENTAGE = 'percentage';
	public const TYPE_FIXED      = 'fixed';

	public const SCOPE_ALL      = 'all';
	public const SCOPE_SPECIFIC = 'specific';

	/**
	 * Discount types with labels.
	 *
	 * @return array<string, string>
	 */
	public static function types(): array {
		return array(
			self::TYPE_PERCENTAGE => __( 'Percentage', 'event-ticket-manager' ),
			self::TYPE_FIXED      => __( 'Fixed amount', 'event-ticket-manager' ),
		);
	}

	/**
	 * Fetch one coupon.
	 *
	 * @param int $coupon_id Coupon ID.
	 * @return object|null
	 */
	public static function get( int $coupon_id ): ?object {
		global $wpdb;

		if ( $coupon_id <= 0 ) {
			return null;
		}

		$table = ETM_Database::coupons_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $coupon_id ) );

		return $row ?: null;
	}

	/**
	 * Fetch a coupon by its code, ignoring case.
	 *
	 * @param string $code Coupon code.
	 * @return object|null
	 */
	public static function get_by_code( string $code ): ?object {
		global $wpdb;

		$code = self::normalise_code( $code );

		if ( '' === $code ) {
			return null;
		}

		$table = ETM_Database::coupons_table();

		// Codes are stored normalised, so a plain comparison is case-insensitive.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s LIMIT 1", $code ) );

		return $row ?: null;
	}

	/**
	 * Every coupon, for the admin list.
	 *
	 * @return object[]
	 */
	public static function all(): array {
		global $wpdb;

		$table = ETM_Database::coupons_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC" );
	}

	/**
	 * Upper-case, trimmed code used for both storage and lookup.
	 *
	 * @param string $code Raw code.
	 * @return string
	 */
	public static function normalise_code( string $code ): string {
		$code = strtoupper( trim( sanitize_text_field( $code ) ) );

		return substr( (string) preg_replace( '/[^A-Z0-9_-]/', '', $code ), 0, 64 );
	}

	/**
	 * Event IDs a coupon is restricted to.
	 *
	 * @param object $coupon Coupon row.
	 * @return int[]
	 */
	public static function event_ids( object $coupon ): array {
		$decoded = json_decode( (string) $coupon->event_ids, true );

		return is_array( $decoded ) ? array_map( 'absint', $decoded ) : array();
	}

	/**
	 * How many confirmed bookings have used a code.
	 *
	 * @param string $code           Coupon code.
	 * @param string $customer_email Optional email to count for one customer.
	 * @return int
	 */
	public static function usage_count( string $code, string $customer_email = '' ): int {
		global $wpdb;

		$code = self::normalise_code( $code );

		if ( '' === $code ) {
			return 0;
		}

		$bookings = ETM_Database::bookings_table();
		$statuses = ETM_Bookings::confirmed_statuses();

		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$params       = array_merge( array( $code ), $statuses );
		$clause       = '';

		if ( '' !== $customer_email ) {
			$clause   = ' AND customer_email = %s';
			$params[] = sanitize_email( $customer_email );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$bookings} WHERE coupon_code = %s AND payment_status IN ({$placeholders}){$clause}",
				$params
			)
		);
	}

	/**
	 * Check a code against an event and return the coupon when it may be used.
	 *
	 * @param string $code           Coupon code.
	 * @param int    $event_id       Event the order belongs to.
	 * @param string $customer_email Customer email, for the per-customer limit.
	 * @return object|WP_Error
	 */
	public static function validate( string $code, int $event_id, string $customer_email = '' ) {
		$code   = self::normalise_code( $code );
		$coupon = '' !== $code ? self::get_by_code( $code ) : null;

		if ( ! $coupon ) {
			return new WP_Error( 'etm_coupon_unknown', __( 'That coupon code is not valid.', 'event-ticket-manager' ) );
		}

		if ( empty( $coupon->enabled ) ) {
			return new WP_Error( 'etm_coupon_disabled', __( 'That coupon is no longer active.', 'event-ticket-manager' ) );
		}

		$now = current_time( 'mysql' );

		if ( ! empty( $coupon->start_date ) && $coupon->start_date > $now ) {
			return new WP_Error( 'etm_coupon_early', __( 'That coupon is not active yet.', 'event-ticket-manager' ) );
		}

		if ( ! empty( $coupon->end_date ) && $coupon->end_date < $now ) {
			return new WP_Error( 'etm_coupon_expired', __( 'That coupon has expired.', 'event-ticket-manager' ) );
		}

		if ( self::SCOPE_SPECIFIC === $coupon->event_scope ) {
			$allowed = self::event_ids( $coupon );

			if ( ! in_array( $event_id, $allowed, true ) ) {
				return new WP_Error( 'etm_coupon_event', __( 'That coupon cannot be used for this event.', 'event-ticket-manager' ) );
			}
		}

		$usage_limit = (int) $coupon->usage_limit;

		if ( $usage_limit > 0 && self::usage_count( $code ) >= $usage_limit ) {
			return new WP_Error( 'etm_coupon_used_up', __( 'That coupon has reached its usage limit.', 'event-ticket-manager' ) );
		}

		$per_customer = (int) $coupon->per_customer_limit;

		if ( $per_customer > 0 && '' !== $customer_email && self::usage_count( $code, $customer_email ) >= $per_customer ) {
			return new WP_Error( 'etm_coupon_customer_limit', __( 'You have already used that coupon.', 'event-ticket-manager' ) );
		}

		return $coupon;
	}

	/**
	 * The discount a coupon takes off an order.
	 *
	 * Percentages are taken from the subtotal (tickets plus roommate add-on) and
	 * a fixed discount can never exceed it, so the discount alone cannot push an
	 * order below zero.
	 *
	 * @param object|null $coupon   Coupon row.
	 * @param float       $subtotal Order subtotal.
	 * @return float
	 */
	/**
	 * Whether coupons are offered at checkout at all.
	 *
	 * A visibility switch, not a delete: coupon records, their usage history
	 * and any code already stored against a past booking are untouched by it.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return (bool) ETM_Settings::general( 'coupon_enabled', 1 );
	}

	public static function discount_for( ?object $coupon, float $subtotal ): float {
		if ( ! $coupon || $subtotal <= 0 ) {
			return 0.0;
		}

		$value = max( 0, (float) $coupon->discount_value );

		$discount = self::TYPE_PERCENTAGE === $coupon->discount_type
			? round( $subtotal * ( $value / 100 ), 2 )
			: round( $value, 2 );

		return round( min( $discount, $subtotal ), 2 );
	}

	/**
	 * Sanitise a submitted coupon.
	 *
	 * @param array<string, mixed> $raw       Raw values.
	 * @param int                  $coupon_id Coupon being edited, 0 when new.
	 * @return array{values: array<string, mixed>, errors: array<string, string>}
	 */
	public static function sanitize( array $raw, int $coupon_id = 0 ): array {
		$errors = array();

		$code = self::normalise_code( (string) ( $raw['code'] ?? '' ) );

		if ( '' === $code ) {
			$errors['code'] = __( 'A coupon code is required.', 'event-ticket-manager' );
		} else {
			$existing = self::get_by_code( $code );

			if ( $existing && (int) $existing->id !== $coupon_id ) {
				$errors['code'] = __( 'That coupon code is already in use.', 'event-ticket-manager' );
			}
		}

		$type  = ( isset( $raw['discount_type'] ) && isset( self::types()[ $raw['discount_type'] ] ) ) ? $raw['discount_type'] : self::TYPE_PERCENTAGE;
		$value = $raw['discount_value'] ?? '';

		if ( '' === trim( (string) $value ) || ! is_numeric( $value ) || (float) $value <= 0 ) {
			$errors['discount_value'] = __( 'Discount value must be greater than zero.', 'event-ticket-manager' );
		} elseif ( self::TYPE_PERCENTAGE === $type && (float) $value > 100 ) {
			$errors['discount_value'] = __( 'A percentage discount cannot be more than 100.', 'event-ticket-manager' );
		}

		$scope     = ( isset( $raw['event_scope'] ) && self::SCOPE_SPECIFIC === $raw['event_scope'] ) ? self::SCOPE_SPECIFIC : self::SCOPE_ALL;
		$event_ids = array();

		if ( self::SCOPE_SPECIFIC === $scope ) {
			foreach ( (array) ( $raw['event_ids'] ?? array() ) as $event_id ) {
				$event_id = absint( $event_id );

				if ( $event_id > 0 ) {
					$event_ids[] = $event_id;
				}
			}

			if ( empty( $event_ids ) ) {
				$errors['event_ids'] = __( 'Choose at least one event, or make the coupon available to all events.', 'event-ticket-manager' );
			}
		}

		$start = ETM_Events::sanitize_datetime( (string) ( $raw['start_date'] ?? '' ) );
		$end   = ETM_Events::sanitize_datetime( (string) ( $raw['end_date'] ?? '' ) );

		if ( null !== $start && null !== $end && strtotime( $end ) < strtotime( $start ) ) {
			$errors['end_date'] = __( 'The end date must be after the start date.', 'event-ticket-manager' );
		}

		return array(
			'values' => array(
				'code'               => $code,
				'discount_type'      => $type,
				'discount_value'     => round( max( 0, (float) $value ), 2 ),
				'usage_limit'        => absint( $raw['usage_limit'] ?? 0 ),
				'per_customer_limit' => absint( $raw['per_customer_limit'] ?? 0 ),
				'start_date'         => $start,
				'end_date'           => $end,
				'event_scope'        => $scope,
				'event_ids'          => (string) wp_json_encode( $event_ids ),
				'enabled'            => empty( $raw['enabled'] ) ? 0 : 1,
			),
			'errors' => $errors,
		);
	}

	/**
	 * Insert a coupon.
	 *
	 * @param array<string, mixed> $values Sanitised values.
	 * @return int|WP_Error
	 */
	public static function create( array $values ) {
		global $wpdb;

		$now = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			ETM_Database::coupons_table(),
			array_merge( $values, array( 'created_at' => $now, 'updated_at' => $now ) ),
			array( '%s', '%s', '%f', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'etm_coupon_insert_failed', __( 'The coupon could not be saved.', 'event-ticket-manager' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a coupon.
	 *
	 * @param int                  $coupon_id Coupon ID.
	 * @param array<string, mixed> $values    Sanitised values.
	 * @return bool
	 */
	public static function update( int $coupon_id, array $values ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update(
			ETM_Database::coupons_table(),
			array_merge( $values, array( 'updated_at' => current_time( 'mysql' ) ) ),
			array( 'id' => $coupon_id ),
			array( '%s', '%s', '%f', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Delete a coupon. Bookings keep the code they were placed with.
	 *
	 * @param int $coupon_id Coupon ID.
	 * @return bool
	 */
	public static function delete( int $coupon_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->delete( ETM_Database::coupons_table(), array( 'id' => $coupon_id ), array( '%d' ) );
	}

	/**
	 * Human readable description of a coupon's discount.
	 *
	 * @param object $coupon Coupon row.
	 * @return string
	 */
	public static function describe( object $coupon ): string {
		if ( self::TYPE_PERCENTAGE === $coupon->discount_type ) {
			/* translators: %s: percentage value. */
			return sprintf( __( '%s%% off', 'event-ticket-manager' ), rtrim( rtrim( number_format( (float) $coupon->discount_value, 2, '.', '' ), '0' ), '.' ) );
		}

		/* translators: %s: formatted amount. */
		return sprintf( __( '%s off', 'event-ticket-manager' ), etm_format_price( (float) $coupon->discount_value ) );
	}
}
