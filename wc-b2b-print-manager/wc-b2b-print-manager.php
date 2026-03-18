<?php
/**
 * Plugin Name:       WC B2B Print Manager
 * Plugin URI:        https://example.com/wc-b2b-print-manager
 * Description:       A WooCommerce-based B2B ordering system for real estate print service platforms. Supports multi-company management, custom roles, dynamic pricing, artwork uploads, and order approval workflows.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Print Manager Dev
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wc-b2b-print-manager
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   8.5
 *
 * @package WC_B2B_Print_Manager
 */

declare( strict_types=1 );

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'WC_B2B_VERSION', '1.0.0' );
define( 'WC_B2B_PLUGIN_FILE', __FILE__ );
define( 'WC_B2B_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_B2B_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WC_B2B_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Check if WooCommerce is active before loading plugin.
 *
 * @return bool True if WooCommerce is active.
 */
function wc_b2b_is_woocommerce_active(): bool {
	return in_array(
		'woocommerce/woocommerce.php',
		apply_filters( 'active_plugins', get_option( 'active_plugins', [] ) ),
		true
	);
}

/**
 * Display admin notice when WooCommerce is missing.
 */
function wc_b2b_missing_woocommerce_notice(): void {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				/* translators: %s: WooCommerce plugin link */
				esc_html__( 'WC B2B Print Manager requires %s to be installed and active.', 'wc-b2b-print-manager' ),
				'<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>'
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Main plugin initialisation.
 * Hooked late to ensure WooCommerce and all plugins are loaded first.
 */
function wc_b2b_init(): void {
	if ( ! wc_b2b_is_woocommerce_active() ) {
		add_action( 'admin_notices', 'wc_b2b_missing_woocommerce_notice' );
		return;
	}

	// Load text domain for i18n.
	load_plugin_textdomain(
		'wc-b2b-print-manager',
		false,
		dirname( WC_B2B_PLUGIN_BASENAME ) . '/languages'
	);

	// Boot the plugin.
	require_once WC_B2B_PLUGIN_DIR . 'includes/class-plugin.php';
	\WC_B2B\Plugin::get_instance();
}
add_action( 'plugins_loaded', 'wc_b2b_init' );

/**
 * Plugin activation hook — runs installer.
 */
function wc_b2b_activate(): void {
	if ( ! wc_b2b_is_woocommerce_active() ) {
		wp_die(
			esc_html__( 'WC B2B Print Manager requires WooCommerce. Please install and activate WooCommerce first.', 'wc-b2b-print-manager' ),
			esc_html__( 'Plugin Activation Error', 'wc-b2b-print-manager' ),
			[ 'back_link' => true ]
		);
	}

	require_once WC_B2B_PLUGIN_DIR . 'includes/class-install.php';
	\WC_B2B\Install::run();
}
register_activation_hook( __FILE__, 'wc_b2b_activate' );

/**
 * Plugin deactivation hook.
 */
function wc_b2b_deactivate(): void {
	// Flush rewrite rules so CPT slugs are removed cleanly.
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'wc_b2b_deactivate' );
