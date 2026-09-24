<?php
/**
 * Capability and access helpers.
 *
 * @package Site0_Ticketing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Centralises access checks for tickets in a multisite context.
 */
class Site0_Ticketing_Capabilities {

	/**
	 * Whether the current user is a network admin.
	 *
	 * @return bool
	 */
	public static function is_network_admin() {
		return current_user_can( 'manage_network' );
	}

	/**
	 * Whether the current user may view a specific ticket.
	 *
	 * @param object $ticket Ticket row.
	 * @return bool
	 */
	public static function can_view_ticket( $ticket ) {
		if ( self::is_network_admin() ) {
			return true;
		}

		if ( ! $ticket ) {
			return false;
		}

		// Anyone with tenant ticket access on the originating blog may view it (shared desk).
		if ( get_current_blog_id() === (int) $ticket->blog_id && self::can_use_tenant_tickets() ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether the current user may use the tenant ticketing UI on this blog.
	 *
	 * Covers site admins, editors and (WooCommerce) shop managers. The check
	 * is capability-based so custom roles can qualify; access remains scoped
	 * to the current blog's membership.
	 *
	 * @return bool
	 */
	public static function can_use_tenant_tickets() {
		if ( self::is_network_admin() ) {
			return true;
		}

		$can = current_user_can( 'manage_options' )      // Site admin.
			|| current_user_can( 'edit_pages' )          // Editor.
			|| current_user_can( 'manage_woocommerce' ); // Shop manager.

		/**
		 * Filters whether the current user can use the tenant ticketing UI.
		 *
		 * @param bool $can Whether access is granted.
		 */
		return apply_filters( 'site0_ticketing_tenant_access', $can );
	}
}
