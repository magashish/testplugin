<?php
/**
 * Public View: B2B Order List Table
 *
 * Reusable order table rendered in both Agent and Company Admin dashboards.
 *
 * Available variables (injected by Dashboard methods):
 *   $orders              — array of \WC_Order objects.
 *   $show_agent_column   — bool (true for company admin, default false).
 *
 * @package WC_B2B\Frontend\Views
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$show_agent_column = $show_agent_column ?? false;
?>
<div class="b2b-orders-tab">
	<?php if ( empty( $orders ) ) : ?>
		<p class="b2b-no-data"><?php esc_html_e( 'No orders found.', 'wc-b2b-print-manager' ); ?></p>
	<?php else : ?>
		<table class="b2b-table b2b-orders-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Order', 'wc-b2b-print-manager' ); ?></th>
					<th><?php esc_html_e( 'Date', 'wc-b2b-print-manager' ); ?></th>
					<?php if ( $show_agent_column ) : ?>
						<th><?php esc_html_e( 'Agent', 'wc-b2b-print-manager' ); ?></th>
					<?php endif; ?>
					<th><?php esc_html_e( 'Status', 'wc-b2b-print-manager' ); ?></th>
					<th><?php esc_html_e( 'Total', 'wc-b2b-print-manager' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'wc-b2b-print-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $orders as $order ) : /** @var \WC_Order $order */ ?>
					<?php
					$status_label = wc_get_order_statuses()[ 'wc-' . $order->get_status() ] ?? $order->get_status();
					$agent_id     = (int) $order->get_meta( '_b2b_agent_id' );
					$agent        = ( $show_agent_column && $agent_id ) ? get_user_by( 'id', $agent_id ) : null;
					$reorder_mgr  = new \WC_B2B\Reorder_Manager();
					?>
					<tr>
						<td>
							<a href="<?php echo esc_url( $order->get_view_order_url() ); ?>">
								#<?php echo esc_html( $order->get_order_number() ); ?>
							</a>
						</td>
						<td><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></td>
						<?php if ( $show_agent_column ) : ?>
							<td><?php echo $agent ? esc_html( $agent->display_name ) : '—'; ?></td>
						<?php endif; ?>
						<td>
							<span class="b2b-status b2b-status--<?php echo esc_attr( $order->get_status() ); ?>">
								<?php echo esc_html( $status_label ); ?>
							</span>
						</td>
						<td><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
						<td class="b2b-order-actions">
							<a href="<?php echo esc_url( $order->get_view_order_url() ); ?>" class="b2b-btn b2b-btn--sm">
								<?php esc_html_e( 'View', 'wc-b2b-print-manager' ); ?>
							</a>
							<?php if ( in_array( $order->get_status(), [ 'completed', 'processing' ], true ) ) : ?>
								<a href="<?php echo esc_url( $reorder_mgr->get_reorder_url( $order->get_id() ) ); ?>"
								   class="b2b-btn b2b-btn--sm b2b-btn--secondary">
									<?php esc_html_e( 'Reorder', 'wc-b2b-print-manager' ); ?>
								</a>
							<?php endif; ?>
							<?php if ( 'pending-approval' === $order->get_status() && current_user_can( 'approve_company_orders' ) ) : ?>
								<button class="b2b-btn b2b-btn--sm b2b-btn--primary b2b-approve-order"
										data-order="<?php echo esc_attr( $order->get_id() ); ?>">
									<?php esc_html_e( 'Approve', 'wc-b2b-print-manager' ); ?>
								</button>
								<button class="b2b-btn b2b-btn--sm b2b-btn--danger b2b-reject-order"
										data-order="<?php echo esc_attr( $order->get_id() ); ?>">
									<?php esc_html_e( 'Reject', 'wc-b2b-print-manager' ); ?>
								</button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
