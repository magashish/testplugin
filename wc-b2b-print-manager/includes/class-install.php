<?php
/**
 * Plugin Installer
 *
 * Runs on activation: creates custom DB tables, sets default options,
 * registers roles, and flushes rewrite rules.
 *
 * Database schema
 * ---------------
 * wp_b2b_artwork_library  — saved artwork files per user.
 * wp_b2b_company_pricing  — per-company product price overrides.
 *
 * @package WC_B2B\Includes
 */

declare( strict_types=1 );

namespace WC_B2B;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Install
 */
class Install {

	/**
	 * DB schema version — bump on every structural change.
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Run the installer.
	 * Called from the activation hook in the main plugin file.
	 */
	public static function run(): void {
		self::create_tables();
		self::set_default_options();
		self::create_roles();

		// Persist schema version so we can run upgrade routines later.
		update_option( 'wc_b2b_db_version', self::DB_VERSION );

		// Must be called after registering CPTs / rewrite rules.
		flush_rewrite_rules();
	}

	// -------------------------------------------------------------------------
	// Database tables
	// -------------------------------------------------------------------------

	/**
	 * Create custom tables using dbDelta() — safe to call repeatedly.
	 *
	 * Schema overview:
	 *
	 * wp_b2b_artwork_library
	 *   id            — PK
	 *   user_id       — owner (WordPress user)
	 *   company_id    — company post ID for quick scoping
	 *   title         — human-readable label
	 *   file_url      — absolute URL to the uploaded file
	 *   file_path     — server-side path (for deletion)
	 *   file_type     — MIME type
	 *   created_at    — timestamp
	 *
	 * wp_b2b_company_pricing
	 *   id            — PK
	 *   company_id    — company post ID
	 *   product_id    — WooCommerce product post ID (0 = apply to all)
	 *   pricing_type  — 'percentage' | 'fixed'
	 *   pricing_value — discount % or fixed price
	 *   created_at    — timestamp
	 */
	private static function create_tables(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		$sql = [];

		// Artwork library table.
		$sql[] = "CREATE TABLE {$wpdb->prefix}b2b_artwork_library (
			id            BIGINT(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id       BIGINT(20)   UNSIGNED NOT NULL DEFAULT 0,
			company_id    BIGINT(20)   UNSIGNED NOT NULL DEFAULT 0,
			title         VARCHAR(255) NOT NULL DEFAULT '',
			file_url      TEXT         NOT NULL,
			file_path     TEXT         NOT NULL,
			file_type     VARCHAR(100) NOT NULL DEFAULT '',
			created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id    (user_id),
			KEY company_id (company_id)
		) $charset;";

		// Company-level pricing overrides.
		$sql[] = "CREATE TABLE {$wpdb->prefix}b2b_company_pricing (
			id            BIGINT(20)       UNSIGNED NOT NULL AUTO_INCREMENT,
			company_id    BIGINT(20)       UNSIGNED NOT NULL DEFAULT 0,
			product_id    BIGINT(20)       UNSIGNED NOT NULL DEFAULT 0,
			pricing_type  ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
			pricing_value DECIMAL(10, 4)   UNSIGNED NOT NULL DEFAULT 0.0000,
			created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY company_id (company_id),
			KEY product_id (product_id)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( $sql as $query ) {
			dbDelta( $query );
		}
	}

	// -------------------------------------------------------------------------
	// Default options
	// -------------------------------------------------------------------------

	/**
	 * Insert plugin options if they do not yet exist.
	 */
	private static function set_default_options(): void {
		$defaults = [
			'wc_b2b_require_approval_default' => 'no',   // Global fallback setting.
			'wc_b2b_artwork_max_size_mb'       => 20,     // Max upload size (MB).
			'wc_b2b_allowed_file_types'        => 'pdf,ai,eps,jpg,jpeg,png,tiff,tif',
			'wc_b2b_order_status_no_approval'  => 'processing',
			'wc_b2b_order_status_approval'     => 'wc-pending-approval',
		];

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key ) ) {
				add_option( $key, $value );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Roles
	// -------------------------------------------------------------------------

	/**
	 * Add custom user roles.
	 * Roles are stored in wp_options so add_role() is safe to call on activation.
	 */
	private static function create_roles(): void {
		// Company Admin — manages agents + orders within their company.
		add_role(
			'company_admin',
			__( 'Company Admin', 'wc-b2b-print-manager' ),
			[
				'read'                   => true,
				'edit_posts'             => false,
				'delete_posts'           => false,
				// Custom caps.
				'manage_company_agents'  => true,
				'view_company_orders'    => true,
				'approve_company_orders' => true,
				'manage_artwork_library' => true,
			]
		);

		// Agent — places orders on behalf of their company.
		add_role(
			'agent',
			__( 'Agent', 'wc-b2b-print-manager' ),
			[
				'read'                   => true,
				'edit_posts'             => false,
				'delete_posts'           => false,
				// Custom caps.
				'view_own_orders'        => true,
				'upload_artwork'         => true,
				'manage_artwork_library' => true,
			]
		);

		// Grant Super Admin (administrator) all custom caps.
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( 'manage_companies' );
			$admin->add_cap( 'manage_company_agents' );
			$admin->add_cap( 'view_all_orders' );
			$admin->add_cap( 'approve_company_orders' );
			$admin->add_cap( 'manage_artwork_library' );
		}
	}

	// -------------------------------------------------------------------------
	// Upgrade routine (called on plugins_loaded when version mismatch)
	// -------------------------------------------------------------------------

	/**
	 * Run upgrade routines when the stored DB version differs.
	 * Hooked from the main Plugin class via 'plugins_loaded'.
	 */
	public static function maybe_upgrade(): void {
		$installed = get_option( 'wc_b2b_db_version', '0' );
		if ( version_compare( $installed, self::DB_VERSION, '<' ) ) {
			self::create_tables();
			update_option( 'wc_b2b_db_version', self::DB_VERSION );
		}
	}

	// -------------------------------------------------------------------------
	// Uninstall helpers (called from uninstall.php)
	// -------------------------------------------------------------------------

	/**
	 * Drop custom tables and options.
	 * Only invoked from uninstall.php.
	 */
	public static function uninstall(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}b2b_artwork_library" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}b2b_company_pricing" );
		// phpcs:enable

		$options = [
			'wc_b2b_db_version',
			'wc_b2b_require_approval_default',
			'wc_b2b_artwork_max_size_mb',
			'wc_b2b_allowed_file_types',
			'wc_b2b_order_status_no_approval',
			'wc_b2b_order_status_approval',
		];

		foreach ( $options as $option ) {
			delete_option( $option );
		}

		remove_role( 'company_admin' );
		remove_role( 'agent' );
	}
}
