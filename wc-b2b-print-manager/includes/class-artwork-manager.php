<?php
/**
 * Artwork Manager
 *
 * Handles:
 *   - File upload field on WooCommerce product pages (and checkout).
 *   - Saving uploaded files to the artwork library (custom DB table).
 *   - Attaching artwork to orders via order meta.
 *   - AJAX: library listing, reuse, and deletion.
 *
 * Uploaded files are stored in /wp-content/uploads/b2b-artwork/{user_id}/
 *
 * @package WC_B2B\Includes
 */

declare( strict_types=1 );

namespace WC_B2B;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Artwork_Manager
 */
class Artwork_Manager {

	/**
	 * Sub-directory inside wp-content/uploads.
	 */
	const UPLOAD_DIR = 'b2b-artwork';

	/**
	 * Order meta key that stores uploaded artwork IDs (JSON array).
	 */
	const ORDER_META_KEY = '_b2b_artwork_ids';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		// Add upload field to single product page.
		add_action( 'woocommerce_before_add_to_cart_button', [ $this, 'render_product_upload_field' ] );

		// Validate and store uploaded file when adding to cart.
		add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_artwork_upload' ], 10, 3 );
		add_action( 'woocommerce_add_cart_item_data', [ $this, 'attach_artwork_to_cart_item' ], 10, 3 );

		// Persist artwork IDs with order on checkout.
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'copy_artwork_to_order_item' ], 10, 4 );
		add_action( 'woocommerce_checkout_order_created', [ $this, 'save_order_artwork_meta' ] );

		// Display artwork in order admin & My Account.
		add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'display_artwork_in_admin_order' ] );
		add_action( 'woocommerce_order_details_after_order_table', [ $this, 'display_artwork_in_my_account_order' ] );

		// AJAX handlers.
		add_action( 'wp_ajax_b2b_get_artwork_library', [ $this, 'ajax_get_library' ] );
		add_action( 'wp_ajax_b2b_save_artwork', [ $this, 'ajax_save_artwork' ] );
		add_action( 'wp_ajax_b2b_delete_artwork', [ $this, 'ajax_delete_artwork' ] );
		add_action( 'wp_ajax_b2b_upload_artwork', [ $this, 'ajax_upload_artwork' ] );
	}

	// -------------------------------------------------------------------------
	// Product Upload Field
	// -------------------------------------------------------------------------

	/**
	 * Render the artwork upload field on the single product page.
	 */
	public function render_product_upload_field(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id    = get_current_user_id();
		$library    = $this->get_user_artwork( $user_id, 5 );
		$max_size   = (int) get_option( 'wc_b2b_artwork_max_size_mb', 20 );
		$file_types = get_option( 'wc_b2b_allowed_file_types', 'pdf,ai,eps,jpg,jpeg,png,tiff,tif' );
		?>
		<div class="b2b-artwork-upload-wrap">
			<h4><?php esc_html_e( 'Upload Artwork', 'wc-b2b-print-manager' ); ?></h4>

			<?php if ( ! empty( $library ) ) : ?>
				<p><strong><?php esc_html_e( 'Choose from your library:', 'wc-b2b-print-manager' ); ?></strong></p>
				<select name="b2b_artwork_library_id" id="b2b_artwork_library_id">
					<option value=""><?php esc_html_e( '— Select saved artwork —', 'wc-b2b-print-manager' ); ?></option>
					<?php foreach ( $library as $item ) : ?>
						<option value="<?php echo esc_attr( $item->id ); ?>">
							<?php echo esc_html( $item->title ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p><?php esc_html_e( 'Or upload a new file:', 'wc-b2b-print-manager' ); ?></p>
			<?php endif; ?>

			<input
				type="file"
				name="b2b_artwork_file[]"
				id="b2b_artwork_file"
				multiple
				accept=".<?php echo esc_attr( str_replace( ',', ',.', $file_types ) ); ?>"
			/>
			<p class="b2b-upload-hint">
				<?php
				printf(
					/* translators: 1: allowed types, 2: max MB */
					esc_html__( 'Accepted: %1$s. Max size: %2$s MB per file.', 'wc-b2b-print-manager' ),
					esc_html( strtoupper( $file_types ) ),
					esc_html( (string) $max_size )
				);
				?>
			</p>
			<label>
				<input type="checkbox" name="b2b_save_to_library" value="1" />
				<?php esc_html_e( 'Save this artwork to my library for future use', 'wc-b2b-print-manager' ); ?>
			</label>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Cart / Checkout
	// -------------------------------------------------------------------------

	/**
	 * Validate the uploaded artwork files.
	 *
	 * @param bool $passed     Current validation status.
	 * @param int  $product_id Product ID.
	 * @param int  $quantity   Quantity.
	 * @return bool
	 */
	public function validate_artwork_upload( bool $passed, int $product_id, int $quantity ): bool {
		// Library selection is always valid.
		if ( ! empty( $_POST['b2b_artwork_library_id'] ) ) {
			return $passed;
		}

		if ( empty( $_FILES['b2b_artwork_file']['name'][0] ) ) {
			return $passed; // Artwork upload is optional.
		}

		$max_size_bytes = (int) get_option( 'wc_b2b_artwork_max_size_mb', 20 ) * 1024 * 1024;
		$allowed_types  = explode( ',', get_option( 'wc_b2b_allowed_file_types', 'pdf,ai,eps,jpg,jpeg,png,tiff,tif' ) );

		foreach ( $_FILES['b2b_artwork_file']['name'] as $i => $name ) {
			if ( empty( $name ) ) {
				continue;
			}

			$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, $allowed_types, true ) ) {
				wc_add_notice(
					sprintf(
						/* translators: %s: file extension */
						__( 'File type ".%s" is not allowed for artwork uploads.', 'wc-b2b-print-manager' ),
						esc_html( $ext )
					),
					'error'
				);
				return false;
			}

			if ( $_FILES['b2b_artwork_file']['size'][ $i ] > $max_size_bytes ) {
				wc_add_notice(
					sprintf(
						/* translators: 1: filename, 2: max MB */
						__( 'File "%1$s" exceeds the %2$s MB size limit.', 'wc-b2b-print-manager' ),
						esc_html( $name ),
						esc_html( (string) ( $max_size_bytes / 1024 / 1024 ) )
					),
					'error'
				);
				return false;
			}
		}

		return $passed;
	}

	/**
	 * Process and attach artwork files to the cart item data.
	 *
	 * @param array $cart_item_data Existing cart item meta.
	 * @param int   $product_id     Product ID.
	 * @param int   $variation_id   Variation ID.
	 * @return array
	 */
	public function attach_artwork_to_cart_item( array $cart_item_data, int $product_id, int $variation_id ): array {
		$user_id    = get_current_user_id();
		$artwork_ids = [];

		// Handle library selection.
		$library_id = (int) sanitize_text_field( wp_unslash( $_POST['b2b_artwork_library_id'] ?? '' ) );
		if ( $library_id ) {
			$artwork_ids[] = $library_id;
		}

		// Handle new file uploads.
		if ( ! empty( $_FILES['b2b_artwork_file']['name'][0] ) ) {
			$save_to_library = ! empty( $_POST['b2b_save_to_library'] );

			foreach ( $_FILES['b2b_artwork_file']['name'] as $i => $name ) {
				if ( empty( $name ) ) {
					continue;
				}

				$file = [
					'name'     => $name,
					'type'     => $_FILES['b2b_artwork_file']['type'][ $i ],
					'tmp_name' => $_FILES['b2b_artwork_file']['tmp_name'][ $i ],
					'error'    => $_FILES['b2b_artwork_file']['error'][ $i ],
					'size'     => $_FILES['b2b_artwork_file']['size'][ $i ],
				];

				$saved_id = $this->save_uploaded_file( $file, $user_id, $save_to_library );
				if ( $saved_id ) {
					$artwork_ids[] = $saved_id;
				}
			}
		}

		if ( ! empty( $artwork_ids ) ) {
			$cart_item_data['b2b_artwork_ids'] = $artwork_ids;
		}

		return $cart_item_data;
	}

	/**
	 * Copy artwork IDs from cart item to WC order line item meta.
	 *
	 * @param \WC_Order_Item_Product $item          Order line item.
	 * @param string                 $cart_item_key Cart item key.
	 * @param array                  $values        Cart item data.
	 * @param \WC_Order              $order         Order object.
	 */
	public function copy_artwork_to_order_item( $item, string $cart_item_key, array $values, \WC_Order $order ): void {
		if ( ! empty( $values['b2b_artwork_ids'] ) ) {
			$item->update_meta_data( '_b2b_artwork_ids', wp_json_encode( $values['b2b_artwork_ids'] ) );
		}
	}

	/**
	 * Aggregate all artwork IDs from line items onto the order for quick access.
	 *
	 * @param \WC_Order $order Order object.
	 */
	public function save_order_artwork_meta( \WC_Order $order ): void {
		$all_ids = [];
		foreach ( $order->get_items() as $item ) {
			$ids = json_decode( $item->get_meta( '_b2b_artwork_ids' ) ?? '[]', true );
			if ( is_array( $ids ) ) {
				$all_ids = array_merge( $all_ids, $ids );
			}
		}
		if ( ! empty( $all_ids ) ) {
			$order->update_meta_data( self::ORDER_META_KEY, array_unique( $all_ids ) );
			$order->save();
		}
	}

	// -------------------------------------------------------------------------
	// Admin / My Account display
	// -------------------------------------------------------------------------

	/**
	 * Show artwork links in the WC admin order edit page.
	 *
	 * @param \WC_Order $order Order object.
	 */
	public function display_artwork_in_admin_order( \WC_Order $order ): void {
		$ids = $order->get_meta( self::ORDER_META_KEY );
		if ( empty( $ids ) ) {
			return;
		}

		echo '<h3>' . esc_html__( 'Order Artwork', 'wc-b2b-print-manager' ) . '</h3>';
		echo '<ul class="b2b-artwork-list">';
		foreach ( (array) $ids as $artwork_id ) {
			$artwork = $this->get_artwork( (int) $artwork_id );
			if ( $artwork ) {
				printf(
					'<li><a href="%s" target="_blank">%s</a> (%s)</li>',
					esc_url( $artwork->file_url ),
					esc_html( $artwork->title ?: basename( $artwork->file_url ) ),
					esc_html( $artwork->file_type )
				);
			}
		}
		echo '</ul>';
	}

	/**
	 * Show artwork links in My Account → Order Details.
	 *
	 * @param \WC_Order $order Order object.
	 */
	public function display_artwork_in_my_account_order( \WC_Order $order ): void {
		$ids = $order->get_meta( self::ORDER_META_KEY );
		if ( empty( $ids ) ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Artwork Files', 'wc-b2b-print-manager' ) . '</h2>';
		echo '<ul class="b2b-artwork-list">';
		foreach ( (array) $ids as $artwork_id ) {
			$artwork = $this->get_artwork( (int) $artwork_id );
			if ( $artwork ) {
				printf(
					'<li><a href="%s" target="_blank">%s</a></li>',
					esc_url( $artwork->file_url ),
					esc_html( $artwork->title ?: basename( $artwork->file_url ) )
				);
			}
		}
		echo '</ul>';
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	/**
	 * AJAX: Return artwork library entries for the current user.
	 */
	public function ajax_get_library(): void {
		check_ajax_referer( 'b2b_artwork_nonce', '_nonce' );

		$user_id = get_current_user_id();
		$items   = $this->get_user_artwork( $user_id );

		$data = array_map(
			fn( $row ) => [
				'id'       => (int) $row->id,
				'title'    => $row->title,
				'file_url' => $row->file_url,
				'type'     => $row->file_type,
				'date'     => $row->created_at,
			],
			$items
		);

		wp_send_json_success( $data );
	}

	/**
	 * AJAX: Save a new artwork entry (title + file_url provided).
	 */
	public function ajax_save_artwork(): void {
		check_ajax_referer( 'b2b_artwork_nonce', '_nonce' );

		$user_id  = get_current_user_id();
		$title    = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );

		if ( empty( $title ) ) {
			wp_send_json_error( [ 'message' => __( 'Title is required.', 'wc-b2b-print-manager' ) ] );
		}

		if ( empty( $_FILES['artwork_file'] ) ) {
			wp_send_json_error( [ 'message' => __( 'No file provided.', 'wc-b2b-print-manager' ) ] );
		}

		$saved_id = $this->save_uploaded_file( $_FILES['artwork_file'], $user_id, true, $title );
		if ( ! $saved_id ) {
			wp_send_json_error( [ 'message' => __( 'Failed to save artwork.', 'wc-b2b-print-manager' ) ] );
		}

		wp_send_json_success(
			[
				'id'      => $saved_id,
				'message' => __( 'Artwork saved to library.', 'wc-b2b-print-manager' ),
			]
		);
	}

	/**
	 * AJAX: Delete an artwork entry (must be owned by current user).
	 */
	public function ajax_delete_artwork(): void {
		check_ajax_referer( 'b2b_artwork_nonce', '_nonce' );

		$user_id    = get_current_user_id();
		$artwork_id = (int) sanitize_text_field( wp_unslash( $_POST['artwork_id'] ?? '' ) );
		$artwork    = $this->get_artwork( $artwork_id );

		if ( ! $artwork ) {
			wp_send_json_error( [ 'message' => __( 'Artwork not found.', 'wc-b2b-print-manager' ) ] );
		}

		if ( (int) $artwork->user_id !== $user_id && ! current_user_can( 'administrator' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wc-b2b-print-manager' ) ] );
		}

		$this->delete_artwork( $artwork_id );

		wp_send_json_success( [ 'message' => __( 'Artwork deleted.', 'wc-b2b-print-manager' ) ] );
	}

	/**
	 * AJAX: Stand-alone artwork upload (used from the dashboard library tab).
	 */
	public function ajax_upload_artwork(): void {
		check_ajax_referer( 'b2b_artwork_nonce', '_nonce' );

		if ( empty( $_FILES['artwork_file'] ) ) {
			wp_send_json_error( [ 'message' => __( 'No file received.', 'wc-b2b-print-manager' ) ] );
		}

		$user_id = get_current_user_id();
		$title   = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) )
			?: sanitize_file_name( $_FILES['artwork_file']['name'] );

		$saved_id = $this->save_uploaded_file( $_FILES['artwork_file'], $user_id, true, $title );
		if ( ! $saved_id ) {
			wp_send_json_error( [ 'message' => __( 'Upload failed.', 'wc-b2b-print-manager' ) ] );
		}

		$artwork = $this->get_artwork( $saved_id );
		wp_send_json_success(
			[
				'id'       => $saved_id,
				'title'    => $artwork->title,
				'file_url' => $artwork->file_url,
				'type'     => $artwork->file_type,
			]
		);
	}

	// -------------------------------------------------------------------------
	// File Storage
	// -------------------------------------------------------------------------

	/**
	 * Move an uploaded file to the B2B upload directory and insert a DB record.
	 *
	 * @param array  $file            $_FILES entry.
	 * @param int    $user_id         Owner user ID.
	 * @param bool   $save_to_library Whether to persist in the library.
	 * @param string $title           Optional title (defaults to filename).
	 * @return int Inserted artwork ID, or 0 on failure.
	 */
	private function save_uploaded_file( array $file, int $user_id, bool $save_to_library = false, string $title = '' ): int {
		if ( $file['error'] !== UPLOAD_ERR_OK ) {
			return 0;
		}

		$upload_dir = $this->get_upload_dir( $user_id );
		if ( ! $upload_dir ) {
			return 0;
		}

		$filename  = sanitize_file_name( $file['name'] );
		$dest_path = $upload_dir['path'] . '/' . wp_unique_filename( $upload_dir['path'], $filename );
		$dest_url  = $upload_dir['url'] . '/' . basename( $dest_path );

		if ( ! move_uploaded_file( $file['tmp_name'], $dest_path ) ) {
			return 0;
		}

		$company_id = Company_Manager::get_user_company_id( $user_id );

		return $this->insert_artwork_record(
			$user_id,
			$company_id,
			$title ?: $filename,
			$dest_url,
			$dest_path,
			$file['type']
		);
	}

	/**
	 * Get (and create) the user-specific upload directory.
	 *
	 * @param int $user_id User ID.
	 * @return array|null ['path' => ..., 'url' => ...] or null on failure.
	 */
	private function get_upload_dir( int $user_id ): ?array {
		$wp_upload   = wp_upload_dir();
		$base_path   = trailingslashit( $wp_upload['basedir'] ) . self::UPLOAD_DIR . '/' . $user_id;
		$base_url    = trailingslashit( $wp_upload['baseurl'] ) . self::UPLOAD_DIR . '/' . $user_id;

		if ( ! wp_mkdir_p( $base_path ) ) {
			return null;
		}

		// Prevent direct browsing.
		$htaccess = $base_path . '/../.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Options -Indexes\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		return [
			'path' => $base_path,
			'url'  => $base_url,
		];
	}

	// -------------------------------------------------------------------------
	// DB Helpers
	// -------------------------------------------------------------------------

	/**
	 * Insert a row into wp_b2b_artwork_library.
	 *
	 * @param int    $user_id    Owner user ID.
	 * @param int    $company_id Company ID.
	 * @param string $title      Display title.
	 * @param string $file_url   Public URL.
	 * @param string $file_path  Server path.
	 * @param string $file_type  MIME type.
	 * @return int Inserted row ID or 0.
	 */
	private function insert_artwork_record(
		int $user_id,
		int $company_id,
		string $title,
		string $file_url,
		string $file_path,
		string $file_type
	): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$wpdb->prefix . 'b2b_artwork_library',
			[
				'user_id'    => $user_id,
				'company_id' => $company_id,
				'title'      => $title,
				'file_url'   => $file_url,
				'file_path'  => $file_path,
				'file_type'  => $file_type,
			],
			[ '%d', '%d', '%s', '%s', '%s', '%s' ]
		);
		// phpcs:enable

		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetch a single artwork row.
	 *
	 * @param int $artwork_id Artwork library row ID.
	 * @return object|null
	 */
	public function get_artwork( int $artwork_id ): ?object {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}b2b_artwork_library WHERE id = %d",
				$artwork_id
			)
		);
		// phpcs:enable
	}

	/**
	 * Fetch artwork library for a user.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Max rows (-1 = all).
	 * @return object[]
	 */
	public function get_user_artwork( int $user_id, int $limit = -1 ): array {
		global $wpdb;

		$limit_sql = $limit > 0 ? $wpdb->prepare( 'LIMIT %d', $limit ) : '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}b2b_artwork_library
				 WHERE user_id = %d
				 ORDER BY created_at DESC
				 $limit_sql",
				$user_id
			)
		);
		// phpcs:enable
	}

	/**
	 * Fetch artwork library for an entire company (admin view).
	 *
	 * @param int $company_id Company post ID.
	 * @return object[]
	 */
	public function get_company_artwork( int $company_id ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT al.*, u.display_name as owner_name
				 FROM {$wpdb->prefix}b2b_artwork_library al
				 LEFT JOIN {$wpdb->users} u ON al.user_id = u.ID
				 WHERE al.company_id = %d
				 ORDER BY al.created_at DESC",
				$company_id
			)
		);
		// phpcs:enable
	}

	/**
	 * Delete an artwork record and its physical file.
	 *
	 * @param int $artwork_id Artwork row ID.
	 */
	private function delete_artwork( int $artwork_id ): void {
		global $wpdb;

		$artwork = $this->get_artwork( $artwork_id );
		if ( ! $artwork ) {
			return;
		}

		// Remove the file from disk.
		if ( file_exists( $artwork->file_path ) ) {
			wp_delete_file( $artwork->file_path );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->delete(
			$wpdb->prefix . 'b2b_artwork_library',
			[ 'id' => $artwork_id ],
			[ '%d' ]
		);
		// phpcs:enable
	}
}
