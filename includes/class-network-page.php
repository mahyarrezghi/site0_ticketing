<?php
/**
 * Network admin UI.
 *
 * @package Site0_Ticketing
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Site0_Ticketing_Tenant_List_Table' ) ) {
	require_once dirname( __FILE__ ) . '/class-tenant-page.php';
}

/**
 * List table showing tickets from every subsite to the network admin.
 */
class Site0_Ticketing_Network_List_Table extends Site0_Ticketing_Tenant_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
	}

	/**
	 * Base admin URL used for ticket links (network admin).
	 *
	 * @return string
	 */
	protected function get_ticket_base_url() {
		return network_admin_url( 'admin.php' );
	}

	/**
	 * Prepares the rows across the network.
	 */
	public function prepare_items() {
		$per_page = 20;

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$this->items = Site0_Ticketing_Tickets::list_network( $per_page, 0, $status, $search );

		$total = Site0_Ticketing_Tickets::count_network( $status, $search );

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
			'cb'      => '<input type="checkbox" />',
			'subject' => __( 'Subject', 'site0-ticketing' ),
			'site'    => __( 'Site', 'site0-ticketing' ),
			'author'  => __( 'Author', 'site0-ticketing' ),
			'status'  => __( 'Status', 'site0-ticketing' ),
			'updated' => __( 'Last Update', 'site0-ticketing' ),
		);
	}

	/**
	 * Bulk actions for status changes.
	 *
	 * @return array
	 */
	public function get_bulk_actions() {
		return array(
			'close'       => __( 'Close', 'site0-ticketing' ),
			'waiting'     => __( 'Mark waiting', 'site0-ticketing' ),
			'in_progress' => __( 'Mark in progress', 'site0-ticketing' ),
			'delete'      => __( 'Delete', 'site0-ticketing' ),
		);
	}

	/**
	 * Checkbox column for bulk actions.
	 *
	 * @param object $item Ticket row.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="ticket[]" value="%d" />',
			(int) $item->id
		);
	}

	/**
	 * Site column.
	 *
	 * @param object $item Ticket row.
	 * @return string
	 */
	public function column_site( $item ) {
		$details = get_blog_details( (int) $item->blog_id );

		if ( ! $details ) {
			return esc_html( '#' . (int) $item->blog_id );
		}

		$name = $details->blogname ? $details->blogname : $details->domain;

		return '<a href="' . esc_url( get_admin_url( $item->blog_id ) ) . '" target="_blank" rel="noopener">' . esc_html( $name ) . '</a>';
	}

	/**
	 * Author column.
	 *
	 * @param object $item Ticket row.
	 * @return string
	 */
	public function column_author( $item ) {
		$user = get_userdata( (int) $item->user_id );

		if ( ! $user ) {
			return esc_html( __( 'Unknown user', 'site0-ticketing' ) );
		}

		return esc_html( $user->display_name . ' (' . $user->user_email . ')' );
	}
}

/**
 * Network admin pages and handlers.
 */
class Site0_Ticketing_Network_Page {

	/**
	 * Singleton.
	 *
	 * @var Site0_Ticketing_Network_Page|null
	 */
	private static $instance = null;

	/**
	 * Returns singleton.
	 *
	 * @return Site0_Ticketing_Network_Page
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
		add_action( 'network_admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_site0_ticketing_network_reply', array( $this, 'handle_reply' ) );
		add_action( 'admin_post_site0_ticketing_network_status', array( $this, 'handle_status' ) );
		add_action( 'admin_post_site0_ticketing_network_close', array( $this, 'handle_close' ) );
		add_action( 'admin_post_site0_ticketing_network_delete', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_site0_ticketing_network_settings', array( $this, 'handle_settings' ) );
		add_action( 'load-toplevel_page_site0-ticketing', array( $this, 'maybe_handle_bulk' ) );
	}

	/**
	 * Registers the network admin menu.
	 */
	public function register_menu() {
		if ( ! Site0_Ticketing_Capabilities::is_network_admin() ) {
			return;
		}

		$count = Site0_Ticketing_Tickets::count_unread_by_admin();
		$title = __( 'Tickets', 'site0-ticketing' );

		if ( $count > 0 ) {
			$title .= ' <span class="awaiting-mod st-unread-count">' . number_format_i18n( $count ) . '</span>';
		}

		add_menu_page(
			__( 'Tickets', 'site0-ticketing' ),
			$title,
			'manage_network',
			'site0-ticketing',
			array( $this, 'render_page' ),
			'dashicons-email',
			26
		);

		add_submenu_page(
			'site0-ticketing',
			__( 'Tickets', 'site0-ticketing' ),
			__( 'All Tickets', 'site0-ticketing' ),
			'manage_network',
			'site0-ticketing',
			array( $this, 'render_page' )
		);

		add_submenu_page(
			'site0-ticketing',
			__( 'Ticket Settings', 'site0-ticketing' ),
			__( 'Settings', 'site0-ticketing' ),
			'manage_network',
			'site0-ticketing-settings',
			array( $this, 'render_settings' )
		);
	}

	/**
	 * Renders the network page.
	 */
	public function render_page() {
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( 'view' === $action ) {
			$this->render_detail();
			return;
		}

		$this->render_list();
	}

	/**
	 * Renders the network list.
	 */
	private function render_list() {
		$table = new Site0_Ticketing_Network_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap site0-ticketing">
			<h1><?php esc_html_e( 'Tickets', 'site0-ticketing' ); ?></h1>

			<form method="get">
				<input type="hidden" name="page" value="site0-ticketing" />
				<?php $table->search_box( __( 'Search tickets by subject, message or number', 'site0-ticketing' ), 'tickets' ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( network_admin_url( 'admin.php?page=site0-ticketing' ) ); ?>">
				<?php wp_nonce_field( 'site0_ticketing_network_bulk', 'site0_ticketing_nonce' ); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the network settings page.
	 */
	public function render_settings() {
		?>
		<div class="wrap site0-ticketing">
			<h1><?php esc_html_e( 'Ticket Settings', 'site0-ticketing' ); ?></h1>

			<?php if ( isset( $_GET['st-settings'] ) && 'saved' === $_GET['st-settings'] ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'site0-ticketing' ); ?></p></div>
			<?php endif; ?>

			<div class="st-settings">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'site0_ticketing_network_settings', 'site0_ticketing_nonce' ); ?>
					<input type="hidden" name="action" value="site0_ticketing_network_settings" />
					<label>
						<input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( 1, (int) get_site_option( 'site0_ticketing_delete_on_uninstall', 0 ) ); ?> />
						<?php esc_html_e( 'Delete all ticket data when the plugin is uninstalled', 'site0-ticketing' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'If unchecked, tickets and replies are kept in the database after uninstall. Uninstalling never affects other sites.', 'site0-ticketing' ); ?>
					</p>
					<hr />
					<label>
						<input type="checkbox" name="auto_close_enabled" value="1" <?php checked( 1, (int) Site0_Ticketing_Auto_Close::is_enabled() ); ?> />
						<?php esc_html_e( 'Automatically close answered tickets', 'site0-ticketing' ); ?>
					</label>
					<p>
						<label for="site0_ticketing_auto_close_days">
							<?php esc_html_e( 'Close after this many days since the last admin reply:', 'site0-ticketing' ); ?>
						</label>
						<input
							type="number"
							id="site0_ticketing_auto_close_days"
							name="auto_close_days"
							min="1"
							step="1"
							value="<?php echo esc_attr( Site0_Ticketing_Auto_Close::get_days() ); ?>"
						/>
					</p>
					<p class="description">
						<?php esc_html_e( 'Only tickets in the "Answered" status that have received at least one admin reply are closed. Tickets awaiting an admin response are never closed automatically.', 'site0-ticketing' ); ?>
					</p>
					<?php submit_button( __( 'Save Settings', 'site0-ticketing' ), 'secondary', 'submit-settings' ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the network ticket detail with reply form.
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

		$author   = get_userdata( (int) $ticket->user_id );
		$site     = get_blog_details( (int) $ticket->blog_id );
		$site_url = $site ? get_admin_url( $ticket->blog_id ) : '';

		$thread = array_merge(
			array(
				(object) array(
					'author_name' => $author ? $author->display_name : __( 'Unknown user', 'site0-ticketing' ),
					'author_type' => Site0_Ticketing_Replies::AUTHOR_TENANT,
					'message'     => $ticket->message,
					'created_at'  => $ticket->created_at,
				),
			),
			$replies
		);
		?>
		<div class="wrap site0-ticketing">
			<a href="<?php echo esc_url( network_admin_url( 'admin.php?page=site0-ticketing' ) ); ?>">&larr; <?php esc_html_e( 'Back to tickets', 'site0-ticketing' ); ?></a>

			<h1><?php echo esc_html( $ticket->subject ); ?></h1>

			<p>
				<?php $ticket_number = Site0_Ticketing_Tickets::format_number( $ticket ); ?>
				<?php if ( '' !== $ticket_number ) : ?>
					<span class="st-ticket-number"><?php echo esc_html( $ticket_number ); ?></span>
				<?php endif; ?>
				<span class="st-badge st-badge--<?php echo esc_attr( $ticket->status ); ?>"><?php echo esc_html( $status ); ?></span>
				<span class="st-meta"><?php esc_html_e( 'Opened on', 'site0-ticketing' ); ?> <?php echo esc_html( mysql2date( get_option( 'date_format' ), $ticket->created_at ) ); ?></span>
			</p>

			<table class="widefat st-site-box">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Site', 'site0-ticketing' ); ?></th>
						<td>
							<?php if ( $site_url ) : ?>
								<a href="<?php echo esc_url( $site_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $site ? $site->blogname : '' ); ?></a>
							<?php else : ?>
								<?php echo esc_html( '#' . (int) $ticket->blog_id ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Author', 'site0-ticketing' ); ?></th>
						<td>
							<?php if ( $author ) : ?>
								<?php echo esc_html( $author->display_name ); ?> &lt;<?php echo esc_html( $author->user_email ); ?>&gt;
							<?php else : ?>
								<?php esc_html_e( 'Unknown user', 'site0-ticketing' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

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
					<h2><?php esc_html_e( 'Reply to this ticket', 'site0-ticketing' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
						<?php wp_nonce_field( 'site0_ticketing_network_reply', 'site0_ticketing_nonce' ); ?>
						<input type="hidden" name="action" value="site0_ticketing_network_reply" />
						<input type="hidden" name="ticket_id" value="<?php echo (int) $ticket->id; ?>" />
						<textarea class="large-text" name="message" rows="6" required></textarea>
						<label class="st-in-progress-toggle">
							<input type="checkbox" name="in_progress" value="1" />
							<?php esc_html_e( 'Keep this ticket in progress (answered but not resolved)', 'site0-ticketing' ); ?>
						</label>
						<?php Site0_Ticketing_Tenant_Page::render_attachment_field( false ); ?>
						<?php submit_button( __( 'Reply', 'site0-ticketing' ), 'primary', 'submit-reply' ); ?>
					</form>

					<div class="st-actions">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'site0_ticketing_network_status', 'site0_ticketing_nonce' ); ?>
							<input type="hidden" name="action" value="site0_ticketing_network_status" />
							<input type="hidden" name="ticket_id" value="<?php echo (int) $ticket->id; ?>" />
							<?php if ( Site0_Ticketing_Tickets::STATUS_IN_PROGRESS === $ticket->status ) : ?>
								<input type="hidden" name="status" value="<?php echo esc_attr( Site0_Ticketing_Tickets::STATUS_ANSWERED ); ?>" />
								<?php submit_button( __( 'Mark Answered', 'site0-ticketing' ), 'secondary', 'submit-status' ); ?>
							<?php else : ?>
								<input type="hidden" name="status" value="<?php echo esc_attr( Site0_Ticketing_Tickets::STATUS_IN_PROGRESS ); ?>" />
								<?php submit_button( __( 'Mark In Progress', 'site0-ticketing' ), 'secondary', 'submit-status' ); ?>
							<?php endif; ?>
						</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'site0_ticketing_network_close', 'site0_ticketing_nonce' ); ?>
							<input type="hidden" name="action" value="site0_ticketing_network_close" />
							<input type="hidden" name="ticket_id" value="<?php echo (int) $ticket->id; ?>" />
							<?php submit_button( __( 'Close Ticket', 'site0-ticketing' ), 'secondary', 'submit-close' ); ?>
						</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this ticket permanently?', 'site0-ticketing' ) ); ?>');">
							<?php wp_nonce_field( 'site0_ticketing_network_delete', 'site0_ticketing_nonce' ); ?>
							<input type="hidden" name="action" value="site0_ticketing_network_delete" />
							<input type="hidden" name="ticket_id" value="<?php echo (int) $ticket->id; ?>" />
							<?php submit_button( __( 'Delete', 'site0-ticketing' ), 'delete', 'submit-delete' ); ?>
						</form>
					</div>
				</div>
			<?php else : ?>
				<div class="st-actions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'site0_ticketing_network_close', 'site0_ticketing_nonce' ); ?>
						<input type="hidden" name="action" value="site0_ticketing_network_close" />
						<input type="hidden" name="ticket_id" value="<?php echo (int) $ticket->id; ?>" />
						<input type="hidden" name="reopen" value="1" />
						<?php submit_button( __( 'Reopen Ticket', 'site0-ticketing' ), 'secondary', 'submit-close' ); ?>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this ticket permanently?', 'site0-ticketing' ) ); ?>');">
						<?php wp_nonce_field( 'site0_ticketing_network_delete', 'site0_ticketing_nonce' ); ?>
						<input type="hidden" name="action" value="site0_ticketing_network_delete" />
						<input type="hidden" name="ticket_id" value="<?php echo (int) $ticket->id; ?>" />
						<?php submit_button( __( 'Delete', 'site0-ticketing' ), 'delete', 'submit-delete' ); ?>
					</form>
				</div>
			<?php endif; ?>

			<a class="button" href="<?php echo esc_url( network_admin_url( 'admin.php?page=site0-ticketing' ) ); ?>"><?php esc_html_e( 'Back', 'site0-ticketing' ); ?></a>
		</div>
		<?php
	}

	/**
	 * Handles network admin replies.
	 */
	public function handle_reply() {
		$nonce = isset( $_POST['site0_ticketing_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['site0_ticketing_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'site0_ticketing_network_reply' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'site0-ticketing' ) );
		}

		if ( ! Site0_Ticketing_Capabilities::is_network_admin() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'site0-ticketing' ) );
		}

		$ticket_id = isset( $_POST['ticket_id'] ) ? (int) $_POST['ticket_id'] : 0;
		$ticket    = Site0_Ticketing_Tickets::get( $ticket_id );

		if ( ! $ticket ) {
			wp_die( esc_html__( 'Ticket not found.', 'site0-ticketing' ) );
		}

		if ( Site0_Ticketing_Tickets::STATUS_CLOSED === $ticket->status ) {
			wp_die( esc_html__( 'This ticket is closed.', 'site0-ticketing' ) );
		}

		$message = isset( $_POST['message'] ) ? wp_kses_post( wp_unslash( $_POST['message'] ) ) : '';

		$attachments = Site0_Ticketing_Attachments::prepare( isset( $_FILES['attachments'] ) ? (array) $_FILES['attachments'] : array() );

		if ( ! empty( $attachments['errors'] ) ) {
			wp_die( '<p>' . implode( '</p><p>', array_map( 'wp_kses_post', $attachments['errors'] ) ) . '</p>' );
		}

		$reply_id = Site0_Ticketing_Replies::add(
			array(
				'ticket_id'   => $ticket_id,
				'user_id'     => get_current_user_id(),
				'author_name' => wp_get_current_user()->display_name,
				'author_type' => Site0_Ticketing_Replies::AUTHOR_ADMIN,
				'message'     => $message,
			)
		);

		if ( is_wp_error( $reply_id ) ) {
			wp_die( esc_html( $reply_id->get_error_message() ) );
		}

		Site0_Ticketing_Attachments::store( $attachments['files'], $ticket_id, (int) $reply_id );

		// Admin reply clears the admin unread flag so the menu bubble drops and
		// flags it unread for the tenant. The reply sets the ticket to "answered"
		// unless the admin chose to keep it "in progress".
		$new_status = isset( $_POST['in_progress'] )
			? Site0_Ticketing_Tickets::STATUS_IN_PROGRESS
			: Site0_Ticketing_Tickets::STATUS_ANSWERED;

		Site0_Ticketing_Tickets::set_status( $ticket_id, $new_status );
		Site0_Ticketing_Tickets::mark_unread_by_tenant( $ticket_id );
		Site0_Ticketing_Tickets::mark_read_by_admin( $ticket_id );

		$this->redirect_to_ticket( $ticket_id );
	}

	/**
	 * Handles standalone status changes (answered / in progress) from the network side.
	 */
	public function handle_status() {
		$nonce = isset( $_POST['site0_ticketing_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['site0_ticketing_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'site0_ticketing_network_status' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'site0-ticketing' ) );
		}

		if ( ! Site0_Ticketing_Capabilities::is_network_admin() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'site0-ticketing' ) );
		}

		$ticket_id = isset( $_POST['ticket_id'] ) ? (int) $_POST['ticket_id'] : 0;
		$ticket    = Site0_Ticketing_Tickets::get( $ticket_id );

		if ( ! $ticket ) {
			wp_die( esc_html__( 'Ticket not found.', 'site0-ticketing' ) );
		}

		$status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';

		if ( ! in_array( $status, array( Site0_Ticketing_Tickets::STATUS_ANSWERED, Site0_Ticketing_Tickets::STATUS_IN_PROGRESS ), true ) ) {
			wp_die( esc_html__( 'Invalid status.', 'site0-ticketing' ) );
		}

		Site0_Ticketing_Tickets::set_status( $ticket_id, $status );

		$this->redirect_to_ticket( $ticket_id );
	}

	/**
	 * Handles close / reopen from the network side.
	 */
	public function handle_close() {
		$nonce = isset( $_POST['site0_ticketing_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['site0_ticketing_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'site0_ticketing_network_close' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'site0-ticketing' ) );
		}

		if ( ! Site0_Ticketing_Capabilities::is_network_admin() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'site0-ticketing' ) );
		}

		$ticket_id = isset( $_POST['ticket_id'] ) ? (int) $_POST['ticket_id'] : 0;
		$ticket    = Site0_Ticketing_Tickets::get( $ticket_id );

		if ( ! $ticket ) {
			wp_die( esc_html__( 'Ticket not found.', 'site0-ticketing' ) );
		}

		$reopen = isset( $_POST['reopen'] ) ? 1 : 0;

		$new_status = $reopen ? Site0_Ticketing_Tickets::STATUS_WAITING : Site0_Ticketing_Tickets::STATUS_CLOSED;

		Site0_Ticketing_Tickets::set_status( $ticket_id, $new_status );

		if ( $reopen ) {
			Site0_Ticketing_Tickets::mark_unread_by_admin( $ticket_id );
			Site0_Ticketing_Tickets::mark_unread_by_tenant( $ticket_id );
		}

		$this->redirect_to_ticket( $ticket_id );
	}

	/**
	 * Handles deletion of a single ticket.
	 */
	public function handle_delete() {
		$nonce = isset( $_POST['site0_ticketing_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['site0_ticketing_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'site0_ticketing_network_delete' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'site0-ticketing' ) );
		}

		if ( ! Site0_Ticketing_Capabilities::is_network_admin() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'site0-ticketing' ) );
		}

		$ticket_id = isset( $_POST['ticket_id'] ) ? (int) $_POST['ticket_id'] : 0;

		Site0_Ticketing_Tickets::delete( $ticket_id );

		wp_safe_redirect( network_admin_url( 'admin.php?page=site0-ticketing' ) );
		exit;
	}

	/**
	 * Handles saving network settings (delete-on-uninstall toggle).
	 */
	public function handle_settings() {
		$nonce = isset( $_POST['site0_ticketing_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['site0_ticketing_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'site0_ticketing_network_settings' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'site0-ticketing' ) );
		}

		if ( ! Site0_Ticketing_Capabilities::is_network_admin() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'site0-ticketing' ) );
		}

		$delete_on_uninstall = isset( $_POST['delete_on_uninstall'] ) ? 1 : 0;

		update_site_option( 'site0_ticketing_delete_on_uninstall', $delete_on_uninstall );

		$auto_close_enabled = isset( $_POST['auto_close_enabled'] ) ? 1 : 0;
		$auto_close_days    = isset( $_POST['auto_close_days'] ) ? max( 1, (int) $_POST['auto_close_days'] ) : Site0_Ticketing_Auto_Close::DEFAULT_DAYS;

		update_site_option( Site0_Ticketing_Auto_Close::OPTION_ENABLED, $auto_close_enabled );
		update_site_option( Site0_Ticketing_Auto_Close::OPTION_DAYS, $auto_close_days );

		if ( $auto_close_enabled ) {
			Site0_Ticketing_Auto_Close::instance()->ensure_scheduled();
		} else {
			Site0_Ticketing_Auto_Close::unschedule();
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'site0-ticketing-settings',
					'st-settings' => 'saved',
				),
				network_admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handles bulk actions from the network list on page load.
	 */
	public function maybe_handle_bulk() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( $_SERVER['REQUEST_METHOD'] ) : 'GET' ) ) {
			return;
		}

		$nonce = isset( $_POST['site0_ticketing_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['site0_ticketing_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'site0_ticketing_network_bulk' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'site0-ticketing' ) );
		}

		if ( ! Site0_Ticketing_Capabilities::is_network_admin() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'site0-ticketing' ) );
		}

		$action  = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		$action2 = isset( $_POST['action2'] ) ? sanitize_key( wp_unslash( $_POST['action2'] ) ) : '';

		$bulk_action = ( '-1' === $action || '' === $action ) ? $action2 : $action;

		if ( ! in_array( $bulk_action, array( 'close', 'waiting', 'in_progress', 'delete' ), true ) ) {
			return;
		}

		$ids = isset( $_POST['ticket'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['ticket'] ) ) : array();

		if ( empty( $ids ) ) {
			return;
		}

		foreach ( $ids as $ticket_id ) {
			switch ( $bulk_action ) {
				case 'close':
					Site0_Ticketing_Tickets::set_status( $ticket_id, Site0_Ticketing_Tickets::STATUS_CLOSED );
					break;
				case 'waiting':
					Site0_Ticketing_Tickets::set_status( $ticket_id, Site0_Ticketing_Tickets::STATUS_WAITING );
					Site0_Ticketing_Tickets::mark_unread_by_admin( $ticket_id );
					break;
				case 'in_progress':
					Site0_Ticketing_Tickets::set_status( $ticket_id, Site0_Ticketing_Tickets::STATUS_IN_PROGRESS );
					Site0_Ticketing_Tickets::mark_read_by_admin( $ticket_id );
					break;
				case 'delete':
					Site0_Ticketing_Tickets::delete( $ticket_id );
					break;
			}
		}

		wp_safe_redirect( network_admin_url( 'admin.php?page=site0-ticketing' ) );
		exit;
	}

	/**
	 * Redirects to the network ticket detail.
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
			network_admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}
}
