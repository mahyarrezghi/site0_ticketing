<?php
/**
 * Schema installation and versioning.
 *
 * @package Site0_Ticketing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles creation / upgrades of the network ticket tables.
 */
class Site0_Ticketing_Install {

	const SCHEMA_VERSION = '1.3.0';
	const DB_VERSION_OPTION = 'site0_ticketing_db_version';

	/**
	 * Fired on plugin activation (network-wide only).
	 */
	public static function activate() {
		// Block single-site activation on multisite; network activation only.
		if ( is_multisite() && ! is_network_admin() && ! defined( 'WP_CLI' ) ) {
			deactivate_plugins( plugin_basename( SITE0_TICKETING_PLUGIN_FILE ) );
			wp_die( esc_html__( 'Site0 Ticketing can only be activated network-wide from the Network Admin.', 'site0-ticketing' ) );
		}

		self::install_or_upgrade();
	}

	/**
	 * Runs schema checks on admin_init and upgrades when needed.
	 */
	public static function maybe_upgrade() {
		$version = get_site_option( self::DB_VERSION_OPTION, '0' );

		if ( version_compare( $version, self::SCHEMA_VERSION, '<' ) ) {
			self::install_or_upgrade();
		}
	}

	/**
	 * Creates/updates the tables and records the schema version.
	 */
	private static function install_or_upgrade() {
		self::create_tables();
		self::backfill_ticket_numbers();
		update_site_option( self::DB_VERSION_OPTION, self::SCHEMA_VERSION );
	}

	/**
	 * Creates the ticket tables using dbDelta against the network base prefix.
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$tickets_table = $wpdb->base_prefix . 's0_tickets';

		$tickets_sql = "CREATE TABLE {$tickets_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			ticket_number VARCHAR(8) DEFAULT NULL,
			blog_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			subject VARCHAR(255) NOT NULL DEFAULT '',
			message LONGTEXT NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'waiting',
			unread_by_tenant TINYINT(1) NOT NULL DEFAULT 0,
			unread_by_admin TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ticket_number (ticket_number),
			KEY blog_id (blog_id),
			KEY user_id (user_id),
			KEY status (status),
			KEY updated_at (updated_at)
		) {$charset_collate};";

		dbDelta( $tickets_sql );

		$replies_table = $wpdb->base_prefix . 's0_ticket_replies';

		$replies_sql = "CREATE TABLE {$replies_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			ticket_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			author_name VARCHAR(255) NOT NULL DEFAULT '',
			author_type VARCHAR(20) NOT NULL DEFAULT 'tenant',
			message LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY ticket_id (ticket_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $replies_sql );

		$attachments_table = $wpdb->base_prefix . 's0_ticket_attachments';

		$attachments_sql = "CREATE TABLE {$attachments_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			ticket_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			reply_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			file_name VARCHAR(255) NOT NULL DEFAULT '',
			stored_name VARCHAR(255) NOT NULL DEFAULT '',
			mime_type VARCHAR(100) NOT NULL DEFAULT '',
			file_size BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY ticket_id (ticket_id),
			KEY reply_id (reply_id)
		) {$charset_collate};";

		dbDelta( $attachments_sql );
	}

	/**
	 * Assigns an 8-digit ticket number to any tickets that lack one.
	 *
	 * Run after the column is added so tickets created before v1.3.0 (and any
	 * row inserted while the column was nullable) get a unique number.
	 */
	public static function backfill_ticket_numbers() {
		global $wpdb;

		$table = $wpdb->base_prefix . 's0_tickets';

		$ids = $wpdb->get_col(
			"SELECT id FROM {$table} WHERE ticket_number IS NULL OR ticket_number = ''"
		);

		if ( empty( $ids ) ) {
			return;
		}

		foreach ( $ids as $ticket_id ) {
			$number = Site0_Ticketing_Tickets::generate_unique_number();

			if ( '' === $number ) {
				continue;
			}

			$wpdb->update(
				$table,
				array( 'ticket_number' => $number ),
				array( 'id' => (int) $ticket_id ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}
}
