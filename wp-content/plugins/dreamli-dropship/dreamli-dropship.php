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
require_once DS_PLUGIN_DIR . 'inc/class-views.php';
require_once DS_PLUGIN_DIR . 'inc/class-leaderboard.php';
require_once DS_PLUGIN_DIR . 'inc/class-entitlements.php';
require_once DS_PLUGIN_DIR . 'inc/class-snapshots.php';
require_once DS_PLUGIN_DIR . 'inc/class-keywords.php';
require_once DS_PLUGIN_DIR . 'inc/class-embeddings.php';
require_once DS_PLUGIN_DIR . 'inc/class-drive-importer.php';


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
	// Create Product Views table
	if (class_exists('DS_Views') && method_exists('DS_Views','install')) {
		DS_Views::install();
	}
	// Create Entitlements table
	if (class_exists('DS_Entitlements') && method_exists('DS_Entitlements','install')) {
		DS_Entitlements::install();
	}
	// Create Snapshots table
	if (class_exists('DS_Snapshots') && method_exists('DS_Snapshots','install')) {
		DS_Snapshots::install();
	}
    // Create Keywords tables and schedules
    if (class_exists('DS_Keywords') && method_exists('DS_Keywords','install')) {
        DS_Keywords::install();
    }
    // Create Embeddings tables
    if (class_exists('DS_Embeddings') && method_exists('DS_Embeddings','install')) {
        DS_Embeddings::install();
    }
});

add_action('plugins_loaded', function () {
    // بارگذاری ماژول‌ها
    DS_Settings::init();
    DS_Roles::init();
    $opt = DS_Settings::get();

    if (!empty($opt['feature_wallet']))            { DS_Wallet::init(); }
    if (!empty($opt['feature_content']))           { DS_Content::init(); }
    if (!empty($opt['feature_orders']))            { DS_Orders::init(); }
    if (!empty($opt['feature_vendor_menus']))      { DS_Vendor_Menus::init(); }
    // Admin menus must always be available as the landing/dashboard
    DS_Admin_Menus::init();
    if (!empty($opt['feature_calculator']))        { DS_Calculator::init(); }
	if (!empty($opt['feature_editor_ui']))          { DS_Editor_UI::init(); }
	if (!empty($opt['feature_pricing']))            { DS_Pricing::init(); }
	if (!empty($opt['feature_material_options']))   { DS_Material_Options::init(); }
	if (!empty($opt['feature_ai_product_seo']))     { DS_AI_Product_SEO::init(); }
	if (!empty($opt['feature_ads']))                { DS_Ads::init(); }
	if (!empty($opt['feature_views']))              { DS_Views::init(); }
	if (!empty($opt['feature_entitlements']))       { DS_Entitlements::init(); }
    if (!empty($opt['feature_snapshots']))         { DS_Snapshots::init(); }
    if (!empty($opt['feature_keywords']))          { DS_Keywords::init(); }
   	if (!empty($opt['feature_embeddings']))        { DS_Embeddings::init(); }
	if (!empty($opt['feature_drive_importer']))     { DS_Drive_Importer::init(); }
	// Ensure Ads table exists on updates (without reactivation)
	if (class_exists('DS_Ads') && method_exists('DS_Ads','table') && method_exists('DS_Ads','install')) {
		if (!DS_Helpers::db_table_exists(DS_Ads::table())) { DS_Ads::install(); }
	}
	// Ensure Views table exists on updates
	if (class_exists('DS_Views') && method_exists('DS_Views','table') && method_exists('DS_Views','install')) {
		if (!DS_Helpers::db_table_exists(DS_Views::table())) { DS_Views::install(); }
	}
	// Ensure Entitlements table exists on updates
	if (class_exists('DS_Entitlements') && method_exists('DS_Entitlements','table') && method_exists('DS_Entitlements','install')) {
		if (!DS_Helpers::db_table_exists(DS_Entitlements::table())) { DS_Entitlements::install(); }
	}
	// Ensure Snapshots table exists on updates
	if (class_exists('DS_Snapshots') && method_exists('DS_Snapshots','table') && method_exists('DS_Snapshots','install')) {
		if (!DS_Helpers::db_table_exists(DS_Snapshots::table())) { DS_Snapshots::install(); }
	}
    // Ensure Keywords tables exist on updates
    if (class_exists('DS_Keywords') && method_exists('DS_Keywords','table_keywords') && method_exists('DS_Keywords','install')) {
        if (!DS_Helpers::db_table_exists(DS_Keywords::table_keywords())) { DS_Keywords::install(); }
    }
    // Ensure Embeddings tables exist on updates
    if (class_exists('DS_Embeddings') && method_exists('DS_Embeddings','table_items') && method_exists('DS_Embeddings','install')) {
        global $wpdb; $t = DS_Embeddings::table_items();
        if (!DS_Helpers::db_table_exists($t)) { DS_Embeddings::install(); }
    }

});