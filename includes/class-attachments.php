<?php
/**
 * Attachment storage and serving.
 *
 * Files are kept in a private, network-level folder under the main site's
 * uploads directory with randomized names. They are only reachable through
 * a capability-checked download endpoint; tenants and network admins can
 * attach images and zip/rar archives to ticket messages.
 *
 * @package Site0_Ticketing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles attachment validation, storage, listing and serving.
 */
class Site0_Ticketing_Attachments {

	/**
	 * Allowed extensions mapped to their expected MIME types.
	 *
	 * @var array
	 */
	const ALLOWED_MIMES = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'jpe'  => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
		'zip'  => 'application/zip',
		'rar'  => 'application/vnd.rar',
	);

	/**
	 * Real MIME types finfo may report for valid zip/rar payloads.
	 *
	 * @var array
	 */
	const ARCHIVE_SNIFF_MIMES = array(
		'application/zip',
		'application/x-zip-compressed',
		'application/x-zip',
		'application/vnd.rar',
		'application/x-rar-compressed',
		'application/x-rar',
		'application/octet-stream',
	);

	/**
	 * Singleton.
	 *
	 * @var Site0_Ticketing_Attachments|null
	 */
	private static $instance = null;

	/**
	 * Returns singleton.
	 *
	 * @return Site0_Ticketing_Attachments
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
		add_action( 'admin_post_site0_ticketing_download', array( $this, 'handle_download' ) );
	}

	/**
	 * Maximum upload size per file in bytes.
	 *
	 * @return int
	 */
	public static function max_size() {
		/**
		 * Filters the maximum attachment size in bytes.
		 *
		 * @param int $max Maximum size in bytes.
		 */
		return (int) apply_filters( 'site0_ticketing_attachment_max_size', 8 * MB_IN_BYTES );
	}

	/**
	 * Maximum number of attachments per message.
	 *
	 * @return int
	 */
	public static function max_count() {
		/**
		 * Filters the maximum number of attachments per message.
		 *
		 * @param int $max Maximum file count.
		 */
		return (int) apply_filters( 'site0_ticketing_attachment_max_count', 5 );
	}

	/**
	 * Private storage base directory (created on demand).
	 *
	 * Uses the main site's uploads directory so files live at the network
	 * level, mirroring the network-level ticket tables.
	 *
	 * @return string|WP_Error Absolute directory path.
	 */
	public static function base_dir() {
		$switched = false;

		if ( is_multisite() && get_main_site_id() !== get_current_blog_id() ) {
			switch_to_blog( get_main_site_id() );
			$switched = true;
		}

		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'site0-ticketing';

		if ( $switched ) {
			restore_current_blog();
		}

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
			return new WP_Error( 'site0_ticketing_att_dir', __( 'The attachment storage directory is not writable.', 'site0-ticketing' ) );
		}

		self::guard_directory( $dir );

		return $dir;
	}

	/**
	 * Blocks direct web access to the storage directory.
	 *
	 * @param string $dir Directory path.
	 */
	private static function guard_directory( $dir ) {
		$htaccess = trailingslashit( $dir ) . '.htaccess';

		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "Options -Indexes\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n" );
		}

		$index = trailingslashit( $dir ) . 'index.php';

		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Normalizes and validates an uploaded files array.
	 *
	 * @param array|null $files Raw $_FILES entry for the multi file input.
	 * @return array {
	 *     @type array $files  Validated payloads ready for store().
	 *     @type array $errors Human-readable error messages.
	 * }
	 */
	public static function prepare( $files ) {
		$prepared = array(
			'files'  => array(),
			'errors' => array(),
		);

		if ( empty( $files ) || ! isset( $files['name'] ) || ! is_array( $files['name'] ) ) {
			return $prepared;
		}

		$count = count( $files['name'] );

		if ( $count > self::max_count() ) {
			$prepared['errors'][] = sprintf(
				/* translators: %d: maximum number of files. */
				__( 'Too many attachments. A message can hold up to %d files.', 'site0-ticketing' ),
				self::max_count()
			);
			return $prepared;
		}

		for ( $i = 0; $i < $count; $i++ ) {
			$name = isset( $files['name'][ $i ] ) ? (string) $files['name'][ $i ] : '';

			if ( '' === $name ) {
				continue;
			}

			$file = array(
				'name'     => $name,
				'type'     => isset( $files['type'][ $i ] ) ? (string) $files['type'][ $i ] : '',
				'tmp_name' => isset( $files['tmp_name'][ $i ] ) ? (string) $files['tmp_name'][ $i ] : '',
				'error'    => isset( $files['error'][ $i ] ) ? (int) $files['error'][ $i ] : UPLOAD_ERR_NO_FILE,
				'size'     => isset( $files['size'][ $i ] ) ? (int) $files['size'][ $i ] : 0,
			);

			$validated = self::validate( $file );

			if ( is_wp_error( $validated ) ) {
				$prepared['errors'][] = sprintf(
					/* translators: %s: file name. */
					__( '%s could not be attached:', 'site0-ticketing' ),
					esc_html( sanitize_file_name( $name ) )
				) . ' ' . $validated->get_error_message();
				continue;
			}

			$prepared['files'][] = $validated;
		}

		return $prepared;
	}

	/**
	 * Validates a single uploaded file against type and size rules.
	 *
	 * @param array $file One entry from the normalized $_FILES array.
	 * @return array|WP_Error Validated payload or error.
	 */
	private static function validate( $file ) {
		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			return new WP_Error( 'site0_ticketing_att_upload', __( 'the upload failed.', 'site0-ticketing' ) );
		}

		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'site0_ticketing_att_upload', __( 'the upload is not valid.', 'site0-ticketing' ) );
		}

		if ( $file['size'] > self::max_size() ) {
			return new WP_Error(
				'site0_ticketing_att_size',
				sprintf(
					/* translators: %s: maximum file size. */
					__( 'the file exceeds the %s size limit.', 'site0-ticketing' ),
					size_format( self::max_size() )
				)
			);
		}

		$filetype = wp_check_filetype( sanitize_file_name( $file['name'] ), self::ALLOWED_MIMES );
		$ext      = strtolower( (string) $filetype['ext'] );

		if ( '' === $ext || ! isset( self::ALLOWED_MIMES[ $ext ] ) ) {
			return new WP_Error( 'site0_ticketing_att_type', __( 'only images and zip/rar archives are allowed.', 'site0-ticketing' ) );
		}

		$expected = self::ALLOWED_MIMES[ $ext ];

		if ( str_starts_with( $expected, 'image/' ) ) {
			// Images must genuinely be that image type; wp_get_image_mime()
			// relies on getimagesize(), which needs no fileinfo extension.
			$real = wp_get_image_mime( $file['tmp_name'] );

			if ( $real !== $expected ) {
				return new WP_Error( 'site0_ticketing_att_type', __( 'the file content does not match an image.', 'site0-ticketing' ) );
			}
		} else {
			$sniffed = self::sniff_mime( $file['tmp_name'] );

			if ( is_wp_error( $sniffed ) ) {
				return $sniffed;
			}

			if ( ! in_array( $sniffed, self::ARCHIVE_SNIFF_MIMES, true ) ) {
				return new WP_Error( 'site0_ticketing_att_type', __( 'the file content does not match a zip/rar archive.', 'site0-ticketing' ) );
			}
		}

		return array(
			'original_name' => sanitize_file_name( $file['name'] ),
			'tmp_name'      => $file['tmp_name'],
			'ext'           => $ext,
			'mime_type'     => $expected,
			'size'          => $file['size'],
		);
	}

	/**
	 * Sniffs the real MIME type of a file on disk.
	 *
	 * @param string $path Absolute file path.
	 * @return string|WP_Error Detected MIME type.
	 */
	private static function sniff_mime( $path ) {
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );

			if ( $finfo ) {
				$mime = finfo_file( $finfo, $path );
				finfo_close( $finfo );

				if ( is_string( $mime ) && '' !== $mime ) {
					return $mime;
				}
			}
		}

		if ( function_exists( 'mime_content_type' ) ) {
			$mime = mime_content_type( $path );

			if ( is_string( $mime ) && '' !== $mime ) {
				return $mime;
			}
		}

		// fileinfo may be disabled on some hosts; fall back to magic bytes.
		$magic = self::sniff_by_magic_bytes( $path );

		if ( '' !== $magic ) {
			return $magic;
		}

		return new WP_Error( 'site0_ticketing_att_sniff', __( 'the file type could not be verified.', 'site0-ticketing' ) );
	}

	/**
	 * Identifies a file by its magic bytes when finfo is unavailable.
	 *
	 * Only covers the types allowed by ALLOWED_MIMES so unknown binaries
	 * stay unverifiable and keep being rejected (fail closed).
	 *
	 * @param string $path Absolute file path.
	 * @return string Detected MIME type or empty string when unknown.
	 */
	private static function sniff_by_magic_bytes( $path ) {
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( ! $handle ) {
			return '';
		}

		$bytes = (string) fread( $handle, 12 );
		fclose( $handle );

		if ( ! isset( $bytes[3] ) ) {
			return '';
		}

		if ( "\x89PNG" === substr( $bytes, 0, 4 ) ) {
			return 'image/png';
		}

		if ( 'GIF8' === substr( $bytes, 0, 4 ) ) {
			return 'image/gif';
		}

		if ( "\xFF\xD8\xFF" === substr( $bytes, 0, 3 ) ) {
			return 'image/jpeg';
		}

		if ( 'RIFF' === substr( $bytes, 0, 4 ) && 'WEBP' === substr( $bytes, 8, 4 ) ) {
			return 'image/webp';
		}

		if ( 'Rar!' === substr( $bytes, 0, 4 ) && "\x1A\x07" === substr( $bytes, 4, 2 ) ) {
			return 'application/vnd.rar';
		}

		if ( 'PK' === substr( $bytes, 0, 2 ) && in_array( substr( $bytes, 2, 2 ), array( "\x03\x04", "\x05\x06", "\x07\x08" ), true ) ) {
			return 'application/zip';
		}

		return '';
	}

	/**
	 * Moves validated files into private storage and records their rows.
	 *
	 * @param array $payloads  Payloads from prepare().
	 * @param int   $ticket_id Ticket ID.
	 * @param int   $reply_id  Reply ID, 0 for the initial ticket message.
	 * @return array {
	 *     @type array $ids    Stored attachment IDs.
	 *     @type array $errors Human-readable error messages.
	 * }
	 */
	public static function store( $payloads, $ticket_id, $reply_id = 0 ) {
		global $wpdb;

		$result = array(
			'ids'    => array(),
			'errors' => array(),
		);

		if ( empty( $payloads ) ) {
			return $result;
		}

		$base = self::base_dir();

		if ( is_wp_error( $base ) ) {
			$result['errors'][] = $base->get_error_message();
			return $result;
		}

		$sub_dir = gmdate( 'Y/m' );
		$target  = trailingslashit( $base ) . $sub_dir;

		if ( ! file_exists( $target ) && ! wp_mkdir_p( $target ) ) {
			$result['errors'][] = __( 'The attachment storage directory is not writable.', 'site0-ticketing' );
			return $result;
		}

		foreach ( $payloads as $payload ) {
			$stored_name = $sub_dir . '/' . wp_generate_password( 24, false, false ) . '.' . $payload['ext'];
			$dest        = trailingslashit( $base ) . $stored_name;

			$moved = @move_uploaded_file( $payload['tmp_name'], $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

			if ( ! $moved || ! file_exists( $dest ) ) {
				$result['errors'][] = sprintf(
					/* translators: %s: file name. */
					__( '%s could not be saved.', 'site0-ticketing' ),
					esc_html( $payload['original_name'] )
				);
				continue;
			}

			$wpdb->insert(
				$wpdb->base_prefix . 's0_ticket_attachments',
				array(
					'ticket_id'   => (int) $ticket_id,
					'reply_id'    => (int) $reply_id,
					'user_id'     => get_current_user_id(),
					'file_name'   => $payload['original_name'],
					'stored_name' => $stored_name,
					'mime_type'   => $payload['mime_type'],
					'file_size'   => (int) $payload['size'],
					'created_at'  => current_time( 'mysql' ),
				),
				array( '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s' )
			);

			if ( $wpdb->insert_id ) {
				$result['ids'][] = (int) $wpdb->insert_id;
			}
		}

		return $result;
	}

	/**
	 * Fetches a single attachment row.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return object|null
	 */
	public static function get( $attachment_id ) {
		global $wpdb;

		$table = $wpdb->base_prefix . 's0_ticket_attachments';

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $attachment_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Fetches attachments for a ticket grouped by reply ID.
	 *
	 * The initial ticket message uses reply key 0.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return array[] Map of reply_id => attachment rows.
	 */
	public static function map_for_ticket( $ticket_id ) {
		global $wpdb;

		$table = $wpdb->base_prefix . 's0_ticket_attachments';
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE ticket_id = %d ORDER BY id ASC", (int) $ticket_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$map = array();

		if ( $rows ) {
			foreach ( $rows as $row ) {
				$reply_id = (int) $row->reply_id;
				$map[ $reply_id ][] = $row;
			}
		}

		return $map;
	}

	/**
	 * Deletes all attachments (files + rows) for a ticket.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return bool
	 */
	public static function delete_for_ticket( $ticket_id ) {
		global $wpdb;

		$table = $wpdb->base_prefix . 's0_ticket_attachments';

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT stored_name FROM {$table} WHERE ticket_id = %d", (int) $ticket_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( $rows ) {
			foreach ( $rows as $row ) {
				self::delete_file( (string) $row->stored_name );
			}
		}

		$wpdb->delete( $table, array( 'ticket_id' => (int) $ticket_id ), array( '%d' ) );

		return true;
	}

	/**
	 * Removes a stored file, validating its path stays inside storage.
	 *
	 * @param string $stored_name Relative stored name (with Y/m prefix).
	 * @return bool
	 */
	private static function delete_file( $stored_name ) {
		$base = self::base_dir();

		if ( is_wp_error( $base ) ) {
			return false;
		}

		$path = realpath( trailingslashit( $base ) . ltrim( (string) $stored_name, '/\\' ) );

		if ( ! $path || 0 !== strpos( $path, (string) realpath( $base ) ) || ! file_exists( $path ) ) {
			return false;
		}

		return @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * Whether a MIME type is an image.
	 *
	 * @param string $mime MIME type.
	 * @return bool
	 */
	public static function is_image( $mime ) {
		return is_string( $mime ) && str_starts_with( $mime, 'image/' );
	}

	/**
	 * Builds the nonce-protected download URL for an attachment.
	 *
	 * @param object $attachment Attachment row.
	 * @return string
	 */
	public static function download_url( $attachment ) {
		$url = add_query_arg(
			array(
				'action'     => 'site0_ticketing_download',
				'attachment' => (int) $attachment->id,
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, 'site0_ticketing_download_' . (int) $attachment->id );
	}

	/**
	 * Streams an attachment after verifying access.
	 */
	public function handle_download() {
		$attachment_id = isset( $_GET['attachment'] ) ? (int) $_GET['attachment'] : 0;
		$nonce         = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'site0_ticketing_download_' . $attachment_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'site0-ticketing' ) );
		}

		$attachment = self::get( $attachment_id );

		if ( ! $attachment ) {
			wp_die( esc_html__( 'Attachment not found.', 'site0-ticketing' ) );
		}

		$ticket = Site0_Ticketing_Tickets::get( (int) $attachment->ticket_id );

		if ( ! $ticket || ! Site0_Ticketing_Capabilities::can_view_ticket( $ticket ) ) {
			wp_die( esc_html__( 'You are not allowed to access this attachment.', 'site0-ticketing' ) );
		}

		$base = self::base_dir();

		if ( is_wp_error( $base ) ) {
			wp_die( esc_html( $base->get_error_message() ) );
		}

		$path = realpath( trailingslashit( $base ) . ltrim( (string) $attachment->stored_name, '/\\' ) );

		if ( ! $path || 0 !== strpos( $path, (string) realpath( $base ) ) || ! file_exists( $path ) ) {
			wp_die( esc_html__( 'The attachment file is missing.', 'site0-ticketing' ) );
		}

		$disposition = self::is_image( $attachment->mime_type ) ? 'inline' : 'attachment';
		$filename    = sanitize_file_name( $attachment->file_name );

		nocache_headers();
		header( 'Content-Type: ' . $attachment->mime_type );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'Content-Disposition: ' . $disposition . '; filename="' . str_replace( '"', '', $filename ) . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Renders the attachments block for one thread entry.
	 *
	 * @param object $attachment Attachment row.
	 * @return string HTML.
	 */
	public static function render_item( $attachment ) {
		$url      = self::download_url( $attachment );
		$name     = $attachment->file_name;
		$template = '<a class="st-attachment" href="%1$s" target="_blank" rel="noopener">%2$s<span class="st-attachment__name">%3$s</span></a>';

		if ( self::is_image( $attachment->mime_type ) ) {
			$html = sprintf(
				$template,
				esc_url( $url ),
				sprintf(
					'<img class="st-attachment__thumb" src="%1$s" alt="%2$s" />',
					esc_url( $url ),
					esc_attr( $name )
				),
				esc_html( $name )
			);
		} else {
			$html = sprintf(
				$template,
				esc_url( $url ),
				'<span class="dashicons dashicons-media-archive st-attachment__icon" aria-hidden="true"></span>',
				esc_html( $name ) . ' <span class="st-meta">' . esc_html( size_format( (int) $attachment->file_size ) ) . '</span>'
			);
		}

		return $html;
	}

	/**
	 * Echoes the attachment list for a thread entry.
	 *
	 * @param array $map      Map from map_for_ticket() for the ticket.
	 * @param int   $reply_id Reply ID (0 for the initial message).
	 */
	public static function render_for_entry( $map, $reply_id ) {
		$items = isset( $map[ (int) $reply_id ] ) ? $map[ (int) $reply_id ] : array();

		if ( empty( $items ) ) {
			return;
		}

		echo '<div class="st-attachments">';

		foreach ( $items as $item ) {
			echo wp_kses_post( self::render_item( $item ) );
		}

		echo '</div>';
	}
}
