<?php
/**
 * Attendees list screen.
 *
 * @package EventTicketManager
 *
 * @var ETM_Attendees_List_Table $table Prepared list table.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filter passthrough.
$etm_status    = isset( $_GET['payment_status'] ) ? sanitize_key( wp_unslash( $_GET['payment_status'] ) ) : '';
$etm_event_id  = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
$etm_check_in  = isset( $_GET['check_in_status'] ) ? sanitize_key( wp_unslash( $_GET['check_in_status'] ) ) : '';
$etm_search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$etm_date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
$etm_date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
// phpcs:enable

// The export follows whatever the screen is currently filtered to.
$etm_export_url = wp_nonce_url(
	add_query_arg(
		array_filter(
			array(
				'action'          => 'etm_export_attendees_csv',
				'payment_status'  => $etm_status,
				'event_id'        => $etm_event_id,
				'check_in_status' => $etm_check_in,
				's'               => $etm_search,
				'date_from'       => $etm_date_from,
				'date_to'         => $etm_date_to,
			),
			static function ( $value ) {
				return '' !== $value && 0 !== $value;
			}
		),
		admin_url( 'admin-post.php' )
	),
	'etm_export_attendees_csv'
);
?>
<div class="wrap etm-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Attendees', 'event-ticket-manager' ); ?></h1>

	<a href="<?php echo esc_url( $etm_export_url ); ?>" class="page-title-action">
		<?php esc_html_e( 'Export CSV', 'event-ticket-manager' ); ?>
	</a>

	<a href="<?php echo esc_url( admin_url( 'admin.php?page=etm-bookings&action=bookings' ) ); ?>" class="page-title-action">
		<?php esc_html_e( 'View by booking', 'event-ticket-manager' ); ?>
	</a>

	<hr class="wp-header-end" />

	<?php if ( $etm_event_id ) : ?>
		<?php $etm_event = ETM_Events::get( $etm_event_id ); ?>
		<?php if ( $etm_event ) : ?>
			<p class="etm-filter-note">
				<?php
				printf(
					/* translators: %s: event title. */
					esc_html__( 'Showing attendees for %s.', 'event-ticket-manager' ),
					'<strong>' . esc_html( $etm_event->title ) . '</strong>'
				);
				?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=etm-bookings' ) ); ?>">
					<?php esc_html_e( 'Clear filter', 'event-ticket-manager' ); ?>
				</a>
			</p>
		<?php endif; ?>
	<?php endif; ?>

	<?php $table->views(); ?>

	<form method="get">
		<input type="hidden" name="page" value="etm-bookings" />
		<?php if ( '' !== $etm_status ) : ?>
			<input type="hidden" name="payment_status" value="<?php echo esc_attr( $etm_status ); ?>" />
		<?php endif; ?>

		<?php $table->search_box( __( 'Search attendees', 'event-ticket-manager' ), 'etm-attendee-search' ); ?>
		<?php $table->display(); ?>
	</form>
</div>
