<?php
/**
 * Schema definition and migrations.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns every table name and the dbDelta schema.
 */
class ETM_Database {

	/**
	 * Fully qualified events table name.
	 *
	 * @return string
	 */
	public static function events_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'et_events';
	}

	/**
	 * Fully qualified bookings table name.
	 *
	 * @return string
	 */
	public static function bookings_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'et_bookings';
	}

	/**
	 * Fully qualified attendees table name.
	 *
	 * @return string
	 */
	public static function attendees_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'et_booking_attendees';
	}

	/**
	 * Fully qualified booking meta table name.
	 *
	 * @return string
	 */
	public static function booking_meta_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'et_booking_meta';
	}

	/**
	 * Fully qualified event meta table name.
	 *
	 * @return string
	 */
	public static function event_meta_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'et_event_meta';
	}

	/**
	 * Fully qualified pending orders table name.
	 *
	 * @return string
	 */
	public static function pending_orders_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'et_pending_orders';
	}

	/**
	 * Fully qualified event ticket types table name.
	 *
	 * @return string
	 */
	public static function event_tickets_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'et_event_tickets';
	}

	/**
	 * Fully qualified booking ticket lines table name.
	 *
	 * @return string
	 */
	public static function booking_tickets_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'et_booking_tickets';
	}

	/**
	 * Fully qualified coupons table name.
	 *
	 * @return string
	 */
	public static function coupons_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'et_coupons';
	}

	/**
	 * Fully qualified fees table name.
	 *
	 * @return string
	 */
	public static function fees_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'et_fees';
	}

	/**
	 * Every table this plugin owns.
	 *
	 * @return string[]
	 */
	public static function all_tables(): array {
		return array(
			self::events_table(),
			self::bookings_table(),
			self::attendees_table(),
			self::booking_meta_table(),
			self::event_meta_table(),
			self::pending_orders_table(),
			self::event_tickets_table(),
			self::booking_tickets_table(),
			self::coupons_table(),
			self::fees_table(),
		);
	}

	/**
	 * Create or migrate all tables.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$events          = self::events_table();
		$bookings        = self::bookings_table();
		$attendees       = self::attendees_table();
		$booking_meta    = self::booking_meta_table();
		$event_meta      = self::event_meta_table();
		$pending         = self::pending_orders_table();
		$event_tickets   = self::event_tickets_table();
		$booking_tickets = self::booking_tickets_table();
		$coupons         = self::coupons_table();
		$fees            = self::fees_table();

		$schema = array();

		$schema[] = "CREATE TABLE {$events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			title varchar(255) NOT NULL DEFAULT '',
			description longtext NULL,
			start_datetime datetime NULL DEFAULT NULL,
			end_datetime datetime NULL DEFAULT NULL,
			ticket_price decimal(12,2) NOT NULL DEFAULT 0.00,
			participant_limit int(10) unsigned NULL DEFAULT NULL,
			roommate_enabled tinyint(1) NOT NULL DEFAULT 0,
			roommate_title varchar(255) NOT NULL DEFAULT '',
			roommate_price decimal(12,2) NOT NULL DEFAULT 0.00,
			roommate_price_label varchar(100) NOT NULL DEFAULT 'each',
			roommate_min_quantity int(10) unsigned NOT NULL DEFAULT 0,
			roommate_max_quantity int(10) unsigned NOT NULL DEFAULT 2,
			roommate_counts_toward_capacity tinyint(1) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'draft',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY status (status),
			KEY start_datetime (start_datetime)
		) {$charset_collate};";

		$schema[] = "CREATE TABLE {$bookings} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			booking_number varchar(64) NULL DEFAULT NULL,
			event_id bigint(20) unsigned NOT NULL DEFAULT 0,
			customer_name varchar(255) NOT NULL DEFAULT '',
			customer_email varchar(255) NOT NULL DEFAULT '',
			ticket_quantity int(10) unsigned NOT NULL DEFAULT 0,
			ticket_price decimal(12,2) NOT NULL DEFAULT 0.00,
			ticket_subtotal decimal(12,2) NOT NULL DEFAULT 0.00,
			roommate_enabled tinyint(1) NOT NULL DEFAULT 0,
			roommate_quantity int(10) unsigned NOT NULL DEFAULT 0,
			roommate_name varchar(255) NULL DEFAULT NULL,
			roommate_email varchar(255) NULL DEFAULT NULL,
			roommate_price decimal(12,2) NOT NULL DEFAULT 0.00,
			fees_total decimal(12,2) NOT NULL DEFAULT 0.00,
			coupon_code varchar(64) NULL DEFAULT NULL,
			discount_total decimal(12,2) NOT NULL DEFAULT 0.00,
			total decimal(12,2) NOT NULL DEFAULT 0.00,
			currency varchar(10) NOT NULL DEFAULT 'USD',
			event_type varchar(20) NOT NULL DEFAULT 'ticket',
			payment_status varchar(20) NOT NULL DEFAULT 'pending',
			stripe_checkout_session_id varchar(255) NULL DEFAULT NULL,
			stripe_payment_intent_id varchar(255) NULL DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY booking_number (booking_number),
			KEY event_id (event_id),
			KEY payment_status (payment_status),
			KEY customer_email (customer_email),
			KEY created_at (created_at),
			KEY stripe_checkout_session_id (stripe_checkout_session_id),
			UNIQUE KEY payment_intent (stripe_payment_intent_id),
			KEY event_status (event_id,payment_status),
			KEY coupon_code (coupon_code)
		) {$charset_collate};";

		$schema[] = "CREATE TABLE {$attendees} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
			attendee_number int(10) unsigned NOT NULL DEFAULT 1,
			name varchar(255) NOT NULL DEFAULT '',
			email varchar(255) NOT NULL DEFAULT '',
			ticket_type_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ticket_title_snapshot varchar(255) NULL DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY booking_attendee (booking_id,attendee_number),
			KEY booking_id (booking_id),
			KEY ticket_type_id (ticket_type_id)
		) {$charset_collate};";

		$schema[] = "CREATE TABLE {$booking_meta} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
			meta_key varchar(191) NOT NULL DEFAULT '',
			meta_value longtext NULL,
			PRIMARY KEY  (id),
			KEY booking_id (booking_id),
			KEY meta_key (meta_key),
			KEY booking_key (booking_id,meta_key)
		) {$charset_collate};";

		$schema[] = "CREATE TABLE {$event_meta} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id bigint(20) unsigned NOT NULL DEFAULT 0,
			meta_key varchar(191) NOT NULL DEFAULT '',
			meta_value longtext NULL,
			PRIMARY KEY  (id),
			KEY event_id (event_id),
			KEY meta_key (meta_key),
			KEY event_key (event_id,meta_key)
		) {$charset_collate};";

		$schema[] = "CREATE TABLE {$pending} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token varchar(64) NOT NULL DEFAULT '',
			event_id bigint(20) unsigned NOT NULL DEFAULT 0,
			seats int(10) unsigned NOT NULL DEFAULT 0,
			payload longtext NULL,
			total decimal(12,2) NOT NULL DEFAULT 0.00,
			currency varchar(10) NOT NULL DEFAULT 'USD',
			stripe_payment_intent_id varchar(255) NULL DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			expires_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY event_id (event_id),
			KEY expires_at (expires_at),
			KEY pending_intent (stripe_payment_intent_id),
			KEY event_expiry (event_id,expires_at)
		) {$charset_collate};";

		$schema[] = "CREATE TABLE {$event_tickets} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id bigint(20) unsigned NOT NULL DEFAULT 0,
			title varchar(255) NOT NULL DEFAULT '',
			price decimal(12,2) NOT NULL DEFAULT 0.00,
			ticket_limit int(10) unsigned NULL DEFAULT NULL,
			min_quantity int(10) unsigned NOT NULL DEFAULT 0,
			max_quantity int(10) unsigned NOT NULL DEFAULT 0,
			price_label varchar(100) NOT NULL DEFAULT 'each',
			variation_type varchar(20) NOT NULL DEFAULT 'standard',
			sort_order int(10) unsigned NOT NULL DEFAULT 0,
			enabled tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY event_id (event_id),
			KEY event_order (event_id,sort_order)
		) {$charset_collate};";

		$schema[] = "CREATE TABLE {$booking_tickets} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ticket_type_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ticket_title_snapshot varchar(255) NOT NULL DEFAULT '',
			unit_price decimal(12,2) NOT NULL DEFAULT 0.00,
			quantity int(10) unsigned NOT NULL DEFAULT 0,
			subtotal decimal(12,2) NOT NULL DEFAULT 0.00,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY booking_id (booking_id),
			KEY ticket_type_id (ticket_type_id)
		) {$charset_collate};";

		$schema[] = "CREATE TABLE {$coupons} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			code varchar(64) NOT NULL DEFAULT '',
			discount_type varchar(20) NOT NULL DEFAULT 'percentage',
			discount_value decimal(12,2) NOT NULL DEFAULT 0.00,
			usage_limit int(10) unsigned NOT NULL DEFAULT 0,
			per_customer_limit int(10) unsigned NOT NULL DEFAULT 0,
			start_date datetime NULL DEFAULT NULL,
			end_date datetime NULL DEFAULT NULL,
			event_scope varchar(20) NOT NULL DEFAULT 'all',
			event_ids longtext NULL,
			enabled tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY enabled (enabled)
		) {$charset_collate};";

		$schema[] = "CREATE TABLE {$fees} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			fee_type varchar(20) NOT NULL DEFAULT 'fixed',
			fee_value decimal(12,2) NOT NULL DEFAULT 0.00,
			scope varchar(20) NOT NULL DEFAULT 'all',
			event_id bigint(20) unsigned NOT NULL DEFAULT 0,
			enabled tinyint(1) NOT NULL DEFAULT 1,
			sort_order int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY scope_event (scope,event_id),
			KEY enabled (enabled)
		) {$charset_collate};";

		foreach ( $schema as $sql ) {
			dbDelta( $sql );
		}

		self::migrate_single_ticket_events();
		self::migrate_ticket_quantity_fields();
		self::migrate_roommate_addon_to_variation();
	}

	/**
	 * Drop every plugin table. Only used by the opt-in uninstall routine.
	 */
	public static function drop_tables(): void {
		global $wpdb;

		foreach ( self::all_tables() as $table ) {
			// Table names are built from $wpdb->prefix and cannot be parameterised.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Check whether the core tables exist.
	 *
	 * @return bool
	 */
	public static function tables_exist(): bool {
		global $wpdb;

		$table = self::events_table();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $found === $table;
	}

	/**
	 * Give every pre-existing event one ticket type built from its old single
	 * price, so nothing that already worked stops working.
	 *
	 * Runs once. Events that already have ticket types are left alone, and no
	 * historical booking is touched: bookings carry their own snapshot.
	 */
	public static function migrate_single_ticket_events(): void {
		global $wpdb;

		if ( 'done' === get_option( 'etm_ticket_types_migrated', '' ) ) {
			return;
		}

		$events        = self::events_table();
		$event_tickets = self::event_tickets_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results(
			"SELECT e.id, e.ticket_price
			 FROM {$events} e
			 LEFT JOIN {$event_tickets} t ON t.event_id = e.id
			 WHERE t.id IS NULL"
		);

		$now = current_time( 'mysql' );

		foreach ( $rows as $row ) {
			/**
			 * Filter the name given to a migrated single-price ticket type.
			 *
			 * @param string $title    Default title.
			 * @param int    $event_id Event ID.
			 */
			$title = (string) apply_filters(
				'etm_migrated_ticket_title',
				__( 'General Admission', 'event-ticket-manager' ),
				(int) $row->id
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$event_tickets,
				array(
					'event_id'     => (int) $row->id,
					'title'        => $title,
					'price'        => (float) $row->ticket_price,
					'ticket_limit' => null,
					'sort_order'   => 0,
					'enabled'      => 1,
					'created_at'   => $now,
					'updated_at'   => $now,
				),
				array( '%d', '%s', '%f', '%d', '%d', '%d', '%s', '%s' )
			);
		}

		update_option( 'etm_ticket_types_migrated', 'done' );
	}

	/**
	 * Give every event that sold the old roommate add-on a real ticket type.
	 *
	 * The add-on is now an ordinary variation ("Sharing a Room"), so an event
	 * that was already offering it keeps offering it, priced and limited the
	 * same way, through the system that now owns the behaviour.
	 *
	 * Runs once. No booking is touched: historical roommate details stay exactly
	 * where they were written. The event's own roommate columns are left alone
	 * too, so nothing that already recorded them loses anything.
	 */
	public static function migrate_roommate_addon_to_variation(): void {
		global $wpdb;

		if ( 'done' === get_option( 'etm_roommate_variation_migrated', '' ) ) {
			return;
		}

		// Never leave the flag unset and never fatal if this runs somewhere the
		// ticket type class was not loaded; the next request picks it up.
		if ( ! class_exists( 'ETM_Event_Tickets' ) ) {
			return;
		}

		$events        = self::events_table();
		$event_tickets = self::event_tickets_table();

		// Only events that actually had the add-on switched on, and that have not
		// already been given a Sharing a Room type by an earlier partial run.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.id, e.roommate_title, e.roommate_price, e.roommate_price_label, e.roommate_max_quantity
				 FROM {$events} e
				 LEFT JOIN {$event_tickets} t
				   ON t.event_id = e.id AND t.variation_type = %s
				 WHERE e.roommate_enabled = 1 AND t.id IS NULL",
				ETM_Event_Tickets::TYPE_SHARING_ROOM
			)
		);

		foreach ( $rows as $row ) {
			$title = trim( (string) $row->roommate_title );
			$label = trim( (string) $row->roommate_price_label );

			// The old add-on was always optional, so the new type is too. Its
			// maximum carries over; a missing one falls back to the old ceiling.
			$max = (int) $row->roommate_max_quantity;

			ETM_Event_Tickets::create(
				(int) $row->id,
				array(
					'title'          => '' !== $title ? $title : __( 'Sharing a Room', 'event-ticket-manager' ),
					'price'          => (float) $row->roommate_price,
					'min_quantity'   => 0,
					'max_quantity'   => $max > 0 ? $max : 2,
					'price_label'    => '' !== $label ? $label : ETM_Event_Tickets::default_price_label(),
					'variation_type' => ETM_Event_Tickets::TYPE_SHARING_ROOM,
					'sort_order'     => 99,
					'enabled'        => 1,
				)
			);
		}

		update_option( 'etm_roommate_variation_migrated', 'done' );
	}

	/**
	 * Move the old per-type inventory limit onto the new per-order maximum.
	 *
	 * Runs once. Minimums stay at 0 so nothing becomes mandatory that was not
	 * mandatory before, and any blank label falls back to "each".
	 */
	public static function migrate_ticket_quantity_fields(): void {
		global $wpdb;

		if ( 'done' === get_option( 'etm_ticket_quantity_migrated', '' ) ) {
			return;
		}

		$table = self::event_tickets_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"UPDATE {$table} SET max_quantity = ticket_limit
			 WHERE ticket_limit IS NOT NULL AND ticket_limit > 0 AND max_quantity = 0"
		);

		$wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET price_label = %s WHERE price_label = '' OR price_label IS NULL", 'each' )
		);
		// phpcs:enable

		update_option( 'etm_ticket_quantity_migrated', 'done' );
	}
}
