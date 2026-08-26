<?php
/**
 * Admin menus, screens and form handlers.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Everything that renders or processes a wp-admin request.
 */
class ETM_Admin {

	public const CAPABILITY = 'manage_options';

	/**
	 * Register hooks.
	 */
	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_etm_save_event', array( $this, 'handle_save_event' ) );
		add_action( 'admin_post_etm_event_action', array( $this, 'handle_event_action' ) );
		add_action( 'admin_post_etm_booking_action', array( $this, 'handle_booking_action' ) );
		add_action( 'admin_post_etm_save_fields', array( $this, 'handle_save_fields' ) );
		add_action( 'admin_post_etm_save_coupon', array( $this, 'handle_save_coupon' ) );
		add_action( 'admin_post_etm_coupon_action', array( $this, 'handle_coupon_action' ) );
		add_action( 'admin_post_etm_save_fee', array( $this, 'handle_save_fee' ) );
		add_action( 'admin_post_etm_fee_action', array( $this, 'handle_fee_action' ) );
		add_action( 'admin_post_etm_save_templates', array( $this, 'handle_save_templates' ) );
		add_action( 'admin_post_etm_reset_template', array( $this, 'handle_reset_template' ) );
		add_action( 'admin_post_etm_send_test_email', array( $this, 'handle_send_test_email' ) );
		add_action( 'wp_ajax_etm_test_stripe_connection', array( $this, 'ajax_test_stripe_connection' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
	}

	/**
	 * The capability required to manage the plugin.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filter the capability required to manage events and bookings.
		 *
		 * @param string $capability Capability name.
		 */
		return (string) apply_filters( 'etm_admin_capability', self::CAPABILITY );
	}

	/**
	 * Register the admin menu tree.
	 */
	public function register_menus(): void {
		$capability = self::capability();

		add_menu_page(
			__( 'Event Tickets', 'event-ticket-manager' ),
			__( 'Event Tickets', 'event-ticket-manager' ),
			$capability,
			'etm-events',
			array( $this, 'render_events_page' ),
			'dashicons-tickets-alt',
			26
		);

		add_submenu_page(
			'etm-events',
			__( 'Events', 'event-ticket-manager' ),
			__( 'Events', 'event-ticket-manager' ),
			$capability,
			'etm-events',
			array( $this, 'render_events_page' )
		);

		add_submenu_page(
			'etm-events',
			__( 'Bookings', 'event-ticket-manager' ),
			__( 'Bookings', 'event-ticket-manager' ),
			$capability,
			'etm-bookings',
			array( $this, 'render_bookings_page' )
		);

		add_submenu_page(
			'etm-events',
			__( 'Coupons', 'event-ticket-manager' ),
			__( 'Coupons', 'event-ticket-manager' ),
			$capability,
			'etm-coupons',
			array( $this, 'render_coupons_page' )
		);

		add_submenu_page(
			'etm-events',
			__( 'Fees', 'event-ticket-manager' ),
			__( 'Fees', 'event-ticket-manager' ),
			$capability,
			'etm-fees',
			array( $this, 'render_fees_page' )
		);

		add_submenu_page(
			'etm-events',
			__( 'Settings', 'event-ticket-manager' ),
			__( 'Settings', 'event-ticket-manager' ),
			$capability,
			'etm-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Load admin CSS and JS on plugin screens only.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! str_contains( $hook_suffix, 'etm-' ) ) {
			return;
		}

		wp_enqueue_style( 'etm-admin', ETM_PLUGIN_URL . 'admin/assets/css/admin.css', array(), ETM_VERSION );
		wp_enqueue_script( 'etm-admin', ETM_PLUGIN_URL . 'admin/assets/js/admin.js', array( 'jquery' ), ETM_VERSION, true );

		wp_localize_script(
			'etm-admin',
			'etmAdmin',
			array(
				'confirmDelete'        => __( 'Delete this permanently? This cannot be undone.', 'event-ticket-manager' ),
				'confirmDeleteBooking' => __( 'Delete this booking? Attendee records will be removed too.', 'event-ticket-manager' ),
				'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
				'stripeTestNonce'      => wp_create_nonce( 'etm_test_stripe_connection' ),
				'testing'              => __( 'Testing...', 'event-ticket-manager' ),
				'testConnection'       => __( 'Test Connection', 'event-ticket-manager' ),
				'genericError'         => __( 'The connection test could not be completed. Please try again.', 'event-ticket-manager' ),
				'unsavedKeys'          => __( 'Save your settings first - the test uses the saved keys, not what is typed on screen.', 'event-ticket-manager' ),
			)
		);

		if ( str_contains( $hook_suffix, 'etm-events' ) ) {
			wp_enqueue_editor();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab check.
		$settings_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		if ( str_contains( $hook_suffix, 'etm-settings' ) && 'templates' === $settings_tab ) {
			wp_enqueue_editor();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab check.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		if ( str_contains( $hook_suffix, 'etm-settings' ) && 'customizer' === $tab ) {
			wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_script( 'wp-color-picker' );
		}
	}

	/**
	 * Verify the saved Stripe credentials against Stripe, on demand.
	 *
	 * Reads the account behind the configured secret key. Nothing is created,
	 * charged or captured, so this is safe to run against live credentials.
	 *
	 * The secret key never leaves the server: the request carries only a nonce,
	 * and the response carries only an account id, a display name and a
	 * message. The key is not echoed back even on failure.
	 */
	public function ajax_test_stripe_connection(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to manage Stripe settings.', 'event-ticket-manager' ) ),
				403
			);
		}

		if ( ! check_ajax_referer( 'etm_test_stripe_connection', 'nonce', false ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Your session has expired. Please reload the page and try again.', 'event-ticket-manager' ) ),
				403
			);
		}

		$mode   = ETM_Stripe::get_mode();
		$result = ETM_Stripe::verify_connection();

		if ( is_wp_error( $result ) ) {
			ETM_Stripe::store_connection_state(
				ETM_Stripe::STATUS_FAILED,
				array( 'message' => ETM_Stripe::redact( $result->get_error_message() ) )
			);

			wp_send_json_error(
				array(
					'message' => __( 'Stripe connection failed.', 'event-ticket-manager' ),
					'reason'  => ETM_Stripe::redact( $result->get_error_message() ),
					'code'    => (string) $result->get_error_code(),
					'mode'    => $mode,
					'status'  => ETM_Stripe::STATUS_FAILED,
					'label'   => ETM_Stripe::connection_status_label( ETM_Stripe::STATUS_FAILED ),
				)
			);
		}

		ETM_Stripe::store_connection_state( ETM_Stripe::STATUS_CONNECTED, $result );

		wp_send_json_success(
			array(
				'message'       => __( 'Stripe connection successful.', 'event-ticket-manager' ),
				'mode'          => $mode,
				'status'        => ETM_Stripe::STATUS_CONNECTED,
				'label'         => ETM_Stripe::connection_status_label( ETM_Stripe::STATUS_CONNECTED ),
				'account_id'    => $result['account_id'],
				'business_name' => $result['business_name'],
			)
		);
	}

	/**
	 * Guard every admin request handler.
	 */	/**
	 * Guard every admin request handler.
	 */
	private function require_capability(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage events.', 'event-ticket-manager' ), 403 );
		}
	}

	/* ------------------------------------------------------- Screens --- */

	/**
	 * Events list or the add/edit form.
	 */
	public function render_events_page(): void {
		$this->require_capability();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$action   = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
		$event_id = isset( $_GET['event'] ) ? absint( $_GET['event'] ) : 0;
		// phpcs:enable

		if ( in_array( $action, array( 'new', 'edit' ), true ) ) {
			$event  = $event_id ? ETM_Events::get( $event_id ) : null;
			$meta   = $event_id ? ETM_Events::get_all_meta( $event_id ) : array();
			$fields = ETM_Custom_Fields::get_fields( ETM_Custom_Fields::GROUP_EVENT, false );

			include ETM_PLUGIN_DIR . 'admin/views/event-form.php';

			return;
		}

		require_once ETM_PLUGIN_DIR . 'admin/class-events-list-table.php';

		$table = new ETM_Events_List_Table();
		$table->prepare_items();

		include ETM_PLUGIN_DIR . 'admin/views/events-list.php';
	}

	/**
	 * Bookings list or a single booking.
	 */
	public function render_bookings_page(): void {
		$this->require_capability();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$action     = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
		$booking_id = isset( $_GET['booking'] ) ? absint( $_GET['booking'] ) : 0;
		// phpcs:enable

		if ( 'view' === $action && $booking_id ) {
			$booking = ETM_Bookings::get( $booking_id );

			if ( ! $booking ) {
				wp_die( esc_html__( 'Booking not found.', 'event-ticket-manager' ), 404 );
			}

			$event         = ETM_Events::get( (int) $booking->event_id );
			$attendees     = ETM_Attendees::get_for_booking( $booking_id );
			$custom_values = ETM_Bookings::get_custom_field_values( $booking_id );
			$meta          = ETM_Bookings::get_all_meta( $booking_id );

			include ETM_PLUGIN_DIR . 'admin/views/booking-detail.php';

			return;
		}

		require_once ETM_PLUGIN_DIR . 'admin/class-bookings-list-table.php';

		$table = new ETM_Bookings_List_Table();
		$table->prepare_items();

		include ETM_PLUGIN_DIR . 'admin/views/bookings-list.php';
	}

	/**
	 * Coupons list or add/edit form.
	 */
	public function render_coupons_page(): void {
		$this->require_capability();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$action    = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
		$coupon_id = isset( $_GET['coupon'] ) ? absint( $_GET['coupon'] ) : 0;
		// phpcs:enable

		$action = in_array( $action, array( 'new', 'edit' ), true ) ? $action : 'list';
		$coupon = 'edit' === $action ? ETM_Coupons::get( $coupon_id ) : null;

		if ( 'edit' === $action && ! $coupon ) {
			$action = 'list';
		}

		include ETM_PLUGIN_DIR . 'admin/views/coupons.php';
	}

	/**
	 * Fees list or add/edit form.
	 */
	public function render_fees_page(): void {
		$this->require_capability();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
		$fee_id = isset( $_GET['fee'] ) ? absint( $_GET['fee'] ) : 0;
		// phpcs:enable

		$action = in_array( $action, array( 'new', 'edit' ), true ) ? $action : 'list';
		$fee    = 'edit' === $action ? ETM_Fees::get( $fee_id ) : null;

		if ( 'edit' === $action && ! $fee ) {
			$action = 'list';
		}

		include ETM_PLUGIN_DIR . 'admin/views/fees.php';
	}

	/**
	 * Create or update a coupon.
	 */
	public function handle_save_coupon(): void {
		$this->require_capability();
		check_admin_referer( 'etm_save_coupon' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$posted    = wp_unslash( $_POST );
		$coupon_id = isset( $posted['coupon_id'] ) ? absint( $posted['coupon_id'] ) : 0;

		$result = ETM_Coupons::sanitize( is_array( $posted ) ? $posted : array(), $coupon_id );

		if ( ! empty( $result['errors'] ) ) {
			set_transient( 'etm_coupon_errors_' . get_current_user_id(), $result['errors'], 60 );

			$this->redirect_back(
				add_query_arg(
					array(
						'page'    => 'etm-coupons',
						'action'  => $coupon_id ? 'edit' : 'new',
						'coupon'  => $coupon_id,
						'etm_msg' => 'invalid',
					),
					admin_url( 'admin.php' )
				)
			);
		}

		if ( $coupon_id ) {
			$saved = ETM_Coupons::update( $coupon_id, $result['values'] );
			$msg   = $saved ? 'updated' : 'error';
		} else {
			$created = ETM_Coupons::create( $result['values'] );
			$msg     = is_wp_error( $created ) ? 'error' : 'created';
		}

		$this->redirect_back(
			add_query_arg(
				array( 'page' => 'etm-coupons', 'etm_msg' => $msg ),
				admin_url( 'admin.php' )
			)
		);
	}

	/**
	 * Delete a coupon.
	 */
	public function handle_coupon_action(): void {
		$this->require_capability();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce checked below.
		$action    = isset( $_REQUEST['etm_action'] ) ? sanitize_key( wp_unslash( $_REQUEST['etm_action'] ) ) : '';
		$coupon_id = isset( $_REQUEST['coupon'] ) ? absint( $_REQUEST['coupon'] ) : 0;
		// phpcs:enable

		check_admin_referer( 'etm_coupon_action_' . $action . '_' . $coupon_id );

		$message = ( 'delete' === $action && ETM_Coupons::delete( $coupon_id ) ) ? 'deleted' : 'error';

		$this->redirect_back(
			add_query_arg( array( 'page' => 'etm-coupons', 'etm_msg' => $message ), admin_url( 'admin.php' ) )
		);
	}

	/**
	 * Create or update a fee.
	 */
	public function handle_save_fee(): void {
		$this->require_capability();
		check_admin_referer( 'etm_save_fee' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$posted = wp_unslash( $_POST );
		$fee_id = isset( $posted['fee_id'] ) ? absint( $posted['fee_id'] ) : 0;

		$result = ETM_Fees::sanitize( is_array( $posted ) ? $posted : array() );

		if ( ! empty( $result['errors'] ) ) {
			set_transient( 'etm_fee_errors_' . get_current_user_id(), $result['errors'], 60 );

			$this->redirect_back(
				add_query_arg(
					array(
						'page'    => 'etm-fees',
						'action'  => $fee_id ? 'edit' : 'new',
						'fee'     => $fee_id,
						'etm_msg' => 'invalid',
					),
					admin_url( 'admin.php' )
				)
			);
		}

		if ( $fee_id ) {
			$saved = ETM_Fees::update( $fee_id, $result['values'] );
			$msg   = $saved ? 'updated' : 'error';
		} else {
			$created = ETM_Fees::create( $result['values'] );
			$msg     = is_wp_error( $created ) ? 'error' : 'created';
		}

		$this->redirect_back(
			add_query_arg( array( 'page' => 'etm-fees', 'etm_msg' => $msg ), admin_url( 'admin.php' ) )
		);
	}

	/**
	 * Delete a fee.
	 */
	public function handle_fee_action(): void {
		$this->require_capability();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce checked below.
		$action = isset( $_REQUEST['etm_action'] ) ? sanitize_key( wp_unslash( $_REQUEST['etm_action'] ) ) : '';
		$fee_id = isset( $_REQUEST['fee'] ) ? absint( $_REQUEST['fee'] ) : 0;
		// phpcs:enable

		check_admin_referer( 'etm_fee_action_' . $action . '_' . $fee_id );

		$message = ( 'delete' === $action && ETM_Fees::delete( $fee_id ) ) ? 'deleted' : 'error';

		$this->redirect_back(
			add_query_arg( array( 'page' => 'etm-fees', 'etm_msg' => $message ), admin_url( 'admin.php' ) )
		);
	}

	/**
	 * Save every email template.
	 */
	public function handle_save_templates(): void {
		$this->require_capability();
		check_admin_referer( 'etm_save_templates' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$posted    = wp_unslash( $_POST );
		$templates = isset( $posted['templates'] ) && is_array( $posted['templates'] ) ? $posted['templates'] : array();

		ETM_Email_Templates::save( $templates );

		$this->redirect_back(
			add_query_arg(
				array( 'page' => 'etm-settings', 'tab' => 'templates', 'etm_msg' => 'updated' ),
				admin_url( 'admin.php' )
			)
		);
	}

	/**
	 * Restore one email template to its default.
	 */
	public function handle_reset_template(): void {
		$this->require_capability();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked below.
		$key = isset( $_REQUEST['template'] ) ? sanitize_key( wp_unslash( $_REQUEST['template'] ) ) : '';

		check_admin_referer( 'etm_reset_template_' . $key );

		ETM_Email_Templates::reset( $key );

		$this->redirect_back(
			add_query_arg(
				array( 'page' => 'etm-settings', 'tab' => 'templates', 'etm_msg' => 'updated' ),
				admin_url( 'admin.php' )
			)
		);
	}

	/**
	 * Settings screen with tabs.
	 */
	public function render_settings_page(): void {
		$this->require_capability();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';

		$tabs = array(
			'general'    => __( 'General', 'event-ticket-manager' ),
			'stripe'     => __( 'Stripe', 'event-ticket-manager' ),
			'email'      => __( 'Email', 'event-ticket-manager' ),
			'forms'      => __( 'Form Fields', 'event-ticket-manager' ),
			'templates'  => __( 'Email Templates', 'event-ticket-manager' ),
			'customizer' => __( 'Customizer', 'event-ticket-manager' ),
		);

		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'general';
		}

		include ETM_PLUGIN_DIR . 'admin/views/settings.php';
	}

	/* ------------------------------------------------------ Handlers --- */

	/**
	 * Create or update an event.
	 */
	public function handle_save_event(): void {
		$this->require_capability();
		check_admin_referer( 'etm_save_event' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$posted   = wp_unslash( $_POST );
		$event_id = isset( $posted['event_id'] ) ? absint( $posted['event_id'] ) : 0;

		$result = ETM_Events::sanitize( is_array( $posted ) ? $posted : array() );

		if ( ! empty( $result['errors'] ) ) {
			set_transient( 'etm_event_errors_' . get_current_user_id(), $result['errors'], 60 );
			set_transient( 'etm_event_input_' . get_current_user_id(), $result['values'], 60 );

			$this->redirect_back(
				add_query_arg(
					array(
						'page'   => 'etm-events',
						'action' => $event_id ? 'edit' : 'new',
						'event'  => $event_id,
						'etm_msg' => 'invalid',
					),
					admin_url( 'admin.php' )
				)
			);
		}

		if ( $event_id ) {
			$saved = ETM_Events::update( $event_id, $result['values'] );
			$msg   = is_wp_error( $saved ) ? 'error' : 'updated';
		} else {
			$saved    = ETM_Events::create( $result['values'] );
			$msg      = is_wp_error( $saved ) ? 'error' : 'created';
			$event_id = is_wp_error( $saved ) ? 0 : (int) $saved;
		}

		// Ticket types repeater.
		if ( $event_id && ! is_wp_error( $saved ) ) {
			$ticket_rows = isset( $posted['event_tickets'] ) && is_array( $posted['event_tickets'] ) ? $posted['event_tickets'] : array();
			$ticket_save = ETM_Event_Tickets::save_for_event( $event_id, $ticket_rows );

			if ( ! empty( $ticket_save['errors'] ) ) {
				set_transient( 'etm_ticket_errors_' . get_current_user_id(), $ticket_save['errors'], 60 );
				$msg = 'invalid';
			}
		}

		// Event instructions, type and RSVP address all live in event meta.
		if ( $event_id && ! is_wp_error( $saved ) ) {
			ETM_Events::update_meta( $event_id, 'instruction', wp_kses_post( (string) ( $posted['instruction'] ?? '' ) ) );

			$event_type = ETM_Events::TYPE_RSVP === ( $posted['event_type'] ?? '' )
				? ETM_Events::TYPE_RSVP
				: ETM_Events::TYPE_TICKET;

			ETM_Events::update_meta( $event_id, 'event_type', $event_type );

			// Optional per-event override, checked for every booking on this
			// event regardless of type. Invalid addresses are dropped rather
			// than blocking the rest of the save, same as the ticket repeater.
			$rsvp_result = ETM_Settings::parse_email_list_with_errors( (string) ( $posted['rsvp_notification_email'] ?? '' ) );

			ETM_Events::update_meta( $event_id, 'rsvp_notification_email', implode( "
", $rsvp_result['valid'] ) );

			if ( ! empty( $rsvp_result['invalid'] ) ) {
				set_transient(
					'etm_event_errors_' . get_current_user_id(),
					array(
						'rsvp_notification_email' => sprintf(
							/* translators: %s: comma-separated list of addresses that were not saved. */
							__( 'These event notification addresses were not valid and were not saved: %s', 'event-ticket-manager' ),
							implode( ', ', $rsvp_result['invalid'] )
						),
					),
					60
				);
				$msg = 'invalid';
			}
		}

		// Event custom fields.
		if ( $event_id && ! is_wp_error( $saved ) ) {
			$custom_raw = isset( $posted['event_fields'] ) && is_array( $posted['event_fields'] ) ? $posted['event_fields'] : array();
			$validated  = ETM_Custom_Fields::validate( ETM_Custom_Fields::GROUP_EVENT, $custom_raw );

			foreach ( $validated['values'] as $key => $value ) {
				ETM_Events::update_meta( $event_id, $key, $value );
			}
		}

		$this->redirect_back(
			add_query_arg(
				array(
					'page'    => 'etm-events',
					'action'  => 'edit',
					'event'   => $event_id,
					'etm_msg' => $msg,
				),
				admin_url( 'admin.php' )
			)
		);
	}

	/**
	 * Duplicate, delete, publish or close an event.
	 */
	public function handle_event_action(): void {
		$this->require_capability();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce checked below per action.
		$action   = isset( $_REQUEST['etm_action'] ) ? sanitize_key( wp_unslash( $_REQUEST['etm_action'] ) ) : '';
		$event_id = isset( $_REQUEST['event'] ) ? absint( $_REQUEST['event'] ) : 0;
		// phpcs:enable

		check_admin_referer( 'etm_event_action_' . $action . '_' . $event_id );

		$message = 'error';

		switch ( $action ) {
			case 'duplicate':
				$new = ETM_Events::duplicate( $event_id );
				$message = is_wp_error( $new ) ? 'error' : 'duplicated';
				break;

			case 'delete':
				$message = ETM_Events::delete( $event_id ) ? 'deleted' : 'error';
				break;

			case 'publish':
				$message = ETM_Events::set_status( $event_id, ETM_Events::STATUS_PUBLISHED ) ? 'updated' : 'error';
				break;

			case 'close':
				$message = ETM_Events::set_status( $event_id, ETM_Events::STATUS_CLOSED ) ? 'updated' : 'error';
				break;

			case 'cancel':
				$message = ETM_Events::set_status( $event_id, ETM_Events::STATUS_CANCELLED ) ? 'updated' : 'error';
				break;
		}

		$this->redirect_back(
			add_query_arg(
				array(
					'page'    => 'etm-events',
					'etm_msg' => $message,
				),
				admin_url( 'admin.php' )
			)
		);
	}

	/**
	 * Change a booking status, resend an email or delete a booking.
	 */
	public function handle_booking_action(): void {
		$this->require_capability();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce checked below per action.
		$action     = isset( $_REQUEST['etm_action'] ) ? sanitize_key( wp_unslash( $_REQUEST['etm_action'] ) ) : '';
		$booking_id = isset( $_REQUEST['booking'] ) ? absint( $_REQUEST['booking'] ) : 0;
		$status     = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '';
		// phpcs:enable

		check_admin_referer( 'etm_booking_action_' . $action . '_' . $booking_id );

		$redirect = add_query_arg(
			array(
				'page'    => 'etm-bookings',
				'action'  => 'view',
				'booking' => $booking_id,
			),
			admin_url( 'admin.php' )
		);

		switch ( $action ) {
			case 'set_status':
				$message = ETM_Bookings::set_status( $booking_id, $status ) ? 'updated' : 'error';
				break;

			case 'resend_customer_email':
				$booking = ETM_Bookings::get( $booking_id );
				$emails  = etm()->service( 'emails' );
				$message = ( $booking && $emails instanceof ETM_Emails && $emails->send_customer_confirmation( $booking ) ) ? 'email_sent' : 'error';
				break;

			case 'delete':
				$message  = ETM_Bookings::delete( $booking_id ) ? 'deleted' : 'error';
				$redirect = add_query_arg( array( 'page' => 'etm-bookings' ), admin_url( 'admin.php' ) );
				break;

			default:
				$message = 'error';
				break;
		}

		$this->redirect_back( add_query_arg( 'etm_msg', $message, $redirect ) );
	}

	/**
	 * Save the custom field manager.
	 */
	public function handle_save_fields(): void {
		$this->require_capability();
		check_admin_referer( 'etm_save_fields' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$posted = wp_unslash( $_POST );
		$groups = isset( $posted['fields'] ) && is_array( $posted['fields'] ) ? $posted['fields'] : array();

		foreach ( ETM_Custom_Fields::groups() as $group => $label ) {
			$submitted = isset( $groups[ $group ] ) && is_array( $groups[ $group ] ) ? $groups[ $group ] : array();
			$existing  = ETM_Custom_Fields::get_fields( $group, false );
			$core_map  = array();

			foreach ( $existing as $field ) {
				if ( ! empty( $field['core'] ) ) {
					$core_map[ $field['key'] ] = $field;
				}
			}

			$clean = array();
			$order = 0;
			$seen  = array();

			foreach ( $submitted as $raw ) {
				if ( ! is_array( $raw ) ) {
					continue;
				}

				$key = ETM_Custom_Fields::sanitize_key( (string) ( $raw['key'] ?? '' ) );

				if ( '' === $key || isset( $seen[ $key ] ) ) {
					continue;
				}

				$seen[ $key ] = true;
				$is_core      = isset( $core_map[ $key ] );

				$clean[] = ETM_Custom_Fields::normalise_field(
					array(
						'key'      => $key,
						'label'    => $raw['label'] ?? ( $core_map[ $key ]['label'] ?? $key ),
						// Core fields keep their type: the database columns depend on it.
						'type'     => $is_core ? $core_map[ $key ]['type'] : ( $raw['type'] ?? 'text' ),
						// Core fields are always enabled and required.
						'required' => $is_core ? 1 : ( empty( $raw['required'] ) ? 0 : 1 ),
						'enabled'  => $is_core ? 1 : ( empty( $raw['enabled'] ) ? 0 : 1 ),
						'order'    => $order,
						'core'     => $is_core ? 1 : 0,
						'options'  => $raw['options'] ?? array(),
					)
				);

				++$order;
			}

			// Never let a core field be dropped from the group.
			foreach ( $core_map as $key => $field ) {
				if ( ! isset( $seen[ $key ] ) ) {
					$field['order'] = $order++;
					$clean[]        = $field;
				}
			}

			ETM_Custom_Fields::save_group( $group, $clean );
		}

		$this->redirect_back(
			add_query_arg(
				array(
					'page'    => 'etm-settings',
					'tab'     => 'forms',
					'etm_msg' => 'fields_saved',
				),
				admin_url( 'admin.php' )
			)
		);
	}

	/**
	 * Send a test copy of the customer email.
	 */
	public function handle_send_test_email(): void {
		$this->require_capability();
		check_admin_referer( 'etm_send_test_email' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$to     = isset( $_POST['test_email'] ) ? sanitize_email( wp_unslash( $_POST['test_email'] ) ) : '';
		$emails = etm()->service( 'emails' );

		$sent = ( is_email( $to ) && $emails instanceof ETM_Emails ) ? $emails->send_test( $to ) : false;

		$this->redirect_back(
			add_query_arg(
				array(
					'page'    => 'etm-settings',
					'tab'     => 'email',
					'etm_msg' => $sent ? 'email_sent' : 'error',
				),
				admin_url( 'admin.php' )
			)
		);
	}

	/**
	 * Redirect and stop.
	 *
	 * @param string $url Destination.
	 */
	private function redirect_back( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render the admin notice for the current etm_msg value.
	 */
	public function render_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$message = isset( $_GET['etm_msg'] ) ? sanitize_key( wp_unslash( $_GET['etm_msg'] ) ) : '';

		if ( '' === $message ) {
			return;
		}

		$messages = array(
			'created'      => array( 'success', __( 'Event created.', 'event-ticket-manager' ) ),
			'updated'      => array( 'success', __( 'Changes saved.', 'event-ticket-manager' ) ),
			'deleted'      => array( 'success', __( 'Deleted.', 'event-ticket-manager' ) ),
			'duplicated'   => array( 'success', __( 'Event duplicated.', 'event-ticket-manager' ) ),
			'fields_saved' => array( 'success', __( 'Form fields saved.', 'event-ticket-manager' ) ),
			'email_sent'   => array( 'success', __( 'Email sent.', 'event-ticket-manager' ) ),
			'invalid'      => array( 'error', __( 'Please correct the highlighted fields.', 'event-ticket-manager' ) ),
			'error'        => array( 'error', __( 'Something went wrong. Please try again.', 'event-ticket-manager' ) ),
		);

		if ( ! isset( $messages[ $message ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $message ][0] ),
			esc_html( $messages[ $message ][1] )
		);
	}
}
