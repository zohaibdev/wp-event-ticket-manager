<?php
/**
 * Public, theme-independent ticket verification page.
 *
 * Reached by scanning an attendee's QR code (or visiting its URL directly):
 * `?etm_ticket=CODE` on the front end. No rewrite rule and no login is
 * needed - template_redirect intercepts the request before the theme loads
 * and the whole response is built here, so this never depends on what theme
 * is active.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Looks up a security code, shows the ticket, and handles the "I'm Coming"
 * and "Check In" actions - all server-validated against the code alone.
 */
class ETM_Ticket_Verification {

	/**
	 * Maximum verification-page actions from one IP per window.
	 */
	private const RATE_LIMIT_MAX = 20;

	/**
	 * Rate-limit window, in seconds.
	 */
	private const RATE_LIMIT_WINDOW = 60;

	/**
	 * Register hooks.
	 */
	public function hooks(): void {
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );
	}

	/**
	 * Intercept and fully handle any request carrying an etm_ticket code.
	 */
	public function maybe_render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only lookup key; mutations are nonce-checked below.
		$raw_code = '';

		if ( isset( $_POST['etm_ticket'] ) ) {
			$raw_code = wp_unslash( $_POST['etm_ticket'] );
		} elseif ( isset( $_GET['etm_ticket'] ) ) {
			$raw_code = wp_unslash( $_GET['etm_ticket'] );
		} else {
			return;
		}
		// phpcs:enable

		$code = ETM_Attendees::sanitize_code( (string) $raw_code );

		nocache_headers();

		$attendee = '' !== $code ? ETM_Attendees::get_by_security_code( $code ) : null;

		if ( ! $attendee ) {
			status_header( 404 );
			$this->render_page( $this->invalid_body() );
			exit;
		}

		$message = '';

		if ( isset( $_POST['etm_ticket_action'] ) ) {
			$message  = $this->handle_action( $attendee, $code );
			$attendee = ETM_Attendees::get_by_security_code( $code ) ?: $attendee;
		}

		$this->render_page( $this->ticket_body( $attendee, $code, $message ) );
		exit;
	}

	/**
	 * Apply a posted "I'm Coming" or "Check In" action.
	 *
	 * @param object $attendee Attendee row, already resolved from the code.
	 * @param string $code     The same code, for the nonce and rate-limit key.
	 * @return string A short status message for the page.
	 */
	private function handle_action( object $attendee, string $code ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified on the next line.
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'etm_ticket_action_' . $code ) ) {
			return __( 'This action could not be verified. Please reload the page and try again.', 'event-ticket-manager' );
		}

		if ( ! $this->rate_limit_ok() ) {
			return __( 'Too many attempts from this connection. Please try again in a minute.', 'event-ticket-manager' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$action = sanitize_key( wp_unslash( $_POST['etm_ticket_action'] ) );

		if ( 'coming' === $action ) {
			ETM_Attendees::set_coming_status( (int) $attendee->id, ETM_Attendees::COMING_YES );

			return __( "Thanks - you're marked as coming.", 'event-ticket-manager' );
		}

		if ( 'check_in' === $action ) {
			ETM_Attendees::set_check_in( (int) $attendee->id, true );

			return __( 'Checked in. Enjoy the event!', 'event-ticket-manager' );
		}

		return '';
	}

	/**
	 * A light per-IP throttle on the two mutating actions.
	 *
	 * @return bool False once the window's quota is used up.
	 */
	private function rate_limit_ok(): bool {
		$ip = etm_get_client_ip();

		if ( '' === $ip ) {
			// No reliable client identity to key a limit on - do not block.
			return true;
		}

		$key   = 'etm_ticket_rl_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT_MAX ) {
			return false;
		}

		set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );

		return true;
	}

	/**
	 * Body markup for a code that does not resolve to any attendee.
	 *
	 * Deliberately generic: it never hints at whether the code is malformed,
	 * expired, or simply belongs to someone else's ticket.
	 *
	 * @return string
	 */
	private function invalid_body(): string {
		ob_start();
		?>
		<div class="etm-ticket-card etm-ticket-card--invalid">
			<h1><?php esc_html_e( 'Ticket Not Found', 'event-ticket-manager' ); ?></h1>
			<p><?php esc_html_e( 'This ticket could not be verified. Please check the QR code or link and try again.', 'event-ticket-manager' ); ?></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Body markup for a resolved ticket.
	 *
	 * @param object $attendee Attendee row.
	 * @param string $code     Security code (already sanitized).
	 * @param string $message  Optional status line from a just-applied action.
	 * @return string
	 */
	private function ticket_body( object $attendee, string $code, string $message ): string {
		$booking = ETM_Bookings::get( (int) $attendee->booking_id );
		$event   = $booking ? ETM_Events::get( (int) $booking->event_id ) : null;

		$checked_in = ! empty( $attendee->check_in_status );
		$coming     = (string) $attendee->coming_status;
		$nonce      = wp_create_nonce( 'etm_ticket_action_' . $code );

		ob_start();
		?>
		<div class="etm-ticket-card">
			<h1><?php echo esc_html( $event ? (string) $event->title : __( 'Event Ticket', 'event-ticket-manager' ) ); ?></h1>

			<?php if ( '' !== $message ) : ?>
				<p class="etm-ticket-message"><?php echo esc_html( $message ); ?></p>
			<?php endif; ?>

			<table class="etm-ticket-table">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'Attendee', 'event-ticket-manager' ); ?></th>
						<td><?php echo esc_html( (string) $attendee->name ); ?></td>
					</tr>
					<?php if ( ! empty( $attendee->email ) ) : ?>
						<tr>
							<th><?php esc_html_e( 'Email', 'event-ticket-manager' ); ?></th>
							<td><?php echo esc_html( (string) $attendee->email ); ?></td>
						</tr>
					<?php endif; ?>
					<tr>
						<th><?php esc_html_e( 'Ticket Type', 'event-ticket-manager' ); ?></th>
						<td><?php echo esc_html( '' !== (string) $attendee->ticket_title_snapshot ? (string) $attendee->ticket_title_snapshot : __( 'Ticket', 'event-ticket-manager' ) ); ?></td>
					</tr>
					<?php if ( $booking ) : ?>
						<tr>
							<th><?php esc_html_e( 'Booking Number', 'event-ticket-manager' ); ?></th>
							<td><?php echo esc_html( (string) $booking->booking_number ); ?></td>
						</tr>
					<?php endif; ?>
					<tr>
						<th><?php esc_html_e( 'Security Code', 'event-ticket-manager' ); ?></th>
						<td><code><?php echo esc_html( $code ); ?></code></td>
					</tr>
					<?php if ( $booking ) : ?>
						<tr>
							<th><?php esc_html_e( 'Payment Status', 'event-ticket-manager' ); ?></th>
							<td><?php echo esc_html( etm_booking_status_label( (string) $booking->payment_status ) ); ?></td>
						</tr>
					<?php endif; ?>
					<?php if ( $event ) : ?>
						<tr>
							<th><?php esc_html_e( 'Event Date', 'event-ticket-manager' ); ?></th>
							<td><?php echo esc_html( etm_format_datetime( $event->start_datetime, false ) ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Event Time', 'event-ticket-manager' ); ?></th>
							<td><?php echo esc_html( etm_event_time_range( $event ) ); ?></td>
						</tr>
					<?php endif; ?>
					<tr>
						<th><?php esc_html_e( 'Check-in Status', 'event-ticket-manager' ); ?></th>
						<td><?php echo esc_html( ETM_Attendees::check_in_label( $checked_in ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Coming', 'event-ticket-manager' ); ?></th>
						<td><?php echo esc_html( ETM_Attendees::coming_status_label( $coming ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<div class="etm-ticket-actions">
				<form method="post">
					<input type="hidden" name="etm_ticket" value="<?php echo esc_attr( $code ); ?>" />
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />
					<button type="submit" name="etm_ticket_action" value="coming" class="etm-ticket-btn">
						<?php echo esc_html( ETM_Attendees::COMING_YES === $coming ? __( "You're Coming", 'event-ticket-manager' ) : __( "I'm Coming", 'event-ticket-manager' ) ); ?>
					</button>
				</form>
				<form method="post">
					<input type="hidden" name="etm_ticket" value="<?php echo esc_attr( $code ); ?>" />
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />
					<button type="submit" name="etm_ticket_action" value="check_in" class="etm-ticket-btn etm-ticket-btn--primary">
						<?php echo esc_html( $checked_in ? __( 'Checked In', 'event-ticket-manager' ) : __( 'Check In', 'event-ticket-manager' ) ); ?>
					</button>
				</form>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Emit the full, theme-independent HTML document and its inline styles.
	 *
	 * @param string $body Already-escaped body markup.
	 */
	private function render_page( string $body ): void {
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( get_bloginfo( 'name' ) . ' — ' . __( 'Ticket', 'event-ticket-manager' ) ); ?></title>
	<style>
		* { box-sizing: border-box; }
		body {
			margin: 0;
			padding: 24px 16px;
			background: #f5f7fa;
			font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
			color: #1f2933;
		}
		.etm-ticket-card {
			max-width: 480px;
			margin: 0 auto;
			background: #fff;
			border: 1px solid #e4e7eb;
			border-radius: 12px;
			padding: 28px 24px;
		}
		.etm-ticket-card h1 {
			margin: 0 0 16px;
			font-size: 20px;
			line-height: 1.3;
		}
		.etm-ticket-message {
			background: #f0f9f4;
			border: 1px solid #b7e4c7;
			color: #1b4332;
			border-radius: 8px;
			padding: 10px 14px;
			font-size: 14px;
			margin: 0 0 16px;
		}
		.etm-ticket-table {
			width: 100%;
			border-collapse: collapse;
			font-size: 14px;
			margin-bottom: 20px;
		}
		.etm-ticket-table th,
		.etm-ticket-table td {
			text-align: left;
			padding: 8px 0;
			border-bottom: 1px solid #e4e7eb;
			vertical-align: top;
		}
		.etm-ticket-table th {
			color: #616e7c;
			font-weight: 600;
			width: 40%;
			padding-right: 12px;
		}
		.etm-ticket-actions {
			display: flex;
			gap: 10px;
			flex-wrap: wrap;
		}
		.etm-ticket-actions form { flex: 1 1 auto; margin: 0; }
		.etm-ticket-btn {
			width: 100%;
			padding: 12px 16px;
			font-size: 14px;
			font-weight: 600;
			border-radius: 8px;
			border: 1px solid #d1d5db;
			background: #fff;
			color: #1f2933;
			cursor: pointer;
		}
		.etm-ticket-btn--primary {
			background: #4f46e5;
			border-color: #4f46e5;
			color: #fff;
		}
		.etm-ticket-card--invalid p { color: #616e7c; }
	</style>
</head>
<body class="etm-ticket-page">
	<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped fragments above. ?>
</body>
</html>
		<?php
	}
}
