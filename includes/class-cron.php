<?php
/**
 * Scheduled maintenance tasks.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Expires stale pending bookings and prunes webhook records.
 */
class ETM_Cron {

	public const HOOK_CLEANUP = 'etm_cleanup_event';

	/**
	 * Register hooks.
	 */
	public function hooks(): void {
		add_action( self::HOOK_CLEANUP, array( $this, 'run_cleanup' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	/**
	 * Schedule the recurring cleanup.
	 */
	public static function schedule_events(): void {
		if ( ! wp_next_scheduled( self::HOOK_CLEANUP ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK_CLEANUP );
		}
	}

	/**
	 * Remove scheduled events on deactivation.
	 */
	public static function clear_events(): void {
		$timestamp = wp_next_scheduled( self::HOOK_CLEANUP );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK_CLEANUP );
		}

		wp_clear_scheduled_hook( self::HOOK_CLEANUP );
	}

	/**
	 * Re-add the schedule if it went missing.
	 */
	public function ensure_scheduled(): void {
		self::schedule_events();
	}

	/**
	 * Cleanup worker.
	 */
	public function run_cleanup(): void {
		// Unpaid checkouts hold seats but write no booking; release them first.
		$expired = ETM_Pending_Orders::purge_expired();

		// Legacy pending booking rows from before bookings were deferred.
		$expired += ETM_Bookings::expire_pending();

		ETM_Webhooks::purge_processed_events();

		if ( $expired > 0 ) {
			ETM_Stripe::log( 'Expired ' . $expired . ' pending booking(s).' );
		}

		/**
		 * Fires after the hourly cleanup has run.
		 *
		 * @param int $expired Number of expired bookings.
		 */
		do_action( 'etm_cleanup_complete', $expired );
	}
}
