<?php
/**
 * Automatic closing of stale answered tickets.
 *
 * @package Site0_Ticketing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Schedules and runs the daily auto-close job.
 */
class Site0_Ticketing_Auto_Close {

	const CRON_HOOK       = 'site0_ticketing_auto_close';
	const OPTION_ENABLED  = 'site0_ticketing_auto_close_enabled';
	const OPTION_DAYS     = 'site0_ticketing_auto_close_days';
	const DEFAULT_ENABLED = 1;
	const DEFAULT_DAYS    = 5;

	/**
	 * Whether automatic closing is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return 1 === (int) get_site_option( self::OPTION_ENABLED, self::DEFAULT_ENABLED );
	}

	/**
	 * Number of days of inactivity before a ticket is closed.
	 *
	 * @return int
	 */
	public static function get_days() {
		return max( 1, (int) get_site_option( self::OPTION_DAYS, self::DEFAULT_DAYS ) );
	}

	/**
	 * Schedules the daily event when enabled and not already scheduled.
	 */
	public static function ensure_scheduled() {
		if ( ! self::is_enabled() ) {
			self::unschedule();
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Clears the scheduled event.
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}

		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Closes answered tickets whose last admin reply is older than the threshold.
	 */
	public static function run() {
		if ( ! self::is_enabled() ) {
			return;
		}

		$threshold = current_datetime()
			->modify( '-' . self::get_days() . ' days' )
			->format( 'Y-m-d H:i:s' );

		Site0_Ticketing_Tickets::close_stale_answered( $threshold );
	}
}

add_action( 'init', array( 'Site0_Ticketing_Auto_Close', 'ensure_scheduled' ) );
add_action( Site0_Ticketing_Auto_Close::CRON_HOOK, array( 'Site0_Ticketing_Auto_Close', 'run' ) );
