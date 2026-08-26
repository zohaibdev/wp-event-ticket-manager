<?php
/**
 * Shared template and formatting helpers.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Locate a template, allowing theme overrides.
 *
 * Themes may override any template by copying it to
 * {theme}/event-ticket-manager/{template}.
 *
 * @param string $template Relative template path.
 * @return string Absolute path, or an empty string when missing.
 */
function etm_locate_template( string $template ): string {
	$template = ltrim( str_replace( array( '..', "\0" ), '', $template ), '/' );

	$theme = locate_template( array( 'event-ticket-manager/' . $template ) );

	if ( $theme ) {
		return $theme;
	}

	$plugin = ETM_PLUGIN_DIR . 'frontend/templates/' . $template;

	/**
	 * Filter the resolved template path.
	 *
	 * @param string $plugin   Absolute path.
	 * @param string $template Relative path.
	 */
	$plugin = (string) apply_filters( 'etm_locate_template', $plugin, $template );

	return file_exists( $plugin ) ? $plugin : '';
}

/**
 * Render a frontend template to a string.
 *
 * @param string               $template Relative template path.
 * @param array<string, mixed> $data     Variables exposed to the template.
 * @return string
 */
function etm_get_template( string $template, array $data = array() ): string {
	$path = etm_locate_template( $template );

	if ( ! $path ) {
		return '';
	}

	ob_start();

	// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- template scope.
	extract( $data, EXTR_SKIP );

	include $path;

	return (string) ob_get_clean();
}

/**
 * Currency symbols for the common codes, falling back to the code itself.
 *
 * @param string $currency Currency code.
 * @return string
 */
function etm_currency_symbol( string $currency = '' ): string {
	$currency = strtoupper( '' !== $currency ? $currency : ETM_Settings::currency() );

	$symbols = array(
		'USD' => '$',
		'EUR' => '&euro;',
		'GBP' => '&pound;',
		'JPY' => '&yen;',
		'AUD' => 'A$',
		'CAD' => 'C$',
		'CHF' => 'CHF',
		'SEK' => 'kr',
		'NOK' => 'kr',
		'DKK' => 'kr',
		'PLN' => 'z&#322;',
		'NZD' => 'NZ$',
		'INR' => '&#8377;',
		'AED' => 'AED',
		'ZAR' => 'R',
	);

	/**
	 * Filter the currency symbol.
	 *
	 * @param string $symbol   Symbol markup.
	 * @param string $currency Currency code.
	 */
	return (string) apply_filters( 'etm_currency_symbol', $symbols[ $currency ] ?? $currency, $currency );
}

/**
 * Number of decimal places used for a currency.
 *
 * Mirrors the zero-decimal list Stripe uses so display and charge amounts agree.
 *
 * @param string $currency Currency code.
 * @return int
 */
function etm_currency_decimals( string $currency = '' ): int {
	$currency = strtoupper( '' !== $currency ? $currency : ETM_Settings::currency() );

	$zero_decimal = array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );

	return in_array( $currency, $zero_decimal, true ) ? 0 : 2;
}

/**
 * Format a price for display.
 *
 * @param float  $amount   Amount.
 * @param string $currency Currency code.
 * @return string
 */
function etm_format_price( float $amount, string $currency = '' ): string {
	$currency = '' !== $currency ? $currency : ETM_Settings::currency();
	$number   = number_format( $amount, etm_currency_decimals( $currency ), '.', ',' );

	/**
	 * Filter the formatted price string.
	 *
	 * @param string $formatted Formatted price.
	 * @param float  $amount    Raw amount.
	 * @param string $currency  Currency code.
	 */
	return (string) apply_filters( 'etm_format_price', etm_currency_symbol( $currency ) . $number, $amount, $currency );
}

/**
 * Format a stored datetime using the site formats.
 *
 * @param string|null $datetime MySQL datetime.
 * @param bool        $with_time Include the time part.
 * @return string
 */
function etm_format_datetime( ?string $datetime, bool $with_time = true ): string {
	if ( empty( $datetime ) || '0000-00-00 00:00:00' === $datetime ) {
		return '';
	}

	$timestamp = strtotime( $datetime );

	if ( false === $timestamp ) {
		return '';
	}

	$format = $with_time
		? get_option( 'date_format' ) . ' ' . get_option( 'time_format' )
		: get_option( 'date_format' );

	return wp_date( $format, $timestamp );
}

/**
 * Human readable event date range.
 *
 * @param object|null $event Event row.
 * @return string
 */
function etm_event_date_range( ?object $event ): string {
	if ( ! $event ) {
		return '';
	}

	$start = etm_format_datetime( $event->start_datetime );
	$end   = etm_format_datetime( $event->end_datetime );

	if ( '' === $start ) {
		return '';
	}

	if ( '' === $end || $start === $end ) {
		return $start;
	}

	$same_day = gmdate( 'Y-m-d', strtotime( (string) $event->start_datetime ) ) === gmdate( 'Y-m-d', strtotime( (string) $event->end_datetime ) );

	if ( $same_day ) {
		$end = wp_date( get_option( 'time_format' ), strtotime( (string) $event->end_datetime ) );
	}

	/* translators: 1: start date, 2: end date. */
	return sprintf( _x( '%1$s to %2$s', 'event date range', 'event-ticket-manager' ), $start, $end );
}

/**
 * Start and end time of an event, without the dates.
 *
 * @param object|null $event Event row.
 * @return string
 */
function etm_event_time_range( ?object $event ): string {
	if ( ! $event || empty( $event->start_datetime ) ) {
		return '';
	}

	$format = get_option( 'time_format' );
	$start  = strtotime( (string) $event->start_datetime );

	if ( false === $start ) {
		return '';
	}

	$time = wp_date( $format, $start );
	$end  = ! empty( $event->end_datetime ) ? strtotime( (string) $event->end_datetime ) : false;

	if ( false === $end ) {
		return (string) $time;
	}

	/* translators: 1: start time, 2: end time. */
	return sprintf( _x( '%1$s - %2$s', 'event time range', 'event-ticket-manager' ), $time, wp_date( $format, $end ) );
}

/**
 * Best-effort client IP for rate limiting and booking records.
 *
 * @return string
 */
function etm_get_client_ip(): string {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	/**
	 * Filter the detected client IP. Sites behind a proxy can map their own
	 * trusted forwarding header here.
	 *
	 * @param string $ip Detected IP.
	 */
	$ip = (string) apply_filters( 'etm_client_ip', $ip );

	return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
}

/**
 * Escaped status badge markup.
 *
 * @param string $status Status key.
 * @param string $label  Human label.
 * @return string
 */
function etm_status_badge( string $status, string $label ): string {
	return sprintf(
		'<span class="etm-badge etm-badge--%1$s">%2$s</span>',
		esc_attr( sanitize_html_class( $status ) ),
		esc_html( $label )
	);
}

/**
 * Booking status label.
 *
 * @param string $status Status key.
 * @return string
 */
function etm_booking_status_label( string $status ): string {
	$statuses = ETM_Bookings::statuses();

	return $statuses[ $status ] ?? ucfirst( $status );
}

/**
 * Event status label.
 *
 * @param string $status Status key.
 * @return string
 */
function etm_event_status_label( string $status ): string {
	$statuses = ETM_Events::statuses();

	return $statuses[ $status ] ?? ucfirst( $status );
}
