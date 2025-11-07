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
        add_submenu_page('ds-root','Entitlements Manager','Entitlements Manager','manage_options','ds-entitlements-manager',[__CLASS__,'entitlements_manager']);
        add_submenu_page('ds-root','Dashboard','Dashboard','manage_options','ds-dashboard',[__CLASS__,'dashboard']);
    }

    static function root() {
        echo '<div class="wrap"><h1>Dreamli Dropship</h1><p>Use submenus.</p></div>';
    }

    static function settings() {

        // ذخیره تنظیمات
        if (isset($_POST['ds_save']) && check_admin_referer('ds_settings')) {

            $fields = [
                // Moderation
                'posts_pending','products_pending','vendor_admin_publish_reward_eur',
                // Drive Importer
                'drive_folder_id','drive_sa_json','drive_batch_size','drive_weekly_hour','drive_weekly_dow',

                // Curated credit
                'curated_credit_enable','curated_credit_amount','credit_min_images','credit_min_words','credit_min_rankmath',

                // Platform fees
                'open_fee_per_item','curated_first_n','curated_first_fee','curated_after_fee',

                // Wallet
                'withdraw_min',

                // Entitlements (monthly)
                'entitlements_enable','entitlement_fee_eur','entitlement_confirm_days','pool_user_id',
                // Entitlement routing/protections
                'entitlement_controls_payouts_ads','pause_payouts_while_pending','ads_autopause_on_entitlement_loss',

                // Claim & Re-claim dynamic fees
                'claim_fee_enable','entitlement_dynamic_fee_enable','claim_fee_period','claim_fee_price_basis','claim_fee_denominator_scope','claim_fee_min_pct','claim_fee_max_pct','claim_fee_min_eur','claim_fee_cache_minutes','claim_fee_statuses',

                // View payouts settings
                'enable_view_payouts','view_payout_rate_eur',
                'view_cap_per_ip_per_product_per_day','view_cap_per_ip_per_day_sitewide','view_cap_per_viewer_per_day_sitewide',
                'view_cap_per_product_per_day','view_cap_per_vendor_per_day','view_cap_per_vendor_per_month',
                'view_cap_sitewide_per_day','view_cap_sitewide_per_month',
                'payout_cap_per_vendor_per_day_eur','payout_cap_per_vendor_per_month_eur','payout_cap_sitewide_per_day_eur','payout_cap_sitewide_per_month_eur',
                'view_pay_for_bots','view_record_bots','view_ua_denylist','view_excluded_vendors','view_excluded_products',

                // Ads CPC
                'ads_cpc_home','ads_cpc_category',

                // Protections: product completeness/status
                'protect_status_demotion','require_featured_image','require_product_category','min_title_len','min_content_len',

                // Snapshots
                'snapshot_retention_days',

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
                        'curated_credit_enable','calc_round_cents','calc_custom_expr_enable',
                        'enable_view_payouts','view_pay_for_bots','view_record_bots',
                        'entitlements_enable','entitlement_controls_payouts_ads','pause_payouts_while_pending','ads_autopause_on_entitlement_loss',
                        'protect_status_demotion','require_featured_image','require_product_category',
                        'claim_fee_enable','entitlement_dynamic_fee_enable'
                    ], true)) {
                        $new[$f] = 0;
                    }
                    continue;
                }

                $val = $_POST[$f];

                if (in_array($f, ['posts_pending','products_pending','curated_credit_enable','calc_round_cents','calc_custom_expr_enable','enable_view_payouts','view_pay_for_bots','view_record_bots','entitlements_enable','entitlement_controls_payouts_ads','pause_payouts_while_pending','ads_autopause_on_entitlement_loss','protect_status_demotion','require_featured_image','require_product_category'], true)) {
                    $new[$f] = (int) !!$val;

                } elseif ($f === 'calc_mode') {
                    $new[$f] = in_array($val, ['linear','custom_expr'], true) ? $val : 'linear';

                } elseif ($f === 'pe_materials') {
                    // پذیرش JSON (ترجیحاً) یا رشته‌ی serialize شده
                    $try = json_decode(stripslashes($val), true);
                    $new[$f] = is_array($try) ? $try : $val;

                } elseif ($f === 'drive_sa_json') {
                    // Preserve JSON as pasted; do not over-sanitize
                    $new[$f] = is_string($_POST[$f]) ? trim(wp_unslash($_POST[$f])) : '';
                } elseif (in_array($f, ['drive_batch_size','drive_weekly_hour','drive_weekly_dow'], true)) {
                    $iv = (int)$val;
                    if ($f === 'drive_batch_size') { $iv = max(1, min(100, $iv)); }
                    if ($f === 'drive_weekly_hour') { $iv = max(0, min(23, $iv)); }
                    if ($f === 'drive_weekly_dow') { $iv = max(-1, min(6, $iv)); }
                    $new[$f] = $iv;
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

            // Normalize list fields
            if (isset($_POST['view_ua_denylist'])) {
                $ua = is_array($_POST['view_ua_denylist']) ? $_POST['view_ua_denylist'] : explode(',', (string)$_POST['view_ua_denylist']);
                $ua = array_values(array_filter(array_map(function($s){ return strtolower(trim(sanitize_text_field($s))); }, $ua)));
                $new['view_ua_denylist'] = $ua;
            }
            if (isset($_POST['view_excluded_vendors'])) {
                $ids = array_values(array_filter(array_map('intval', preg_split('/[\s,;]+/', (string)$_POST['view_excluded_vendors']))));
                $new['view_excluded_vendors'] = $ids;
            }
            if (isset($_POST['view_excluded_products'])) {
                $ids = array_values(array_filter(array_map('intval', preg_split('/[\s,;]+/', (string)$_POST['view_excluded_products']))));
                $new['view_excluded_products'] = $ids;
            }
            if (isset($_POST['claim_fee_statuses'])) {
                $st = is_array($_POST['claim_fee_statuses']) ? $_POST['claim_fee_statuses'] : explode(',', (string)$_POST['claim_fee_statuses']);
                $st = array_values(array_filter(array_map(function($s){ return strtolower(trim(sanitize_text_field($s))); }, $st)));
                $new['claim_fee_statuses'] = $st;
            }

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

          <?php
          // Drive notices
          if (isset($_GET['ds_drive_msg'])) {
            $msg = sanitize_text_field($_GET['ds_drive_msg']);
            $err = isset($_GET['ds_drive_err']) ? sanitize_text_field($_GET['ds_drive_err']) : '';
            if ($msg === 'ok') {
              echo '<div class="notice notice-success"><p>Drive connection OK.</p></div>';
            } elseif ($msg === 'sync_started') {
              echo '<div class="notice notice-info"><p>Drive sync started in the background.</p></div>';
            } elseif ($msg === 'reset_ok') {
              echo '<div class="notice notice-success"><p>Drive sync stopped and state cleared.</p></div>';
            } elseif ($msg === 'clean_ok') {
              echo '<div class="notice notice-success"><p>All Drive sync data purged and tasks unscheduled.</p></div>';
            } else {
              echo '<div class="notice notice-error"><p>Drive error: '.esc_html($err ?: $msg).'</p></div>';
            }
          }
          ?>

          <h2>Google Drive Importer</h2>
          <form method="post">
            <?php wp_nonce_field('ds_settings'); ?>
            <table class="form-table" style="max-width:1000px;">
              <tr>
                <th scope="row">Root Folder ID</th>
                <td>
                  <input type="text" name="drive_folder_id" value="<?php echo esc_attr($s['drive_folder_id']); ?>" style="width:420px;">
                  <p class="description">Example: 1_ZMEyR1nG4v4lesS_R7avfJngWlVvZtI</p>
                </td>
              </tr>
              <tr>
                <th scope="row">Service Account JSON</th>
                <td>
                  <textarea name="drive_sa_json" rows="8" style="width:100%;max-width:900px;" placeholder='{"type":"service_account",...}'><?php echo esc_textarea($s['drive_sa_json']); ?></textarea>
                  <p class="description">Paste the full JSON key for a Google service account with access to the shared folder (see setup steps below).</p>
                </td>
              </tr>
              <tr>
                <th scope="row">Batch size</th>
                <td>
                  <input type="number" min="1" max="100" name="drive_batch_size" value="<?php echo esc_attr((int)$s['drive_batch_size']); ?>">
                  <p class="description">Folders processed per minute during a sync (lower = less load). Default 20.</p>
                </td>
              </tr>
              <tr>
                <th scope="row">Weekly schedule</th>
                <td>
                  <label>Hour (site time): <input type="number" min="0" max="23" name="drive_weekly_hour" value="<?php echo esc_attr((int)$s['drive_weekly_hour']); ?>"></label>
                  &nbsp; Day of week:
                  <select name="drive_weekly_dow">
                    <?php
                      $dow = (int)$s['drive_weekly_dow'];
                      $opts = [ -1=>'Any (anchor on first run)', 0=>'Sunday', 1=>'Monday', 2=>'Tuesday', 3=>'Wednesday', 4=>'Thursday', 5=>'Friday', 6=>'Saturday' ];
                      foreach ($opts as $k=>$lbl) { echo '<option value="'.esc_attr($k).'" '.selected($dow,$k,false).'>'.esc_html($lbl).'</option>'; }
                    ?>
                  </select>
                </td>
              </tr>
            </table>

            <p style="margin-top:8px;">
              <button class="button button-primary" name="ds_save" value="1">Save Settings</button>
              &nbsp;
              <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url('admin-post.php?action=ds_drive_test'), 'ds_drive_actions') ); ?>">Test Connection</a>
              &nbsp;
              <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url('admin-post.php?action=ds_drive_sync_now'), 'ds_drive_actions') ); ?>">Sync Now</a>
              &nbsp;
              <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url('admin-post.php?action=ds_drive_reset'), 'ds_drive_actions') ); ?>" onclick="return confirm('Stop the Google Drive sync and clear its state?');">Stop & Reset</a>
              &nbsp;
              <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url('admin-post.php?action=ds_drive_clean'), 'ds_drive_actions') ); ?>" onclick="return confirm('This will purge ALL Drive sync data (state, logs, locks) and unschedule tasks. Continue?');">Clean Drive Data</a>
            </p>

            <?php
            // Recent logs
            $log = get_option('ds_drive_log', []);
            if (is_array($log) && !empty($log)) {
              echo '<h3>Drive Import Logs (latest)</h3>';
              echo '<table class="widefat striped" style="max-width:1000px;"><thead><tr><th>Time</th><th>Level</th><th>Event</th><th>Context</th></tr></thead><tbody>';
              $last = array_slice($log, -50);
              foreach ($last as $row) {
                $t = !empty($row['t']) ? date_i18n('Y-m-d H:i:s', (int)$row['t']) : '';
                $lvl = esc_html($row['level'] ?? '');
                $msg = esc_html($row['msg'] ?? '');
                $ctx = esc_html( wp_json_encode($row['ctx'] ?? []) );
                echo '<tr><td>'.$t.'</td><td>'.$lvl.'</td><td>'.$msg.'</td><td><code style="white-space:pre-wrap">'.$ctx.'</code></td></tr>';
              }
              echo '</tbody></table>';
            }
            ?>

            <h3>Setup steps (Service Account)</h3>
            <ol style="max-width:900px;">
              <li>In Google Cloud Console, create a project → Enable API: <b>Google Drive API</b>.</li>
              <li>Create credentials → <b>Service account</b>. After creating, go to "Keys" → <b>Add key</b> → <b>Create new key</b> (JSON). Download it.</li>
              <li>Copy the JSON contents and paste into <b>Service Account JSON</b> above.</li>
              <li>Share your Drive root folder ID with the service account email (from the JSON), with <b>Viewer</b> access.</li>
              <li>Click <b>Save Settings</b>, then <b>Test Connection</b>. Use <b>Sync Now</b> to run immediately; weekly runs occur automagically at the configured time.</li>
            </ol>

            <h2>Moderation</h2>
            <p><label><input type="checkbox" name="posts_pending" value="1" <?php checked($s['posts_pending']); ?>> Vendor blog posts → <b>Pending</b></label></p>
            <p><label><input type="checkbox" name="products_pending" value="1" <?php checked($s['products_pending']); ?>> Vendor products → <b>Pending</b></label></p>
            <p>Vendor Admin publish reward (€): <input type="number" step="0.01" min="0" name="vendor_admin_publish_reward_eur" value="<?php echo esc_attr($s['vendor_admin_publish_reward_eur']); ?>"> <small>Paid to Vendor Admin when they publish another users pending product.</small></p>

            <h2 style="margin-top:24px;">Curated Credit</h2>
            <p><label><input type="checkbox" name="curated_credit_enable" value="1" <?php checked($s['curated_credit_enable']); ?>> Enable € credit on first publish</label></p>
            <p>Amount (€): <input type="number" step="0.01" name="curated_credit_amount" value="<?php echo esc_attr($s['curated_credit_amount']); ?>"></p>
            <p>Require min images: <input type="number" step="1" min="0" name="credit_min_images" value="<?php echo esc_attr($s['credit_min_images']); ?>"> | min words: <input type="number" step="1" min="0" name="credit_min_words" value="<?php echo esc_attr($s['credit_min_words']); ?>"> | RankMath ≥ <input type="number" step="1" min="0" max="100" name="credit_min_rankmath" value="<?php echo esc_attr($s['credit_min_rankmath']); ?>"></p>

            <h2 style="margin-top:24px;">Platform Fees</h2>
            <p>Open plan fee/item (€): <input type="number" step="0.01" name="open_fee_per_item" value="<?php echo esc_attr($s['open_fee_per_item']); ?>"></p>
            <p>Curated: first N items <input type="number" step="1" name="curated_first_n" value="<?php echo esc_attr($s['curated_first_n']); ?>"> → fee € <input type="number" step="0.01" name="curated_first_fee" value="<?php echo esc_attr($s['curated_first_fee']); ?>"> ; after → € <input type="number" step="0.01" name="curated_after_fee" value="<?php echo esc_attr($s['curated_after_fee']); ?>"></p>

            <h2 style="margin-top:24px;">Wallet / Payouts</h2>
            <p>Minimum withdrawal (€): <input type="number" step="0.01" name="withdraw_min" value="<?php echo esc_attr($s['withdraw_min']); ?>"></p>

            <h2 style="margin-top:24px;">Entitlements (Monthly Ownership)</h2>
            <p><label><input type="checkbox" name="entitlements_enable" value="1" <?php checked($s['entitlements_enable']); ?>> Enable monthly entitlements</label></p>
            <p>Fee per product below mean (€): <input type="number" step="0.01" min="0" name="entitlement_fee_eur" value="<?php echo esc_attr($s['entitlement_fee_eur']); ?>">
               &nbsp; Confirmation window (days): <input type="number" step="1" min="1" name="entitlement_confirm_days" value="<?php echo esc_attr($s['entitlement_confirm_days']); ?>">
               &nbsp; Pool User ID: <input type="number" step="1" min="1" name="pool_user_id" value="<?php echo esc_attr($s['pool_user_id']); ?>">
            </p>
            <div style="background:#fff;border:1px solid #eee;padding:10px;max-width:900px;">
              <p><label><input type="checkbox" name="entitlement_controls_payouts_ads" value="1" <?php checked($s['entitlement_controls_payouts_ads']); ?>> Entitlement governs view payouts and CPC rights (recommended)</label></p>
              <p><label><input type="checkbox" name="pause_payouts_while_pending" value="1" <?php checked($s['pause_payouts_while_pending']); ?>> Pause view payouts while entitlement is pending (still records views)</label></p>
              <p><label><input type="checkbox" name="ads_autopause_on_entitlement_loss" value="1" <?php checked($s['ads_autopause_on_entitlement_loss']); ?>> Auto‑pause CPC campaigns when entitlement is lost (pool/claimed)</label></p>
            </div>

            <h2 style="margin-top:24px;">Claim &amp; Re-claim Fees</h2>
            <div style="background:#fff;border:1px solid #eee;padding:10px;max-width:900px;">
              <p><label><input type="checkbox" name="claim_fee_enable" value="1" <?php checked($s['claim_fee_enable']); ?>> Require payment to claim pooled products (dynamic, based on average sales)</label></p>
              <p><label><input type="checkbox" name="entitlement_dynamic_fee_enable" value="1" <?php checked($s['entitlement_dynamic_fee_enable']); ?>> Use the same dynamic model for monthly underperformance fee (replaces fixed €2)</label></p>
              <p>Period:
                <select name="claim_fee_period">
                  <option value="rolling_30d" <?php selected($s['claim_fee_period'],'rolling_30d'); ?>>Rolling 30 days</option>
                  <option value="month_to_date" <?php selected($s['claim_fee_period'],'month_to_date'); ?>>Month-to-date</option>
                  <option value="previous_month" <?php selected($s['claim_fee_period'],'previous_month'); ?>>Previous month</option>
                </select>
                &nbsp; Price basis:
                <select name="claim_fee_price_basis">
                  <option value="current" <?php selected($s['claim_fee_price_basis'],'current'); ?>>Current price</option>
                  <option value="regular" <?php selected($s['claim_fee_price_basis'],'regular'); ?>>Regular price</option>
                  <option value="sale" <?php selected($s['claim_fee_price_basis'],'sale'); ?>>Sale price</option>
                </select>
              </p>
              <p>Denominator scope:
                <select name="claim_fee_denominator_scope">
                  <option value="published" <?php selected($s['claim_fee_denominator_scope'],'published'); ?>>Published only</option>
                  <option value="published_private" <?php selected($s['claim_fee_denominator_scope'],'published_private'); ?>>Published + Private</option>
                  <option value="all" <?php selected($s['claim_fee_denominator_scope'],'all'); ?>>All (incl. pending/draft)</option>
                </select>
              </p>
              <?php $st_csv = is_array($s['claim_fee_statuses']) ? implode(', ', array_map('sanitize_text_field',$s['claim_fee_statuses'])) : (string)$s['claim_fee_statuses']; ?>
              <p>Count orders with statuses (CSV, e.g., processing, completed): <input type="text" name="claim_fee_statuses" value="<?php echo esc_attr($st_csv); ?>" style="width:360px;"></p>
              <p>Min percent: <input type="number" step="0.01" min="0" max="100" name="claim_fee_min_pct" value="<?php echo esc_attr($s['claim_fee_min_pct']); ?>">
                 &nbsp; Max percent: <input type="number" step="0.01" min="0" max="100" name="claim_fee_max_pct" value="<?php echo esc_attr($s['claim_fee_max_pct']); ?>">
                 &nbsp; Min fee (€): <input type="number" step="0.01" min="0" name="claim_fee_min_eur" value="<?php echo esc_attr($s['claim_fee_min_eur']); ?>">
                 &nbsp; Cache (min): <input type="number" step="1" min="1" name="claim_fee_cache_minutes" value="<?php echo esc_attr($s['claim_fee_cache_minutes']); ?>">
              </p>
            </div>

            <h2 style="margin-top:24px;">View Payouts</h2>
            <p><label><input type="checkbox" name="enable_view_payouts" value="1" <?php checked($s['enable_view_payouts']); ?>> Enable paying per unique view (EUR)</label></p>
            <p>Rate per paid view (€): <input type="number" step="0.0001" min="0" name="view_payout_rate_eur" value="<?php echo esc_attr($s['view_payout_rate_eur']); ?>"> <small>Paid once per viewer per product per day</small></p>

            <h3>Caps — Counts (0 = disabled)</h3>
            <table class="widefat striped" style="max-width:1000px;">
              <tbody>
                <tr><td style="width:380px;">Per IP per Product per Day</td><td><input type="number" step="1" min="0" name="view_cap_per_ip_per_product_per_day" value="<?php echo esc_attr($s['view_cap_per_ip_per_product_per_day']); ?>"></td></tr>
                <tr><td>Per IP per Day (sitewide)</td><td><input type="number" step="1" min="0" name="view_cap_per_ip_per_day_sitewide" value="<?php echo esc_attr($s['view_cap_per_ip_per_day_sitewide']); ?>"></td></tr>
                <tr><td>Per Viewer per Day (sitewide)</td><td><input type="number" step="1" min="0" name="view_cap_per_viewer_per_day_sitewide" value="<?php echo esc_attr($s['view_cap_per_viewer_per_day_sitewide']); ?>"></td></tr>
                <tr><td>Per Product per Day</td><td><input type="number" step="1" min="0" name="view_cap_per_product_per_day" value="<?php echo esc_attr($s['view_cap_per_product_per_day']); ?>"></td></tr>
                <tr><td>Per Vendor per Day</td><td><input type="number" step="1" min="0" name="view_cap_per_vendor_per_day" value="<?php echo esc_attr($s['view_cap_per_vendor_per_day']); ?>"></td></tr>
                <tr><td>Per Vendor per Month</td><td><input type="number" step="1" min="0" name="view_cap_per_vendor_per_month" value="<?php echo esc_attr($s['view_cap_per_vendor_per_month']); ?>"></td></tr>
                <tr><td>Sitewide per Day</td><td><input type="number" step="1" min="0" name="view_cap_sitewide_per_day" value="<?php echo esc_attr($s['view_cap_sitewide_per_day']); ?>"></td></tr>
                <tr><td>Sitewide per Month</td><td><input type="number" step="1" min="0" name="view_cap_sitewide_per_month" value="<?php echo esc_attr($s['view_cap_sitewide_per_month']); ?>"></td></tr>
              </tbody>
            </table>

            <h3>Caps — Payout (€)</h3>
            <table class="widefat striped" style="max-width:1000px;">
              <tbody>
                <tr><td style="width:380px;">Per Vendor per Day (€)</td><td><input type="number" step="0.01" min="0" name="payout_cap_per_vendor_per_day_eur" value="<?php echo esc_attr($s['payout_cap_per_vendor_per_day_eur']); ?>"></td></tr>
                <tr><td>Per Vendor per Month (€)</td><td><input type="number" step="0.01" min="0" name="payout_cap_per_vendor_per_month_eur" value="<?php echo esc_attr($s['payout_cap_per_vendor_per_month_eur']); ?>"></td></tr>
                <tr><td>Sitewide per Day (€)</td><td><input type="number" step="0.01" min="0" name="payout_cap_sitewide_per_day_eur" value="<?php echo esc_attr($s['payout_cap_sitewide_per_day_eur']); ?>"></td></tr>
                <tr><td>Sitewide per Month (€)</td><td><input type="number" step="0.01" min="0" name="payout_cap_sitewide_per_month_eur" value="<?php echo esc_attr($s['payout_cap_sitewide_per_month_eur']); ?>"></td></tr>
              </tbody>
            </table>

            <h3>Bot and Exclusions</h3>
            <p><label><input type="checkbox" name="view_record_bots" value="1" <?php checked($s['view_record_bots']); ?>> Record bot views (for analytics)</label>
               &nbsp; <label><input type="checkbox" name="view_pay_for_bots" value="1" <?php checked($s['view_pay_for_bots']); ?>> Pay for bots (not recommended)</label></p>
            <?php $ua_csv = is_array($s['view_ua_denylist']) ? implode(', ', $s['view_ua_denylist']) : (string)$s['view_ua_denylist']; ?>
            <p>UA denylist (comma separated): <input type="text" name="view_ua_denylist" style="width:100%;max-width:780px;" value="<?php echo esc_attr($ua_csv); ?>"></p>
            <?php $ex_v_csv = is_array($s['view_excluded_vendors']) ? implode(',', array_map('intval',$s['view_excluded_vendors'])) : (string)$s['view_excluded_vendors']; ?>
            <?php $ex_p_csv = is_array($s['view_excluded_products']) ? implode(',', array_map('intval',$s['view_excluded_products'])) : (string)$s['view_excluded_products']; ?>
            <p>Exclude Vendor IDs (CSV): <input type="text" name="view_excluded_vendors" value="<?php echo esc_attr($ex_v_csv); ?>" style="width:300px;"> &nbsp; Exclude Product IDs (CSV): <input type="text" name="view_excluded_products" value="<?php echo esc_attr($ex_p_csv); ?>" style="width:300px;"></p>

            <h2 style="margin-top:24px;">Vendor Protections</h2>
            <p><label><input type="checkbox" name="protect_status_demotion" value="1" <?php checked($s['protect_status_demotion']); ?>> Prevent vendors from demoting published products (publish → draft/private)</label></p>
            <p><label><input type="checkbox" name="require_featured_image" value="1" <?php checked($s['require_featured_image']); ?>> Require featured image</label>
               &nbsp; <label><input type="checkbox" name="require_product_category" value="1" <?php checked($s['require_product_category']); ?>> Require at least one category</label></p>
            <p>Minimum title length: <input type="number" step="1" min="0" name="min_title_len" value="<?php echo esc_attr($s['min_title_len']); ?>"> &nbsp; Minimum content length: <input type="number" step="1" min="0" name="min_content_len" value="<?php echo esc_attr($s['min_content_len']); ?>"></p>

            <h2 style="margin-top:24px;">Snapshots (Backup & Restore)</h2>
            <p>Retention (days): <input type="number" step="1" min="1" name="snapshot_retention_days" value="<?php echo esc_attr($s['snapshot_retention_days']); ?>"> <small>Older snapshots are removed daily.</small></p>

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

    static function entitlements_manager() {
        if (!current_user_can('manage_options')) wp_die('No access.');
        $q = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
        $paged = max(1, intval($_GET['paged'] ?? 1));
        $per_page = 25;
        $args = [
            'post_type' => 'product',
            'posts_per_page' => $per_page,
            'paged' => $paged,
            'post_status' => ['publish','private','pending','draft','future'],
            'orderby' => 'date',
            'order' => 'DESC',
        ];
        if ($q !== '') { $args['s'] = $q; }
        $query = new WP_Query($args);
        $posts = $query->posts;
        $total = (int)$query->found_posts;
        $pages = max(1, (int)ceil($total / $per_page));

        echo '<div class="wrap">';
        echo '<h1>Entitlements Manager</h1>';
        echo '<form method="get" style="margin:12px 0;">';
        echo '<input type="hidden" name="page" value="ds-entitlements-manager">';
        echo '<input type="text" name="q" value="'.esc_attr($q).'" placeholder="Search products by title/keyword" style="width:320px;"> ';
        echo '<button class="button">Search</button>';
        echo '</form>';

        if (isset($_GET['bulk_done'])) {
            $msg = sprintf('Bulk "%s" done for %d item(s).', esc_html($_GET['bulk_done']), intval($_GET['bulk_count'] ?? 0));
            echo '<div class="notice notice-success"><p>'.esc_html($msg).'</p></div>';
        }

        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Product</th><th>Holder</th><th>Pool</th><th>Last Month</th><th>Actions</th></tr></thead><tbody>';
        if ($posts) {
            global $wpdb; $t = class_exists('DS_Entitlements') ? DS_Entitlements::table() : '';
            $prev_month = date('Y-m', strtotime('-1 month', current_time('timestamp')));
            foreach ($posts as $p) {
                $pid = (int)$p->ID;
                $title = get_the_title($pid) ?: ('#'.$pid);
                $holder = class_exists('DS_Entitlements') ? (int)DS_Entitlements::current_holder($pid) : (int)$p->post_author;
                $holder_name = $holder ? ( ($u=get_userdata($holder)) ? $u->display_name : ('#'.$holder) ) : '-';
                $override = (int) get_post_meta($pid, '_ds_entitlement_override_user', true);
                $in_pool = (int) get_post_meta($pid, '_ds_pool', true) === 1;
                $row = $t ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE product_id=%d AND month=%s", $pid, $prev_month)) : null;
                $lm = $row ? sprintf('v:%d / mean:%0.2f / fee:€%0.2f / %s', (int)$row->views, (float)$row->mean_views, (float)$row->amount_due, esc_html($row->status)) : '—';

                echo '<tr>';
                echo '<td>'.(int)$pid.'</td>';
                echo '<td><a href="'.esc_url(get_edit_post_link($pid)).'">'.esc_html($title).'</a></td>';
                echo '<td>'.esc_html($holder_name).($override?' <span style="color:#d63638;">(override)</span>':'').'</td>';
                echo '<td>'.($in_pool?'<span style="color:#d63638;">Yes</span>':'No').'</td>';
                echo '<td>'.esc_html($lm).'</td>';
                echo '<td>';
                // Info: current claim fee
                if (class_exists('DS_Entitlements') && method_exists('DS_Entitlements','claim_fee_breakdown')) {
                    $bd = DS_Entitlements::claim_fee_breakdown($pid);
                    $fee_t = '€'.number_format((float)($bd['amount'] ?? 0),2).' ('.number_format((float)($bd['percent'] ?? 0),2).'%)';
                    echo '<div style="color:#666;margin-bottom:6px;">Claim fee now: '.esc_html($fee_t).'</div>';
                }
                // Override set
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin-right:6px;">';
                echo '<input type="hidden" name="action" value="ds_entitlement_override_set">';
                echo '<input type="hidden" name="product_id" value="'.(int)$pid.'">';
                echo wp_nonce_field('ds_ent_override_'.$pid, '_wpnonce', true, false);
                echo '<input type="number" name="user_id" min="1" placeholder="User ID" style="width:100px;"> ';
                echo '<button class="button">Set</button>';
                echo '</form>';
                // Clear override
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin-right:6px;">';
                echo '<input type="hidden" name="action" value="ds_entitlement_override_clear">';
                echo '<input type="hidden" name="product_id" value="'.(int)$pid.'">';
                echo wp_nonce_field('ds_ent_override_'.$pid, '_wpnonce', true, false);
                echo '<button class="button">Clear</button>';
                echo '</form>';
                // Pool send/remove
                if (!$in_pool) {
                    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin-right:6px;">';
                    echo '<input type="hidden" name="action" value="ds_pool_send">';
                    echo '<input type="hidden" name="product_id" value="'.(int)$pid.'">';
                    echo wp_nonce_field('ds_pool_'.$pid, '_wpnonce', true, false);
                    echo '<button class="button">Send to Pool</button>';
                    echo '</form>';
                } else {
                    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin-right:6px;">';
                    echo '<input type="hidden" name="action" value="ds_pool_remove">';
                    echo '<input type="hidden" name="product_id" value="'.(int)$pid.'">';
                    echo wp_nonce_field('ds_pool_'.$pid, '_wpnonce', true, false);
                    echo '<input type="number" name="user_id" min="1" placeholder="Restore User ID" style="width:120px;"> ';
                    echo '<button class="button">Remove from Pool</button>';
                    echo '</form>';
                }
                echo '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="6">No products found.</td></tr>';
        }
        echo '</tbody></table>';

        // Pagination
        if ($pages > 1) {
            echo '<p style="margin:12px 0;">';
            for ($i=1;$i<=$pages;$i++){
                $u = add_query_arg(['page'=>'ds-entitlements-manager','q'=>$q,'paged'=>$i], admin_url('admin.php'));
                echo $i===$paged ? '<span class="button button-primary" style="margin-right:6px;">'.$i.'</span>' : '<a class="button" style="margin-right:6px;" href="'.esc_url($u).'">'.$i.'</a>';
            }
            echo '</p>';
        }

        echo '<h2 style="margin-top:24px;">Bulk tools</h2>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="max-width:820px;background:#fff;border:1px solid #eee;padding:12px;">';
        echo wp_nonce_field('ds_ent_bulk', '_wpnonce', true, false);
        echo '<input type="hidden" name="action" value="ds_entitlements_bulk">';
        echo '<p><select name="bulk_action">';
        echo '<option value="set_override">Set override holder</option>';
        echo '<option value="clear_override">Clear override</option>';
        echo '<option value="send_pool">Send to Pool</option>';
        echo '<option value="remove_pool">Remove from Pool</option>';
        echo '</select> &nbsp; Target User ID (for set/remove): <input type="number" name="user_id" min="1" style="width:120px;"></p>';
        echo '<p>Product IDs (comma or space separated):<br><textarea name="product_ids" rows="3" style="width:100%;"></textarea></p>';
        echo '<p><button class="button button-primary">Run</button></p>';
        echo '</form>';

        echo '</div>';
    }

    static function dashboard() {
        if (!current_user_can('manage_options')) wp_die('No access.');
        $preset = isset($_GET['preset']) ? sanitize_key($_GET['preset']) : 'weekly';
        $from = isset($_GET['from']) ? sanitize_text_field($_GET['from']) : '';
        $to   = isset($_GET['to']) ? sanitize_text_field($_GET['to']) : '';
        list($start, $end) = DS_Leaderboard::get_period($preset, $from, $to);
        $from_d = substr($start,0,10); $to_d = substr($end,0,10);

        global $wpdb;
        $vt = class_exists('DS_Views') ? DS_Views::table() : '';
        $wt = DS_Wallet::table();

        // KPIs
        $total_views = $vt ? (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$vt} WHERE view_date BETWEEN %s AND %s", $from_d, $to_d)) : 0;
        $total_view_payouts = (float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM {$wt} WHERE type='view_reward' AND status IN ('posted','paid') AND created_at BETWEEN %s AND %s", $start, $end));
        $total_earned = (float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(CASE WHEN amount>0 THEN amount ELSE 0 END),0) FROM {$wt} WHERE status IN ('posted','paid') AND created_at BETWEEN %s AND %s", $start, $end));
        $total_spent  = (float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(CASE WHEN amount<0 THEN -amount ELSE 0 END),0) FROM {$wt} WHERE status IN ('posted','paid') AND created_at BETWEEN %s AND %s", $start, $end));
        $new_products = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status IN ('publish','private','draft','pending','future') AND post_date BETWEEN %s AND %s", $start, $end));

        echo '<div class="wrap">';
        echo '<h1>Dropship Dashboard</h1>';
        echo '<form method="get" style="margin:12px 0;">';
        echo '<input type="hidden" name="page" value="ds-dashboard">';
        echo '<label>Period: <select name="preset">';
        foreach (['daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly','custom'=>'Custom'] as $k=>$lab) {
            printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($preset,$k,false), esc_html($lab));
        }
        echo '</select></label> ';
        echo '<label>From: <input type="date" name="from" value="'.esc_attr($from_d).'"></label> ';
        echo '<label>To: <input type="date" name="to" value="'.esc_attr($to_d).'"></label> ';
        echo '<button class="button">Apply</button>';
        echo '</form>';

        echo '<div class="card" style="padding:12px;max-width:1100px;background:#fff;border:1px solid #e5e5e5;">';
        printf('<p><b>Total Views:</b> %d &nbsp; | &nbsp; <b>Total View Payouts:</b> €%0.2f &nbsp; | &nbsp; <b>Total Earnings (+):</b> €%0.2f &nbsp; | &nbsp; <b>Total Spend (−):</b> €%0.2f &nbsp; | &nbsp; <b>New Products:</b> %d</p>', $total_views, $total_view_payouts, $total_earned, $total_spent, $new_products);
        echo '</div>';

        // Top Vendors by Views
        if ($vt) {
            $top_vendors = $wpdb->get_results($wpdb->prepare("SELECT vendor_id AS user_id, COUNT(*) AS views FROM {$vt} WHERE view_date BETWEEN %s AND %s GROUP BY vendor_id ORDER BY views DESC LIMIT 10", $from_d, $to_d));
            echo '<h2 style="margin-top:16px;">Top Vendors by Views</h2><table class="widefat striped"><thead><tr><th>User</th><th>Views</th></tr></thead><tbody>';
            if ($top_vendors) {
                foreach ($top_vendors as $r) {
                    $u = get_user_by('id', (int)$r->user_id);
                    printf('<tr><td>%s</td><td>%d</td></tr>', $u ? esc_html($u->display_name.' (@'.$u->user_login.')') : ('#'.(int)$r->user_id), (int)$r->views);
                }
            } else echo '<tr><td colspan="2">—</td></tr>';
            echo '</tbody></table>';
        }

        // Top Vendors by View Earnings
        $top_ve = $wpdb->get_results($wpdb->prepare("SELECT user_id, SUM(amount) AS euros FROM {$wt} WHERE type='view_reward' AND status IN ('posted','paid') AND created_at BETWEEN %s AND %s GROUP BY user_id ORDER BY euros DESC LIMIT 10", $start, $end));
        echo '<h2 style="margin-top:16px;">Top Vendors by View Earnings</h2><table class="widefat striped"><thead><tr><th>User</th><th>€</th></tr></thead><tbody>';
        if ($top_ve) {
            foreach ($top_ve as $r) {
                $u = get_user_by('id', (int)$r->user_id);
                printf('<tr><td>%s</td><td>€%0.2f</td></tr>', $u ? esc_html($u->display_name.' (@'.$u->user_login.')') : ('#'.(int)$r->user_id), (float)$r->euros);
            }
        } else echo '<tr><td colspan="2">—</td></tr>';
        echo '</tbody></table>';

        // Top Products by Views
        if ($vt) {
            $top_products = $wpdb->get_results($wpdb->prepare("SELECT product_id, COUNT(*) AS views FROM {$vt} WHERE view_date BETWEEN %s AND %s GROUP BY product_id ORDER BY views DESC LIMIT 10", $from_d, $to_d));
            echo '<h2 style="margin-top:16px;">Top Products by Views</h2><table class="widefat striped"><thead><tr><th>Product</th><th>Views</th></tr></thead><tbody>';
            if ($top_products) {
                foreach ($top_products as $r) {
                    $title = get_the_title((int)$r->product_id) ?: ('#'.(int)$r->product_id);
                    printf('<tr><td>%s</td><td>%d</td></tr>', esc_html($title), (int)$r->views);
                }
            } else echo '<tr><td colspan="2">—</td></tr>';
            echo '</tbody></table>';
        }

        // Latest view payouts
        $latest = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wt} WHERE type='view_reward' AND status IN ('posted','paid') AND created_at BETWEEN %s AND %s ORDER BY id DESC LIMIT 25", $start, $end));
        echo '<h2 style="margin-top:16px;">Latest View Reward Payouts</h2><table class="widefat striped"><thead><tr><th>ID</th><th>User</th><th>€</th><th>Ref</th><th>Created</th></tr></thead><tbody>';
        if ($latest) {
            foreach ($latest as $r) {
                $u = get_user_by('id', (int)$r->user_id);
                printf('<tr><td>%d</td><td>%s</td><td>€%0.4f</td><td>%s</td><td>%s</td></tr>', (int)$r->id, $u ? esc_html($u->user_login) : ('#'.(int)$r->user_id), (float)$r->amount, esc_html($r->ref_id), esc_html($r->created_at));
            }
        } else echo '<tr><td colspan="5">—</td></tr>';
        echo '</tbody></table>';

        echo '</div>';
    }
}