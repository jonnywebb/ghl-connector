<?php
/*
Plugin Name: Gravity Forms HighLevel (GHL) Gravity Forms Connector
Description: Connects Gravity Forms to HighLevel V2 API with User Journey tracking.
Version: 2.5
Author: Sebo Marketing - Jonny Webb
*/

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) exit;

// 1. Initialize the Update Checker (public GitHub repo)
require 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/jonnywebb/ghl-connector/', 
    __FILE__, 
    'ghl-connector'
);

// The repo is public, so update checks need no token.
$myUpdateChecker->setBranch('main');

// 2. Network defaults (multisite): one token and location for every site
require_once __DIR__ . '/class-ghl-network.php';
GHL_Connector_Network::init();

// 3. Initialize the Add-On
add_action( 'gform_loaded', array( 'GHL_Connector_Bootstrap', 'load' ), 5 );

class GHL_Connector_Bootstrap {
    public static function load() {
        if ( ! method_exists( 'GFForms', 'include_feed_addon_framework' ) ) {
            return;
        }

        require_once( 'class-ghl-feed-addon.php' );
        GFAddOn::register( 'GHLFeedAddOn' );
    }
}

function ghl_feed_addon() {
    return GHLFeedAddOn::get_instance();
}
