<?php
/**
 * Company Admin
 *
 * Admin-side company management:
 *   - Company list table columns (logo thumbnail, member count, pricing type).
 *   - Per-product pricing override management (AJAX save).
 *   - User assignment table on company edit screen.
 *
 * @package WC_B2B\Admin
 */

declare( strict_types=1 );

namespace WC_B2B\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Company_Admin
 */
class Company_Admin {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		// Custom columns on the Companies list table.
		add_filter( 'manage_b2b_company_posts_columns', [ $this, 'add_list_columns' ] );
		add_action( 'manage_b2b_company_posts_custom_column', [ $this, 'render_list_column' ], 10, 2 );

		// Per-product pricing meta box on company edit screen.
		add_action( 'add_meta_boxes_b2b_company', [ $this, 'add_pricing_meta_box' ] );
		add_action( 'wp_ajax_b2b_save_product_pricing', [ $this, 'ajax_save_product_pricing' ] );
		add_action( 'wp_ajax_b2b_get_product_pricings', [ $this, 'ajax_get_product_pricings' ] );

		// Show company members on edit screen.
		add_action( 'add_meta_boxes_b2b_company', [ $this, 'add_members_meta_box' ] );
	}

	// -------------------------------------------------------------------------
	// List Table Columns
	// -------------------------------------------------------------------------

	/**
	 * Define custom columns for the Companies list table.
	 *
	 * @param array $columns Default columns.
	 * @return array
	 */
	public function add_list_columns( array $columns ): array {
		$new = [];
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['b2b_logo']     = __( 'Logo', 'wc-b2b-print-manager' );
				$new['b2b_pricing']  = __( 'Pricing Rule', 'wc-b2b-print-manager' );
				$new['b2b_approval'] = __( 'Approval', 'wc-b2b-print-manager' );
				$new['b2b_members']  = __( 'Members', 'wc-b2b-print-manager' );
			}
		}
		return $new;
	}

	/**
	 * Render custom column values.
	 *
	 * @param string $column  Column slug.
	 * @param int    $post_id Company post ID.
	 */
	public function render_list_column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'b2b_logo':
				$thumb = get_the_post_thumbnail( $post_id, [ 50, 50 ] );
				echo $thumb ?: '<span aria-label="' . esc_attr__( 'No logo', 'wc-b2b-print-manager' ) . '">—</span>';
				break;

			case 'b2b_pricing':
				$rule = \WC_B2B\Company_Manager::get_company_pricing( $post_id );
				if ( 0.0 === $rule['value'] ) {
					esc_html_e( 'No rule', 'wc-b2b-print-manager' );
					break;
				}
				if ( 'percentage' === $rule['type'] ) {
					printf( esc_html__( '%s%% discount', 'wc-b2b-print-manager' ), esc_html( (string) $rule['value'] ) );
				} else {
					printf(
						esc_html__( 'Fixed price: %s', 'wc-b2b-print-manager' ),
						esc_html( wc_price( $rule['value'] ) )
					);
				}
				break;

			case 'b2b_approval':
				echo \WC_B2B\Company_Manager::requires_approval( $post_id )
					? '<span class="b2b-badge b2b-badge--warning">' . esc_html__( 'Required', 'wc-b2b-print-manager' ) . '</span>'
					: '<span class="b2b-badge b2b-badge--success">' . esc_html__( 'Auto-approve', 'wc-b2b-print-manager' ) . '</span>';
				break;

			case 'b2b_members':
				$count = count( \WC_B2B\Company_Manager::get_company_users( $post_id ) );
				echo esc_html( (string) $count );
				break;
		}
	}

	// -------------------------------------------------------------------------
	// Per-product Pricing Meta Box
	// -------------------------------------------------------------------------

	/**
	 * Add product-level pricing override meta box.
	 */
	public function add_pricing_meta_box(): void {
		add_meta_box(
			'b2b_product_pricing',
			__( 'Per-Product Pricing Overrides', 'wc-b2b-print-manager' ),
			[ $this, 'render_pricing_meta_box' ],
			\WC_B2B\Company_Manager::POST_TYPE,
			'normal',
			'default'
		);
	}

	/**
	 * Render per-product pricing meta box.
	 *
	 * @param \WP_Post $post Company post.
	 */
	public function render_pricing_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'b2b_product_pricing_nonce', 'b2b_product_pricing_nonce' );
		?>
		<div id="b2b-product-pricing" data-company="<?php echo esc_attr( $post->ID ); ?>">
			<p>
				<?php esc_html_e( 'Add per-product pricing rules. These override the company-wide pricing setting above.', 'wc-b2b-print-manager' ); ?>
			</p>

			<div class="b2b-add-rule">
				<label><?php esc_html_e( 'Product:', 'wc-b2b-print-manager' ); ?></label>
				<input type="text" id="b2b-product-search" class="wc-product-search" style="min-width:300px;"
					   data-placeholder="<?php esc_attr_e( 'Search for a product…', 'wc-b2b-print-manager' ); ?>"
					   data-action="woocommerce_json_search_products" />

				<label><?php esc_html_e( 'Type:', 'wc-b2b-print-manager' ); ?></label>
				<select id="b2b-pricing-type-product">
					<option value="percentage"><?php esc_html_e( 'Percentage Discount (%)', 'wc-b2b-print-manager' ); ?></option>
					<option value="fixed"><?php esc_html_e( 'Fixed Price', 'wc-b2b-print-manager' ); ?></option>
				</select>

				<label><?php esc_html_e( 'Value:', 'wc-b2b-print-manager' ); ?></label>
				<input type="number" id="b2b-pricing-value-product" step="0.01" min="0" style="width:80px;" />

				<button type="button" class="button" id="b2b-add-product-rule">
					<?php esc_html_e( 'Add Rule', 'wc-b2b-print-manager' ); ?>
				</button>
			</div>

			<table class="widefat striped" id="b2b-product-rules-table" style="margin-top:12px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Product', 'wc-b2b-print-manager' ); ?></th>
						<th><?php esc_html_e( 'Type', 'wc-b2b-print-manager' ); ?></th>
						<th><?php esc_html_e( 'Value', 'wc-b2b-print-manager' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'wc-b2b-print-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr class="b2b-loading-row"><td colspan="4"><?php esc_html_e( 'Loading…', 'wc-b2b-print-manager' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * AJAX: save a per-product pricing rule.
	 */
	public function ajax_save_product_pricing(): void {
		if (
			! isset( $_POST['_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['_nonce'] ), 'b2b_product_pricing_nonce' )
		) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'wc-b2b-print-manager' ) ] );
		}

		if ( ! current_user_can( 'manage_companies' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wc-b2b-print-manager' ) ] );
		}

		$company_id    = (int) sanitize_text_field( wp_unslash( $_POST['company_id'] ?? '' ) );
		$product_id    = (int) sanitize_text_field( wp_unslash( $_POST['product_id'] ?? '' ) );
		$pricing_type  = sanitize_text_field( wp_unslash( $_POST['pricing_type'] ?? '' ) );
		$pricing_value = (float) sanitize_text_field( wp_unslash( $_POST['pricing_value'] ?? '' ) );

		$saved = \WC_B2B\Pricing_Engine::save_pricing_override( $company_id, $product_id, $pricing_type, $pricing_value );

		if ( $saved ) {
			wp_send_json_success( [ 'message' => __( 'Pricing rule saved.', 'wc-b2b-print-manager' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Failed to save rule.', 'wc-b2b-print-manager' ) ] );
		}
	}

	/**
	 * AJAX: retrieve per-product pricing rules for a company.
	 */
	public function ajax_get_product_pricings(): void {
		check_ajax_referer( 'b2b_product_pricing_nonce', '_nonce' );

		if ( ! current_user_can( 'manage_companies' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wc-b2b-print-manager' ) ] );
		}

		$company_id = (int) sanitize_text_field( wp_unslash( $_GET['company_id'] ?? '' ) );

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$rules = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}b2b_company_pricing
				 WHERE company_id = %d AND product_id > 0
				 ORDER BY id ASC",
				$company_id
			)
		);
		// phpcs:enable

		$data = array_map(
			function ( $row ) {
				$product = wc_get_product( $row->product_id );
				return [
					'id'            => (int) $row->id,
					'product_id'    => (int) $row->product_id,
					'product_name'  => $product ? $product->get_name() : __( '(Deleted)', 'wc-b2b-print-manager' ),
					'pricing_type'  => $row->pricing_type,
					'pricing_value' => (float) $row->pricing_value,
				];
			},
			$rules
		);

		wp_send_json_success( $data );
	}

	// -------------------------------------------------------------------------
	// Members Meta Box
	// -------------------------------------------------------------------------

	/**
	 * Add a company members list meta box.
	 */
	public function add_members_meta_box(): void {
		add_meta_box(
			'b2b_company_members',
			__( 'Company Members', 'wc-b2b-print-manager' ),
			[ $this, 'render_members_meta_box' ],
			\WC_B2B\Company_Manager::POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * Render the company members list.
	 *
	 * @param \WP_Post $post Company post.
	 */
	public function render_members_meta_box( \WP_Post $post ): void {
		$members = \WC_B2B\Company_Manager::get_company_users( $post->ID );

		if ( empty( $members ) ) {
			echo '<p>' . esc_html__( 'No users assigned to this company yet.', 'wc-b2b-print-manager' ) . '</p>';
			return;
		}

		echo '<ul class="b2b-member-list">';
		foreach ( $members as $member ) {
			printf(
				'<li><a href="%s">%s</a> <span class="b2b-role-badge">%s</span></li>',
				esc_url( get_edit_user_link( $member->ID ) ),
				esc_html( $member->display_name ),
				esc_html( \WC_B2B\Role_Manager::get_role_label( $member->ID ) )
			);
		}
		echo '</ul>';
		printf(
			'<p><a href="%s" class="button button-small">%s</a></p>',
			esc_url( admin_url( 'users.php' ) ),
			esc_html__( 'Manage Users', 'wc-b2b-print-manager' )
		);
	}
}
