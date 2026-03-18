<?php
/**
 * Checkout Customizer
 *
 * Implements the B2B-specific checkout flow:
 *   - Removes billing & shipping fields.
 *   - Disables all payment gateways (orders are invoiced externally).
 *   - Bypasses the standard order-pay flow.
 *   - Auto-assigns the correct order status after placement.
 *
 * @package WC_B2B\Includes
 */

declare( strict_types=1 );

namespace WC_B2B;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Checkout_Customizer
 */
class Checkout_Customizer {

	/**
	 * Register checkout hooks — only for B2B users.
	 */
	public function __construct() {
		// Only apply for logged-in B2B users.
		add_action( 'wp', [ $this, 'maybe_activate_b2b_checkout' ] );
	}

	/**
	 * Activate B2B checkout overrides when the current user is agent/company_admin.
	 */
	public function maybe_activate_b2b_checkout(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! Role_Manager::is_agent( $user_id ) && ! Role_Manager::is_company_admin( $user_id ) ) {
			return;
		}

		$this->register_hooks();
	}

	/**
	 * Register all the checkout override hooks.
	 */
	private function register_hooks(): void {
		// ── Fields ──────────────────────────────────────────────────────────
		add_filter( 'woocommerce_checkout_fields', [ $this, 'remove_checkout_fields' ] );
		add_filter( 'woocommerce_billing_fields', '__return_empty_array' );
		add_filter( 'woocommerce_shipping_fields', '__return_empty_array' );

		// Skip field validation since we removed the fields.
		add_filter( 'woocommerce_checkout_required_field_notice', '__return_false' );

		// ── Payment Gateways ─────────────────────────────────────────────────
		add_filter( 'woocommerce_available_payment_gateways', '__return_empty_array' );

		// ── Order Creation ───────────────────────────────────────────────────
		// Populate customer details from WP user profile.
		add_action( 'woocommerce_checkout_order_created', [ $this, 'populate_order_from_user' ] );

		// Set the correct order status based on company approval setting.
		add_action( 'woocommerce_checkout_order_created', [ $this, 'set_initial_order_status' ], 20 );

		// ── UI Tweaks ────────────────────────────────────────────────────────
		add_action( 'woocommerce_checkout_before_customer_details', [ $this, 'render_b2b_checkout_notice' ] );
		remove_action( 'woocommerce_checkout_order_review', 'woocommerce_checkout_payment', 20 );
		add_action( 'woocommerce_checkout_order_review', [ $this, 'render_b2b_place_order_button' ], 20 );
	}

	// -------------------------------------------------------------------------
	// Field Overrides
	// -------------------------------------------------------------------------

	/**
	 * Strip all billing/shipping fields from the checkout form.
	 *
	 * @param array $fields WooCommerce checkout fields array.
	 * @return array
	 */
	public function remove_checkout_fields( array $fields ): array {
		// Keep only the order notes field so agents can leave instructions.
		$fields['billing']  = [];
		$fields['shipping'] = [];

		// Retain order comments if present.
		$fields['order'] = [
			'order_comments' => $fields['order']['order_comments'] ?? [],
		];

		return $fields;
	}

	// -------------------------------------------------------------------------
	// Order Hooks
	// -------------------------------------------------------------------------

	/**
	 * Fill order address data from WP user profile (no billing form submitted).
	 *
	 * @param \WC_Order $order Newly created order.
	 */
	public function populate_order_from_user( \WC_Order $order ): void {
		$user = wp_get_current_user();
		if ( ! $user->ID ) {
			return;
		}

		$company_id   = Company_Manager::get_user_company_id( $user->ID );
		$company_name = $company_id ? get_the_title( $company_id ) : '';

		$order->set_billing_first_name( $user->first_name );
		$order->set_billing_last_name( $user->last_name );
		$order->set_billing_email( $user->user_email );
		$order->set_billing_company( $company_name );

		// Store company ID in order meta for quick lookups.
		$order->update_meta_data( '_b2b_company_id', $company_id );
		$order->update_meta_data( '_b2b_agent_id', $user->ID );
		$order->save();
	}

	/**
	 * Set the initial order status based on company approval requirements.
	 *
	 * @param \WC_Order $order Newly created order.
	 */
	public function set_initial_order_status( \WC_Order $order ): void {
		$company_id = (int) $order->get_meta( '_b2b_company_id' );

		if ( $company_id && Company_Manager::requires_approval( $company_id ) ) {
			$order->set_status( 'wc-pending-approval', __( 'Awaiting company admin approval.', 'wc-b2b-print-manager' ) );
		} else {
			$order->set_status( 'processing', __( 'B2B order placed — no approval required.', 'wc-b2b-print-manager' ) );
		}

		$order->save();
	}

	// -------------------------------------------------------------------------
	// UI Overrides
	// -------------------------------------------------------------------------

	/**
	 * Render an informational notice at the top of the B2B checkout.
	 */
	public function render_b2b_checkout_notice(): void {
		$company_id = Company_Manager::get_user_company_id();
		$requires   = $company_id && Company_Manager::requires_approval( $company_id );
		?>
		<div class="b2b-checkout-notice woocommerce-info">
			<?php if ( $requires ) : ?>
				<?php esc_html_e( 'Your order will be submitted for approval before processing.', 'wc-b2b-print-manager' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'Your order will be processed immediately. No payment is required at this stage.', 'wc-b2b-print-manager' ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a simplified "Place Order" button (no payment form).
	 */
	public function render_b2b_place_order_button(): void {
		$order_button_text = apply_filters(
			'wc_b2b_place_order_button_text',
			__( 'Place Order', 'wc-b2b-print-manager' )
		);
		?>
		<div id="b2b-place-order">
			<?php wp_nonce_field( 'woocommerce-process_checkout', 'woocommerce-process-checkout-nonce' ); ?>
			<button type="submit" class="button alt wc-b2b-place-order" id="place_order"
					name="woocommerce_checkout_place_order">
				<?php echo esc_html( $order_button_text ); ?>
			</button>
		</div>
		<?php
	}
}
