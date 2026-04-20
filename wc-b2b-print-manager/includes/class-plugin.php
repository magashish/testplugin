<?php
/**
 * Core Plugin Bootstrap
 *
 * Instantiates all modules and wires them together via WordPress hooks.
 * This is the single entry point — only this class is allowed to `new` other modules.
 *
 * @package WC_B2B\Includes
 */

declare( strict_types=1 );

namespace WC_B2B;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Plugin
 *
 * Singleton that boots every feature module.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Module registry — keeps references alive.
	 *
	 * @var array<string, object>
	 */
	private array $modules = [];

	/**
	 * Private constructor — use get_instance().
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->init_modules();
	}

	/**
	 * Return (and lazily create) the singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	/**
	 * Require all class files.
	 * Autoloading is avoided intentionally to keep the plugin dependency-free.
	 */
	private function load_dependencies(): void {
		$includes = WC_B2B_PLUGIN_DIR . 'includes/';
		$admin    = WC_B2B_PLUGIN_DIR . 'admin/';
		$public   = WC_B2B_PLUGIN_DIR . 'public/';

		// Core utilities.
		require_once $includes . 'class-install.php';
		require_once $includes . 'class-company-manager.php';
		require_once $includes . 'class-role-manager.php';
		require_once $includes . 'class-pricing-engine.php';
		require_once $includes . 'class-b2b-gateway.php';
		require_once $includes . 'class-checkout-customizer.php';
		require_once $includes . 'class-invoice-manager.php';
		require_once $includes . 'class-order-controller.php';
		require_once $includes . 'class-artwork-manager.php';
		require_once $includes . 'class-reorder-manager.php';

		// Admin layer.
		if ( is_admin() ) {
			require_once $admin . 'class-admin.php';
			require_once $admin . 'class-company-admin.php';
			require_once $admin . 'class-order-admin.php';
		}

		// Public / front-end layer.
		if ( ! is_admin() ) {
			require_once $public . 'class-public.php';
			require_once $public . 'class-dashboard.php';
		}
	}

	/**
	 * Instantiate every module.
	 * Each module registers its own hooks in its constructor.
	 */
	private function init_modules(): void {
		// Always-on modules (both admin and front-end need these).
		$this->modules['company_manager']    = new Company_Manager();
		$this->modules['role_manager']       = new Role_Manager();
		$this->modules['pricing_engine']     = new Pricing_Engine();
		$this->modules['checkout_customizer'] = new Checkout_Customizer();
		$this->modules['order_controller']   = new Order_Controller();
		$this->modules['artwork_manager']    = new Artwork_Manager();
		$this->modules['reorder_manager']    = new Reorder_Manager();

		// Admin-only modules.
		if ( is_admin() ) {
			$this->modules['admin']         = new Admin\Admin();
			$this->modules['company_admin'] = new Admin\Company_Admin();
			$this->modules['order_admin']   = new Admin\Order_Admin();
		}

		// Front-end modules.
		// B2B_Public also owns the wp_ajax_* handlers for frontend AJAX actions.
		// admin-ajax.php sets is_admin()=true, so we must instantiate it during
		// AJAX requests too — otherwise those actions are never registered.
		if ( ! is_admin() || wp_doing_ajax() ) {
			$this->modules['public'] = new Frontend\B2B_Public();
		}
		if ( ! is_admin() ) {
			$this->modules['dashboard'] = new Frontend\Dashboard();
		}
	}

	/**
	 * Retrieve a loaded module by key.
	 *
	 * @param string $key Module key.
	 * @return object|null
	 */
	public function get_module( string $key ): ?object {
		return $this->modules[ $key ] ?? null;
	}
}
