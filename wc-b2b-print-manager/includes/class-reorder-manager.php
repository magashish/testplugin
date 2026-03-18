<?php
/**
 * Reorder Manager
 *
 * Adds a "Reorder" button to My Account → Orders and processes the
 * reorder request: validates previous order items and adds them to cart.
 *
 * @package WC_B2B\Includes
 */

declare( strict_types=1 );

namespace WC_B2B;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Reorder_Manager
 */
class Reorder_Manager {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		// Add Reorder button to My Account orders table.
		add_action( 'woocommerce_my_account_my_orders_actions', [ $this, 'add_reorder_action' ], 10, 2 );

		// Handle the reorder GET request.
		add_action( 'init', [ $this, 'process_reorder_request' ] );
	}

	// -------------------------------------------------------------------------
	// My Account — Reorder button
	// -------------------------------------------------------------------------

	/**
	 * Append a "Reorder" action to the My Account orders table row.
	 *
	 * @param array     $actions Existing row actions.
	 * @param \WC_Order $order   Order object.
	 * @return array
	 */
	public function add_reorder_action( array $actions, \WC_Order $order ): array {
		// Only allow on completed / processing orders.
		if ( ! in_array( $order->get_status(), [ 'completed', 'processing' ], true ) ) {
			return $actions;
		}

		if ( ! Role_Manager::can_view_order( $order->get_id() ) ) {
			return $actions;
		}

		$actions['b2b_reorder'] = [
			'url'  => $this->get_reorder_url( $order->get_id() ),
			'name' => __( 'Reorder', 'wc-b2b-print-manager' ),
		];

		return $actions;
	}

	// -------------------------------------------------------------------------
	// Reorder URL builder
	// -------------------------------------------------------------------------

	/**
	 * Build the signed reorder URL.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return string
	 */
	public function get_reorder_url( int $order_id ): string {
		return wp_nonce_url(
			add_query_arg(
				[
					'b2b_action'   => 'reorder',
					'order_id'     => $order_id,
				],
				wc_get_cart_url()
			),
			'b2b_reorder_' . $order_id
		);
	}

	// -------------------------------------------------------------------------
	// Reorder processing
	// -------------------------------------------------------------------------

	/**
	 * Intercept the reorder GET request and populate the cart.
	 */
	public function process_reorder_request(): void {
		if (
			! isset( $_GET['b2b_action'] ) ||
			'reorder' !== sanitize_text_field( wp_unslash( $_GET['b2b_action'] ) )
		) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( wc_get_cart_url() ) );
			exit;
		}

		$order_id = (int) sanitize_text_field( wp_unslash( $_GET['order_id'] ?? '' ) );

		// Verify nonce.
		if (
			! isset( $_GET['_wpnonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'b2b_reorder_' . $order_id )
		) {
			wc_add_notice( __( 'Invalid reorder link.', 'wc-b2b-print-manager' ), 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( 'orders' ) );
			exit;
		}

		// Permission check.
		if ( ! Role_Manager::can_view_order( $order_id ) ) {
			wc_add_notice( __( 'You do not have permission to reorder this order.', 'wc-b2b-print-manager' ), 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( 'orders' ) );
			exit;
		}

		$this->populate_cart_from_order( $order_id );

		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}

	/**
	 * Re-add every line item from a previous order to the cart.
	 *
	 * Items that are no longer purchasable are skipped with a notice.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	private function populate_cart_from_order( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Order not found.', 'wc-b2b-print-manager' ), 'error' );
			return;
		}

		$added   = 0;
		$skipped = 0;

		foreach ( $order->get_items() as $item ) {
			/** @var \WC_Order_Item_Product $item */
			$product_id   = $item->get_product_id();
			$variation_id = $item->get_variation_id();
			$quantity     = $item->get_quantity();
			$product      = wc_get_product( $variation_id ?: $product_id );

			if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
				wc_add_notice(
					sprintf(
						/* translators: %s: product name */
						__( '"%s" could not be added to your cart (unavailable).', 'wc-b2b-print-manager' ),
						esc_html( $item->get_name() )
					),
					'notice'
				);
				++$skipped;
				continue;
			}

			// Carry over variation attributes.
			$variation_data = [];
			if ( $variation_id ) {
				$variation_obj  = new \WC_Product_Variation( $variation_id );
				$variation_data = $variation_obj->get_variation_attributes();
			}

			// Carry over artwork IDs so agents can keep the same files.
			$cart_item_data = [];
			$artwork_ids    = json_decode( $item->get_meta( '_b2b_artwork_ids' ) ?? '[]', true );
			if ( ! empty( $artwork_ids ) ) {
				$cart_item_data['b2b_artwork_ids']     = $artwork_ids;
				$cart_item_data['b2b_reorder_from']    = $order_id;
			}

			$result = WC()->cart->add_to_cart(
				$product_id,
				$quantity,
				$variation_id,
				$variation_data,
				$cart_item_data
			);

			if ( $result ) {
				++$added;
			} else {
				++$skipped;
			}
		}

		if ( $added > 0 ) {
			wc_add_notice(
				sprintf(
					/* translators: %d: number of items added */
					_n(
						'%d item has been added to your cart from your previous order.',
						'%d items have been added to your cart from your previous order.',
						$added,
						'wc-b2b-print-manager'
					),
					$added
				),
				'success'
			);
		}

		do_action( 'wc_b2b_after_reorder', $order_id, $added, $skipped );
	}
}
