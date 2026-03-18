<?php
/**
 * Plugin Uninstall
 *
 * Fires when the user clicks "Delete" in the WP plugin screen.
 * Removes all plugin data: DB tables, options, custom roles.
 *
 * This file is executed by WordPress directly — not via the plugin's
 * autoloader — so we bootstrap only what we need.
 *
 * @package WC_B2B_Print_Manager
 */

// Only run if WordPress is uninstalling the plugin.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-install.php';
\WC_B2B\Install::uninstall();
