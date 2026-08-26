<?php
/**
 * Event add/edit form.
 *
 * @package EventTicketManager
 *
 * @var object|null $event  Event row when editing.
 * @var array       $meta   Event meta values.
 * @var array       $fields Event custom field definitions.
 */

defined( 'ABSPATH' ) || exit;

$etm_user_id = get_current_user_id();
$etm_errors  = get_transient( 'etm_event_errors_' . $etm_user_id );
$etm_input   = get_transient( 'etm_event_input_' . $etm_user_id );

delete_transient( 'etm_event_errors_' . $etm_user_id );
delete_transient( 'etm_event_input_' . $etm_user_id );

$etm_errors = is_array( $etm_errors ) ? $etm_errors : array();
$etm_input  = is_array( $etm_input ) ? $etm_input : array();

/**
 * Current value for a field: rejected submission first, then the stored event.
 *
 * @param string      $key      Column name.
 * @param array       $input    Rejected input.
 * @param object|null $event    Event row.
 * @param mixed       $fallback Default value.
 * @return mixed
 */
$etm_value = static function ( string $key, array $input, ?object $event, $fallback = '' ) {
	if ( array_key_exists( $key, $input ) ) {
		return $input[ $key ];
	}

	if ( $event && isset( $event->$key ) ) {
		return $event->$key;
	}

	return $fallback;
};

$etm_datetime_value = static function ( $value ): string {
	if ( empty( $value ) || '0000-00-00 00:00:00' === $value ) {
		return '';
	}

	$timestamp = strtotime( (string) $value );

	return $timestamp ? gmdate( 'Y-m-d\TH:i', $timestamp ) : '';
};

$etm_is_edit = (bool) $event;
?>
<div class="wrap etm-wrap">
	<h1 class="wp-heading-inline">
		<?php echo $etm_is_edit ? esc_html__( 'Edit Event', 'event-ticket-manager' ) : esc_html__( 'Add Event', 'event-ticket-manager' ); ?>
	</h1>

	<a href="<?php echo esc_url( admin_url( 'admin.php?page=etm-events' ) ); ?>" class="page-title-action">
		<?php esc_html_e( 'Back to events', 'event-ticket-manager' ); ?>
	</a>

	<hr class="wp-header-end" />

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="etm-form">
		<?php wp_nonce_field( 'etm_save_event' ); ?>
		<input type="hidden" name="action" value="etm_save_event" />
		<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) ( $event->id ?? 0 ) ); ?>" />

		<div class="etm-columns">
			<div class="etm-columns__main">
				<div class="etm-card">
					<h2 class="etm-card__title"><?php esc_html_e( 'Event details', 'event-ticket-manager' ); ?></h2>

					<div class="etm-admin-field">
						<label for="etm-title"><?php esc_html_e( 'Event Title', 'event-ticket-manager' ); ?> <span class="etm-required">*</span></label>
						<input
							type="text"
							id="etm-title"
							name="title"
							class="regular-text etm-w-100"
							value="<?php echo esc_attr( (string) $etm_value( 'title', $etm_input, $event ) ); ?>"
							required
						/>
						<?php if ( isset( $etm_errors['title'] ) ) : ?>
							<p class="etm-error"><?php echo esc_html( $etm_errors['title'] ); ?></p>
						<?php endif; ?>
					</div>

					<div class="etm-admin-field">
						<label for="etm-description"><?php esc_html_e( 'Description', 'event-ticket-manager' ); ?></label>
						<?php
						wp_editor(
							(string) $etm_value( 'description', $etm_input, $event ),
							'etm-description',
							array(
								'textarea_name' => 'description',
								'textarea_rows' => 8,
								'media_buttons' => false,
							)
						);
						?>
					</div>

					<div class="etm-admin-row">
						<div class="etm-admin-field">
							<label for="etm-event-type"><?php esc_html_e( 'Event Type', 'event-ticket-manager' ); ?></label>
							<select id="etm-event-type" name="event_type">
								<?php $etm_event_type = (string) ( $meta['event_type'] ?? ETM_Events::TYPE_TICKET ); ?>
								<?php foreach ( ETM_Events::types() as $etm_type_key => $etm_type_label ) : ?>
									<option value="<?php echo esc_attr( $etm_type_key ); ?>" <?php selected( $etm_event_type, $etm_type_key ); ?>>
										<?php echo esc_html( $etm_type_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'An RSVP event takes registrations and never asks for payment.', 'event-ticket-manager' ); ?>
							</p>
						</div>

						<div class="etm-admin-field" id="etm-rsvp-email">
							<label for="etm-rsvp-notification"><?php esc_html_e( 'Event Notification Emails', 'event-ticket-manager' ); ?></label>
							<textarea
								id="etm-rsvp-notification"
								name="rsvp_notification_email"
								class="regular-text"
								rows="3"
								placeholder="events@example.com, manager@example.com"
							><?php echo esc_textarea( (string) ( $meta['rsvp_notification_email'] ?? '' ) ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Optional. Overrides the addresses set under Settings > Email for every booking on this event, ticket or RSVP. One address per line or comma-separated. Leave empty to use the global settings.', 'event-ticket-manager' ); ?>
							</p>
							<?php if ( isset( $etm_errors['rsvp_notification_email'] ) ) : ?>
								<p class="etm-error"><?php echo esc_html( $etm_errors['rsvp_notification_email'] ); ?></p>
							<?php endif; ?>
						</div>
					</div>

					<div class="etm-admin-field">
						<label for="etm-instruction"><?php esc_html_e( 'Event Instructions', 'event-ticket-manager' ); ?></label>
						<?php
						// Stored in event meta, so no new database column is needed.
						wp_editor(
							(string) ( $meta['instruction'] ?? '' ),
							'etm-instruction',
							array(
								'textarea_name' => 'instruction',
								'textarea_rows' => 5,
								'media_buttons' => false,
							)
						);
						?>
						<p class="description">
							<?php esc_html_e( 'Shown to customers at the bottom of the ticket block, under the Get Tickets button. Leave empty to hide it.', 'event-ticket-manager' ); ?>
						</p>
					</div>

					<div class="etm-admin-row">
						<div class="etm-admin-field">
							<label for="etm-start"><?php esc_html_e( 'Start Date & Time', 'event-ticket-manager' ); ?> <span class="etm-required">*</span></label>
							<input
								type="datetime-local"
								id="etm-start"
								name="start_datetime"
								value="<?php echo esc_attr( $etm_datetime_value( $etm_value( 'start_datetime', $etm_input, $event ) ) ); ?>"
								required
							/>
							<?php if ( isset( $etm_errors['start_datetime'] ) ) : ?>
								<p class="etm-error"><?php echo esc_html( $etm_errors['start_datetime'] ); ?></p>
							<?php endif; ?>
						</div>

						<div class="etm-admin-field">
							<label for="etm-end"><?php esc_html_e( 'End Date & Time', 'event-ticket-manager' ); ?> <span class="etm-required">*</span></label>
							<input
								type="datetime-local"
								id="etm-end"
								name="end_datetime"
								value="<?php echo esc_attr( $etm_datetime_value( $etm_value( 'end_datetime', $etm_input, $event ) ) ); ?>"
								required
							/>
							<?php if ( isset( $etm_errors['end_datetime'] ) ) : ?>
								<p class="etm-error"><?php echo esc_html( $etm_errors['end_datetime'] ); ?></p>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<div class="etm-card">
					<h2 class="etm-card__title"><?php esc_html_e( 'Tickets', 'event-ticket-manager' ); ?></h2>

					<?php
					$etm_ticket_types  = $etm_is_edit ? ETM_Event_Tickets::get_for_event( (int) $event->id ) : array();
					$etm_ticket_errors = get_transient( 'etm_ticket_errors_' . $etm_user_id );

					delete_transient( 'etm_ticket_errors_' . $etm_user_id );

					$etm_ticket_errors = is_array( $etm_ticket_errors ) ? $etm_ticket_errors : array();

					/**
					 * Render one ticket type row.
					 *
					 * @param int         $index  Row index.
					 * @param object|null $ticket Ticket row.
					 */
					$etm_ticket_row = static function ( int $index, ?object $ticket = null ) {
						$name = 'event_tickets[' . $index . ']';
						?>
						<div class="etm-ticket-row" data-etm-ticket-row>
							<div class="etm-ticket-row__handle">
								<button type="button" class="button-link etm-move" data-etm-move="up" aria-label="<?php esc_attr_e( 'Move up', 'event-ticket-manager' ); ?>">&uarr;</button>
								<button type="button" class="button-link etm-move" data-etm-move="down" aria-label="<?php esc_attr_e( 'Move down', 'event-ticket-manager' ); ?>">&darr;</button>
							</div>

							<input type="hidden" name="<?php echo esc_attr( $name ); ?>[id]" value="<?php echo esc_attr( (string) ( $ticket->id ?? 0 ) ); ?>" />

							<div class="etm-ticket-row__field etm-ticket-row__field--title">
								<label><?php esc_html_e( 'Ticket Name', 'event-ticket-manager' ); ?></label>
								<input
									type="text"
									name="<?php echo esc_attr( $name ); ?>[title]"
									value="<?php echo esc_attr( (string) ( $ticket->title ?? '' ) ); ?>"
									placeholder="<?php esc_attr_e( 'e.g. Adult', 'event-ticket-manager' ); ?>"
									class="regular-text"
								/>
							</div>

							<div class="etm-ticket-row__field">
								<label><?php esc_html_e( 'Ticket Type', 'event-ticket-manager' ); ?></label>
								<?php $etm_variation_type = ETM_Event_Tickets::sanitize_variation_type( $ticket->variation_type ?? '' ); ?>
								<select name="<?php echo esc_attr( $name ); ?>[variation_type]">
									<?php foreach ( ETM_Event_Tickets::variation_types() as $etm_type_key => $etm_type_label ) : ?>
										<option value="<?php echo esc_attr( $etm_type_key ); ?>" <?php selected( $etm_variation_type, $etm_type_key ); ?>>
											<?php echo esc_html( $etm_type_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>

							<div class="etm-ticket-row__field">
								<label>
									<?php
									printf(
										/* translators: %s: currency code. */
										esc_html__( 'Price (%s)', 'event-ticket-manager' ),
										esc_html( ETM_Settings::currency() )
									);
									?>
								</label>
								<input
									type="number"
									name="<?php echo esc_attr( $name ); ?>[price]"
									value="<?php echo esc_attr( (string) ( $ticket->price ?? '' ) ); ?>"
									min="0"
									step="0.01"
									placeholder="0.00"
								/>
							</div>

							<div class="etm-ticket-row__field">
								<label><?php esc_html_e( 'Price Label', 'event-ticket-manager' ); ?></label>
								<input
									type="text"
									name="<?php echo esc_attr( $name ); ?>[price_label]"
									value="<?php echo esc_attr( (string) ( $ticket->price_label ?? '' ) ); ?>"
									placeholder="<?php esc_attr_e( 'each', 'event-ticket-manager' ); ?>"
								/>
							</div>

							<div class="etm-ticket-row__field etm-ticket-row__field--qty">
								<label><?php esc_html_e( 'Min Qty', 'event-ticket-manager' ); ?></label>
								<input
									type="number"
									name="<?php echo esc_attr( $name ); ?>[min_quantity]"
									value="<?php echo esc_attr( (string) (int) ( $ticket->min_quantity ?? 0 ) ); ?>"
									min="0"
									step="1"
								/>
							</div>

							<div class="etm-ticket-row__field etm-ticket-row__field--qty">
								<label><?php esc_html_e( 'Max Qty', 'event-ticket-manager' ); ?></label>
								<input
									type="number"
									name="<?php echo esc_attr( $name ); ?>[max_quantity]"
									value="<?php echo esc_attr( (string) (int) ( $ticket->max_quantity ?? 0 ) ); ?>"
									min="0"
									step="1"
									placeholder="0"
								/>
							</div>

							<div class="etm-ticket-row__field etm-ticket-row__field--flags">
								<label class="etm-checkbox">
									<input
										type="checkbox"
										name="<?php echo esc_attr( $name ); ?>[enabled]"
										value="1"
										<?php checked( 1, (int) ( $ticket->enabled ?? 1 ) ); ?>
									/>
									<?php esc_html_e( 'Enabled', 'event-ticket-manager' ); ?>
								</label>

								<?php if ( $ticket && (int) $ticket->id > 0 ) : ?>
									<span class="etm-muted etm-ticket-row__sold">
										<?php
										printf(
											/* translators: %d: number sold. */
											esc_html__( '%d sold', 'event-ticket-manager' ),
											(int) ETM_Event_Tickets::sold_for_type( (int) $ticket->id )
										);
										?>
									</span>
								<?php endif; ?>
							</div>

							<div class="etm-ticket-row__remove">
								<button type="button" class="button-link delete etm-remove-ticket" aria-label="<?php esc_attr_e( 'Remove ticket type', 'event-ticket-manager' ); ?>">
									&times;
								</button>
							</div>
						</div>
						<?php
					};
					?>

					<div class="etm-tickets-repeater" data-etm-tickets>
						<p class="description">
							<?php esc_html_e( 'Add one row per ticket type. Names are up to you - Adult, Child, VIP, Early Bird, anything. Min Qty is how many a customer must take (0 makes the type optional); Max Qty is the most they may take in one order (0 means no limit of its own). Price Label is the text shown next to the price, for example "per person".', 'event-ticket-manager' ); ?>
							<br />
							<?php esc_html_e( 'Set Ticket Type to "Sharing a Room" for a shared room. It is priced and limited exactly like any other type, but instead of one attendee form per ticket the checkout asks once for the name and email of the person sharing the room, however many of them are bought.', 'event-ticket-manager' ); ?>
						</p>

						<?php if ( ! empty( $etm_ticket_errors ) ) : ?>
							<div class="notice notice-error inline">
								<?php foreach ( $etm_ticket_errors as $etm_ticket_error ) : ?>
									<p><?php echo esc_html( $etm_ticket_error ); ?></p>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>

						<div data-etm-ticket-rows>
							<?php
							if ( empty( $etm_ticket_types ) ) {
								$etm_ticket_row( 0, null );
							} else {
								foreach ( $etm_ticket_types as $etm_index => $etm_ticket ) {
									$etm_ticket_row( (int) $etm_index, $etm_ticket );
								}
							}
							?>
						</div>

						<p>
							<button
								type="button"
								class="button etm-add-ticket"
								data-etm-next-ticket-index="<?php echo esc_attr( (string) max( 1, count( $etm_ticket_types ) ) ); ?>"
							>
								<?php esc_html_e( 'Add Ticket Type', 'event-ticket-manager' ); ?>
							</button>
						</p>
					</div>

					<div class="etm-admin-field">
						<label for="etm-limit"><?php esc_html_e( 'Participant Limit', 'event-ticket-manager' ); ?></label>
						<input
							type="number"
							id="etm-limit"
							name="participant_limit"
							min="0"
							step="1"
							value="<?php echo esc_attr( (string) $etm_value( 'participant_limit', $etm_input, $event, '' ) ); ?>"
						/>
						<p class="description"><?php esc_html_e( 'Total places across every ticket type. Leave empty for unlimited.', 'event-ticket-manager' ); ?></p>
					</div>

				</div>

				<?php if ( ! empty( $fields ) ) : ?>
					<div class="etm-card">
						<h2 class="etm-card__title"><?php esc_html_e( 'Additional event fields', 'event-ticket-manager' ); ?></h2>

						<?php foreach ( $fields as $etm_field ) : ?>
							<?php if ( empty( $etm_field['enabled'] ) ) : ?>
								<?php continue; ?>
							<?php endif; ?>
							<div class="etm-admin-field">
								<?php
								echo ETM_Custom_Fields::render_field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_field().
									$etm_field,
									'event_fields[' . $etm_field['key'] . ']',
									$meta[ $etm_field['key'] ] ?? '',
									'etm-event-field-' . $etm_field['key']
								);
								?>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<div class="etm-columns__side">
				<div class="etm-card">
					<h2 class="etm-card__title"><?php esc_html_e( 'Publish', 'event-ticket-manager' ); ?></h2>

					<div class="etm-admin-field">
						<label for="etm-status"><?php esc_html_e( 'Status', 'event-ticket-manager' ); ?></label>
						<select id="etm-status" name="status">
							<?php foreach ( ETM_Events::statuses() as $etm_key => $etm_label ) : ?>
								<option value="<?php echo esc_attr( $etm_key ); ?>" <?php selected( (string) $etm_value( 'status', $etm_input, $event, 'draft' ), $etm_key ); ?>>
									<?php echo esc_html( $etm_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<p class="submit">
						<button type="submit" class="button button-primary button-large">
							<?php echo $etm_is_edit ? esc_html__( 'Save Event', 'event-ticket-manager' ) : esc_html__( 'Create Event', 'event-ticket-manager' ); ?>
						</button>
					</p>
				</div>

				<?php if ( $etm_is_edit ) : ?>
					<?php
					$etm_sold      = ETM_Capacity::get_sold_tickets( (int) $event->id );
					$etm_remaining = ETM_Capacity::get_remaining( $event );
					?>
					<div class="etm-card">
						<h2 class="etm-card__title"><?php esc_html_e( 'At a glance', 'event-ticket-manager' ); ?></h2>

						<ul class="etm-stats">
							<li>
								<span><?php esc_html_e( 'Tickets sold', 'event-ticket-manager' ); ?></span>
								<strong><?php echo esc_html( (string) $etm_sold ); ?></strong>
							</li>
							<li>
								<span><?php esc_html_e( 'Remaining', 'event-ticket-manager' ); ?></span>
								<strong>
									<?php echo ETM_Capacity::UNLIMITED === $etm_remaining ? '&infin;' : esc_html( (string) $etm_remaining ); ?>
								</strong>
							</li>
						</ul>

						<p>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=etm-bookings&event_id=' . (int) $event->id ) ); ?>">
								<?php esc_html_e( 'View bookings for this event', 'event-ticket-manager' ); ?>
							</a>
						</p>
					</div>

					<div class="etm-card">
						<h2 class="etm-card__title"><?php esc_html_e( 'Shortcode', 'event-ticket-manager' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Paste this into any page or post.', 'event-ticket-manager' ); ?></p>
						<code class="etm-shortcode" data-etm-copy>[event_ticket event_id="<?php echo esc_html( (string) (int) $event->id ); ?>"]</code>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</form>

	<template id="etm-ticket-row-template">
		<?php $etm_ticket_row( 9999, null ); ?>
	</template>
</div>
