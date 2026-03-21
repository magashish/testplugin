<?php
/**
 * B2B Invoice Payment Gateway
 *
 * A no-op payment gateway used exclusively for B2B checkout.
 * B2B orders are invoiced externally, so no actual payment is collected here.
 * This gateway exists solely to satisfy WooCommerce's payment-method validation.
 *
 * @package WC_B2B\Includes
 */

declare( strict_types=1 );

namespace WC_B2B;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class B2B_Gateway
 *
 * Extends WC_Payment_Gateway to provide a "B2B Invoice" option that
 * passes WooCommerce checkout validation without collecting payment.
 */
class B2B_Gateway extends \WC_Payment_Gateway {

	/**
	 * Set up gateway properties.
	 */
	public function __construct() {
		$this->id                 = 'b2b_invoice';
		$this->method_title       = __( 'B2B Invoice', 'wc-b2b-print-manager' );
		$this->method_description = __( 'Used internally for B2B orders. Payment is handled via external invoice.', 'wc-b2b-print-manager' );
		$this->title              = __( 'Invoice', 'wc-b2b-print-manager' );
		$this->has_fields         = false;

		// Load saved settings (required by WC_Payment_Gateway).
		$this->init_form_fields();
		$this->init_settings();
	}

	/**
	 * No admin settings needed — gateway is managed programmatically.
	 */
	public function init_form_fields(): void {
		$this->form_fields = [];
	}

	/**
	 * Process the payment — order status is already set by Checkout_Customizer.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array Result array expected by WooCommerce.
	 */
	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );

		// Empty the cart.
		WC()->cart->empty_cart();

		return [
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		];
	}
}
