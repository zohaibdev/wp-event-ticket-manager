<?php
/**
 * Form field manager tab.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'etm_render_field_row' ) ) {
	/**
	 * Render one field row.
	 *
	 * @param string               $group Group key.
	 * @param int                  $index Row index.
	 * @param array<string, mixed> $field Field definition.
	 */
	function etm_render_field_row( string $group, int $index, array $field ): void {
	$is_core = ! empty( $field['core'] );
	$name    = 'fields[' . $group . '][' . $index . ']';
	?>
	<tr class="etm-field-row<?php echo $is_core ? ' etm-field-row--core' : ''; ?>" data-etm-field-row>
		<td class="etm-field-row__handle">
			<button type="button" class="button-link etm-move" data-etm-move="up" aria-label="<?php esc_attr_e( 'Move up', 'event-ticket-manager' ); ?>">&uarr;</button>
			<button type="button" class="button-link etm-move" data-etm-move="down" aria-label="<?php esc_attr_e( 'Move down', 'event-ticket-manager' ); ?>">&darr;</button>
		</td>

		<td>
			<input
				type="text"
				name="<?php echo esc_attr( $name ); ?>[label]"
				value="<?php echo esc_attr( (string) $field['label'] ); ?>"
				class="regular-text"
				placeholder="<?php esc_attr_e( 'Field label', 'event-ticket-manager' ); ?>"
				required
			/>
		</td>

		<td>
			<?php if ( $is_core ) : ?>
				<code><?php echo esc_html( (string) $field['key'] ); ?></code>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[key]" value="<?php echo esc_attr( (string) $field['key'] ); ?>" />
			<?php else : ?>
				<input
					type="text"
					name="<?php echo esc_attr( $name ); ?>[key]"
					value="<?php echo esc_attr( (string) $field['key'] ); ?>"
					class="code"
					placeholder="field_key"
					pattern="[a-z0-9_]+"
					required
				/>
			<?php endif; ?>
		</td>

		<td>
			<?php if ( $is_core ) : ?>
				<span class="etm-muted"><?php echo esc_html( ETM_Custom_Fields::field_types()[ $field['type'] ] ?? $field['type'] ); ?></span>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[type]" value="<?php echo esc_attr( (string) $field['type'] ); ?>" />
			<?php else : ?>
				<select name="<?php echo esc_attr( $name ); ?>[type]" data-etm-field-type>
					<?php foreach ( ETM_Custom_Fields::field_types() as $etm_type => $etm_type_label ) : ?>
						<option value="<?php echo esc_attr( $etm_type ); ?>" <?php selected( $field['type'], $etm_type ); ?>>
							<?php echo esc_html( $etm_type_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
		</td>

		<td>
			<textarea
				name="<?php echo esc_attr( $name ); ?>[options]"
				rows="2"
				class="small-text etm-options"
				placeholder="<?php esc_attr_e( 'One option per line', 'event-ticket-manager' ); ?>"
				<?php echo $is_core ? 'disabled' : ''; ?>
			><?php echo esc_textarea( implode( "\n", (array) $field['options'] ) ); ?></textarea>
		</td>

		<td class="etm-field-row__flags">
			<label>
				<input
					type="checkbox"
					name="<?php echo esc_attr( $name ); ?>[required]"
					value="1"
					<?php checked( 1, (int) $field['required'] ); ?>
					<?php echo $is_core ? 'disabled' : ''; ?>
				/>
				<?php esc_html_e( 'Required', 'event-ticket-manager' ); ?>
			</label>
			<br />
			<label>
				<input
					type="checkbox"
					name="<?php echo esc_attr( $name ); ?>[enabled]"
					value="1"
					<?php checked( 1, (int) $field['enabled'] ); ?>
					<?php echo $is_core ? 'disabled' : ''; ?>
				/>
				<?php esc_html_e( 'Enabled', 'event-ticket-manager' ); ?>
			</label>
		</td>

		<td>
			<?php if ( $is_core ) : ?>
				<span class="etm-muted" title="<?php esc_attr_e( 'Built-in field', 'event-ticket-manager' ); ?>"><?php esc_html_e( 'Built-in', 'event-ticket-manager' ); ?></span>
			<?php else : ?>
				<button type="button" class="button-link delete etm-remove-field"><?php esc_html_e( 'Remove', 'event-ticket-manager' ); ?></button>
			<?php endif; ?>
		</td>
	</tr>
		<?php
	}
}
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="etm-fields-form">
	<?php wp_nonce_field( 'etm_save_fields' ); ?>
	<input type="hidden" name="action" value="etm_save_fields" />

	<p class="description etm-intro">
		<?php esc_html_e( 'Add your own fields to the checkout forms. Built-in fields map to database columns, so they stay enabled and required. Everything else is stored against the booking and shown in the admin and in emails.', 'event-ticket-manager' ); ?>
	</p>

	<?php foreach ( ETM_Custom_Fields::groups() as $etm_group => $etm_group_label ) : ?>
		<?php $etm_fields = ETM_Custom_Fields::get_fields( $etm_group, false ); ?>

		<div class="etm-card" data-etm-field-group="<?php echo esc_attr( $etm_group ); ?>">
			<h2 class="etm-card__title"><?php echo esc_html( $etm_group_label ); ?></h2>

			<?php if ( ETM_Custom_Fields::GROUP_ATTENDEE === $etm_group ) : ?>
				<p class="description"><?php esc_html_e( 'Repeated once for every ticket in the order.', 'event-ticket-manager' ); ?></p>
			<?php elseif ( ETM_Custom_Fields::GROUP_ROOMMATE === $etm_group ) : ?>
				<p class="description"><?php esc_html_e( 'Shown once when the order contains a Sharing a Room ticket.', 'event-ticket-manager' ); ?></p>
			<?php elseif ( ETM_Custom_Fields::GROUP_EVENT === $etm_group ) : ?>
				<p class="description"><?php esc_html_e( 'Extra fields shown on the event edit screen in the admin.', 'event-ticket-manager' ); ?></p>
			<?php endif; ?>

			<table class="widefat striped etm-fields-table">
				<thead>
					<tr>
						<th class="etm-col-handle"><span class="screen-reader-text"><?php esc_html_e( 'Order', 'event-ticket-manager' ); ?></span></th>
						<th><?php esc_html_e( 'Label', 'event-ticket-manager' ); ?></th>
						<th><?php esc_html_e( 'Key', 'event-ticket-manager' ); ?></th>
						<th><?php esc_html_e( 'Type', 'event-ticket-manager' ); ?></th>
						<th><?php esc_html_e( 'Options', 'event-ticket-manager' ); ?></th>
						<th><?php esc_html_e( 'Behaviour', 'event-ticket-manager' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'event-ticket-manager' ); ?></th>
					</tr>
				</thead>
				<tbody data-etm-field-rows>
					<?php foreach ( $etm_fields as $etm_index => $etm_field ) : ?>
						<?php etm_render_field_row( $etm_group, (int) $etm_index, $etm_field ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p>
				<button
					type="button"
					class="button etm-add-field"
					data-etm-group="<?php echo esc_attr( $etm_group ); ?>"
					data-etm-next-index="<?php echo esc_attr( (string) count( $etm_fields ) ); ?>"
				>
					<?php esc_html_e( 'Add field', 'event-ticket-manager' ); ?>
				</button>
			</p>
		</div>
	<?php endforeach; ?>

	<?php submit_button( __( 'Save Fields', 'event-ticket-manager' ) ); ?>
</form>

<template id="etm-field-row-template">
	<?php etm_render_field_row( '__GROUP__', 0, ETM_Custom_Fields::make_field( '', '', 'text', false, 0, false ) ); ?>
</template>
