<?php
if (!defined('ABSPATH')) exit;

final class DS_Calculator {
    static function init() {
        add_shortcode('ds_price_calculator', [__CLASS__, 'shortcode']);
        add_action('rest_api_init', function () {
            register_rest_route('ds/v1','/calc',[
                'methods'=>'POST',
                'permission_callback'=>function(){ return is_user_logged_in(); },
                'callback'=>[__CLASS__, 'rest_calc'],
            ]);
        });
    }

    static function calc_price($weight_g, $time_min, $user_id=0) {
        $s = DS_Settings::get();
        $base   = (float)$s['calc_base'];
        $rate_g = (float)$s['calc_rate_per_gram'];
        $rate_m = (float)$s['calc_rate_per_min'];
        $markup = (float)$s['calc_markup_percent'];

        $linear = ($base + $weight_g*$rate_g + $time_min*$rate_m) * (1 + $markup/100);
        $price  = $linear;

        if ($s['calc_mode']==='custom_expr' && $s['calc_custom_expr_enable']) {
            $expr = (string)$s['calc_custom_expr'];
            $map = [
                'weight_g'=>(float)$weight_g,'time_min'=>(float)$time_min,'base'=>$base,
                'rate_g'=>$rate_g,'rate_min'=>$rate_m,'markup'=>$markup,
            ];
            foreach ($map as $k=>$v) $expr = preg_replace('/\b'.preg_quote($k,'/').'\b/', (string)$v, $expr);
            $expr = preg_replace('/[^0-9\.\+\-\*\/\(\)\s]/', '', $expr);
            // بدون eval؛ اگر فقط کاراکترهای مجاز بود، تلاش برای محاسبه‌ی ساده نشون می‌دیم → فعلاً fallback به linear.
            // برای فرمول‌های پیچیده‌تر می‌تونی از فیلتر زیر استفاده کنی.
        }

        $price = $s['calc_round_cents'] ? round($price, 2) : $price;
        return apply_filters('ds_price_calculator', $price, $weight_g, $time_min, $user_id);
    }

    static function shortcode() {
        if (!is_user_logged_in()) return '<p>برای استفاده وارد شوید.</p>';
        ob_start(); ?>
        <form id="ds-calc" onsubmit="return false;" style="max-width:420px">
          <label>وزن (گرم)<br><input type="number" id="w" min="1" required></label><br>
          <label>زمان پرینت (دقیقه)<br><input type="number" id="t" min="1" required></label><br>
          <button class="button button-primary" onclick="dsCalc()">محاسبه</button>
          <p id="ds-calc-res"></p>
        </form>
        <script>
        async function dsCalc(){
          const w = Number(document.getElementById('w').value||0);
          const t = Number(document.getElementById('t').value||0);
          const res = await fetch('<?php echo esc_js(rest_url('ds/v1/calc')); ?>', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ weight_g:w, time_min:t })
          });
          const j = await res.json();
          document.getElementById('ds-calc-res').innerText = j?.price_eur ? ('قیمت: €'+j.price_eur) : 'خطا';
        }
        </script>
        <?php return ob_get_clean();
    }

    static function rest_calc($req) {
        $w = (float) ($req['weight_g'] ?? 0);
        $t = (float) ($req['time_min'] ?? 0);
        $price = self::calc_price($w, $t, get_current_user_id());
        return ['price_eur' => $price];
    }
}
