<?php
/**
 * Admin Bootstrap
 *
 * Registers the top-level "B2B Print Manager" admin menu, enqueues admin
 * assets, and wires up global admin hooks (admin notices, settings page, etc.).
 *
 * @package WC_B2B\Admin
 */

declare( strict_types=1 );

namespace WC_B2B\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin
 */
class Admin {

	/**
	 * Menu/page slug.
	 */
	const MENU_SLUG = 'wc-b2b-manager';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_notices', [ $this, 'show_admin_notices' ] );

		// Settings API.
		add_action( 'admin_init', [ $this, 'register_settings' ] );

		// Assign user to company from profile page.
		add_action( 'show_user_profile', [ $this, 'render_user_company_field' ] );
		add_action( 'edit_user_profile', [ $this, 'render_user_company_field' ] );
		add_action( 'personal_options_update', [ $this, 'save_user_company_field' ] );
		add_action( 'edit_user_profile_update', [ $this, 'save_user_company_field' ] );
	}

	// -------------------------------------------------------------------------
	// Menus
	// -------------------------------------------------------------------------

	/**
	 * Register the top-level admin menu and sub-pages.
	 */
	public function register_admin_menu(): void {
		add_menu_page(
			__( 'B2B Print Manager', 'wc-b2b-print-manager' ),
			__( 'B2B Manager', 'wc-b2b-print-manager' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render_settings_page' ],
			'dashicons-building',
			56
		);

		// Companies sub-page — points to the Companies CPT list.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Companies', 'wc-b2b-print-manager' ),
			__( 'Companies', 'wc-b2b-print-manager' ),
			'manage_companies',
			'edit.php?post_type=b2b_company'
		);

		// Order approval queue.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Pending Approvals', 'wc-b2b-print-manager' ),
			__( 'Pending Approvals', 'wc-b2b-print-manager' ),
			'approve_company_orders',
			'wc-b2b-approvals',
			[ $this, 'render_approval_page' ]
		);

		// Invoice sending page.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Send Invoices', 'wc-b2b-print-manager' ),
			__( 'Send Invoices', 'wc-b2b-print-manager' ),
			'manage_companies',
			'wc-b2b-invoices',
			[ $this, 'render_invoices_page' ]
		);

		// Settings sub-page (same callback as the top-level page).
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'wc-b2b-print-manager' ),
			__( 'Settings', 'wc-b2b-print-manager' ),
			'manage_woocommerce',
			self::MENU_SLUG, // Reuse top-level slug → same page.
			[ $this, 'render_settings_page' ]
		);
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	/**
	 * Enqueue admin-only scripts and styles.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		// Load on all B2B Manager pages.
		$b2b_hooks = [
			'toplevel_page_' . self::MENU_SLUG,
			'b2b-manager_page_wc-b2b-approvals',
			'b2b-manager_page_wc-b2b-invoices',
		];

		if ( ! in_array( $hook, $b2b_hooks, true ) && ! $this->is_company_cpt_page() ) {
			return;
		}

		wp_enqueue_style(
			'wc-b2b-admin',
			WC_B2B_PLUGIN_URL . 'assets/css/admin.css',
			[],
			WC_B2B_VERSION
		);

		wp_enqueue_script(
			'wc-b2b-admin',
			WC_B2B_PLUGIN_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			WC_B2B_VERSION,
			true
		);

		wp_localize_script(
			'wc-b2b-admin',
			'wcB2BAdmin',
			[
				'ajax_url'       => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'b2b_order_action' ),
				'member_nonce'   => wp_create_nonce( 'b2b_member_management' ),
				'invoice_nonce'  => wp_create_nonce( 'b2b_invoice_nonce' ),
				'i18n'         => [
					'confirm_approve'  => __( 'Approve this order?', 'wc-b2b-print-manager' ),
					'confirm_reject'   => __( 'Reject this order?', 'wc-b2b-print-manager' ),
					'reason_prompt'    => __( 'Enter rejection reason (optional):', 'wc-b2b-print-manager' ),
					'processing'       => __( 'Processing…', 'wc-b2b-print-manager' ),
					'confirm_remove'   => __( 'Remove this user from the company?', 'wc-b2b-print-manager' ),
					'creating_user'    => __( 'Creating user…', 'wc-b2b-print-manager' ),
					'adding_user'      => __( 'Adding user…', 'wc-b2b-print-manager' ),
					'no_members'       => __( 'No users assigned to this company yet.', 'wc-b2b-print-manager' ),
				],
			]
		);
	}

	/**
	 * Helper: is the current admin page the Companies CPT screen?
	 *
	 * @return bool
	 */
	private function is_company_cpt_page(): bool {
		global $typenow;
		return 'b2b_company' === ( $typenow ?? '' );
	}

	// -------------------------------------------------------------------------
	// Settings Page
	// -------------------------------------------------------------------------

	/**
	 * Render the plugin's global settings page.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wc-b2b-print-manager' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'B2B Print Manager — Settings', 'wc-b2b-print-manager' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'wc_b2b_settings_group' );
				do_settings_sections( self::MENU_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Register Settings API fields.
	 */
	public function register_settings(): void {
		register_setting( 'wc_b2b_settings_group', 'wc_b2b_artwork_max_size_mb', 'absint' );
		register_setting( 'wc_b2b_settings_group', 'wc_b2b_allowed_file_types', 'sanitize_text_field' );
		register_setting( 'wc_b2b_settings_group', 'wc_b2b_require_approval_default', 'sanitize_text_field' );
		register_setting( 'wc_b2b_settings_group', 'wc_b2b_order_status_no_approval', 'sanitize_text_field' );

		add_settings_section(
			'wc_b2b_artwork_section',
			__( 'Artwork Upload Settings', 'wc-b2b-print-manager' ),
			'__return_empty_string',
			self::MENU_SLUG
		);

		add_settings_field(
			'wc_b2b_artwork_max_size_mb',
			__( 'Max File Size (MB)', 'wc-b2b-print-manager' ),
			[ $this, 'field_max_size' ],
			self::MENU_SLUG,
			'wc_b2b_artwork_section'
		);

		add_settings_field(
			'wc_b2b_allowed_file_types',
			__( 'Allowed File Types', 'wc-b2b-print-manager' ),
			[ $this, 'field_file_types' ],
			self::MENU_SLUG,
			'wc_b2b_artwork_section'
		);

		add_settings_section(
			'wc_b2b_orders_section',
			__( 'Order Settings', 'wc-b2b-print-manager' ),
			'__return_empty_string',
			self::MENU_SLUG
		);

		add_settings_field(
			'wc_b2b_require_approval_default',
			__( 'Default Approval Requirement', 'wc-b2b-print-manager' ),
			[ $this, 'field_require_approval' ],
			self::MENU_SLUG,
			'wc_b2b_orders_section'
		);
	}

	/** Settings field callbacks */
	public function field_max_size(): void {
		$val = absint( get_option( 'wc_b2b_artwork_max_size_mb', 20 ) );
		printf( '<input type="number" name="wc_b2b_artwork_max_size_mb" value="%d" min="1" max="200" />', $val );
	}

	public function field_file_types(): void {
		$val = esc_attr( get_option( 'wc_b2b_allowed_file_types', 'pdf,ai,eps,jpg,jpeg,png,tiff,tif' ) );
		printf( '<input type="text" name="wc_b2b_allowed_file_types" value="%s" class="regular-text" />', $val );
		echo '<p class="description">' . esc_html__( 'Comma-separated list of allowed extensions.', 'wc-b2b-print-manager' ) . '</p>';
	}

	public function field_require_approval(): void {
		$val = get_option( 'wc_b2b_require_approval_default', 'no' );
		printf(
			'<label><input type="checkbox" name="wc_b2b_require_approval_default" value="yes" %s /> %s</label>',
			checked( $val, 'yes', false ),
			esc_html__( 'Require approval for all new companies by default', 'wc-b2b-print-manager' )
		);
	}

	// -------------------------------------------------------------------------
	// Invoices Page
	// -------------------------------------------------------------------------

	/**
	 * Render the "Send Invoices" admin page.
	 * Lists all companies with a shared month/year selector and a per-company
	 * "Send" button plus "Send to All" for the selected period.
	 */
	public function render_invoices_page(): void {
		if ( ! current_user_can( 'manage_companies' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wc-b2b-print-manager' ) );
		}

		$companies = \WC_B2B\Company_Manager::get_all_companies();

		// Default period: last month.
		$last_month_ts = strtotime( 'first day of last month' );
		$default_month = (int) date( 'n', $last_month_ts );
		$default_year  = (int) date( 'Y', $last_month_ts );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Send Monthly Invoices', 'wc-b2b-print-manager' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Select a billing period and send an invoice email to the company admin(s) of each company.', 'wc-b2b-print-manager' ); ?>
			</p>

			<div style="display:flex;align-items:center;gap:16px;margin:20px 0;padding:16px 20px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;flex-wrap:wrap">
				<label style="font-weight:600"><?php esc_html_e( 'Billing Period:', 'wc-b2b-print-manager' ); ?></label>

				<select id="b2b-bulk-invoice-month">
					<?php for ( $m = 1; $m <= 12; $m++ ) : ?>
						<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $default_month, $m ); ?>>
							<?php echo esc_html( date_i18n( 'F', mktime( 0, 0, 0, $m, 1 ) ) ); ?>
						</option>
					<?php endfor; ?>
				</select>

				<input type="number" id="b2b-bulk-invoice-year"
					   value="<?php echo esc_attr( $default_year ); ?>"
					   min="2020" max="<?php echo esc_attr( (string) ( $default_year + 1 ) ); ?>"
					   style="width:80px" />

				<button type="button" class="button button-primary" id="b2b-send-all-invoices">
					<?php esc_html_e( 'Send to All Companies', 'wc-b2b-print-manager' ); ?>
				</button>

				<span id="b2b-bulk-invoice-status" style="font-size:13px"></span>
			</div>

			<?php if ( empty( $companies ) ) : ?>
				<p><?php esc_html_e( 'No companies found.', 'wc-b2b-print-manager' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Company', 'wc-b2b-print-manager' ); ?></th>
							<th><?php esc_html_e( 'Company Admins', 'wc-b2b-print-manager' ); ?></th>
							<th><?php esc_html_e( 'Last Invoice Sent', 'wc-b2b-print-manager' ); ?></th>
							<th><?php esc_html_e( 'Last Invoice Period', 'wc-b2b-print-manager' ); ?></th>
							<th style="width:160px"><?php esc_html_e( 'Action', 'wc-b2b-print-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $companies as $company ) :
							$last_sent   = get_post_meta( $company->ID, '_b2b_last_invoice_sent', true );
							$last_period = get_post_meta( $company->ID, '_b2b_last_invoice_period', true );
							$members     = \WC_B2B\Company_Manager::get_company_users( $company->ID );
							$admins      = array_filter( $members, fn( $u ) => \WC_B2B\Role_Manager::is_company_admin( $u->ID ) );
							$admin_names = implode( ', ', array_map( fn( $u ) => $u->display_name, $admins ) );
						?>
							<tr data-company-id="<?php echo esc_attr( $company->ID ); ?>">
								<td>
									<a href="<?php echo esc_url( get_edit_post_link( $company->ID ) ); ?>">
										<?php echo esc_html( $company->post_title ); ?>
									</a>
								</td>
								<td><?php echo $admin_names ? esc_html( $admin_names ) : '<em style="color:#9ca3af">' . esc_html__( 'None', 'wc-b2b-print-manager' ) . '</em>'; ?></td>
								<td>
									<?php echo $last_sent
										? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $last_sent ) ) )
										: '—'; ?>
								</td>
								<td>
									<?php echo $last_period
										? esc_html( date_i18n( 'F Y', strtotime( $last_period . '-01' ) ) )
										: '—'; ?>
								</td>
								<td>
									<button type="button"
											class="button b2b-send-invoice-row"
											data-company="<?php echo esc_attr( $company->ID ); ?>">
										<?php esc_html_e( 'Send Invoice', 'wc-b2b-print-manager' ); ?>
									</button>
									<span class="b2b-row-invoice-status" style="display:block;font-size:12px;margin-top:4px"></span>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Approval Queue Page
	// -------------------------------------------------------------------------

	/**
	 * Render the pending order approvals admin page.
	 */
	public function render_approval_page(): void {
		if ( ! current_user_can( 'approve_company_orders' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wc-b2b-print-manager' ) );
		}

		$user_id    = get_current_user_id();
		$company_id = current_user_can( 'administrator' ) ? 0 : \WC_B2B\Company_Manager::get_user_company_id( $user_id );

		if ( $company_id ) {
			$orders = \WC_B2B\Order_Controller::get_pending_orders_for_company( $company_id );
		} else {
			// Super admin — show all pending approvals.
			$orders = wc_get_orders(
				[
					'status' => [ 'wc-pending-approval' ],
					'limit'  => -1,
				]
			);
		}

		include WC_B2B_PLUGIN_DIR . 'admin/views/order-approval.php';
	}

	// -------------------------------------------------------------------------
	// Admin Notices
	// -------------------------------------------------------------------------

	/**
	 * Show pending-approval count badge in admin notices.
	 */
	public function show_admin_notices(): void {
		if ( ! current_user_can( 'approve_company_orders' ) ) {
			return;
		}

		$user_id    = get_current_user_id();
		$company_id = current_user_can( 'administrator' ) ? 0 : \WC_B2B\Company_Manager::get_user_company_id( $user_id );

		if ( $company_id ) {
			$count = count( \WC_B2B\Order_Controller::get_pending_orders_for_company( $company_id ) );
		} else {
			$count = (int) wc_orders_count( 'pending-approval' );
		}

		if ( $count > 0 ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				sprintf(
					/* translators: 1: count, 2: link */
					esc_html(
						_n(
							'%1$d order is awaiting approval. %2$s',
							'%1$d orders are awaiting approval. %2$s',
							$count,
							'wc-b2b-print-manager'
						)
					),
					$count,
					'<a href="' . esc_url( admin_url( 'admin.php?page=wc-b2b-approvals' ) ) . '">'
					. esc_html__( 'Review now', 'wc-b2b-print-manager' )
					. '</a>'
				)
			);
		}
	}

	// -------------------------------------------------------------------------
	// User Profile — Company Assignment
	// -------------------------------------------------------------------------

	/**
	 * Add company selection field to WP user profile.
	 *
	 * @param \WP_User $user Current user being edited.
	 */
	public function render_user_company_field( \WP_User $user ): void {
		if ( ! current_user_can( 'manage_companies' ) && ! current_user_can( 'manage_company_agents' ) ) {
			return;
		}

		$current_company = \WC_B2B\Company_Manager::get_user_company_id( $user->ID );
		$companies       = \WC_B2B\Company_Manager::get_all_companies();
		?>
		<h2><?php esc_html_e( 'B2B Company Assignment', 'wc-b2b-print-manager' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Assigned Company', 'wc-b2b-print-manager' ); ?></th>
				<td>
					<?php wp_nonce_field( 'b2b_user_company_nonce', 'b2b_user_company_nonce' ); ?>
					<select name="b2b_company_id">
						<option value="0"><?php esc_html_e( '— No Company —', 'wc-b2b-print-manager' ); ?></option>
						<?php foreach ( $companies as $company ) : ?>
							<option value="<?php echo esc_attr( $company->ID ); ?>" <?php selected( $current_company, $company->ID ); ?>>
								<?php echo esc_html( $company->post_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save company selection from user profile form.
	 *
	 * @param int $user_id User ID being saved.
	 */
	public function save_user_company_field( int $user_id ): void {
		if (
			! isset( $_POST['b2b_user_company_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['b2b_user_company_nonce'] ), 'b2b_user_company_nonce' )
		) {
			return;
		}

		if ( ! current_user_can( 'manage_companies' ) && ! current_user_can( 'manage_company_agents' ) ) {
			return;
		}

		$company_id = (int) sanitize_text_field( wp_unslash( $_POST['b2b_company_id'] ?? '' ) );

		if ( $company_id ) {
			\WC_B2B\Company_Manager::assign_user_to_company( $user_id, $company_id );
		} else {
			delete_user_meta( $user_id, \WC_B2B\Company_Manager::USER_META_COMPANY );
		}
	}
}
