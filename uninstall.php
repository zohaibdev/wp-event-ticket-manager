<?php
/**
 * Uninstall routine.
 *
 * Nothing is deleted unless the administrator explicitly opted in under
 * Event Tickets > Settings > General.
 *
 * @package EventTicketManager
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove every trace of the plugin for one site.
 */
function etm_uninstall_site(): void {
	global $wpdb;

	$general = get_option( 'etm_general_settings', array() );

	if ( ! is_array( $general ) || empty( $general['delete_data_on_uninstall'] ) ) {
		return;
	}

	$tables = array(
		$wpdb->prefix . 'et_pending_orders',
		$wpdb->prefix . 'et_coupons',
		$wpdb->prefix . 'et_fees',
		$wpdb->prefix . 'et_booking_attendees',
		$wpdb->prefix . 'et_booking_meta',
		$wpdb->prefix . 'et_event_meta',
		$wpdb->prefix . 'et_bookings',
		$wpdb->prefix . 'et_events',
	);

	foreach ( $tables as $table ) {
		// Table names come from $wpdb->prefix and cannot be parameterised.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	$options = array(
		'etm_general_settings',
		'etm_stripe_settings',
		'etm_email_settings',
		'etm_branding_settings',
		'etm_custom_fields',
		'etm_version',
		'etm_db_version',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Webhook idempotency records.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'etm_whk_' ) . '%'
		)
	);

	// Rate limit transients.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_etm_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_etm_' ) . '%'
		)
	);

	wp_clear_scheduled_hook( 'etm_cleanup_event' );
}

if ( is_multisite() ) {
	$etm_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $etm_site_ids as $etm_site_id ) {
		switch_to_blog( (int) $etm_site_id );
		etm_uninstall_site();
		restore_current_blog();
	}
} else {
	etm_uninstall_site();
}
