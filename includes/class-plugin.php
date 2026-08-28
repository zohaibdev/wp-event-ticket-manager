<?php
/**
 * Plugin container and bootstrapper.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads every service and wires the WordPress hooks.
 */
final class ETM_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var ETM_Plugin|null
	 */
	private static ?ETM_Plugin $instance = null;

	/**
	 * Instantiated services keyed by short name.
	 *
	 * @var array<string, object>
	 */
	private array $services = array();

	/**
	 * Retrieve the singleton.
	 *
	 * @return ETM_Plugin
	 */
	public static function instance(): ETM_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor: load files then boot services.
	 */
	private function __construct() {
		$this->includes();
		$this->init_services();

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ) );
		add_filter( 'plugin_action_links_' . ETM_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Load class files.
	 */
	private function includes(): void {
		$files = array(
			'includes/class-database.php',
			'includes/class-settings.php',
			'includes/class-custom-fields.php',
			'includes/class-events.php',
			'includes/class-event-tickets.php',
			'includes/class-fees.php',
			'includes/class-coupons.php',
			'includes/class-pending-orders.php',
			'includes/class-capacity.php',
			'includes/class-bookings.php',
			'includes/class-qr-encoder.php',
			'includes/class-attendees.php',
			'includes/class-attendees-export.php',
			'includes/class-ticket-verification.php',
			'includes/class-stripe.php',
			'includes/class-checkout.php',
			'includes/class-webhooks.php',
			'includes/class-email-templates.php',
			'includes/class-emails.php',
			'includes/class-shortcodes.php',
			'includes/class-cron.php',
			'includes/functions.php',
		);

		if ( is_admin() ) {
			$files[] = 'admin/class-admin.php';
		}

		foreach ( $files as $file ) {
			require_once ETM_PLUGIN_DIR . $file;
		}
	}

	/**
	 * Instantiate services in dependency order.
	 */
	private function init_services(): void {
		$this->services['database']      = new ETM_Database();
		$this->services['settings']      = new ETM_Settings();
		$this->services['custom_fields'] = new ETM_Custom_Fields();
		$this->services['events']        = new ETM_Events();
		$this->services['capacity']      = new ETM_Capacity();
		$this->services['bookings']      = new ETM_Bookings();
		$this->services['attendees']     = new ETM_Attendees();
		$this->services['ticket_verification'] = new ETM_Ticket_Verification();
		$this->services['stripe']        = new ETM_Stripe();
		$this->services['emails']        = new ETM_Emails();
		$this->services['checkout']      = new ETM_Checkout();
		$this->services['webhooks']      = new ETM_Webhooks();
		$this->services['shortcodes']    = new ETM_Shortcodes();
		$this->services['cron']          = new ETM_Cron();

		if ( is_admin() ) {
			$this->services['admin'] = new ETM_Admin();
		}

		foreach ( $this->services as $service ) {
			if ( method_exists( $service, 'hooks' ) ) {
				$service->hooks();
			}
		}

		/**
		 * Fires once every plugin service is registered.
		 *
		 * @param ETM_Plugin $plugin Plugin container.
		 */
		do_action( 'etm_services_loaded', $this );
	}

	/**
	 * Fetch a service instance.
	 *
	 * @param string $name Service key.
	 * @return object|null
	 */
	public function service( string $name ): ?object {
		return $this->services[ $name ] ?? null;
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'event-ticket-manager', false, dirname( ETM_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * Activation routine: tables, indexes, cron, version stamp.
	 */
	public static function activate(): void {
		require_once ETM_PLUGIN_DIR . 'includes/class-database.php';
		require_once ETM_PLUGIN_DIR . 'includes/class-settings.php';
		require_once ETM_PLUGIN_DIR . 'includes/class-custom-fields.php';
		// The schema migrations create ticket types, so this one is needed even
		// though activation never boots the full plugin.
		require_once ETM_PLUGIN_DIR . 'includes/class-event-tickets.php';
		// The attendee-record and security-code migrations need these: the
		// former reads each booking to build the purchaser's attendee row.
		require_once ETM_PLUGIN_DIR . 'includes/class-bookings.php';
		require_once ETM_PLUGIN_DIR . 'includes/class-attendees.php';
		require_once ETM_PLUGIN_DIR . 'includes/class-cron.php';

		ETM_Database::install();
		ETM_Settings::install_defaults();
		ETM_Custom_Fields::install_defaults();
		ETM_Cron::schedule_events();

		update_option( 'etm_version', ETM_VERSION );
		update_option( 'etm_db_version', ETM_DB_VERSION );

		// The webhook route lives on the REST API, so permalinks must be fresh.
		flush_rewrite_rules();
	}

	/**
	 * Deactivation routine. User data is deliberately preserved.
	 */
	public static function deactivate(): void {
		require_once ETM_PLUGIN_DIR . 'includes/class-cron.php';

		ETM_Cron::clear_events();
		flush_rewrite_rules();
	}

	/**
	 * Run migrations when the stored DB version is behind the code.
	 */
	public function maybe_upgrade(): void {
		$stored = get_option( 'etm_db_version', '0' );

		if ( version_compare( $stored, ETM_DB_VERSION, '<' ) ) {
			ETM_Database::install();
			update_option( 'etm_db_version', ETM_DB_VERSION );
			update_option( 'etm_version', ETM_VERSION );
		}
	}

	/**
	 * Add a settings shortcut on the plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function plugin_action_links( array $links ): array {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=etm-settings' ) ),
			esc_html__( 'Settings', 'event-ticket-manager' )
		);

		array_unshift( $links, $settings );

		return $links;
	}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialising.
	 */
	public function __wakeup() {
		throw new \RuntimeException( 'ETM_Plugin cannot be unserialized.' );
	}
}
