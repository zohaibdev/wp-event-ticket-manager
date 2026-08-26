<?php
/**
 * Plugin settings storage, defaults and sanitisation.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the three option groups used by the plugin.
 */
class ETM_Settings {

	public const OPTION_GENERAL = 'etm_general_settings';
	public const OPTION_STRIPE  = 'etm_stripe_settings';
	public const OPTION_EMAIL    = 'etm_email_settings';
	public const OPTION_BRANDING = 'etm_branding_settings';

	/**
	 * Runtime cache of option groups.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $cache = array();

	/**
	 * Register hooks.
	 */
	public function hooks(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Default values per option group.
	 *
	 * @param string $group Option name.
	 * @return array<string, mixed>
	 */
	public static function defaults( string $group ): array {
		$defaults = array(
			self::OPTION_GENERAL => array(
				'currency'                 => 'USD',
				'booking_number_prefix'    => 'EVT',
				'pending_hold_minutes'     => 30,
				'delete_data_on_uninstall' => 0,
				// Coupons stay configured and historical bookings keep their
				// codes; this only controls whether checkout offers the field.
				'coupon_enabled'           => 1,
				'success_url'              => '',
				'cancel_url'               => '',
			),
			self::OPTION_STRIPE  => array(
				'enable_stripe'        => 0,
				'connection_method'    => 'manual',
				'mode'                 => 'test',
				'test_publishable_key' => '',
				'test_secret_key'      => '',
				'live_publishable_key' => '',
				'live_secret_key'      => '',
				'webhook_secret'       => '',
				'live_webhook_secret'  => '',
				'statement_descriptor' => '',
			),
			self::OPTION_EMAIL   => array(
				// Left blank by default: a blank value means "read the legacy
				// single-address setting, then fall back to the WP admin email",
				// which is what admin_notification_emails() does at read time.
				'admin_notification_emails' => '',
				'rsvp_notification_emails'  => '',
				'from_name'                => get_bloginfo( 'name' ),
				'from_email'               => get_option( 'admin_email' ),
				'enable_customer_email'    => 1,
				'enable_admin_email'       => 1,
				'customer_subject'         => __( 'Your booking is confirmed - {booking_number}', 'event-ticket-manager' ),
				'customer_heading'         => __( 'Thank you for your booking!', 'event-ticket-manager' ),
				'customer_intro'           => __( 'Hi {customer_name}, your payment was received and your tickets for {event_title} are confirmed.', 'event-ticket-manager' ),
				'admin_subject'            => __( 'New booking {booking_number} for {event_title}', 'event-ticket-manager' ),
			),
			self::OPTION_BRANDING => self::branding_defaults(),
		);

		/**
		 * Filter the default settings for an option group.
		 *
		 * @param array  $values Defaults.
		 * @param string $group  Option group name.
		 */
		return apply_filters( 'etm_settings_defaults', $defaults[ $group ] ?? array(), $group );
	}

	/**
	 * Seed default options on activation without clobbering existing values.
	 */
	public static function install_defaults(): void {
		foreach ( array( self::OPTION_GENERAL, self::OPTION_STRIPE, self::OPTION_EMAIL, self::OPTION_BRANDING ) as $group ) {
			$existing = get_option( $group, null );

			if ( ! is_array( $existing ) ) {
				add_option( $group, self::defaults( $group ) );
				continue;
			}

			update_option( $group, array_merge( self::defaults( $group ), $existing ) );
		}
	}

	/**
	 * Read a whole option group merged over its defaults.
	 *
	 * @param string $group Option name.
	 * @return array<string, mixed>
	 */
	public static function get_group( string $group ): array {
		if ( isset( self::$cache[ $group ] ) ) {
			return self::$cache[ $group ];
		}

		$stored = get_option( $group, array() );
		$stored = is_array( $stored ) ? $stored : array();

		self::$cache[ $group ] = array_merge( self::defaults( $group ), $stored );

		return self::$cache[ $group ];
	}

	/**
	 * Read a single setting.
	 *
	 * @param string $group        Option name.
	 * @param string $key          Setting key.
	 * @param mixed  $default_value Fallback when missing.
	 * @return mixed
	 */
	public static function get( string $group, string $key, $default_value = null ) {
		$values = self::get_group( $group );

		return array_key_exists( $key, $values ) ? $values[ $key ] : $default_value;
	}

	/**
	 * Convenience reader for the general group.
	 *
	 * @param string $key           Setting key.
	 * @param mixed  $default_value Fallback.
	 * @return mixed
	 */
	public static function general( string $key, $default_value = null ) {
		return self::get( self::OPTION_GENERAL, $key, $default_value );
	}

	/**
	 * Convenience reader for the Stripe group.
	 *
	 * @param string $key           Setting key.
	 * @param mixed  $default_value Fallback.
	 * @return mixed
	 */
	public static function stripe( string $key, $default_value = null ) {
		return self::get( self::OPTION_STRIPE, $key, $default_value );
	}

	/**
	 * Split a comma- and/or newline-separated list of addresses into valid,
	 * de-duplicated emails, dropping anything invalid.
	 *
	 * @param string $raw Raw textarea value.
	 * @return string[]
	 */
	public static function parse_email_list( string $raw ): array {
		return self::parse_email_list_with_errors( $raw )['valid'];
	}

	/**
	 * Same as parse_email_list(), but also reports which tokens did not parse
	 * as a valid address, so the admin can be told what was dropped.
	 *
	 * @param string $raw Raw textarea value.
	 * @return array{valid: string[], invalid: string[]}
	 */
	public static function parse_email_list_with_errors( string $raw ): array {
		$tokens = preg_split( '/[,
]+/', $raw );

		$valid   = array();
		$invalid = array();
		$seen    = array();

		foreach ( (array) $tokens as $token ) {
			$token = trim( (string) $token );

			if ( '' === $token ) {
				continue;
			}

			$email = sanitize_email( $token );

			if ( '' === $email || ! is_email( $email ) ) {
				$invalid[] = $token;
				continue;
			}

			// Addresses are not case sensitive in practice; keep the first
			// spelling seen and silently drop any repeat of it.
			$key = strtolower( $email );

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$valid[]      = $email;
		}

		return array(
			'valid'   => $valid,
			'invalid' => $invalid,
		);
	}

	/**
	 * Where ticket, free and paid order notifications go.
	 *
	 * Reads the multi-address setting; a site that never re-saved settings
	 * since upgrading falls back to the single address it had configured
	 * before, and only then to the WordPress admin email.
	 *
	 * @return string[]
	 */
	public static function admin_notification_emails(): array {
		$raw = (string) self::email( 'admin_notification_emails', '' );

		if ( '' === trim( $raw ) ) {
			// Pre-multi-address installs stored one address under this key.
			$raw = (string) self::email( 'admin_notification_email', '' );
		}

		$emails = self::parse_email_list( $raw );

		return ! empty( $emails ) ? $emails : array( (string) get_option( 'admin_email' ) );
	}

	/**
	 * Where RSVP notifications go when an event has no override of its own.
	 *
	 * Blank is meaningful here: an empty result means "use the ticket admin
	 * addresses instead", handled by the caller.
	 *
	 * @return string[]
	 */
	public static function rsvp_notification_emails(): array {
		$raw = (string) self::email( 'rsvp_notification_emails', '' );

		if ( '' === trim( $raw ) ) {
			$raw = (string) self::email( 'rsvp_notification_email', '' );
		}

		return self::parse_email_list( $raw );
	}

	/**
	 * Convenience reader for the email group.
	 *
	 * @param string $key           Setting key.
	 * @param mixed  $default_value Fallback.
	 * @return mixed
	 */
	public static function email( string $key, $default_value = null ) {
		return self::get( self::OPTION_EMAIL, $key, $default_value );
	}

	/**
	 * Convenience reader for the branding group.
	 *
	 * @param string $key           Setting key.
	 * @param mixed  $default_value Fallback.
	 * @return mixed
	 */
	public static function branding( string $key, $default_value = null ) {
		return self::get( self::OPTION_BRANDING, $key, $default_value );
	}

	/**
	 * The branding palette definition: key, label and default colour.
	 *
	 * @return array<string, array{label: string, default: string, description: string}>
	 */
	public static function branding_palette(): array {
		return array(
			'primary_color'     => array(
				'label'       => __( 'Primary Color', 'event-ticket-manager' ),
				'default'     => '#4f46e5',
				'description' => __( 'Buttons, active step and focus rings.', 'event-ticket-manager' ),
			),
			'secondary_color'   => array(
				'label'       => __( 'Secondary Color', 'event-ticket-manager' ),
				'default'     => '#6b7280',
				'description' => __( 'Supporting text, labels and hints.', 'event-ticket-manager' ),
			),
			'accent_color'      => array(
				'label'       => __( 'Accent Color', 'event-ticket-manager' ),
				'default'     => '#10b981',
				'description' => __( 'Completed steps and success states.', 'event-ticket-manager' ),
			),
			'text_color'        => array(
				'label'       => __( 'Text Color', 'event-ticket-manager' ),
				'default'     => '#111827',
				'description' => __( 'Headings and body copy.', 'event-ticket-manager' ),
			),
			'background_color'  => array(
				'label'       => __( 'Background Color', 'event-ticket-manager' ),
				'default'     => '#ffffff',
				'description' => __( 'Ticket block and modal background.', 'event-ticket-manager' ),
			),
			'button_text_color' => array(
				'label'       => __( 'Button Text Color', 'event-ticket-manager' ),
				'default'     => '#ffffff',
				'description' => __( 'Text on primary buttons.', 'event-ticket-manager' ),
			),
		);
	}

	/**
	 * Default branding values.
	 *
	 * @return array<string, string>
	 */
	public static function branding_defaults(): array {
		$defaults = array();

		foreach ( self::branding_palette() as $key => $config ) {
			$defaults[ $key ] = $config['default'];
		}

		return $defaults;
	}

	/**
	 * Branding rendered as CSS custom properties for the frontend.
	 *
	 * @return string
	 */
	public static function branding_css(): string {
		$values = self::get_group( self::OPTION_BRANDING );
		$map    = array(
			'primary_color'     => '--etm-primary',
			'secondary_color'   => '--etm-secondary',
			'accent_color'      => '--etm-accent-color',
			'text_color'        => '--etm-text',
			'background_color'  => '--etm-surface',
			'button_text_color' => '--etm-on-primary',
		);

		$declarations = array();

		foreach ( $map as $key => $property ) {
			$color = isset( $values[ $key ] ) ? sanitize_hex_color( (string) $values[ $key ] ) : '';

			if ( $color ) {
				$declarations[] = $property . ':' . $color . ';';
			}
		}

		if ( empty( $declarations ) ) {
			return '';
		}

		// A soft tint of the primary colour, used for selected rows and hovers.
		$primary = sanitize_hex_color( (string) ( $values['primary_color'] ?? '' ) );

		if ( $primary ) {
			$declarations[] = '--etm-primary-soft:' . self::hex_to_rgba( $primary, 0.08 ) . ';';
			$declarations[] = '--etm-primary-ring:' . self::hex_to_rgba( $primary, 0.28 ) . ';';
		}

		// The accent is a background for glyphs the admin does not choose a
		// colour for, so pick the readable foreground rather than assuming white.
		$accent = sanitize_hex_color( (string) ( $values['accent_color'] ?? '' ) );

		if ( $accent ) {
			$declarations[] = '--etm-on-accent:' . self::readable_on( $accent ) . ';';
		}

		return '.etm-block,.etm-modal{' . implode( '', $declarations ) . '}';
	}

	/**
	 * Relative luminance of a hex colour, per WCAG 2.1.
	 *
	 * @param string $hex Hex colour.
	 * @return float
	 */
	public static function luminance( string $hex ): float {
		$hex = ltrim( $hex, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( 6 !== strlen( $hex ) ) {
			return 0.0;
		}

		$channels = array();

		foreach ( array( 0, 2, 4 ) as $offset ) {
			$value = hexdec( substr( $hex, $offset, 2 ) ) / 255;

			$channels[] = $value <= 0.03928 ? $value / 12.92 : pow( ( $value + 0.055 ) / 1.055, 2.4 );
		}

		return ( 0.2126 * $channels[0] ) + ( 0.7152 * $channels[1] ) + ( 0.0722 * $channels[2] );
	}

	/**
	 * WCAG contrast ratio between two hex colours.
	 *
	 * @param string $foreground Hex colour.
	 * @param string $background Hex colour.
	 * @return float
	 */
	public static function contrast_ratio( string $foreground, string $background ): float {
		$light = self::luminance( $foreground );
		$dark  = self::luminance( $background );

		if ( $dark > $light ) {
			list( $light, $dark ) = array( $dark, $light );
		}

		return round( ( $light + 0.05 ) / ( $dark + 0.05 ), 2 );
	}

	/**
	 * Pick black or white text for a background, whichever reads better.
	 *
	 * Used for colours the plugin derives rather than the admin choosing, so a
	 * pale brand colour never ends up with unreadable white glyphs on it.
	 *
	 * @param string $background Hex colour.
	 * @return string
	 */
	public static function readable_on( string $background ): string {
		$on_white = self::contrast_ratio( '#ffffff', $background );
		$on_dark  = self::contrast_ratio( '#111827', $background );

		return $on_dark > $on_white ? '#111827' : '#ffffff';
	}

	/**
	 * Convert a hex colour into an rgba() string.
	 *
	 * @param string $hex   Hex colour.
	 * @param float  $alpha Alpha channel.
	 * @return string
	 */
	public static function hex_to_rgba( string $hex, float $alpha ): string {
		$hex = ltrim( $hex, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( 6 !== strlen( $hex ) ) {
			return 'rgba(79,70,229,' . $alpha . ')';
		}

		return sprintf(
			'rgba(%d,%d,%d,%s)',
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
			rtrim( rtrim( number_format( $alpha, 2, '.', '' ), '0' ), '.' )
		);
	}

	/**
	 * Persist a whole group and reset the runtime cache.
	 *
	 * @param string               $group  Option name.
	 * @param array<string, mixed> $values Values to store.
	 */
	public static function update_group( string $group, array $values ): void {
		update_option( $group, $values );
		unset( self::$cache[ $group ] );
	}

	/**
	 * Configured currency code.
	 *
	 * @return string
	 */
	public static function currency(): string {
		return strtoupper( (string) self::general( 'currency', 'USD' ) );
	}

	/**
	 * Register settings so options.php handles saving and nonce checks.
	 */
	public function register_settings(): void {
		register_setting(
			'etm_general_group',
			self::OPTION_GENERAL,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_general' ),
				'default'           => self::defaults( self::OPTION_GENERAL ),
			)
		);

		register_setting(
			'etm_stripe_group',
			self::OPTION_STRIPE,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_stripe' ),
				'default'           => self::defaults( self::OPTION_STRIPE ),
			)
		);

		register_setting(
			'etm_branding_group',
			self::OPTION_BRANDING,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_branding' ),
				'default'           => self::branding_defaults(),
			)
		);

		register_setting(
			'etm_email_group',
			self::OPTION_EMAIL,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_email' ),
				'default'           => self::defaults( self::OPTION_EMAIL ),
			)
		);
	}

	/**
	 * Sanitise the general group.
	 *
	 * @param mixed $input Raw submission.
	 * @return array<string, mixed>
	 */
	public static function sanitize_general( $input ): array {
		$input   = is_array( $input ) ? $input : array();
		$current = self::get_group( self::OPTION_GENERAL );

		if ( ! current_user_can( 'manage_options' ) ) {
			return $current;
		}

		$currency = strtoupper( sanitize_text_field( $input['currency'] ?? $current['currency'] ) );
		$currency = preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : 'USD';

		$prefix = strtoupper( sanitize_text_field( $input['booking_number_prefix'] ?? $current['booking_number_prefix'] ) );
		$prefix = (string) preg_replace( '/[^A-Z0-9-]/', '', $prefix );
		$prefix = '' === $prefix ? 'EVT' : substr( $prefix, 0, 10 );

		$hold = isset( $input['pending_hold_minutes'] ) ? absint( $input['pending_hold_minutes'] ) : (int) $current['pending_hold_minutes'];
		$hold = max( 5, min( 1440, $hold ) );

		$clean = array(
			'currency'                 => $currency,
			'booking_number_prefix'    => $prefix,
			'pending_hold_minutes'     => $hold,
			'coupon_enabled'           => empty( $input['coupon_enabled'] ) ? 0 : 1,
			'delete_data_on_uninstall' => empty( $input['delete_data_on_uninstall'] ) ? 0 : 1,
			'success_url'              => esc_url_raw( trim( (string) ( $input['success_url'] ?? '' ) ) ),
			'cancel_url'               => esc_url_raw( trim( (string) ( $input['cancel_url'] ?? '' ) ) ),
		);

		unset( self::$cache[ self::OPTION_GENERAL ] );

		return $clean;
	}

	/**
	 * Sanitise the Stripe group. Blank key fields keep the stored secret.
	 *
	 * @param mixed $input Raw submission.
	 * @return array<string, mixed>
	 */
	public static function sanitize_stripe( $input ): array {
		$input   = is_array( $input ) ? $input : array();
		$current = self::get_group( self::OPTION_STRIPE );

		if ( ! current_user_can( 'manage_options' ) ) {
			return $current;
		}

		$mode = ( isset( $input['mode'] ) && 'live' === $input['mode'] ) ? 'live' : 'test';

		$method = isset( $input['connection_method'] ) ? (string) $input['connection_method'] : ETM_Stripe::CONNECTION_MANUAL;
		$method = isset( ETM_Stripe::connection_methods()[ $method ] ) ? $method : ETM_Stripe::CONNECTION_MANUAL;

		$clean = array(
			'enable_stripe'        => empty( $input['enable_stripe'] ) ? 0 : 1,
			'connection_method'    => $method,
			'mode'                 => $mode,
			'statement_descriptor' => substr( sanitize_text_field( $input['statement_descriptor'] ?? '' ), 0, 22 ),
		);

		$key_fields = array(
			'test_publishable_key',
			'test_secret_key',
			'live_publishable_key',
			'live_secret_key',
			'webhook_secret',
			'live_webhook_secret',
		);

		foreach ( $key_fields as $field ) {
			$value = isset( $input[ $field ] ) ? trim( sanitize_text_field( $input[ $field ] ) ) : '';

			// An empty submission means "leave the stored key alone" - the UI never
			// prints secrets back into the form. A single dash clears the value.
			if ( '' === $value ) {
				$clean[ $field ] = (string) ( $current[ $field ] ?? '' );
				continue;
			}

			$clean[ $field ] = ( '-' === $value ) ? '' : $value;

			// Caught here rather than left for Stripe to reject later: a key
			// carrying look-alike characters is visually indistinguishable from
			// a good one, so the admin has no way to spot it themselves.
			if ( '' !== $clean[ $field ] && ETM_Stripe::has_invalid_characters( $clean[ $field ] ) ) {
				add_settings_error(
					self::OPTION_STRIPE,
					'etm_stripe_key_characters_' . $field,
					sprintf(
						/* translators: %s: the settings field name, e.g. live_secret_key. */
						__( 'The value saved for %s contains characters that are not valid in a Stripe key. Stripe keys use only letters, numbers and underscores. Copy the key again directly from the Stripe dashboard using its copy button - keys pasted from chat apps, PDFs or translated pages often pick up look-alike characters that Stripe rejects as invalid.', 'event-ticket-manager' ),
						str_replace( '_', ' ', $field )
					)
				);
			}
		}

		unset( self::$cache[ self::OPTION_STRIPE ] );

		return $clean;
	}

	/**
	 * Sanitise the email group.
	 *
	 * @param mixed $input Raw submission.
	 * @return array<string, mixed>
	 */
	public static function sanitize_email( $input ): array {
		$input   = is_array( $input ) ? $input : array();
		$current = self::get_group( self::OPTION_EMAIL );

		if ( ! current_user_can( 'manage_options' ) ) {
			return $current;
		}

		$admin_result = self::parse_email_list_with_errors( (string) ( $input['admin_notification_emails'] ?? '' ) );
		$rsvp_result  = self::parse_email_list_with_errors( (string) ( $input['rsvp_notification_emails'] ?? '' ) );

		if ( ! empty( $admin_result['invalid'] ) ) {
			add_settings_error(
				self::OPTION_EMAIL,
				'etm_invalid_admin_emails',
				sprintf(
					/* translators: %s: comma-separated list of addresses that were not saved. */
					__( 'These admin notification addresses were not valid and were not saved: %s', 'event-ticket-manager' ),
					implode( ', ', $admin_result['invalid'] )
				)
			);
		}

		if ( ! empty( $rsvp_result['invalid'] ) ) {
			add_settings_error(
				self::OPTION_EMAIL,
				'etm_invalid_rsvp_emails',
				sprintf(
					/* translators: %s: comma-separated list of addresses that were not saved. */
					__( 'These RSVP notification addresses were not valid and were not saved: %s', 'event-ticket-manager' ),
					implode( ', ', $rsvp_result['invalid'] )
				)
			);
		}

		$admin_emails = implode( "
", $admin_result['valid'] );

		// Required: never let a save leave notifications with nowhere to go.
		if ( '' === $admin_emails ) {
			add_settings_error(
				self::OPTION_EMAIL,
				'etm_admin_emails_required',
				__( 'Enter at least one valid admin notification email address.', 'event-ticket-manager' )
			);

			// admin_notification_emails() reads whatever is still stored (this
			// submission hasn't been saved yet), so it resolves to the previous
			// value, then the legacy single address, then the WP admin email.
			$admin_emails = implode( "
", self::admin_notification_emails() );
		}

		$from_email = sanitize_email( $input['from_email'] ?? '' );

		$clean = array(
			'admin_notification_emails' => $admin_emails,
			// Blank is meaningful: it falls back to the ticket admin addresses.
			'rsvp_notification_emails'  => implode( "
", $rsvp_result['valid'] ),
			'from_email'                => is_email( $from_email ) ? $from_email : $current['from_email'],
			'from_name'                 => sanitize_text_field( $input['from_name'] ?? $current['from_name'] ),
			'enable_customer_email'     => empty( $input['enable_customer_email'] ) ? 0 : 1,
			'enable_admin_email'        => empty( $input['enable_admin_email'] ) ? 0 : 1,
			'customer_subject'          => sanitize_text_field( $input['customer_subject'] ?? $current['customer_subject'] ),
			'customer_heading'          => sanitize_text_field( $input['customer_heading'] ?? $current['customer_heading'] ),
			'customer_intro'            => wp_kses_post( $input['customer_intro'] ?? $current['customer_intro'] ),
			'admin_subject'             => sanitize_text_field( $input['admin_subject'] ?? $current['admin_subject'] ),
		);

		unset( self::$cache[ self::OPTION_EMAIL ] );

		return $clean;
	}

	/**
	 * Sanitise the branding group. Invalid colours fall back to the default.
	 *
	 * @param mixed $input Raw submission.
	 * @return array<string, string>
	 */
	public static function sanitize_branding( $input ): array {
		$input   = is_array( $input ) ? $input : array();
		$current = self::get_group( self::OPTION_BRANDING );

		if ( ! current_user_can( 'manage_options' ) ) {
			return $current;
		}

		$clean = array();

		foreach ( self::branding_palette() as $key => $config ) {
			$submitted = isset( $input[ $key ] ) ? sanitize_hex_color( trim( (string) $input[ $key ] ) ) : '';

			if ( $submitted ) {
				$clean[ $key ] = $submitted;
				continue;
			}

			$stored = isset( $current[ $key ] ) ? sanitize_hex_color( (string) $current[ $key ] ) : '';

			$clean[ $key ] = $stored ? $stored : $config['default'];
		}

		unset( self::$cache[ self::OPTION_BRANDING ] );

		return $clean;
	}
}
