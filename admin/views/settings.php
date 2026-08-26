<?php
/**
 * Settings screen shell with tabs.
 *
 * @package EventTicketManager
 *
 * @var string   $tab  Active tab key.
 * @var string[] $tabs Available tabs.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap etm-wrap">
	<h1><?php esc_html_e( 'Event Ticket Settings', 'event-ticket-manager' ); ?></h1>

	<?php
	// Prints the "Settings saved." notice after options.php redirects back here.
	settings_errors();
	?>

	<nav class="nav-tab-wrapper etm-tabs">
		<?php foreach ( $tabs as $etm_key => $etm_label ) : ?>
			<a
				href="<?php echo esc_url( admin_url( 'admin.php?page=etm-settings&tab=' . $etm_key ) ); ?>"
				class="nav-tab <?php echo $tab === $etm_key ? 'nav-tab-active' : ''; ?>"
			>
				<?php echo esc_html( $etm_label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<?php
	$etm_partial = ETM_PLUGIN_DIR . 'admin/views/settings-' . $tab . '.php';

	if ( file_exists( $etm_partial ) ) {
		include $etm_partial;
	}
	?>
</div>
