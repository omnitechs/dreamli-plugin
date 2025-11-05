<?php
// inc/class-editor-ui.php
if (!defined('ABSPATH')) exit;

final class DS_Editor_UI {

    public static function init() {
        add_action('add_meta_boxes_product', [__CLASS__, 'add_box']);
        add_action('save_post_product', [__CLASS__, 'save_meta'], 10, 3);
        add_filter('upload_mimes', [__CLASS__, 'allow_avif']);
        
        // Add the NEW REST endpoint to dynamically fetch the JSON
        add_action('rest_api_init', function(){
            register_rest_route('dreamli/v1','/get-json',[
                'methods'=>'POST',
                'permission_callback'=> [__CLASS__, 'can_edit_json'],
                'callback'=> [__CLASS__,'rest_get_json']
            ]);
        });
    }
    
    // Permission check for the new endpoint
    public static function can_edit_json($req) {
        return current_user_can('edit_products');
    }
    
    // Callback for the new endpoint
    public static function rest_get_json($req) {
        $parts = $req->get_param('parts');
        if (!is_array($parts) || empty($parts)) {
            $parts = [['label'=>'Body','share_pct'=>100,'field_id'=>'e040e2a']];
        }
        // Call the (now public) function to build and return the fresh JSON
        $json_string = self::apf_json_full($parts);
        return new WP_REST_Response(['ok' => true, 'json' => $json_string], 200);
    }

    public static function allow_avif($mimes){
        $mimes['avif'] = 'image/avif';
        return $mimes;
    }

    public static function add_box() {
        if (!current_user_can('edit_products')) return;
        add_meta_box('ds_price_calc','قیمت‌گذار / متریال (Dreamli)', [__CLASS__,'render_box'], 'product','side','high');
    }

    /** ===== Helper: ساخت choice با URL عکس (سازگار با Import) ===== */
    private static function make_choice($slug, $label, $url){
        if (!$url) $url = '';
        return [
            'selected'       => false,
            'disabled'       => false,
            'slug'           => $slug,
            'label'          => $label,
            'pricing_type'   => 'none',
            'pricing_amount' => 0,
            'options'        => new stdClass(),
            'image'          => $url,
            'image_url'      => $url,
        ];
    }

    /** ===== Helper: map لیست‌های [slug,label,url] به choices ===== */
    private static function map_choices(array $rows) {
        $out = [];
        foreach ($rows as $r) {
            $url = preg_replace('~/Translucent_Ice_BlUE-~','/Translucent_Ice_Blue-',$r[2]);
            $url = preg_replace('~/Silk_Baby_BlUE~','/Silk_Baby_Blue',$url);
            $out[] = self::make_choice($r[0], $r[1], $url);
        }
        return $out;
    }

    /** * ===== تولید JSON کامل WAPF با همه رنگ‌ها =====
     * This function is now PUBLIC and DYNAMIC.
     * It builds the JSON based on the $parts array passed to it.
     */
    public static function apf_json_full(array $parts) {
        
        $cfg   = DS_Pricing::get_cfg();
        $rules = $cfg['po_choice_rules'] ?? [];

        $get_base_rule = function($label) use ($rules) {
            $default_rule = ['type' => 'none', 'value' => 0, 'extra' => 0];
            foreach ($rules as $mat_name => $rule) {
                if (strcasecmp($mat_name, $label) === 0) return $rule;
            }
            if ($label === 'galaxy' && isset($rules['Galaxy'])) return $rules['Galaxy'];
            if ($label === 'Sparkle' && !isset($rules['Sparkle']) && isset($rules['Galaxy'])) return $rules['Galaxy'];
            return $default_rule;
        };

        $create_split_pricing = function($material_label, $part_share_pct) use ($get_base_rule) {
            $rule = $get_base_rule($material_label);
            $type = $rule['type'] ?? 'none';
            $base_value = (float)($rule['value'] ?? 0);
            $extra = (float)($rule['extra'] ?? 0);
            $share_multiplier = $part_share_pct / 100.0;
            
            $final_pricing_type = 'fixed'; // پیش‌فرض برای 'inherit' یا 'none'
            $final_pricing_amount = 0.0;
            
            if ($type === 'fixed') {
                // این قانون فیکس است. ما آن را به 'qt' تبدیل می‌کنیم طبق درخواست شما
                // و قیمت تقسیم‌شده را محاسبه می‌کنیم
                $split_price = ($base_value * $share_multiplier) + ($extra * $share_multiplier);
                
                $final_pricing_type = 'qt'; // "quantity-based flat"
                $final_pricing_amount = round($split_price, 2);

            } else if ($type === 'percent') {
                // قانون درصد: باید تحت تاثیر سهم وزن هر بخش نیز باشد.
                // بنابراین مقدار درصد نهایی = درصد پایه * سهم بخش (share_multiplier)
                // مثال: 10% با سهم 60% => 6%
                $final_pricing_type = 'percent';
                $final_pricing_amount = round($base_value * $share_multiplier, 4);
            
            }
            // برای 'inherit' یا 'none':
            // نوع 'fixed' و مبلغ 0.0 باقی می‌ماند.
            // class-material-options.php بعداً آن را محاسبه خواهد کرد.
            
            return [
                'pricing_type'   => $final_pricing_type,
                'pricing_amount' => $final_pricing_amount,
                'options'        => new stdClass()
            ];
        };

        // --- Color/Texture Lists (Unchanged) ---
        $basic = [
            ["arctic-whisper","Arctic Whisper","https://shop.dreamli.nl/wp-content/uploads/2025/09/Arctic_Whisper-150x150.avif"],
            ["bambu-green","Bambu Green","https://shop.dreamli.nl/wp-content/uploads/2025/09/Bambu_Green-2.avif"],
            ["beige","Beige","https://shop.dreamli.nl/wp-content/uploads/2025/09/Beige.avif"],
            ["black","Black","https://shop.dreamli.nl/wp-content/uploads/2025/09/Black.avif"],
            ["blue","Blue","https://shop.dreamli.nl/wp-content/uploads/2025/09/Blue.avif"],
            ["blue-grey","Blue Grey","https://shop.dreamli.nl/wp-content/uploads/2025/09/Blue_Grey.avif"],
            ["blueberry-bubblegum","Blueberry Bubblegum","https://shop.dreamli.nl/wp-content/uploads/2025/09/Blueberry_Bubblegum-150x150.avif"],
            ["bright-green","Bright Green","https://shop.dreamli.nl/wp-content/uploads/2025/09/Bright_Green-150x150.avif"],
            ["bronze","Bronze","https://shop.dreamli.nl/wp-content/uploads/2025/09/Bronze.avif"],
            ["brown","Brown","https://shop.dreamli.nl/wp-content/uploads/2025/09/Brown.avif"],
            ["cobalt-blue","Cobalt Blue","https://shop.dreamli.nl/wp-content/uploads/2025/09/Cobalt_Blue-150x150.avif"],
            ["cocoa-brown","Cocoa Brown","https://shop.dreamli.nl/wp-content/uploads/2025/09/Cocoa_Brown-150x150.avif"],
            ["cotton-candy-cloud","Cotton Candy Cloud","https://shop.dreamli.nl/wp-content/uploads/2025/09/Cotton_Candy_Cloud-150x150.avif"],
            ["cyan","Cyan","https://shop.dreamli.nl/wp-content/uploads/2025/09/Cyan.avif"],
            ["dark-gray","Dark Gray","https://shop.dreamli.nl/wp-content/uploads/2025/09/Dark_Gray-150x150.avif"],
            ["dusk-glare","Dusk Glare","https://shop.dreamli.nl/wp-content/uploads/2025/09/Dusk_Glare-150x150.avif"],
            ["gold","Gold","https://shop.dreamli.nl/wp-content/uploads/2025/09/Gold.avif"],
            ["gray","Gray","https://shop.dreamli.nl/wp-content/uploads/2025/09/Gray.avif"],
            ["hot-pink","Hot Pink","https://shop.dreamli.nl/wp-content/uploads/2025/09/Hot_Pink-150x150.avif"],
            ["indigo-purple","Indigo Purple","https://shop.dreamli.nl/wp-content/uploads/2025/09/Indigo_Purple-150x150.avif"],
            ["jade-white","Jade White","https://shop.dreamli.nl/wp-content/uploads/2025/09/Jade_White-150x150.avif"],
            ["light-gray","Light Gray","https://shop.dreamli.nl/wp-content/uploads/2025/09/Light_Gray-150x150.avif"],
            ["magenta","Magenta","https://shop.dreamli.nl/wp-content/uploads/2025/09/Magenta.avif"],
            ["maroon-red","Maroon Red","https://shop.dreamli.nl/wp-content/uploads/2025/09/Maroon_Red-150x150.avif"],
            ["mint-lime","Mint Lime","https://shop.dreamli.nl/wp-content/uploads/2025/09/Mint_Lime-150x150.avif"],
            ["mistletoe-green","Mistletoe Green","https://shop.dreamli.nl/wp-content/uploads/2025/09/Mistletoe_Green.avif"],
            ["ocean-to-meadow","Ocean to Meadow","https://shop.dreamli.nl/wp-content/uploads/2025/09/Ocean_to_Meadow-150x150.avif"],
            ["orange","Orange","https://shop.dreamli.nl/wp-content/uploads/2025/09/Orange.avif"],
            ["pink","Pink","https://shop.dreamli.nl/wp-content/uploads/2025/09/Pink.avif"],
            ["pink-citrus","Pink Citrus","https://shop.dreamli.nl/wp-content/uploads/2025/09/Pink_Citrus-150x150.avif"],
            ["pumpkin-orange","Pumpkin Orange","https://shop.dreamli.nl/wp-content/uploads/2025/09/Pumpkin_Orange-150x150.avif"],
            ["purple","Purple","https://shop.dreamli.nl/wp-content/uploads/2025/09/Purple.avif"],
            ["red","Red","https://shop.dreamli.nl/wp-content/uploads/2025/09/Red.avif"],
            ["silver","Silver","https://shop.dreamli.nl/wp-content/uploads/2025/09/Silver.avif"],
            ["solar-breeze","Solar Breeze","https://shop.dreamli.nl/wp-content/uploads/2025/09/Solar_Breeze-150x150.avif"],
            ["sunflower-yellow","Sunflower Yellow","https://shop.dreamli.nl/wp-content/uploads/2025/09/Sunflower_Yellow-150x150.avif"],
            ["turquoise","Turquoise","https://shop.dreamli.nl/wp-content/uploads/2025/09/Turquoise-150x150.avif"],
            ["yellow","Yellow","https://shop.dreamli.nl/wp-content/uploads/2025/09/Yellow.avif"],
        ];
        $sparkle = [
            ["alpine-green-sparkle","Alpine Green Sparkle","https://shop.dreamli.nl/wp-content/uploads/2025/09/Alpine_Green_Sparkle.avif"],
            ["classic-gold-sparkle","Classic Gold Sparkle","https://shop.dreamli.nl/wp-content/uploads/2025/09/Classic_Gold_Sparkle.avif"],
            ["crimson-red-sparkle","Crimson Red Sparkle","https://shop.dreamli.nl/wp-content/uploads/2025/09/Crimson_Red_Sparkle.avif"],
            ["onyx-black-sparkle","Onyx Black Sparkle","https://shop.dreamli.nl/wp-content/uploads/2025/09/Onyx_Black_Sparkle.avif"],
            ["royal-purple-sparkle","Royal Purple Sparkle","https://shop.dreamli.nl/wp-content/uploads/2025/09/Royal_Purple_Sparkle.avif"],
            ["slate-gray-sparkle","Slate Gray Sparkle","https://shop.dreamli.nl/wp-content/uploads/2025/09/Slate_Gray_Sparkle.avif"],
        ];
        $galaxy = [
            ["brown-galaxy","Brown Galaxy","https://shop.dreamli.nl/wp-content/uploads/2025/09/Brown_Galaxy.avif"],
            ["green-galaxy","Green Galaxy","https://shop.dreamli.nl/wp-content/uploads/2025/09/Green_Galaxy.avif"],
            ["nebulae-galaxy","Nebulae Galaxy","https://shop.dreamli.nl/wp-content/uploads/2025/09/Nebulae_Galaxy.avif"],
            ["purple-galaxy","Purple Galaxy","https://shop.dreamli.nl/wp-content/uploads/2025/09/Purple_Galaxy.avif"],
        ];
        $glow = [
            ["glow-blue","Glow Blue","https://shop.dreamli.nl/wp-content/uploads/2025/09/Glow_Blue.avif"],
            ["glow-green","Glow Green","https://shop.dreamli.nl/wp-content/uploads/2025/09/Glow_Green.avif"],
            ["glow-orange","Glow Orange","https://shop.dreamli.nl/wp-content/uploads/2025/09/Glow_Orange.avif"],
            ["glow-pink","Glow Pink","https://shop.dreamli.nl/wp-content/uploads/2025/09/Glow_Pink.avif"],
            ["glow-yellow","Glow Yellow","https://shop.dreamli.nl/wp-content/uploads/2025/09/Glow_Yellow.avif"],
            ["dusk-glare","Dusk Glare","https://shop.dreamli.nl/wp-content/uploads/2025/09/Dusk_Glare-150x150.avif"],
        ];
        $marble = [
            ["red-granite-marble","Red Granite Marble","https://shop.dreamli.nl/wp-content/uploads/2025/09/Red_Granite_Marble.avif"],
            ["white-marble","White Marble","https://shop.dreamli.nl/wp-content/uploads/2025/09/White_Marble.avif"],
        ];
        $matte = [
            ["matte-apple-green","Matte Apple Green","https://shop.dreamli.nl/wp-content/uploads/2025/09/Matte_Apple_Green-150x150.avif"],
            ["matte-ash-gray","Matte Ash Gray","https://shop.dreamli.nl/wp-content/uploads/2025/09/Matte_Ash_Gray.avif"],
            ["matte-bone-white","Matte Bone White","https://shop.dreamli.nl/wp-content/uploads/2025/09/Matte_Bone_White-150x150.avif"],
            ["matte-caramel","Matte Caramel","https://shop.dreamli.nl/wp-content/uploads/2025/09/Matte_Caramel-150x150.avif"],
            ["matte-charcoal","Matte Charcoal","https://shop.dreamli.nl/wp-content/uploads/2025/09/Matte_Charcoal.avif"],
            ["matte-dark-blue","Matte Dark Blue","https://shop.dreamli.nl/wp-content/uploads/2025/09/Matte_Dark_Blue.avif"],
            ["matte-dark-brown","Matte Dark Brown","https://shop.dreamli.nl/wp-content/uploads/2025/09/Matte_Dark_Brown.avif"],
            ["matte-dark-chocolate","Matte Dark Chocolate","https://shop.dreamli.nl/wp-content/uploads/2025/09/Matte_Dark_Chocolate-150x150.avif"],
            ["matte-dark-green","Matte Dark Green","https://shop.dreamli.nl/wp-content/uploads/2025/09/Matte_Dark_Green.avif"],
            ["matte-dark-red","Matte Dark Red","https://shop.dreamli.nl/wp-content/uploads/2025/09/Matte_Dark_Red.avif"],
            ["matte-desert-tan","Matte Desert Tan","https://shop.dreamli.nl/wp-content/uploads/2025/09/Matte_Desert_Tan.avif"],
            ["matte-grass-green","Matte Grass Green","https://shop.dreamli.nl/wp-content/uploads/2025/09/Matte_Grass_Green.avif"],
            ["matte-ice-blue","Matte Ice Blue","https://shop.dreamli.nl/wp-content/uploads/2025/09/Matte_Ice_Blue.avif"],
            ["matte-ivory-white","Matte Ivory White","https://shop.dreamli.nl/wp-content/uploads/2025/09/Matte_Ivory_White.avif"],
            ["matte-latte-brown","Matte Latte Brown","https://shop.dreamli.nl/wp-content/uploads/2025/09/Matte_Latte_Brown.avif"],
            ["matte-lemon-yellow","Matte Lemon Yellow","https://shop.dreamli.nl/wp-content/uploads/2025/09/Matte_Lemon_Yellow.avif"],
            ["matte-lilac-purple","Matte Lilac Purple","https://shop.dreamli.nl/wp-content/uploads/2025/09/Matte_Lilac_Purple.avif"],
            ["matte-mandarin-orange","Matte Mandarin Orange","https://shop.dreamli.nl/wp-content/uploads/2025/09/Matte_Mandarin_Orange.avif"],
            ["matte-marine-blue","Matte Marine Blue","https://shop.dreamli.nl/wp-content/uploads/2025/09/Matte_Marine_Blue.avif"],
            ["matte-nardo-gray","Matte Nardo Gray","https://shop.dreamli.nl/wp-content/uploads/2025/09/Matte_Nardo_Gray-150x150.avif"],
            ["matte-plum","Matte Plum","https://shop.dreamli.nl/wp-content/uploads/2025/09/Matte_Plum-150x150.avif"],
            ["matte-sakura-pink","Matte Sakura Pink","https://shop.dreamli.nl/wp-content/uploads/2025/09/Matte_Sakura_Pink.avif"],
            ["matte-scarlet-red","Matte Scarlet Red","https://shop.dreamli.nl/wp-content/uploads/2025/09/Matte_Scarlet_Red.avif"],
            ["matte-sky-blue","Matte Sky Blue","https://shop.dreamli.nl/wp-content/uploads/2025/09/Matte_Sky_Blue-150x150.avif"],
            ["matte-terracotta","Matte Terracotta","https://shop.dreamli.nl/wp-content/uploads/2025/09/Matte_Terracotta-150x150.avif"],
        ];
        $metallic = [
            ["cobalt-blue-metallic","Cobalt Blue Metallic","https://shop.dreamli.nl/wp-content/uploads/2025/09/Cobalt_Blue_Metallic.avif"],
            ["copper-brown-metallic","Copper Brown Metallic","https://shop.dreamli.nl/wp-content/uploads/2025/09/Copper_Brown_Metallic.avif"],
            ["iridium-gold-metallic","Iridium Gold Metallic","https://shop.dreamli.nl/wp-content/uploads/2025/09/Iridium_Gold_Metallic.avif"],
            ["iron-gray-metallic","Iron Gray Metallic","https://shop.dreamli.nl/wp-content/uploads/2025/09/Iron_Gray_Metallic.avif"],
            ["oxide-green-metallic","Oxide Green Metallic","https://shop.dreamli.nl/wp-content/uploads/2025/09/Oxide_Green_Metallic.avif"],
        ];
        $translucent = [
            ["translucent-blue","Translucent Blue","https://shop.dreamli.nl/wp-content/uploads/2025/09/Translucent_Blue-150x150.avif"],
            ["translucent-cherry-pink","Translucent Cherry Pink","https://shop.dreamli.nl/wp-content/uploads/2025/09/Translucent_Cherry_Pink-150x150.avif"],
            ["translucent-ice-blue","Translucent Ice Blue","https://shop.dreamli.nl/wp-content/uploads/2025/09/Translucent_Ice_Blue-150x150.avif"],
            ["translucent-lavender","Translucent Lavender","https://shop.dreamli.nl/wp-content/uploads/2025/09/Translucent_Lavender-150x150.avif"],
            ["translucent-light-jade","Translucent Light Jade","https://shop.dreamli.nl/wp-content/uploads/2025/09/Translucent_Light_Jade-150x150.avif"],
            ["translucent-mellow-yellow","Translucent Mellow Yellow","https://shop.dreamli.nl/wp-content/uploads/2025/09/Translucent_Mellow_Yellow-150x150.avif"],
            ["translucent-orange","Translucent Orange","https://shop.dreamli.nl/wp-content/uploads/2025/09/Translucent_Orange-150x150.avif"],
            ["translucent-purple","Translucent Purple","https://shop.dreamli.nl/wp-content/uploads/2025/09/Translucent_Purple-150x150.avif"],
            ["translucent-red","Translucent Red","https://shop.dreamli.nl/wp-content/uploads/2025/09/Translucent_Red-150x150.avif"],
            ["translucent-teal","Translucent Teal","https://shop.dreamli.nl/wp-content/uploads/2025/09/Translucent_Teal-150x150.avif"],
        ];
        $wood = [
            ["wood-black-walnut","Wood Black Walnut","https://shop.dreamli.nl/wp-content/uploads/2025/09/Wood_Black_Walnut.avif"],
            ["wood-classic-birch","Wood Classic Birch","https://shop.dreamli.nl/wp-content/uploads/2025/09/Wood_Classic_Birch.avif"],
            ["wood-clay-brown","Wood Clay Brown","https://shop.dreamli.nl/wp-content/uploads/2025/09/Wood_Clay_Brown.avif"],
            ["wood-ochre-yellow","Wood Ochre Yellow","https://shop.dreamli.nl/wp-content/uploads/2025/09/Wood_Ochre_Yellow.avif"],
            ["wood-rosewood","Wood Rosewood","https://shop.dreamli.nl/wp-content/uploads/2025/09/Wood_Rosewood.avif"],
            ["wood-white-oak","Wood White Oak","https://shop.dreamli.nl/wp-content/uploads/2025/09/Wood_White_Oak.avif"],
        ];
        $silk = [
            ["silk-aurora-purple","Silk Aurora Purple","https://shop.dreamli.nl/wp-content/uploads/2025/09/Aurora_Purple-150x150.avif"],
            ["silk-baby-blue","Silk Baby Blue","https://shop.dreamli.nl/wp-content/uploads/2025/09/Silk_Baby_Blue.avif"],
            ["silk-blue","Silk Blue","https://shop.dreamli.nl/wp-content/uploads/2025/09/Silk_Blue.avif"],
            ["silk-blue-hawaii","Silk Blue Hawaii","https://shop.dreamli.nl/wp-content/uploads/2025/09/Blue_Hawaii-150x150.avif"],
            ["silk-candy-green","Silk Candy Green","https://shop.dreamli.nl/wp-content/uploads/2025/09/Silk_Candy_Green.avif"],
            ["silk-candy-red","Silk Candy Red","https://shop.dreamli.nl/wp-content/uploads/2025/09/Silk_Candy_Red.avif"],
            ["silk-champagne","Silk Champagne","https://shop.dreamli.nl/wp-content/uploads/2025/09/Silk_Champagne.avif"],
            ["silk-dawn-radiance","Silk Dawn Radiance","https://shop.dreamli.nl/wp-content/uploads/2025/09/Dawn_Radiance-150x150.avif"],
            ["silk-gilded-rose","Silk Gilded Rose","https://shop.dreamli.nl/wp-content/uploads/2025/09/Gilded_Rose-150x150.avif"],
            ["silk-gold","Silk Gold","https://shop.dreamli.nl/wp-content/uploads/2025/09/Silk_Gold.avif"],
            ["silk-midnight-blaze","Silk Midnight Blaze","https://shop.dreamli.nl/wp-content/uploads/2025/09/Midnight_Blaze-150x150.avif"],
            ["silk-mint","Silk Mint","https://shop.dreamli.nl/wp-content/uploads/2025/09/Silk_Mint.avif"],
            ["silk-neon-city","Silk Neon City","https://shop.dreamli.nl/wp-content/uploads/2025/09/Neon_City-150x150.avif"],
            ["silk-pink","Silk Pink","https://shop.dreamli.nl/wp-content/uploads/2025/09/Silk_Pink.avif"],
            ["silk-purple","Silk Purple","https://shop.dreamli.nl/wp-content/uploads/2025/09/Silk_Purple.avif"],
            ["silk-rose-gold","Silk Rose Gold","https://shop.dreamli.nl/wp-content/uploads/2025/09/Silk_Rose_Gold.avif"],
            ["silk-silver","Silk Silver","https://shop.dreamli.nl/wp-content/uploads/2025/09/Silk_Silver.avif"],
            ["silk-south-beach","Silk South Beach","https://shop.dreamli.nl/wp-content/uploads/2025/09/South_Beach-150x150.avif"],
            ["silk-titan-gray","Silk Titan Gray","https://shop.dreamli.nl/wp-content/uploads/2025/09/Silk_Titan_Gray.avif"],
            ["silk-velvet-eclipse","Silk Velvet Eclipse","https://shop.dreamli.nl/wp-content/uploads/2025/09/Velvet_Eclipse-1-150x150.avif"],
            ["silk-white","Silk White","https://shop.dreamli.nl/wp-content/uploads/2025/09/Silk_White.avif"],
        ];

        
        // --- DYNAMIC FIELD GENERATION ---
        
        // Helper function for creating Color Swatches
        $mk = function($type_field_id, $material_label, $material_id, $conditional_slug, $rows){
            return [
                "conditionals" => [[ "rules" => [[ "field"=>$type_field_id,"condition"=>"==","value"=>$conditional_slug,"generated"=>false ]] ]],
                "label"        => $material_label, // Label is "Basic", "Sparkle", etc.
                "type"         => "image-swatch",
                "id"           => $material_id . '_' . substr(md5($type_field_id), 0, 6), // Make ID unique per part
                "pricing"      => ["enabled"=>false,"amount"=>0,"type"=>"fixed"],
                "clone"        => ["enabled"=>false,"type"=>"qty"],
                "product_query"=> new stdClass(),
                "group"        => "field",
                "subtype"      => null,
                "required"     => false,
                "description"  => null,
                "class"        => null,
                "width"        => null,
                "choices"      => self::map_choices($rows),
                "parent_clone" => [],
                "meta"         => "",
                "label_pos"    => "tooltip",
                "items_per_row" => 3,
                "items_per_row_mobile" => 3,
                "items_per_row_tablet" => 3,
                "grid_layout"  => "fixed",
                "item_width"   => 68
            ];
        };

        $fields = [];
        $variables = []; // We will also build variables dynamically
        
        // If no parts are defined, create a default "Body" part
        if (empty($parts)) {
            $parts = [['label'=>'Body','share_pct'=>100,'field_id'=>'']];
        }

        // --- THIS IS YOUR 'FOR' LOOP ---
        // Loop through each part and create a full set of fields
        foreach ($parts as $index => $part) {
            $part_label = esc_html($part['label'] ?? 'Part');
            $part_share_pct = (float)($part['share_pct'] ?? 100.0);
            
            // Use the user-provided ID if it exists, otherwise generate one
            $type_field_id = $part['field_id'] ?: 'ds_type_' . $index; 

            // --- 1. Create the "Type" field for this Part ---
            $choices = [
                array_merge(["selected"=>true ,"disabled"=>false,"slug"=>"hs2lo","label"=>"Basic"      ], $create_split_pricing("Basic", $part_share_pct)),
                array_merge(["selected"=>false,"disabled"=>false,"slug"=>"8tuei","label"=>"Sparkle"    ], $create_split_pricing("Sparkle", $part_share_pct)),
                array_merge(["selected"=>false,"disabled"=>false,"slug"=>"kheir","label"=>"galaxy"     ], $create_split_pricing("Galaxy", $part_share_pct)),
                array_merge(["selected"=>false,"disabled"=>false,"slug"=>"0qpgm","label"=>"Glow"       ], $create_split_pricing("Glow", $part_share_pct)),
                array_merge(["selected"=>false,"disabled"=>false,"slug"=>"x5vw3","label"=>"Marble"     ], $create_split_pricing("Marble", $part_share_pct)),
                array_merge(["selected"=>false,"disabled"=>false,"slug"=>"f87e1","label"=>"Matte"      ], $create_split_pricing("Matte", $part_share_pct)),
                array_merge(["selected"=>false,"disabled"=>false,"slug"=>"rx7f9","label"=>"Metallic"   ], $create_split_pricing("Metallic", $part_share_pct)),
                array_merge(["selected"=>false,"disabled"=>false,"slug"=>"dla8z","label"=>"Translucent"], $create_split_pricing("Translucent", $part_share_pct)),
                array_merge(["selected"=>false,"disabled"=>false,"slug"=>"egdqr","label"=>"Wood"       ], $create_split_pricing("Wood", $part_share_pct)),
                array_merge(["selected"=>false,"disabled"=>false,"slug"=>"y3xpw","label"=>"Silk"       ], $create_split_pricing("Silk", $part_share_pct)),
            ];

            $fields[] = [
                "conditionals" => [],
                "label"        => $part_label, // Dynamic Label: "Body" or "Part"
                "type"         => "text-swatch",
                "id"           => $type_field_id, // Dynamic ID
                "pricing"      => ["enabled"=>true,"amount"=>0,"type"=>"fixed"],
                "clone"        => ["enabled"=>false,"type"=>"qty"],
                "product_query"=> new stdClass(),
                "group"        => "field",
                "subtype"      => null,
                "required"     => true,
                "description"  => '<a href="/color-guide/">Are you confused? click here! </a>',
                "class"        => null,
                "width"        => null,
                "choices"      => $choices,
                "parent_clone" => [],
                "meta"         => "",
            ];

            // --- 2. Create all "Color" fields for this Part ---
            // We use the *original* IDs from your JSON
            $fields[] = $mk($type_field_id, 'Basic',       '23124d2','hs2lo', $basic);
            $fields[] = $mk($type_field_id, 'Sparkle',     '32c16ac','8tuei', $sparkle);
            $fields[] = $mk($type_field_id, 'Galaxy',      'cfc4ad3','kheir', $galaxy);
            $fields[] = $mk($type_field_id, 'Glow',        '3fc1ed1','0qpgm', $glow);
            $fields[] = $mk($type_field_id, 'Marble',      '5226a7a','x5vw3', $marble);
            $fields[] = $mk($type_field_id, 'Matte',       '2f549ea','f87e1', $matte);
            $fields[] = $mk($type_field_id, 'Metallic',    'ebe0e86','rx7f9', $metallic);
            $fields[] = $mk($type_field_id, 'Translucent', 'ae54e3f','dla8z', $translucent);
            $fields[] = $mk($type_field_id, 'Wood',        'ade19ab','egdqr', $wood);
            $fields[] = $mk($type_field_id, 'Silk',        'eb22f2c','y3xpw', $silk);
            
            // Add a variable for this part
            $variables[] = ["default"=>"0","name"=>"var_".$index,"rules"=>[["type"=>"field","field"=>$type_field_id,"variable"=>"","condition"=>"==","value"=>"8tuei"]]];
        }
        // --- END OF 'FOR' LOOP ---

        $json = [
            "fields"     => $fields,
            "conditions" => [[ "rules" => [[ "subject"=>"product","condition"=>"product","value"=>[["id"=>"1","text"=>""]] ]] ]], // Using a dummy ID
            "layout"     => ["swap_type"=>"rules","labels_position"=>"above","instructions_position"=>"field","mark_required"=>true,"enable_gallery_images"=>false,"gallery_images"=>[]],
            "variables"  => $variables,
        ];

        return wp_json_encode($json, JSON_UNESCAPED_SLASHES);
    }

    public static function render_box($post) {
        $w    = get_post_meta($post->ID, '_ds_weight_g', true);
        $th   = get_post_meta($post->ID, '_ds_time_h', true);
        $base = get_post_meta($post->ID, '_ds_base_material', true) ?: 'Basic';
        
        // Get the $parts array
        $parts = get_post_meta($post->ID, '_ds_parts', true);
        if (!is_array($parts) || empty($parts)) $parts = [['label'=>'Body','share_pct'=>100,'field_id'=>'']];
        
        $nonce = wp_create_nonce('wp_rest');
        $url_calc  = esc_url( rest_url('dreamli/v1/calc') );
        $url_build = esc_url( rest_url('dreamli/v1/options/build') );
        // NEW: Add the URL for our dynamic JSON endpoint
        $url_get_json = esc_url( rest_url('dreamli/v1/get-json') );
        ?>
        <div style="padding:6px 2px;">
            <p><label>وزن کل (گرم)<br>
                <input type="number" min="1" step="0.01" id="ds_w" name="ds_weight_g" value="<?php echo esc_attr($w); ?>" style="width:100%;">
            </label></p>
            <p><label>زمان پرینت (ساعت)<br>
                <input type="number" min="0.01" step="0.01" id="ds_th" name="ds_time_h" value="<?php echo esc_attr($th); ?>" style="width:100%;">
            </label></p>
            <p><label>متریال پایه (Baseline)<br>
                <select id="ds_base" name="ds_base_material" style="width:100%;">
                    <?php foreach (array_keys(DS_Pricing::get_cfg()['pe_materials']) as $m)
                        printf('<option value="%s"%s>%s</option>', esc_attr($m), selected($base,$m,false), esc_html($m)); ?>
                </select>
            </label></p>

            <hr>
            <p style="margin:6px 0;"><b>بخش‌ها</b> (نام + درصد وزن):</p>
            <div id="ds_parts_wrap"></div>
            <p><button type="button" class="button" id="ds_add_part">+ افزودن بخش</button></p>

            <div style="display:flex;gap:6px;margin:8px 0;flex-wrap:wrap">
                <button type="button" class="button" id="ds_btn_calc">محاسبه</button>
                <button type="button" class="button" id="ds_btn_full">اعمال بدون تخفیف (FULL)</button>
                <button type="button" class="button" id="ds_btn_market">اعمال با تخفیف (MARKET)</button>
            </div>

            <div id="ds_result" style="font-size:12px;line-height:1.6;color:#444">—</div>
        </div>

        <script>
        (function(){
          const pid   = <?php echo (int)$post->ID; ?>;
          const nonce = '<?php echo esc_js($nonce); ?>';
          const URLC  = '<?php echo $url_calc; ?>';
          const URLB  = '<?php echo $url_build; ?>';
          const URL_GET_JSON = '<?php echo $url_get_json; ?>'; // URL for the new endpoint

          // APF_JSON is no longer defined here. It will be fetched.
          
          const $ = s=>document.querySelector(s);
          const res = $('#ds_result');
          const w   = $('#ds_w');
          const th  = $('#ds_th');
          const base= $('#ds_base');
          const wrap= $('#ds_parts_wrap');

          const parts_initial = <?php echo wp_json_encode(array_values($parts)); ?>;

          function rowTpl(p, idx){
            return `
              <div class="ds-part" data-i="${idx}" style="border:1px solid #ccd0d4;padding:6px;margin:6px 0;border-radius:4px;">
                <div style="display:flex;gap:6px;align-items:center;">
                  <input type="text" class="ds_label" placeholder="نام بخش" value="${p.label||''}" style="flex:1;">
                  <input type="number" class="ds_share" min="0" max="100" step="1" value="${p.share_pct||0}" style="width:80px" title="سهم درصد از وزن">
                </div>
                <div style="margin-top:6px;display:flex;gap:6px;align-items:center;">
                  <input type="text" class="ds_fieldid" placeholder="(اختیاری) ID فیلد Type" value="${p.field_id||''}" style="flex:1;">
                  <button type="button" class="button button-small ds_del">حذف</button>
                </div>
              </div>`;
          }
          function render(parts){
            wrap.innerHTML = parts.map((p,i)=>rowTpl(p,i)).join('');
            wrap.querySelectorAll('.ds_del').forEach(btn=>{
              btn.addEventListener('click', e=> e.target.closest('.ds-part').remove());
            });
          }
          render(parts_initial);

          $('#ds_add_part').addEventListener('click',()=>{
            wrap.insertAdjacentHTML('beforeend', rowTpl({label:'Part',share_pct:0,field_id:''}, Date.now()));
          });

          function collectParts(){
            const out=[];
            wrap.querySelectorAll('.ds-part').forEach(box=>{
              out.push({
                label: box.querySelector('.ds_label').value||'Part',
                share_pct: Number(box.querySelector('.ds_share').value||0),
                field_id: box.querySelector('.ds_fieldid').value||'',
              });
            });
            // Ensure at least one part exists
            if (out.length === 0) out.push({label:'Body', share_pct:100, field_id:''});
            return out;
          }

          // persist parts
          const hiddenParts = document.createElement('input');
          hiddenParts.type = 'hidden';
          hiddenParts.name = 'ds_parts_json';
          document.forms['post'].appendChild(hiddenParts);
          function setHiddenParts(){ hiddenParts.value = JSON.stringify(collectParts()); }
          document.addEventListener('input', setHiddenParts); setHiddenParts();

          let lastCalc = null;

          async function call(url, payload){
            const res = await fetch(url, {
              method:'POST',
              headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},
              body: JSON.stringify(payload)
            });
            const txt = await res.text();
            let json=null; try{ json=JSON.parse(txt);}catch(e){}
            return { ok: res.ok, status: res.status, json, txt };
          }

          async function doCalc(){
            res.innerHTML = 'در حال محاسبه...';
            const r = await call(URLC, {
              product_id: pid,
              weight_g: Number(w.value||0),
              time_h: Number(th.value||0),
              base_material: base.value,
              parts: collectParts()
            });
            if (!(r.ok && r.json)){
              res.innerHTML = 'خطا: HTTP '+ r.status + ' – ' + (r.json?.message || r.txt?.slice(0,180) || 'unknown');
              console.error('REST /calc error', r);
              return null;
            }
            lastCalc = r.json;
            res.innerHTML =
              'BASE (FULL): € '+ Number(r.json.base_full).toFixed(2) +
              ' | BASE (MARKET): € '+ Number(r.json.base_market).toFixed(2);
            return r.json;
          }

          const sleep = (ms)=>new Promise(r=>setTimeout(r,ms));
          const click = (el)=>{
            if (!el) return false;
            el.dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true}));
            if (window.jQuery) try { jQuery(el).trigger('click'); } catch(e){}
            return true;
          };

          // This function now accepts the JSON string as an argument
          async function runApfImportFixed(apfJsonString){
            if (!apfJsonString) {
                console.warn('No JSON string provided to runApfImportFixed');
                return;
            }
            // ... (rest of the function is identical)
            click(document.querySelector('li.customfields_options.customfields_tab a[href="#customfields_options"]'));
            await sleep(250);
            const impLink = document.querySelector('.wapf-top-options .wapf-import');
            if (!impLink) { console.warn('Import link not found'); return; }
            const modalClass = impLink.getAttribute('data-modal-class');
            click(impLink);
            let modal=null, ta=null;
            for (let i=0;i<60;i++){
              modal = modal || (modalClass ? document.querySelector('.'+modalClass) : null);
              if (modal && getComputedStyle(modal).display === 'none') modal.style.display = 'block';
              ta = modal?.querySelector('.wapf-import-ta');
              if (ta) break;
              await sleep(100);
            }
            if (!ta) { console.warn('Import textarea not found'); return; }

            ta.value = apfJsonString; // Use the new JSON string
            
            ta.dispatchEvent(new InputEvent('input',{bubbles:true}));
            ta.dispatchEvent(new Event('change',{bubbles:true}));
            const sel = modal.querySelector('.wapf-import-mode');
            if (sel) { sel.value = 'replace'; sel.dispatchEvent(new Event('change',{bubbles:true})); }
            const importBtn = modal.querySelector('.btn-wapf-import');
            if (importBtn) click(importBtn);
            for (let i=0;i<60;i++){
              const okEl = modal.querySelector('.wapf-import-success');
              if (okEl && getComputedStyle(okEl).display !== 'none') break;
              await sleep(100);
            }
            const closeA = modal.querySelector('.wapf_close');
            if (closeA) {
              closeA.dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true}));
              if (window.jQuery) try { jQuery(closeA).trigger('click'); } catch(e){}
            }
            await sleep(150);
            if (modal && getComputedStyle(modal).display !== 'none') modal.style.display = 'none';
            const generalTab = document.querySelector('li.general_options.general_tab a[href="#general_product_data"]');
            if (generalTab) {
              generalTab.dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true}));
              if (window.jQuery) try { jQuery(generalTab).trigger('click'); } catch(e){}
            }
          }

          async function doApply(mode){
            if (!lastCalc) {
              const cj = await doCalc();
              if (!cj) return;
            }

            res.innerHTML = 'در حال ساخت JSON داینامیک...';
            
            // --- SYSTEM 1: Get the new JSON ---
            // Call the new endpoint, passing the *current* parts from the UI
            const jsonRes = await call(URL_GET_JSON, {
                parts: collectParts()
            });

            if (!(jsonRes.ok && jsonRes.json && jsonRes.json.json)){
                res.innerHTML = 'خطا: دریافت JSON داینامیک شکست خورد.';
                console.error('REST /get-json error', jsonRes);
                return;
            }
            
            const freshJsonString = jsonRes.json.json;
            res.innerHTML = 'JSON جدید ساخته شد. در حال ایمپورت...';

            // --- SYSTEM 2: (JS Builder) ---
            // Import the *fresh* JSON string we just fetched
            try { await runApfImportFixed(freshJsonString); } catch(e){ console.warn('APF import failed:', e); }

            // --- SYSTEM 3: (PHP Pricer) ---
            // This runs last, to apply 'percent' or 'inherit' prices
            res.innerHTML = 'در حال اعمال قیمت‌های نهایی...';
            const r = await call(URLB, {
              product_id: pid,
              mode,
              weight_g: Number(w.value||0),
              time_h: Number(th.value||0),
              base_material: base.value,
              parts: collectParts(),
              ensure_create: true 
            });

            if (!(r.ok && r.json)){
              res.innerHTML = 'خطا: HTTP '+ r.status + ' – ' + (r.json?.message || r.txt?.slice(0,180) || 'apply failed');
              console.error('REST /options/build error', r);
              return;
            }

            const reg = document.getElementById('_regular_price');
            const sale= document.getElementById('_sale_price');
            const full = Number(r.json.base_full ?? lastCalc.base_full).toFixed(2);
            const market = Number(r.json.base_market ?? lastCalc.base_market).toFixed(2);
            if (reg) reg.value = full;
            if (sale) sale.value = (mode==='market') ? market : '';

            res.innerHTML = 'انجام شد. Regular = €'+full + (mode==='market' ? (' | Sale = €'+market) : '') + '<br><em>برای ذخیره نهایی Update/Publish را بزن.</em>';
          }

          document.getElementById('ds_btn_calc').addEventListener('click', doCalc);
          document.getElementById('ds_btn_full').addEventListener('click', ()=>doApply('full'));
          document.getElementById('ds_btn_market').addEventListener('click', ()=>doApply('market'));
        })();
        </script>
        <?php
    }

    public static function save_meta($post_id, $post, $update) {
        if ($post->post_type!=='product') return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post',$post_id)) return;

        if (isset($_POST['ds_weight_g'])) update_post_meta($post_id,'_ds_weight_g', floatval($_POST['ds_weight_g']));
        if (isset($_POST['ds_time_h']))  update_post_meta($post_id,'_ds_time_h',  floatval($_POST['ds_time_h']));
        if (isset($_POST['ds_base_material'])) update_post_meta($post_id,'_ds_base_material', sanitize_text_field($_POST['ds_base_material']));
        if (!empty($_POST['ds_parts_json'])) {
            $arr = json_decode(stripslashes($_POST['ds_parts_json']), true);
            if (is_array($arr)) update_post_meta($post_id,'_ds_parts', $arr);
        }
    }
}