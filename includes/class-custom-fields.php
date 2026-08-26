<?php
/**
 * Lightweight internal custom field system (no ACF dependency).
 *
 * Fields are grouped, stored in a single option, rendered on the frontend and
 * validated server side. Core fields map onto real database columns and cannot
 * be removed; everything else is persisted to the meta tables.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Field definition storage, rendering and validation.
 */
class ETM_Custom_Fields {

	public const OPTION = 'etm_custom_fields';

	public const GROUP_CUSTOMER = 'customer_fields';
	public const GROUP_ATTENDEE = 'attendee_fields';
	public const GROUP_ROOMMATE = 'roommate_fields';
	public const GROUP_EVENT    = 'event_fields';

	/**
	 * Runtime cache of the stored definitions.
	 *
	 * @var array<string, array<int, array<string, mixed>>>|null
	 */
	private static ?array $cache = null;

	/**
	 * Field types the manager can render.
	 *
	 * @return array<string, string>
	 */
	public static function field_types(): array {
		return array(
			'text'     => __( 'Text', 'event-ticket-manager' ),
			'email'    => __( 'Email', 'event-ticket-manager' ),
			'number'   => __( 'Number', 'event-ticket-manager' ),
			'textarea' => __( 'Textarea', 'event-ticket-manager' ),
			'select'   => __( 'Select', 'event-ticket-manager' ),
			'checkbox' => __( 'Checkbox', 'event-ticket-manager' ),
			'radio'    => __( 'Radio', 'event-ticket-manager' ),
			'date'     => __( 'Date', 'event-ticket-manager' ),
			'phone'    => __( 'Phone', 'event-ticket-manager' ),
		);
	}

	/**
	 * Human labels for the field groups.
	 *
	 * @return array<string, string>
	 */
	public static function groups(): array {
		return array(
			self::GROUP_CUSTOMER => __( 'Customer Fields', 'event-ticket-manager' ),
			self::GROUP_ATTENDEE => __( 'Attendee Fields', 'event-ticket-manager' ),
			self::GROUP_ROOMMATE => __( 'Roommate Fields', 'event-ticket-manager' ),
			self::GROUP_EVENT    => __( 'Event Fields', 'event-ticket-manager' ),
		);
	}

	/**
	 * Default field definitions. Core fields are locked to their DB columns.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function defaults(): array {
		return array(
			self::GROUP_CUSTOMER => array(
				self::make_field( 'customer_name', __( 'Full Name', 'event-ticket-manager' ), 'text', true, 0, true ),
				self::make_field( 'customer_email', __( 'Email Address', 'event-ticket-manager' ), 'email', true, 1, true ),
			),
			self::GROUP_ATTENDEE => array(
				self::make_field( 'name', __( 'Name', 'event-ticket-manager' ), 'text', true, 0, true ),
				self::make_field( 'email', __( 'Email', 'event-ticket-manager' ), 'email', true, 1, true ),
			),
			self::GROUP_ROOMMATE => array(
				self::make_field( 'roommate_name', __( 'Roommate Name', 'event-ticket-manager' ), 'text', true, 0, true ),
				self::make_field( 'roommate_email', __( 'Roommate Email', 'event-ticket-manager' ), 'email', true, 1, true ),
			),
			self::GROUP_EVENT    => array(),
		);
	}

	/**
	 * Build a normalised field definition.
	 *
	 * @param string $key      Field key.
	 * @param string $label    Field label.
	 * @param string $type     Field type.
	 * @param bool   $required Whether the field is required.
	 * @param int    $order    Sort order.
	 * @param bool   $core     Whether the field maps to a database column.
	 * @param array  $options  Choice options for select/radio.
	 * @return array<string, mixed>
	 */
	public static function make_field( string $key, string $label, string $type = 'text', bool $required = false, int $order = 0, bool $core = false, array $options = array() ): array {
		return array(
			'key'      => $key,
			'label'    => $label,
			'type'     => $type,
			'required' => $required ? 1 : 0,
			'enabled'  => 1,
			'order'    => $order,
			'core'     => $core ? 1 : 0,
			'options'  => $options,
		);
	}

	/**
	 * Seed defaults on activation.
	 */
	public static function install_defaults(): void {
		$stored = get_option( self::OPTION, null );

		if ( ! is_array( $stored ) ) {
			add_option( self::OPTION, self::defaults() );
			return;
		}

		// Make sure the core fields survive an upgrade.
		$defaults = self::defaults();

		foreach ( $defaults as $group => $fields ) {
			if ( ! isset( $stored[ $group ] ) || ! is_array( $stored[ $group ] ) ) {
				$stored[ $group ] = $fields;
				continue;
			}

			$existing_keys = wp_list_pluck( $stored[ $group ], 'key' );

			foreach ( $fields as $field ) {
				if ( ! in_array( $field['key'], $existing_keys, true ) ) {
					$stored[ $group ][] = $field;
				}
			}
		}

		update_option( self::OPTION, $stored );
	}

	/**
	 * All stored definitions.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$merged = array();

		foreach ( array_keys( self::groups() ) as $group ) {
			$fields = isset( $stored[ $group ] ) && is_array( $stored[ $group ] ) ? $stored[ $group ] : ( self::defaults()[ $group ] ?? array() );
			$fields = array_map( array( __CLASS__, 'normalise_field' ), $fields );

			usort(
				$fields,
				static function ( $a, $b ) {
					return $a['order'] <=> $b['order'];
				}
			);

			$merged[ $group ] = array_values( $fields );
		}

		self::$cache = $merged;

		return self::$cache;
	}

	/**
	 * Fields for one group.
	 *
	 * @param string $group        Group key.
	 * @param bool   $only_enabled Filter out disabled fields.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_fields( string $group, bool $only_enabled = true ): array {
		$all    = self::all();
		$fields = $all[ $group ] ?? array();

		if ( $only_enabled ) {
			$fields = array_values(
				array_filter(
					$fields,
					static function ( $field ) {
						return ! empty( $field['enabled'] );
					}
				)
			);
		}

		/**
		 * Filter the fields rendered for a group.
		 *
		 * @param array  $fields Field definitions.
		 * @param string $group  Group key.
		 */
		return apply_filters( 'etm_custom_fields', $fields, $group );
	}

	/**
	 * Custom (non-core) fields for a group.
	 *
	 * @param string $group Group key.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_custom_fields( string $group ): array {
		return array_values(
			array_filter(
				self::get_fields( $group ),
				static function ( $field ) {
					return empty( $field['core'] );
				}
			)
		);
	}

	/**
	 * Persist a group definition.
	 *
	 * @param string $group  Group key.
	 * @param array  $fields Field definitions.
	 */
	public static function save_group( string $group, array $fields ): void {
		$all = self::all();

		$all[ $group ] = array_values( array_map( array( __CLASS__, 'normalise_field' ), $fields ) );

		update_option( self::OPTION, $all );
		self::$cache = null;
	}

	/**
	 * Coerce a stored definition into the canonical shape.
	 *
	 * @param mixed $field Raw definition.
	 * @return array<string, mixed>
	 */
	public static function normalise_field( $field ): array {
		$field = is_array( $field ) ? $field : array();
		$types = self::field_types();
		$type  = isset( $field['type'] ) && isset( $types[ $field['type'] ] ) ? $field['type'] : 'text';

		$options = array();

		if ( isset( $field['options'] ) ) {
			$raw = is_array( $field['options'] ) ? $field['options'] : preg_split( '/\r\n|\r|\n/', (string) $field['options'] );

			foreach ( (array) $raw as $option ) {
				$option = sanitize_text_field( (string) $option );

				if ( '' !== $option ) {
					$options[] = $option;
				}
			}
		}

		return array(
			'key'      => self::sanitize_key( (string) ( $field['key'] ?? '' ) ),
			'label'    => sanitize_text_field( (string) ( $field['label'] ?? '' ) ),
			'type'     => $type,
			'required' => empty( $field['required'] ) ? 0 : 1,
			'enabled'  => empty( $field['enabled'] ) ? 0 : 1,
			'order'    => isset( $field['order'] ) ? (int) $field['order'] : 0,
			'core'     => empty( $field['core'] ) ? 0 : 1,
			'options'  => $options,
		);
	}

	/**
	 * Restrictive key sanitiser.
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	public static function sanitize_key( string $key ): string {
		$key = strtolower( $key );
		$key = (string) preg_replace( '/[^a-z0-9_]/', '_', $key );
		$key = (string) preg_replace( '/_+/', '_', $key );

		return trim( $key, '_' );
	}

	/**
	 * Validate and sanitise a submitted value set for a group.
	 *
	 * @param string               $group Group key.
	 * @param array<string, mixed> $data  Raw submitted values keyed by field key.
	 * @param bool                 $custom_only Only process non-core fields.
	 * @return array{values: array<string, mixed>, errors: array<string, string>}
	 */
	public static function validate( string $group, array $data, bool $custom_only = false ): array {
		$values = array();
		$errors = array();

		foreach ( self::get_fields( $group ) as $field ) {
			if ( $custom_only && ! empty( $field['core'] ) ) {
				continue;
			}

			$key = $field['key'];
			$raw = $data[ $key ] ?? '';
			$val = self::sanitize_value( $field, $raw );

			if ( ! empty( $field['required'] ) && ( '' === $val || array() === $val ) ) {
				/* translators: %s: field label. */
				$errors[ $key ] = sprintf( __( '%s is required.', 'event-ticket-manager' ), $field['label'] );
				continue;
			}

			if ( '' !== $val && 'email' === $field['type'] && ! is_email( (string) $val ) ) {
				/* translators: %s: field label. */
				$errors[ $key ] = sprintf( __( '%s must be a valid email address.', 'event-ticket-manager' ), $field['label'] );
				continue;
			}

			if ( '' !== $val && in_array( $field['type'], array( 'select', 'radio' ), true ) && ! empty( $field['options'] ) && ! in_array( (string) $val, $field['options'], true ) ) {
				/* translators: %s: field label. */
				$errors[ $key ] = sprintf( __( '%s contains an invalid selection.', 'event-ticket-manager' ), $field['label'] );
				continue;
			}

			$values[ $key ] = $val;
		}

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * Sanitise a single value according to its field type.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $raw   Raw value.
	 * @return mixed
	 */
	public static function sanitize_value( array $field, $raw ) {
		switch ( $field['type'] ) {
			case 'email':
				return sanitize_email( is_scalar( $raw ) ? trim( (string) $raw ) : '' );

			case 'number':
				if ( ! is_scalar( $raw ) || '' === trim( (string) $raw ) ) {
					return '';
				}

				return (string) floatval( $raw );

			case 'textarea':
				return sanitize_textarea_field( is_scalar( $raw ) ? (string) $raw : '' );

			case 'checkbox':
				return empty( $raw ) ? '' : '1';

			case 'date':
				$value = sanitize_text_field( is_scalar( $raw ) ? (string) $raw : '' );

				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';

			case 'phone':
				$value = sanitize_text_field( is_scalar( $raw ) ? (string) $raw : '' );

				return (string) preg_replace( '/[^0-9+()\-.\s]/', '', $value );

			default:
				return sanitize_text_field( is_scalar( $raw ) ? (string) $raw : '' );
		}
	}

	/**
	 * Render one field as HTML for the frontend checkout modal.
	 *
	 * @param array<string, mixed> $field      Field definition.
	 * @param string               $input_name Input name attribute.
	 * @param mixed                $value      Current value.
	 * @param string               $input_id   Optional id attribute.
	 * @return string
	 */
	public static function render_field( array $field, string $input_name, $value = '', string $input_id = '' ): string {
		$input_id = '' !== $input_id ? $input_id : 'etm-field-' . sanitize_html_class( $input_name );
		$required = ! empty( $field['required'] );
		$attrs    = $required ? ' required aria-required="true"' : '';
		$label    = esc_html( $field['label'] );
		$out      = '<div class="etm-field etm-field--' . esc_attr( $field['type'] ) . '">';

		if ( 'checkbox' === $field['type'] ) {
			$out .= '<label class="etm-field__checkbox" for="' . esc_attr( $input_id ) . '">';
			$out .= '<input type="checkbox" id="' . esc_attr( $input_id ) . '" name="' . esc_attr( $input_name ) . '" value="1"' . checked( ! empty( $value ), true, false ) . $attrs . ' /> ';
			$out .= '<span>' . $label . ( $required ? ' <span class="etm-required">*</span>' : '' ) . '</span>';
			$out .= '</label>';
			$out .= '<span class="etm-field__error" data-error-for="' . esc_attr( $input_name ) . '"></span>';
			$out .= '</div>';

			return $out;
		}

		$out .= '<label class="etm-field__label" for="' . esc_attr( $input_id ) . '">' . $label . ( $required ? ' <span class="etm-required">*</span>' : '' ) . '</label>';

		switch ( $field['type'] ) {
			case 'textarea':
				$out .= '<textarea class="etm-input" id="' . esc_attr( $input_id ) . '" name="' . esc_attr( $input_name ) . '" rows="3"' . $attrs . '>' . esc_textarea( (string) $value ) . '</textarea>';
				break;

			case 'select':
				$out .= '<select class="etm-input" id="' . esc_attr( $input_id ) . '" name="' . esc_attr( $input_name ) . '"' . $attrs . '>';
				$out .= '<option value="">' . esc_html__( 'Select an option', 'event-ticket-manager' ) . '</option>';

				foreach ( (array) $field['options'] as $option ) {
					$out .= '<option value="' . esc_attr( $option ) . '"' . selected( (string) $value, (string) $option, false ) . '>' . esc_html( $option ) . '</option>';
				}

				$out .= '</select>';
				break;

			case 'radio':
				$out .= '<div class="etm-field__radios" role="radiogroup" aria-label="' . esc_attr( $field['label'] ) . '">';

				foreach ( (array) $field['options'] as $index => $option ) {
					$radio_id = $input_id . '-' . (int) $index;
					$out     .= '<label class="etm-field__radio" for="' . esc_attr( $radio_id ) . '">';
					$out     .= '<input type="radio" id="' . esc_attr( $radio_id ) . '" name="' . esc_attr( $input_name ) . '" value="' . esc_attr( $option ) . '"' . checked( (string) $value, (string) $option, false ) . $attrs . ' /> ';
					$out     .= '<span>' . esc_html( $option ) . '</span></label>';
				}

				$out .= '</div>';
				break;

			default:
				$type_map = array(
					'email'  => 'email',
					'number' => 'number',
					'date'   => 'date',
					'phone'  => 'tel',
					'text'   => 'text',
				);
				$html_type = $type_map[ $field['type'] ] ?? 'text';

				$out .= '<input class="etm-input" type="' . esc_attr( $html_type ) . '" id="' . esc_attr( $input_id ) . '" name="' . esc_attr( $input_name ) . '" value="' . esc_attr( (string) $value ) . '" autocomplete="off"' . $attrs . ' />';
				break;
		}

		$out .= '<span class="etm-field__error" data-error-for="' . esc_attr( $input_name ) . '"></span>';
		$out .= '</div>';

		return $out;
	}
}
