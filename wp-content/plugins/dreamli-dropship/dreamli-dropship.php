<?php
/**
 * Plugin Name: Dreamli Dropship
 * Description: Roles, Wallet/Ledger, Curated credit on publish, per-item platform fees, vendor dashboards, posts/products moderation, settings, and price calculator.
 * Version: 1.0.0
 * Author: Dreamli
 * Text Domain: dreamli-dropship
 */


if (!defined('ABSPATH')) exit;

define('DS_PLUGIN_VERSION', '1.0.0');
define('DS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DS_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once DS_PLUGIN_DIR . 'inc/helpers.php';
require_once DS_PLUGIN_DIR . 'inc/class-settings.php';
require_once DS_PLUGIN_DIR . 'inc/class-roles.php';
require_once DS_PLUGIN_DIR . 'inc/class-wallet.php';
require_once DS_PLUGIN_DIR . 'inc/class-content.php';
require_once DS_PLUGIN_DIR . 'inc/class-orders.php';
require_once DS_PLUGIN_DIR . 'inc/class-vendor-menus.php';
require_once DS_PLUGIN_DIR . 'inc/class-admin-menus.php';
require_once DS_PLUGIN_DIR . 'inc/class-calculator.php';
require_once DS_PLUGIN_DIR . 'inc/class-editor-ui.php';
require_once DS_PLUGIN_DIR . 'inc/class-pricing.php';
require_once DS_PLUGIN_DIR . 'inc/class-material-options.php';
require_once DS_PLUGIN_DIR . 'inc/class-ai-product-seo.php';
require_once DS_PLUGIN_DIR . 'inc/class-ads.php';


register_activation_hook(__FILE__, function () {
    // Tables and default settings
    DS_Wallet::create_table();
    if (!get_option('ds_settings')) {
        update_option('ds_settings', DS_Settings::defaults());
    }
    // Create AI jobs table (async polling)
    if (class_exists('DS_AI_Product_SEO') && method_exists('DS_AI_Product_SEO', 'install')) {
        DS_AI_Product_SEO::install();
    }
	// Create Ads table (CPC campaigns)
	if (class_exists('DS_Ads') && method_exists('DS_Ads', 'install')) {
		DS_Ads::install();
	}
});

add_action('plugins_loaded', function () {
    // بارگذاری ماژول‌ها
    DS_Settings::init();
    DS_Roles::init();
    DS_Wallet::init();
    DS_Content::init();
    DS_Orders::init();
    DS_Vendor_Menus::init();
    DS_Admin_Menus::init();
    DS_Calculator::init();
	DS_Editor_UI::init();
	DS_Pricing::init();
	DS_Material_Options::init();
	DS_AI_Product_SEO::init();
	DS_Ads::init();
	// Ensure Ads table exists on updates (without reactivation)
	if (class_exists('DS_Ads') && method_exists('DS_Ads','table') && method_exists('DS_Ads','install')) {
		if (!DS_Helpers::db_table_exists(DS_Ads::table())) { DS_Ads::install(); }
	}

});