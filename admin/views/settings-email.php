<?php
/**
 * Email settings tab.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

$etm_email = ETM_Settings::get_group( ETM_Settings::OPTION_EMAIL );
$etm_name  = ETM_Settings::OPTION_EMAIL;
?>
<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="etm-settings-form">
	<?php settings_fields( 'etm_email_group' ); ?>

	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row">
					<label for="etm-admin-email"><?php esc_html_e( 'Admin Notification Emails', 'event-ticket-manager' ); ?></label>
				</th>
				<td>
					<textarea
						id="etm-admin-email"
						name="<?php echo esc_attr( $etm_name ); ?>[admin_notification_emails]"
						class="large-text"
						rows="3"
						required
						placeholder="admin@example.com, manager@example.com, bookings@example.com"
					><?php echo esc_textarea( implode( "
", ETM_Settings::admin_notification_emails() ) ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'Where new booking notifications are sent. Enter one address per line or separate them with commas; every booking notifies each address separately.', 'event-ticket-manager' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="etm-rsvp-email"><?php esc_html_e( 'RSVP Notification Emails', 'event-ticket-manager' ); ?></label>
				</th>
				<td>
					<textarea
						id="etm-rsvp-email"
						name="<?php echo esc_attr( $etm_name ); ?>[rsvp_notification_emails]"
						class="large-text"
						rows="3"
						placeholder="events@example.com, manager@example.com"
					><?php echo esc_textarea( implode( "
", ETM_Settings::rsvp_notification_emails() ) ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'Where RSVP notifications go. One address per line or comma-separated. An event can override this. Leave empty to use the admin notification addresses above.', 'event-ticket-manager' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="etm-from-name"><?php esc_html_e( 'From Name', 'event-ticket-manager' ); ?></label>
				</th>
				<td>
					<input
						type="text"
						id="etm-from-name"
						name="<?php echo esc_attr( $etm_name ); ?>[from_name]"
						value="<?php echo esc_attr( (string) $etm_email['from_name'] ); ?>"
						class="regular-text"
					/>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="etm-from-email"><?php esc_html_e( 'From Email', 'event-ticket-manager' ); ?></label>
				</th>
				<td>
					<input
						type="email"
						id="etm-from-email"
						name="<?php echo esc_attr( $etm_name ); ?>[from_email]"
						value="<?php echo esc_attr( (string) $etm_email['from_email'] ); ?>"
						class="regular-text"
					/>
					<p class="description"><?php esc_html_e( 'Use an address on your own domain so messages are not rejected by spam filters.', 'event-ticket-manager' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Notifications', 'event-ticket-manager' ); ?></th>
				<td>
					<fieldset>
						<label for="etm-enable-customer">
							<input
								type="checkbox"
								id="etm-enable-customer"
								name="<?php echo esc_attr( $etm_name ); ?>[enable_customer_email]"
								value="1"
								<?php checked( 1, (int) $etm_email['enable_customer_email'] ); ?>
							/>
							<?php esc_html_e( 'Send the customer a booking confirmation', 'event-ticket-manager' ); ?>
						</label>
						<br />
						<label for="etm-enable-admin">
							<input
								type="checkbox"
								id="etm-enable-admin"
								name="<?php echo esc_attr( $etm_name ); ?>[enable_admin_email]"
								value="1"
								<?php checked( 1, (int) $etm_email['enable_admin_email'] ); ?>
							/>
							<?php esc_html_e( 'Send the administrator a new booking notification', 'event-ticket-manager' ); ?>
						</label>
					</fieldset>
					<p class="description"><?php esc_html_e( 'Both are sent only after a payment is confirmed by a verified Stripe webhook.', 'event-ticket-manager' ); ?></p>
				</td>
			</tr>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Customer confirmation', 'event-ticket-manager' ); ?></h2>

	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row">
					<label for="etm-customer-subject"><?php esc_html_e( 'Subject', 'event-ticket-manager' ); ?></label>
				</th>
				<td>
					<input
						type="text"
						id="etm-customer-subject"
						name="<?php echo esc_attr( $etm_name ); ?>[customer_subject]"
						value="<?php echo esc_attr( (string) $etm_email['customer_subject'] ); ?>"
						class="large-text"
					/>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="etm-customer-heading"><?php esc_html_e( 'Heading', 'event-ticket-manager' ); ?></label>
				</th>
				<td>
					<input
						type="text"
						id="etm-customer-heading"
						name="<?php echo esc_attr( $etm_name ); ?>[customer_heading]"
						value="<?php echo esc_attr( (string) $etm_email['customer_heading'] ); ?>"
						class="large-text"
					/>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="etm-customer-intro"><?php esc_html_e( 'Intro Text', 'event-ticket-manager' ); ?></label>
				</th>
				<td>
					<textarea
						id="etm-customer-intro"
						name="<?php echo esc_attr( $etm_name ); ?>[customer_intro]"
						rows="3"
						class="large-text"
					><?php echo esc_textarea( (string) $etm_email['customer_intro'] ); ?></textarea>
				</td>
			</tr>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Admin notification', 'event-ticket-manager' ); ?></h2>

	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row">
					<label for="etm-admin-subject"><?php esc_html_e( 'Subject', 'event-ticket-manager' ); ?></label>
				</th>
				<td>
					<input
						type="text"
						id="etm-admin-subject"
						name="<?php echo esc_attr( $etm_name ); ?>[admin_subject]"
						value="<?php echo esc_attr( (string) $etm_email['admin_subject'] ); ?>"
						class="large-text"
					/>
				</td>
			</tr>
		</tbody>
	</table>

	<div class="etm-card etm-card--info">
		<h3><?php esc_html_e( 'Available placeholders', 'event-ticket-manager' ); ?></h3>
		<p class="etm-placeholders">
			<code>{booking_number}</code>
			<code>{customer_name}</code>
			<code>{customer_email}</code>
			<code>{event_title}</code>
			<code>{event_date}</code>
			<code>{ticket_quantity}</code>
			<code>{total}</code>
			<code>{site_name}</code>
		</p>
		<p class="description">
			<?php esc_html_e( 'The full email layout lives in frontend/templates/emails/ and can be overridden from your theme in event-ticket-manager/emails/.', 'event-ticket-manager' ); ?>
		</p>
	</div>

	<?php submit_button(); ?>
</form>

<div class="etm-card etm-card--info">
	<h2 class="etm-card__title"><?php esc_html_e( 'Send a test email', 'event-ticket-manager' ); ?></h2>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="etm-inline-form">
		<?php wp_nonce_field( 'etm_send_test_email' ); ?>
		<input type="hidden" name="action" value="etm_send_test_email" />

		<label class="screen-reader-text" for="etm-test-email"><?php esc_html_e( 'Test recipient', 'event-ticket-manager' ); ?></label>
		<input
			type="email"
			id="etm-test-email"
			name="test_email"
			value="<?php echo esc_attr( (string) ( ETM_Settings::admin_notification_emails()[0] ?? '' ) ); ?>"
			class="regular-text"
			required
		/>

		<button type="submit" class="button"><?php esc_html_e( 'Send test', 'event-ticket-manager' ); ?></button>
	</form>

	<p class="description"><?php esc_html_e( 'Sends a sample customer confirmation using placeholder booking data.', 'event-ticket-manager' ); ?></p>
</div>
