<?php
/**
 * Bookings list screen.
 *
 * @package EventTicketManager
 *
 * @var ETM_Bookings_List_Table $table Prepared list table.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filter passthrough.
$etm_status   = isset( $_GET['payment_status'] ) ? sanitize_key( wp_unslash( $_GET['payment_status'] ) ) : '';
$etm_event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
// phpcs:enable
?>
<div class="wrap etm-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Bookings', 'event-ticket-manager' ); ?></h1>

	<a href="<?php echo esc_url( admin_url( 'admin.php?page=etm-bookings' ) ); ?>" class="page-title-action">
		<?php esc_html_e( 'Back to attendees', 'event-ticket-manager' ); ?>
	</a>

	<hr class="wp-header-end" />

	<?php if ( $etm_event_id ) : ?>
		<?php $etm_event = ETM_Events::get( $etm_event_id ); ?>
		<?php if ( $etm_event ) : ?>
			<p class="etm-filter-note">
				<?php
				printf(
					/* translators: %s: event title. */
					esc_html__( 'Showing bookings for %s.', 'event-ticket-manager' ),
					'<strong>' . esc_html( $etm_event->title ) . '</strong>'
				);
				?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=etm-bookings&action=bookings' ) ); ?>">
					<?php esc_html_e( 'Clear filter', 'event-ticket-manager' ); ?>
				</a>
			</p>
		<?php endif; ?>
	<?php endif; ?>

	<?php $table->views(); ?>

	<form method="get">
		<input type="hidden" name="page" value="etm-bookings" />
		<input type="hidden" name="action" value="bookings" />
		<?php if ( '' !== $etm_status ) : ?>
			<input type="hidden" name="payment_status" value="<?php echo esc_attr( $etm_status ); ?>" />
		<?php endif; ?>

		<?php $table->search_box( __( 'Search bookings', 'event-ticket-manager' ), 'etm-booking-search' ); ?>
		<?php $table->display(); ?>
	</form>
</div>
