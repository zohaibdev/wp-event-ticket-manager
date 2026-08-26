<?php
/**
 * Branding customizer tab.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

$etm_branding = ETM_Settings::get_group( ETM_Settings::OPTION_BRANDING );
$etm_name     = ETM_Settings::OPTION_BRANDING;
$etm_palette  = ETM_Settings::branding_palette();
?>
<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="etm-settings-form">
	<?php settings_fields( 'etm_branding_group' ); ?>

	<p class="description etm-intro">
		<?php esc_html_e( 'These colours are published as CSS variables and applied to the ticket block, the checkout modal, the step indicator, buttons and the ticket variation rows. Stripe\'s payment form picks up the primary, text and background colours too.', 'event-ticket-manager' ); ?>
	</p>

	<div class="etm-columns">
		<div class="etm-columns__main">
			<div class="etm-card">
				<h2 class="etm-card__title"><?php esc_html_e( 'Brand colours', 'event-ticket-manager' ); ?></h2>

				<table class="form-table" role="presentation">
					<tbody>
						<?php foreach ( $etm_palette as $etm_key => $etm_config ) : ?>
							<?php $etm_value = (string) ( $etm_branding[ $etm_key ] ?? $etm_config['default'] ); ?>
							<tr>
								<th scope="row">
									<label for="etm-color-<?php echo esc_attr( $etm_key ); ?>">
										<?php echo esc_html( $etm_config['label'] ); ?>
									</label>
								</th>
								<td>
									<input
										type="text"
										id="etm-color-<?php echo esc_attr( $etm_key ); ?>"
										class="etm-color-field"
										name="<?php echo esc_attr( $etm_name ); ?>[<?php echo esc_attr( $etm_key ); ?>]"
										value="<?php echo esc_attr( $etm_value ); ?>"
										data-default-color="<?php echo esc_attr( $etm_config['default'] ); ?>"
									/>
									<p class="description"><?php echo esc_html( $etm_config['description'] ); ?></p>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php
				$etm_button_contrast = ETM_Settings::contrast_ratio(
					(string) $etm_branding['button_text_color'],
					(string) $etm_branding['primary_color']
				);
				$etm_text_contrast   = ETM_Settings::contrast_ratio(
					(string) $etm_branding['text_color'],
					(string) $etm_branding['background_color']
				);
				?>

				<div class="etm-contrast">
					<h3><?php esc_html_e( 'Readability check', 'event-ticket-manager' ); ?></h3>

					<p class="<?php echo $etm_button_contrast >= 4.5 ? 'etm-status-ok' : 'etm-status-warn'; ?>">
						<?php
						printf(
							/* translators: %s: contrast ratio, for example 6.29. */
							esc_html__( 'Button text on the primary colour: %s:1', 'event-ticket-manager' ),
							esc_html( (string) $etm_button_contrast )
						);
						echo ' ';
						echo $etm_button_contrast >= 4.5
							? esc_html__( '(passes WCAG AA)', 'event-ticket-manager' )
							: esc_html__( '(below the 4.5:1 minimum - pick a lighter or darker button text colour)', 'event-ticket-manager' );
						?>
					</p>

					<p class="<?php echo $etm_text_contrast >= 4.5 ? 'etm-status-ok' : 'etm-status-warn'; ?>">
						<?php
						printf(
							/* translators: %s: contrast ratio, for example 17.74. */
							esc_html__( 'Body text on the background colour: %s:1', 'event-ticket-manager' ),
							esc_html( (string) $etm_text_contrast )
						);
						echo ' ';
						echo $etm_text_contrast >= 4.5
							? esc_html__( '(passes WCAG AA)', 'event-ticket-manager' )
							: esc_html__( '(below the 4.5:1 minimum - increase the difference between them)', 'event-ticket-manager' );
						?>
					</p>

					<p class="description">
						<?php esc_html_e( 'Ratios are recalculated when you save. The completed-step tick picks its own readable colour automatically.', 'event-ticket-manager' ); ?>
					</p>
				</div>

				<?php submit_button(); ?>
			</div>
		</div>

		<div class="etm-columns__side">
			<div class="etm-card">
				<h2 class="etm-card__title"><?php esc_html_e( 'Live preview', 'event-ticket-manager' ); ?></h2>

				<div
					class="etm-preview"
					data-etm-preview
					style="
						--preview-primary: <?php echo esc_attr( $etm_branding['primary_color'] ); ?>;
						--preview-secondary: <?php echo esc_attr( $etm_branding['secondary_color'] ); ?>;
						--preview-accent: <?php echo esc_attr( $etm_branding['accent_color'] ); ?>;
						--preview-text: <?php echo esc_attr( $etm_branding['text_color'] ); ?>;
						--preview-bg: <?php echo esc_attr( $etm_branding['background_color'] ); ?>;
						--preview-on-primary: <?php echo esc_attr( $etm_branding['button_text_color'] ); ?>;
					"
				>
					<div class="etm-preview__card">
						<span class="etm-preview__label"><?php esc_html_e( 'Tickets', 'event-ticket-manager' ); ?></span>
						<strong class="etm-preview__title"><?php esc_html_e( 'Summer Retreat', 'event-ticket-manager' ); ?></strong>

						<div class="etm-preview__addon">
							<span class="etm-preview__addon-text"><?php esc_html_e( 'Sharing a Room', 'event-ticket-manager' ); ?></span>
							<span class="etm-preview__price">$75 <?php esc_html_e( 'each', 'event-ticket-manager' ); ?></span>
							<span class="etm-preview__stepper">
								<span class="etm-preview__stepper-btn">&minus;</span>
								<span class="etm-preview__stepper-value">1</span>
								<span class="etm-preview__stepper-btn">+</span>
							</span>
						</div>

						<div class="etm-preview__steps">
							<span class="etm-preview__step is-done"></span>
							<span class="etm-preview__step is-active"></span>
							<span class="etm-preview__step"></span>
							<span class="etm-preview__step"></span>
						</div>

						<button type="button" class="etm-preview__button" disabled>
							<?php esc_html_e( 'Get Tickets', 'event-ticket-manager' ); ?>
						</button>
					</div>
				</div>

				<p class="description">
					<?php esc_html_e( 'The preview updates as you pick colours. Save to apply them on the frontend.', 'event-ticket-manager' ); ?>
				</p>
			</div>

			<div class="etm-card etm-card--info">
				<h2 class="etm-card__title"><?php esc_html_e( 'CSS variables', 'event-ticket-manager' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Available to your own stylesheets on .etm-block and .etm-modal:', 'event-ticket-manager' ); ?>
				</p>
				<ul class="etm-list">
					<li><code>--etm-primary</code></li>
					<li><code>--etm-secondary</code></li>
					<li><code>--etm-accent-color</code></li>
					<li><code>--etm-text</code></li>
					<li><code>--etm-surface</code></li>
					<li><code>--etm-on-primary</code></li>
				</ul>
			</div>
		</div>
	</div>
</form>
