<?php
/**
 * Public (Front-End) Bootstrap
 *
 * Responsibilities:
 *   1. Register 'b2b-dashboard' as a WooCommerce My Account endpoint.
 *   2. Auto-redirect company_admin / agent from the generic WC dashboard
 *      to /my-account/b2b-dashboard/ on every login.
 *   3. Strip irrelevant WC menu items (Downloads, Addresses, bare Dashboard)
 *      for B2B roles and replace with a single "Company Portal" entry.
 *   4. Override the bare My Account page title with the company name.
 *   5. Flush rewrite rules once after the endpoint is registered (fixes 404).
 *
 * @package WC_B2B\Frontend
 */

declare( strict_types=1 );

namespace WC_B2B\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class B2B_Public
 */
class B2B_Public {

	/**
	 * My Account endpoint slug.
	 */
	const ENDPOINT = 'b2b-dashboard';

	/**
	 * Register all front-end hooks.
	 */
	public function __construct() {
		// Endpoint registration + 404 fix.
		add_action( 'init', [ $this, 'register_endpoint' ] );
		add_action( 'init', [ $this, 'maybe_flush_rewrite_rules' ], 20 );

		// Auto-redirect B2B users away from the generic WC dashboard.
		add_action( 'template_redirect', [ $this, 'redirect_b2b_users_to_portal' ] );

		// My Account navigation.
		add_filter( 'woocommerce_account_menu_items', [ $this, 'customize_account_menu' ] );

		// Render the endpoint content.
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', [ $this, 'render_endpoint_content' ] );

		// Override the generic WC dashboard text (fallback if redirect doesn't fire).
		add_action( 'woocommerce_account_dashboard', [ $this, 'override_account_dashboard' ] );

		// Replace "My account" page title with the company name for B2B users.
		add_filter( 'the_title', [ $this, 'customize_account_page_title' ], 10, 2 );

		// Front-end assets.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// AJAX: agent management from the front-end dashboard.
		add_action( 'wp_ajax_b2b_assign_agent', [ $this, 'ajax_assign_agent' ] );
		add_action( 'wp_ajax_b2b_remove_agent', [ $this, 'ajax_remove_agent' ] );
	}

	// -------------------------------------------------------------------------
	// Endpoint + 404 fix
	// -------------------------------------------------------------------------

	/**
	 * Register 'b2b-dashboard' as a WooCommerce My Account endpoint.
	 * Must fire on 'init' so WordPress registers the rewrite rule.
	 */
	public function register_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Flush rewrite rules once per plugin version so the custom endpoint
	 * is immediately accessible without a manual Settings > Permalinks save.
	 *
	 * Stored in a versioned option; re-runs on plugin update automatically.
	 */
	public function maybe_flush_rewrite_rules(): void {
		if ( get_option( 'wc_b2b_flushed_rewrites' ) !== WC_B2B_VERSION ) {
			flush_rewrite_rules( false ); // false = don't regenerate .htaccess (faster).
			update_option( 'wc_b2b_flushed_rewrites', WC_B2B_VERSION );
		}
	}

	// -------------------------------------------------------------------------
	// Auto-redirect
	// -------------------------------------------------------------------------

	/**
	 * Redirect company_admin and agent users from the bare /my-account/ page
	 * directly to /my-account/b2b-dashboard/.
	 *
	 * Only fires on the plain account page — NOT on sub-endpoints like
	 * /my-account/orders/ or /my-account/b2b-dashboard/ itself.
	 */
	public function redirect_b2b_users_to_portal(): void {
		if ( ! is_user_logged_in() || ! is_account_page() ) {
			return;
		}

		global $wp;

		// Already on the B2B dashboard — do nothing.
		if ( isset( $wp->query_vars[ self::ENDPOINT ] ) ) {
			return;
		}

		// On a different WC sub-endpoint (orders, edit-account, etc.) — do nothing.
		if ( is_wc_endpoint_url() ) {
			return;
		}

		// We are on the bare /my-account/ dashboard page.
		$user_id = get_current_user_id();
		if (
			\WC_B2B\Role_Manager::is_agent( $user_id ) ||
			\WC_B2B\Role_Manager::is_company_admin( $user_id )
		) {
			wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
			exit;
		}
	}

	// -------------------------------------------------------------------------
	// My Account menu
	// -------------------------------------------------------------------------

	/**
	 * Rebuild the My Account nav for B2B roles.
	 *
	 * Company Admin menu: Company Portal | Account Details | Log Out
	 * Agent menu:         My Dashboard   | Account Details | Log Out
	 *
	 * Removed for B2B users: Dashboard (bare), Orders (handled in portal),
	 * Downloads, Addresses.
	 *
	 * @param array $items WooCommerce default menu items.
	 * @return array
	 */
	public function customize_account_menu( array $items ): array {
		if ( ! is_user_logged_in() ) {
			return $items;
		}

		$user_id     = get_current_user_id();
		$is_b2b      = \WC_B2B\Role_Manager::is_agent( $user_id ) || \WC_B2B\Role_Manager::is_company_admin( $user_id );
		$is_ca       = \WC_B2B\Role_Manager::is_company_admin( $user_id );

		if ( ! $is_b2b ) {
			return $items; // Regular customers keep the standard menu.
		}

		// Items to always remove for B2B users.
		$remove = [ 'dashboard', 'downloads', 'edit-address', 'orders' ];
		foreach ( $remove as $key ) {
			unset( $items[ $key ] );
		}

		// Build the clean B2B menu: portal first, account details, then logout.
		$logout = $items['customer-logout'] ?? null;
		unset( $items['customer-logout'] );

		$portal_label            = $is_ca
			? __( 'Company Portal', 'wc-b2b-print-manager' )
			: __( 'My Dashboard', 'wc-b2b-print-manager' );
		$items[ self::ENDPOINT ] = $portal_label;

		// Keep Account Details so users can update password/email.
		// (Already in $items after the removals above — just ensure it's last.)
		if ( isset( $items['edit-account'] ) ) {
			$account = $items['edit-account'];
			unset( $items['edit-account'] );
			$items['edit-account'] = $account;
		}

		if ( $logout ) {
			$items['customer-logout'] = $logout;
		}

		return $items;
	}

	// -------------------------------------------------------------------------
	// Endpoint content
	// -------------------------------------------------------------------------

	/**
	 * Render the B2B Dashboard when the /my-account/b2b-dashboard/ endpoint fires.
	 */
	public function render_endpoint_content(): void {
		( new Dashboard() )->render();
	}

	// -------------------------------------------------------------------------
	// Override generic WC dashboard (fallback)
	// -------------------------------------------------------------------------

	/**
	 * Replace the generic WooCommerce "From your account dashboard…" text
	 * with a branded redirect notice for B2B users.
	 * This fires on the bare /my-account/ page — normally the redirect above
	 * handles it, but this is a graceful fallback.
	 */
	public function override_account_dashboard(): void {
		$user_id = get_current_user_id();
		if ( ! \WC_B2B\Role_Manager::is_agent( $user_id ) && ! \WC_B2B\Role_Manager::is_company_admin( $user_id ) ) {
			return;
		}

		$company_id   = \WC_B2B\Company_Manager::get_user_company_id( $user_id );
		$company_name = $company_id ? get_the_title( $company_id ) : '';
		$portal_url   = wc_get_account_endpoint_url( self::ENDPOINT );
		?>
		<div class="b2b-portal-redirect-notice">
			<?php if ( $company_name ) : ?>
				<p class="b2b-welcome-line">
					<?php
					printf(
						/* translators: %s: company name */
						esc_html__( 'Welcome to the %s portal.', 'wc-b2b-print-manager' ),
						'<strong>' . esc_html( $company_name ) . '</strong>'
					);
					?>
				</p>
			<?php endif; ?>
			<p>
				<a href="<?php echo esc_url( $portal_url ); ?>" class="button wc-forward">
					<?php esc_html_e( 'Go to Company Portal →', 'wc-b2b-print-manager' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Page title
	// -------------------------------------------------------------------------

	/**
	 * Replace the generic "My account" page title with the company name
	 * (or role-appropriate label) for B2B users viewing My Account.
	 *
	 * @param string $title   Current title.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public function customize_account_page_title( string $title, int $post_id ): string {
		if ( ! is_account_page() || ! is_user_logged_in() ) {
			return $title;
		}

		// Only change the title of the My Account page post itself.
		$account_page_id = wc_get_page_id( 'myaccount' );
		if ( $post_id !== $account_page_id ) {
			return $title;
		}

		$user_id = get_current_user_id();
		if ( ! \WC_B2B\Role_Manager::is_agent( $user_id ) && ! \WC_B2B\Role_Manager::is_company_admin( $user_id ) ) {
			return $title;
		}

		$company_id = \WC_B2B\Company_Manager::get_user_company_id( $user_id );
		if ( $company_id ) {
			return get_the_title( $company_id ) . ' &mdash; ' . __( 'Portal', 'wc-b2b-print-manager' );
		}

		return \WC_B2B\Role_Manager::is_company_admin( $user_id )
			? __( 'Company Portal', 'wc-b2b-print-manager' )
			: __( 'My Dashboard', 'wc-b2b-print-manager' );
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	/**
	 * Enqueue public CSS and JS on B2B-relevant pages.
	 */
	public function enqueue_assets(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! \WC_B2B\Role_Manager::is_agent( $user_id ) && ! \WC_B2B\Role_Manager::is_company_admin( $user_id ) ) {
			return;
		}

		wp_enqueue_style(
			'wc-b2b-public',
			WC_B2B_PLUGIN_URL . 'assets/css/public.css',
			[],
			WC_B2B_VERSION
		);

		wp_enqueue_script(
			'wc-b2b-public',
			WC_B2B_PLUGIN_URL . 'assets/js/public.js',
			[ 'jquery' ],
			WC_B2B_VERSION,
			true
		);

		wp_localize_script(
			'wc-b2b-public',
			'wcB2BPublic',
			[
				'ajax_url'    => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'b2b_artwork_nonce' ),
				'order_nonce' => wp_create_nonce( 'b2b_order_action' ),
				'shop_url'    => get_permalink( wc_get_page_id( 'shop' ) ),
				'i18n'        => [
					'confirm_delete' => __( 'Delete this artwork from your library?', 'wc-b2b-print-manager' ),
					'uploading'      => __( 'Uploading…', 'wc-b2b-print-manager' ),
					'confirm_reject' => __( 'Reject this order?', 'wc-b2b-print-manager' ),
					'reason_prompt'  => __( 'Enter a rejection reason (optional):', 'wc-b2b-print-manager' ),
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// AJAX: Agent Management (Company Admin)
	// -------------------------------------------------------------------------

	/**
	 * AJAX: assign an existing WP user as an agent in the company admin's company.
	 *
	 * POST: user_id, _nonce
	 */
	public function ajax_assign_agent(): void {
		check_ajax_referer( 'b2b_manage_agents', '_nonce' );

		if ( ! current_user_can( 'manage_company_agents' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wc-b2b-print-manager' ) ] );
		}

		$manager_company = \WC_B2B\Company_Manager::get_user_company_id();
		$user_id         = (int) sanitize_text_field( wp_unslash( $_POST['user_id'] ?? '' ) );

		if ( ! $user_id || ! get_user_by( 'id', $user_id ) ) {
			wp_send_json_error( [ 'message' => __( 'User not found.', 'wc-b2b-print-manager' ) ] );
		}

		\WC_B2B\Company_Manager::assign_user_to_company( $user_id, $manager_company );

		$user = new \WP_User( $user_id );
		if ( ! in_array( 'agent', (array) $user->roles, true ) && ! \WC_B2B\Role_Manager::is_company_admin( $user_id ) ) {
			$user->set_role( 'agent' );
		}

		wp_send_json_success( [ 'message' => __( 'Agent assigned successfully.', 'wc-b2b-print-manager' ) ] );
	}

	/**
	 * AJAX: remove an agent from the company admin's company.
	 *
	 * POST: user_id, _nonce
	 */
	public function ajax_remove_agent(): void {
		check_ajax_referer( 'b2b_manage_agents', '_nonce' );

		if ( ! current_user_can( 'manage_company_agents' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wc-b2b-print-manager' ) ] );
		}

		$manager_company = \WC_B2B\Company_Manager::get_user_company_id();
		$user_id         = (int) sanitize_text_field( wp_unslash( $_POST['user_id'] ?? '' ) );
		$agent_company   = \WC_B2B\Company_Manager::get_user_company_id( $user_id );

		if ( $agent_company !== $manager_company ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wc-b2b-print-manager' ) ] );
		}

		delete_user_meta( $user_id, \WC_B2B\Company_Manager::USER_META_COMPANY );
		wp_send_json_success( [ 'message' => __( 'Agent removed from company.', 'wc-b2b-print-manager' ) ] );
	}
}
