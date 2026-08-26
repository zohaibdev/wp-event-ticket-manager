<?php
/**
 * Events list screen.
 *
 * @package EventTicketManager
 *
 * @var ETM_Events_List_Table $table Prepared list table.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap etm-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Events', 'event-ticket-manager' ); ?></h1>

	<a href="<?php echo esc_url( admin_url( 'admin.php?page=etm-events&action=new' ) ); ?>" class="page-title-action">
		<?php esc_html_e( 'Add New', 'event-ticket-manager' ); ?>
	</a>

	<hr class="wp-header-end" />

	<?php $table->views(); ?>

	<form method="get">
		<input type="hidden" name="page" value="etm-events" />
		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter passthrough.
		$etm_status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		?>
		<?php if ( '' !== $etm_status ) : ?>
			<input type="hidden" name="status" value="<?php echo esc_attr( $etm_status ); ?>" />
		<?php endif; ?>

		<?php $table->search_box( __( 'Search events', 'event-ticket-manager' ), 'etm-event-search' ); ?>
		<?php $table->display(); ?>
	</form>

	<div class="etm-help-panel">
		<h2><?php esc_html_e( 'Using the ticket block', 'event-ticket-manager' ); ?></h2>
		<p>
			<?php esc_html_e( 'Paste the shortcode for an event into any page or post to display its ticket purchase block. No single event page or archive is needed.', 'event-ticket-manager' ); ?>
		</p>
		<p><code>[event_ticket event_id="123"]</code></p>
	</div>
</div>
