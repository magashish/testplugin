<?php
/**
 * Public (Front-End) Bootstrap
 *
 * Registers the custom My Account endpoint, enqueues front-end assets,
 * and handles AJAX actions that don't live in dedicated classes.
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
		// Register custom My Account endpoint.
		add_action( 'init', [ $this, 'register_endpoint' ] );
		add_filter( 'woocommerce_account_menu_items', [ $this, 'add_menu_item' ] );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', [ $this, 'render_endpoint_content' ] );

		// Front-end assets.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// AJAX: assign user to company (company admin action).
		add_action( 'wp_ajax_b2b_assign_agent', [ $this, 'ajax_assign_agent' ] );
		add_action( 'wp_ajax_b2b_remove_agent', [ $this, 'ajax_remove_agent' ] );
	}

	// -------------------------------------------------------------------------
	// Endpoint
	// -------------------------------------------------------------------------

	/**
	 * Register 'b2b-dashboard' as a WooCommerce My Account endpoint.
	 */
	public function register_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Add "B2B Dashboard" to My Account navigation.
	 *
	 * @param array $items Existing menu items.
	 * @return array
	 */
	public function add_menu_item( array $items ): array {
		if ( ! is_user_logged_in() ) {
			return $items;
		}

		$user_id = get_current_user_id();
		if ( ! \WC_B2B\Role_Manager::is_agent( $user_id ) && ! \WC_B2B\Role_Manager::is_company_admin( $user_id ) ) {
			return $items;
		}

		// Insert before 'logout'.
		$logout = $items['customer-logout'] ?? null;
		unset( $items['customer-logout'] );

		$items[ self::ENDPOINT ] = __( 'B2B Dashboard', 'wc-b2b-print-manager' );

		if ( $logout ) {
			$items['customer-logout'] = $logout;
		}

		return $items;
	}

	/**
	 * Render the dashboard endpoint content.
	 * Delegates to the Dashboard class.
	 */
	public function render_endpoint_content(): void {
		( new Dashboard() )->render();
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
				'ajax_url'   => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'b2b_artwork_nonce' ),
				'order_nonce' => wp_create_nonce( 'b2b_order_action' ),
				'i18n'       => [
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

		// Only admins can assign to companies other than their own.
		\WC_B2B\Company_Manager::assign_user_to_company( $user_id, $manager_company );

		// Ensure the user has the 'agent' role.
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

		// Security: company admin can only remove users from their own company.
		if ( $agent_company !== $manager_company ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wc-b2b-print-manager' ) ] );
		}

		delete_user_meta( $user_id, \WC_B2B\Company_Manager::USER_META_COMPANY );
		wp_send_json_success( [ 'message' => __( 'Agent removed from company.', 'wc-b2b-print-manager' ) ] );
	}
}
