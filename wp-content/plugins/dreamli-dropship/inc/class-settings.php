<?php
if (!defined('ABSPATH')) exit;

final class DS_Settings {

    const OPTION_KEY = 'ds_settings';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
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
            // --- Moderation
            'posts_pending'    => 1,
            'products_pending' => 1,

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

            // --- NEW: APF/WAPF Choice Pricing Controller
            // تیک فعال‌سازی: اگر روشن باشد، هنگام «اعمال»، قیمت گزینه‌های Type از روی این جدول ست می‌شود
            'apf_choice_apply' => 0,
            // برای هر متریال: type=fixed|percent ، اگر fixed باشد extra به دلتا اضافه می‌شود؛ اگر percent باشد همان درصد به WAPF داده می‌شود
            'apf_choice_pricing' => [
                // پر می‌شود در get() بر اساس pe_materials
            ],
        ];
    }

    /** ---------- Get/Update keeping backward compatibility ---------- */
    public static function get() {
        $def = self::defaults();
        $opt = get_option(self::OPTION_KEY);
        if (!is_array($opt)) $opt = [];

        // Merge with defaults (your previous behavior)
        $opt = array_merge($def, $opt);

        // Ensure pe_materials is array
        if (!isset($opt['pe_materials']) || !is_array($opt['pe_materials'])) {
            $opt['pe_materials'] = $def['pe_materials'];
        }

        // Ensure apf_choice_pricing skeleton exists for all materials
        if (!isset($opt['apf_choice_pricing']) || !is_array($opt['apf_choice_pricing'])) {
            $opt['apf_choice_pricing'] = [];
        }
        foreach (array_keys($opt['pe_materials']) as $mat) {
            if (!isset($opt['apf_choice_pricing'][$mat])) {
                $opt['apf_choice_pricing'][$mat] = [
                    'type'    => 'fixed', // fixed | percent
                    'percent' => 0,
                    'extra'   => 0,
                ];
            } else {
                $row = $opt['apf_choice_pricing'][$mat];
                $opt['apf_choice_pricing'][$mat] = [
                    'type'    => in_array(($row['type'] ?? 'fixed'), ['fixed','percent'], true) ? $row['type'] : 'fixed',
                    'percent' => floatval($row['percent'] ?? 0),
                    'extra'   => floatval($row['extra'] ?? 0),
                ];
            }
        }

        // Normalize apply switch
        $opt['apf_choice_apply'] = !empty($opt['apf_choice_apply']) ? 1 : 0;

        return $opt;
    }

    public static function update($new) {
        $cur = self::get();
        update_option(self::OPTION_KEY, array_merge($cur, $new));
    }

    /** ---------- Admin UI ---------- */
    public static function add_menu() {
        // Root
        add_menu_page(
            'Dreamli',
            'Dreamli',
            'manage_woocommerce',
            'ds_root',
            function(){ echo '<div class="wrap"><h1>Dreamli</h1><p>Use submenus.</p></div>'; },
            'dashicons-admin-generic',
            58
        );

        // Pricing & Fields
        add_submenu_page(
            'ds_root',
            'Pricing & Fields',
            'Pricing & Fields',
            'manage_woocommerce',
            'ds_pricing',
            [__CLASS__, 'render_page']
        );
    }

    public static function register_settings() {
        register_setting(self::OPTION_KEY, self::OPTION_KEY);

        add_settings_section('ds_section_apf', 'APF/WAPF – Choice Pricing Controller', function(){
            echo '<p>نوع و مقدار قیمت گزینه‌های <strong>Type</strong> (متریال‌ها). اگر «Apply» روشن باشد، هنگام «اعمال»، این قواعد روی گزینه‌های Type در WAPF نوشته می‌شود.</p>';
        }, self::OPTION_KEY);

        add_settings_field('apf_choice_apply', 'Apply choice pricing from settings', function(){
            $opt = self::get();
            printf(
                '<label><input type="checkbox" name="%s[apf_choice_apply]" value="1" %s> فعال</label>',
                esc_attr(self::OPTION_KEY),
                checked(!empty($opt['apf_choice_apply']), true, false)
            );
        }, self::OPTION_KEY, 'ds_section_apf');

        add_settings_field('apf_choice_pricing', 'Per-material rules', function(){
            $opt = self::get();
            $mats = array_keys($opt['pe_materials']);
            echo '<table class="widefat striped" style="max-width:1000px"><thead><tr>';
            echo '<th>Material</th><th>Type</th><th style="width:160px">Percent (%%)</th><th style="width:160px">Extra (fixed €)</th>';
            echo '</tr></thead><tbody>';
            foreach ($mats as $m) {
                $row = $opt['apf_choice_pricing'][$m] ?? ['type'=>'fixed','percent'=>0,'extra'=>0];
                $type = esc_attr($row['type']);
                $percent = esc_attr($row['percent']);
                $extra = esc_attr($row['extra']);
                echo '<tr>';
                echo '<td><code>'.esc_html($m).'</code></td>';
                echo '<td><select name="'.esc_attr(self::OPTION_KEY).'[apf_choice_pricing]['.esc_attr($m).'][type]">';
                echo '<option value="fixed" '.selected($type,'fixed',false).'>fixed</option>';
                echo '<option value="percent" '.selected($type,'percent',false).'>percent</option>';
                echo '</select></td>';
                echo '<td><input type="number" step="0.01" name="'.esc_attr(self::OPTION_KEY).'[apf_choice_pricing]['.esc_attr($m).'][percent]" value="'.esc_attr($percent).'" /></td>';
                echo '<td><input type="number" step="0.01" name="'.esc_attr(self::OPTION_KEY).'[apf_choice_pricing]['.esc_attr($m).'][extra]" value="'.esc_attr($extra).'" /></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '<p class="description">اگر <strong>fixed</strong> باشد: مبلغ گزینه = <code>دلتا + Extra</code>. اگر <strong>percent</strong> باشد: مقدار درصد به WAPF داده می‌شود تا روی قیمت اعمال شود.</p>';
        }, self::OPTION_KEY, 'ds_section_apf');
    }

    public static function render_page() {
        echo '<div class="wrap"><h1>Dreamli – Pricing & Fields</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields(self::OPTION_KEY);
        do_settings_sections(self::OPTION_KEY);
        submit_button();
        echo '</form></div>';
    }
}
