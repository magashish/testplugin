<?php
/**
 * Invoice Manager
 *
 * Collects all WooCommerce orders placed by a company's members within a
 * calendar month and emails an HTML invoice to every company_admin in that
 * company.
 *
 * @package WC_B2B\Includes
 */

declare( strict_types=1 );

namespace WC_B2B;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Invoice_Manager
 */
class Invoice_Manager {

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Send a monthly invoice for a company to all its company_admin users.
	 *
	 * @param int $company_id Company post ID.
	 * @param int $month      1–12.
	 * @param int $year       e.g. 2025.
	 * @return true|\WP_Error
	 */
	public static function send_invoice( int $company_id, int $month, int $year ) {
		$company = Company_Manager::get_company( $company_id );
		if ( ! $company ) {
			return new \WP_Error( 'no_company', __( 'Company not found.', 'wc-b2b-print-manager' ) );
		}

		$orders = self::get_orders_for_period( $company_id, $month, $year );

		$recipients = self::get_company_admin_emails( $company_id );
		if ( empty( $recipients ) ) {
			return new \WP_Error(
				'no_recipient',
				__( 'No company admin found — please assign at least one company admin before sending an invoice.', 'wc-b2b-print-manager' )
			);
		}

		$period_label = date_i18n( 'F Y', mktime( 0, 0, 0, $month, 1, $year ) );
		$subject      = sprintf(
			/* translators: 1: company name, 2: month+year label */
			__( 'Invoice for %1$s — %2$s', 'wc-b2b-print-manager' ),
			$company->post_title,
			$period_label
		);

		$html    = self::build_invoice_html( $company, $orders, $month, $year );
		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];

		$sent = wp_mail( implode( ',', $recipients ), $subject, $html, $headers );

		if ( ! $sent ) {
			return new \WP_Error(
				'email_failed',
				__( 'Email could not be sent. Please check your mail configuration.', 'wc-b2b-print-manager' )
			);
		}

		update_post_meta( $company_id, '_b2b_last_invoice_sent', current_time( 'mysql' ) );
		update_post_meta( $company_id, '_b2b_last_invoice_period', sprintf( '%04d-%02d', $year, $month ) );

		return true;
	}

	/**
	 * Fetch all WooCommerce orders placed by any member of a company
	 * within the given calendar month.
	 *
	 * @param int $company_id Company post ID.
	 * @param int $month      1–12.
	 * @param int $year       e.g. 2025.
	 * @return \WC_Order[]
	 */
	public static function get_orders_for_period( int $company_id, int $month, int $year ): array {
		$users    = Company_Manager::get_company_users( $company_id );
		$user_ids = array_map( fn( $u ) => $u->ID, $users );

		if ( empty( $user_ids ) ) {
			return [];
		}

		$days_in_month = (int) date( 't', mktime( 0, 0, 0, $month, 1, $year ) );

		$orders = wc_get_orders(
			[
				'customer'    => $user_ids,
				'limit'       => -1,
				'orderby'     => 'date',
				'order'       => 'ASC',
				'date_after'  => sprintf( '%04d-%02d-01T00:00:00', $year, $month ),
				'date_before' => sprintf( '%04d-%02d-%02dT23:59:59', $year, $month, $days_in_month ),
			]
		);

		return is_array( $orders ) ? $orders : [];
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Return email addresses of all company_admin users in a company.
	 *
	 * @param int $company_id Company post ID.
	 * @return string[]
	 */
	private static function get_company_admin_emails( int $company_id ): array {
		$members = Company_Manager::get_company_users( $company_id );
		$emails  = [];

		foreach ( $members as $member ) {
			if ( Role_Manager::is_company_admin( $member->ID ) ) {
				$emails[] = $member->user_email;
			}
		}

		return $emails;
	}

	/**
	 * Build the HTML invoice email body.
	 *
	 * @param \WP_Post   $company Company post.
	 * @param \WC_Order[] $orders  Orders for the period.
	 * @param int         $month   1–12.
	 * @param int         $year    e.g. 2025.
	 * @return string HTML string.
	 */
	private static function build_invoice_html( \WP_Post $company, array $orders, int $month, int $year ): string {
		$shop_name    = get_bloginfo( 'name' );
		$shop_email   = get_option( 'admin_email' );
		$period_label = date_i18n( 'F Y', mktime( 0, 0, 0, $month, 1, $year ) );
		$generated    = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		$invoice_num  = strtoupper( sprintf( 'INV-%04d%02d-%d', $year, $month, $company->ID ) );

		$grand_total = array_reduce(
			$orders,
			fn( float $carry, \WC_Order $order ) => $carry + (float) $order->get_total(),
			0.0
		);

		ob_start();
		?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html( $invoice_num ); ?></title>
<style>
  body{margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#111827}
  .wrap{max-width:700px;margin:32px auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,.1)}
  .header{background:linear-gradient(135deg,#1e3a5f 0%,#2563eb 100%);color:#fff;padding:36px 44px}
  .header h1{margin:0 0 6px;font-size:30px;font-weight:700;letter-spacing:-.5px}
  .header p{margin:0;opacity:.8;font-size:13px}
  .body{padding:36px 44px}
  .meta-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:36px;padding:20px 24px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb}
  .meta-item .label{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:4px}
  .meta-item .value{font-size:15px;font-weight:600;color:#111827}
  h2{font-size:15px;font-weight:700;color:#111827;margin:0 0 12px;text-transform:uppercase;letter-spacing:.04em}
  table{width:100%;border-collapse:collapse;margin-bottom:8px;font-size:14px}
  thead th{background:#f9fafb;padding:10px 14px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;border-top:1px solid #e5e7eb;border-bottom:2px solid #e5e7eb}
  tbody td{padding:12px 14px;border-bottom:1px solid #f3f4f6;color:#374151;vertical-align:top}
  tbody tr:last-child td{border-bottom:none}
  .total-row{background:#f9fafb}
  .total-row td{padding:14px;font-size:15px;font-weight:700;color:#111827;border-top:2px solid #e5e7eb}
  .text-right{text-align:right}
  .no-orders{text-align:center;padding:32px;color:#9ca3af;font-style:italic}
  .footer{padding:20px 44px;background:#f9fafb;font-size:12px;color:#9ca3af;border-top:1px solid #e5e7eb}
  .badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;text-transform:capitalize}
  .badge-processing{background:#d1fae5;color:#065f46}
  .badge-pending-approval{background:#fef3c7;color:#92400e}
  .badge-completed{background:#dbeafe;color:#1e40af}
  .badge-cancelled{background:#fee2e2;color:#991b1b}
</style>
</head>
<body>
<div class="wrap">

  <div class="header">
    <h1><?php echo esc_html( $shop_name ); ?></h1>
    <p><?php echo esc_html( $shop_email ); ?></p>
  </div>

  <div class="body">

    <div class="meta-grid">
      <div class="meta-item">
        <div class="label"><?php esc_html_e( 'Invoice', 'wc-b2b-print-manager' ); ?></div>
        <div class="value"><?php echo esc_html( $invoice_num ); ?></div>
      </div>
      <div class="meta-item">
        <div class="label"><?php esc_html_e( 'Billing Period', 'wc-b2b-print-manager' ); ?></div>
        <div class="value"><?php echo esc_html( $period_label ); ?></div>
      </div>
      <div class="meta-item">
        <div class="label"><?php esc_html_e( 'Billed To', 'wc-b2b-print-manager' ); ?></div>
        <div class="value"><?php echo esc_html( $company->post_title ); ?></div>
      </div>
    </div>

    <h2><?php esc_html_e( 'Orders', 'wc-b2b-print-manager' ); ?></h2>

    <?php if ( empty( $orders ) ) : ?>
      <p class="no-orders"><?php esc_html_e( 'No orders were placed during this period.', 'wc-b2b-print-manager' ); ?></p>
    <?php else : ?>
      <table>
        <thead>
          <tr>
            <th><?php esc_html_e( 'Order', 'wc-b2b-print-manager' ); ?></th>
            <th><?php esc_html_e( 'Date', 'wc-b2b-print-manager' ); ?></th>
            <th><?php esc_html_e( 'Agent', 'wc-b2b-print-manager' ); ?></th>
            <th><?php esc_html_e( 'Items', 'wc-b2b-print-manager' ); ?></th>
            <th><?php esc_html_e( 'Status', 'wc-b2b-print-manager' ); ?></th>
            <th class="text-right"><?php esc_html_e( 'Total', 'wc-b2b-print-manager' ); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ( $orders as $order ) : /** @var \WC_Order $order */
            $agent_id   = (int) $order->get_meta( '_b2b_agent_id' );
            $agent      = $agent_id ? get_user_by( 'id', $agent_id ) : null;
            $agent_name = $agent ? $agent->display_name : '—';
            $status     = $order->get_status();
            $items_html = implode( '<br>', array_map(
              fn( \WC_Order_Item $i ) => esc_html( $i->get_name() . ' × ' . $i->get_quantity() ),
              array_values( $order->get_items() )
            ) );
          ?>
          <tr>
            <td><strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong></td>
            <td><?php echo esc_html( wc_format_datetime( $order->get_date_created(), get_option( 'date_format' ) ) ); ?></td>
            <td><?php echo esc_html( $agent_name ); ?></td>
            <td><?php echo wp_kses( $items_html, [ 'br' => [] ] ); ?></td>
            <td><span class="badge badge-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( wc_get_order_status_name( $status ) ); ?></span></td>
            <td class="text-right"><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="total-row">
            <td colspan="5"><?php esc_html_e( 'Total Amount Due', 'wc-b2b-print-manager' ); ?></td>
            <td class="text-right"><?php echo wp_kses_post( wc_price( $grand_total ) ); ?></td>
          </tr>
        </tfoot>
      </table>
    <?php endif; ?>

  </div>

  <div class="footer">
    <?php
    printf(
      /* translators: %s: date/time generated */
      esc_html__( 'Generated on %s', 'wc-b2b-print-manager' ),
      esc_html( $generated )
    );
    ?>
  </div>

</div>
</body>
</html>
		<?php
		return ob_get_clean() ?: '';
	}
}
