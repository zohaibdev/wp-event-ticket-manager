<?php
/**
 * Multi-step checkout modal with in-modal Stripe payment.
 *
 * Override by copying to {theme}/event-ticket-manager/checkout-modal.php.
 *
 * @package EventTicketManager
 *
 * @var object $event                 Event row.
 * @var int    $event_id              Event ID.
 * @var string $uid                   Unique DOM id for this instance.
 * @var string $currency              Currency code.
 * @var array  $ticket_types          Enabled ticket types: id, title, price, max.
 * @var bool   $is_rsvp               Whether the event takes registrations rather than payment.
 * @var array  $customer_fields       Customer field definitions.
 * @var array  $attendee_fields       Attendee field definitions.
 * @var array  $attendee_extra_fields Non-core attendee fields.
 * @var array  $roommate_fields       Roommate field definitions.
 * @var bool   $coupon_enabled        Whether the coupon field is offered.
 */

defined( 'ABSPATH' ) || exit;

$modal_id = $uid . '-modal';

$etm_steps = array(
	1 => array(
		'title' => __( 'Your Details', 'event-ticket-manager' ),
		'icon'  => '<path d="M10 10a3.5 3.5 0 100-7 3.5 3.5 0 000 7z"/><path d="M3.5 17a6.5 6.5 0 0113 0"/>',
	),
	2 => array(
		'title' => __( 'Review', 'event-ticket-manager' ),
		'icon'  => '<path d="M7 3h6v2.5H7zM6 5H4.5v12h11V5H14"/><path d="M7.5 10h5M7.5 13h5"/>',
	),
	3 => array(
		'title' => __( 'Payment', 'event-ticket-manager' ),
		'icon'  => '<rect x="2.5" y="4.5" width="15" height="11" rx="1.8"/><path d="M2.5 8.5h15M5.5 12.5h3"/>',
	),
	4 => array(
		'title' => __( 'Complete', 'event-ticket-manager' ),
		'icon'  => '<circle cx="10" cy="10" r="7.2"/><path d="M6.8 10.2l2.2 2.2 4.2-4.6"/>',
	),
);
?>
<div class="etm-modal" id="<?php echo esc_attr( $modal_id ); ?>" data-etm-modal hidden>
	<div class="etm-modal__overlay" data-etm-close tabindex="-1"></div>

	<div
		class="etm-modal__dialog"
		role="dialog"
		aria-modal="true"
		aria-labelledby="<?php echo esc_attr( $modal_id ); ?>-title"
	>
		<header class="etm-modal__header">
			<div class="etm-modal__heading">
				<h2 class="etm-modal__title" id="<?php echo esc_attr( $modal_id ); ?>-title">
					<?php echo esc_html( $event->title ); ?>
				</h2>
				<?php $etm_range = etm_event_date_range( $event ); ?>
				<?php if ( '' !== $etm_range ) : ?>
					<p class="etm-modal__subtitle"><?php echo esc_html( $etm_range ); ?></p>
				<?php endif; ?>
			</div>

			<button
				type="button"
				class="etm-modal__close"
				data-etm-close
				aria-label="<?php esc_attr_e( 'Close checkout', 'event-ticket-manager' ); ?>"
			>
				<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true" focusable="false">
					<path d="M5 5l10 10M15 5L5 15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
				</svg>
			</button>
		</header>

		<!-- Step indicator -->
		<ol class="etm-steps" aria-label="<?php esc_attr_e( 'Checkout progress', 'event-ticket-manager' ); ?>">
			<span class="etm-steps__track" aria-hidden="true">
				<span class="etm-steps__progress" data-etm-progress></span>
			</span>

			<?php foreach ( $etm_steps as $etm_number => $etm_step ) : ?>
				<li class="etm-steps__item" data-etm-step-label="<?php echo esc_attr( (string) $etm_number ); ?>">
					<span class="etm-steps__marker">
						<svg class="etm-steps__icon" viewBox="0 0 20 20" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
							<?php echo $etm_step['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG paths. ?>
						</svg>
						<svg class="etm-steps__check" viewBox="0 0 20 20" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
							<path d="M5 10.5l3.5 3.5L15 6.5" />
						</svg>
					</span>
					<span class="etm-steps__text">
						<span class="etm-steps__number">
							<?php
							printf(
								/* translators: %d: step number. */
								esc_html__( 'Step %d', 'event-ticket-manager' ),
								(int) $etm_number
							);
							?>
						</span>
						<span class="etm-steps__title"><?php echo esc_html( $etm_step['title'] ); ?></span>
					</span>
				</li>
			<?php endforeach; ?>
		</ol>

		<form class="etm-modal__body" data-etm-form novalidate>
			<div class="etm-alert" data-etm-alert role="alert" hidden></div>

			<!-- Step 1: purchaser and attendee information -->
			<section class="etm-step" data-etm-step="1">
				<fieldset class="etm-fieldset">
					<legend class="etm-fieldset__legend">
						<span class="etm-fieldset__badge"><?php esc_html_e( 'You', 'event-ticket-manager' ); ?></span>
						<?php esc_html_e( 'Your Details', 'event-ticket-manager' ); ?>
					</legend>

					<p class="etm-fieldset__hint" data-etm-purchaser-hint>
						<?php esc_html_e( 'You are the first attendee on this booking.', 'event-ticket-manager' ); ?>
						<span class="etm-fieldset__ticket" data-etm-purchaser-ticket></span>
					</p>

					<div class="etm-grid">
						<?php foreach ( $customer_fields as $field ) : ?>
							<?php
							echo ETM_Custom_Fields::render_field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_field().
								$field,
								'customer[' . $field['key'] . ']',
								'',
								$uid . '-customer-' . $field['key']
							);
							?>
						<?php endforeach; ?>

						<?php foreach ( $attendee_extra_fields as $field ) : ?>
							<?php
							// Extra attendee questions for the purchaser (attendee 1).
							echo ETM_Custom_Fields::render_field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_field().
								$field,
								'attendees[0][' . $field['key'] . ']',
								'',
								$uid . '-attendee-0-' . $field['key']
							);
							?>
						<?php endforeach; ?>
					</div>
				</fieldset>

				<div data-etm-attendees></div>

				<?php if ( ! empty( $roommate_fields ) ) : ?>
					<!-- Shown whenever the order contains a Sharing a Room ticket,
					     once and once only: the purchaser is already known from
					     Your Details above, so the room's other occupant is the
					     only person still to name. Its inputs are disabled while
					     hidden, which keeps them out of both validation and the
					     submitted data. -->
					<fieldset class="etm-fieldset etm-fieldset--roommate" data-etm-sharing-room hidden>
						<legend class="etm-fieldset__legend">
							<span class="etm-fieldset__badge etm-fieldset__badge--accent"><?php esc_html_e( 'Sharing a Room', 'event-ticket-manager' ); ?></span>
							<?php esc_html_e( 'Roommate Details', 'event-ticket-manager' ); ?>
						</legend>

						<p class="etm-fieldset__hint">The person sharing your room. <strong>Your own details are the ones you gave above.</strong></p>


						<div class="etm-grid">
							<?php foreach ( $roommate_fields as $field ) : ?>
								<?php
								echo ETM_Custom_Fields::render_field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_field().
									$field,
									'sharing_room[' . $field['key'] . ']',
									'',
									$uid . '-sharing-room-' . $field['key']
								);
								?>
							<?php endforeach; ?>
						</div>
					</fieldset>
				<?php endif; ?>
			</section>

			<!-- Step 2: order review -->
			<section class="etm-step" data-etm-step="2" hidden>
				<h3 class="etm-step__title"><?php esc_html_e( 'Review your order', 'event-ticket-manager' ); ?></h3>
				<p class="etm-step__hint"><?php esc_html_e( 'Check everything is correct before paying.', 'event-ticket-manager' ); ?></p>

				<div class="etm-summary">
					<div class="etm-summary__head">
						<span class="etm-summary__event"><?php echo esc_html( $event->title ); ?></span>
						<?php $etm_date_range = etm_event_date_range( $event ); ?>
						<?php if ( '' !== $etm_date_range ) : ?>
							<span class="etm-summary__date"><?php echo esc_html( $etm_date_range ); ?></span>
						<?php endif; ?>
					</div>

					<dl class="etm-summary__lines">
						<!-- One row per selected ticket type, filled in by the browser
						     and re-verified server-side before payment. -->
						<div data-etm-review-tickets></div>

						<!-- Subtotal, fees and any discount, priced by the server. -->
						<div data-etm-review-summary></div>

						<div class="etm-summary__row etm-summary__row--total">
							<dt><?php esc_html_e( 'Total', 'event-ticket-manager' ); ?></dt>
							<dd data-etm-review-total><?php echo esc_html( etm_format_price( 0, $currency ) ); ?></dd>
						</div>
					</dl>
				</div>

				<?php if ( ! $is_rsvp && ( $coupon_enabled ?? true ) ) : ?>
					<div class="etm-coupon" data-etm-coupon>
						<label class="etm-coupon__label" for="<?php echo esc_attr( $uid ); ?>-coupon">
							<?php esc_html_e( 'Coupon code', 'event-ticket-manager' ); ?>
						</label>

						<div class="etm-coupon__row" data-etm-coupon-entry>
							<input
								type="text"
								id="<?php echo esc_attr( $uid ); ?>-coupon"
								class="etm-input etm-coupon__input"
								data-etm-coupon-input
								autocomplete="off"
								placeholder="<?php esc_attr_e( 'Enter code', 'event-ticket-manager' ); ?>"
							/>
							<button type="button" class="etm-button etm-button--ghost" data-etm-coupon-apply>
								<?php esc_html_e( 'Apply', 'event-ticket-manager' ); ?>
							</button>
						</div>

						<div class="etm-coupon__applied" data-etm-coupon-applied hidden>
							<span class="etm-coupon__code" data-etm-coupon-code></span>
							<button type="button" class="etm-coupon__remove" data-etm-coupon-remove>
								<?php esc_html_e( 'Remove', 'event-ticket-manager' ); ?>
							</button>
						</div>

						<p class="etm-coupon__message" data-etm-coupon-message role="status"></p>
					</div>
				<?php endif; ?>

				<div class="etm-summary__people" data-etm-review-people></div>
			</section>

			<!-- Step 3: payment, handled inside this modal by Stripe Elements -->
			<section class="etm-step" data-etm-step="3" hidden>
				<h3 class="etm-step__title"><?php esc_html_e( 'Payment', 'event-ticket-manager' ); ?></h3>
				<p class="etm-step__hint">
					<?php esc_html_e( 'Your card details are handled directly by Stripe and never touch this site.', 'event-ticket-manager' ); ?>
				</p>

				<div class="etm-payment">
					<div class="etm-payment__loading" data-etm-payment-loading>
						<span class="etm-spinner" aria-hidden="true"></span>
						<span><?php esc_html_e( 'Preparing secure payment...', 'event-ticket-manager' ); ?></span>
					</div>

					<div class="etm-payment__element" data-etm-payment-element></div>

					<p class="etm-payment__secure">
						<svg viewBox="0 0 20 20" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true" focusable="false">
							<rect x="4.5" y="8.5" width="11" height="8" rx="1.6" />
							<path d="M7 8.5V6.8a3 3 0 016 0v1.7" />
						</svg>
						<?php esc_html_e( 'Secured by Stripe', 'event-ticket-manager' ); ?>
					</p>
				</div>
			</section>

			<!-- Step 4: order complete -->
			<section class="etm-step etm-step--complete" data-etm-step="4" hidden>
				<div class="etm-complete__icon" aria-hidden="true">
					<svg viewBox="0 0 48 48" width="46" height="46" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
						<circle cx="24" cy="24" r="20" />
						<path d="M15 24.5l6.5 6.5L33 19" />
					</svg>
				</div>

				<h3 class="etm-complete__title"><?php esc_html_e( 'Order Complete', 'event-ticket-manager' ); ?></h3>
				<p class="etm-complete__message">
					<?php esc_html_e( 'Your booking has been confirmed.', 'event-ticket-manager' ); ?>
				</p>

				<div class="etm-complete__number">
					<span class="etm-complete__number-label"><?php esc_html_e( 'Booking number', 'event-ticket-manager' ); ?></span>
					<strong data-etm-complete-number>&mdash;</strong>
				</div>

				<div class="etm-summary etm-summary--complete" data-etm-complete-summary></div>

				<p class="etm-complete__email" data-etm-complete-email></p>
			</section>
		</form>

		<footer class="etm-modal__footer">
			<button type="button" class="etm-button etm-button--ghost" data-etm-back hidden>
				<?php esc_html_e( 'Back', 'event-ticket-manager' ); ?>
			</button>

			<div class="etm-modal__footer-right">
				<span class="etm-modal__total" data-etm-footer-total-wrap<?php echo $is_rsvp ? ' hidden' : ''; ?>>
					<span class="etm-modal__total-label"><?php esc_html_e( 'Total', 'event-ticket-manager' ); ?></span>
					<strong data-etm-modal-total><?php echo esc_html( etm_format_price( 0, $currency ) ); ?></strong>
				</span>

				<button type="button" class="etm-button etm-button--primary" data-etm-next>
					<?php esc_html_e( 'Continue', 'event-ticket-manager' ); ?>
				</button>

				<button type="button" class="etm-button etm-button--primary" data-etm-to-payment hidden>
					<?php esc_html_e( 'Continue to Payment', 'event-ticket-manager' ); ?>
				</button>

				<button type="button" class="etm-button etm-button--primary" data-etm-pay hidden>
					<span data-etm-pay-label><?php esc_html_e( 'Pay', 'event-ticket-manager' ); ?></span>
				</button>

				<button type="button" class="etm-button etm-button--primary" data-etm-done hidden>
					<?php esc_html_e( 'Done', 'event-ticket-manager' ); ?>
				</button>
			</div>
		</footer>
	</div>
</div>

<template data-etm-attendee-template>
	<fieldset class="etm-fieldset etm-fieldset--attendee">
		<legend class="etm-fieldset__legend">
			<span class="etm-fieldset__badge etm-fieldset__badge--muted" data-etm-attendee-badge>2</span>
			<span data-etm-attendee-label><?php esc_html_e( 'Attendee', 'event-ticket-manager' ); ?></span>
		</legend>
		<div class="etm-grid">
			<?php foreach ( $attendee_fields as $field ) : ?>
				<?php
				echo ETM_Custom_Fields::render_field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_field().
					$field,
					'attendees[__INDEX__][' . $field['key'] . ']',
					'',
					$uid . '-attendee-__INDEX__-' . $field['key']
				);
				?>
			<?php endforeach; ?>
		</div>
	</fieldset>
</template>
