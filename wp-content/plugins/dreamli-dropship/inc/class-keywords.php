<?php
if (!defined('ABSPATH')) exit;

/**
 * DataForSEO-driven keyword suggestions, intent, volume, FAQ (PAA) per WooCommerce category and Polylang language.
 * Ultra-tight batching to reduce costs:
 * - Ideas:  up to 20 seeds per async task → keywords_data/google_ads/keywords_for_keywords/task_post
 * - Volume: up to 1000 keywords per async task → keywords_data/google_ads/search_volume/task_post
 * - Intent: dataforseo_labs/google/search_intent/live (batched, no polling)
 * - PAA:    serp/google/organic (Advanced) async; only 1 seed per category by default
 */
final class DS_Keywords {
    // Schedules & hooks
    const CRON_REFRESH = 'ds_keywords_refresh_biweekly';
    const CRON_POLL    = 'ds_keywords_poll';
    // Action Scheduler hooks
    const AS_REFRESH   = 'ds_keywords_refresh_as';
    const AS_POLL      = 'ds_keywords_poll_as';

    // Options
    const OPT_LOGIN  = 'ds_dfs_login';
    const OPT_PASS   = 'ds_dfs_password';
    const OPT_LANGMAP = 'ds_dfs_lang_map_json'; // {"en":{"language_code":"en","location_code":2840}, ...}
    const OPT_LIMITS  = 'ds_dfs_limits_json';   // caps & toggles JSON
    const OPT_LOG_ENABLED   = 'ds_dfs_log_enabled'; // yes|no
    const OPT_LOG_RETENTION = 'ds_dfs_log_retention_days'; // int days

    // Tables
    public static function table_keywords(){ global $wpdb; return $wpdb->prefix.'ds_keywords'; }
    public static function table_faq()      { global $wpdb; return $wpdb->prefix.'ds_keyword_faq'; }
    public static function table_queue()    { global $wpdb; return $wpdb->prefix.'ds_dfs_queue'; }
    public static function table_ads_forecast(){ global $wpdb; return $wpdb->prefix.'ds_keyword_ads_forecast'; }
    public static function table_logs()     { global $wpdb; return $wpdb->prefix.'ds_keywords_logs'; }

    public static function init(){
        // Admin settings
        add_action('admin_menu', [__CLASS__,'admin_menu']);
        add_action('admin_init', [__CLASS__,'register_settings']);
        add_action('admin_init', [__CLASS__,'handle_admin_actions']);
        add_action('admin_init', [__CLASS__,'purge_logs_maybe']);

        // Term fields: related seeds per category & per language
        add_action('product_cat_add_form_fields', [__CLASS__,'cat_add_fields']);
        add_action('product_cat_edit_form_fields', [__CLASS__,'cat_edit_fields'], 10, 2);
        add_action('created_product_cat', [__CLASS__,'cat_save_fields']);
        add_action('edited_product_cat',  [__CLASS__,'cat_save_fields']);

        // Product metabox UI
        add_action('add_meta_boxes_product', [__CLASS__,'add_metabox']);

        // Cron schedules and handlers
        add_filter('cron_schedules', [__CLASS__,'add_schedules']);
        add_action(self::CRON_REFRESH, [__CLASS__,'cron_refresh']);
        add_action(self::CRON_POLL,    [__CLASS__,'cron_poll']);

        // Action Scheduler handlers (if available)
        if (function_exists('as_schedule_recurring_action')) {
            add_action(self::AS_REFRESH, [__CLASS__,'cron_refresh']);
            add_action(self::AS_POLL,    [__CLASS__,'cron_poll']);
            add_action('action_scheduler_init', [__CLASS__, 'schedule_as_events'], 20);
            add_action('init', [__CLASS__, 'schedule_as_events'], 20);
        }

        // Ensure polling is scheduled (every 5 min) when Action Scheduler is not available
        if (!function_exists('as_schedule_recurring_action')) {
            if (!wp_next_scheduled(self::CRON_POLL)) {
                wp_schedule_event(time()+300, 'five_minutes', self::CRON_POLL);
            }
        }
    }

    public static function install(){
        global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        // Suggestions table
        $sql1 = "CREATE TABLE ".self::table_keywords()." (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            term_id BIGINT(20) UNSIGNED NOT NULL,
            lang VARCHAR(12) NOT NULL,
            keyword VARCHAR(255) NOT NULL,
            source VARCHAR(32) NOT NULL DEFAULT 'ideas',
            intent_main VARCHAR(32) NULL,
            intent_probs TEXT NULL,
            volume INT NULL,
            cpc DECIMAL(10,4) NULL,
            competition DECIMAL(6,4) NULL,
            top_of_page_bid_low DECIMAL(10,4) NULL,
            top_of_page_bid_high DECIMAL(10,4) NULL,
            serp_features TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY term_lang_kw (term_id, lang, keyword(191)),
            KEY term_lang (term_id, lang),
            KEY lang (lang)
        ) $charset;";
        dbDelta($sql1);

        // FAQ table
        $sql2 = "CREATE TABLE ".self::table_faq()." (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            term_id BIGINT(20) UNSIGNED NOT NULL,
            lang VARCHAR(12) NOT NULL,
            question VARCHAR(512) NOT NULL,
            answer TEXT NULL,
            source VARCHAR(16) NOT NULL DEFAULT 'paa',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY term_lang (term_id, lang)
        ) $charset;";
        dbDelta($sql2);

        // Queue (posted tasks to DataForSEO)
        $sql3 = "CREATE TABLE ".self::table_queue()." (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            endpoint VARCHAR(128) NOT NULL,
            task_id VARCHAR(64) NOT NULL,
            tag VARCHAR(255) NULL,
            term_id BIGINT(20) UNSIGNED NULL,
            lang VARCHAR(12) NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'posted',
            payload_json LONGTEXT NULL,
            result_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY endpoint_task (endpoint, task_id),
            KEY status_idx (status),
            KEY term_lang (term_id, lang)
        ) $charset;";
        dbDelta($sql3);

        // Ads forecasts table
        $sql4 = "CREATE TABLE ".self::table_ads_forecast()." (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            term_id BIGINT(20) UNSIGNED NOT NULL,
            lang VARCHAR(12) NOT NULL,
            keyword VARCHAR(255) NOT NULL,
            clicks DECIMAL(12,4) NULL,
            impressions DECIMAL(12,2) NULL,
            cost DECIMAL(12,4) NULL,
            cpc DECIMAL(12,4) NULL,
            ctr DECIMAL(8,4) NULL,
            position DECIMAL(6,2) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY term_lang_kw (term_id, lang, keyword(191)),
            KEY term_lang (term_id, lang)
        ) $charset;";
        dbDelta($sql4);

        // Logs table
        $sql5 = "CREATE TABLE ".self::table_logs()." (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            ts DATETIME NOT NULL,
            level VARCHAR(16) NOT NULL,
            endpoint VARCHAR(160) NULL,
            action VARCHAR(40) NULL,
            term_id BIGINT(20) UNSIGNED NULL,
            lang VARCHAR(12) NULL,
            task_id VARCHAR(64) NULL,
            http_code INT NULL,
            error TEXT NULL,
            message TEXT NULL,
            request_json_snippet TEXT NULL,
            response_json_snippet TEXT NULL,
            run_tag VARCHAR(191) NULL,
            PRIMARY KEY (id),
            KEY ts_idx (ts),
            KEY level_idx (level),
            KEY endpoint_idx (endpoint(120)),
            KEY term_lang (term_id, lang),
            KEY task_idx (task_id)
        ) $charset;";
        dbDelta($sql5);

        // Schedules
        $sch = wp_get_schedules();
        if (!isset($sch['biweekly'])) {
            add_filter('cron_schedules', function($s){ $s['biweekly']=['interval'=>14*DAY_IN_SECONDS,'display'=>'Every 2 Weeks']; return $s; });
        }
        if (!wp_next_scheduled(self::CRON_REFRESH)) {
            wp_schedule_event(time()+600, 'biweekly', self::CRON_REFRESH);
        }
        $sch = wp_get_schedules();
        if (!isset($sch['five_minutes'])) {
            add_filter('cron_schedules', function($s){ $s['five_minutes']=['interval'=>5*MINUTE_IN_SECONDS,'display'=>'Every 5 Minutes']; return $s; });
        }
        if (!wp_next_scheduled(self::CRON_POLL)) {
            wp_schedule_event(time()+300, 'five_minutes', self::CRON_POLL);
        }
    }

    // ----- Admin -----
    public static function admin_menu(){
        add_submenu_page('ds-root', 'Keywords (DataForSEO)', 'Keywords', 'manage_options', 'ds-keywords', [__CLASS__,'render_settings']);
        add_submenu_page('ds-root', 'Keywords Logs', 'Keywords Logs', 'manage_options', 'ds-keywords-logs', [__CLASS__,'render_logs']);
    }
    public static function register_settings(){
        register_setting('ds_keywords', self::OPT_LOGIN,  ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('ds_keywords', self::OPT_PASS,   ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
        register_setting('ds_keywords', self::OPT_LANGMAP,['type'=>'string','sanitize_callback'=>'wp_kses_post']);
        register_setting('ds_keywords', self::OPT_LIMITS, ['type'=>'string','sanitize_callback'=>'wp_kses_post', 'default'=>wp_json_encode([
                'max_ideas_per_cat'=>300,       // cap per category (after API returns)
                'max_ads_per_cat'=>50,
                'min_ads_volume'=>50,
                'expansion_enable'=>true,       // related_keywords toggle
                'forecast_enable'=>false,
                'budget_cap_credits'=>1000,
                'max_cats_per_run'=>50,
                'max_tasks_per_run'=>1000,
            // Tightening knobs:
                'ideas_seeds_per_task'=>20,     // max 20 per DFSEO docs
                'volume_keywords_per_task'=>1000, // max 1000 per DFSEO docs
                'paa_max_seeds_per_cat'=>1      // keep PAA cheap
        ])]);
        // Logging
        register_setting('ds_keywords', self::OPT_LOG_ENABLED,  ['type'=>'string','sanitize_callback'=>'sanitize_text_field','default'=>'yes']);
        register_setting('ds_keywords', self::OPT_LOG_RETENTION,['type'=>'integer','sanitize_callback'=>'absint','default'=>14]);
    }
    public static function render_settings(){
        if (!current_user_can('manage_options')) return;
        $langmap = get_option(self::OPT_LANGMAP, '');
        $limits  = get_option(self::OPT_LIMITS, '');
        $log_on  = get_option(self::OPT_LOG_ENABLED,'yes');
        $ret     = (int) get_option(self::OPT_LOG_RETENTION,14);
        ?>
        <div class="wrap">
            <h1>Keyword Suggestions (DataForSEO)</h1>
            <form method="post" action="options.php">
                <?php settings_fields('ds_keywords'); ?>
                <?php do_settings_sections('ds_keywords'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th>DataForSEO Login</th>
                        <td><input type="text" name="<?php echo esc_attr(self::OPT_LOGIN); ?>" value="<?php echo esc_attr(get_option(self::OPT_LOGIN,'')); ?>" style="width:320px;"></td>
                    </tr>
                    <tr>
                        <th>DataForSEO Password</th>
                        <td><input type="password" name="<?php echo esc_attr(self::OPT_PASS); ?>" value="<?php echo esc_attr(get_option(self::OPT_PASS,'')); ?>" style="width:320px;"></td>
                    </tr>
                    <tr>
                        <th>Language → Market map (JSON)</th>
                        <td>
                            <textarea name="<?php echo esc_attr(self::OPT_LANGMAP); ?>" rows="8" cols="80" placeholder='{"en":{"language_code":"en","location_code":2840},"fr":{"language_code":"fr","location_code":2250}}'><?php echo esc_textarea($langmap); ?></textarea>
                            <p class="description">Map Polylang language slugs to DataForSEO language_code and location_code.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Limits & Thresholds (JSON)</th>
                        <td>
                            <textarea name="<?php echo esc_attr(self::OPT_LIMITS); ?>" rows="8" cols="80"><?php echo esc_textarea($limits); ?></textarea>
                            <p class="description">Caps & batching settings for lowest cost.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Verbose logging</th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPT_LOG_ENABLED); ?>" value="yes" <?php checked($log_on,'yes');?>> Enable DB logs</label>
                        </td>
                    </tr>
                    <tr>
                        <th>Log retention (days)</th>
                        <td><input type="number" min="1" max="180" name="<?php echo esc_attr(self::OPT_LOG_RETENTION); ?>" value="<?php echo esc_attr($ret); ?>" style="width:100px;"></td>
                    </tr>
                </table>
                <?php submit_button('Save Keywords Settings'); ?>
            </form>
            <hr>
            <h2>Manual run</h2>
            <p>
                <?php $nonce_all = wp_create_nonce('ds_kw_refresh_all'); ?>
                <a class="button button-primary" href="<?php echo esc_url( add_query_arg(['page'=>'ds-keywords','ds_kw_action'=>'refresh_all','mode'=>'stale','_wpnonce'=>$nonce_all], admin_url('admin.php')) ); ?>">Run now for all categories (only stale)</a>
                <a class="button" style="margin-left:6px" href="<?php echo esc_url( add_query_arg(['page'=>'ds-keywords','ds_kw_action'=>'refresh_all','mode'=>'force','_wpnonce'=>$nonce_all], admin_url('admin.php')) ); ?>">Run now for all categories (force)</a>
                <a class="button" style="margin-left:6px" href="<?php echo esc_url( admin_url('admin.php?page=ds-keywords-logs') ); ?>">Open Logs</a>
            </p>
        </div>
        <?php
    }

    public static function render_logs(){
        if (!current_user_can('manage_options')) return;
        global $wpdb; $t = self::table_logs();
        // Filters
        $level = isset($_GET['level']) ? sanitize_text_field($_GET['level']) : '';
        $endpoint = isset($_GET['endpoint']) ? sanitize_text_field($_GET['endpoint']) : '';
        $term_id = isset($_GET['term_id']) ? absint($_GET['term_id']) : 0;
        $lang = isset($_GET['lang']) ? sanitize_text_field($_GET['lang']) : '';
        $task_id = isset($_GET['task_id']) ? sanitize_text_field($_GET['task_id']) : '';
        $q = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
        $paged = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
        $per_page = 50; $offset = ($paged-1)*$per_page;

        $where = 'WHERE 1=1'; $params = [];
        if ($level){ $where .= ' AND level=%s'; $params[]=$level; }
        if ($endpoint){ $where .= ' AND endpoint LIKE %s'; $params[]='%'.$wpdb->esc_like($endpoint).'%'; }
        if ($term_id){ $where .= ' AND term_id=%d'; $params[]=$term_id; }
        if ($lang){ $where .= ' AND lang=%s'; $params[]=$lang; }
        if ($task_id){ $where .= ' AND task_id=%s'; $params[]=$task_id; }
        if ($q){ $like = '%'.$wpdb->esc_like($q).'%'; $where .= " AND (message LIKE %s OR error LIKE %s OR request_json_snippet LIKE %s OR response_json_snippet LIKE %s)"; array_push($params,$like,$like,$like,$like); }

        $sql_count = "SELECT COUNT(*) FROM {$t} {$where}";
        if (!empty($params)) $total = (int) $wpdb->get_var($wpdb->prepare($sql_count, $params));
        else $total = (int) $wpdb->get_var($sql_count);

        $sql = "SELECT * FROM {$t} {$where} ORDER BY ts DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($params, [$per_page,$offset])), ARRAY_A);

        $base_url = admin_url('admin.php?page=ds-keywords-logs');
        ?>
        <div class="wrap">
            <h1>Keywords Logs</h1>
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin-bottom:10px">
                <input type="hidden" name="page" value="ds-keywords-logs">
                Level: <select name="level">
                    <option value="">(any)</option>
                    <?php foreach (['info','warn','error'] as $lv){ echo '<option value="'.esc_attr($lv).'" '.selected($level,$lv,false).'>'.esc_html($lv).'</option>'; } ?>
                </select>
                Endpoint: <input type="text" name="endpoint" value="<?php echo esc_attr($endpoint); ?>" style="width:220px">
                Term ID: <input type="number" name="term_id" value="<?php echo esc_attr($term_id); ?>" style="width:100px">
                Lang: <input type="text" name="lang" value="<?php echo esc_attr($lang); ?>" style="width:80px">
                Task ID: <input type="text" name="task_id" value="<?php echo esc_attr($task_id); ?>" style="width:160px">
                Search: <input type="text" name="q" value="<?php echo esc_attr($q); ?>" style="width:220px">
                <button class="button">Filter</button>
                <?php $nonce = wp_create_nonce('ds_kw_clear_logs'); ?>
                <a class="button" href="<?php echo esc_url( add_query_arg(['ds_kw_action'=>'clear_logs','_wpnonce'=>$nonce], $base_url) ); ?>" onclick="return confirm('Clear all logs?')">Clear All</a>
            </form>
            <p><?php echo number_format_i18n($total); ?> log entries found.</p>
            <table class="widefat fixed striped">
                <thead><tr>
                    <th style="width:150px">Time</th>
                    <th>Level</th>
                    <th>Endpoint</th>
                    <th>Action</th>
                    <th>Term</th>
                    <th>Lang</th>
                    <th>Task</th>
                    <th>HTTP</th>
                    <th>Message / Error</th>
                </tr></thead>
                <tbody>
                <?php if (!$rows){ echo '<tr><td colspan="9"><em>No logs.</em></td></tr>'; } else { foreach ($rows as $r){ ?>
                    <tr>
                        <td><?php echo esc_html($r['ts']); ?></td>
                        <td><?php echo esc_html($r['level']); ?></td>
                        <td><?php echo esc_html($r['endpoint']); ?></td>
                        <td><?php echo esc_html($r['action']); ?></td>
                        <td><?php echo esc_html((string)$r['term_id']); ?></td>
                        <td><?php echo esc_html($r['lang']); ?></td>
                        <td><?php echo esc_html($r['task_id']); ?></td>
                        <td><?php echo esc_html((string)$r['http_code']); ?></td>
                        <td>
                            <div><strong><?php echo esc_html($r['message']); ?></strong><?php if(!empty($r['error'])) echo '<br><span style="color:#a00">'.esc_html($r['error']).'</span>'; ?></div>
                            <?php if(!empty($r['request_json_snippet'])) echo '<details><summary>Request</summary><code style="white-space:pre-wrap;display:block">'.esc_html($r['request_json_snippet']).'</code></details>'; ?>
                            <?php if(!empty($r['response_json_snippet'])) echo '<details><summary>Response</summary><code style="white-space:pre-wrap;display:block">'.esc_html($r['response_json_snippet']).'</code></details>'; ?>
                        </td>
                    </tr>
                <?php } } ?>
                </tbody>
            </table>
            <?php
            $total_pages = max(1, ceil($total / $per_page));
            if ($total_pages > 1){
                echo '<div class="tablenav"><div class="tablenav-pages">';
                for ($p=1; $p<=$total_pages; $p++){
                    $url = add_query_arg(array_merge($_GET, ['paged'=>$p]), $base_url);
                    $class = $p==$paged ? 'class="button button-primary"' : 'class="button"';
                    echo '<a '.$class.' style="margin-right:4px" href="'.esc_url($url).'">'.$p.'</a>';
                }
                echo '</div></div>';
            }
            ?>
        </div>
        <?php
    }

    public static function handle_admin_actions(){
        if (!current_user_can('manage_options')) return;
        $action = isset($_GET['ds_kw_action']) ? sanitize_text_field($_GET['ds_kw_action']) : '';

        if ($action === 'refresh_now' && isset($_GET['term_id']) && isset($_GET['lang'])){
            check_admin_referer('ds_kw_refresh');
            $term_id = absint($_GET['term_id']);
            $lang    = sanitize_text_field($_GET['lang']);
            $langs   = ($lang === 'all') ? self::get_languages() : [$lang];
            foreach ($langs as $l){ update_term_meta($term_id, 'ds_kw_last_refresh_'.$l, ''); }
            self::log_write('info','Admin: manual refresh_now', ['action'=>'admin','term_id'=>$term_id,'lang'=>$lang]);
            self::cron_refresh();
            wp_safe_redirect(remove_query_arg(['ds_kw_action','term_id','lang','_wpnonce']));
            exit;
        }

        if ($action === 'refresh_all'){
            check_admin_referer('ds_kw_refresh_all');
            $force = isset($_GET['mode']) && $_GET['mode'] === 'force';
            $langs = self::get_languages();
            if ($force){
                $terms = get_terms(['taxonomy'=>'product_cat','hide_empty'=>false]);
                foreach ($terms as $t){ foreach ($langs as $l){ update_term_meta($t->term_id, 'ds_kw_last_refresh_'.$l, ''); } }
            }
            self::log_write('info','Admin: manual refresh_all', ['action'=>'admin','run_tag'=>$force?'force':'stale']);
            self::cron_refresh();
            wp_safe_redirect(remove_query_arg(['ds_kw_action','mode','_wpnonce']));
            exit;
        }

        if ($action === 'clear_logs'){
            check_admin_referer('ds_kw_clear_logs');
            global $wpdb; $t=self::table_logs(); $wpdb->query("TRUNCATE TABLE {$t}");
            wp_safe_redirect(remove_query_arg(['ds_kw_action','_wpnonce']));
            exit;
        }
    }

    // ----- Category fields: related seeds per language -----
    public static function cat_add_fields(){
        $langs = self::get_languages();
        echo '<div class="form-field">';
        echo '<label>Related keywords (per language)</label>';
        foreach ($langs as $lang){
            echo '<p><strong>'.esc_html($lang).'</strong><br/>';
            echo '<textarea name="ds_related_keywords_'.esc_attr($lang).'" rows="2" cols="40" placeholder="comma, separated, keywords"></textarea></p>';
        }
        echo '</div>';
    }
    public static function cat_edit_fields($term, $taxonomy){
        if ($taxonomy !== 'product_cat') return;
        $langs = self::get_languages();
        echo '<tr class="form-field"><th scope="row">Related keywords (per language)</th><td>';
        foreach ($langs as $lang){
            $val = get_term_meta($term->term_id, 'ds_related_keywords_'.$lang, true);
            echo '<p><strong>'.esc_html($lang).'</strong><br/>';
            echo '<textarea name="ds_related_keywords_'.esc_attr($lang).'" rows="2" cols="60" placeholder="comma, separated, keywords">'.esc_textarea($val).'</textarea></p>';
        }
        // Manual refresh buttons
        $nonce = wp_create_nonce('ds_kw_refresh');
        $base  = admin_url('edit-tags.php?taxonomy=product_cat&post_type=product');
        $tid   = (int)$term->term_id;
        echo '<p style="margin-top:10px">';
        echo '<a class="button" href="'.esc_url(add_query_arg(['ds_kw_action'=>'refresh_now','term_id'=>$tid,'lang'=>'all','_wpnonce'=>$nonce], $base)).'">Refresh keywords now (all languages)</a> ';
        foreach ($langs as $lang){
            echo '<a class="button" style="margin-left:6px" href="'.esc_url(add_query_arg(['ds_kw_action'=>'refresh_now','term_id'=>$tid,'lang'=>$lang,'_wpnonce'=>$nonce], $base)).'">Refresh now ['.esc_html($lang).']</a>';
        }
        echo '</p>';
        echo '</td></tr>';
    }
    public static function cat_save_fields($term_id){
        $langs = self::get_languages();
        foreach ($langs as $lang){
            if (isset($_POST['ds_related_keywords_'.$lang])){
                $v = sanitize_text_field($_POST['ds_related_keywords_'.$lang]);
                update_term_meta($term_id, 'ds_related_keywords_'.$lang, $v);
            }
        }
    }

    // ----- Metabox -----
    public static function add_metabox(){
        add_meta_box('ds_keywords_box', 'Keyword Suggestions', [__CLASS__,'render_metabox'], 'product', 'side', 'default');
    }
    public static function render_metabox($post){
        $post_id = $post->ID;
        $lang = self::get_post_language($post_id);
        $term_ids = function_exists('wc_get_product_terms') ? wc_get_product_terms($post_id, 'product_cat', ['fields'=>'ids']) : [];
        global $wpdb; $t = self::table_keywords();
        $items = [];
        if ($lang && $term_ids){
            $in = implode(',', array_map('intval',$term_ids));
            $sql = $wpdb->prepare("SELECT keyword, volume, intent_main, cpc, competition FROM {$t} WHERE lang=%s AND term_id IN ($in) ORDER BY volume DESC LIMIT 50", $lang);
            $items = $wpdb->get_results($sql, ARRAY_A);
        }
        echo '<div style="max-height:240px; overflow:auto">';
        if (!$items){ echo '<em>No suggestions yet for this product\'s language/categories.</em>'; }
        else {
            echo '<strong>Suggestions</strong>';
            echo '<table class="widefat fixed" style="font-size:12px"><thead><tr><th>Keyword</th><th>Vol</th><th>Intent</th></tr></thead><tbody>';
            foreach ($items as $row){
                echo '<tr><td>'.esc_html($row['keyword']).'</td><td>'.esc_html((string)($row['volume']??'')) .'</td><td>'.esc_html((string)($row['intent_main']??'')) .'</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
        // Ads candidates section
        $limits = self::get_limits();
        $minVol = isset($limits['min_ads_volume']) ? (int)$limits['min_ads_volume'] : 50;
        $ads = [];
        if ($lang && $term_ids){
            $in = implode(',', array_map('intval',$term_ids));
            $tf = self::table_ads_forecast();
            $sql = $wpdb->prepare(
                    "SELECT k.keyword, COALESCE(f.clicks,0) clicks, COALESCE(f.impressions,0) imps, COALESCE(k.volume,0) vol, k.intent_main
                 FROM {$t} k
                 LEFT JOIN {$tf} f ON f.term_id = k.term_id AND f.lang = k.lang AND f.keyword = k.keyword
                 WHERE k.lang=%s AND k.term_id IN ($in) AND COALESCE(k.volume,0) >= %d AND (
                    LOWER(COALESCE(k.intent_main,'')) IN ('transactional','commercial','commercial_investigation','commercial') OR k.intent_main IS NULL
                 )
                 ORDER BY clicks DESC, vol DESC
                 LIMIT %d",
                    $lang, $minVol, (int)$limits['max_ads_per_cat']
            );
            $ads = $wpdb->get_results($sql, ARRAY_A);
        }
        echo '<div style="margin-top:10px; max-height:240px; overflow:auto">';
        echo '<strong>Ads candidates</strong> <span style="color:#888">(filtered by intent & volume)</span>';
        if (!$ads){ echo '<br><em>No Ads candidates yet.</em>'; }
        else {
            echo '<table class="widefat fixed" style="font-size:12px"><thead><tr><th>Keyword</th><th>Clicks</th><th>Impr</th><th>Vol</th></tr></thead><tbody>';
            foreach ($ads as $row){
                echo '<tr><td>'.esc_html($row['keyword']).'</td><td>'.esc_html((string)($row['clicks']??'')) .'</td><td>'.esc_html((string)($row['imps']??'')) .'</td><td>'.esc_html((string)($row['vol']??'')) .'</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
        echo '<p><a href="'.esc_url(admin_url('admin.php?page=ds-keywords')).'" class="button">Open Keywords</a></p>';
    }

    // ----- Cron Schedules -----
    public static function add_schedules($s){
        if (!isset($s['biweekly'])){ $s['biweekly'] = ['interval'=>14*DAY_IN_SECONDS,'display'=>'Every 2 Weeks']; }
        if (!isset($s['five_minutes'])){ $s['five_minutes'] = ['interval'=>5*MINUTE_IN_SECONDS,'display'=>'Every 5 Minutes']; }
        return $s;
    }

    // ----- Cron handlers -----
    public static function cron_refresh(){
        $auth = self::get_auth(); if (!$auth){ self::log_write('warn','cron_refresh: missing auth'); return; }
        $map = self::get_lang_map(); if (!$map){ self::log_write('warn','cron_refresh: missing language map'); return; }

        $limits = self::get_limits();
        $maxCatsPerRun  = (int)($limits['max_cats_per_run'] ?? 50);
        $maxTasksPerRun = (int)($limits['max_tasks_per_run'] ?? 1000);
        $seeds_per_task = max(1, (int)($limits['ideas_seeds_per_task'] ?? 20)); // DFSEO limit 20
        $max_per_batch_post = 100; // POST up to 100 tasks per HTTP call

        $catsProcessed = 0; $tasksPosted = 0; $seedsCount = 0;

        self::log_write('info','cron_refresh: start', ['action'=>'cron_refresh','response'=>['maxCats'=>$maxCatsPerRun,'maxTasks'=>$maxTasksPerRun]]);
        $tasks = []; $ctxs  = [];

        $langs = self::get_languages();
        $terms = get_terms(['taxonomy'=>'product_cat','hide_empty'=>false]);
        $now = current_time('mysql');

        foreach ($langs as $lang){
            if (empty($map[$lang])) continue;
            $lang_code = (string)$map[$lang]['language_code'];
            $loc_code  = (int)$map[$lang]['location_code'];

            foreach ($terms as $term){
                if ($catsProcessed >= $maxCatsPerRun || $tasksPosted >= $maxTasksPerRun) break;

                $term_id_lang = self::translate_term_id($term->term_id, $lang);
                $tobj = get_term($term_id_lang, 'product_cat'); if (!$tobj || is_wp_error($tobj)) continue;

                // Stale?
                $last = get_term_meta($term_id_lang, 'ds_kw_last_refresh_'.$lang, true);
                $stale = !$last || (strtotime($last) < time() - 13.5*DAY_IN_SECONDS);
                if (!$stale) continue;

                // Build seeds and group by up to 20 per task
                $seeds = self::build_seeds_for_term_lang($tobj, $lang);
                if (!$seeds) continue;
                $seedsCount += count($seeds);

                $groups = array_chunk($seeds, $seeds_per_task);
                foreach ($groups as $group){
                    if ($tasksPosted >= $maxTasksPerRun) break;
                    $tasks[] = [
                            'keywords' => array_values($group),
                            'language_code' => $lang_code,
                            'location_code' => $loc_code,
                            'include_adult_keywords' => false,
                            'limit' => min(300, (int)$limits['max_ideas_per_cat'])
                    ];
                    $ctxs[] = ['endpoint'=>'keywords_data/google_ads/keywords_for_keywords','term_id'=>$term_id_lang,'lang'=>$lang,'tag'=>'ideas:'.$term_id_lang.':'.$lang];

                    if (count($tasks) >= $max_per_batch_post){
                        self::dfs_post_tasks('keywords_data/google_ads/keywords_for_keywords/task_post', $tasks, $ctxs, $auth);
                        $tasksPosted += count($tasks); $tasks=[]; $ctxs=[];
                    }
                }

                if (!empty($groups)){
                    $catsProcessed++;
                    // Mark attempt so concurrent runs skip
                    update_term_meta($term_id_lang, 'ds_kw_last_refresh_'.$lang, $now);
                }
            }
        }

        if ($tasks){
            self::dfs_post_tasks('keywords_data/google_ads/keywords_for_keywords/task_post', $tasks, $ctxs, $auth);
            $tasksPosted += count($tasks);
        }

        self::log_write('info','cron_refresh: done', ['action'=>'cron_refresh','response'=>['catsProcessed'=>$catsProcessed,'tasksPosted'=>$tasksPosted,'seeds'=>$seedsCount]]);
    }

    public static function cron_poll(){
        $auth = self::get_auth(); if (!$auth){ self::log_write('warn','cron_poll: missing auth'); return; }
        self::log_write('info','cron_poll: start', ['action'=>'cron_poll']);
        // Ideas
        self::poll_endpoint_ready('keywords_data/google_ads/keywords_for_keywords', $auth, function($result){ self::handle_ideas_result($result); });
        // Related expansion
        self::poll_endpoint_ready('dataforseo_labs/google/related_keywords', $auth, function($result){ self::handle_related_result($result); });
        // Volume
        self::poll_endpoint_ready('keywords_data/google_ads/search_volume', $auth, function($result){ self::handle_volume_result($result); });
        // PAA via SERP Organic (Advanced)
        self::poll_endpoint_ready('serp/google/organic', $auth, function($result){ self::handle_paa_result($result); });
        // Forecasts (optional)
        self::poll_endpoint_ready('keywords_data/google_ads/ad_traffic_by_keywords', $auth, function($result){ self::handle_forecast_result($result); });
        self::log_write('info','cron_poll: done', ['action'=>'cron_poll']);
    }

    // ----- Seeds -----
    private static function build_seeds_for_term_lang($term, $lang){
        $seeds = [];
        $name = is_object($term) ? $term->name : (string)$term;
        if ($name) $seeds[] = $name;
        $extra = (string) get_term_meta($term->term_id, 'ds_related_keywords_'.$lang, true);
        if ($extra){
            $parts = array_filter(array_map('trim', explode(',', $extra)));
            foreach ($parts as $p){ if ($p!=='') $seeds[] = $p; }
        }
        $seeds = array_values(array_unique($seeds));
        // Cap total seed list per category to keep ideas reasonable (front cap = 20 via grouping anyway)
        return array_slice($seeds, 0, 20);
    }

    // ----- Logging helpers -----
    private static function log_enabled(){ return get_option(self::OPT_LOG_ENABLED,'yes') === 'yes'; }
    private static function log_write($level, $message, $ctx = []){
        if (!self::log_enabled()) return;
        global $wpdb; $t = self::table_logs();
        $row = [
                'ts'   => current_time('mysql'),
                'level'=> substr((string)$level,0,16),
                'endpoint'=> isset($ctx['endpoint']) ? substr((string)$ctx['endpoint'],0,160) : null,
                'action'  => isset($ctx['action']) ? substr((string)$ctx['action'],0,40) : null,
                'term_id' => isset($ctx['term_id']) ? (int)$ctx['term_id'] : null,
                'lang'    => isset($ctx['lang']) ? substr((string)$ctx['lang'],0,12) : null,
                'task_id' => isset($ctx['task_id']) ? substr((string)$ctx['task_id'],0,64) : null,
                'http_code'=> isset($ctx['http_code']) ? (int)$ctx['http_code'] : null,
                'error'   => isset($ctx['error']) ? (string)$ctx['error'] : null,
                'message' => (string)$message,
                'request_json_snippet'  => isset($ctx['request']) ? self::trim_snippet($ctx['request']) : null,
                'response_json_snippet' => isset($ctx['response'])? self::trim_snippet($ctx['response']): null,
                'run_tag' => isset($ctx['run_tag']) ? substr((string)$ctx['run_tag'],0,191) : null,
        ];
        $wpdb->insert($t, $row);
    }
    private static function trim_snippet($data){
        $s = is_string($data) ? $data : wp_json_encode($data);
        if (!is_string($s)) return null;
        $s = preg_replace('/\s+/', ' ', $s);
        return substr($s, 0, 2000);
    }
    public static function purge_logs_maybe(){
        if (!self::log_enabled()) return;
        // Run at most every 12 hours
        $key = 'ds_kw_logs_purge_last';
        $last = get_transient($key);
        if ($last) return;
        $days = max(1, (int)get_option(self::OPT_LOG_RETENTION,14));
        global $wpdb; $t = self::table_logs();
        $wpdb->query($wpdb->prepare("DELETE FROM {$t} WHERE ts < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)", $days));
        set_transient($key, 1, 12*HOUR_IN_SECONDS);
    }

    // ----- DataForSEO HTTP helpers -----
    private static function get_auth(){
        $login = (string) get_option(self::OPT_LOGIN,'');
        $pass  = (string) get_option(self::OPT_PASS,'');
        if (!$login || !$pass) return null;
        return ['login'=>$login,'password'=>$pass];
    }
    private static function get_lang_map(){
        $raw = get_option(self::OPT_LANGMAP,'');
        $map = json_decode($raw, true);
        if (!is_array($map) || empty($map)) {
            // Fallback to English/United States when Polylang or mapping is not configured
            $map = ['en' => ['language_code' => 'en', 'location_code' => 2840]];
        }
        return $map;
    }
    private static function get_limits(){
        $raw = get_option(self::OPT_LIMITS,''); $arr = json_decode($raw, true);
        if (!is_array($arr)) $arr = [];
        $def = [
                'max_ideas_per_cat'=>300,'max_ads_per_cat'=>50,'min_ads_volume'=>50,
                'expansion_enable'=>true,'forecast_enable'=>false,'budget_cap_credits'=>1000,
                'max_cats_per_run'=>50,'max_tasks_per_run'=>1000,
                'ideas_seeds_per_task'=>20,'volume_keywords_per_task'=>1000,'paa_max_seeds_per_cat'=>1
        ];
        return array_replace($def, $arr);
    }

    // ----- Language helpers (Polylang-optional) -----
    private static function get_languages(){
        if (function_exists('pll_languages_list')) {
            $langs = pll_languages_list();
            if (is_array($langs) && !empty($langs)) return $langs;
        }
        return ['en'];
    }
    private static function get_post_language($post_id){
        if (function_exists('pll_get_post_language')){
            $l = pll_get_post_language($post_id);
            if ($l) return $l;
        }
        return 'en';
    }
    private static function translate_term_id($term_id, $lang){
        $tid = (int)$term_id;
        if (function_exists('pll_get_term')){
            $tr = pll_get_term($tid, $lang);
            if ($tr) return (int)$tr;
        }
        return $tid;
    }

    private static function dfs_post($path, $body, $auth){
        $url = 'https://api.dataforseo.com/v3/'.ltrim($path,'/');
        $args = [
                'headers' => [
                        'Authorization' => 'Basic '. base64_encode($auth['login'].':'.$auth['password']),
                        'Content-Type'  => 'application/json'
                ],
                'timeout' => 45,
                'body' => wp_json_encode($body)
        ];
        self::log_write('info', 'POST '.$path, [ 'endpoint'=>$path, 'action'=>'request', 'request'=>$body ]);
        $res = wp_remote_post($url, $args);
        if (is_wp_error($res)){
            self::log_write('error','POST failed: '.$res->get_error_message(), ['endpoint'=>$path,'action'=>'response','error'=>$res->get_error_message()]);
            return [null, 0, $res->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $body_raw = wp_remote_retrieve_body($res);
        $json = json_decode($body_raw, true);
        self::log_write(($code>=200&&$code<300)?'info':'warn', 'POST '.$path.' http='.$code, ['endpoint'=>$path,'action'=>'response','http_code'=>$code,'response'=> $json?:$body_raw]);
        return [$json, $code, null];
    }

    private static function dfs_get($path, $auth){
        $url = 'https://api.dataforseo.com/v3/'.ltrim($path,'/');
        $args = [ 'headers' => [ 'Authorization' => 'Basic '. base64_encode($auth['login'].':'.$auth['password']) ], 'timeout'=>45 ];
        self::log_write('info','GET '.$path, ['endpoint'=>$path,'action'=>'request']);
        $res = wp_remote_get($url, $args);
        if (is_wp_error($res)){
            self::log_write('error','GET failed: '.$res->get_error_message(), ['endpoint'=>$path,'action'=>'response','error'=>$res->get_error_message()]);
            return [null, 0, $res->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $body_raw = wp_remote_retrieve_body($res);
        $json = json_decode($body_raw, true);
        self::log_write(($code>=200&&$code<300)?'info':'warn', 'GET '.$path.' http='.$code, ['endpoint'=>$path,'action'=>'response','http_code'=>$code,'response'=>$json?:$body_raw]);
        return [$json, $code, null];
    }

    private static function dfs_post_tasks($endpoint_task_post, $tasks, $ctxs, $auth){
        if (!$tasks) return;
        list($json,$code,$err) = self::dfs_post($endpoint_task_post, $tasks, $auth);
        if ($err || $code < 200 || $code >= 300){
            self::log_write('error','Task post failed', ['endpoint'=>$endpoint_task_post,'action'=>'post_tasks','http_code'=>$code,'error'=>$err,'request'=>$tasks,'response'=>$json]);
            error_log('DataForSEO post error '.$code.': '.$err);
            return;
        }
        // Record queue entries if provided
        global $wpdb; $tq = self::table_queue(); $now = current_time('mysql');
        $endpoint_base = trim(str_replace('/task_post','', $endpoint_task_post), '/');
        $items = is_array($json['tasks'] ?? null) ? $json['tasks'] : [];
        $ins_count = 0; $task_ids=[];
        foreach ($items as $idx=>$task){
            $task_id = (string)($task['id'] ?? '');
            $ctx = $ctxs[$idx] ?? null;
            if ($task_id && $ctx){
                $wpdb->insert($tq, [
                        'endpoint' => $endpoint_base,
                        'task_id'  => $task_id,
                        'tag'      => $ctx['tag'],
                        'term_id'  => $ctx['term_id'],
                        'lang'     => $ctx['lang'],
                        'status'   => 'posted',
                        'payload_json' => wp_json_encode($tasks[$idx]),
                        'created_at'=> $now,
                        'updated_at'=> $now,
                ]);
                $ins_count++; $task_ids[]=$task_id;
            }
        }
        self::log_write('info','Posted tasks: '.$ins_count, ['endpoint'=>$endpoint_base,'action'=>'post_tasks','response'=>['task_ids'=>$task_ids]]);
    }

    private static function poll_endpoint_ready($endpoint_base, $auth, $handler){
        // SERP uses a consolidated /serp/tasks_ready endpoint.
        $tasks_ready_path = (strpos($endpoint_base, 'serp/') === 0)
                ? 'serp/tasks_ready'
                : $endpoint_base . '/tasks_ready';

        list($ready,$code,$err) = self::dfs_get($tasks_ready_path, $auth);
        if ($err || $code < 200 || $code >= 300){
            self::log_write('warn','tasks_ready failed', ['endpoint'=>$endpoint_base,'action'=>'tasks_ready','http_code'=>$code,'error'=>$err]);
            return;
        }

        $ready_tasks = [];
        if (isset($ready['tasks']) && is_array($ready['tasks'])){
            foreach ($ready['tasks'] as $t){
                if (!empty($t['result'])){
                    foreach ($t['result'] as $r){ if (!empty($r['id'])) $ready_tasks[] = $r['id']; }
                }
            }
        }
        self::log_write('info','tasks_ready count: '.count($ready_tasks), ['endpoint'=>$endpoint_base,'action'=>'tasks_ready','response'=>['count'=>count($ready_tasks)]]);
        if (!$ready_tasks) return;

        foreach ($ready_tasks as $tid){
            // For SERP Organic we need Advanced GET path
            $get_path = (strpos($endpoint_base,'serp/google/organic')===0)
                    ? $endpoint_base.'/task_get/advanced/'.rawurlencode($tid)
                    : $endpoint_base.'/task_get/'.rawurlencode($tid);

            list($res,$code,$err) = self::dfs_get($get_path, $auth);
            if ($err || $code < 200 || $code >= 300){
                self::log_write('warn','task_get failed', ['endpoint'=>$endpoint_base,'action'=>'task_get','task_id'=>$tid,'http_code'=>$code,'error'=>$err]);
                continue;
            }
            if (isset($res['tasks'][0]['result']) && is_array($res['tasks'][0]['result'])){
                foreach ($res['tasks'][0]['result'] as $result){ $handler($result); }
            }
        }
    }

    // ----- Handlers -----
    private static function handle_ideas_result($result){
        global $wpdb; $tk = self::table_keywords(); $tq = self::table_queue();
        $now = current_time('mysql');
        $task_id = (string)($result['id'] ?? '');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tq} WHERE endpoint=%s AND task_id=%s", 'keywords_data/google_ads/keywords_for_keywords', $task_id), ARRAY_A);
        $term_id = $row ? (int)$row['term_id'] : 0;
        $lang = $row ? (string)$row['lang'] : '';
        $items = isset($result['items']) && is_array($result['items']) ? $result['items'] : [];
        $ins = 0;

        foreach ($items as $it){
            $kw = isset($it['keyword']) ? (string)$it['keyword'] : '';
            if ($kw==='') continue;
            $volume = isset($it['keyword_info']['search_volume']) ? (int)$it['keyword_info']['search_volume'] : null;
            $comp   = isset($it['keyword_info']['competition']) ? (float)$it['keyword_info']['competition'] : null;
            $cpc    = isset($it['keyword_info']['cpc']) ? (float)$it['keyword_info']['cpc'] : null;
            $bid_l  = isset($it['keyword_info']['low_top_of_page_bid']) ? (float)$it['keyword_info']['low_top_of_page_bid'] : null;
            $bid_h  = isset($it['keyword_info']['high_top_of_page_bid']) ? (float)$it['keyword_info']['high_top_of_page_bid'] : null;

            $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$tk} (term_id, lang, keyword, source, volume, competition, cpc, top_of_page_bid_low, top_of_page_bid_high, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE volume=VALUES(volume), competition=VALUES(competition), cpc=VALUES(cpc), top_of_page_bid_low=VALUES(top_of_page_bid_low), top_of_page_bid_high=VALUES(top_of_page_bid_high), updated_at=VALUES(updated_at)",
                    $term_id, $lang, $kw, 'ideas',
                    is_null($volume) ? null : $volume,
                    is_null($comp) ? null : $comp,
                    is_null($cpc) ? null : $cpc,
                    is_null($bid_l) ? null : $bid_l,
                    is_null($bid_h) ? null : $bid_h,
                    $now, $now
            ));
            $ins++;
        }

        self::log_write('info','ideas result saved', ['endpoint'=>'keywords_data/google_ads/keywords_for_keywords','action'=>'handle_result','term_id'=>$term_id,'lang'=>$lang,'task_id'=>$task_id,'response'=>['items'=>$ins]]);
        if ($row){
            $wpdb->update($tq, ['status'=>'completed','result_json'=>substr(wp_json_encode($result),0,20000),'updated_at'=>$now], ['id'=>(int)$row['id']]);
        }
        if ($term_id && $lang){ self::enqueue_followups_for_term_lang($term_id, $lang); }
    }

    private static function enqueue_followups_for_term_lang($term_id, $lang){
        $auth = self::get_auth(); if (!$auth) return;
        $map = self::get_lang_map(); if (empty($map[$lang])) return;
        $limits = self::get_limits();
        $lang_code = (string)$map[$lang]['language_code'];
        $loc_code  = (int)$map[$lang]['location_code'];
        global $wpdb; $tk = self::table_keywords();

        // Category seed tokens for cheap heuristic expansion
        $term = get_term($term_id, 'product_cat');
        $seed_text = $term && !is_wp_error($term) ? mb_strtolower($term->name) : '';
        $seed_tokens = preg_split('/\s+/u', $seed_text, -1, PREG_SPLIT_NO_EMPTY);

        // Choose top suggestions to expand (cheap)
        $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT keyword, COALESCE(volume,0) vol
             FROM {$tk} WHERE term_id=%d AND lang=%s AND source='ideas'
             ORDER BY vol DESC LIMIT 50", $term_id, $lang
        ), ARRAY_A);

        $to_expand = [];
        foreach ($rows as $r){
            $kw = mb_strtolower($r['keyword']);
            foreach ($seed_tokens as $tok){
                if ($tok && mb_stripos($kw, $tok) !== false){ $to_expand[] = $r['keyword']; break; }
            }
            if (count($to_expand) >= 10) break; // tighter cap to save cost
        }

        // Related keywords (async, optional)
        if (!empty($to_expand) && !empty($limits['expansion_enable'])){
            $tasks=[]; $ctxs=[]; $max_batch=100;
            foreach ($to_expand as $kw){
                $tasks[] = [
                        'keyword' => $kw,
                        'language_code' => $lang_code,
                        'location_code' => $loc_code,
                        'limit' => 100
                ];
                $ctxs[] = ['endpoint'=>'dataforseo_labs/google/related_keywords','term_id'=>$term_id,'lang'=>$lang,'tag'=>'related:'.$term_id.':'.$lang];
                if (count($tasks) >= $max_batch){
                    self::dfs_post_tasks('dataforseo_labs/google/related_keywords/task_post', $tasks, $ctxs, $auth); $tasks=[]; $ctxs=[];
                }
            }
            if ($tasks){ self::dfs_post_tasks('dataforseo_labs/google/related_keywords/task_post', $tasks, $ctxs, $auth); }
        }

        // Build deduped pool for intent & volume
        $pool_rows = $wpdb->get_results($wpdb->prepare("SELECT DISTINCT keyword FROM {$tk} WHERE term_id=%d AND lang=%s", $term_id, $lang), ARRAY_A);
        $pool = array_values(array_unique(array_map(function($r){ return (string)$r['keyword']; }, $pool_rows)));
        if ($pool){
            // ---- INTENT (LIVE) ----
            $intent_chunks = array_chunk($pool, 1000);
            foreach ($intent_chunks as $ch){
                $payload = [[ 'keywords'=>$ch, 'language_code'=>$lang_code, 'location_code'=>$loc_code ]];
                list($json,$code,$err) = self::dfs_post('dataforseo_labs/google/search_intent/live', $payload, $auth);
                if ($err || $code < 200 || $code >= 300){
                    self::log_write('warn','intent live failed', ['endpoint'=>'dataforseo_labs/google/search_intent/live','action'=>'live','http_code'=>$code,'error'=>$err,'request'=>$payload,'term_id'=>$term_id,'lang'=>$lang]);
                } else {
                    if (isset($json['tasks'][0]['result'][0]['items'])){
                        $items = $json['tasks'][0]['result'][0]['items'];
                        self::handle_intent_items($items, $term_id, $lang);
                    }
                }
            }

            // ---- VOLUME (ASYNC) ----
            $per_task = max(1, (int)($limits['volume_keywords_per_task'] ?? 1000));
            $chunks = array_chunk($pool, $per_task);
            $tasks=[]; $ctxs=[];
            foreach ($chunks as $ch){
                $tasks[] = [ 'keywords'=>$ch, 'language_code'=>$lang_code, 'location_code'=>$loc_code ];
                $ctxs[]  = ['endpoint'=>'keywords_data/google_ads/search_volume','term_id'=>$term_id,'lang'=>$lang,'tag'=>'volume:'.$term_id.':'.$lang];
            }
            if ($tasks){ self::dfs_post_tasks('keywords_data/google_ads/search_volume/task_post', $tasks, $ctxs, $auth); }
        }

        // ---- PAA (SERP Organic Advanced async) ----
        $paa_seeds = [];
        if ($term && $term->name){ $paa_seeds[] = $term->name; }
        // keep to configured cap (default 1)
        $paa_seeds = array_slice($paa_seeds, 0, max(0,(int)$limits['paa_max_seeds_per_cat']));
        if ($paa_seeds){
            $paa_tasks=[]; $paa_ctxs=[];
            foreach ($paa_seeds as $k){
                $paa_tasks[] = [
                        'keyword'=>$k,
                        'language_code'=>$lang_code,
                        'location_code'=>$loc_code,
                        'device'=>'desktop',
                        'os'=>'windows',
                        'depth'=>100,
                        'people_also_ask_click_depth'=>2
                ];
                $paa_ctxs[]  = ['endpoint'=>'serp/google/organic','term_id'=>$term_id,'lang'=>$lang,'tag'=>'paa:'.$term_id.':'.$lang];
            }
            self::dfs_post_tasks('serp/google/organic/task_post', $paa_tasks, $paa_ctxs, $auth);
        }

        // ---- Forecasts (optional) ----
        if (!empty($limits['forecast_enable']) && !empty($pool)){
            $chunks = array_chunk($pool, 1000);
            $tasks=[]; $ctxs=[];
            foreach ($chunks as $ch){
                $tasks[] = [ 'keywords'=>$ch, 'language_code'=>$lang_code, 'location_code'=>$loc_code ];
                $ctxs[]  = ['endpoint'=>'keywords_data/google_ads/ad_traffic_by_keywords','term_id'=>$term_id,'lang'=>$lang,'tag'=>'forecast:'.$term_id.':'.$lang];
            }
            if ($tasks){ self::dfs_post_tasks('keywords_data/google_ads/ad_traffic_by_keywords/task_post', $tasks, $ctxs, $auth); }
        }
    }

    private static function handle_related_result($result){
        global $wpdb; $tk = self::table_keywords(); $tq = self::table_queue();
        $now = current_time('mysql');
        $task_id = (string)($result['id'] ?? '');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tq} WHERE endpoint=%s AND task_id=%s", 'dataforseo_labs/google/related_keywords', $task_id), ARRAY_A);
        $term_id = $row ? (int)$row['term_id'] : 0; $lang = $row ? (string)$row['lang'] : '';
        $items = isset($result['items']) && is_array($result['items']) ? $result['items'] : [];
        $ins=0;
        foreach ($items as $it){
            $kw = isset($it['keyword']) ? (string)$it['keyword'] : '';
            if ($kw==='') continue;
            $volume = isset($it['search_volume']) ? (int)$it['search_volume'] : ( isset($it['keyword_info']['search_volume']) ? (int)$it['keyword_info']['search_volume'] : null );
            $comp   = isset($it['competition']) ? (float)$it['competition'] : null;
            $cpc    = isset($it['cpc']) ? (float)$it['cpc'] : null;
            $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$tk} (term_id, lang, keyword, source, volume, competition, cpc, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE source='related', volume=COALESCE(VALUES(volume), volume), competition=COALESCE(VALUES(competition), competition), cpc=COALESCE(VALUES(cpc), cpc), updated_at=VALUES(updated_at)",
                    $term_id, $lang, $kw, 'related',
                    is_null($volume) ? null : $volume,
                    is_null($comp) ? null : $comp,
                    is_null($cpc) ? null : $cpc,
                    $now, $now
            ));
            $ins++;
        }
        self::log_write('info','related result saved', ['endpoint'=>'dataforseo_labs/google/related_keywords','action'=>'handle_result','term_id'=>$term_id,'lang'=>$lang,'task_id'=>$task_id,'response'=>['items'=>$ins]]);
        if ($row){ $wpdb->update($tq, ['status'=>'completed','result_json'=>substr(wp_json_encode($result),0,20000),'updated_at'=>$now], ['id'=>(int)$row['id']]); }
    }

    private static function handle_intent_items($items, $term_id, $lang){
        global $wpdb; $tk = self::table_keywords(); $now = current_time('mysql');
        $ins=0;
        foreach ($items as $it){
            $kw = isset($it['keyword']) ? (string)$it['keyword'] : '';
            if ($kw==='') continue;
            $intent = '';
            $probs  = null;
            if (isset($it['search_intent_info'])){
                $info = $it['search_intent_info'];
                $intent = (string)($info['main_intent'] ?? ($info['main'] ?? ''));
                $probs = wp_json_encode($info);
            }
            $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$tk} (term_id, lang, keyword, source, intent_main, intent_probs, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE intent_main=VALUES(intent_main), intent_probs=VALUES(intent_probs), updated_at=VALUES(updated_at)",
                    $term_id, $lang, $kw, 'intent', $intent, $probs, $now, $now
            ));
            $ins++;
        }
        self::log_write('info','intent result saved (live)', ['endpoint'=>'dataforseo_labs/google/search_intent/live','action'=>'handle_result','term_id'=>$term_id,'lang'=>$lang,'response'=>['items'=>$ins]]);
    }

    private static function handle_volume_result($result){
        global $wpdb; $tk = self::table_keywords(); $tq = self::table_queue();
        $now = current_time('mysql');
        $task_id = (string)($result['id'] ?? '');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tq} WHERE endpoint=%s AND task_id=%s", 'keywords_data/google_ads/search_volume', $task_id), ARRAY_A);
        $term_id = $row ? (int)$row['term_id'] : 0; $lang = $row ? (string)$row['lang'] : '';
        $items = isset($result['items']) && is_array($result['items']) ? $result['items'] : [];
        $ins=0;
        foreach ($items as $it){
            $kw = isset($it['keyword']) ? (string)$it['keyword'] : '';
            if ($kw==='') continue;
            $volume = isset($it['search_volume']) ? (int)$it['search_volume'] : ( isset($it['keyword_info']['search_volume']) ? (int)$it['keyword_info']['search_volume'] : null );
            $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$tk} (term_id, lang, keyword, source, volume, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE volume=COALESCE(VALUES(volume), volume), updated_at=VALUES(updated_at)",
                    $term_id, $lang, $kw, 'volume', is_null($volume)?null:$volume, $now, $now
            ));
            $ins++;
        }
        self::log_write('info','volume result saved', ['endpoint'=>'keywords_data/google_ads/search_volume','action'=>'handle_result','term_id'=>$term_id,'lang'=>$lang,'task_id'=>$task_id,'response'=>['items'=>$ins]]);
        if ($row){ $wpdb->update($tq, ['status'=>'completed','result_json'=>substr(wp_json_encode($result),0,20000),'updated_at'=>$now], ['id'=>(int)$row['id']]); }
    }

    private static function handle_paa_result($result){
        global $wpdb; $tf = self::table_faq(); $tq = self::table_queue();
        $now = current_time('mysql');
        $task_id = (string)($result['id'] ?? '');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tq} WHERE endpoint=%s AND task_id=%s", 'serp/google/organic', $task_id), ARRAY_A);
        $term_id = $row ? (int)$row['term_id'] : 0; $lang = $row ? (string)$row['lang'] : '';
        $items = isset($result['items']) && is_array($result['items']) ? $result['items'] : [];
        $ins=0;
        foreach ($items as $root){
            if ((string)($root['type'] ?? '') !== 'people_also_ask') continue;
            $children = isset($root['items']) && is_array($root['items']) ? $root['items'] : [];
            foreach ($children as $el){
                $q = (string)($el['title'] ?? '');
                if ($q==='') continue;
                $a = '';
                if (isset($el['expanded']['answer'])) $a = (string)$el['expanded']['answer'];
                elseif (isset($el['answer'])) $a = (string)$el['answer'];
                $wpdb->insert($tf, [
                        'term_id'=>$term_id,'lang'=>$lang,'question'=>$q,'answer'=>$a,'source'=>'paa','created_at'=>$now,'updated_at'=>$now
                ]);
                $ins++;
            }
        }
        self::log_write('info','paa result saved', ['endpoint'=>'serp/google/organic','action'=>'handle_result','term_id'=>$term_id,'lang'=>$lang,'task_id'=>$task_id,'response'=>['items'=>$ins]]);
        if ($row){ $wpdb->update($tq, ['status'=>'completed','result_json'=>substr(wp_json_encode($result),0,20000),'updated_at'=>$now], ['id'=>(int)$row['id']]); }
    }

    private static function handle_forecast_result($result){
        global $wpdb; $tf = self::table_ads_forecast(); $tq = self::table_queue();
        $now = current_time('mysql');
        $task_id = (string)($result['id'] ?? '');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tq} WHERE endpoint=%s AND task_id=%s", 'keywords_data/google_ads/ad_traffic_by_keywords', $task_id), ARRAY_A);
        $term_id = $row ? (int)$row['term_id'] : 0; $lang = $row ? (string)$row['lang'] : '';
        $items = isset($result['items']) && is_array($result['items']) ? $result['items'] : [];
        $ins=0;
        foreach ($items as $it){
            $kw = isset($it['keyword']) ? (string)$it['keyword'] : '';
            if ($kw==='') continue;
            $m = isset($it['metrics']) && is_array($it['metrics']) ? $it['metrics'] : $it;
            $clicks = isset($m['clicks']) ? (float)$m['clicks'] : null;
            $impr   = isset($m['impressions']) ? (float)$m['impressions'] : null;
            $cost   = isset($m['cost']) ? (float)$m['cost'] : null;
            $cpc    = isset($m['cpc']) ? (float)$m['cpc'] : ( ($clicks && $cost) ? (float)$cost / max(0.001,$clicks) : null );
            $ctr    = isset($m['ctr']) ? (float)$m['ctr'] : null;
            $pos    = isset($m['position']) ? (float)$m['position'] : null;
            $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$tf} (term_id, lang, keyword, clicks, impressions, cost, cpc, ctr, position, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE clicks=VALUES(clicks), impressions=VALUES(impressions), cost=VALUES(cost), cpc=VALUES(cpc), ctr=VALUES(ctr), position=VALUES(position), updated_at=VALUES(updated_at)",
                    $term_id, $lang, $kw,
                    is_null($clicks)?null:$clicks,
                    is_null($impr)?null:$impr,
                    is_null($cost)?null:$cost,
                    is_null($cpc)?null:$cpc,
                    is_null($ctr)?null:$ctr,
                    is_null($pos)?null:$pos,
                    $now, $now
            ));
            $ins++;
        }
        self::log_write('info','forecast result saved', ['endpoint'=>'keywords_data/google_ads/ad_traffic_by_keywords','action'=>'handle_result','term_id'=>$term_id,'lang'=>$lang,'task_id'=>$task_id,'response'=>['items'=>$ins]]);
        if ($row){ $wpdb->update($tq, ['status'=>'completed','result_json'=>substr(wp_json_encode($result),0,20000),'updated_at'=>$now], ['id'=>(int)$row['id']]); }
    }

    public static function schedule_as_events(){
        if (!function_exists('as_next_scheduled_action') || !function_exists('as_schedule_recurring_action')) {
            return; // Action Scheduler not available
        }
        // Only proceed after init/action_scheduler_init
        if (!did_action('init') && !did_action('action_scheduler_init')) {
            return;
        }
        // Schedule biweekly refresh
        if (!as_next_scheduled_action(self::AS_REFRESH, [], 'ds-keywords')) {
            as_schedule_recurring_action(time()+600, 14*DAY_IN_SECONDS, self::AS_REFRESH, [], 'ds-keywords');
        }
        // Schedule 5-minute poller
        if (!as_next_scheduled_action(self::AS_POLL, [], 'ds-keywords')) {
            as_schedule_recurring_action(time()+300, 5*MINUTE_IN_SECONDS, self::AS_POLL, [], 'ds-keywords');
        }
        // Clear WP-Cron duplicates if any
        if (function_exists('wp_next_scheduled')) {
            if (wp_next_scheduled(self::CRON_REFRESH)) { wp_clear_scheduled_hook(self::CRON_REFRESH); }
            if (wp_next_scheduled(self::CRON_POLL))    { wp_clear_scheduled_hook(self::CRON_POLL); }
        }
    }
}
