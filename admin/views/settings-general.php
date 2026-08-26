<?php
/**
 * General settings tab.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

$etm_general = ETM_Settings::get_group( ETM_Settings::OPTION_GENERAL );
$etm_name    = ETM_Settings::OPTION_GENERAL;
?>
<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="etm-settings-form">
	<?php settings_fields( 'etm_general_group' ); ?>

	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row">
					<label for="etm-currency"><?php esc_html_e( 'Currency', 'event-ticket-manager' ); ?></label>
				</th>
				<td>
					<input
						type="text"
						id="etm-currency"
						name="<?php echo esc_attr( $etm_name ); ?>[currency]"
						value="<?php echo esc_attr( (string) $etm_general['currency'] ); ?>"
						class="small-text"
						maxlength="3"
						pattern="[A-Za-z]{3}"
					/>
					<p class="description"><?php esc_html_e( 'Three letter ISO currency code, for example USD or EUR. Must be supported by your Stripe account.', 'event-ticket-manager' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="etm-prefix"><?php esc_html_e( 'Booking Number Prefix', 'event-ticket-manager' ); ?></label>
				</th>
				<td>
					<input
						type="text"
						id="etm-prefix"
						name="<?php echo esc_attr( $etm_name ); ?>[booking_number_prefix]"
						value="<?php echo esc_attr( (string) $etm_general['booking_number_prefix'] ); ?>"
						class="small-text"
						maxlength="10"
					/>
					<p class="description">
						<?php
						printf(
							/* translators: %s: booking number example. */
							esc_html__( 'Booking numbers look like %s.', 'event-ticket-manager' ),
							'<code>' . esc_html( $etm_general['booking_number_prefix'] . '-' . gmdate( 'Y' ) . '-000125' ) . '</code>'
						);
						?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="etm-hold"><?php esc_html_e( 'Pending Booking Hold', 'event-ticket-manager' ); ?></label>
				</th>
				<td>
					<input
						type="number"
						id="etm-hold"
						name="<?php echo esc_attr( $etm_name ); ?>[pending_hold_minutes]"
						value="<?php echo esc_attr( (string) $etm_general['pending_hold_minutes'] ); ?>"
						class="small-text"
						min="5"
						max="1440"
						step="1"
					/>
					<span><?php esc_html_e( 'minutes', 'event-ticket-manager' ); ?></span>
					<p class="description"><?php esc_html_e( 'How long an unpaid booking holds its places while the customer is on the Stripe payment page. Expired holds are released automatically.', 'event-ticket-manager' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="etm-success-url"><?php esc_html_e( 'Return URL', 'event-ticket-manager' ); ?></label>
				</th>
				<td>
					<input
						type="url"
						id="etm-success-url"
						name="<?php echo esc_attr( $etm_name ); ?>[success_url]"
						value="<?php echo esc_attr( (string) $etm_general['success_url'] ); ?>"
						class="regular-text"
						placeholder="<?php echo esc_attr( home_url( '/tickets/' ) ); ?>"
					/>
					<p class="description"><?php esc_html_e( 'Optional. Leave empty to send customers back to the page they bought from.', 'event-ticket-manager' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Coupons', 'event-ticket-manager' ); ?></th>
				<td>
					<label for="etm-coupon-enabled">
						<input
							type="checkbox"
							id="etm-coupon-enabled"
							name="<?php echo esc_attr( $etm_name ); ?>[coupon_enabled]"
							value="1"
							<?php checked( 1, (int) $etm_general['coupon_enabled'] ); ?>
						/>
						<?php esc_html_e( 'Enable Coupon Code', 'event-ticket-manager' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Show or hide the coupon code field on checkout. Turning this off hides the field and refuses any coupon submitted anyway; your existing coupons and past bookings are left untouched, and turning it back on restores them.', 'event-ticket-manager' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Uninstall', 'event-ticket-manager' ); ?></th>
				<td>
					<label for="etm-delete-data">
						<input
							type="checkbox"
							id="etm-delete-data"
							name="<?php echo esc_attr( $etm_name ); ?>[delete_data_on_uninstall]"
							value="1"
							<?php checked( 1, (int) $etm_general['delete_data_on_uninstall'] ); ?>
						/>
						<?php esc_html_e( 'Delete all plugin data when the plugin is deleted', 'event-ticket-manager' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Off by default. Events, bookings and attendee records are always kept on deactivation.', 'event-ticket-manager' ); ?></p>
				</td>
			</tr>
		</tbody>
	</table>

	<?php submit_button(); ?>
</form>

<div class="etm-card etm-card--info">
	<h2 class="etm-card__title"><?php esc_html_e( 'Shortcode reference', 'event-ticket-manager' ); ?></h2>
	<p><?php esc_html_e( 'Display the ticket block for an event on any page or post:', 'event-ticket-manager' ); ?></p>
	<p><code>[event_ticket event_id="123"]</code></p>
	<p class="description"><?php esc_html_e( 'Find the exact shortcode for each event on the Events screen.', 'event-ticket-manager' ); ?></p>
</div>
