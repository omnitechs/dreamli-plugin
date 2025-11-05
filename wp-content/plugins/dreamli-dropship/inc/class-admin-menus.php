<?php
if (!defined('ABSPATH')) exit;

final class DS_Admin_Menus {
    static function init() {
        add_action('admin_menu', [__CLASS__, 'menus']);
    }

    static function menus() {
        if (!current_user_can('manage_options')) return;

        add_menu_page(
            'Dropship',
            'Dropship',
            'manage_options',
            'ds-root',
            [__CLASS__,'root'],
            'dashicons-admin-generic',
            58
        );

        add_submenu_page('ds-root','Settings','Settings','manage_options','ds-settings',[__CLASS__,'settings']);
        add_submenu_page('ds-root','Ledger','Ledger','manage_options','ds-ledger',[__CLASS__,'ledger']);
        add_submenu_page('ds-root','Orders Report','Orders Report','manage_options','ds-orders-report',[__CLASS__,'orders_report']);
        add_submenu_page('ds-root','Leaderboard','Leaderboard','manage_options','ds-leaderboard-admin',[__CLASS__,'leaderboard']);
    }

    static function root() {
        echo '<div class="wrap"><h1>Dreamli Dropship</h1><p>Use submenus.</p></div>';
    }

    static function settings() {

        // ذخیره تنظیمات
        if (isset($_POST['ds_save']) && check_admin_referer('ds_settings')) {

            $fields = [
                // Moderation
                'posts_pending','products_pending',

                // Curated credit
                'curated_credit_enable','curated_credit_amount','credit_min_images','credit_min_words','credit_min_rankmath',

                // Platform fees
                'open_fee_per_item','curated_first_n','curated_first_fee','curated_after_fee',

                // Wallet
                'withdraw_min',

                // Ads CPC
                'ads_cpc_home','ads_cpc_category',

                // Product Options integration
                'po_meta_key','po_type_field_main_id','po_type_field_secondary_id',

                // Simple calculator
                'calc_mode','calc_base','calc_rate_per_gram','calc_rate_per_min','calc_markup_percent',
                'calc_round_cents','calc_custom_expr_enable','calc_custom_expr',

                // Advanced pricing engine
                'pe_waste_rate','pe_fail_risk_rate','pe_electricity_kwh','pe_printer_power_w',
                'pe_labor_rate','pe_labor_fixed_min','pe_packaging','pe_misc',
                'pe_dep_full','pe_oh_full','pe_dep_market','pe_oh_market',
                'pe_profit_hint_full','pe_profit_hint_market','pe_materials'
            ];

            $new = [];
            foreach ($fields as $f) {
                if (!isset($_POST[$f])) {
                    if (in_array($f, [
                        'posts_pending','products_pending',
                        'curated_credit_enable','calc_round_cents','calc_custom_expr_enable'
                    ], true)) {
                        $new[$f] = 0;
                    }
                    continue;
                }

                $val = $_POST[$f];

                if (in_array($f, ['posts_pending','products_pending','curated_credit_enable','calc_round_cents','calc_custom_expr_enable'], true)) {
                    $new[$f] = (int) !!$val;

                } elseif ($f === 'calc_mode') {
                    $new[$f] = in_array($val, ['linear','custom_expr'], true) ? $val : 'linear';

                } elseif ($f === 'pe_materials') {
                    // پذیرش JSON (ترجیحاً) یا رشته‌ی serialize شده
                    $try = json_decode(stripslashes($val), true);
                    $new[$f] = is_array($try) ? $try : $val;

                } else {
                    $new[$f] = is_numeric($val) ? (float)$val : sanitize_text_field($val);
                }
            }

            // --- قوانین قیمت‌گذاری انتخاب‌ها (جدید) ---
            // انتطار ورودی: cp_mat[][name|type|value|extra]
            $rules = [];
            if (!empty($_POST['cp_mat']) && is_array($_POST['cp_mat'])) {
                foreach ($_POST['cp_mat'] as $row) {
                    $name = sanitize_text_field($row['name'] ?? '');
                    if ($name === '') continue;

                    $type  = sanitize_text_field($row['type'] ?? 'inherit');
                    if (!in_array($type, ['inherit','fixed','percent','none'], true)) $type = 'inherit';

                    $value = isset($row['value']) ? floatval($row['value']) : 0.0; // برای fixed یا percent
                    $extra = isset($row['extra']) ? floatval($row['extra']) : 0.0; // اکسترا ثابت

                    $rules[$name] = [
                        'type'  => $type,   // inherit: از دلتاهای محاسبه‌شده (fixed) استفاده کن
                        'value' => $value,  // در حالت percent = درصد روی قیمت پایه | در حالت fixed = مقدار ثابت
                        'extra' => $extra,  // مبلغ ثابت اضافه روی هر انتخاب
                    ];
                }
            }
            $new['po_choice_rules'] = $rules;
            // -------------------------------------------

            DS_Settings::update($new);
            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
        }

        $s = DS_Settings::get();
        $materials_cfg = $s['pe_materials'];
        $materials = is_array($materials_cfg) ? array_keys($materials_cfg) : [];
        $choice_rules = is_array($s['po_choice_rules'] ?? null) ? $s['po_choice_rules'] : [];

        ?>
        <div class="wrap">
          <h1>Dropship Settings</h1>
          <form method="post">
            <?php wp_nonce_field('ds_settings'); ?>

            <h2>Moderation</h2>
            <p><label><input type="checkbox" name="posts_pending" value="1" <?php checked($s['posts_pending']); ?>> Vendor blog posts → <b>Pending</b></label></p>
            <p><label><input type="checkbox" name="products_pending" value="1" <?php checked($s['products_pending']); ?>> Vendor products → <b>Pending</b></label></p>

            <h2 style="margin-top:24px;">Curated Credit</h2>
            <p><label><input type="checkbox" name="curated_credit_enable" value="1" <?php checked($s['curated_credit_enable']); ?>> Enable € credit on first publish</label></p>
            <p>Amount (€): <input type="number" step="0.01" name="curated_credit_amount" value="<?php echo esc_attr($s['curated_credit_amount']); ?>"></p>
            <p>Require min images: <input type="number" step="1" min="0" name="credit_min_images" value="<?php echo esc_attr($s['credit_min_images']); ?>"> | min words: <input type="number" step="1" min="0" name="credit_min_words" value="<?php echo esc_attr($s['credit_min_words']); ?>"> | RankMath ≥ <input type="number" step="1" min="0" max="100" name="credit_min_rankmath" value="<?php echo esc_attr($s['credit_min_rankmath']); ?>"></p>

            <h2 style="margin-top:24px;">Platform Fees</h2>
            <p>Open plan fee/item (€): <input type="number" step="0.01" name="open_fee_per_item" value="<?php echo esc_attr($s['open_fee_per_item']); ?>"></p>
            <p>Curated: first N items <input type="number" step="1" name="curated_first_n" value="<?php echo esc_attr($s['curated_first_n']); ?>"> → fee € <input type="number" step="0.01" name="curated_first_fee" value="<?php echo esc_attr($s['curated_first_fee']); ?>"> ; after → € <input type="number" step="0.01" name="curated_after_fee" value="<?php echo esc_attr($s['curated_after_fee']); ?>"></p>

            <h2 style="margin-top:24px;">Wallet / Payouts</h2>
            <p>Minimum withdrawal (€): <input type="number" step="0.01" name="withdraw_min" value="<?php echo esc_attr($s['withdraw_min']); ?>"></p>

            <h2 style="margin-top:24px;">Promoted Products (CPC)</h2>
            <p>Cost per click – Home (€): <input type="number" step="0.01" name="ads_cpc_home" value="<?php echo esc_attr($s['ads_cpc_home']); ?>">
               | Category (€): <input type="number" step="0.01" name="ads_cpc_category" value="<?php echo esc_attr($s['ads_cpc_category']); ?>">
            </p>

            <h2 style="margin-top:24px;">Product Options Integration</h2>
            <p>Meta key (JSON fields): <input type="text" name="po_meta_key" value="<?php echo esc_attr($s['po_meta_key']); ?>" placeholder="_wapf_field ..."> <small>خالی = کشف خودکار</small></p>
            <p>Type (main) field ID: <input type="text" name="po_type_field_main_id" value="<?php echo esc_attr($s['po_type_field_main_id']); ?>" placeholder="مثلاً 8cd3e52">
               &nbsp; Type (part2) field ID (اختیاری): <input type="text" name="po_type_field_secondary_id" value="<?php echo esc_attr($s['po_type_field_secondary_id']); ?>"></p>

            <h2 style="margin-top:24px;">Price Calculator (simple)</h2>
            <p>Mode:
                <select name="calc_mode">
                    <option value="linear" <?php selected($s['calc_mode'],'linear'); ?>>Linear</option>
                    <option value="custom_expr" <?php selected($s['calc_mode'],'custom_expr'); ?>>Custom Expression</option>
                </select>
            </p>
            <p>Base € <input type="number" step="0.01" name="calc_base" value="<?php echo esc_attr($s['calc_base']); ?>">
               | €/g <input type="number" step="0.0001" name="calc_rate_per_gram" value="<?php echo esc_attr($s['calc_rate_per_gram']); ?>">
               | €/min <input type="number" step="0.0001" name="calc_rate_per_min" value="<?php echo esc_attr($s['calc_rate_per_min']); ?>">
               | Markup % <input type="number" step="0.1" name="calc_markup_percent" value="<?php echo esc_attr($s['calc_markup_percent']); ?>">
               | <label><input type="checkbox" name="calc_round_cents" value="1" <?php checked($s['calc_round_cents']); ?>> Round to 2 decimals</label>
            </p>
            <p><label><input type="checkbox" name="calc_custom_expr_enable" value="1" <?php checked($s['calc_custom_expr_enable']); ?>> Enable Custom expression</label></p>
            <p><textarea name="calc_custom_expr" rows="2" style="width:100%;"><?php echo esc_textarea($s['calc_custom_expr']); ?></textarea></p>

            <h2 style="margin-top:24px;">Pricing Engine (Advanced)</h2>
            <p>Waste <input type="number" step="0.001" name="pe_waste_rate" value="<?php echo esc_attr($s['pe_waste_rate']); ?>">
               | Fail risk <input type="number" step="0.001" name="pe_fail_risk_rate" value="<?php echo esc_attr($s['pe_fail_risk_rate']); ?>">
               | kWh € <input type="number" step="0.01" name="pe_electricity_kwh" value="<?php echo esc_attr($s['pe_electricity_kwh']); ?>">
               | Power W <input type="number" step="1" name="pe_printer_power_w" value="<?php echo esc_attr($s['pe_printer_power_w']); ?>"></p>
            <p>Labor €/h <input type="number" step="0.1" name="pe_labor_rate" value="<?php echo esc_attr($s['pe_labor_rate']); ?>">
               | Labor fixed min <input type="number" step="1" name="pe_labor_fixed_min" value="<?php echo esc_attr($s['pe_labor_fixed_min']); ?>">
               | Packaging € <input type="number" step="0.01" name="pe_packaging" value="<?php echo esc_attr($s['pe_packaging']); ?>">
               | Misc € <input type="number" step="0.01" name="pe_misc" value="<?php echo esc_attr($s['pe_misc']); ?>"></p>
            <p>FULL: Dep €/h <input type="number" step="0.01" name="pe_dep_full" value="<?php echo esc_attr($s['pe_dep_full']); ?>">
               | OH €/h <input type="number" step="0.01" name="pe_oh_full" value="<?php echo esc_attr($s['pe_oh_full']); ?>">
               | Profit hint € <input type="number" step="0.1" name="pe_profit_hint_full" value="<?php echo esc_attr($s['pe_profit_hint_full']); ?>"></p>
            <p>MARKET: Dep €/h <input type="number" step="0.01" name="pe_dep_market" value="<?php echo esc_attr($s['pe_dep_market']); ?>">
               | OH €/h <input type="number" step="0.01" name="pe_oh_market" value="<?php echo esc_attr($s['pe_oh_market']); ?>">
               | Profit hint € <input type="number" step="0.1" name="pe_profit_hint_market" value="<?php echo esc_attr($s['pe_profit_hint_market']); ?>"></p>

            <p><b>Materials JSON</b> (name → price_per_kg_full/market, discountable, premium_uplift_pct):</p>
            <textarea name="pe_materials" rows="8" style="width:100%;"><?php
                echo esc_textarea(is_array($s['pe_materials']) ? wp_json_encode($s['pe_materials'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) : (string)$s['pe_materials']);
            ?></textarea>

            <h2 style="margin-top:28px;">Choice Pricing Rules per Material</h2>
            <p style="max-width:850px;">
                <small>
                    <b>inherit</b>: از دلتاهای محاسبه‌شده (fixed) استفاده می‌شود.<br>
                    <b>fixed</b>: مقدار <em>Value</em> به‌صورت یورو به گزینه اضافه می‌شود.<br>
                    <b>percent</b>: مقدار <em>Value</em> درصدی از <em>قیمت پایه محصول</em> (base price) به گزینه اضافه می‌شود.<br>
                    <b>none</b>: قیمت‌گذاری گزینه غیرفعال می‌شود.<br>
                    فیلد <b>Extra</b> همیشه به‌صورت ثابت به قیمت گزینه افزوده می‌شود (روی همه‌ی حالت‌ها).
                </small>
            </p>

            <table class="widefat striped" style="max-width:1000px;">
              <thead>
                <tr>
                  <th style="width:220px;">Material</th>
                  <th style="width:160px;">Type</th>
                  <th style="width:160px;">Value</th>
                  <th style="width:160px;">Extra (€)</th>
                </tr>
              </thead>
              <tbody>
              <?php
                if ($materials) {
                    $i = 0;
                    foreach ($materials as $m) {
                        $rule = $choice_rules[$m] ?? ['type'=>'inherit','value'=>0,'extra'=>0];
                        $type  = esc_attr($rule['type']);
                        $value = esc_attr($rule['value']);
                        $extra = esc_attr($rule['extra']);
                        echo '<tr>';
                        echo '<td><input type="text" readonly value="'.esc_attr($m).'" />';
                        echo '<input type="hidden" name="cp_mat['.$i.'][name]" value="'.esc_attr($m).'"></td>';

                        echo '<td><select name="cp_mat['.$i.'][type]">';
                        foreach (['inherit'=>'inherit','fixed'=>'fixed','percent'=>'percent','none'=>'none'] as $k=>$lbl) {
                            echo '<option value="'.$k.'" '.selected($type,$k,false).'>'.$lbl.'</option>';
                        }
                        echo '</select></td>';

                        echo '<td><input type="number" step="0.01" name="cp_mat['.$i.'][value]" value="'.$value.'" /></td>';
                        echo '<td><input type="number" step="0.01" name="cp_mat['.$i.'][extra]" value="'.$extra.'" /></td>';
                        echo '</tr>';
                        $i++;
                    }
                } else {
                    echo '<tr><td colspan="4">No materials found. Fill Materials JSON above.</td></tr>';
                }
              ?>
              </tbody>
            </table>

            <p style="margin-top:16px;">
                <button class="button button-primary" name="ds_save" value="1">Save Settings</button>
            </p>
          </form>
        </div>
        <?php
    }

    static function ledger() {
        if (!current_user_can('manage_options')) wp_die('No access.');

        // actions
        if (isset($_POST['ds_ledger_action']) && check_admin_referer('ds_ledger')) {
            global $wpdb;
            $action = sanitize_text_field($_POST['ds_ledger_action']);
            if ($action === 'mark_withdraw') {
                $id = (int)($_POST['row_id'] ?? 0);
                $status = in_array($_POST['new_status'] ?? '', ['paid','rejected'], true) ? $_POST['new_status'] : 'paid';
                $wpdb->update(DS_Wallet::table(), ['status'=>$status], ['id'=>$id]);
                echo '<div class="notice notice-success"><p>Updated.</p></div>';
            } elseif ($action === 'manual_adjust') {
                $uid = (int)($_POST['user_id'] ?? 0);
                $amt = (float)($_POST['amount'] ?? 0);
                $note = sanitize_text_field($_POST['note'] ?? '');
                if ($uid && $amt) {
                    DS_Wallet::add($uid, 'adjust', $amt, 'admin#'.get_current_user_id(), 'posted', ['note'=>$note]);
                    echo '<div class="notice notice-success"><p>Adjustment added.</p></div>';
                }
            }
        }

        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM ".DS_Wallet::table()." ORDER BY id DESC LIMIT 200");

        echo '<div class="wrap"><h1>Ledger</h1>';

        echo '<h2>Manual adjustment</h2>';
        echo '<form method="post" style="margin:12px 0">';
        wp_nonce_field('ds_ledger');
        echo '<input type="hidden" name="ds_ledger_action" value="manual_adjust">';
        echo 'User ID: <input type="number" name="user_id" required> ';
        echo 'Amount (€): <input type="number" step="0.01" name="amount" required> ';
        echo 'Note: <input type="text" name="note" style="width:260px"> ';
        echo '<button class="button button-primary">Add</button>';
        echo '</form>';

        echo '<h2>Latest entries</h2>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>User</th><th>Type</th><th>Amount</th><th>Status</th><th>Ref</th><th>Created</th><th>Action</th></tr></thead><tbody>';
        if ($rows) {
            foreach ($rows as $r) {
                echo '<tr>';
                printf('<td>%d</td><td>%d</td><td>%s</td><td>€%0.2f</td><td>%s</td><td>%s</td><td>%s</td>',
                    $r->id, $r->user_id, esc_html($r->type), $r->amount, esc_html($r->status), esc_html($r->ref_id), esc_html($r->created_at)
                );
                echo '<td>';
                if ($r->type === 'withdraw' && $r->status === 'pending') {
                    echo '<form method="post" style="display:inline">';
                    wp_nonce_field('ds_ledger');
                    echo '<input type="hidden" name="ds_ledger_action" value="mark_withdraw">';
                    echo '<input type="hidden" name="row_id" value="'.esc_attr($r->id).'">';
                    echo '<select name="new_status"><option value="paid">paid</option><option value="rejected">rejected</option></select> ';
                    echo '<button class="button">Set</button>';
                    echo '</form>';
                }
                echo '</td></tr>';
            }
        } else echo '<tr><td colspan="8">No rows.</td></tr>';
        echo '</tbody></table></div>';
    }

    static function orders_report() {
        if (!current_user_can('manage_options')) wp_die('No access.');
        if (!function_exists('wc_get_orders')) { echo '<div class="wrap"><p>WooCommerce required.</p></div>'; return; }

        $paged = max(1, intval($_GET['paged'] ?? 1));
        $per_page = 25;

        $data = wc_get_orders(['limit'=>$per_page,'paginate'=>true,'page'=>$paged,'orderby'=>'date','order'=>'DESC']);
        $orders = is_object($data) && isset($data->orders) ? $data->orders : (array)$data;

        echo '<div class="wrap"><h1>Orders Report</h1>';
        echo '<table class="widefat striped"><thead><tr><th>#</th><th>Date</th><th>Status</th><th>Total</th><th>Platform Fee</th></tr></thead><tbody>';
        foreach ($orders as $order) {
            $fee = get_post_meta($order->get_id(), '_ds_platform_fee_total_eur', true);
            printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>€%0.2f</td><td>%s</td></tr>',
                esc_html($order->get_order_number()),
                esc_html($order->get_date_created() ? $order->get_date_created()->date_i18n('Y-m-d H:i') : '-'),
                esc_html(wc_get_order_status_name($order->get_status())),
                floatval($order->get_total()),
                $fee!=='' ? '€'.esc_html($fee) : '—'
            );
        }
        echo '</tbody></table>';

        $next = add_query_arg(['paged'=>$paged+1], admin_url('admin.php?page=ds-orders-report'));
        $prev = add_query_arg(['paged'=>max(1,$paged-1)], admin_url('admin.php?page=ds-orders-report'));
        echo '<p style="margin:12px;"><a class="button" href="'.esc_url($prev).'">Prev</a> <a class="button" href="'.esc_url($next).'">Next</a></p>';
        echo '</div>';        
    }

    static function leaderboard() {
        if (!current_user_can('manage_options')) wp_die('No access.');
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'earned';
        $preset = isset($_GET['preset']) ? sanitize_key($_GET['preset']) : 'weekly';
        $from = isset($_GET['from']) ? sanitize_text_field($_GET['from']) : '';
        $to   = isset($_GET['to']) ? sanitize_text_field($_GET['to']) : '';
        $paged = max(1, intval($_GET['paged'] ?? 1));

        list($start, $end) = DS_Leaderboard::get_period($preset, $from, $to);
        $rows = DS_Leaderboard::aggregate($start, $end);

        echo '<div class="wrap">';
        echo '<h1>Leaderboard</h1>';
        DS_Leaderboard::render_filters($tab, $preset, $start, $end);
        DS_Leaderboard::render_table($rows, $tab, $paged, 50);
        echo '<p style="margin-top:12px;color:#666;">Views counted once per viewer per product per day. Time range: '.esc_html($start).' → '.esc_html($end).'</p>';
        echo '</div>';
    }
}