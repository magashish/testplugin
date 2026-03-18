<?php
/**
 * Company Manager
 *
 * Registers the 'company' Custom Post Type and exposes a clean API for
 * reading/writing company data (logo, pricing rules, approval settings).
 *
 * All user↔company relationships are stored as user meta (company_id).
 *
 * @package WC_B2B\Includes
 */

declare( strict_types=1 );

namespace WC_B2B;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Company_Manager
 */
class Company_Manager {

	/**
	 * CPT slug.
	 */
	const POST_TYPE = 'b2b_company';

	/**
	 * User meta key that stores the company ID.
	 */
	const USER_META_COMPANY = 'b2b_company_id';

	/**
	 * Wire up all hooks.
	 */
	public function __construct() {
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_company_meta_boxes' ] );
		add_action( 'save_post_' . self::POST_TYPE, [ $this, 'save_company_meta' ], 10, 2 );
	}

	// -------------------------------------------------------------------------
	// CPT Registration
	// -------------------------------------------------------------------------

	/**
	 * Register the 'b2b_company' post type.
	 */
	public function register_post_type(): void {
		$labels = [
			'name'               => _x( 'Companies', 'post type general name', 'wc-b2b-print-manager' ),
			'singular_name'      => _x( 'Company', 'post type singular name', 'wc-b2b-print-manager' ),
			'menu_name'          => __( 'Companies', 'wc-b2b-print-manager' ),
			'add_new'            => __( 'Add New', 'wc-b2b-print-manager' ),
			'add_new_item'       => __( 'Add New Company', 'wc-b2b-print-manager' ),
			'edit_item'          => __( 'Edit Company', 'wc-b2b-print-manager' ),
			'new_item'           => __( 'New Company', 'wc-b2b-print-manager' ),
			'view_item'          => __( 'View Company', 'wc-b2b-print-manager' ),
			'search_items'       => __( 'Search Companies', 'wc-b2b-print-manager' ),
			'not_found'          => __( 'No companies found', 'wc-b2b-print-manager' ),
			'not_found_in_trash' => __( 'No companies found in trash', 'wc-b2b-print-manager' ),
		];

		$args = [
			'labels'              => $labels,
			'public'              => false,
			'publicly_queryable'  => false,
			'show_ui'             => true,
			'show_in_menu'        => false, // Shown under our custom admin menu.
			'show_in_nav_menus'   => false,
			'query_var'           => false,
			'rewrite'             => false,
			'capability_type'     => 'post',
			'capabilities'        => [
				'edit_post'          => 'manage_companies',
				'read_post'          => 'manage_companies',
				'delete_post'        => 'manage_companies',
				'edit_posts'         => 'manage_companies',
				'edit_others_posts'  => 'manage_companies',
				'publish_posts'      => 'manage_companies',
				'read_private_posts' => 'manage_companies',
			],
			'has_archive'         => false,
			'hierarchical'        => false,
			'menu_position'       => null,
			'supports'            => [ 'title', 'thumbnail' ], // thumbnail = logo.
		];

		register_post_type( self::POST_TYPE, $args );
	}

	// -------------------------------------------------------------------------
	// Meta Boxes
	// -------------------------------------------------------------------------

	/**
	 * Register meta boxes for company settings.
	 */
	public function add_company_meta_boxes(): void {
		add_meta_box(
			'b2b_company_settings',
			__( 'Company Settings', 'wc-b2b-print-manager' ),
			[ $this, 'render_settings_meta_box' ],
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render the company settings meta box HTML.
	 *
	 * @param \WP_Post $post Current post object.
	 */
	public function render_settings_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'b2b_company_meta_nonce', 'b2b_company_meta_nonce' );

		$pricing_type     = get_post_meta( $post->ID, '_b2b_pricing_type', true ) ?: 'percentage';
		$pricing_value    = get_post_meta( $post->ID, '_b2b_pricing_value', true ) ?: '0';
		$require_approval = get_post_meta( $post->ID, '_b2b_require_approval', true ) ?: 'no';
		?>
		<table class="form-table">
			<tr>
				<th><label for="b2b_pricing_type"><?php esc_html_e( 'Pricing Type', 'wc-b2b-print-manager' ); ?></label></th>
				<td>
					<select id="b2b_pricing_type" name="b2b_pricing_type">
						<option value="percentage" <?php selected( $pricing_type, 'percentage' ); ?>>
							<?php esc_html_e( 'Percentage Discount (%)', 'wc-b2b-print-manager' ); ?>
						</option>
						<option value="fixed" <?php selected( $pricing_type, 'fixed' ); ?>>
							<?php esc_html_e( 'Fixed Custom Price', 'wc-b2b-print-manager' ); ?>
						</option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="b2b_pricing_value"><?php esc_html_e( 'Pricing Value', 'wc-b2b-print-manager' ); ?></label></th>
				<td>
					<input type="number" id="b2b_pricing_value" name="b2b_pricing_value"
						   value="<?php echo esc_attr( $pricing_value ); ?>"
						   step="0.01" min="0" class="small-text" />
					<p class="description">
						<?php esc_html_e( 'For percentage: enter 10 for 10% discount. For fixed: enter the custom price.', 'wc-b2b-print-manager' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Order Approval', 'wc-b2b-print-manager' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="b2b_require_approval" value="yes"
							<?php checked( $require_approval, 'yes' ); ?> />
						<?php esc_html_e( 'Require company admin approval before orders are processed', 'wc-b2b-print-manager' ); ?>
					</label>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save company meta box data.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function save_company_meta( int $post_id, \WP_Post $post ): void {
		// Verify nonce.
		if (
			! isset( $_POST['b2b_company_meta_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['b2b_company_meta_nonce'] ), 'b2b_company_meta_nonce' )
		) {
			return;
		}

		// Skip autosaves and revisions.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Permission check.
		if ( ! current_user_can( 'manage_companies' ) ) {
			return;
		}

		$pricing_type     = isset( $_POST['b2b_pricing_type'] ) ? sanitize_text_field( wp_unslash( $_POST['b2b_pricing_type'] ) ) : 'percentage';
		$pricing_value    = isset( $_POST['b2b_pricing_value'] ) ? floatval( $_POST['b2b_pricing_value'] ) : 0.0;
		$require_approval = isset( $_POST['b2b_require_approval'] ) ? 'yes' : 'no';

		// Validate pricing type.
		if ( ! in_array( $pricing_type, [ 'percentage', 'fixed' ], true ) ) {
			$pricing_type = 'percentage';
		}

		update_post_meta( $post_id, '_b2b_pricing_type', $pricing_type );
		update_post_meta( $post_id, '_b2b_pricing_value', $pricing_value );
		update_post_meta( $post_id, '_b2b_require_approval', $require_approval );
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Get the company ID for a given user.
	 *
	 * @param int $user_id WordPress user ID. Defaults to current user.
	 * @return int Company post ID, or 0 if not assigned.
	 */
	public static function get_user_company_id( int $user_id = 0 ): int {
		if ( 0 === $user_id ) {
			$user_id = get_current_user_id();
		}
		return (int) get_user_meta( $user_id, self::USER_META_COMPANY, true );
	}

	/**
	 * Assign a user to a company.
	 *
	 * @param int $user_id    WordPress user ID.
	 * @param int $company_id Company post ID.
	 * @return bool True on success.
	 */
	public static function assign_user_to_company( int $user_id, int $company_id ): bool {
		if ( ! get_post( $company_id ) || self::POST_TYPE !== get_post_type( $company_id ) ) {
			return false;
		}
		return (bool) update_user_meta( $user_id, self::USER_META_COMPANY, $company_id );
	}

	/**
	 * Get all users belonging to a company.
	 *
	 * @param int    $company_id Company post ID.
	 * @param string $role       Optional: filter by role ('agent', 'company_admin').
	 * @return \WP_User[]
	 */
	public static function get_company_users( int $company_id, string $role = '' ): array {
		$args = [
			'meta_key'   => self::USER_META_COMPANY, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value' => $company_id,             // phpcs:ignore WordPress.DB.SlowDBQuery
			'number'     => -1,
		];

		if ( $role ) {
			$args['role'] = $role;
		}

		return get_users( $args );
	}

	/**
	 * Get a company's pricing rule.
	 *
	 * @param int $company_id Company post ID.
	 * @return array{type: string, value: float}
	 */
	public static function get_company_pricing( int $company_id ): array {
		return [
			'type'  => get_post_meta( $company_id, '_b2b_pricing_type', true ) ?: 'percentage',
			'value' => (float) ( get_post_meta( $company_id, '_b2b_pricing_value', true ) ?: 0 ),
		];
	}

	/**
	 * Check whether order approval is required for a company.
	 *
	 * @param int $company_id Company post ID.
	 * @return bool
	 */
	public static function requires_approval( int $company_id ): bool {
		$value = get_post_meta( $company_id, '_b2b_require_approval', true );
		return 'yes' === $value;
	}

	/**
	 * Get all published companies.
	 *
	 * @return \WP_Post[]
	 */
	public static function get_all_companies(): array {
		return get_posts(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		);
	}

	/**
	 * Get a company post by ID with a type safety guard.
	 *
	 * @param int $company_id Company post ID.
	 * @return \WP_Post|null
	 */
	public static function get_company( int $company_id ): ?\WP_Post {
		$post = get_post( $company_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		return $post;
	}
}
