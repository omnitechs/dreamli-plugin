<?php
// inc/class-material-options.php
if (!defined('ABSPATH')) exit;

final class DS_Material_Options {

    public static function init() {
        add_action('rest_api_init', function(){
            register_rest_route('dreamli/v1','/calc',[
                'methods'=>'POST',
                'permission_callback'=>[__CLASS__,'can_edit'],
                'callback'=>[__CLASS__,'rest_calc']
            ]);
            register_rest_route('dreamli/v1','/options/build',[
                'methods'=>'POST',
                'permission_callback'=>[__CLASS__,'can_edit'],
                'callback'=>[__CLASS__,'rest_build']
            ]);
        });
    }

    // This permission check is now shared
    public static function can_edit($req){
        $pid = intval($req->get_param('product_id'));
        if ($pid && !current_user_can('edit_post', $pid)) return false;
        if (!current_user_can('edit_products')) return false;
        return true;
    }

    /** ---------- CALC ---------- */
    public static function rest_calc($req){
        $pid    = intval($req['product_id']);
        $w      = floatval($req['weight_g'] ?? 0);
        $th     = floatval($req['time_h'] ?? 0);
        $base   = sanitize_text_field($req['base_material'] ?? 'Basic');
        $parts  = is_array($req['parts'] ?? null) ? $req['parts'] : [];

        if ($w <= 0 || $th <= 0) return new WP_Error('bad_params','weight/time required',['status'=>400]);

        $all = DS_Pricing::compute_all($w, $th);
        if (empty($all[$base])) $base = 'Basic';
        list($base_full,   $d_full)   = DS_Pricing::deltas_vs_base($all,'full',$base);
        list($base_market, $d_market) = DS_Pricing::deltas_vs_base($all,'market',$base);

        $parts = self::normalize_parts($parts);
        list($meta_key) = self::locate_options_meta($pid, DS_Settings::get()['po_meta_key'] ?? '');

        return new WP_REST_Response([
            'ok'=>true,
            'base_material'=>$base,
            'base_full'=>$base_full,
            'base_market'=>$base_market,
            'deltas_full'=>$d_full,
            'deltas_market'=>$d_market,
            'parts'=>$parts,
            'meta_key'=>$meta_key,
            'note'=> empty($meta_key) ? 'هنوز فیلدهای سفارشی پیدا نشد؛ با «اعمال» ساخته می‌شود.' : ''
        ], 200);
    }

    /** ---------- BUILD/APPLY ---------- */
    public static function rest_build($req){
        $pid    = intval($req['product_id']);
        $mode   = ($req['mode'] ?? 'full') === 'market' ? 'market' : 'full';
        $w      = floatval($req['weight_g'] ?? 0);
        $th     = floatval($req['time_h'] ?? 0);
        $base   = sanitize_text_field($req['base_material'] ?? 'Basic');
        $parts  = is_array($req['parts'] ?? null) ? $req['parts'] : [];
        $ensure = !empty($req['ensure_create']);

        if ($w <= 0 || $th <= 0) return new WP_Error('bad_params','weight/time required',['status'=>400]);

        // محاسبه قیمت‌ها برای هر متریال
        $all = DS_Pricing::compute_all($w, $th);
        if (empty($all[$base])) $base = 'Basic';

        list($base_full,   $d_full)   = DS_Pricing::deltas_vs_base($all,'full',$base);
        list($base_market, $d_market) = DS_Pricing::deltas_vs_base($all,'market',$base);

        // دلتاها برای inherit
        $inherit_deltas = ($mode === 'market') ? $d_market : $d_full;
        // قیمت پایه انتخاب‌شده (برای percent)
        $base_price     = ($mode === 'market') ? $base_market : $base_full;

        // نرمال‌سازی سهم‌ها
        $parts = self::normalize_parts($parts);

        // نوشتن روی گزینه‌ها با توجه به Rules
        $written = self::write_to_product_options(
            $pid,
            $inherit_deltas,
            $base_price,
            $parts,
            $ensure
        );

        // ذخیره قیمت محصول: همیشه Regular = FULL ؛ اگر MARKET → Sale = MARKET
        if (function_exists('wc_get_product')) {
            $product = wc_get_product($pid);
            if ($product) {
                $product->set_regular_price( wc_format_decimal($base_full, 2) );
                if ($mode === 'market') $product->set_sale_price( wc_format_decimal($base_market, 2) );
                else $product->set_sale_price('');
                $product->save();
            }
        }

        return new WP_REST_Response([
            'ok'=>true,
            'mode'=>$mode,
            'base_full'=>$base_full,
            'base_market'=>$base_market,
            'base_price'=>($mode==='market')?$base_market:$base_full,
            'written'=>$written
        ], 200);
    }

    /** ---------- Helpers ---------- */
    private static function normalize_parts(array $parts){
        $filtered = [];
        foreach ($parts as $p){
            $label = trim((string)($p['label'] ?? 'Part'));
            $share = max(0,min(100, floatval($p['share_pct'] ?? 0)));
            $id    = trim((string)($p['field_id'] ?? ''));
            if ($share <= 0) continue;
            $filtered[] = ['label'=>$label ?: 'Part', 'share_pct'=>$share, 'field_id'=>$id];
        }
        if (empty($filtered)) $filtered = [['label'=>'Body','share_pct'=>100,'field_id'=>'']];

        $sum = 0.0;
        foreach ($filtered as $p) $sum += max(0.0,(float)$p['share_pct']);
        if ($sum <= 0) $sum = 1.0;
        foreach ($filtered as &$p) $p['share_pct'] = round(((float)$p['share_pct'] / $sum) * 100.0, 6);
        unset($p);
        return $filtered;
    }

    private static function locate_options_meta($pid, $prefer_key=''){
        if ($prefer_key) {
            $val = get_post_meta($pid, $prefer_key, true);
            if (is_array($val) && isset($val['fields'])) return [$prefer_key, $val];
            if (is_string($val) && $val !== '') {
                $arr = json_decode($val, true);
                if (is_array($arr) && isset($arr['fields'])) return [$prefer_key, $arr];
                $arr = maybe_unserialize($val);
                if (is_array($arr) && isset($arr['fields'])) return [$prefer_key, $arr];
            }
        }
        $candidates = [
            '_wapf_fieldgroup','wapf_fieldgroup',
            '_wapf_field_groups','wapf_field_groups',
            '_wapf_product','wapf_product',
            '_product_addons','product_addons',
            '_wapf_field','wapf_field'
        ];
        foreach ($candidates as $k){
            $v = get_post_meta($pid, $k, true);
            if (is_array($v) && isset($v['fields'])) return [$k, $v];
            if (is_string($v) && $v !== '') {
                $arr = json_decode($v, true);
                if (is_array($arr) && isset($arr['fields'])) return [$k, $arr];
                $arr = maybe_unserialize($v);
                if (is_array($arr) && isset($arr['fields'])) return [$k, $arr];
            }
        }
        return [null, null];
    }

    /**
     * This function now ONLY calculates prices for 'inherit' types.
     * 'percent' and 'fixed' (as 'qt') are already set by the JSON builder.
     */
    private static function write_to_product_options($pid, array $inherit_deltas, float $base_price, array $parts, bool $ensure_create){
        $cfg        = DS_Pricing::get_cfg();
        $materials  = array_keys($cfg['pe_materials']);
        $rules      = isset($cfg['po_choice_rules']) && is_array($cfg['po_choice_rules']) ? $cfg['po_choice_rules'] : [];

        list($meta_key, $arr) = self::locate_options_meta($pid, DS_Settings::get()['po_meta_key'] ?? '');

        if (!$arr) return ['found'=>false,'written'=>false,'reason'=>'meta_key_not_found_after_import'];

        $changed = 0;
        
        // Loop through each PART defined in the UI (e.g., "Body", "Part")
        foreach ($parts as $part) {
            $part_label = $part['label'];
            $part_share_pct = (float)$part['share_pct'];
            $part_field_id = $part['field_id'];
            
            // Find the "Type" field for this specific part (e.g., field with label "Body")
            $type_field =& self::find_or_create_text_swatch_field($arr, $part_field_id, $part_label, $materials);
            
            if ($type_field && isset($type_field['choices']) && is_array($type_field['choices'])) {
                
                // Loop through each material choice (Basic, Glow, Silk...)
                foreach ($type_field['choices'] as &$ch){
                    $mat_label = $ch['label'] ?? '';
                    $mat_name  = self::material_canon($mat_label, $materials);
                    if ($mat_name === null) continue;

                    // Get the rule for this material (e.g., "Glow: fixed 10")
                    $r = isset($rules[$mat_name]) && is_array($rules[$mat_name]) ? $rules[$mat_name] : ['type'=>'inherit','value'=>0,'extra'=>0];
                    $type  = in_array($r['type'] ?? 'inherit', ['inherit','fixed','percent'], true) ? $r['type'] : 'inherit';

                    // --- THIS IS THE KEY ---
                    // The JSON (class-editor-ui.php) has already set 'fixed' (as 'qt') and 'percent' prices.
                    // We ONLY need to run this PHP logic for 'inherit' types.
                    
                    if ($type === 'fixed' || $type === 'percent') {
                        // The JSON has already set the correct type and price.
                        // We do nothing and trust the JSON.
                        continue;
                    }
                    
                    // --- If we are here, the type is 'inherit' (or 'none', which defaults to 'inherit') ---
                    $val   = (float)($r['value'] ?? 0); // Not used for 'inherit'
                    $extra = (float)($r['extra'] ?? 0);
                    $share_multiplier = $part_share_pct / 100.0; // e.g., 0.5
                    
                    $amount_before_split = 0.0;
                    
                    // Only 'inherit' logic remains
                    $inherit = isset($inherit_deltas[$mat_name]) ? (float)$inherit_deltas[$mat_name] : 0.0;
                    $amount_before_split = $inherit;
                    
                    // Calculate the final split price
                    $final_amount = ($amount_before_split * $share_multiplier) + ($extra * $share_multiplier);
                    $final_amount = round($final_amount, 2);

                    // Write the final calculated price to the choice
                    // 'inherit' rules always become a 'fixed' price offset
                    $ch['pricing_type']   = 'fixed';
                    $ch['pricing_amount'] = $final_amount;
                    if (isset($ch['pricing']) && is_array($ch['pricing'])) {
                        $ch['pricing']['amount']  = $final_amount;
                        $ch['pricing']['type']    = 'fixed';
                    } else {
                        $ch['pricing'] = ['enabled'=>true,'amount'=>$final_amount,'type'=>'fixed'];
                    }

                    if (!isset($ch['options']) || !is_array($ch['options'])) $ch['options'] = [];
                    if (!isset($ch['selected'])) $ch['selected'] = false;

                    $changed++;
                }
                unset($ch);
            }
        }
        // --- END OF LOGIC REWRITE ---

        update_post_meta($pid, $meta_key, $arr);

        return ['found'=>true,'created'=>false,'written'=>true,'changed'=>$changed,'meta_key'=>$meta_key];
    }

    private static function &find_or_create_text_swatch_field(&$arr, $wanted_id, $part_label, $materials){
        if (!isset($arr['fields']) || !is_array($arr['fields'])) $arr['fields'] = [];

        foreach ($arr['fields'] as &$f){
            if (!isset($f['type']) || $f['type'] !== 'text-swatch') continue;

            // Find by the exact ID the user entered
            $id_match = $wanted_id && isset($f['id']) && $f['id'] === $wanted_id;
            
            // Find by the exact label (e.g., "Body" or "Part")
            // This is the primary way to find the field.
            $label_match = isset($f['label']) && mb_strtolower(trim($f['label'])) === mb_strtolower(trim($part_label));
            
            // Find the default 'e040e2a' ID
            $default_id_match = isset($f['id']) && $f['id'] === 'e040e2a';

            if ($id_match || $label_match || ($part_label === 'Body' && $default_id_match)) {
                if (!isset($f['choices']) || !is_array($f['choices']) || empty($f['choices'])) {
                    $f['choices'] = self::choices_from_materials($materials);
                }
                return $f;
            }
        }
        
        // Fallback: If the JSON import somehow failed and the field doesn't exist
        $choices = self::choices_from_materials($materials);
        $new = [
            'id'          => $wanted_id ?: 'ds_type_' . substr(md5($part_label), 0, 8),
            'label'       => $part_label,
            'description' => '<a href="/color-guide/">Need help choosing a color? Click here.</a>',
            'type'        => 'text-swatch',
            'required'    => true,
            'class'       => null,
            'width'       => 100,
            'group'       => 'field',
            'meta'        => '',
            'parent_clone'=> [],
            'clone'       => ['enabled'=>false],
            'pricing'     => ['enabled'=>true,'amount'=>0,'type'=>'fixed'],
            'choices'     => $choices,
            'options'     => ['choices'=>$choices,'group'=>'field','meta'=>''],
            'conditionals'=> []
        ];

        $arr['fields'][] = $new;
        $last_idx = array_key_last($arr['fields']);
        return $arr['fields'][$last_idx];
    }

    private static function choices_from_materials($materials){
        $out = [];
        foreach ($materials as $m){
            $out[] = [
                'selected'=> false,
                'disabled'=> false,
                'slug'    => strtolower(preg_replace('~\s+~','-', $m)).'-'.substr(md5($m),0,5),
                'label'   => $m,
                'pricing_type'  => 'none', // Default to 'none'
                'pricing_amount'=> 0,
                'options'=>[]
            ];
        }
        return $out;
    }

    private static function material_canon($label, $materials){
        $k = strtolower(trim((string)$label));
        foreach ($materials as $m) if ($k === strtolower($m)) return $m;
        $syn = ['sparkle'=>'Galaxy','galaxy'=>'Galaxy','basic'=>'Basic'];
        if (isset($syn[$k])) return $syn[$k];
        return null;
    }
}