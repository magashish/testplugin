<?php
/**
 * Admin View: Pending Order Approvals
 *
 * Rendered by Admin::render_approval_page().
 *
 * Available variables:
 *   $orders  — array of \WC_Order objects pending approval.
 *
 * @package WC_B2B\Admin\Views
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap wc-b2b-approval-page">
	<h1><?php esc_html_e( 'Pending Order Approvals', 'wc-b2b-print-manager' ); ?></h1>

	<?php if ( empty( $orders ) ) : ?>
		<p class="b2b-no-data"><?php esc_html_e( 'No orders are currently awaiting approval. ', 'wc-b2b-print-manager' ); ?></p>
	<?php else : ?>
		<p>
			<?php
			printf(
				/* translators: %d: order count */
				esc_html(
					_n(
						'%d order requires your approval.',
						'%d orders require your approval.',
						count( $orders ),
						'wc-b2b-print-manager'
					)
				),
				count( $orders )
			);
			?>
		</p>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Order', 'wc-b2b-print-manager' ); ?></th>
					<th><?php esc_html_e( 'Date', 'wc-b2b-print-manager' ); ?></th>
					<th><?php esc_html_e( 'Agent', 'wc-b2b-print-manager' ); ?></th>
					<th><?php esc_html_e( 'Company', 'wc-b2b-print-manager' ); ?></th>
					<th><?php esc_html_e( 'Total', 'wc-b2b-print-manager' ); ?></th>
					<th><?php esc_html_e( 'Items', 'wc-b2b-print-manager' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'wc-b2b-print-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $orders as $order ) : /** @var \WC_Order $order */ ?>
					<?php
					$company_id = (int) $order->get_meta( '_b2b_company_id' );
					$agent_id   = (int) $order->get_meta( '_b2b_agent_id' );
					$company    = $company_id ? \WC_B2B\Company_Manager::get_company( $company_id ) : null;
					$agent      = $agent_id ? get_user_by( 'id', $agent_id ) : null;
					?>
					<tr data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
						<td>
							<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">
								#<?php echo esc_html( $order->get_order_number() ); ?>
							</a>
						</td>
						<td><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></td>
						<td><?php echo $agent ? esc_html( $agent->display_name ) : '—'; ?></td>
						<td><?php echo $company ? esc_html( $company->post_title ) : '—'; ?></td>
						<td><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
						<td>
							<ul class="b2b-item-list">
								<?php foreach ( $order->get_items() as $item ) : ?>
									<li><?php echo esc_html( $item->get_name() ) . ' &times; ' . esc_html( $item->get_quantity() ); ?></li>
								<?php endforeach; ?>
							</ul>
						</td>
						<td>
							<button class="button button-primary b2b-approve-order"
									data-order="<?php echo esc_attr( $order->get_id() ); ?>">
								<?php esc_html_e( 'Approve', 'wc-b2b-print-manager' ); ?>
							</button>
							<button class="button b2b-reject-order"
									data-order="<?php echo esc_attr( $order->get_id() ); ?>">
								<?php esc_html_e( 'Reject', 'wc-b2b-print-manager' ); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
