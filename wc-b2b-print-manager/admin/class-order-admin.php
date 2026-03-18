<?php
/**
 * Order Admin
 *
 * Enhances the WooCommerce admin orders screen for B2B:
 *   - Shows company name and agent columns.
 *   - Adds "Pending Approval" status filter tab.
 *   - Displays B2B meta in the order edit sidebar.
 *
 * @package WC_B2B\Admin
 */

declare( strict_types=1 );

namespace WC_B2B\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Order_Admin
 */
class Order_Admin {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		// HPOS (High-Performance Order Storage) order table columns.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', [ $this, 'add_order_columns' ] );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ $this, 'render_order_column' ], 10, 2 );

		// Legacy orders post table columns (fallback).
		add_filter( 'manage_edit-shop_order_columns', [ $this, 'add_order_columns' ] );
		add_action( 'manage_shop_order_posts_custom_column', [ $this, 'render_order_column_legacy' ], 10, 2 );

		// Order edit page — B2B sidebar section.
		add_action( 'woocommerce_admin_order_data_after_order_details', [ $this, 'render_b2b_order_sidebar' ] );

		// Filter views — add 'Pending Approval' tab.
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', [ $this, 'handle_status_filter' ] );
	}

	// -------------------------------------------------------------------------
	// Columns
	// -------------------------------------------------------------------------

	/**
	 * Register B2B columns on the orders table.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_order_columns( array $columns ): array {
		$new = [];
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$new['b2b_company'] = __( 'Company', 'wc-b2b-print-manager' );
				$new['b2b_agent']   = __( 'Agent', 'wc-b2b-print-manager' );
			}
		}
		return $new;
	}

	/**
	 * Render B2B column content (HPOS).
	 *
	 * @param string    $column Column key.
	 * @param \WC_Order $order  Order object.
	 */
	public function render_order_column( string $column, \WC_Order $order ): void {
		$this->output_b2b_column( $column, $order );
	}

	/**
	 * Render B2B column content (legacy post table).
	 *
	 * @param string $column   Column key.
	 * @param int    $post_id  Post ID.
	 */
	public function render_order_column_legacy( string $column, int $post_id ): void {
		$order = wc_get_order( $post_id );
		if ( $order ) {
			$this->output_b2b_column( $column, $order );
		}
	}

	/**
	 * Shared column output logic.
	 *
	 * @param string    $column Column key.
	 * @param \WC_Order $order  Order object.
	 */
	private function output_b2b_column( string $column, \WC_Order $order ): void {
		switch ( $column ) {
			case 'b2b_company':
				$company_id = (int) $order->get_meta( '_b2b_company_id' );
				if ( $company_id ) {
					$company = \WC_B2B\Company_Manager::get_company( $company_id );
					echo $company
						? '<strong>' . esc_html( $company->post_title ) . '</strong>'
						: '<em>' . esc_html__( 'Unknown', 'wc-b2b-print-manager' ) . '</em>';
				} else {
					echo '—';
				}
				break;

			case 'b2b_agent':
				$agent_id = (int) $order->get_meta( '_b2b_agent_id' );
				if ( $agent_id ) {
					$user = get_user_by( 'id', $agent_id );
					echo $user
						? '<a href="' . esc_url( get_edit_user_link( $agent_id ) ) . '">' . esc_html( $user->display_name ) . '</a>'
						: '<em>' . esc_html__( 'Deleted user', 'wc-b2b-print-manager' ) . '</em>';
				} else {
					echo '—';
				}
				break;
		}
	}

	// -------------------------------------------------------------------------
	// Order Edit Sidebar
	// -------------------------------------------------------------------------

	/**
	 * Add B2B metadata panel to the order edit page.
	 *
	 * @param \WC_Order $order Order object.
	 */
	public function render_b2b_order_sidebar( \WC_Order $order ): void {
		$company_id      = (int) $order->get_meta( '_b2b_company_id' );
		$agent_id        = (int) $order->get_meta( '_b2b_agent_id' );
		$approved_by_id  = (int) $order->get_meta( '_b2b_approved_by' );
		$approved_at     = $order->get_meta( '_b2b_approved_at' );
		$rejected_reason = $order->get_meta( '_b2b_rejection_reason' );

		if ( ! $company_id && ! $agent_id ) {
			return; // Not a B2B order.
		}
		?>
		<div class="order_data_column b2b-order-meta">
			<h4><?php esc_html_e( 'B2B Details', 'wc-b2b-print-manager' ); ?></h4>

			<?php if ( $company_id ) : ?>
				<p>
					<strong><?php esc_html_e( 'Company:', 'wc-b2b-print-manager' ); ?></strong><br />
					<?php
					$company = \WC_B2B\Company_Manager::get_company( $company_id );
					echo $company ? esc_html( $company->post_title ) : esc_html__( 'Unknown', 'wc-b2b-print-manager' );
					?>
				</p>
			<?php endif; ?>

			<?php if ( $agent_id ) : ?>
				<p>
					<strong><?php esc_html_e( 'Agent:', 'wc-b2b-print-manager' ); ?></strong><br />
					<?php
					$agent = get_user_by( 'id', $agent_id );
					echo $agent
						? '<a href="' . esc_url( get_edit_user_link( $agent_id ) ) . '">' . esc_html( $agent->display_name ) . '</a>'
						: esc_html__( 'Deleted user', 'wc-b2b-print-manager' );
					?>
				</p>
			<?php endif; ?>

			<?php if ( $approved_by_id ) : ?>
				<p>
					<strong><?php esc_html_e( 'Approved by:', 'wc-b2b-print-manager' ); ?></strong><br />
					<?php
					$approver = get_user_by( 'id', $approved_by_id );
					echo $approver ? esc_html( $approver->display_name ) : '—';
					if ( $approved_at ) {
						echo '<br /><small>' . esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $approved_at ) ) ) . '</small>';
					}
					?>
				</p>
			<?php endif; ?>

			<?php if ( $rejected_reason ) : ?>
				<p>
					<strong><?php esc_html_e( 'Rejection Reason:', 'wc-b2b-print-manager' ); ?></strong><br />
					<?php echo esc_html( $rejected_reason ); ?>
				</p>
			<?php endif; ?>

			<?php if ( 'pending-approval' === $order->get_status() && current_user_can( 'approve_company_orders' ) ) : ?>
				<div class="b2b-order-actions">
					<button class="button button-primary b2b-approve-order"
							data-order="<?php echo esc_attr( $order->get_id() ); ?>">
						<?php esc_html_e( 'Approve', 'wc-b2b-print-manager' ); ?>
					</button>
					<button class="button b2b-reject-order"
							data-order="<?php echo esc_attr( $order->get_id() ); ?>">
						<?php esc_html_e( 'Reject', 'wc-b2b-print-manager' ); ?>
					</button>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Status Filter
	// -------------------------------------------------------------------------

	/**
	 * Honour the ?status=pending-approval query parameter on the HPOS orders table.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public function handle_status_filter( array $args ): array {
		if (
			isset( $_GET['status'] ) && // phpcs:ignore WordPress.Security.NonceVerification
			'pending-approval' === sanitize_text_field( wp_unslash( $_GET['status'] ) ) // phpcs:ignore
		) {
			$args['status'] = [ 'wc-pending-approval' ];
		}
		return $args;
	}
}
