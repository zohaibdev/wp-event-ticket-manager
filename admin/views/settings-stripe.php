<?php
/**
 * Stripe settings tab.
 *
 * Secret values are never printed back into the form. An empty field keeps the
 * stored key; a single dash clears it.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

$etm_stripe = ETM_Settings::get_group( ETM_Settings::OPTION_STRIPE );
$etm_name   = ETM_Settings::OPTION_STRIPE;
$etm_mode   = ETM_Stripe::get_mode();

/**
 * Render the "saved" hint for a secret field.
 *
 * @param string $value Stored value.
 * @return string
 */
$etm_key_hint = static function ( string $value ): string {
	if ( '' === $value ) {
		return esc_html__( 'Not set.', 'event-ticket-manager' );
	}

	return sprintf(
		/* translators: %s: masked key ending. */
		esc_html__( 'Saved (ends in %s). Leave blank to keep it, or enter a dash to clear it.', 'event-ticket-manager' ),
		'<code>' . esc_html( substr( $value, -4 ) ) . '</code>'
	);
};
?>
<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="etm-settings-form">
	<?php settings_fields( 'etm_stripe_group' ); ?>

	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable Stripe', 'event-ticket-manager' ); ?></th>
				<td>
					<label for="etm-enable-stripe">
						<input
							type="checkbox"
							id="etm-enable-stripe"
							name="<?php echo esc_attr( $etm_name ); ?>[enable_stripe]"
							value="1"
							<?php checked( 1, (int) $etm_stripe['enable_stripe'] ); ?>
						/>
						<?php esc_html_e( 'Accept card payments inside the checkout modal', 'event-ticket-manager' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Ticket blocks show an unavailable message until this is enabled and a secret key is saved.', 'event-ticket-manager' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="etm-connection-method"><?php esc_html_e( 'Stripe Connection Method', 'event-ticket-manager' ); ?></label>
				</th>
				<td>
					<select id="etm-connection-method" name="<?php echo esc_attr( $etm_name ); ?>[connection_method]">
						<?php foreach ( ETM_Stripe::connection_methods() as $etm_method_key => $etm_method_label ) : ?>
							<option value="<?php echo esc_attr( $etm_method_key ); ?>" <?php selected( ETM_Stripe::get_connection_method(), $etm_method_key ); ?>>
								<?php echo esc_html( $etm_method_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'Keys are entered by hand below. Additional connection methods can be added later without changing what is already saved.', 'event-ticket-manager' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Mode', 'event-ticket-manager' ); ?></th>
				<td>
					<fieldset>
						<label>
							<input type="radio" name="<?php echo esc_attr( $etm_name ); ?>[mode]" value="test" <?php checked( 'test', $etm_stripe['mode'] ); ?> />
							<?php esc_html_e( 'Test', 'event-ticket-manager' ); ?>
						</label>
						<br />
						<label>
							<input type="radio" name="<?php echo esc_attr( $etm_name ); ?>[mode]" value="live" <?php checked( 'live', $etm_stripe['mode'] ); ?> />
							<?php esc_html_e( 'Live', 'event-ticket-manager' ); ?>
						</label>
					</fieldset>
				</td>
			</tr>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Test keys', 'event-ticket-manager' ); ?></h2>

	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row">
					<label for="etm-test-pk"><?php esc_html_e( 'Test Publishable Key', 'event-ticket-manager' ); ?></label>
				</th>
				<td>
					<input
						type="text"
						id="etm-test-pk"
						name="<?php echo esc_attr( $etm_name ); ?>[test_publishable_key]"
						value=""
						class="regular-text"
						autocomplete="off"
						placeholder="pk_test_..."
					/>
					<p class="description"><?php echo wp_kses_post( $etm_key_hint( (string) $etm_stripe['test_publishable_key'] ) ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="etm-test-sk"><?php esc_html_e( 'Test Secret Key', 'event-ticket-manager' ); ?></label>
				</th>
				<td>
					<input
						type="password"
						id="etm-test-sk"
						name="<?php echo esc_attr( $etm_name ); ?>[test_secret_key]"
						value=""
						class="regular-text"
						autocomplete="new-password"
						placeholder="sk_test_..."
					/>
					<p class="description"><?php echo wp_kses_post( $etm_key_hint( (string) $etm_stripe['test_secret_key'] ) ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="etm-webhook-secret"><?php esc_html_e( 'Webhook Signing Secret', 'event-ticket-manager' ); ?></label>
				</th>
				<td>
					<input
						type="password"
						id="etm-webhook-secret"
						name="<?php echo esc_attr( $etm_name ); ?>[webhook_secret]"
						value=""
						class="regular-text"
						autocomplete="new-password"
						placeholder="whsec_..."
					/>
					<p class="description"><?php echo wp_kses_post( $etm_key_hint( (string) $etm_stripe['webhook_secret'] ) ); ?></p>
				</td>
			</tr>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Live keys', 'event-ticket-manager' ); ?></h2>

	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row">
					<label for="etm-live-pk"><?php esc_html_e( 'Live Publishable Key', 'event-ticket-manager' ); ?></label>
				</th>
				<td>
					<input
						type="text"
						id="etm-live-pk"
						name="<?php echo esc_attr( $etm_name ); ?>[live_publishable_key]"
						value=""
						class="regular-text"
						autocomplete="off"
						placeholder="pk_live_..."
					/>
					<p class="description"><?php echo wp_kses_post( $etm_key_hint( (string) $etm_stripe['live_publishable_key'] ) ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="etm-live-sk"><?php esc_html_e( 'Live Secret Key', 'event-ticket-manager' ); ?></label>
				</th>
				<td>
					<input
						type="password"
						id="etm-live-sk"
						name="<?php echo esc_attr( $etm_name ); ?>[live_secret_key]"
						value=""
						class="regular-text"
						autocomplete="new-password"
						placeholder="sk_live_..."
					/>
					<p class="description"><?php echo wp_kses_post( $etm_key_hint( (string) $etm_stripe['live_secret_key'] ) ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="etm-live-webhook"><?php esc_html_e( 'Live Webhook Secret', 'event-ticket-manager' ); ?></label>
				</th>
				<td>
					<input
						type="password"
						id="etm-live-webhook"
						name="<?php echo esc_attr( $etm_name ); ?>[live_webhook_secret]"
						value=""
						class="regular-text"
						autocomplete="new-password"
						placeholder="whsec_..."
					/>
					<p class="description"><?php echo wp_kses_post( $etm_key_hint( (string) $etm_stripe['live_webhook_secret'] ) ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="etm-descriptor"><?php esc_html_e( 'Statement Descriptor', 'event-ticket-manager' ); ?></label>
				</th>
				<td>
					<input
						type="text"
						id="etm-descriptor"
						name="<?php echo esc_attr( $etm_name ); ?>[statement_descriptor]"
						value="<?php echo esc_attr( (string) $etm_stripe['statement_descriptor'] ); ?>"
						class="regular-text"
						maxlength="22"
					/>
					<p class="description"><?php esc_html_e( 'Optional. Appears on the customer bank statement, up to 22 characters.', 'event-ticket-manager' ); ?></p>
				</td>
			</tr>
		</tbody>
	</table>

	<?php submit_button(); ?>
</form>

<div class="etm-card etm-card--info">
	<h2 class="etm-card__title"><?php esc_html_e( 'Webhook setup', 'event-ticket-manager' ); ?></h2>

	<p><?php esc_html_e( 'Payment is taken inside the checkout modal with Stripe Elements, and a booking is only marked as paid once the payment has been verified server-side. Add this endpoint in your Stripe dashboard so confirmations still arrive if a customer closes the window:', 'event-ticket-manager' ); ?></p>

	<p><code class="etm-shortcode" data-etm-copy><?php echo esc_html( ETM_Webhooks::get_endpoint_url() ); ?></code></p>

	<p><?php esc_html_e( 'Subscribe it to these events:', 'event-ticket-manager' ); ?></p>

	<ul class="etm-list">
		<?php foreach ( ETM_Webhooks::subscribed_events() as $etm_event_name ) : ?>
			<li><code><?php echo esc_html( $etm_event_name ); ?></code></li>
		<?php endforeach; ?>
	</ul>

	<p class="description">
		<?php
		printf(
			/* translators: %s: current mode. */
			esc_html__( 'Current mode: %s. Paste the signing secret from the matching Stripe dashboard mode.', 'event-ticket-manager' ),
			'<strong>' . esc_html( $etm_mode ) . '</strong>'
		);
		?>
	</p>

	<?php
	$etm_conn   = ETM_Stripe::connection_state();
	$etm_status = $etm_conn['status'];
	?>
	<div class="etm-card etm-card--info" id="etm-stripe-connection">
		<h2 class="etm-card__title"><?php esc_html_e( 'Connection status', 'event-ticket-manager' ); ?></h2>

		<p>
			<strong data-etm-conn-label class="etm-conn etm-conn--<?php echo esc_attr( $etm_status ); ?>">
				<?php echo esc_html( ETM_Stripe::connection_status_label( $etm_status ) ); ?>
			</strong>
			<span class="etm-muted">
				&mdash;
				<?php
				printf(
					/* translators: %s: test or live. */
					esc_html__( '%s mode', 'event-ticket-manager' ),
					esc_html( $etm_mode )
				);
				?>
			</span>
		</p>

		<p data-etm-conn-detail class="description">
			<?php if ( ETM_Stripe::STATUS_CONNECTED === $etm_status ) : ?>
				<?php if ( '' !== $etm_conn['business_name'] ) : ?>
					<?php echo esc_html( $etm_conn['business_name'] ); ?>
					<?php if ( '' !== $etm_conn['account_id'] ) : ?>
						(<code><?php echo esc_html( $etm_conn['account_id'] ); ?></code>)
					<?php endif; ?>
				<?php elseif ( '' !== $etm_conn['account_id'] ) : ?>
					<code><?php echo esc_html( $etm_conn['account_id'] ); ?></code>
				<?php endif; ?>
				<?php if ( $etm_conn['tested_at'] > 0 ) : ?>
					&middot;
					<?php
					printf(
						/* translators: %s: human readable time difference. */
						esc_html__( 'last tested %s ago', 'event-ticket-manager' ),
						esc_html( human_time_diff( $etm_conn['tested_at'] ) )
					);
					?>
				<?php endif; ?>
			<?php elseif ( ETM_Stripe::STATUS_FAILED === $etm_status && '' !== $etm_conn['message'] ) : ?>
				<?php echo esc_html( $etm_conn['message'] ); ?>
			<?php else : ?>
				<?php esc_html_e( 'The saved credentials have not been verified yet. Changing the mode or either key clears this until it is tested again.', 'event-ticket-manager' ); ?>
			<?php endif; ?>
		</p>

		<p>
			<button type="button" class="button" data-etm-test-stripe>
				<?php esc_html_e( 'Test Connection', 'event-ticket-manager' ); ?>
			</button>
			<span class="description">
				<?php esc_html_e( 'Reads your Stripe account to confirm the saved keys work. It never creates a payment or charges a card. Save any changes first.', 'event-ticket-manager' ); ?>
			</span>
		</p>
	</div>

	<?php if ( ETM_Stripe::is_configured() ) : ?>
		<p class="etm-status-ok"><?php esc_html_e( 'Stripe is configured and payments are enabled.', 'event-ticket-manager' ); ?></p>
	<?php else : ?>
		<p class="etm-status-warn"><?php esc_html_e( 'Stripe is not ready yet: enable it above and save a secret key for the active mode.', 'event-ticket-manager' ); ?></p>
	<?php endif; ?>

	<?php if ( '' === ETM_Stripe::get_webhook_secret() ) : ?>
		<p class="etm-status-warn"><?php esc_html_e( 'No webhook signing secret is saved for the active mode. Payments will not be confirmed until one is added.', 'event-ticket-manager' ); ?></p>
	<?php endif; ?>
</div>
