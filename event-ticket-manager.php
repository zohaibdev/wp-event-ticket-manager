<?php
/**
 * Plugin Name:       Event Ticket Manager
 * Plugin URI:        https://webnday.com
 * Description:       Standalone event ticket management: event CRUD, ticket variations and quantities, attendee collection, in-page Stripe payments, bookings and email notifications. Frontend is shortcode based.
 * Version:           1.9.1
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Webnday
 * Author URI:        https://webnday.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       event-ticket-manager
 * Domain Path:       /languages
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

define( 'ETM_VERSION', '1.9.1' );
define( 'ETM_DB_VERSION', '1.7.0' );
define( 'ETM_PLUGIN_FILE', __FILE__ );
define( 'ETM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ETM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ETM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Minimum PHP guard. Bail out politely instead of fataling on old hosts.
 */
if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Event Ticket Manager requires PHP 8.1 or higher. The plugin has been loaded in a limited state.', 'event-ticket-manager' )
			);
		}
	);
	return;
}

require_once ETM_PLUGIN_DIR . 'includes/class-plugin.php';

/**
 * Main plugin accessor.
 *
 * @return ETM_Plugin
 */
function etm() {
	return ETM_Plugin::instance();
}

register_activation_hook( __FILE__, array( 'ETM_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ETM_Plugin', 'deactivate' ) );

etm();
