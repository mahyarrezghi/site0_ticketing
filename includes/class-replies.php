<?php
/**
 * Ticket reply model.
 *
 * @package Site0_Ticketing
 */

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for the site0_ticket_replies table.
 */
class Site0_Ticketing_Replies {

	const AUTHOR_TENANT = 'tenant';
	const AUTHOR_ADMIN  = 'admin';

	/**
	 * Adds a reply to a ticket.
	 *
	 * Wiring the reply author_type to the surrounding logic.
	 *
	 * @param array $args {
	 *     @type int    $ticket_id   Ticket ID.
	 *     @type int    $user_id     Replying user.
	 *     @type string $author_name Display name.
	 *     @type string $author_type tenant|admin.
	 *     @type string $message     Reply body.
	 * }
	 * @return int|WP_Error
	 */
	public static function add( $args ) {
		global $wpdb;

		$ticket_id   = isset( $args['ticket_id'] ) ? (int) $args['ticket_id'] : 0;
		$user_id     = isset( $args['user_id'] ) ? (int) $args['user_id'] : get_current_user_id();
		$author_name = isset( $args['author_name'] ) ? sanitize_text_field( $args['author_name'] ) : '';
		$author_type = isset( $args['author_type'] ) && self::AUTHOR_ADMIN === $args['author_type'] ? self::AUTHOR_ADMIN : self::AUTHOR_TENANT;
		$message     = isset( $args['message'] ) ? wp_kses_post( $args['message'] ) : '';

		if ( '' === trim( wp_strip_all_tags( $message ) ) ) {
			return new WP_Error( 'site0_ticketing_reply_empty', __( 'Please enter a reply.', 'site0-ticketing' ) );
		}

		if ( ! $ticket_id ) {
			return new WP_Error( 'site0_ticketing_reply_no_ticket', __( 'Invalid ticket.', 'site0-ticketing' ) );
		}

		$result = $wpdb->insert(
			$wpdb->base_prefix . 's0_ticket_replies',
			array(
				'ticket_id'   => $ticket_id,
				'user_id'     => $user_id,
				'author_name' => $author_name,
				'author_type' => $author_type,
				'message'     => $message,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error( 'site0_ticketing_db_error', __( 'Could not save the reply.', 'site0-ticketing' ) );
		}

		$reply_id = (int) $wpdb->insert_id;

		/**
		 * Fires after a reply is added to a ticket.
		 *
		 * @since 1.2.0
		 *
		 * @param int    $reply_id    Reply ID.
		 * @param int    $ticket_id   Ticket ID.
		 * @param string $author_type Reply author type (tenant|admin).
		 * @param int    $user_id     Reply author user ID.
		 */
		do_action( 'site0_ticketing_reply_added', $reply_id, $ticket_id, $author_type, $user_id );

		return $reply_id;
	}

	/**
	 * Fetches all replies for a ticket, oldest first.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return object[]
	 */
	public static function list_for_ticket( $ticket_id ) {
		global $wpdb;

		$table = $wpdb->base_prefix . 's0_ticket_replies';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE ticket_id = %d ORDER BY created_at ASC, id ASC",
				(int) $ticket_id
			)
		);
	}

	/**
	 * Deletes all replies for a ticket.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return bool
	 */
	public static function delete_all_for_ticket( $ticket_id ) {
		global $wpdb;

		$result = $wpdb->delete(
			$wpdb->base_prefix . 's0_ticket_replies',
			array( 'ticket_id' => (int) $ticket_id ),
			array( '%d' )
		);

		return false !== $result;
	}
}
