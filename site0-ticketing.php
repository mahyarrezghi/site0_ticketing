<?php
/**
 * Plugin Name:       Site0 Ticketing
 * Plugin URI:        https://site0.ir
 * Description:       Support ticketing for the Site0 multisite network. Tenants open tickets from their subsite dashboard; network admins answer from the network dashboard.
 * Version:           1.3.0
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * Author:            Mahyar Rezghi
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       site0-ticketing
 * Domain Path:       /languages
 *
 * Network:           true
 *
 * @package Site0_Ticketing
 */

defined( 'ABSPATH' ) || exit;

define( 'SITE0_TICKETING_VERSION', '1.3.0' );
define( 'SITE0_TICKETING_PLUGIN_FILE', __FILE__ );
define( 'SITE0_TICKETING_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SITE0_TICKETING_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SITE0_TICKETING_PLUGIN_DIR . 'includes/class-install.php';
require_once SITE0_TICKETING_PLUGIN_DIR . 'includes/class-capabilities.php';
require_once SITE0_TICKETING_PLUGIN_DIR . 'includes/class-tickets.php';
require_once SITE0_TICKETING_PLUGIN_DIR . 'includes/class-replies.php';
require_once SITE0_TICKETING_PLUGIN_DIR . 'includes/class-attachments.php';
require_once SITE0_TICKETING_PLUGIN_DIR . 'includes/class-tenant-page.php';
require_once SITE0_TICKETING_PLUGIN_DIR . 'includes/class-network-page.php';
require_once SITE0_TICKETING_PLUGIN_DIR . 'includes/class-assets.php';

/**
 * Main plugin class.
 *
 * @since 1.0.0
 */
final class Site0_Ticketing {

	/**
	 * Singleton instance.
	 *
	 * @var Site0_Ticketing|null
	 */
	private static $instance = null;

	/**
	 * Returns the single instance.
	 *
	 * @return Site0_Ticketing
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Hooks everything together.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_init', array( 'Site0_Ticketing_Install', 'maybe_upgrade' ) );
		add_filter( 'all_plugins', array( $this, 'hide_from_subsites' ) );

		register_activation_hook( __FILE__, array( 'Site0_Ticketing_Install', 'activate' ) );

		Site0_Ticketing_Assets::instance();
		Site0_Ticketing_Tenant_Page::instance();
		Site0_Ticketing_Network_Page::instance();
		Site0_Ticketing_Attachments::instance();
	}

	/**
	 * Initialises components once plugins are loaded.
	 */
	public function init() {
		self::guard_multisite();
	}

	/**
	 * Loads the plugin text domain using the network locale so tenant UI is translated.
	 */
	public function load_textdomain() {
		$locale = function_exists( 'get_site_locale' ) ? get_site_locale() : get_locale();
		load_textdomain( 'site0-ticketing', SITE0_TICKETING_PLUGIN_DIR . 'languages/site0-ticketing-' . $locale . '.mo' );
		load_plugin_textdomain( 'site0-ticketing', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/**
	 * Soft-guards against non-multisite installs.
	 */
	private function guard_multisite() {
		if ( ! is_multisite() ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Site0 Ticketing requires a WordPress multisite network.', 'site0-ticketing' ) . '</p></div>';
				}
			);
		}
	}

	/**
	 * Hides the plugin from subsite plugin lists so tenants cannot
	 * see or deactivate it.
	 *
	 * @param array $plugins All plugins.
	 * @return array
	 */
	public function hide_from_subsites( $plugins ) {
		if ( ! is_network_admin() ) {
			unset( $plugins[ plugin_basename( SITE0_TICKETING_PLUGIN_FILE ) ] );
		}

		return $plugins;
	}
}

Site0_Ticketing::instance();
