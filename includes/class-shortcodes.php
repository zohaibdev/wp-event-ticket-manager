<?php
/**
 * Frontend shortcode and asset loading.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the [event_ticket] block.
 */
class ETM_Shortcodes {

	/**
	 * Stripe.js handle.
	 */
	private const STRIPE_JS = 'etm-stripe-js';

	/**
	 * Whether the frontend assets have been enqueued already.
	 *
	 * @var bool
	 */
	private bool $assets_done = false;

	/**
	 * Register hooks.
	 */
	public function hooks(): void {
		add_shortcode( 'event_ticket', array( $this, 'render_event_ticket' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register (but do not enqueue) the frontend assets.
	 */
	public function register_assets(): void {
		wp_register_style(
			'etm-frontend',
			ETM_PLUGIN_URL . 'frontend/assets/css/frontend.css',
			array(),
			ETM_VERSION
		);

		// Stripe.js must be loaded from Stripe's own domain; bundling it breaks
		// PCI compliance and is forbidden by their terms.
		wp_register_script( self::STRIPE_JS, 'https://js.stripe.com/v3/', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion

		wp_register_script(
			'etm-frontend',
			ETM_PLUGIN_URL . 'frontend/assets/js/frontend.js',
			array( self::STRIPE_JS ),
			ETM_VERSION,
			true
		);
	}

	/**
	 * Enqueue assets on demand and hand shared config to the script.
	 */
	private function enqueue_assets(): void {
		if ( $this->assets_done ) {
			return;
		}

		wp_enqueue_style( 'etm-frontend' );

		$branding_css = ETM_Settings::branding_css();

		if ( '' !== $branding_css ) {
			wp_add_inline_style( 'etm-frontend', $branding_css );
		}

		wp_enqueue_script( self::STRIPE_JS );
		wp_enqueue_script( 'etm-frontend' );

		wp_localize_script(
			'etm-frontend',
			'etmConfig',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( ETM_Checkout::NONCE_ACTION ),
				'currency' => array(
					'code'     => ETM_Settings::currency(),
					'symbol'   => html_entity_decode( etm_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
					'decimals' => etm_currency_decimals(),
				),
				'appearance' => array(
					'primary'    => (string) ETM_Settings::branding( 'primary_color', '#4f46e5' ),
					'text'       => (string) ETM_Settings::branding( 'text_color', '#111827' ),
					'background' => (string) ETM_Settings::branding( 'background_color', '#ffffff' ),
				),
				'i18n'     => array(
					'required'      => __( 'This field is required.', 'event-ticket-manager' ),
					'invalidEmail'  => __( 'Please enter a valid email address.', 'event-ticket-manager' ),
					'genericError'  => __( 'Something went wrong. Please try again.', 'event-ticket-manager' ),
					'paymentFailed' => __( 'The payment could not be completed. Please check your card details and try again.', 'event-ticket-manager' ),
					'stillPending'  => __( 'Your payment is still being confirmed. We will email you as soon as it clears.', 'event-ticket-manager' ),
					'attendees'     => __( 'Attendees', 'event-ticket-manager' ),
					'yourDetails'   => __( 'You', 'event-ticket-manager' ),
					'roommate'      => __( 'Roommate', 'event-ticket-manager' ),
					'pay'           => __( 'Pay', 'event-ticket-manager' ),
					'event'         => __( 'Event', 'event-ticket-manager' ),
					'date'          => __( 'Date', 'event-ticket-manager' ),
					'time'          => __( 'Time', 'event-ticket-manager' ),
					'name'          => __( 'Name', 'event-ticket-manager' ),
					'email'         => __( 'Email', 'event-ticket-manager' ),
					'quantity'      => __( 'Tickets', 'event-ticket-manager' ),
					'ticketPrice'   => __( 'Ticket price', 'event-ticket-manager' ),
					'subtotal'      => __( 'Ticket subtotal', 'event-ticket-manager' ),
					'paymentStatus' => __( 'Payment status', 'event-ticket-manager' ),
					'transaction'   => __( 'Transaction ID', 'event-ticket-manager' ),
					'bookingDate'   => __( 'Booking date', 'event-ticket-manager' ),
					'totalPaid'     => __( 'Total paid', 'event-ticket-manager' ),
					'emailSent'     => __( 'A confirmation email has been sent to', 'event-ticket-manager' ),
					'selectTicket'  => __( 'Please select at least one ticket.', 'event-ticket-manager' ),
					'ticketsLabel'  => __( 'Tickets', 'event-ticket-manager' ),
					'eventFull'     => __( 'There are no more places available for this event.', 'event-ticket-manager' ),
					'yourTicket'    => __( 'Your ticket', 'event-ticket-manager' ),
					'each'          => __( 'each', 'event-ticket-manager' ),
					'continuePay'   => __( 'Continue to Payment', 'event-ticket-manager' ),
					'completeOrder' => __( 'Complete Booking', 'event-ticket-manager' ),
					'completeRsvp'  => __( 'Confirm RSVP', 'event-ticket-manager' ),
					'couponApplied' => __( 'Coupon applied.', 'event-ticket-manager' ),
					'couponRemoved' => __( 'Coupon removed.', 'event-ticket-manager' ),
					'total'         => __( 'Total', 'event-ticket-manager' ),
					'fees'          => __( 'Fees', 'event-ticket-manager' ),
					'coupon'        => __( 'Coupon', 'event-ticket-manager' ),
				),
			)
		);

		$this->assets_done = true;
	}

	/**
	 * Shortcode handler.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_event_ticket( $atts ): string {
		$atts = shortcode_atts(
			array(
				'event_id' => 0,
			),
			$atts,
			'event_ticket'
		);

		$event_id = absint( $atts['event_id'] );

		if ( $event_id <= 0 ) {
			return $this->admin_notice( __( 'Add an event ID to the shortcode, for example [event_ticket event_id="12"].', 'event-ticket-manager' ) );
		}

		$event = ETM_Events::get( $event_id );

		if ( ! $event ) {
			return $this->admin_notice( __( 'That event could not be found.', 'event-ticket-manager' ) );
		}

		if ( ETM_Events::STATUS_DRAFT === $event->status && ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		if ( ! ETM_Event_Tickets::event_has_tickets( $event_id ) ) {
			return $this->admin_notice( __( 'This event has no enabled ticket types yet. Add at least one under Event Tickets > Events.', 'event-ticket-manager' ) );
		}

		$this->enqueue_assets();

		$sold_out         = ETM_Capacity::is_sold_out( $event );
		$purchasable      = ETM_Events::is_purchasable( $event );
		$payments_ready   = ETM_Stripe::is_configured() && '' !== ETM_Stripe::get_publishable_key();
		$event_cap        = ETM_Capacity::get_remaining( $event );

		// Prices and limits come from the event's ticket types.
		$ticket_types = array();

		foreach ( ETM_Event_Tickets::get_for_event( $event_id, true ) as $ticket ) {
			$max = ETM_Event_Tickets::max_selectable( $ticket, $event_cap );

			$ticket_types[] = array(
				'id'             => (int) $ticket->id,
				'title'          => (string) $ticket->title,
				'price'          => round( (float) $ticket->price, 2 ),
				'min'            => ETM_Event_Tickets::min_for_type( $ticket ),
				'max'            => $max,
				'price_label'    => ETM_Event_Tickets::price_label( $ticket ),
				'variation_type' => ETM_Event_Tickets::sanitize_variation_type( $ticket->variation_type ?? '' ),
			);
		}

		$has_tickets = ! empty( $ticket_types );

		// Every type sold out is the same thing as the event being sold out.
		if ( $has_tickets && ! $sold_out ) {
			$sellable = array_filter(
				$ticket_types,
				static function ( $type ) {
					return $type['max'] > 0;
				}
			);

			$sold_out = empty( $sellable );
		}

		$data = array(
			'event'                  => $event,
			'event_id'               => $event_id,
			'uid'                    => 'etm-' . $event_id . '-' . wp_rand( 1000, 9999 ),
			'ticket_price'           => round( (float) $event->ticket_price, 2 ),
			'currency'               => ETM_Settings::currency(),
			// Repeated onto the block markup as a fallback for the localised
			// script. wp_localize_script() emits a separate inline <script>,
			// which caching and script-combining plugins are free to strip,
			// move or fail to include in a bundle - and when that happens the
			// checkout has no admin-ajax URL and no nonce, so nothing can be
			// submitted at all. These travel inside the shortcode's own HTML,
			// where no amount of script rewriting can separate them from it.
			'ajax_url'               => admin_url( 'admin-ajax.php' ),
			'nonce'                  => wp_create_nonce( ETM_Checkout::NONCE_ACTION ),
			'currency_symbol'        => html_entity_decode( etm_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'currency_decimals'      => etm_currency_decimals(),
			'coupon_enabled'         => ETM_Coupons::enabled(),
			'description'            => (string) $event->description,
			'instruction'            => (string) ETM_Events::get_meta( $event_id, 'instruction', '' ),
			'is_rsvp'                => ETM_Events::is_rsvp( $event_id ),
			'sold_out'               => $sold_out,
			'purchasable'            => $purchasable && ! $sold_out && $payments_ready && $has_tickets,
			'payments_ready'         => $payments_ready,
			'ticket_types'           => $ticket_types,
			'has_tickets'            => $has_tickets,
			'event_cap'              => $event_cap,
			// The purchaser is attendee 1, so "Your Details" carries the customer
			// fields plus any extra attendee fields for that first person.
			'customer_fields'        => ETM_Custom_Fields::get_fields( ETM_Custom_Fields::GROUP_CUSTOMER ),
			'attendee_fields'        => ETM_Custom_Fields::get_fields( ETM_Custom_Fields::GROUP_ATTENDEE ),
			'attendee_extra_fields'  => ETM_Custom_Fields::get_custom_fields( ETM_Custom_Fields::GROUP_ATTENDEE ),
			// Reused as-is for Sharing a Room, so any extra roommate question an
			// admin already configured keeps being asked.
			'roommate_fields'        => ETM_Custom_Fields::get_fields( ETM_Custom_Fields::GROUP_ROOMMATE ),
		);

		/**
		 * Filter the data passed to the ticket block template.
		 *
		 * @param array  $data  Template data.
		 * @param object $event Event row.
		 */
		$data = (array) apply_filters( 'etm_ticket_block_data', $data, $event );

		return etm_get_template( 'event-ticket-block.php', $data );
	}

	/**
	 * Message shown only to administrators when the shortcode is misconfigured.
	 *
	 * @param string $message Message text.
	 * @return string
	 */
	private function admin_notice( string $message ): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		return '<div class="etm-admin-warning">' . esc_html( $message ) . '</div>';
	}
}
