<?php
/**
 * Plugin Name: Gravity Forms HighLevel (GHL) Connector
 * Plugin URI:  https://github.com/jonnywebb/ghl-connector
 * Description: Map Gravity Forms fields to HighLevel contacts, notes and pipeline opportunities without a premium plugin. Pairs with Sebo Attribution or Campaign Tracker for lead source.
 * Version:     2.5.1
 * Author:      Sebo Marketing - Jonny Webb
 * Requires PHP: 7.0
 * License:     GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GHL_CONNECTOR_VERSION',  '2.5.1' );
define( 'GHL_CONNECTOR_FILE',     __FILE__ );
define( 'GHL_CONNECTOR_PATH',     plugin_dir_path( __FILE__ ) );
define( 'GHL_CONNECTOR_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Updates come from this repo's GitHub releases. The repo is public, so no
 * token is needed.
 */
require GHL_CONNECTOR_PATH . 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$ghl_connector_updates = PucFactory::buildUpdateChecker(
	'https://github.com/jonnywebb/ghl-connector/',
	__FILE__,
	'ghl-connector'
);
$ghl_connector_updates->setBranch( 'main' );

/**
 * Multisite: one token and Location ID for the whole network, which each site
 * can override.
 */
require_once GHL_CONNECTOR_PATH . 'class-ghl-network.php';
GHL_Connector_Network::init();

add_action( 'gform_loaded', array( 'GHL_Connector_Bootstrap', 'load' ), 5 );

class GHL_Connector_Bootstrap {

	public static function load() {
		if ( ! method_exists( 'GFForms', 'include_feed_addon_framework' ) ) {
			return;
		}

		require_once GHL_CONNECTOR_PATH . 'class-ghl-api.php';
		require_once GHL_CONNECTOR_PATH . 'class-ghl-feed-addon.php';

		GFAddOn::register( 'GHLFeedAddOn' );
	}
}

function ghl_feed_addon() {
	return GHLFeedAddOn::get_instance();
}

/**
 * Admin-side cache buster for the GHL field / pipeline / user lists.
 */
add_action( 'admin_init', function () {
	if ( empty( $_GET['ghl_refresh_lists'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'gravityforms_edit_forms' ) ) {
		return;
	}
	if ( ! isset( $_GET['_ghlnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_ghlnonce'] ) ), 'ghl_refresh_lists' ) ) {
		return;
	}

	if ( class_exists( 'GHL_API' ) ) {
		GHL_API::flush_all_caches();
	}

	wp_safe_redirect( remove_query_arg( array( 'ghl_refresh_lists', '_ghlnonce' ) ) );
	exit;
} );
