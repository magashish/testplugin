<?php
/**
 * Pricing Engine
 *
 * Dynamically modifies WooCommerce product prices based on company-level
 * pricing rules stored in post meta.
 *
 * Two pricing modes:
 *   - percentage: regular_price * (1 - discount/100)
 *   - fixed:      overrides price with a flat value
 *
 * Product-level overrides in wp_b2b_company_pricing take precedence over
 * the company-wide rule (product_id = 0 row is the fallback).
 *
 * @package WC_B2B\Includes
 */

declare( strict_types=1 );

namespace WC_B2B;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pricing_Engine
 */
class Pricing_Engine {

	/**
	 * In-request cache: company_id => pricing rule array.
	 *
	 * @var array<int, array>
	 */
	private static array $rule_cache = [];

	/**
	 * Register WooCommerce price hooks.
	 */
	public function __construct() {
		// Hook into WooCommerce price filters (fires on loop + single product).
		add_filter( 'woocommerce_product_get_price', [ $this, 'apply_company_price' ], 20, 2 );
		add_filter( 'woocommerce_product_get_sale_price', [ $this, 'apply_company_price' ], 20, 2 );
		add_filter( 'woocommerce_product_get_regular_price', [ $this, 'apply_company_price' ], 20, 2 );

		// Variable product price range.
		add_filter( 'woocommerce_variation_prices_price', [ $this, 'apply_company_price_to_variation' ], 20, 3 );
		add_filter( 'woocommerce_variation_prices_regular_price', [ $this, 'apply_company_price_to_variation' ], 20, 3 );
		add_filter( 'woocommerce_variation_prices_sale_price', [ $this, 'apply_company_price_to_variation' ], 20, 3 );

		// Ensure variation price cache is bypassed per-user (uses transients keyed on user).
		add_filter( 'woocommerce_get_variation_prices_hash', [ $this, 'add_company_to_price_hash' ], 10, 1 );
	}

	// -------------------------------------------------------------------------
	// Price Filter Callbacks
	// -------------------------------------------------------------------------

	/**
	 * Apply the company pricing rule to a simple product price.
	 *
	 * @param string            $price   Original price string.
	 * @param \WC_Product       $product WooCommerce product object.
	 * @return string Modified price.
	 */
	public function apply_company_price( string $price, \WC_Product $product ): string {
		$company_id = Company_Manager::get_user_company_id();
		if ( ! $company_id || '' === $price ) {
			return $price;
		}

		$rule = $this->get_pricing_rule( $company_id, $product->get_id() );
		return $this->calculate_price( $price, $rule );
	}

	/**
	 * Apply pricing to variation prices (used in the price range cache).
	 *
	 * @param string             $price     Original price.
	 * @param \WC_Product        $variation Variation product.
	 * @param \WC_Product        $product   Parent variable product.
	 * @return string
	 */
	public function apply_company_price_to_variation( string $price, \WC_Product $variation, \WC_Product $product ): string {
		$company_id = Company_Manager::get_user_company_id();
		if ( ! $company_id || '' === $price ) {
			return $price;
		}

		// Check for variation-specific rule first, fall back to parent product rule.
		$rule = $this->get_pricing_rule( $company_id, $variation->get_id() )
			?? $this->get_pricing_rule( $company_id, $product->get_id() );

		return $this->calculate_price( $price, $rule );
	}

	/**
	 * Append company ID to the variation price cache hash so each company
	 * gets its own cached price set.
	 *
	 * @param array $hash Existing hash array.
	 * @return array
	 */
	public function add_company_to_price_hash( array $hash ): array {
		$hash[] = Company_Manager::get_user_company_id();
		return $hash;
	}

	// -------------------------------------------------------------------------
	// Core Calculation
	// -------------------------------------------------------------------------

	/**
	 * Apply a pricing rule to a price string.
	 *
	 * @param string     $price Raw price string.
	 * @param array|null $rule  ['type' => string, 'value' => float] or null.
	 * @return string Calculated price string.
	 */
	private function calculate_price( string $price, ?array $rule ): string {
		if ( null === $rule || 0.0 === $rule['value'] ) {
			return $price;
		}

		$numeric = (float) $price;
		if ( $numeric <= 0 ) {
			return $price;
		}

		switch ( $rule['type'] ) {
			case 'percentage':
				// Clamp discount between 0–100 %.
				$discount = min( max( $rule['value'], 0 ), 100 );
				$new_price = $numeric * ( 1 - $discount / 100 );
				break;

			case 'fixed':
				$new_price = max( $rule['value'], 0 );
				break;

			default:
				return $price;
		}

		return (string) round( $new_price, wc_get_price_decimals() );
	}

	// -------------------------------------------------------------------------
	// Rule Resolution
	// -------------------------------------------------------------------------

	/**
	 * Resolve the pricing rule for a company + product combination.
	 *
	 * Priority: product-specific DB row > product-specific post meta >
	 *           company-wide DB row > company-wide post meta.
	 *
	 * @param int $company_id Company post ID.
	 * @param int $product_id WooCommerce product ID.
	 * @return array|null ['type' => string, 'value' => float] or null if no rule.
	 */
	private function get_pricing_rule( int $company_id, int $product_id ): ?array {
		$cache_key = "{$company_id}_{$product_id}";
		if ( isset( self::$rule_cache[ $cache_key ] ) ) {
			return self::$rule_cache[ $cache_key ];
		}

		// 1. Check the custom pricing table for a product-specific override.
		$db_rule = $this->get_db_pricing_rule( $company_id, $product_id );
		if ( $db_rule ) {
			self::$rule_cache[ $cache_key ] = $db_rule;
			return $db_rule;
		}

		// 2. Fall back to company-wide rule from post meta.
		$meta_rule = Company_Manager::get_company_pricing( $company_id );
		$rule      = $meta_rule['value'] > 0 ? $meta_rule : null;

		self::$rule_cache[ $cache_key ] = $rule;
		return $rule;
	}

	/**
	 * Query the wp_b2b_company_pricing table for an explicit override.
	 *
	 * @param int $company_id Company post ID.
	 * @param int $product_id Product ID (0 for company-wide rows).
	 * @return array|null
	 */
	private function get_db_pricing_rule( int $company_id, int $product_id ): ?array {
		global $wpdb;

		// Try product-specific rule first, then catch-all (product_id = 0).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT pricing_type, pricing_value
				 FROM {$wpdb->prefix}b2b_company_pricing
				 WHERE company_id = %d
				   AND (product_id = %d OR product_id = 0)
				 ORDER BY product_id DESC
				 LIMIT 1",
				$company_id,
				$product_id
			)
		);
		// phpcs:enable

		if ( ! $row ) {
			return null;
		}

		return [
			'type'  => $row->pricing_type,
			'value' => (float) $row->pricing_value,
		];
	}

	// -------------------------------------------------------------------------
	// Admin API
	// -------------------------------------------------------------------------

	/**
	 * Save a per-product pricing override for a company.
	 *
	 * @param int    $company_id    Company post ID.
	 * @param int    $product_id    Product ID (0 = company-wide).
	 * @param string $pricing_type  'percentage' | 'fixed'.
	 * @param float  $pricing_value Discount % or fixed price.
	 * @return bool
	 */
	public static function save_pricing_override(
		int $company_id,
		int $product_id,
		string $pricing_type,
		float $pricing_value
	): bool {
		global $wpdb;

		if ( ! in_array( $pricing_type, [ 'percentage', 'fixed' ], true ) ) {
			return false;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}b2b_company_pricing
				 WHERE company_id = %d AND product_id = %d",
				$company_id,
				$product_id
			)
		);

		if ( $existing ) {
			$result = $wpdb->update(
				$wpdb->prefix . 'b2b_company_pricing',
				[
					'pricing_type'  => $pricing_type,
					'pricing_value' => $pricing_value,
				],
				[
					'company_id' => $company_id,
					'product_id' => $product_id,
				],
				[ '%s', '%f' ],
				[ '%d', '%d' ]
			);
		} else {
			$result = $wpdb->insert(
				$wpdb->prefix . 'b2b_company_pricing',
				[
					'company_id'    => $company_id,
					'product_id'    => $product_id,
					'pricing_type'  => $pricing_type,
					'pricing_value' => $pricing_value,
				],
				[ '%d', '%d', '%s', '%f' ]
			);
		}
		// phpcs:enable

		// Clear in-memory cache.
		self::$rule_cache = [];

		return false !== $result;
	}
}
