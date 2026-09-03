<?php
/**
 * Enqueues the minimal admin stylesheet.
 *
 * @package Site0_Ticketing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Assets loader.
 */
class Site0_Ticketing_Assets {

	/**
	 * Singleton.
	 *
	 * @var Site0_Ticketing_Assets|null
	 */
	private static $instance = null;

	/**
	 * Returns singleton.
	 *
	 * @return Site0_Ticketing_Assets
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hooks.
	 */
	private function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Loads the stylesheet only on ticket screens.
	 *
	 * @param string $hook Current admin screen.
	 */
	public function enqueue( $hook ) {
		if ( false === strpos( $hook, 'site0-ticketing' ) ) {
			return;
		}

		wp_enqueue_style(
			'site0-ticketing-admin',
			SITE0_TICKETING_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			SITE0_TICKETING_VERSION
		);
	}
}
