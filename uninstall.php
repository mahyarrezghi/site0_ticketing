<?php
/**
 * Uninstall routine for Site0 Ticketing.
 *
 * Data is preserved by default. Tables are dropped only when a network
 * admin has explicitly enabled deletion (network option
 * `site0_ticketing_delete_on_uninstall` set to 1 via Network Admin →
 * Tickets → Settings).
 *
 * @package Site0_Ticketing
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Never drop data unless explicitly opted in.
if ( 1 !== (int) get_site_option( 'site0_ticketing_delete_on_uninstall', 0 ) ) {
	return;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->base_prefix}s0_tickets" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->base_prefix}s0_ticket_replies" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->base_prefix}s0_ticket_attachments" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Remove stored attachment files under the main site's uploads directory.
$switched = false;

if ( is_multisite() && get_main_site_id() !== get_current_blog_id() ) {
	switch_to_blog( get_main_site_id() );
	$switched = true;
}

$upload_dir = wp_upload_dir();
$dir        = trailingslashit( $upload_dir['basedir'] ) . 'site0-ticketing';

if ( $switched ) {
	restore_current_blog();
}

/**
 * Recursively deletes a directory from the attachment storage.
 *
 * @param string $path Directory path.
 */
function site0_ticketing_rrmdir( $path ) {
	if ( ! is_dir( $path ) ) {
		return;
	}

	$entries = array_diff( (array) scandir( $path ), array( '.', '..' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

	foreach ( $entries as $entry ) {
		$entry_path = $path . '/' . $entry;

		if ( is_dir( $entry_path ) ) {
			site0_ticketing_rrmdir( $entry_path );
		} else {
			@unlink( $entry_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}

if ( is_dir( $dir ) && false !== strpos( realpath( $dir ), (string) realpath( untrailingslashit( $upload_dir['basedir'] ) ) ) ) {
	site0_ticketing_rrmdir( $dir );
}

delete_site_option( 'site0_ticketing_db_version' );
delete_site_option( 'site0_ticketing_delete_on_uninstall' );
