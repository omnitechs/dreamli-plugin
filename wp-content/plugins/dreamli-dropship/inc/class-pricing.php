<?php
if (!defined('ABSPATH')) exit;

final class DS_Pricing {

    public static function init() { /* noop */ }

    // همون رفتار قبلی: تنظیمات از DS_Settings
    public static function get_cfg() { return DS_Settings::get(); }

    /**
     * مدل قیمت‌گذاری با سود ترکیبی (درصد + فی ثابت)
     * – کاملاً سازگار با ساختار تنظیمات قبلی:
     *   pe_waste_rate, pe_printer_power_w, pe_electricity_kwh,
     *   pe_labor_fixed_min, pe_labor_rate, pe_dep_full/market, pe_oh_full/market,
     *   pe_packaging, pe_misc, pe_fail_risk_rate, pe_profit_hint_full/market (اگر خواستی هنوز استفاده‌شان کنی)
     * – کلیدهای جدید اختیاری (اگر در DS_Settings نبودن، دیفالت می‌گیریم):
     *   pe_margin_full_pct, pe_margin_full_fee, pe_margin_market_pct, pe_margin_market_fee
     */
    private static function calc_model(array $cfg, float $weight_g, float $time_h, float $price_per_kg, float $dep_per_h, float $oh_per_h, string $mode): array {
        $weight_kg  = $weight_g / 1000.0;

        $waste_rate = isset($cfg['pe_waste_rate']) ? (float)$cfg['pe_waste_rate'] : 0.05;
        $power_w    = isset($cfg['pe_printer_power_w']) ? (float)$cfg['pe_printer_power_w'] : 120.0;
        $kwh_eur    = isset($cfg['pe_electricity_kwh']) ? (float)$cfg['pe_electricity_kwh'] : 0.40;

        $labor_min  = isset($cfg['pe_labor_fixed_min']) ? (float)$cfg['pe_labor_fixed_min'] : 8.0;
        $labor_rate = isset($cfg['pe_labor_rate']) ? (float)$cfg['pe_labor_rate'] : 15.0;

        $packaging  = isset($cfg['pe_packaging']) ? (float)$cfg['pe_packaging'] : 0.60;
        $misc       = isset($cfg['pe_misc']) ? (float)$cfg['pe_misc'] : 0.80;

        $fail_rate  = isset($cfg['pe_fail_risk_rate']) ? (float)$cfg['pe_fail_risk_rate'] : 0.05;

        // سود ترکیبی (درصد + فی ثابت) — اگر در تنظیمات نبود، دیفالت می‌گیریم
        $m_full_pct   = isset($cfg['pe_margin_full_pct'])   ? (float)$cfg['pe_margin_full_pct']   : 0.35; // 35%
        $m_full_fee   = isset($cfg['pe_margin_full_fee'])   ? (float)$cfg['pe_margin_full_fee']   : 8.00;
        $m_market_pct = isset($cfg['pe_margin_market_pct']) ? (float)$cfg['pe_margin_market_pct'] : 0.25; // 25%
        $m_market_fee = isset($cfg['pe_margin_market_fee']) ? (float)$cfg['pe_margin_market_fee'] : 6.00;

        // هزینه‌ها
        $material   = $weight_kg * $price_per_kg * (1.0 + $waste_rate);
        $energy     = ($power_w / 1000.0) * $time_h * $kwh_eur;
        $labor      = ($labor_min / 60.0) * $labor_rate;
        $dep        = $dep_per_h * $time_h;
        $oh         = $oh_per_h  * $time_h;
        $fixeds     = $packaging + $misc;

        $subtotal   = $material + $energy + $labor + $dep + $oh + $fixeds;
        $after_risk = $subtotal * (1.0 + $fail_rate);

        // اعمال سود ترکیبی
        if ($mode === 'full') {
            $final = $after_risk * (1.0 + $m_full_pct) + $m_full_fee;
            $profit = $final - $after_risk;
        } else {
            $final = $after_risk * (1.0 + $m_market_pct) + $m_market_fee;
            $profit = $final - $after_risk;
        }

        return [
            'subtotal'    => round($subtotal, 2),
            'after_risk'  => round($after_risk, 2),
            'profit'      => round($profit, 2),
            'final_price' => round($final, 2)
        ];
    }

    /**
     * خروجی قدیمی و سازگار:
     * [
     *   'Basic' => ['full'=>[...],'market'=>[...]],
     *   'Galaxy'=> ['full'=>[...],'market'=>[...]], ...
     * ]
     * همچنان از pe_materials در DS_Settings استفاده می‌کنیم:
     *   pe_materials => [
     *     'Basic' => [
     *       'price_per_kg_full'   => ...,
     *       'price_per_kg_market' => ...,
     *       'discountable'        => true/false,
     *       'premium_uplift_pct'  => 0.10  // اختیاری
     *     ],
     *     ...
     *   ]
     */
    public static function compute_all(float $weight_g, float $time_h): array {
        $cfg  = self::get_cfg();
        $mats = $cfg['pe_materials'];
        $out  = [];

        foreach ($mats as $name => $m) {
            $price_full_kg   = isset($m['price_per_kg_full'])   ? (float)$m['price_per_kg_full']   : 24.0;
            $price_market_kg = isset($m['price_per_kg_market']) ? (float)$m['price_per_kg_market'] : $price_full_kg;

            $dep_full   = isset($cfg['pe_dep_full'])   ? (float)$cfg['pe_dep_full']   : 0.60;
            $dep_market = isset($cfg['pe_dep_market']) ? (float)$cfg['pe_dep_market'] : 0.20;

            $oh_full    = isset($cfg['pe_oh_full'])    ? (float)$cfg['pe_oh_full']    : 0.80;
            $oh_market  = isset($cfg['pe_oh_market'])  ? (float)$cfg['pe_oh_market']  : 0.20;

            // محاسبه با منطق جدید سود
            $full   = self::calc_model($cfg, $weight_g, $time_h, $price_full_kg,   $dep_full,   $oh_full,   'full');
            $market = self::calc_model($cfg, $weight_g, $time_h, $price_market_kg, $dep_market, $oh_market, 'market');

            // اگر discountable=false → market همان full باشد
            $is_discountable = isset($m['discountable']) ? (bool)$m['discountable'] : true;
            if (!$is_discountable) {
                $market = $full;
            }

            // پریمیوم آپلیفت (اختیاری) – روی profit و final_price اعمال می‌کنیم
            $uplift = isset($m['premium_uplift_pct']) ? (float)$m['premium_uplift_pct'] : 0.0;
            if ($uplift > 0) {
                foreach (['full','market'] as $k) {
                    $x = ($k === 'full') ? $full : $market;
                    $x['final_price'] = round($x['final_price'] * (1.0 + $uplift), 2);
                    $x['profit']      = round($x['profit']      * (1.0 + $uplift), 2);
                    if ($k === 'full') $full = $x; else $market = $x;
                }
            }

            $out[$name] = ['full' => $full, 'market' => $market];
        }

        return $out;
    }

    /**
     * مثل قبل:‌ برمی‌گرداند [base_price, deltas]
     * deltas: اختلاف هر متریال با base در همان mode
     */
    public static function deltas_vs_base(array $all, string $mode, string $base): array {
        $mode = ($mode === 'market') ? 'market' : 'full';
        if (!isset($all[$base])) { $keys = array_keys($all); $base = $keys ? $keys[0] : null; }
        if (!$base) return [0.0, []];

        $base_price = (float)$all[$base][$mode]['final_price'];
        $deltas = [];
        foreach ($all as $mat => $v) {
            $deltas[$mat] = round(((float)$v[$mode]['final_price']) - $base_price, 2);
        }
        return [$base_price, $deltas];
    }
}
