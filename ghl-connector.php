<?php
/*
Plugin Name: Gravity Forms HighLevel (GHL) Gravity Forms Connector
Description: Connects Gravity Forms to HighLevel V2 API with User Journey tracking.
Version: 2.3
Author: Sebo Marketing - Jonny Webb
*/

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) exit;

// 1. Initialize the Update Checker (GitHub Private Repo)
require 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/jonnywebb/ghl-connector/', 
    __FILE__, 
    'ghl-connector'
);

// Set Authentication for Private Repo
$myUpdateChecker->setAuthentication('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');
$myUpdateChecker->setBranch('main');

// 2. Initialize the Add-On
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
