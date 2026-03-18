<?php
/**
 * Order Controller
 *
 * Manages:
 *   - Custom order status: "pending-approval"
 *   - Order visibility restrictions (agent / company_admin / admin)
 *   - Order approval / rejection AJAX handlers
 *   - My Account orders query scoping
 *
 * @package WC_B2B\Includes
 */

declare( strict_types=1 );

namespace WC_B2B;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Order_Controller
 */
class Order_Controller {

	/**
	 * Custom order status slug (without 'wc-' prefix for registration).
	 */
	const PENDING_APPROVAL_STATUS = 'pending-approval';

	/**
	 * Wire up all hooks.
	 */
	public function __construct() {
		// Register custom status.
		add_action( 'init', [ $this, 'register_order_status' ] );
		add_filter( 'wc_order_statuses', [ $this, 'add_order_status_to_list' ] );

		// Scope order queries for non-admins.
		add_action( 'woocommerce_my_account_my_orders_query', [ $this, 'scope_my_account_orders' ] );
		add_filter( 'woocommerce_orders_table_query_clauses', [ $this, 'scope_admin_orders_query' ], 10, 3 );

		// AJAX: approve / reject order (company admin action).
		add_action( 'wp_ajax_b2b_approve_order', [ $this, 'ajax_approve_order' ] );
		add_action( 'wp_ajax_b2b_reject_order', [ $this, 'ajax_reject_order' ] );

		// Bulk action in WC admin orders table.
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', [ $this, 'add_bulk_approve_action' ] );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', [ $this, 'handle_bulk_approve_action' ], 10, 3 );
	}

	// -------------------------------------------------------------------------
	// Custom Status
	// -------------------------------------------------------------------------

	/**
	 * Register 'pending-approval' as a WooCommerce order status.
	 */
	public function register_order_status(): void {
		register_post_status(
			'wc-' . self::PENDING_APPROVAL_STATUS,
			[
				'label'                     => _x( 'Pending Approval', 'Order status', 'wc-b2b-print-manager' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: order count */
				'label_count'               => _n_noop(
					'Pending Approval <span class="count">(%s)</span>',
					'Pending Approval <span class="count">(%s)</span>',
					'wc-b2b-print-manager'
				),
			]
		);
	}

	/**
	 * Add 'pending-approval' to WooCommerce's order status dropdown list.
	 *
	 * @param array $statuses Existing statuses.
	 * @return array
	 */
	public function add_order_status_to_list( array $statuses ): array {
		// Insert after 'pending'.
		$new = [];
		foreach ( $statuses as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'wc-pending' === $key ) {
				$new[ 'wc-' . self::PENDING_APPROVAL_STATUS ] = _x(
					'Pending Approval',
					'Order status label',
					'wc-b2b-print-manager'
				);
			}
		}
		return $new;
	}

	// -------------------------------------------------------------------------
	// Order Query Scoping
	// -------------------------------------------------------------------------

	/**
	 * Scope My Account → Orders to the current user or their company.
	 *
	 * @param array $args WP_Query args for orders.
	 * @return array
	 */
	public function scope_my_account_orders( array $args ): array {
		$user_id = get_current_user_id();

		if ( Role_Manager::is_super_admin( $user_id ) ) {
			return $args; // Admin sees all.
		}

		if ( Role_Manager::is_company_admin( $user_id ) ) {
			// Get all user IDs in this company.
			$company_id  = Company_Manager::get_user_company_id( $user_id );
			$company_users = Company_Manager::get_company_users( $company_id );
			$user_ids    = array_map( fn( $u ) => $u->ID, $company_users );
			if ( $user_ids ) {
				$args['customer'] = $user_ids;
			}
			return $args;
		}

		// Agent: own orders only.
		$args['customer'] = $user_id;
		return $args;
	}

	/**
	 * Scope the WooCommerce HPOS orders table query (admin area).
	 *
	 * @param array              $clauses SQL clauses.
	 * @param \WC_Order_Query    $query   Order query object.
	 * @param array              $args    Query args.
	 * @return array
	 */
	public function scope_admin_orders_query( array $clauses, $query, array $args ): array {
		if ( ! is_admin() ) {
			return $clauses;
		}

		$user_id = get_current_user_id();
		if ( Role_Manager::is_super_admin( $user_id ) ) {
			return $clauses;
		}

		if ( Role_Manager::is_company_admin( $user_id ) ) {
			$company_id    = Company_Manager::get_user_company_id( $user_id );
			$company_users = Company_Manager::get_company_users( $company_id );
			$user_ids      = array_map( fn( $u ) => (int) $u->ID, $company_users );
			if ( empty( $user_ids ) ) {
				$clauses['where'] = ( $clauses['where'] ?? '' ) . ' AND 1=0';
				return $clauses;
			}
			global $wpdb;
			$ids_placeholder         = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
			$clauses['where']         = ( $clauses['where'] ?? '' ) . $wpdb->prepare(
				" AND {$wpdb->prefix}wc_orders.customer_id IN ($ids_placeholder)",
				...$user_ids
			);
		}

		return $clauses;
	}

	// -------------------------------------------------------------------------
	// AJAX Handlers
	// -------------------------------------------------------------------------

	/**
	 * AJAX: Company admin approves an order.
	 *
	 * Expected POST: order_id, _nonce
	 */
	public function ajax_approve_order(): void {
		$this->verify_order_action_request();

		$order_id = (int) sanitize_text_field( wp_unslash( $_POST['order_id'] ?? '' ) );
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_send_json_error( [ 'message' => __( 'Order not found.', 'wc-b2b-print-manager' ) ] );
		}

		if ( ! Role_Manager::can_view_order( $order_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wc-b2b-print-manager' ) ] );
		}

		$order->set_status(
			'processing',
			sprintf(
				/* translators: %s: approver display name */
				__( 'Approved by %s.', 'wc-b2b-print-manager' ),
				wp_get_current_user()->display_name
			)
		);
		$order->update_meta_data( '_b2b_approved_by', get_current_user_id() );
		$order->update_meta_data( '_b2b_approved_at', current_time( 'mysql' ) );
		$order->save();

		do_action( 'wc_b2b_order_approved', $order );

		wp_send_json_success(
			[
				'message'    => __( 'Order approved successfully.', 'wc-b2b-print-manager' ),
				'new_status' => $order->get_status(),
			]
		);
	}

	/**
	 * AJAX: Company admin rejects an order.
	 *
	 * Expected POST: order_id, reason, _nonce
	 */
	public function ajax_reject_order(): void {
		$this->verify_order_action_request();

		$order_id = (int) sanitize_text_field( wp_unslash( $_POST['order_id'] ?? '' ) );
		$reason   = sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) );
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_send_json_error( [ 'message' => __( 'Order not found.', 'wc-b2b-print-manager' ) ] );
		}

		if ( ! Role_Manager::can_view_order( $order_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wc-b2b-print-manager' ) ] );
		}

		$note = $reason
			? sprintf(
				/* translators: 1: rejector display name, 2: rejection reason */
				__( 'Rejected by %1$s. Reason: %2$s', 'wc-b2b-print-manager' ),
				wp_get_current_user()->display_name,
				$reason
			)
			: sprintf(
				/* translators: %s: rejector display name */
				__( 'Rejected by %s.', 'wc-b2b-print-manager' ),
				wp_get_current_user()->display_name
			);

		$order->set_status( 'cancelled', $note );
		$order->update_meta_data( '_b2b_rejected_by', get_current_user_id() );
		$order->update_meta_data( '_b2b_rejected_at', current_time( 'mysql' ) );
		$order->update_meta_data( '_b2b_rejection_reason', $reason );
		$order->save();

		do_action( 'wc_b2b_order_rejected', $order, $reason );

		wp_send_json_success(
			[
				'message'    => __( 'Order rejected.', 'wc-b2b-print-manager' ),
				'new_status' => $order->get_status(),
			]
		);
	}

	// -------------------------------------------------------------------------
	// Bulk Actions
	// -------------------------------------------------------------------------

	/**
	 * Add "Approve Orders" to the WC admin bulk actions menu.
	 *
	 * @param array $actions Existing bulk actions.
	 * @return array
	 */
	public function add_bulk_approve_action( array $actions ): array {
		if ( current_user_can( 'approve_company_orders' ) ) {
			$actions['b2b_approve_orders'] = __( 'Approve Orders', 'wc-b2b-print-manager' );
		}
		return $actions;
	}

	/**
	 * Handle the "Approve Orders" bulk action.
	 *
	 * @param string $redirect_url URL to redirect to after action.
	 * @param string $action       Action name.
	 * @param array  $order_ids    Selected order IDs.
	 * @return string
	 */
	public function handle_bulk_approve_action( string $redirect_url, string $action, array $order_ids ): string {
		if ( 'b2b_approve_orders' !== $action ) {
			return $redirect_url;
		}

		if ( ! current_user_can( 'approve_company_orders' ) ) {
			return $redirect_url;
		}

		$approved = 0;
		foreach ( $order_ids as $order_id ) {
			$order_id = (int) $order_id;
			if ( ! Role_Manager::can_view_order( $order_id ) ) {
				continue;
			}
			$order = wc_get_order( $order_id );
			if ( $order && 'wc-' . self::PENDING_APPROVAL_STATUS === $order->get_status( 'edit' ) ) {
				$order->set_status( 'processing', __( 'Bulk approved.', 'wc-b2b-print-manager' ) );
				$order->save();
				++$approved;
			}
		}

		return add_query_arg( 'b2b_approved', $approved, $redirect_url );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Verify nonce and minimum capability for order action AJAX calls.
	 */
	private function verify_order_action_request(): void {
		if (
			! isset( $_POST['_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['_nonce'] ), 'b2b_order_action' )
		) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'wc-b2b-print-manager' ) ] );
		}

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => __( 'You must be logged in.', 'wc-b2b-print-manager' ) ] );
		}

		if ( ! current_user_can( 'approve_company_orders' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'wc-b2b-print-manager' ) ] );
		}
	}

	// -------------------------------------------------------------------------
	// Static Helpers
	// -------------------------------------------------------------------------

	/**
	 * Get all orders pending approval for a given company.
	 *
	 * @param int $company_id Company post ID.
	 * @return \WC_Order[]
	 */
	public static function get_pending_orders_for_company( int $company_id ): array {
		$users    = Company_Manager::get_company_users( $company_id );
		$user_ids = array_map( fn( $u ) => $u->ID, $users );

		if ( empty( $user_ids ) ) {
			return [];
		}

		$orders = wc_get_orders(
			[
				'status'   => [ 'wc-' . self::PENDING_APPROVAL_STATUS ],
				'customer' => $user_ids,
				'limit'    => -1,
			]
		);

		return is_array( $orders ) ? $orders : [];
	}
}
