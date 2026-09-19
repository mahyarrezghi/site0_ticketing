<?php
/**
 * Ticket model and helpers.
 *
 * @package Site0_Ticketing
 */

defined( 'ABSPATH' ) || exit;

/**
 * CRUD and status helpers for the site0_tickets table.
 */
class Site0_Ticketing_Tickets {

	const STATUS_WAITING     = 'waiting';
	const STATUS_ANSWERED    = 'answered';
	const STATUS_IN_PROGRESS = 'in_progress';
	const STATUS_CLOSED      = 'closed';

	/**
	 * Allowed statuses and their sanitized keys.
	 *
	 * @return array
	 */
	public static function get_statuses() {
		return array(
			self::STATUS_WAITING     => __( 'Waiting', 'site0-ticketing' ),
			self::STATUS_ANSWERED    => __( 'Answered', 'site0-ticketing' ),
			self::STATUS_IN_PROGRESS => __( 'In Progress', 'site0-ticketing' ),
			self::STATUS_CLOSED      => __( 'Closed', 'site0-ticketing' ),
		);
	}

	/**
	 * Normalizes an arbitrary status input to an allowed status.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	public static function sanitize_status( $status ) {
		$statuses = self::get_statuses();

		return isset( $statuses[ $status ] ) ? $status : self::STATUS_WAITING;
	}

	/**
	 * Creates a ticket.
	 *
	 * @param array $args {
	 *     @type int    $blog_id     Subsite the ticket belongs to.
	 *     @type int    $user_id     Tenant who opened it.
	 *     @type string $subject     Subject line.
	 *     @type string $message     Initial message body.
	 * }
	 * @return int|WP_Error Ticket ID on success.
	 */
	public static function create( $args ) {
		global $wpdb;

		$blog_id = isset( $args['blog_id'] ) ? (int) $args['blog_id'] : get_current_blog_id();
		$user_id = isset( $args['user_id'] ) ? (int) $args['user_id'] : get_current_user_id();
		$subject = isset( $args['subject'] ) ? sanitize_text_field( $args['subject'] ) : '';
		$message = isset( $args['message'] ) ? wp_kses_post( $args['message'] ) : '';

		if ( '' === $subject ) {
			return new WP_Error( 'site0_ticketing_subject_empty', __( 'Please enter a subject.', 'site0-ticketing' ) );
		}

		if ( '' === trim( wp_strip_all_tags( $message ) ) ) {
			return new WP_Error( 'site0_ticketing_message_empty', __( 'Please describe the issue.', 'site0-ticketing' ) );
		}

		$now = current_time( 'mysql' );

		$result = $wpdb->insert(
			$wpdb->base_prefix . 's0_tickets',
			array(
				'blog_id'          => $blog_id,
				'user_id'          => $user_id,
				'subject'          => $subject,
				'message'          => $message,
				'status'           => self::STATUS_WAITING,
				'unread_by_tenant' => 0,
				'unread_by_admin'  => 1,
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error( 'site0_ticketing_db_error', __( 'Could not create the ticket.', 'site0-ticketing' ) );
		}

		$ticket_id = (int) $wpdb->insert_id;

		/**
		 * Fires after a ticket is created.
		 *
		 * @since 1.2.0
		 *
		 * @param int    $ticket_id Ticket ID.
		 * @param int    $blog_id   Blog the ticket belongs to.
		 * @param int    $user_id   User who opened the ticket.
		 * @param string $subject   Ticket subject.
		 */
		do_action( 'site0_ticketing_ticket_created', $ticket_id, $blog_id, $user_id, $subject );

		return $ticket_id;
	}

	/**
	 * Fetches a single ticket row.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return object|null
	 */
	public static function get( $ticket_id ) {
		global $wpdb;

		$table = $wpdb->base_prefix . 's0_tickets';

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $ticket_id )
		);
	}

	/**
	 * Fetches tickets for a blog, newest first.
	 *
	 * @param int    $blog_id Blog ID.
	 * @param int    $limit   Number of rows.
	 * @param int    $offset  Offset for pagination.
	 * @param string $status  Optional status filter.
	 * @param string $search  Optional search term.
	 * @return object[]
	 */
	public static function list_by_blog( $blog_id, $limit = 20, $offset = 0, $status = '', $search = '' ) {
		global $wpdb;

		$table  = $wpdb->base_prefix . 's0_tickets';
		$where  = 'WHERE blog_id = %d';
		$params = array( (int) $blog_id );

		if ( '' !== $status ) {
			$where    .= ' AND status = %s';
			$params[] = self::sanitize_status( $status );
		}

		if ( '' !== $search ) {
			$where    .= ' AND (subject LIKE %s OR message LIKE %s)';
			$like      = '%' . $wpdb->esc_like( $search ) . '%';
			$params[]  = $like;
			$params[]  = $like;
		}

		$params[] = (int) $limit;
		$params[] = (int) $offset;

		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} {$where} ORDER BY updated_at DESC LIMIT %d OFFSET %d",
			$params
		);

		return $wpdb->get_results( $sql );
	}

	/**
	 * Counts tickets for a blog.
	 *
	 * @param int    $blog_id Blog ID.
	 * @param string $status  Optional status filter.
	 * @param string $search  Optional search term.
	 * @return int
	 */
	public static function count_by_blog( $blog_id, $status = '', $search = '' ) {
		global $wpdb;

		$table  = $wpdb->base_prefix . 's0_tickets';
		$where  = 'WHERE blog_id = %d';
		$params = array( (int) $blog_id );

		if ( '' !== $status ) {
			$where    .= ' AND status = %s';
			$params[] = self::sanitize_status( $status );
		}

		if ( '' !== $search ) {
			$where    .= ' AND (subject LIKE %s OR message LIKE %s)';
			$like      = '%' . $wpdb->esc_like( $search ) . '%';
			$params[]  = $like;
			$params[]  = $like;
		}

		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} {$where}",
			$params
		);

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Fetches tickets across the network, newest first.
	 *
	 * @param int    $limit   Number of rows.
	 * @param int    $offset  Offset for pagination.
	 * @param string $status  Optional status filter.
	 * @param string $search  Optional search term.
	 * @return object[]
	 */
	public static function list_network( $limit = 20, $offset = 0, $status = '', $search = '' ) {
		global $wpdb;

		$table  = $wpdb->base_prefix . 's0_tickets';
		$where  = 'WHERE 1=1';
		$params = array();

		if ( '' !== $status ) {
			$where    .= ' AND status = %s';
			$params[] = self::sanitize_status( $status );
		}

		if ( '' !== $search ) {
			$where    .= ' AND (subject LIKE %s OR message LIKE %s)';
			$like      = '%' . $wpdb->esc_like( $search ) . '%';
			$params[]  = $like;
			$params[]  = $like;
		}

		$params[] = (int) $limit;
		$params[] = (int) $offset;

		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} {$where} ORDER BY updated_at DESC LIMIT %d OFFSET %d",
			$params
		);

		return $wpdb->get_results( $sql );
	}

	/**
	 * Counts tickets across the network.
	 *
	 * @param string $status Optional status filter.
	 * @param string $search Optional search term.
	 * @return int
	 */
	public static function count_network( $status = '', $search = '' ) {
		global $wpdb;

		$table  = $wpdb->base_prefix . 's0_tickets';
		$where  = 'WHERE 1=1';
		$params = array();

		if ( '' !== $status ) {
			$where    .= ' AND status = %s';
			$params[] = self::sanitize_status( $status );
		}

		if ( '' !== $search ) {
			$where    .= ' AND (subject LIKE %s OR message LIKE %s)';
			$like      = '%' . $wpdb->esc_like( $search ) . '%';
			$params[]  = $like;
			$params[]  = $like;
		}

		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} {$where}",
			$params
		);

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Counts unread-by-admin tickets (waiting for network admin).
	 *
	 * @return int
	 */
	public static function count_unread_by_admin() {
		global $wpdb;

		$table = $wpdb->base_prefix . 's0_tickets';

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE unread_by_admin = %d AND status <> %s",
				1,
				self::STATUS_CLOSED
			)
		);
	}

	/**
	 * Counts unread-by-tenant tickets for a blog.
	 *
	 * @param int $blog_id Blog ID.
	 * @return int
	 */
	public static function count_unread_by_tenant_for_blog( $blog_id ) {
		global $wpdb;

		$table = $wpdb->base_prefix . 's0_tickets';

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE blog_id = %d AND unread_by_tenant = %d",
				(int) $blog_id,
				1
			)
		);
	}

	/**
	 * Updates a ticket's status.
	 *
	 * @param int    $ticket_id Ticket ID.
	 * @param string $status    New status.
	 * @return bool
	 */
	public static function set_status( $ticket_id, $status ) {
		global $wpdb;

		$status = self::sanitize_status( $status );

		$result = $wpdb->update(
			$wpdb->base_prefix . 's0_tickets',
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $ticket_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Marks a ticket unread by the network admin (e.g. tenant replied).
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return bool
	 */
	public static function mark_unread_by_admin( $ticket_id ) {
		return self::flag( $ticket_id, 'unread_by_admin', 1 );
	}

	/**
	 * Marks a ticket read by the network admin (e.g. admin replied).
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return bool
	 */
	public static function mark_read_by_admin( $ticket_id ) {
		return self::flag( $ticket_id, 'unread_by_admin', 0 );
	}

	/**
	 * Marks tickets of a blog as read by the tenant.
	 *
	 * @param int $blog_id Blog ID.
	 * @return bool
	 */
	public static function mark_read_by_tenant( $blog_id ) {
		global $wpdb;

		$result = $wpdb->update(
			$wpdb->base_prefix . 's0_tickets',
			array( 'unread_by_tenant' => 0 ),
			array( 'blog_id' => (int) $blog_id, 'unread_by_tenant' => 1 ),
			array( '%d' ),
			array( '%d', '%d' )
		);

		return false !== $result;
	}

	/**
	 * Marks a ticket unread by the tenant (e.g. admin replied).
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return bool
	 */
	public static function mark_unread_by_tenant( $ticket_id ) {
		return self::flag( $ticket_id, 'unread_by_tenant', 1 );
	}

	/**
	 * Sets a single flag column.
	 *
	 * @param int    $ticket_id Ticket ID.
	 * @param string $column    Column name.
	 * @param int    $value     0 or 1.
	 * @return bool
	 */
	private static function flag( $ticket_id, $column, $value ) {
		global $wpdb;

		$table = $wpdb->base_prefix . 's0_tickets';
		$allow = array( 'unread_by_tenant', 'unread_by_admin' );

		if ( ! in_array( $column, $allow, true ) ) {
			return false;
		}

		$result = $wpdb->update(
			$table,
			array( $column => (int) $value ),
			array( 'id' => (int) $ticket_id ),
			array( '%d' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Deletes a ticket and its replies.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return bool
	 */
	public static function delete( $ticket_id ) {
		global $wpdb;

		$ticket_id = (int) $ticket_id;
		$table     = $wpdb->base_prefix . 's0_tickets';

		Site0_Ticketing_Replies::delete_all_for_ticket( $ticket_id );
		Site0_Ticketing_Attachments::delete_for_ticket( $ticket_id );

		$result = $wpdb->delete( $table, array( 'id' => $ticket_id ), array( '%d' ) );

		return false !== $result;
	}
}
