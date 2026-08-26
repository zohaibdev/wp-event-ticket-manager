<?php
/**
 * Frontend ticket purchase block.
 *
 * Override by copying to {theme}/event-ticket-manager/event-ticket-block.php.
 *
 * @package EventTicketManager
 *
 * @var object $event                 Event row.
 * @var int    $event_id              Event ID.
 * @var string $uid                   Unique DOM id for this instance.
 * @var array  $ticket_types          Enabled ticket types: id, title, price, min, max, price_label, variation_type.
 * @var bool   $has_tickets           Whether any ticket type is sellable.
 * @var int    $event_cap             Remaining event capacity, or -1 for unlimited.
 * @var string $currency              Currency code.
 * @var string $description           Event description, may be empty.
 * @var string $instruction           Event instructions, may be empty.
 * @var bool   $is_rsvp               Whether the event takes registrations rather than payment.
 * @var bool   $sold_out              Whether the event is sold out.
 * @var bool   $purchasable           Whether tickets can be bought right now.
 * @var bool   $payments_ready        Whether Stripe is configured.
 * @var array  $customer_fields       Customer field definitions.
 * @var array  $attendee_fields       Attendee field definitions.
 * @var array  $attendee_extra_fields Non-core attendee fields.
 * @var array  $roommate_fields       Roommate field definitions.
 */

defined( 'ABSPATH' ) || exit;
?>
<div
	class="etm-block<?php echo $sold_out ? ' etm-block--sold-out' : ''; ?>"
	id="<?php echo esc_attr( $uid ); ?>"
	data-etm-block
	data-event-id="<?php echo esc_attr( (string) $event_id ); ?>"
	data-event-cap="<?php echo esc_attr( (string) $event_cap ); ?>"
	data-is-rsvp="<?php echo $is_rsvp ? '1' : '0'; ?>"
	<?php
	// Fallback for the localised script: see ETM_Shortcodes::render_event_ticket().
	// Only read when window.etmConfig is missing, so the normal path is unchanged.
	?>
	data-etm-ajax-url="<?php echo esc_attr( (string) ( $ajax_url ?? '' ) ); ?>"
	data-etm-nonce="<?php echo esc_attr( (string) ( $nonce ?? '' ) ); ?>"
	data-etm-currency-symbol="<?php echo esc_attr( (string) ( $currency_symbol ?? '' ) ); ?>"
	data-etm-currency-decimals="<?php echo esc_attr( (string) ( $currency_decimals ?? 2 ) ); ?>"
>
	<div class="etm-block__inner">
		<div class="etm-block__info">
			<span class="etm-block__label"><?php esc_html_e( 'Tickets', 'event-ticket-manager' ); ?></span>
			<h3 class="etm-block__title"><?php echo esc_html( $event->title ); ?></h3>

			<?php if ( '' !== trim( (string) $description ) ) : ?>
				<div class="etm-block__description">
					<?php echo wp_kses_post( wpautop( $description ) ); ?>
				</div>
			<?php endif; ?>

			<?php $date_range = etm_event_date_range( $event ); ?>
			<?php if ( '' !== $date_range ) : ?>
				<p class="etm-block__meta">
					<svg class="etm-icon" viewBox="0 0 20 20" width="15" height="15" aria-hidden="true" focusable="false">
						<path d="M6 2v3M14 2v3M3 8h14M4 4h12a1 1 0 011 1v11a1 1 0 01-1 1H4a1 1 0 01-1-1V5a1 1 0 011-1z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
					</svg>
					<?php echo esc_html( $date_range ); ?>
				</p>
			<?php endif; ?>
		</div>

		<?php if ( $sold_out ) : ?>
			<p class="etm-block__status etm-block__status--sold-out"><?php esc_html_e( 'Sold out', 'event-ticket-manager' ); ?></p>
		<?php elseif ( ! $payments_ready ) : ?>
			<p class="etm-block__status"><?php esc_html_e( 'Ticket sales are currently unavailable.', 'event-ticket-manager' ); ?></p>
		<?php elseif ( ! $purchasable ) : ?>
			<p class="etm-block__status"><?php esc_html_e( 'Ticket sales are closed for this event.', 'event-ticket-manager' ); ?></p>
		<?php else : ?>
			<div class="etm-tickets" data-etm-ticket-list>
				<?php foreach ( $ticket_types as $etm_index => $type ) : ?>
					<?php
					$row_id = $uid . '-type-' . (int) $type['id'];

					// Both bounds come from the ticket type itself; no position in
					// the list is treated as special.
					$etm_min = max( 0, (int) $type['min'] );
					?>
					<div
						class="etm-ticket<?php echo $type['max'] < 1 ? ' etm-ticket--unavailable' : ''; ?>"
						data-etm-ticket
						data-ticket-id="<?php echo esc_attr( (string) $type['id'] ); ?>"
						data-ticket-title="<?php echo esc_attr( $type['title'] ); ?>"
						data-ticket-price="<?php echo esc_attr( (string) $type['price'] ); ?>"
						data-ticket-label="<?php echo esc_attr( (string) $type['price_label'] ); ?>"
						data-ticket-max="<?php echo esc_attr( (string) $type['max'] ); ?>"
						data-ticket-min="<?php echo esc_attr( (string) $etm_min ); ?>"
						data-ticket-variation="<?php echo esc_attr( (string) $type['variation_type'] ); ?>"
					>
						<div class="etm-ticket__info">
							<span class="etm-ticket__name" id="<?php echo esc_attr( $row_id ); ?>-label">
								<?php echo esc_html( $type['title'] ); ?>
							</span>
							<span class="etm-ticket__price">
								<?php echo esc_html( etm_format_price( (float) $type['price'], $currency ) ); ?>
								<span class="etm-ticket__each"><?php echo esc_html( $type['price_label'] ); ?></span>
							</span>
						</div>

						<?php if ( $type['max'] < 1 ) : ?>
							<span class="etm-ticket__soldout"><?php esc_html_e( 'Sold out', 'event-ticket-manager' ); ?></span>
						<?php else : ?>
							<div class="etm-ticket__controls">
								<span class="etm-ticket__subtotal" data-etm-line-subtotal aria-live="polite"></span>

								<div class="etm-stepper">
									<button
										type="button"
										class="etm-stepper__btn"
										data-etm-qty-down
										aria-label="<?php
										/* translators: %s: ticket type name. */
										printf( esc_attr__( 'Decrease %s quantity', 'event-ticket-manager' ), esc_attr( $type['title'] ) );
										?>"
									>&minus;</button>
									<input
										type="number"
										class="etm-stepper__input"
										data-etm-qty
										value="<?php echo esc_attr( (string) $etm_min ); ?>"
										min="<?php echo esc_attr( (string) $etm_min ); ?>"
										max="<?php echo esc_attr( (string) $type['max'] ); ?>"
										step="1"
										inputmode="numeric"
										aria-labelledby="<?php echo esc_attr( $row_id ); ?>-label"
									/>
									<button
										type="button"
										class="etm-stepper__btn"
										data-etm-qty-up
										aria-label="<?php
										/* translators: %s: ticket type name. */
										printf( esc_attr__( 'Increase %s quantity', 'event-ticket-manager' ), esc_attr( $type['title'] ) );
										?>"
									>+</button>
								</div>
							</div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>

			</div>

			<?php
			// The block opens on whatever the configured minimums add up to.
			$etm_start_total = 0.0;
			$etm_start_qty   = 0;

			foreach ( $ticket_types as $etm_type ) {
				$etm_type_min     = max( 0, min( (int) $etm_type['min'], (int) $etm_type['max'] ) );
				$etm_start_qty   += $etm_type_min;
				$etm_start_total += $etm_type_min * (float) $etm_type['price'];
			}

			?>

			<div class="etm-block__footer">
				<?php if ( ! $is_rsvp ) : ?>
					<div class="etm-total">
						<span class="etm-total__label"><?php esc_html_e( 'Total', 'event-ticket-manager' ); ?></span>
						<span class="etm-total__value" data-etm-total aria-live="polite">
							<?php echo esc_html( etm_format_price( $etm_start_total, $currency ) ); ?>
						</span>
					</div>
				<?php endif; ?>

				<button
					type="button"
					class="etm-button etm-button--primary"
					data-etm-open
					<?php disabled( $etm_start_qty < 1 ); ?>
				>
					<?php
					echo $is_rsvp
						? esc_html__( 'RSVP', 'event-ticket-manager' )
						: esc_html__( 'Get Tickets', 'event-ticket-manager' );
					?>
				</button>
			</div>
		<?php endif; ?>

		<?php if ( '' !== trim( (string) $instruction ) ) : ?>
			<div class="etm-instructions">
				<?php echo wp_kses_post( wpautop( $instruction ) ); ?>
			</div>
		<?php endif; ?>
	</div>

	<?php
	if ( $purchasable ) {
		echo etm_get_template( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template escapes its own output.
			'checkout-modal.php',
			array(
				'event'                 => $event,
				'event_id'              => $event_id,
				'uid'                   => $uid,
				'currency'              => $currency,
				'ticket_types'          => $ticket_types,
				'is_rsvp'               => $is_rsvp,
				'customer_fields'       => $customer_fields,
				'attendee_fields'       => $attendee_fields,
				'attendee_extra_fields' => $attendee_extra_fields,
				'roommate_fields'       => $roommate_fields,
				'coupon_enabled'        => $coupon_enabled ?? true,
			)
		);
	}
	?>
</div>
