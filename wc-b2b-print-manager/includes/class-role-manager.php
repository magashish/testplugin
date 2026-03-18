<?php
/**
 * Role Manager
 *
 * Handles all capability and permission logic:
 *   – Order visibility restrictions (agents see own, company_admin sees company's).
 *   – User admin table scoping (company_admin can only see their own agents).
 *   – Capability mapping helpers used across the plugin.
 *
 * @package WC_B2B\Includes
 */

declare( strict_types=1 );

namespace WC_B2B;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Role_Manager
 */
class Role_Manager {

	/**
	 * Register all hooks.
	 */
	public function __construct() {
		// Restrict the WP admin Users list for company admins.
		add_action( 'pre_get_users', [ $this, 'scope_users_to_company' ] );

		// Prevent agents/company_admins from accessing irrelevant admin pages.
		add_action( 'admin_init', [ $this, 'restrict_admin_access' ] );
	}

	// -------------------------------------------------------------------------
	// Access Control
	// -------------------------------------------------------------------------

	/**
	 * Scope the Users list table so company admins only see their own agents.
	 *
	 * @param \WP_User_Query $query User query object.
	 */
	public function scope_users_to_company( \WP_User_Query $query ): void {
		if ( ! is_admin() || current_user_can( 'administrator' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_company_agents' ) ) {
			return;
		}

		$company_id = Company_Manager::get_user_company_id();
		if ( ! $company_id ) {
			return;
		}

		// Append a meta query to limit results to same company.
		$meta_query   = (array) $query->get( 'meta_query' );
		$meta_query[] = [
			'key'   => Company_Manager::USER_META_COMPANY,
			'value' => $company_id,
		];
		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Redirect non-admin roles away from areas they should not access.
	 */
	public function restrict_admin_access(): void {
		if ( current_user_can( 'administrator' ) ) {
			return;
		}

		$user = wp_get_current_user();
		if ( empty( $user->roles ) ) {
			return;
		}

		$role = $user->roles[0] ?? '';

		// Agents get access to very limited pages.
		if ( 'agent' === $role ) {
			$allowed_pages = [ 'admin-ajax.php', 'profile.php' ];
			$current_page  = basename( $_SERVER['PHP_SELF'] ?? '' );

			if ( ! in_array( $current_page, $allowed_pages, true ) ) {
				wp_safe_redirect( wc_get_account_endpoint_url( 'b2b-dashboard' ) );
				exit;
			}
		}
	}

	// -------------------------------------------------------------------------
	// Capability Helpers (static — used everywhere)
	// -------------------------------------------------------------------------

	/**
	 * Check if the current (or given) user can view a specific order.
	 *
	 * Rules:
	 *   - Administrator: always yes.
	 *   - company_admin: yes if the order's customer belongs to same company.
	 *   - agent: yes only if the order belongs to them.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @param int $user_id  Optional. Defaults to current user.
	 * @return bool
	 */
	public static function can_view_order( int $order_id, int $user_id = 0 ): bool {
		if ( 0 === $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( user_can( $user_id, 'administrator' ) ) {
			return true;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$order_customer_id = (int) $order->get_customer_id();

		if ( user_can( $user_id, 'view_company_orders' ) ) {
			// Company admin — must share the same company.
			$admin_company    = Company_Manager::get_user_company_id( $user_id );
			$customer_company = Company_Manager::get_user_company_id( $order_customer_id );
			return $admin_company && $admin_company === $customer_company;
		}

		if ( user_can( $user_id, 'view_own_orders' ) ) {
			return $order_customer_id === $user_id;
		}

		return false;
	}

	/**
	 * Check if a user is a Super Admin (WordPress administrator).
	 *
	 * @param int $user_id Optional.
	 * @return bool
	 */
	public static function is_super_admin( int $user_id = 0 ): bool {
		if ( 0 === $user_id ) {
			$user_id = get_current_user_id();
		}
		return user_can( $user_id, 'administrator' );
	}

	/**
	 * Check if a user is a Company Admin.
	 *
	 * @param int $user_id Optional.
	 * @return bool
	 */
	public static function is_company_admin( int $user_id = 0 ): bool {
		if ( 0 === $user_id ) {
			$user_id = get_current_user_id();
		}
		return user_can( $user_id, 'manage_company_agents' );
	}

	/**
	 * Check if a user is an Agent.
	 *
	 * @param int $user_id Optional.
	 * @return bool
	 */
	public static function is_agent( int $user_id = 0 ): bool {
		if ( 0 === $user_id ) {
			$user_id = get_current_user_id();
		}
		return user_can( $user_id, 'view_own_orders' ) && ! self::is_company_admin( $user_id );
	}

	/**
	 * Return a human-readable role label for a user.
	 *
	 * @param int $user_id Optional.
	 * @return string
	 */
	public static function get_role_label( int $user_id = 0 ): string {
		if ( self::is_super_admin( $user_id ) ) {
			return __( 'Super Admin', 'wc-b2b-print-manager' );
		}
		if ( self::is_company_admin( $user_id ) ) {
			return __( 'Company Admin', 'wc-b2b-print-manager' );
		}
		if ( self::is_agent( $user_id ) ) {
			return __( 'Agent', 'wc-b2b-print-manager' );
		}
		return __( 'Unknown', 'wc-b2b-print-manager' );
	}
}
