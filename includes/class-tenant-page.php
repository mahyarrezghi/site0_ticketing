<?php
/**
 * Tenant-facing dashboard UI.
 *
 * @package Site0_Ticketing
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * List table that shows a subsite's tickets to its tenant.
 */
class Site0_Ticketing_Tenant_List_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'ticket',
				'plural'   => 'tickets',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Text shown when there are no tickets.
	 */
	public function no_items() {
		esc_html_e( 'No tickets yet.', 'site0-ticketing' );
	}

	/**
	 * Prepares the rows.
	 */
	public function prepare_items() {
		$per_page = 15;

		$columns = $this->get_columns();
		$hidden  = array();
		$sortable = $this->get_sortable_columns();

		$this->_column_headers = array( $columns, $hidden, $sortable );

		$blog_id = get_current_blog_id();
		$status  = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$this->items = Site0_Ticketing_Tickets::list_tickets( $blog_id, $per_page, 0, $status, $search );

		$total = Site0_Ticketing_Tickets::count_tickets( $blog_id, $status, $search );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total / $per_page ),
			)
		);
	}

	/**
	 * Column definitions.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'subject' => __( 'Subject', 'site0-ticketing' ),
			'status'  => __( 'Status', 'site0-ticketing' ),
			'updated' => __( 'Last Update', 'site0-ticketing' ),
		);
	}

	/**
	 * Base admin URL used for ticket links.
	 *
	 * Subclasses may override this to point at a different admin (e.g. network admin).
	 *
	 * @return string
	 */
	protected function get_ticket_base_url() {
		return admin_url( 'admin.php' );
	}

	/**
	 * Subject column, linked to the detail view with an unread dot.
	 *
	 * @param object $item Ticket row.
	 * @return string
	 */
	public function column_subject( $item ) {
		$url = add_query_arg(
			array(
				'page'     => 'site0-ticketing',
				'ticket'   => (int) $item->id,
				'action'   => 'view',
			),
			$this->get_ticket_base_url()
		);

		$dot = ( '1' === (string) $item->unread_by_tenant )
			? '<span class="st-unread-dot" aria-label="' . esc_attr__( 'Unread', 'site0-ticketing' ) . '"></span> '
			: '';

		$number = Site0_Ticketing_Tickets::format_number( $item );
		$prefix = '' !== $number
			? '<span class="st-ticket-number">' . esc_html( $number ) . '</span> '
			: '';

		return '<a class="st-ticket-link" href="' . esc_url( $url ) . '">' . $dot . $prefix . esc_html( $item->subject ) . '</a>';
	}

	/**
	 * Status column with colored badge.
	 *
	 * @param object $item Ticket row.
	 * @return string
	 */
	public function column_status( $item ) {
		$statuses = Site0_Ticketing_Tickets::get_statuses();
		$status   = isset( $statuses[ $item->status ] ) ? $statuses[ $item->status ] : $item->status;

		return '<span class="st-badge st-badge--' . esc_attr( $item->status ) . '">' . esc_html( $status ) . '</span>';
	}

	/**
	 * Updated column.
	 *
	 * @param object $item Ticket row.
	 * @return string
	 */
	public function column_updated( $item ) {
		$updated = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item->updated_at );

		return esc_html( $updated );
	}
}

/**
 * Tenant-facing admin pages.
 */
class Site0_Ticketing_Tenant_Page {

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_site0_ticketing_create', array( $this, 'handle_create' ) );
		add_action( 'admin_post_site0_ticketing_reply', array( $this, 'handle_reply' ) );
		add_action( 'admin_post_site0_ticketing_close', array( $this, 'handle_close' ) );
	}

	/**
	 * Registers the Tickets menu for a subsite.
	 */
	public function register_menu() {
		if ( ! Site0_Ticketing_Capabilities::can_use_tenant_tickets() ) {
			return;
		}

		$count = Site0_Ticketing_Tickets::count_unread_by_tenant_for_blog( get_current_blog_id() );
		$title = __( 'Tickets', 'site0-ticketing' );

		if ( $count > 0 ) {
			$title .= ' <span class="awaiting-mod st-unread-count">' . number_format_i18n( $count ) . '</span>';
		}

		add_menu_page(
			__( 'Tickets', 'site0-ticketing' ),
			$title,
			'read',
			'site0-ticketing',
			array( $this, 'render_page' ),
			'dashicons-email',
			25
		);
	}

	/**
	 * Renders the tenant page (list or detail).
	 */
	public function render_page() {
		// Hard guard: the page is registered with 'read', so block
		// lower-privileged users from hitting the URL directly.
		if ( ! Site0_Ticketing_Capabilities::can_use_tenant_tickets() ) {
			wp_die( esc_html__( 'You are not allowed to access tickets.', 'site0-ticketing' ) );
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( 'view' === $action ) {
			$this->render_detail();
		} else {
			$this->render_list();
		}

		// Clear unread flags only after the page body and unread dots have
		// rendered for this request.
		Site0_Ticketing_Tickets::mark_read_by_tenant( get_current_blog_id() );
	}

	/**
	 * Renders the ticket list + new ticket form.
	 */
	private function render_list() {
		$table = new Site0_Ticketing_Tenant_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap site0-ticketing">
			<h1><?php esc_html_e( 'Tickets', 'site0-ticketing' ); ?></h1>

			<div class="st-new-ticket">
				<h2><?php esc_html_e( 'Open a new ticket', 'site0-ticketing' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<?php wp_nonce_field( 'site0_ticketing_create', 'site0_ticketing_nonce' ); ?>
					<input type="hidden" name="action" value="site0_ticketing_create" />
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="st-subject"><?php esc_html_e( 'Subject', 'site0-ticketing' ); ?></label></th>
							<td><input type="text" class="regular-text" id="st-subject" name="subject" maxlength="255" required /></td>
						</tr>
						<tr>
							<th scope="row"><label for="st-message"><?php esc_html_e( 'Issue', 'site0-ticketing' ); ?></label></th>
							<td><textarea class="large-text" id="st-message" name="message" rows="6" required></textarea></td>
						</tr>
						<?php Site0_Ticketing_Tenant_Page::render_attachment_field(); ?>
					</table>
					<?php submit_button( __( 'Submit Ticket', 'site0-ticketing' ) ); ?>
				</form>
			</div>

			<hr />

			<h2><?php esc_html_e( 'Your tickets', 'site0-ticketing' ); ?></h2>
			<form method="get">
				<input type="hidden" name="page" value="site0-ticketing" />
				<?php $table->search_box( __( 'Search tickets by subject, message or number', 'site0-ticketing' ), 'tickets' ); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders a ticket detail with the reply thread.
	 */
	private function render_detail() {
		$ticket_id = isset( $_GET['ticket'] ) ? (int) $_GET['ticket'] : 0;
		$ticket    = Site0_Ticketing_Tickets::get( $ticket_id );

		if ( ! $ticket || ! Site0_Ticketing_Capabilities::can_view_ticket( $ticket ) ) {
			wp_die( esc_html__( 'Ticket not found or you do not have access.', 'site0-ticketing' ) );
		}

		$replies = Site0_Ticketing_Replies::list_for_ticket( $ticket_id );
		$attachment_map = Site0_Ticketing_Attachments::map_for_ticket( $ticket_id );
		$statuses = Site0_Ticketing_Tickets::get_statuses();
		$status   = isset( $statuses[ $ticket->status ] ) ? $statuses[ $ticket->status ] : $ticket->status;

		$thread = array_merge(
			array(
				(object) array(
					'author_name' => wp_get_current_user()->display_name,
					'author_type' => Site0_Ticketing_Replies::AUTHOR_TENANT,
					'message'     => $ticket->message,
					'created_at'  => $ticket->created_at,
				),
			),
			$replies
		);
		?>
		<div class="wrap site0-ticketing">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=site0-ticketing' ) ); ?>">&larr; <?php esc_html_e( 'Back to tickets', 'site0-ticketing' ); ?></a>

			<h1><?php echo esc_html( $ticket->subject ); ?></h1>

			<p>
				<?php $ticket_number = Site0_Ticketing_Tickets::format_number( $ticket ); ?>
				<?php if ( '' !== $ticket_number ) : ?>
					<span class="st-ticket-number"><?php echo esc_html( $ticket_number ); ?></span>
				<?php endif; ?>
				<span class="st-badge st-badge--<?php echo esc_attr( $ticket->status ); ?>"><?php echo esc_html( $status ); ?></span>
				<span class="st-meta"><?php esc_html_e( 'Opened on', 'site0-ticketing' ); ?> <?php echo esc_html( mysql2date( get_option( 'date_format' ), $ticket->created_at ) ); ?></span>
			</p>

			<div class="st-thread">
				<?php
				foreach ( $thread as $entry ) {
					$class = ( Site0_Ticketing_Replies::AUTHOR_ADMIN === $entry->author_type ) ? 'st-admin' : 'st-tenant';
					?>
					<div class="st-message <?php echo esc_attr( $class ); ?>">
						<div class="st-message__head">
							<strong><?php echo esc_html( $entry->author_name ); ?></strong>
							<span class="st-meta"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry->created_at ) ); ?></span>
						</div>
						<div class="st-message__body"><?php echo wp_kses_post( wpautop( $entry->message ) ); ?></div>
						<?php Site0_Ticketing_Attachments::render_for_entry( $attachment_map, isset( $entry->id ) ? $entry->id : 0 ); ?>
					</div>
					<?php
				}
				?>
			</div>

			<?php if ( Site0_Ticketing_Tickets::STATUS_CLOSED !== $ticket->status ) : ?>
				<div class="st-reply">
					<h2><?php esc_html_e( 'Add a reply', 'site0-ticketing' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
						<?php wp_nonce_field( 'site0_ticketing_reply', 'site0_ticketing_nonce' ); ?>
						<input type="hidden" name="action" value="site0_ticketing_reply" />
						<input type="hidden" name="ticket_id" value="<?php echo (int) $ticket->id; ?>" />
						<textarea class="large-text" name="message" rows="5" required></textarea>
						<?php if ( Site0_Ticketing_Capabilities::is_network_admin() ) : ?>
							<label class="st-in-progress-toggle">
								<input type="checkbox" name="in_progress" value="1" />
								<?php esc_html_e( 'Keep this ticket in progress (answered but not resolved)', 'site0-ticketing' ); ?>
							</label>
						<?php endif; ?>
						<?php self::render_attachment_field( false ); ?>
						<?php submit_button( __( 'Submit Reply', 'site0-ticketing' ) ); ?>
					</form>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="st-close-form">
						<?php wp_nonce_field( 'site0_ticketing_close', 'site0_ticketing_nonce' ); ?>
						<input type="hidden" name="action" value="site0_ticketing_close" />
						<input type="hidden" name="ticket_id" value="<?php echo (int) $ticket->id; ?>" />
						<?php submit_button( __( 'Close Ticket', 'site0-ticketing' ), 'secondary', 'submit-close' ); ?>
					</form>
				</div>
			<?php else : ?>
				<p class="st-closed-note"><?php esc_html_e( 'This ticket is closed.', 'site0-ticketing' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders the shared attachment file input row.
	 *
	 * @param bool $in_table True to render as a form-table row, false for a standalone block.
	 */
	public static function render_attachment_field( $in_table = true ) {
		if ( $in_table ) {
			?>
			<tr class="st-attachment-field">
				<th scope="row"><label for="st-attachments"><?php esc_html_e( 'Attachments', 'site0-ticketing' ); ?></label></th>
				<td>
					<?php self::render_attachment_input(); ?>
				</td>
			</tr>
			<?php
		} else {
			?>
			<div class="st-attachment-field">
				<label for="st-attachments"><?php esc_html_e( 'Attachments', 'site0-ticketing' ); ?></label>
				<?php self::render_attachment_input(); ?>
			</div>
			<?php
		}
	}

	/**
	 * Renders the attachment file input and its description.
	 */
	private static function render_attachment_input() {
		?>
		<input type="file" id="st-attachments" name="attachments[]" multiple
			accept="image/jpeg,image/png,image/gif,image/webp,.zip,.rar" />
		<p class="description">
			<?php
			printf(
				/* translators: 1: allowed file types, 2: maximum size, 3: maximum file count. */
				esc_html__( 'Optional. %1$s only, up to %2$s each, %3$d files max.', 'site0-ticketing' ),
				esc_html__( 'Images, ZIP or RAR', 'site0-ticketing' ),
				esc_html( size_format( Site0_Ticketing_Attachments::max_size() ) ),
				(int) Site0_Ticketing_Attachments::max_count()
			);
			?>
		</p>
		<?php
	}

	/**
	 * Handles ticket creation.
	 */
	public function handle_create() {
		$nonce = isset( $_POST['site0_ticketing_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['site0_ticketing_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'site0_ticketing_create' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'site0-ticketing' ) );
		}

		if ( ! Site0_Ticketing_Capabilities::can_use_tenant_tickets() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'site0-ticketing' ) );
		}

		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$message = isset( $_POST['message'] ) ? wp_kses_post( wp_unslash( $_POST['message'] ) ) : '';

		$attachments = Site0_Ticketing_Attachments::prepare( isset( $_FILES['attachments'] ) ? (array) $_FILES['attachments'] : array() );

		if ( ! empty( $attachments['errors'] ) ) {
			wp_die( '<p>' . implode( '</p><p>', array_map( 'wp_kses_post', $attachments['errors'] ) ) . '</p>' );
		}

		$result = Site0_Ticketing_Tickets::create(
			array(
				'blog_id' => get_current_blog_id(),
				'user_id' => get_current_user_id(),
				'subject' => $subject,
				'message' => $message,
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		$ticket_id = (int) $result;

		Site0_Ticketing_Attachments::store( $attachments['files'], $ticket_id, 0 );

		$this->redirect_to_ticket( $ticket_id );
	}

	/**
	 * Handles a tenant-side reply.
	 *
	 * A network admin replying from this screen is an official answer:
	 * the reply is stored as admin-authored, the ticket moves to "answered"
	 * and is flagged unread for the tenant. Anyone else replying moves the
	 * ticket back to "waiting" for the network admin.
	 */
	public function handle_reply() {
		$nonce = isset( $_POST['site0_ticketing_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['site0_ticketing_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'site0_ticketing_reply' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'site0-ticketing' ) );
		}

		$ticket_id = isset( $_POST['ticket_id'] ) ? (int) $_POST['ticket_id'] : 0;
		$ticket    = Site0_Ticketing_Tickets::get( $ticket_id );

		if ( ! $ticket || ! Site0_Ticketing_Capabilities::can_view_ticket( $ticket ) ) {
			wp_die( esc_html__( 'Ticket not found or you do not have access.', 'site0-ticketing' ) );
		}

		if ( Site0_Ticketing_Tickets::STATUS_CLOSED === $ticket->status ) {
			wp_die( esc_html__( 'This ticket is closed.', 'site0-ticketing' ) );
		}

		$message = isset( $_POST['message'] ) ? wp_kses_post( wp_unslash( $_POST['message'] ) ) : '';

		$attachments = Site0_Ticketing_Attachments::prepare( isset( $_FILES['attachments'] ) ? (array) $_FILES['attachments'] : array() );

		if ( ! empty( $attachments['errors'] ) ) {
			wp_die( '<p>' . implode( '</p><p>', array_map( 'wp_kses_post', $attachments['errors'] ) ) . '</p>' );
		}

		$is_admin_reply = Site0_Ticketing_Capabilities::is_network_admin();

		$reply_id = Site0_Ticketing_Replies::add(
			array(
				'ticket_id'   => $ticket_id,
				'user_id'     => get_current_user_id(),
				'author_name' => wp_get_current_user()->display_name,
				'author_type' => $is_admin_reply ? Site0_Ticketing_Replies::AUTHOR_ADMIN : Site0_Ticketing_Replies::AUTHOR_TENANT,
				'message'     => $message,
			)
		);

		if ( is_wp_error( $reply_id ) ) {
			wp_die( esc_html( $reply_id->get_error_message() ) );
		}

		Site0_Ticketing_Attachments::store( $attachments['files'], $ticket_id, (int) $reply_id );

		if ( $is_admin_reply ) {
			// Admin reply answers the ticket and notifies the tenant, unless the
			// admin chose to keep it in progress.
			$new_status = isset( $_POST['in_progress'] )
				? Site0_Ticketing_Tickets::STATUS_IN_PROGRESS
				: Site0_Ticketing_Tickets::STATUS_ANSWERED;

			Site0_Ticketing_Tickets::set_status( $ticket_id, $new_status );
			Site0_Ticketing_Tickets::mark_unread_by_tenant( $ticket_id );
		} else {
			// Tenant reply moves the ticket back to "waiting" for the admin.
			Site0_Ticketing_Tickets::set_status( $ticket_id, Site0_Ticketing_Tickets::STATUS_WAITING );
			Site0_Ticketing_Tickets::mark_unread_by_admin( $ticket_id );
		}

		$this->redirect_to_ticket( $ticket_id );
	}

	/**
	 * Handles closing a ticket from the tenant side.
	 */
	public function handle_close() {
		$nonce = isset( $_POST['site0_ticketing_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['site0_ticketing_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'site0_ticketing_close' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'site0-ticketing' ) );
		}

		$ticket_id = isset( $_POST['ticket_id'] ) ? (int) $_POST['ticket_id'] : 0;
		$ticket    = Site0_Ticketing_Tickets::get( $ticket_id );

		if ( ! $ticket || ! Site0_Ticketing_Capabilities::can_view_ticket( $ticket ) ) {
			wp_die( esc_html__( 'Ticket not found or you do not have access.', 'site0-ticketing' ) );
		}

		Site0_Ticketing_Tickets::set_status( $ticket_id, Site0_Ticketing_Tickets::STATUS_CLOSED );

		$this->redirect_to_ticket( $ticket_id );
	}

	/**
	 * Redirects to the ticket detail view.
	 *
	 * @param int $ticket_id Ticket ID.
	 */
	private function redirect_to_ticket( $ticket_id ) {
		$url = add_query_arg(
			array(
				'page'   => 'site0-ticketing',
				'action' => 'view',
				'ticket' => (int) $ticket_id,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}
}
