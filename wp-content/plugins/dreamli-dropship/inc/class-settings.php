<?php
if (!defined('ABSPATH')) exit;

final class DS_Settings {

    const OPTION_KEY = 'ds_settings';

    public static function init() {
        // Consolidated under DS_Admin_Menus; no separate DS_Settings admin pages.
    }

    /** ---------- Defaults (merged with your current values) ---------- */
    public static function defaults() {
        $materials_default = [
            "Basic"       => ["price_per_kg_full"=>23, "price_per_kg_market"=>14, "discountable"=>true],
            "Galaxy"      => ["price_per_kg_full"=>28, "price_per_kg_market"=>28, "discountable"=>false],
            "Glow"        => ["price_per_kg_full"=>28, "price_per_kg_market"=>28, "discountable"=>false, "premium_uplift_pct"=>0.20],
            "Marble"      => ["price_per_kg_full"=>28, "price_per_kg_market"=>28, "discountable"=>false],
            "Matte"       => ["price_per_kg_full"=>23, "price_per_kg_market"=>14, "discountable"=>true],
			"Sparkle"     => ["price_per_kg_full"=>28, "price_per_kg_market"=>28, "discountable"=>false],
            "Metallic"    => ["price_per_kg_full"=>28, "price_per_kg_market"=>28, "discountable"=>false],
            "Translucent" => ["price_per_kg_full"=>28, "price_per_kg_market"=>25, "discountable"=>false],
            "Wood"        => ["price_per_kg_full"=>28, "price_per_kg_market"=>28, "discountable"=>false],
            "Silk"        => ["price_per_kg_full"=>26, "price_per_kg_market"=>17, "discountable"=>true],
        ];

        return [
            // --- Claim & Re-claim Fees (dynamic)
            'claim_fee_enable' => 1,
            'entitlement_dynamic_fee_enable' => 1,
            'claim_fee_period' => 'rolling_30d', // rolling_30d | month_to_date | previous_month
            'claim_fee_price_basis' => 'current', // current | regular | sale
            'claim_fee_denominator_scope' => 'published', // published | published_private | all
            'claim_fee_min_pct' => 0.0,
            'claim_fee_max_pct' => 100.0,
            'claim_fee_min_eur' => 0.00,
            'claim_fee_cache_minutes' => 60,
            'claim_fee_statuses' => ['processing','completed'],
            // --- Moderation
            'posts_pending'    => 1,
            'products_pending' => 1,

                        // --- Vendor Admin reviewer reward
                        'vendor_admin_publish_reward_eur' => 0.50, 

            // --- Ads (CPC)
            'ads_cpc_home'     => 0.10,
            'ads_cpc_category' => 0.10,

            // --- Curated credit
            'curated_credit_enable' => 1,
            'curated_credit_amount' => 2.00,
            'credit_min_images'     => 0,
            'credit_min_words'      => 0,
            'credit_min_rankmath'   => 0,

            // --- Fees
            'open_fee_per_item' => 1.00,
            'curated_first_n'   => 4,
            'curated_first_fee' => 0.50,
            'curated_after_fee' => 1.00,

            // --- Wallet
            'withdraw_min' => 1.00,

                        // --- Entitlements (monthly ownership protection)
                        'entitlements_enable' => 1,
                        'entitlement_fee_eur' => 2.00,
                        'entitlement_confirm_days' => 7,
                        'pool_user_id' => 0,

                                    // --- Entitlement routing and protections
                                    'entitlement_controls_payouts_ads' => 1,
                                    'pause_payouts_while_pending' => 0,
                                    'ads_autopause_on_entitlement_loss' => 1,

                                    // --- Product completeness protections (vendor)
                                    'protect_status_demotion' => 1,
                                    'require_featured_image' => 1,
                                    'require_product_category' => 1,
                                    'min_title_len' => 6,
                                    'min_content_len' => 120,

                                    // --- Snapshots
                                    'snapshot_retention_days' => 90, 

            // --- View Payouts and Caps
            'enable_view_payouts' => 0,
            'view_payout_rate_eur' => 0.01,
            // Count caps (0 = disabled)
            'view_cap_per_ip_per_product_per_day' => 3,
            'view_cap_per_ip_per_day_sitewide'    => 50,
            'view_cap_per_viewer_per_day_sitewide'=> 20,
            'view_cap_per_product_per_day'        => 2000,
            'view_cap_per_vendor_per_day'         => 5000,
            'view_cap_per_vendor_per_month'       => 100000,
            'view_cap_sitewide_per_day'           => 50000,
            'view_cap_sitewide_per_month'         => 1500000,
            // Payout € caps
            'payout_cap_per_vendor_per_day_eur'   => 200.00,
            'payout_cap_per_vendor_per_month_eur' => 5000.00,
            'payout_cap_sitewide_per_day_eur'     => 2000.00,
            'payout_cap_sitewide_per_month_eur'   => 50000.00,
            // Bot/exclusion controls
            'view_ua_denylist' => ['bot','crawler','spider','headless','monitoring'],
            'view_pay_for_bots' => 0,
            'view_record_bots'  => 1,
            'view_excluded_vendors'  => [],
            'view_excluded_products' => [],

            // --- Calculator (reserved)
            'calc_mode' => 'linear',
            'calc_base' => 2.00,
            'calc_rate_per_gram' => 0.02,
            'calc_rate_per_min'  => 0.10,
            'calc_markup_percent'=> 0.0,
            'calc_round_cents'   => 1,
            'calc_custom_expr_enable'=>0,
            'calc_custom_expr' => '(base + weight_g*rate_g + time_min*rate_min) * (1 + markup/100)',

            // --- Product Options (WAPF) meta key; empty = auto-detect
            'po_meta_key' => '',
            // IDs for WAPF "Type" fields (optional)
            'po_type_field_main_id' => '',
            'po_type_field_secondary_id' => '',
            // Choice pricing rules per material for WAPF (used by Dropship settings UI)
            'po_choice_rules' => [],

            // --- Pricing Engine config
            'pe_waste_rate' => 0.08,
            'pe_fail_risk_rate' => 0.05,
            'pe_electricity_kwh' => 0.30,
            'pe_printer_power_w' => 120,
            'pe_labor_rate' => 14.0,
            'pe_labor_fixed_min' => 15,
            'pe_packaging' => 1.20,
            'pe_misc' => 0.50,
            'pe_dep_full' => 0.60,
            'pe_oh_full'  => 0.80,
            'pe_dep_market' => 0.20,
            'pe_oh_market'  => 0.20,
            'pe_profit_hint_full' => 16.0,
            'pe_profit_hint_market' => 12.0,
            'pe_materials' => $materials_default,
        ];
    }

    /** ---------- Get/Update keeping backward compatibility ---------- */
    public static function get() {
        $def = self::defaults();
        $opt = get_option(self::OPTION_KEY);
        if (!is_array($opt)) $opt = [];

        // Merge with defaults
        $opt = array_merge($def, $opt);

        // Ensure pe_materials is array
        if (!isset($opt['pe_materials']) || !is_array($opt['pe_materials'])) {
            $opt['pe_materials'] = $def['pe_materials'];
        }

        // Normalize choice pricing rules used by Dropship UI
        if (!isset($opt['po_choice_rules']) || !is_array($opt['po_choice_rules'])) {
            $opt['po_choice_rules'] = [];
        }
        $norm_rules = [];
        foreach (array_keys($opt['pe_materials']) as $mat) {
            $row = $opt['po_choice_rules'][$mat] ?? ['type'=>'inherit','value'=>0,'extra'=>0];
            $type = in_array(($row['type'] ?? 'inherit'), ['inherit','fixed','percent','none'], true) ? $row['type'] : 'inherit';
            $norm_rules[$mat] = [
                'type'  => $type,
                'value' => isset($row['value']) ? (float)$row['value'] : 0.0,
                'extra' => isset($row['extra']) ? (float)$row['extra'] : 0.0,
            ];
        }
        $opt['po_choice_rules'] = $norm_rules;

        return $opt;
    }

    public static function update($new) {
        $cur = self::get();
        update_option(self::OPTION_KEY, array_merge($cur, $new));
    }

}
