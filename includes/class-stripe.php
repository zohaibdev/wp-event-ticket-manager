<?php
/**
 * Stripe gateway wrapper.
 *
 * Talks to the Stripe REST API over the WordPress HTTP API so the plugin has no
 * Composer dependency. If the official Stripe PHP SDK happens to be autoloaded
 * by the site it is used for signature verification instead of the built-in
 * implementation, but it is never required.
 *
 * Secret keys never leave the server. Only the publishable key reaches the
 * browser, where Stripe.js uses it to confirm a PaymentIntent whose client
 * secret this class creates.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stripe API client and key management.
 */
class ETM_Stripe {

	public const API_BASE    = 'https://api.stripe.com/v1/';
	public const API_VERSION = '2023-10-16';

	/**
	 * Currencies Stripe expects in whole units rather than cents.
	 *
	 * @var string[]
	 */
	private const ZERO_DECIMAL = array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );

	/**
	 * Register hooks.
	 */
	public function hooks(): void {}

	/**
	 * Whether payments are switched on.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return (bool) ETM_Settings::stripe( 'enable_stripe', 0 );
	}

	/**
	 * Current mode: test or live.
	 *
	 * @return string
	 */
	public static function get_mode(): string {
		return 'live' === ETM_Settings::stripe( 'mode', 'test' ) ? 'live' : 'test';
	}

	/**
	 * A full-access secret key (sk_).
	 */
	public const CREDENTIAL_SECRET = 'secret_key';

	/**
	 * A restricted key (rk_) carrying only the permissions it was granted.
	 */
	public const CREDENTIAL_RESTRICTED = 'restricted_key';

	/**
	 * Credential types with labels.
	 *
	 * @return array<string, string>
	 */
	public static function credential_types(): array {
		return array(
			self::CREDENTIAL_SECRET     => __( 'Secret Key', 'event-ticket-manager' ),
			self::CREDENTIAL_RESTRICTED => __( 'Restricted API Key', 'event-ticket-manager' ),
		);
	}

	/**
	 * Which credential the site is configured to authenticate with.
	 *
	 * @return string
	 */
	public static function get_credential_type(): string {
		$type = (string) ETM_Settings::stripe( 'credential_type', self::CREDENTIAL_SECRET );

		return isset( self::credential_types()[ $type ] ) ? $type : self::CREDENTIAL_SECRET;
	}

	/**
	 * The Stripe permissions this plugin actually needs.
	 *
	 * Derived from the complete set of API calls it makes - creating, updating,
	 * cancelling and retrieving PaymentIntents, plus reading the account for the
	 * connection test. It touches no customer, charge, refund or payment method
	 * endpoint, so a restricted key needs nothing beyond these.
	 *
	 * @return array<int, array{name: string, level: string, required: bool, note: string}>
	 */
	public static function required_permissions(): array {
		return array(
			array(
				'name'     => __( 'PaymentIntents', 'event-ticket-manager' ),
				'level'    => __( 'Write', 'event-ticket-manager' ),
				'required' => true,
				'note'     => __( 'Creating, updating, cancelling and reading the payment for each booking.', 'event-ticket-manager' ),
			),
			array(
				'name'     => __( 'Account', 'event-ticket-manager' ),
				'level'    => __( 'Read', 'event-ticket-manager' ),
				'required' => false,
				'note'     => __( 'Only used by Test Connection, to show which account answered. Payments work without it.', 'event-ticket-manager' ),
			),
		);
	}

	/**
	 * The server-side credential for the active mode and credential type.
	 *
	 * Everything that talks to Stripe reads its key through here, so selecting a
	 * restricted key switches the whole integration over at once - there is no
	 * second path that could keep using the old credential.
	 *
	 * @return string
	 */
	public static function get_secret_key(): string {
		$mode  = self::get_mode();
		$field = self::CREDENTIAL_RESTRICTED === self::get_credential_type()
			? $mode . '_restricted_key'
			: $mode . '_secret_key';

		return trim( (string) ETM_Settings::stripe( $field, '' ) );
	}

	/**
	 * Whether the stored key's prefix disagrees with the selected type.
	 *
	 * Reported rather than enforced: a site already running an rk_ key pasted
	 * into the secret field works perfectly well, and refusing to take payments
	 * over a labelling mismatch would be a worse outcome than saying so.
	 *
	 * @return string Empty when consistent.
	 */
	public static function credential_type_mismatch(): string {
		$key = self::get_secret_key();

		if ( '' === $key ) {
			return '';
		}

		$restricted = 0 === strpos( $key, 'rk_' );
		$secret     = 0 === strpos( $key, 'sk_' );
		$wants_rk   = self::CREDENTIAL_RESTRICTED === self::get_credential_type();

		if ( $wants_rk && $secret ) {
			return __( 'Credential type is set to Restricted API Key, but the saved key is a full secret key (sk_).', 'event-ticket-manager' );
		}

		if ( ! $wants_rk && $restricted ) {
			return __( 'Credential type is set to Secret Key, but the saved key is a restricted key (rk_).', 'event-ticket-manager' );
		}

		return '';
	}

	/**
	 * Publishable key for the active mode.
	 *
	 * @return string
	 */
	public static function get_publishable_key(): string {
		$key = 'live' === self::get_mode()
			? ETM_Settings::stripe( 'live_publishable_key', '' )
			: ETM_Settings::stripe( 'test_publishable_key', '' );

		return trim( (string) $key );
	}

	/**
	 * Webhook signing secret for the active mode.
	 *
	 * Falls back to the shared secret field when no live-specific secret is set.
	 *
	 * @return string
	 */
	public static function get_webhook_secret(): string {
		$shared = trim( (string) ETM_Settings::stripe( 'webhook_secret', '' ) );

		if ( 'live' === self::get_mode() ) {
			$live = trim( (string) ETM_Settings::stripe( 'live_webhook_secret', '' ) );

			return '' !== $live ? $live : $shared;
		}

		return $shared;
	}

	/**
	 * Whether the gateway can actually be used.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return self::is_enabled() && '' !== self::get_secret_key();
	}

	/**
	 * Whether a key contains anything Stripe keys never contain.
	 *
	 * Stripe keys are strictly ASCII letters, digits and underscores. A key that
	 * has travelled through a translation layer, an OCR step or some document
	 * editors can come back with Cyrillic look-alikes substituted in - a Roman
	 * "x" replaced by U+0445, "o" by U+043E - which are visually identical and
	 * survive copy and paste. The prefix still matches, so every local check
	 * passes and the only symptom is Stripe answering "Invalid API Key", which
	 * points the admin at the wrong problem entirely.
	 *
	 * @param string $key Key to inspect.
	 * @return bool
	 */
	public static function has_invalid_characters( string $key ): bool {
		return '' !== $key && (bool) preg_match( '/[^A-Za-z0-9_]/', $key );
	}

	/**
	 * Sanity-check the configured keys against the active mode, without ever
	 * calling Stripe.
	 *
	 * A key pasted into the wrong field - a test key saved as the live key, or
	 * the reverse - still passes is_configured() (both are non-empty strings)
	 * and would otherwise surface only as a cryptic Stripe authentication
	 * error once a real charge is attempted. Checking the key prefixes here
	 * catches the common misconfiguration immediately, with a message that
	 * says what to go fix.
	 *
	 * @return string Empty when the keys look right for the active mode.
	 */
	public static function key_mode_error(): string {
		$secret      = self::get_secret_key();
		$publishable = self::get_publishable_key();

		if ( '' === $secret || '' === $publishable ) {
			// Nothing configured yet: is_configured() already reports this.
			return '';
		}

		foreach ( array( 'secret' => $secret, 'publishable' => $publishable ) as $which => $key ) {
			if ( self::has_invalid_characters( $key ) ) {
				return sprintf(
					/* translators: %s: which key, secret or publishable. */
					__( 'The Stripe %s key contains characters that are not valid in a Stripe key. Copy it again straight from the Stripe dashboard - a key that has passed through a translator, PDF or chat app can pick up look-alike characters that Stripe will reject.', 'event-ticket-manager' ),
					$which
				);
			}
		}

		$expected = 'live' === self::get_mode() ? 'live' : 'test';
		$other    = 'live' === $expected ? 'test' : 'live';

		// Restricted keys (rk_) are live/test just like secret keys.
		$secret_ok = 0 === strpos( $secret, 'sk_' . $expected . '_' )
			|| 0 === strpos( $secret, 'rk_' . $expected . '_' );

		if ( ! $secret_ok ) {
			return 0 === strpos( $secret, 'sk_' . $other . '_' ) || 0 === strpos( $secret, 'rk_' . $other . '_' )
				? sprintf(
					/* translators: 1: expected mode (live/test), 2: the mode the key actually belongs to. */
					__( 'The Stripe secret key is a %2$s key, but Stripe mode is set to %1$s. Save a %1$s secret key under Settings > Stripe.', 'event-ticket-manager' ),
					$expected,
					$other
				)
				: self::wrong_slot_message( $secret );
		}

		$publishable_ok = 0 === strpos( $publishable, 'pk_' . $expected . '_' );

		if ( ! $publishable_ok ) {
			return 0 === strpos( $publishable, 'pk_' . $other . '_' )
				? sprintf(
					/* translators: 1: expected mode (live/test), 2: the mode the key actually belongs to. */
					__( 'The Stripe publishable key is a %2$s key, but Stripe mode is set to %1$s. Save a %1$s publishable key under Settings > Stripe.', 'event-ticket-manager' ),
					$expected,
					$other
				)
				: self::publishable_slot_message( $publishable );
		}

		return '';
	}

	/**
	 * Explain a key that is the wrong kind for the server-side slot.
	 *
	 * The commonest setup mistake is pasting the publishable key here: both are
	 * called "keys" and both are copied from the same dashboard page. Stripe
	 * answers with "Invalid API Key provided: pk_live_...", which reads as
	 * though the key were broken rather than simply in the wrong box, and sends
	 * people off rotating a perfectly good key.
	 *
	 * @param string $key The stored server-side key.
	 * @return string
	 */
	private static function wrong_slot_message( string $key ): string {
		if ( 0 === strpos( $key, 'pk_' ) ) {
			return __( 'A publishable key (pk_) is saved as the server-side key. Publishable keys are meant for the browser and cannot authenticate API requests, which is why Stripe rejects it as invalid. Move it to the Publishable Key field, and save a secret key (sk_) or a restricted key (rk_) here instead.', 'event-ticket-manager' );
		}

		return __( 'The configured Stripe secret key is not in a recognised format. It should begin with sk_ or rk_.', 'event-ticket-manager' );
	}

	/**
	 * Explain a key that is the wrong kind for the publishable slot.
	 *
	 * This one matters more than a formatting warning: the publishable key is
	 * handed to every visitor's browser, so a secret key stored here would be
	 * published to the world as soon as a ticket block rendered.
	 *
	 * @param string $key The stored publishable key.
	 * @return string
	 */
	private static function publishable_slot_message( string $key ): string {
		if ( 0 === strpos( $key, 'sk_' ) || 0 === strpos( $key, 'rk_' ) ) {
			return __( 'A secret or restricted key is saved in the Publishable Key field. That field is sent to every visitor, so this key must be removed and rotated in Stripe immediately. The publishable key is the one beginning pk_.', 'event-ticket-manager' );
		}

		return __( 'The configured Stripe publishable key is not in a recognised format. It should begin with pk_.', 'event-ticket-manager' );
	}

	/**
	 * Convert a decimal amount into the smallest currency unit.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency code.
	 * @return int
	 */
	public static function amount_to_stripe( float $amount, string $currency ): int {
		if ( in_array( strtoupper( $currency ), self::ZERO_DECIMAL, true ) ) {
			return (int) round( $amount );
		}

		return (int) round( $amount * 100 );
	}

	/**
	 * Convert a Stripe amount back into a decimal.
	 *
	 * @param int    $amount   Amount in the smallest unit.
	 * @param string $currency Currency code.
	 * @return float
	 */
	public static function amount_from_stripe( int $amount, string $currency ): float {
		if ( in_array( strtoupper( $currency ), self::ZERO_DECIMAL, true ) ) {
			return (float) $amount;
		}

		return round( $amount / 100, 2 );
	}

	/**
	 * Perform an authenticated Stripe API request.
	 *
	 * @param string               $method          HTTP method.
	 * @param string               $path            API path, e.g. payment_intents.
	 * @param array<string, mixed> $params          Request parameters.
	 * @param string               $idempotency_key Optional idempotency key.
	 * @return array<string, mixed>|WP_Error Decoded response body.
	 */
	public static function request( string $method, string $path, array $params = array(), string $idempotency_key = '' ) {
		$secret = self::get_secret_key();

		if ( '' === $secret ) {
			return new WP_Error( 'etm_stripe_not_configured', __( 'Stripe is not configured.', 'event-ticket-manager' ) );
		}

		$url = self::API_BASE . ltrim( $path, '/' );

		/**
		 * Filter the Stripe HTTP timeout, in seconds.
		 *
		 * A checkout can make up to three of these calls in one request
		 * (retrieve, update, create), so the ceiling here is multiplied. Kept
		 * well under the request duration cap that managed hosts enforce -
		 * exceeding it kills the PHP worker and returns a 502 with no error
		 * anyone can act on, instead of a clean, reportable failure.
		 *
		 * @param int    $seconds Timeout.
		 * @param string $path    API path being called.
		 */
		$timeout = max( 5, (int) apply_filters( 'etm_stripe_timeout', 12, $path ) );

		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => $timeout,
			'headers' => array(
				'Authorization'  => 'Bearer ' . $secret,
				'Stripe-Version' => self::API_VERSION,
				'Content-Type'   => 'application/x-www-form-urlencoded',
			),
		);

		if ( '' !== $idempotency_key ) {
			$args['headers']['Idempotency-Key'] = $idempotency_key;
		}

		if ( 'GET' === $args['method'] ) {
			if ( ! empty( $params ) ) {
				$url = add_query_arg( $params, $url );
			}
		} else {
			$args['body'] = self::build_query( $params );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			self::log( 'HTTP error calling ' . $path . ': ' . $response->get_error_message() );

			return new WP_Error( 'etm_stripe_http_error', __( 'We could not reach the payment provider. Please try again.', 'event-ticket-manager' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			self::log( 'Malformed response from ' . $path );

			return new WP_Error( 'etm_stripe_bad_response', __( 'The payment provider returned an unexpected response.', 'event-ticket-manager' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			$message = $body['error']['message'] ?? __( 'The payment could not be started.', 'event-ticket-manager' );
			$type    = (string) ( $body['error']['type'] ?? '' );

			self::log( 'Stripe API error (' . $code . ') on ' . $path . ': [' . $type . '] ' . $message );

			// card_error is the customer's own declined/invalid card and is safe
			// (useful, even) to show them verbatim. Every other type - a bad key,
			// a malformed request, Stripe's own outage - is a site problem the
			// customer can do nothing about and should not see the internals of.
			return new WP_Error(
				'etm_stripe_api_error',
				$message,
				array(
					'status'         => $code,
					'type'           => $type,
					'customer_safe'  => 'card_error' === $type,
				)
			);
		}

		return $body;
	}

	/**
	 * Build a Stripe-compatible form encoded body.
	 *
	 * @param array<string, mixed> $params Parameters.
	 * @return string
	 */
	private static function build_query( array $params ): string {
		return http_build_query( $params, '', '&', PHP_QUERY_RFC1738 );
	}

	/**
	 * Create a PaymentIntent for an in-modal payment.
	 *
	 * @param array<string, mixed> $params          Intent parameters.
	 * @param string               $idempotency_key Optional idempotency key.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_payment_intent( array $params, string $idempotency_key = '' ) {
		/**
		 * Filter the PaymentIntent parameters before creation.
		 *
		 * @param array $params Intent parameters.
		 */
		$params = (array) apply_filters( 'etm_stripe_payment_intent_params', $params );

		return self::request( 'POST', 'payment_intents', $params, $idempotency_key );
	}

	/**
	 * Update an existing PaymentIntent (used when a customer edits their order
	 * and retries payment, so no orphan intents are left behind).
	 *
	 * @param string               $payment_intent_id PaymentIntent ID.
	 * @param array<string, mixed> $params            Fields to update.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_payment_intent( string $payment_intent_id, array $params ) {
		$payment_intent_id = sanitize_text_field( $payment_intent_id );

		if ( '' === $payment_intent_id ) {
			return new WP_Error( 'etm_stripe_missing_intent', __( 'Missing payment intent.', 'event-ticket-manager' ) );
		}

		return self::request( 'POST', 'payment_intents/' . rawurlencode( $payment_intent_id ), $params );
	}

	/**
	 * Cancel a PaymentIntent that will never be paid.
	 *
	 * @param string $payment_intent_id PaymentIntent ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function cancel_payment_intent( string $payment_intent_id ) {
		$payment_intent_id = sanitize_text_field( $payment_intent_id );

		if ( '' === $payment_intent_id ) {
			return new WP_Error( 'etm_stripe_missing_intent', __( 'Missing payment intent.', 'event-ticket-manager' ) );
		}

		return self::request( 'POST', 'payment_intents/' . rawurlencode( $payment_intent_id ) . '/cancel' );
	}

	/**
	 * Whether a PaymentIntent can still be reused for another attempt.
	 *
	 * @param string $status Intent status.
	 * @return bool
	 */
	public static function intent_is_reusable( string $status ): bool {
		return in_array(
			$status,
			array( 'requires_payment_method', 'requires_confirmation', 'requires_action' ),
			true
		);
	}

	/**
	 * Retrieve a PaymentIntent.
	 *
	 * @param string $payment_intent_id PaymentIntent ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function retrieve_payment_intent( string $payment_intent_id ) {
		$payment_intent_id = sanitize_text_field( $payment_intent_id );

		if ( '' === $payment_intent_id ) {
			return new WP_Error( 'etm_stripe_missing_intent', __( 'Missing payment intent.', 'event-ticket-manager' ) );
		}

		return self::request( 'GET', 'payment_intents/' . rawurlencode( $payment_intent_id ) );
	}

	/**
	 * Verify a webhook signature header.
	 *
	 * Implements the Stripe signature scheme (v1 HMAC-SHA256 over
	 * "timestamp.payload") with a replay tolerance window.
	 *
	 * @param string $payload   Raw request body.
	 * @param string $signature Stripe-Signature header.
	 * @param string $secret    Signing secret.
	 * @param int    $tolerance Allowed clock drift in seconds.
	 * @return true|WP_Error
	 */
	public static function verify_webhook_signature( string $payload, string $signature, string $secret, int $tolerance = 300 ) {
		if ( '' === $secret ) {
			return new WP_Error( 'etm_webhook_no_secret', 'Webhook signing secret is not configured.' );
		}

		if ( '' === $signature ) {
			return new WP_Error( 'etm_webhook_no_signature', 'Missing Stripe signature header.' );
		}

		$timestamp  = null;
		$signatures = array();

		foreach ( explode( ',', $signature ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );

			if ( 2 !== count( $pair ) ) {
				continue;
			}

			if ( 't' === $pair[0] ) {
				$timestamp = (int) $pair[1];
				continue;
			}

			if ( 'v1' === $pair[0] ) {
				$signatures[] = $pair[1];
			}
		}

		if ( null === $timestamp || empty( $signatures ) ) {
			return new WP_Error( 'etm_webhook_bad_signature_header', 'Unable to parse the Stripe signature header.' );
		}

		if ( $tolerance > 0 && abs( time() - $timestamp ) > $tolerance ) {
			return new WP_Error( 'etm_webhook_timestamp', 'Stripe signature timestamp is outside the tolerance window.' );
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );

		foreach ( $signatures as $candidate ) {
			if ( hash_equals( $expected, $candidate ) ) {
				return true;
			}
		}

		return new WP_Error( 'etm_webhook_signature_mismatch', 'Stripe signature verification failed.' );
	}

	/**
	 * How the site's Stripe credentials were supplied.
	 *
	 * Only manual entry exists today. The value is stored and validated so that
	 * adding an OAuth/Connect method later does not require migrating settings
	 * or guessing what an existing site was using.
	 */
	public const CONNECTION_MANUAL = 'manual';

	/**
	 * Connection methods with labels.
	 *
	 * @return array<string, string>
	 */
	public static function connection_methods(): array {
		return array(
			self::CONNECTION_MANUAL => __( 'Manual Connection', 'event-ticket-manager' ),
		);
	}

	/**
	 * The configured connection method, normalised.
	 *
	 * @return string
	 */
	public static function get_connection_method(): string {
		$method = (string) ETM_Settings::stripe( 'connection_method', self::CONNECTION_MANUAL );

		return isset( self::connection_methods()[ $method ] ) ? $method : self::CONNECTION_MANUAL;
	}

	/**
	 * Verify the configured credentials against Stripe.
	 *
	 * Retrieves the account behind the secret key. This is a read: it creates no
	 * PaymentIntent, no charge and no customer, so it is safe to run against
	 * live credentials as often as an admin likes.
	 *
	 * Stripe's answer is authoritative. The key-prefix check runs first only
	 * because it can name the mistake precisely ("this is a test key") where the
	 * API can only say the key is invalid.
	 *
	 * @return array{account_id: string, business_name: string, livemode: bool}|WP_Error
	 */
	public static function verify_connection() {
		if ( ! self::is_enabled() ) {
			return new WP_Error( 'etm_stripe_disabled', __( 'Stripe is not enabled. Tick "Enable Stripe" and save before testing.', 'event-ticket-manager' ) );
		}

		if ( '' === self::get_secret_key() ) {
			return new WP_Error(
				'etm_stripe_no_secret',
				sprintf(
					/* translators: %s: the active mode, test or live. */
					__( 'No %s secret key is saved. Enter one and save before testing.', 'event-ticket-manager' ),
					self::get_mode()
				)
			);
		}

		$mismatch = self::key_mode_error();

		if ( '' !== $mismatch ) {
			return new WP_Error( 'etm_stripe_key_mode', $mismatch );
		}

		$account = self::request( 'GET', 'account' );

		if ( is_wp_error( $account ) ) {
			$data = $account->get_error_data();

			// A restricted key granted only PaymentIntents cannot read the
			// account. That is a perfectly usable key for this plugin, so rather
			// than report a working configuration as broken, fall back to
			// probing the one permission payments actually need.
			if ( 403 === (int) ( $data['status'] ?? 0 ) && self::CREDENTIAL_RESTRICTED === self::get_credential_type() ) {
				$probe = self::request( 'GET', 'payment_intents', array( 'limit' => 1 ) );

				if ( is_wp_error( $probe ) ) {
					$probe_status = (int) ( $probe->get_error_data()['status'] ?? 0 );

					if ( 403 === $probe_status ) {
						return new WP_Error(
							'etm_stripe_missing_permission',
							__( 'The restricted key works, but it has no PaymentIntents permission. Edit the key in Stripe and grant PaymentIntents: Write.', 'event-ticket-manager' )
						);
					}

					return $probe;
				}

				return array(
					'account_id'    => '',
					'business_name' => '',
					'livemode'      => 'live' === self::get_mode(),
					'limited'       => true,
				);
			}

			return $account;
		}

		// Stripe reports which side of the account answered. If that disagrees
		// with the configured mode the keys are crossed in a way the prefix
		// check cannot see, and trusting it would mean charging real cards from
		// a site that believes it is in test mode.
		$livemode  = ! empty( $account['livemode'] );
		$wants_live = 'live' === self::get_mode();

		if ( $livemode !== $wants_live ) {
			return new WP_Error(
				'etm_stripe_mode_mismatch',
				sprintf(
					/* translators: 1: configured mode, 2: mode Stripe reported. */
					__( 'Stripe answered in %2$s mode but this site is set to %1$s mode. Check that the saved keys belong to the %1$s side of your Stripe account.', 'event-ticket-manager' ),
					$wants_live ? 'live' : 'test',
					$livemode ? 'live' : 'test'
				)
			);
		}

		// Deliberately narrow: an account id and a display name are enough to
		// confirm which account answered, and nothing else about it is the
		// browser's business.
		$name = '';

		foreach ( array( 'business_name', 'display_name' ) as $key ) {
			if ( ! empty( $account[ $key ] ) && is_string( $account[ $key ] ) ) {
				$name = $account[ $key ];
				break;
			}
		}

		if ( '' === $name && ! empty( $account['settings']['dashboard']['display_name'] ) ) {
			$name = (string) $account['settings']['dashboard']['display_name'];
		}

		return array(
			'account_id'    => isset( $account['id'] ) ? (string) $account['id'] : '',
			'business_name' => $name,
			'livemode'      => $livemode,
			'limited'       => false,
		);
	}

	/**
	 * Option holding the result of the last connection test.
	 */
	public const CONNECTION_OPTION = 'etm_stripe_connection';

	public const STATUS_NOT_CONNECTED = 'not_connected';
	public const STATUS_CONNECTED     = 'connected';
	public const STATUS_FAILED        = 'failed';

	/**
	 * A short fingerprint of the credentials a test was run against.
	 *
	 * Lets a stored "connected" result be invalidated the moment either key is
	 * edited, without ever storing the keys themselves anywhere a status read
	 * could reach. A hash cannot be reversed into a key.
	 *
	 * @return string
	 */
	private static function credentials_fingerprint(): string {
		return md5( self::get_mode() . '|' . self::get_secret_key() . '|' . self::get_publishable_key() );
	}

	/**
	 * The stored connection state, as it applies to the current mode and keys.
	 *
	 * A result recorded against different credentials, or against the other
	 * mode, is reported as not connected rather than carried over: showing
	 * "Connected" for keys nobody has tested is worse than showing nothing.
	 *
	 * @return array{status: string, mode: string, tested_at: int, account_id: string, business_name: string, message: string}
	 */
	public static function connection_state(): array {
		$stored = get_option( self::CONNECTION_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$blank = array(
			'status'        => self::STATUS_NOT_CONNECTED,
			'mode'          => self::get_mode(),
			'tested_at'     => 0,
			'account_id'    => '',
			'business_name' => '',
			'message'       => '',
		);

		if ( empty( $stored['fingerprint'] ) || $stored['fingerprint'] !== self::credentials_fingerprint() ) {
			return $blank;
		}

		if ( ( $stored['mode'] ?? '' ) !== self::get_mode() ) {
			return $blank;
		}

		return array(
			'status'        => in_array( $stored['status'] ?? '', array( self::STATUS_CONNECTED, self::STATUS_FAILED ), true )
				? $stored['status']
				: self::STATUS_NOT_CONNECTED,
			'mode'          => (string) $stored['mode'],
			'tested_at'     => (int) ( $stored['tested_at'] ?? 0 ),
			'account_id'    => (string) ( $stored['account_id'] ?? '' ),
			'business_name' => (string) ( $stored['business_name'] ?? '' ),
			'message'       => (string) ( $stored['message'] ?? '' ),
		);
	}

	/**
	 * Record the outcome of a connection test.
	 *
	 * Only the account id, a display name and a message are kept - never a key.
	 *
	 * @param string               $status  One of the STATUS_* constants.
	 * @param array<string, mixed> $details Account details or an error message.
	 */
	public static function store_connection_state( string $status, array $details = array() ): void {
		update_option(
			self::CONNECTION_OPTION,
			array(
				'status'        => $status,
				'mode'          => self::get_mode(),
				'fingerprint'   => self::credentials_fingerprint(),
				'tested_at'     => time(),
				'account_id'    => (string) ( $details['account_id'] ?? '' ),
				'business_name' => (string) ( $details['business_name'] ?? '' ),
				'message'       => (string) ( $details['message'] ?? '' ),
			),
			false
		);
	}

	/**
	 * Human label for a connection status.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	public static function connection_status_label( string $status ): string {
		$labels = array(
			self::STATUS_CONNECTED     => __( 'Connected', 'event-ticket-manager' ),
			self::STATUS_FAILED        => __( 'Connection Failed', 'event-ticket-manager' ),
			self::STATUS_NOT_CONNECTED => __( 'Not Connected', 'event-ticket-manager' ),
		);

		return $labels[ $status ] ?? $labels[ self::STATUS_NOT_CONNECTED ];
	}

	/**
	 * Whether the official Stripe PHP SDK is present on the site.
	 *
	 * @return bool
	 */
	public static function sdk_available(): bool {
		return class_exists( '\Stripe\Webhook' );
	}

	/**
	 * Strip anything shaped like a Stripe key out of a string.
	 *
	 * @param string $text Text that may quote an API error.
	 * @return string
	 */
	public static function redact( string $text ): string {
		// Stripe partially redacts keys in its own error copy, and these never
		// reach a customer - but a key fragment has no business in a log file or
		// an admin notice either, so anything key-shaped is removed outright.
		return (string) preg_replace( '/\b(sk|rk|pk)_(live|test)_[A-Za-z0-9*]+/', '$1_$2_[redacted]', $text );
	}

	/**
	 * Write a debug line when WP_DEBUG_LOG is enabled.
	 *
	 * @param string $message Message.
	 */
	public static function log( string $message ): void {
		/**
		 * Filter whether gateway logging is enabled.
		 *
		 * @param bool $enabled Defaults to WP_DEBUG.
		 */
		$enabled = (bool) apply_filters( 'etm_enable_logging', defined( 'WP_DEBUG' ) && WP_DEBUG );

		if ( ! $enabled ) {
			return;
		}

		error_log( '[Event Ticket Manager] ' . self::redact( $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
