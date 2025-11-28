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
    // Correlation for a single refresh run
    private static $run_id = null;
    // Schedules & hooks
    const CRON_REFRESH = 'ds_keywords_refresh_biweekly';
    const CRON_POLL    = 'ds_keywords_poll';
    // Action Scheduler hooks
    const AS_REFRESH   = 'ds_keywords_refresh_as';
    const AS_POLL      = 'ds_keywords_poll_as';
    const AS_REPORT    = 'ds_keywords_daily_report';

    // Options
    const OPT_LOGIN  = 'ds_dfs_login';
    const OPT_PASS   = 'ds_dfs_password';
    const OPT_LANGMAP = 'ds_dfs_lang_map_json'; // {"en":{"language_code":"en","location_code":2840}, ...}
    const OPT_LIMITS  = 'ds_dfs_limits_json';   // caps & toggles JSON
    const OPT_LOG_ENABLED   = 'ds_dfs_log_enabled'; // yes|no
    const OPT_LOG_RETENTION = 'ds_dfs_log_retention_days'; // int days
    const OPT_LOG_SNIPPET   = 'ds_dfs_log_snippet'; // int chars per snippet

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
        // Gate check single-event hook (term/lang-specific follow-ups evaluation)
        add_action('ds_keywords_gate_check', [__CLASS__, 'gate_check_handler'], 10, 2);

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
            add_action(self::AS_REPORT,  [__CLASS__,'run_daily_diagnostics']);
            add_action('action_scheduler_init', [__CLASS__, 'schedule_as_events'], 20);
            add_action('init', [__CLASS__, 'schedule_as_events'], 20);
            // Ensure single scheduler if AS is present
            add_action('action_scheduler_init', [__CLASS__, 'ensure_single_scheduler'], 5);
        }
        // Ensure single scheduler at early init too
        add_action('init', [__CLASS__, 'ensure_single_scheduler'], 5);

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
        register_setting('ds_keywords', self::OPT_LOG_SNIPPET,  ['type'=>'integer','sanitize_callback'=>'absint','default'=>2000]);
    }
    public static function render_settings(){
        if (!current_user_can('manage_options')) return;
        $langmap = get_option(self::OPT_LANGMAP, '');
        $limits  = get_option(self::OPT_LIMITS, '');
        $log_on  = get_option(self::OPT_LOG_ENABLED,'yes');
        $ret     = (int) get_option(self::OPT_LOG_RETENTION,14);
        $snip    = (int) get_option(self::OPT_LOG_SNIPPET,2000);
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
                            <textarea name="<?php echo esc_attr(self::OPT_LIMITS); ?>" rows="10" cols="80"><?php echo esc_textarea($limits); ?></textarea>
                            <p class="description">Caps, batching, and per-endpoint delivery modes.</p>
                            <details style="margin-top:6px">
                                <summary><strong>About delivery modes: live vs async</strong></summary>
                                <div style="margin-top:8px; max-width:860px">
                                    <p>Each endpoint can run in two modes:</p>
                                    <ul style="list-style:disc; margin-left:20px">
                                        <li><strong>live</strong>: call DataForSEO <code>.../live</code> and save results immediately (best for testing).</li>
                                        <li><strong>async</strong>: create tasks with <code>.../task_post</code> and let the poller fetch results later (best for production cost control and batching).</li>
                                    </ul>
                                    <p>Set these keys in the JSON to choose per-endpoint mode:</p>
                                    <ul style="list-style:disc; margin-left:20px">
                                        <li><code>ideas_mode</code> for <code>keywords_for_keywords</code></li>
                                        <li><code>related_mode</code> for <code>dataforseo_labs/google/related_keywords</code></li>
                                        <li><code>volume_mode</code> for <code>google_ads/search_volume</code></li>
                                        <li><code>forecast_mode</code> for <code>ad_traffic_by_keywords</code> (async only at the moment)</li>
                                        <li><code>paa_mode</code> for <code>serp/google/organic (PAA)</code> (async only at the moment)</li>
                                    </ul>
                                    <p><strong>Notes</strong></p>
                                    <ul style="list-style:disc; margin-left:20px">
                                        <li>Intent uses Labs <code>search_intent/live</code> and is always live.</li>
                                        <li>PAA and Forecasts do not have a live path implemented yet. Keep <code>paa_mode</code> and <code>forecast_mode</code> set to <code>"async"</code>.</li>
                                        <li>In live mode the poller skips the endpoint unless there are existing posted tasks to finish. Skips are logged as <code>poll_skip</code> in Keywords Logs.</li>
                                        <li>When <code>tasks_ready</code> returns empty, the poller automatically falls back to direct <code>task_get</code> for your queued task IDs and logs the exact IDs being checked.</li>
                                    </ul>
                                    <p style="margin-top:6px"><strong>Example — test/dev (don’t wait):</strong></p>
                                    <pre style="white-space:pre-wrap"><code>{
  "ideas_mode": "live",
  "related_mode": "live",
  "volume_mode": "live",
  "forecast_mode": "async",
  "paa_mode": "async"
}</code></pre>
                                    <p style="margin-top:6px"><strong>Example — production (cost-efficient batching):</strong></p>
                                    <pre style="white-space:pre-wrap"><code>{
  "ideas_mode": "async",
  "related_mode": "async",
  "volume_mode": "async",
  "forecast_mode": "async",
  "paa_mode": "async"
}</code></pre>
                                    <p>You can always switch these and click “Run now for all categories” to apply immediately.</p>
                                </div>
                            </details>
                            <details style="margin-top:6px">
                                <summary><strong>Expansion & long-tail controls</strong></summary>
                                <div style="margin-top:8px; max-width:860px">
                                    <p>Use these to increase coverage and capture long‑tails:</p>
                                    <ul style="list-style:disc; margin-left:20px">
                                        <li><code>expansion_enable</code> (bool): enable Labs <code>related_keywords</code> expansion. Default: <code>true</code>.</li>
                                        <li><code>expansion_candidates_cap</code> (int): how many top Ideas we expand per category. Higher values = more breadth. Suggested dev: 80–120; prod: 20–40.</li>
                                        <li><code>expansion_token_filter</code> (bool): when <code>true</code>, only expand ideas that include a token from the category name (cheaper, more relevant). Set to <code>false</code> in dev to broaden.</li>
                                        <li><code>expand_max_depth</code> (int): <code>1</code> = expand only Ideas; <code>2</code> = also expand top outputs from Related (multi‑hop). Default: <code>1</code>.</li>
                                        <li><code>expand_related_limit_per_seed</code> (int): Labs results to request per seed (max 100). Default: <code>20</code>.</li>
                                        <li><code>suggest_enable</code> (bool): use Labs <code>search_suggestions/live</code> (autocomplete) for long‑tails. Default: <code>false</code>.</li>
                                        <li><code>suggest_limit_per_seed</code> (int): autocomplete items per seed. Default: <code>20</code>.</li>
                                        <li><code>max_total_keywords_per_cat</code> (int): hard cap on saved keywords per term/lang to prevent runaway growth. Default: <code>500</code>.</li>
                                    </ul>
                                    <p style="margin-top:6px"><strong>Example — dev (broad):</strong></p>
                                    <pre style="white-space:pre-wrap"><code>{
  "expansion_enable": true,
  "expansion_candidates_cap": 80,
  "expansion_token_filter": false,
  "expand_max_depth": 2,
  "expand_related_limit_per_seed": 50,
  "suggest_enable": true,
  "suggest_limit_per_seed": 20,
  "max_total_keywords_per_cat": 800
}</code></pre>
                                </div>
                            </details>
                            <details style="margin-top:6px">
                                <summary><strong>Batching, caps & budgets</strong></summary>
                                <div style="margin-top:8px; max-width:860px">
                                    <ul style="list-style:disc; margin-left:20px">
                                        <li><code>max_cats_per_run</code>, <code>max_tasks_per_run</code>: safety caps for a single refresh run.</li>
                                        <li><code>ideas_seeds_per_task</code> (max 20), <code>volume_keywords_per_task</code> (max 1000): batching sizes per DataForSEO limits.</li>
                                        <li><code>budget_cap_credits</code>: soft ceiling for estimated credits per run; the run will bail before exceeding it.</li>
                                        <li><code>forecast_enable</code> (bool): enable Google Ads forecasts (async only). Costs extra.</li>
                                        <li><code>min_ads_volume</code>, <code>max_ads_per_cat</code>: filters for the Ads candidates table in the product editor.</li>
                                        <li><code>paa_max_seeds_per_cat</code>: number of PAA (FAQ) seeds to post per category (default 1 to keep costs low); results are visible in the Category admin preview (not auto‑injected on front‑end).</li>
                                    </ul>
                                    <p style="margin-top:6px"><strong>Example — production (cost‑controlled):</strong></p>
                                    <pre style="white-space:pre-wrap"><code>{
  "max_cats_per_run": 50,
  "max_tasks_per_run": 1000,
  "budget_cap_credits": 300,
  "ideas_seeds_per_task": 20,
  "volume_keywords_per_task": 1000,
  "forecast_enable": false,
  "min_ads_volume": 50,
  "max_ads_per_cat": 50,
  "paa_max_seeds_per_cat": 1
}</code></pre>
                                </div>
                            </details>
                            <details style="margin-top:6px">
                                <summary><strong>Follow‑ups gating & poll throttle</strong></summary>
                                <div style="margin-top:8px; max-width:860px">
                                    <p>Use this to let upstream tasks "settle" and run big, single follow‑up batches later (saves credits), and to reduce polling frequency.</p>
                                    <ul style="list-style:disc; margin-left:20px">
                                        <li><code>followups_mode</code>: <code>"immediate"</code> (run follow‑ups as soon as Ideas complete) or <code>"gated"</code> (wait until most Ideas are done or timeout).</li>
                                        <li><code>settle_hours</code> (int): maximum wait window for the gate (e.g., 24–48). After this, follow‑ups run even if some Ideas stragglers remain.</li>
                                        <li><code>min_ready_ratio</code> (0..1): run early once ≥ this fraction of Ideas tasks are completed for the term/lang (e.g., 0.9).</li>
                                        <li><code>poll_min_interval_minutes</code> (int): throttle for the 5‑minute poller; when set (e.g., 600 = 10h), poll cycles are skipped until this interval elapses.</li>
                                    </ul>
                                    <p><strong>Example — slow & cheap (batch late):</strong></p>
                                    <pre style="white-space:pre-wrap"><code>{
  "followups_mode": "gated",
  "settle_hours": 48,
  "min_ready_ratio": 0.95,
  "poll_min_interval_minutes": 600
}</code></pre>
                                </div>
                            </details>
                            <details style="margin-top:6px">
                                <summary><strong>Comprehensive JSON key reference</strong></summary>
                                <div style="margin-top:8px; max-width:980px">
                                    <p>All keys are optional; unspecified keys fall back to sensible defaults. Values shown in parentheses are types and ranges.</p>

                                    <h4>Delivery modes (string: "live" | "async")</h4>
                                    <ul style="list-style:disc; margin-left:20px">
                                        <li><code>ideas_mode</code> — Google Ads keyword ideas; async recommended for bulk (≤ 20 seeds per task).</li>
                                        <li><code>related_mode</code> — DataForSEO Labs related keywords; Labs has no async <code>task_post</code> so use <code>"live"</code>. Your code live‑batches many seeds per POST.</li>
                                        <li><code>volume_mode</code> — Google Ads search volume; async recommended (≤ 1000 keywords per task).</li>
                                        <li><code>forecast_mode</code> — Ads forecasts; async only.</li>
                                        <li><code>paa_mode</code> — SERP People Also Ask; async only.</li>
                                    </ul>

                                    <h4>Global batching (aggregates across many categories/languages)</h4>
                                    <ul style="list-style:disc; margin-left:20px">
                                        <li><code>global_batching_enable</code> (bool) — enable cross‑term aggregation. Default: <code>true</code>.</li>
                                        <li><code>tasks_per_http_post</code> (int 1..100) — max items per HTTP POST for batch endpoints. Default: 100.</li>
                                        <li><code>global_flush_after_minutes</code> (int) — reserved; batches flush at request end and after each poll cycle.</li>
                                    </ul>

                                    <h4>Smart Fill (top‑up in the same run; no re‑posting Ideas)</h4>
                                    <ul style="list-style:disc; margin-left:20px">
                                        <li><code>smart_fill_enable</code> (bool) — run expand→fill cycles during the same refresh. Default: <code>true</code>.</li>
                                        <li><code>target_keywords_per_cat</code> (int ≥ 1) — top‑up goal per category+language. Default: 300.</li>
                                        <li><code>max_smart_cycles_per_run</code> (int ≥ 1) — how many expand→fill iterations per run. Default: 2.</li>
                                    </ul>

                                    <h4>Expansion & long‑tail</h4>
                                    <ul style="list-style:disc; margin-left:20px">
                                        <li><code>expansion_enable</code> (bool) — turn Labs related expansion on/off. Default: <code>true</code>.</li>
                                        <li><code>expansion_candidates_cap</code> (int ≥ 1) — how many top ideas/seeds to expand per cycle. Default: 60.</li>
                                        <li><code>expansion_token_filter</code> (bool) — only expand ideas containing a token from the category name. Default: <code>true</code>.</li>
                                        <li><code>expand_max_depth</code> (1|2) — 1 = only expand ideas; 2 = may expand top related outputs (depth 2). Default: 1.</li>
                                        <li><code>expand_related_limit_per_seed</code> (int 1..100) — items per seed for Labs related. Default: 20 (we suggest 30–50 for breadth).</li>
                                        <li><code>suggest_enable</code> (bool) — include Labs search suggestions (autocomplete). Default: <code>false</code>.</li>
                                        <li><code>suggest_limit_per_seed</code> (int) — suggestions per seed. Default: 20.</li>
                                        <li><code>max_total_keywords_per_cat</code> (int) — hard cap of saved keywords per category+language. Default: 500.</li>
                                    </ul>

                                    <h4>Batching sizes (per task)</h4>
                                    <ul style="list-style:disc; margin-left:20px">
                                        <li><code>ideas_seeds_per_task</code> (int 1..20) — seeds per Ideas task. Default: 20.</li>
                                        <li><code>volume_keywords_per_task</code> (int 1..1000) — keywords per Volume task. Default: 1000.</li>
                                        <li><em>Note:</em> The aggregator additionally groups up to <code>tasks_per_http_post</code> tasks into one HTTP POST.</li>
                                    </ul>

                                    <h4>Caps & budgets</h4>
                                    <ul style="list-style:disc; margin-left:20px">
                                        <li><code>max_cats_per_run</code> (int) — limit categories processed per refresh. Default: 50.</li>
                                        <li><code>max_tasks_per_run</code> (int) — limit tasks posted per refresh. Default: 1000.</li>
                                        <li><code>budget_cap_credits</code> (int) — soft ceiling for estimated credits; the run will bail before exceeding it. Default: 1000.</li>
                                    </ul>

                                    <h4>Follow‑ups gating & poll throttle</h4>
                                    <ul style="list-style:disc; margin-left:20px">
                                        <li><code>followups_mode</code> ("immediate"|"gated") — run follow‑ups now or after settling. Default: <code>"immediate"</code>.</li>
                                        <li><code>settle_hours</code> (int ≥ 1) — max wait window for a gated run. Default: 24.</li>
                                        <li><code>min_ready_ratio</code> (0..1) — open the gate once ≥ this fraction of Ideas are complete. Default: 0.9.</li>
                                        <li><code>poll_min_interval_minutes</code> (int ≥ 0) — skip poll cycles until this interval elapses. Default: 600 (10h).</li>
                                    </ul>

                                    <h4>PAA & Forecasts</h4>
                                    <ul style="list-style:disc; margin-left:20px">
                                        <li><code>paa_max_seeds_per_cat</code> (int) — SERP PAA seeds per category; async only. Default: 1.</li>
                                        <li><code>forecast_enable</code> (bool) — enable Ads forecasts (async). Default: <code>false</code>.</li>
                                    </ul>

                                    <h4>Profiles you can start with</h4>
                                    <pre style="white-space:pre-wrap"><code>// Fast + Batched (during sprints)
{
  "ideas_mode":"async","related_mode":"live","volume_mode":"async","paa_mode":"async",
  "global_batching_enable":true,"tasks_per_http_post":100,
  "smart_fill_enable":true,"target_keywords_per_cat":300,"max_smart_cycles_per_run":2,
  "expansion_enable":true,"expansion_candidates_cap":60,"expansion_token_filter":true,
  "expand_related_limit_per_seed":50,
  "followups_mode":"gated","settle_hours":3,"min_ready_ratio":0.95,
  "poll_min_interval_minutes":0,
  "ideas_seeds_per_task":20,"volume_keywords_per_task":1000,
  "max_cats_per_run":200,"max_tasks_per_run":5000,
  "paa_max_seeds_per_cat":1
}

// Cost‑controlled (batch late)
{
  "ideas_mode":"async","related_mode":"live","volume_mode":"async","paa_mode":"async",
  "global_batching_enable":true,"tasks_per_http_post":60,
  "smart_fill_enable":true,"target_keywords_per_cat":200,"max_smart_cycles_per_run":1,
  "expansion_enable":true,"expansion_candidates_cap":30,"expansion_token_filter":true,
  "expand_related_limit_per_seed":30,
  "followups_mode":"gated","settle_hours":24,"min_ready_ratio":0.95,
  "poll_min_interval_minutes":15,
  "ideas_seeds_per_task":20,"volume_keywords_per_task":1000,
  "max_cats_per_run":50,"max_tasks_per_run":600,
  "paa_max_seeds_per_cat":1
}</code></pre>
                                </div>
                            </details>
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
        // Saved keywords preview per language (top 100)
        global $wpdb; $tk = self::table_keywords();
        echo '<div style="margin-top:12px">';
        echo '<details open><summary><strong>Saved keywords preview</strong> <span style="color:#888">(per language)</span></summary>';
        foreach ($langs as $lang){
            $tid_lang = self::translate_term_id($tid, $lang);
            $count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tk} WHERE term_id=%d AND lang=%s", $tid_lang, $lang));
            echo '<div style="margin:8px 0 16px">';
            echo '<h4 style="margin:4px 0">'.esc_html(strtoupper($lang)).' — '.number_format_i18n($count).' items</h4>';
            if ($count === 0){
                echo '<em>No saved keywords yet for this language.</em>';
            } else {
                $rows = $wpdb->get_results($wpdb->prepare("SELECT keyword, source, COALESCE(volume,0) vol, COALESCE(intent_main,'') intent, updated_at FROM {$tk} WHERE term_id=%d AND lang=%s ORDER BY vol DESC, updated_at DESC LIMIT 100", $tid_lang, $lang), ARRAY_A);
                echo '<table class="widefat fixed" style="font-size:12px"><thead><tr><th>Keyword</th><th>Vol</th><th>Intent</th><th>Source</th><th>Updated</th></tr></thead><tbody>';
                foreach ($rows as $r){
                    echo '<tr>';
                    echo '<td>'.esc_html($r['keyword']).'</td>';
                    echo '<td>'.esc_html((string)$r['vol']).'</td>';
                    echo '<td>'.esc_html((string)$r['intent']).'</td>';
                    echo '<td>'.esc_html((string)$r['source']).'</td>';
                    echo '<td>'.esc_html($r['updated_at']).'</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
                $logs_url = admin_url('admin.php?page=ds-keywords-logs&term_id='.(int)$tid_lang.'&lang='.rawurlencode($lang));
                echo '<p><a class="button" href="'.esc_url($logs_url).'">Open logs for this term/lang</a></p>';
            }
            echo '</div>';
        }
        echo '</details>';
        echo '</div>';

        // FAQs (PAA) preview
        $tf = self::table_faq();
        echo '<div style="margin-top:12px">';
        echo '<details><summary><strong>Saved FAQs (PAA) preview</strong> <span style="color:#888">(per language)</span></summary>';
        foreach ($langs as $lang){
            $tid_lang = self::translate_term_id($tid, $lang);
            $faq_count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tf} WHERE term_id=%d AND lang=%s", $tid_lang, $lang));
            echo '<div style="margin:8px 0 16px">';
            echo '<h4 style="margin:4px 0">'.esc_html(strtoupper($lang)).' — '.number_format_i18n($faq_count).' Q/A</h4>';
            if ($faq_count === 0){
                echo '<em>No FAQs saved yet for this language.</em>';
            } else {
                $rows = $wpdb->get_results($wpdb->prepare("SELECT question, COALESCE(answer,'') answer, updated_at FROM {$tf} WHERE term_id=%d AND lang=%s ORDER BY updated_at DESC LIMIT 30", $tid_lang, $lang), ARRAY_A);
                echo '<table class="widefat fixed" style="font-size:12px"><thead><tr><th style="width:55%">Question</th><th>Answer (snippet)</th><th>Updated</th></tr></thead><tbody>';
                foreach ($rows as $r){
                    $ans = $r['answer']; if (mb_strlen($ans) > 140) { $ans = mb_substr($ans, 0, 140).'…'; }
                    echo '<tr>';
                    echo '<td>'.esc_html($r['question']).'</td>';
                    echo '<td>'.esc_html($ans).'</td>';
                    echo '<td>'.esc_html($r['updated_at']).'</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
                $logs_url = admin_url('admin.php?page=ds-keywords-logs&endpoint=serp%2Fgoogle%2Forganic&term_id='.(int)$tid_lang.'&lang='.rawurlencode($lang));
                echo '<p><a class="button" href="'.esc_url($logs_url).'">Open PAA logs for this term/lang</a></p>';
            }
            echo '</div>';
        }
        echo '</details>';
        echo '</div>';
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
        // Move from side to normal (full-width) and raise priority for a larger, more capable widget
        add_meta_box('ds_keywords_box', 'Keyword Suggestions', [__CLASS__,'render_metabox'], 'product', 'normal', 'high');
    }
    public static function render_metabox($post){
        $post_id = $post->ID;
        $lang = self::get_post_language($post_id);
        $term_ids = function_exists('wc_get_product_terms') ? wc_get_product_terms($post_id, 'product_cat', ['fields'=>'ids']) : [];
        global $wpdb; $t = self::table_keywords();
        $items = [];
        if ($lang && $term_ids){
            $in = implode(',', array_map('intval',$term_ids));
            $sql = $wpdb->prepare("SELECT keyword, source, volume, intent_main, cpc, competition FROM {$t} WHERE lang=%s AND term_id IN ($in) ORDER BY volume DESC LIMIT 150", $lang);
            $items = $wpdb->get_results($sql, ARRAY_A);
        }
        echo '<div style="overflow:auto">';
        if (!$items){ echo '<em>No suggestions yet for this product\'s language/categories.</em>'; }
        else {
            echo '<strong>Suggestions</strong>';
            echo '<table class="widefat fixed" style="font-size:12px"><thead><tr><th>Keyword</th><th>Vol</th><th>Intent</th><th>Source</th></tr></thead><tbody>';
            foreach ($items as $row){
                echo '<tr><td>'.esc_html($row['keyword']).'</td><td>'.esc_html((string)($row['volume']??'')) .'</td><td>'.esc_html((string)($row['intent_main']??'')) .'</td><td>'.esc_html((string)($row['source']??'')) .'</td></tr>';
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

        // FAQs (PAA) aggregated from this product's categories
        if ($lang && $term_ids){
            $tf = self::table_faq();
            $term_ids_lang = array_map(function($tid) use ($lang){ return self::translate_term_id((int)$tid, $lang); }, $term_ids);
            $term_ids_lang = array_unique(array_map('intval', $term_ids_lang));
            if (!empty($term_ids_lang)){
                $in = implode(',', $term_ids_lang);
                $rows = $wpdb->get_results($wpdb->prepare("SELECT question, COALESCE(answer,'') answer, updated_at FROM {$tf} WHERE lang=%s AND term_id IN ($in) ORDER BY updated_at DESC LIMIT 20", $lang), ARRAY_A);
                echo '<div style="margin-top:12px">';
                echo '<strong>FAQs (PAA)</strong>';
                if (!$rows){
                    echo '<br><em>No FAQs saved yet for this product\'s categories.</em>';
                } else {
                    echo '<table class="widefat fixed" style="font-size:12px"><thead><tr><th style="width:55%">Question</th><th>Answer (snippet)</th><th>Updated</th></tr></thead><tbody>';
                    foreach ($rows as $r){
                        $ans = $r['answer']; if (mb_strlen($ans) > 140) { $ans = mb_substr($ans, 0, 140).'…'; }
                        echo '<tr><td>'.esc_html($r['question']).'</td><td>'.esc_html($ans).'</td><td>'.esc_html($r['updated_at']).'</td></tr>';
                    }
                    echo '</tbody></table>';
                }
                echo '</div>';
            }
        }

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
        // Correlate this refresh run
        self::$run_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : ( 'run-'.uniqid('', true) );
        $auth = self::get_auth(); if (!$auth){ self::log_write('warn','cron_refresh: missing auth', ['run_tag'=>self::$run_id]); return; }
        $map = self::get_lang_map(); if (!$map){ self::log_write('warn','cron_refresh: missing language map', ['run_tag'=>self::$run_id]); return; }

        $limits = self::get_limits();
        $maxCatsPerRun  = (int)($limits['max_cats_per_run'] ?? 50);
        $maxTasksPerRun = (int)($limits['max_tasks_per_run'] ?? 1000);
        $seeds_per_task = max(1, (int)($limits['ideas_seeds_per_task'] ?? 20)); // DFSEO limit 20
        $max_per_batch_post = 100; // POST up to 100 tasks per HTTP call

        $catsProcessed = 0; $tasksPosted = 0; $seedsCount = 0;

        self::log_write('info','cron_refresh: start', ['action'=>'cron_refresh','response'=>['maxCats'=>$maxCatsPerRun,'maxTasks'=>$maxTasksPerRun]]);
        self::pipeline_log('refresh','start', null, null, ['maxCats'=>$maxCatsPerRun,'maxTasks'=>$maxTasksPerRun]);
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
                self::pipeline_log('seeds','built', $term_id_lang, $lang, ['count'=>count($seeds), 'examples'=>array_slice($seeds,0,3)]);

                $groups = array_chunk($seeds, $seeds_per_task);
                $ideas_mode = self::get_mode('ideas_mode');
                if ($ideas_mode === 'live'){
                    $saved_total = 0; $errors = 0; $posts = 0;
                    foreach ($groups as $group){
                        if ($tasksPosted >= $maxTasksPerRun) break;
                        $payload = [[
                            'keywords' => array_values($group),
                            'language_code' => $lang_code,
                            'location_code' => $loc_code,
                            'include_adult_keywords' => false,
                            'limit' => min(300, (int)$limits['max_ideas_per_cat'])
                        ]];
                        // Try reuse from logs to avoid re-calling live endpoint
                        $reuse_saved = self::reuse_ideas_from_logs($payload[0], $term_id_lang, $lang);
                        if ($reuse_saved > 0){
                            $saved_total += $reuse_saved; $posts++;
                            self::log_write('info','ideas live reuse hit', ['endpoint'=>'keywords_data/google_ads/keywords_for_keywords/live','action'=>'reuse','term_id'=>$term_id_lang,'lang'=>$lang,'response'=>['items'=>$reuse_saved]]);
                            self::maybe_trigger_followups($term_id_lang, $lang);
                            $tasksPosted++;
                            continue;
                        }
                        self::log_write('info','ideas live request', ['endpoint'=>'keywords_data/google_ads/keywords_for_keywords/live','action'=>'live','term_id'=>$term_id_lang,'lang'=>$lang,'request'=>$payload]);
                        list($json,$code,$err) = self::dfs_post('keywords_data/google_ads/keywords_for_keywords/live', $payload, $auth);
                        if ($err || $code < 200 || $code >= 300){
                            $errors++; self::log_write('warn','ideas live failed', ['endpoint'=>'keywords_data/google_ads/keywords_for_keywords/live','action'=>'live','term_id'=>$term_id_lang,'lang'=>$lang,'http_code'=>$code,'error'=>$err]);
                            continue;
                        }
                        // Support both shapes from DFSEO live: (a) result[0].items[], (b) result[] item nodes
                        $items = [];
                        if (isset($json['tasks'][0]['result'][0]['items']) && is_array($json['tasks'][0]['result'][0]['items'])) {
                            $items = $json['tasks'][0]['result'][0]['items'];
                        } elseif (isset($json['tasks'][0]['result']) && is_array($json['tasks'][0]['result'])) {
                            $res = $json['tasks'][0]['result'];
                            if (!empty($res) && isset($res[0]) && is_array($res[0]) && array_key_exists('keyword', $res[0])) {
                                $items = $res;
                            }
                        }
                        self::log_write('info','ideas live nodes', ['endpoint'=>'keywords_data/google_ads/keywords_for_keywords/live','action'=>'handle_result','term_id'=>$term_id_lang,'lang'=>$lang,'response'=>['nodes'=>is_array($items)?count($items):0]]);
                        $saved = self::save_ideas_items($items, $term_id_lang, $lang);
                        $saved_total += $saved; $posts++;
                        self::log_write('info','ideas live saved', ['endpoint'=>'keywords_data/google_ads/keywords_for_keywords/live','action'=>'handle_result','term_id'=>$term_id_lang,'lang'=>$lang,'response'=>['items'=>$saved]]);
                        if ($saved > 0){ self::maybe_trigger_followups($term_id_lang, $lang); }
                        $tasksPosted++;
                    }
                    if (!empty($groups)){
                        $catsProcessed++;
                        update_term_meta($term_id_lang, 'ds_kw_last_refresh_'.$lang, $now);
                    }
                } else {
                    foreach ($groups as $group){
                        if ($tasksPosted >= $maxTasksPerRun) break;
                        $payload_task = [
                            'keywords' => array_values($group),
                            'language_code' => $lang_code,
                            'location_code' => $loc_code,
                            'include_adult_keywords' => false,
                            'limit' => min(300, (int)$limits['max_ideas_per_cat'])
                        ];
                        // Try reuse from logs to avoid posting duplicate async tasks
                        $reuse_saved = self::reuse_ideas_from_logs($payload_task, $term_id_lang, $lang);
                        if ($reuse_saved > 0){
                            self::log_write('info','ideas async reuse hit', ['endpoint'=>'keywords_data/google_ads/keywords_for_keywords/live','action'=>'reuse','term_id'=>$term_id_lang,'lang'=>$lang,'response'=>['items'=>$reuse_saved]]);
                            self::maybe_trigger_followups($term_id_lang, $lang);
                            continue;
                        }
                        $tasks[] = $payload_task;
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
        }

        if ($tasks){
            self::dfs_post_tasks('keywords_data/google_ads/keywords_for_keywords/task_post', $tasks, $ctxs, $auth);
            $tasksPosted += count($tasks);
        }

        self::log_write('info','cron_refresh: done', ['action'=>'cron_refresh','response'=>['catsProcessed'=>$catsProcessed,'tasksPosted'=>$tasksPosted,'seeds'=>$seedsCount]]);
    }

    public static function cron_poll(){
        // Prevent overlapping poll runs
        $lock_key = 'ds_kw_poll_lock';
        if (get_transient($lock_key)) { self::log_write('info','cron_poll: skip (locked)', ['action'=>'cron_poll']); return; }
        set_transient($lock_key, 1, 60);
        try {
            $auth = self::get_auth(); if (!$auth){ self::log_write('warn','cron_poll: missing auth'); return; }
            // Throttle polling to reduce API GETs (free but noisy); user can set to ~10h
            $limits = self::get_limits();
            $min_minutes = max(0, (int)($limits['poll_min_interval_minutes'] ?? 0));
            if ($min_minutes > 0){
                $last = (int) get_option('ds_kw_last_poll_unix', 0);
                $nowu = time();
                if ($last > 0 && ($nowu - $last) < ($min_minutes * 60)){
                    self::log_write('info','cron_poll: skip (throttled)', ['action'=>'cron_poll','message'=>'poll_skip (throttled)','response'=>['since_sec'=>$nowu-$last,'min_interval_min'=>$min_minutes]]);
                    return;
                }
                update_option('ds_kw_last_poll_unix', $nowu);
            }
            self::log_write('info','cron_poll: start', ['action'=>'cron_poll']);
            // Forecasts (optional)
            $should_poll_forecast = !empty($limits['forecast_enable']);
            $ideas_mode = self::get_mode('ideas_mode');
            $related_mode = self::get_mode('related_mode');
            $volume_mode = self::get_mode('volume_mode');
            $forecast_mode = self::get_mode('forecast_mode');
            $paa_mode = self::get_mode('paa_mode');

            // Ideas
            if (!($ideas_mode === 'live' && !self::queue_has_posted('keywords_data/google_ads/keywords_for_keywords'))){
                self::poll_endpoint_ready('keywords_data/google_ads/keywords_for_keywords', $auth, function($result){ self::handle_ideas_result($result); });
            } else {
                self::log_write('info','poll_skip (live mode, no posted tasks)', ['endpoint'=>'keywords_data/google_ads/keywords_for_keywords','action'=>'poll_skip']);
            }
            // Related (Labs live-only effectively)
            if (!($related_mode === 'live' && !self::queue_has_posted('dataforseo_labs/google/related_keywords'))){
                self::poll_endpoint_ready('dataforseo_labs/google/related_keywords', $auth, function($result){ self::handle_related_result($result); });
            } else {
                self::log_write('info','poll_skip (live mode, no posted tasks)', ['endpoint'=>'dataforseo_labs/google/related_keywords','action'=>'poll_skip']);
            }
            // Volume
            if (!($volume_mode === 'live' && !self::queue_has_posted('keywords_data/google_ads/search_volume'))){
                self::poll_endpoint_ready('keywords_data/google_ads/search_volume', $auth, function($result){ self::handle_volume_result($result); });
            } else {
                self::log_write('info','poll_skip (live mode, no posted tasks)', ['endpoint'=>'keywords_data/google_ads/search_volume','action'=>'poll_skip']);
            }
            // PAA
            if (!($paa_mode === 'live' && !self::queue_has_posted('serp/google/organic'))){
                self::poll_endpoint_ready('serp/google/organic', $auth, function($result){ self::handle_paa_result($result); });
            } else {
                self::log_write('info','poll_skip (live mode, no posted tasks)', ['endpoint'=>'serp/google/organic','action'=>'poll_skip']);
            }
            // Forecasts (optional)
            if ($should_poll_forecast){
                if (!($forecast_mode === 'live' && !self::queue_has_posted('keywords_data/google_ads/ad_traffic_by_keywords'))){
                    self::poll_endpoint_ready('keywords_data/google_ads/ad_traffic_by_keywords', $auth, function($result){ self::handle_forecast_result($result); });
                } else {
                    self::log_write('info','poll_skip (live mode, no posted tasks)', ['endpoint'=>'keywords_data/google_ads/ad_traffic_by_keywords','action'=>'poll_skip']);
                }
            }
            // Flush any aggregated batches now
            self::agg_flush_all($auth);
            self::log_write('info','cron_poll: done', ['action'=>'cron_poll']);
        } catch (\Throwable $e) {
            self::log_write('error','cron_poll crashed', ['endpoint'=>'ds-pipeline','action'=>'poll:crash','error'=>$e->getMessage()]);
        } finally {
            delete_transient($lock_key);
        }
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
        $len = (int) get_option(self::OPT_LOG_SNIPPET, 2000);
        if ($len < 200) $len = 200; if ($len > 20000) $len = 20000;
        return substr($s, 0, $len);
    }

    // ----- Pipeline logging (structured) -----
    private static function pipeline_log($stage, $status, $term_id = null, $lang = null, $extra = [], $cycle = 0){
        // Keep existing logging system; this adds structured entries under endpoint 'ds-pipeline'
        $action = trim((string)$stage).':'.trim((string)$status);
        $resp = is_array($extra) ? $extra : ['note'=>(string)$extra];
        if ($cycle !== null) { $resp['cycle'] = (int)$cycle; }
        self::log_write('info', 'pipeline', [
            'endpoint' => 'ds-pipeline',
            'action'   => $action,
            'term_id'  => $term_id ? (int)$term_id : null,
            'lang'     => $lang ? (string)$lang : null,
            'response' => $resp,
            'run_tag'  => self::$run_id
        ]);
    }

    // ----- Live response reuse from logs (last 2 days) -----
    private static function find_recent_live_response_from_logs($endpoint_live, array $signatures){
        // Returns decoded JSON of the response if found, else null
        global $wpdb; $t = self::table_logs();
        $since = gmdate('Y-m-d H:i:s', time() - 2*DAY_IN_SECONDS);
        // Get recent successful response entries for this endpoint
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, ts, response_json_snippet FROM {$t} WHERE endpoint=%s AND action='response' AND http_code BETWEEN 200 AND 299 AND response_json_snippet IS NOT NULL AND ts >= %s ORDER BY id DESC LIMIT 200",
            $endpoint_live, $since
        ), ARRAY_A);
        if (!$rows) return null;
        foreach ($rows as $resp){
            $resp_id = (int)$resp['id']; $resp_ts = $resp['ts'];
            // Look back up to 30 minutes for a matching request entry for the same endpoint
            $reqs = $wpdb->get_results($wpdb->prepare(
                "SELECT id, request_json_snippet FROM {$t} WHERE endpoint=%s AND action='request' AND id <= %d AND ts >= DATE_SUB(%s, INTERVAL 30 MINUTE) ORDER BY id DESC LIMIT 50",
                $endpoint_live, $resp_id, $resp_ts
            ), ARRAY_A);
            if (!$reqs) continue;
            foreach ($reqs as $req){
                $ok = true; $snippet = (string)$req['request_json_snippet'];
                foreach ($signatures as $sig){ if ($sig!=='' && strpos($snippet, $sig) === false){ $ok=false; break; } }
                if ($ok){
                    $json = json_decode((string)$resp['response_json_snippet'], true);
                    if (is_array($json)) return $json;
                }
            }
        }
        return null;
    }

    private static function reuse_ideas_from_logs($payload_task, $term_id, $lang){
        $keywords = isset($payload_task['keywords']) && is_array($payload_task['keywords']) ? $payload_task['keywords'] : [];
        $lang_code = isset($payload_task['language_code']) ? (string)$payload_task['language_code'] : '';
        $loc_code  = isset($payload_task['location_code']) ? (string)$payload_task['location_code'] : '';
        $sigs = array_slice(array_values(array_unique(array_filter(array_map('strval',$keywords)))), 0, 3);
        if ($lang_code!=='') $sigs[] = '"language_code":"'.$lang_code.'"';
        if ($loc_code!=='')  $sigs[] = '"location_code":'.$loc_code;
        $json = self::find_recent_live_response_from_logs('keywords_data/google_ads/keywords_for_keywords/live', $sigs);
        if (!$json) return 0;
        $items = [];
        if (isset($json['tasks'][0]['result'][0]['items']) && is_array($json['tasks'][0]['result'][0]['items'])){
            $items = $json['tasks'][0]['result'][0]['items'];
        } elseif (isset($json['tasks'][0]['result']) && is_array($json['tasks'][0]['result'])){
            $res = $json['tasks'][0]['result'];
            if (!empty($res) && isset($res[0]) && is_array($res[0]) && array_key_exists('keyword',$res[0])){
                $items = $res;
            }
        }
        $saved = self::save_ideas_items($items, $term_id, $lang);
        if ($saved>0){ self::log_write('info','reuse ideas from logs', ['endpoint'=>'keywords_data/google_ads/keywords_for_keywords/live','action'=>'reuse','term_id'=>$term_id,'lang'=>$lang,'response'=>['items'=>$saved]]); }
        return $saved;
    }

    private static function reuse_related_from_logs($payload_task, $term_id, $lang){
        $kw = isset($payload_task['keyword']) ? (string)$payload_task['keyword'] : '';
        $lang_code = isset($payload_task['language_code']) ? (string)$payload_task['language_code'] : '';
        $loc_code  = isset($payload_task['location_code']) ? (string)$payload_task['location_code'] : '';
        $sigs = [];
        if ($kw!=='') $sigs[] = '"keyword":"'.$kw.'"';
        if ($lang_code!=='') $sigs[] = '"language_code":"'.$lang_code+'"';
        if ($loc_code!=='')  $sigs[] = '"location_code":'.$loc_code;
        $json = self::find_recent_live_response_from_logs('dataforseo_labs/google/related_keywords/live', $sigs);
        if (!$json) return 0;
        $items = isset($json['tasks'][0]['result'][0]['items']) && is_array($json['tasks'][0]['result'][0]['items']) ? $json['tasks'][0]['result'][0]['items'] : [];
        $norm = [];
        foreach ($items as $it){
            if (isset($it['keyword_data'])){
                $kd = $it['keyword_data'];
                $kw2 = isset($kd['keyword']) ? (string)$kd['keyword'] : '';
                $info = (isset($kd['keyword_info']) && is_array($kd['keyword_info'])) ? $kd['keyword_info'] : [];
                $norm[] = array_merge(['keyword'=>$kw2], $info);
            } elseif (isset($it['keyword'])) {
                $norm[] = $it;
            }
        }
        $saved = self::save_related_items($norm, $term_id, $lang);
        if ($saved>0){ self::log_write('info','reuse related from logs', ['endpoint'=>'dataforseo_labs/google/related_keywords/live','action'=>'reuse','term_id'=>$term_id,'lang'=>$lang,'response'=>['items'=>$saved]]); }
        return $saved;
    }

    private static function reuse_volume_from_logs($payload_task, $term_id, $lang){
        $keywords = isset($payload_task['keywords']) && is_array($payload_task['keywords']) ? $payload_task['keywords'] : [];
        $lang_code = isset($payload_task['language_code']) ? (string)$payload_task['language_code'] : '';
        $loc_code  = isset($payload_task['location_code']) ? (string)$payload_task['location_code'] : '';
        $sigs = array_slice(array_values(array_unique(array_filter(array_map('strval',$keywords)))), 0, 3);
        if ($lang_code!=='') $sigs[] = '"language_code":"'.$lang_code.'"';
        if ($loc_code!=='')  $sigs[] = '"location_code":'.$loc_code;
        $json = self::find_recent_live_response_from_logs('keywords_data/google_ads/search_volume/live', $sigs);
        if (!$json) return 0;
        $items = [];
        if (isset($json['tasks'][0]['result'][0]['items']) && is_array($json['tasks'][0]['result'][0]['items'])){
            $items = $json['tasks'][0]['result'][0]['items'];
        } elseif (isset($json['tasks'][0]['result']) && is_array($json['tasks'][0]['result'])){
            $res = $json['tasks'][0]['result'];
            if (!empty($res) && isset($res[0]) && is_array($res[0]) && array_key_exists('keyword',$res[0])){
                $items = $res;
            }
        }
        $saved = self::save_volume_items($items, $term_id, $lang);
        if ($saved>0){ self::log_write('info','reuse volume from logs', ['endpoint'=>'keywords_data/google_ads/search_volume/live','action'=>'reuse','term_id'=>$term_id,'lang'=>$lang,'response'=>['items'=>$saved]]); }
        return $saved;
    }

    private static function reuse_intent_from_logs($payload_task, $term_id, $lang){
        $keywords = isset($payload_task['keywords']) && is_array($payload_task['keywords']) ? $payload_task['keywords'] : [];
        $lang_code = isset($payload_task['language_code']) ? (string)$payload_task['language_code'] : '';
        $loc_code  = isset($payload_task['location_code']) ? (string)$payload_task['location_code'] : '';
        $sigs = array_slice(array_values(array_unique(array_filter(array_map('strval',$keywords)))), 0, 3);
        if ($lang_code!=='') $sigs[] = '"language_code":"'.$lang_code.'"';
        if ($loc_code!=='')  $sigs[] = '"location_code":'.$loc_code;
        $json = self::find_recent_live_response_from_logs('dataforseo_labs/google/search_intent/live', $sigs);
        if (!$json) return 0;
        $items = isset($json['tasks'][0]['result'][0]['items']) ? $json['tasks'][0]['result'][0]['items'] : [];
        if (!is_array($items)) $items = [];
        self::handle_intent_items($items, $term_id, $lang);
        $saved = is_array($items) ? count($items) : 0;
        if ($saved>0){ self::log_write('info','reuse intent from logs', ['endpoint'=>'dataforseo_labs/google/search_intent/live','action'=>'reuse','term_id'=>$term_id,'lang'=>$lang,'response'=>['items'=>$saved]]); }
        return $saved;
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
                'ideas_seeds_per_task'=>20,'volume_keywords_per_task'=>1000,'paa_max_seeds_per_cat'=>1,
                // Expansion knobs
                'expansion_candidates_cap' => 20,
                'expansion_token_filter' => true,
                'expand_max_depth' => 1, // 1 = only expand ideas; 2 = also expand top related outputs
                'expand_related_limit_per_seed' => 20, // DFSEO Labs related limit per seed
                'suggest_enable' => false, // use Labs search_suggestions
                'suggest_limit_per_seed' => 20,
                'max_total_keywords_per_cat' => 500,
                // Smart fill knobs
                'smart_fill_enable' => true,
                'target_keywords_per_cat' => 300,
                'max_smart_cycles_per_run' => 2,
                // Modes: 'async' (post & poll) or 'live' (immediate)
                'ideas_mode' => 'async',
                'related_mode' => 'async',
                'volume_mode' => 'async',
                'forecast_mode' => 'async',
                'paa_mode' => 'async',
                // Follow-ups gating & polling throttle
                'followups_mode' => 'immediate', // 'immediate' or 'gated'
                'settle_hours' => 24,
                'min_ready_ratio' => 0.9,
                'poll_min_interval_minutes' => 600, // 10 hours
                // Global batching aggregator
                'global_batching_enable' => true,
                'tasks_per_http_post' => 100, // max 100 per DFSEO POST
                'global_flush_after_minutes' => 20
        ];
        return array_replace($def, $arr);
    }

    // ----- Global batching aggregator (buffers + flush) -----
    private static $agg_related = [];
    private static $agg_related_ctx = [];
    private static $agg_volume_tasks = [];
    private static $agg_volume_ctx = [];

    private static function agg_tasks_per_http_post(){
        $limits = self::get_limits();
        $n = isset($limits['tasks_per_http_post']) ? (int)$limits['tasks_per_http_post'] : 100;
        if ($n < 1) $n = 1; if ($n > 100) $n = 100; return $n;
    }
    private static function agg_enabled(){
        $limits = self::get_limits();
        return !empty($limits['global_batching_enable']);
    }

    private static function agg_add_related($payload_item, $term_id, $lang){
        if (!self::agg_enabled()) return false;
        self::$agg_related[] = $payload_item;
        self::$agg_related_ctx[] = ['term_id'=>$term_id,'lang'=>$lang];
        return true;
    }
    private static function agg_flush_related($auth){
        if (empty(self::$agg_related)) return;
        $max = self::agg_tasks_per_http_post();
        while (!empty(self::$agg_related)){
            $batch = array_splice(self::$agg_related, 0, $max);
            $ctxs  = array_splice(self::$agg_related_ctx, 0, $max);
            self::log_write('info','related live batch request', ['endpoint'=>'dataforseo_labs/google/related_keywords/live','action'=>'live','request'=>$batch]);
            list($json,$code,$err) = self::dfs_post('dataforseo_labs/google/related_keywords/live', $batch, $auth);
            if ($err || $code < 200 || $code >= 300){
                self::log_write('warn','related live batch failed', ['endpoint'=>'dataforseo_labs/google/related_keywords/live','action'=>'live','http_code'=>$code,'error'=>$err,'response'=>$json]);
                continue;
            }
            $tasks_arr = is_array($json['tasks'] ?? null) ? $json['tasks'] : [];
            $normalized_total = 0; $nodes_total = 0; $saved_batch = 0;
            foreach ($tasks_arr as $idx=>$task){
                $items = (isset($task['result'][0]['items']) && is_array($task['result'][0]['items'])) ? $task['result'][0]['items'] : [];
                $nodes_total += is_array($items)?count($items):0;
                $norm = [];
                foreach ($items as $it){
                    if (isset($it['keyword_data'])){
                        $kd = $it['keyword_data'];
                        $kw2 = isset($kd['keyword']) ? (string)$kd['keyword'] : '';
                        $info = (isset($kd['keyword_info']) && is_array($kd['keyword_info'])) ? $kd['keyword_info'] : [];
                        $norm[] = array_merge(['keyword'=>$kw2], $info);
                    } elseif (isset($it['keyword'])) {
                        $norm[] = $it;
                    }
                }
                $normalized_total += count($norm);
                $ctx = $ctxs[$idx] ?? null;
                if ($ctx && !empty($norm)){
                    $saved_now = self::save_related_items($norm, (int)$ctx['term_id'], (string)$ctx['lang']);
                    $saved_batch += $saved_now;
                }
            }
            self::log_write('info','related live batch saved', ['endpoint'=>'dataforseo_labs/google/related_keywords/live','action'=>'handle_result','response'=>['nodes'=>$nodes_total,'normalized'=>$normalized_total,'items_saved'=>$saved_batch]]);
        }
    }

    private static function agg_add_volume_task($payload_task, $ctx){
        if (!self::agg_enabled()) return false;
        self::$agg_volume_tasks[] = $payload_task;
        self::$agg_volume_ctx[] = $ctx;
        return true;
    }
    private static function agg_flush_volume($auth){
        if (empty(self::$agg_volume_tasks)) return;
        $max = self::agg_tasks_per_http_post();
        while (!empty(self::$agg_volume_tasks)){
            $tasks = array_splice(self::$agg_volume_tasks, 0, $max);
            $ctxs  = array_splice(self::$agg_volume_ctx, 0, $max);
            self::dfs_post_tasks('keywords_data/google_ads/search_volume/task_post', $tasks, $ctxs, $auth);
        }
    }

    private static function agg_flush_all($auth){
        if (!self::agg_enabled()) return;
        self::agg_flush_related($auth);
        self::agg_flush_volume($auth);
    }

    private static $agg_shutdown_registered = false;
    private static function agg_register_shutdown_once($auth){
        if (!self::agg_enabled()) return;
        if (self::$agg_shutdown_registered) return;
        self::$agg_shutdown_registered = true;
        // Capture current auth for shutdown closure
        $auth_copy = $auth;
        add_action('shutdown', function() use ($auth_copy){
            // Final flush of all aggregated endpoints
            DS_Keywords::agg_flush_all($auth_copy);
        }, 1);
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

    private static function queue_has_posted($endpoint_base){
        global $wpdb; $tq = self::table_queue();
        $cnt = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tq} WHERE endpoint=%s AND status='posted'", $endpoint_base));
        return $cnt > 0;
    }

    private static function poll_endpoint_ready($endpoint_base, $auth, $handler){
        // SERP uses a consolidated /serp/tasks_ready endpoint.
        $tasks_ready_path = (strpos($endpoint_base, 'serp/') === 0)
                ? 'serp/tasks_ready'
                : $endpoint_base . '/tasks_ready';

        // Ask DataForSEO which tasks are ready. If this fails or returns 0, we will still fall back to our queue.
        list($ready,$code,$err) = self::dfs_get($tasks_ready_path, $auth);
        $http_ok = ($code >= 200 && $code < 300);
        if (!$http_ok){
            self::log_write('warn','tasks_ready failed', ['endpoint'=>$endpoint_base,'action'=>'tasks_ready','http_code'=>$code,'error'=>$err]);
        }

        $ready_tasks = [];
        if ($http_ok && isset($ready['tasks']) && is_array($ready['tasks'])){
            foreach ($ready['tasks'] as $t){
                if (!empty($t['result'])){
                    foreach ($t['result'] as $r){ if (!empty($r['id'])) $ready_tasks[] = $r['id']; }
                }
            }
        }
        self::log_write('info','tasks_ready count: '.count($ready_tasks), ['endpoint'=>$endpoint_base,'action'=>'tasks_ready','response'=>['count'=>count($ready_tasks)]]);

        // If DataForSEO doesn't return ready ids here (or tasks_ready failed), fall back to our own queue of posted tasks
        if (!$ready_tasks){
            global $wpdb; $tq = self::table_queue();
            // Try a small batch of oldest posted tasks for this endpoint (older than 1 minute)
            $fallback = $wpdb->get_col($wpdb->prepare(
                "SELECT task_id FROM {$tq} WHERE endpoint=%s AND status='posted' AND created_at < DATE_SUB(NOW(), INTERVAL 1 MINUTE) ORDER BY created_at ASC LIMIT 20",
                $endpoint_base
            ));
            if ($fallback){
                self::log_write('info','fallback task_get from queue', ['endpoint'=>$endpoint_base,'action'=>'tasks_fallback','response'=>['count'=>count($fallback),'task_ids'=>$fallback]]);
                foreach ($fallback as $tid){
                    $get_path = (strpos($endpoint_base,'serp/google/organic')===0)
                        ? $endpoint_base.'/task_get/advanced/'.rawurlencode($tid)
                        : $endpoint_base.'/task_get/'.rawurlencode($tid);
                    list($res,$code,$err) = self::dfs_get($get_path, $auth);
                    if ($err || $code < 200 || $code >= 300){
                        self::log_write('warn','task_get failed (fallback)', ['endpoint'=>$endpoint_base,'action'=>'task_get','task_id'=>$tid,'http_code'=>$code,'error'=>$err]);
                        continue;
                    }
                    $nodes = isset($res['tasks'][0]['result']) && is_array($res['tasks'][0]['result']) ? $res['tasks'][0]['result'] : [];
                    self::log_write('info','task_get nodes', ['endpoint'=>$endpoint_base,'action'=>'task_get','task_id'=>$tid,'response'=>['nodes'=>count($nodes)]]);
                    foreach ($nodes as $node){
                        if (is_array($node)) { $node['id'] = $tid; }
                        $handler($node);
                    }
                    if (!$nodes){
                        // Not ready yet; keep it as posted for next poll
                        self::log_write('info','task_get no result yet (fallback)', ['endpoint'=>$endpoint_base,'action'=>'task_get','task_id'=>$tid,'response'=>$res]);
                    }
                }
            }
            // even if no fallback, nothing else to do
            return;
        }

        // Normal ready path: fetch by ids returned from tasks_ready
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
            $nodes = isset($res['tasks'][0]['result']) && is_array($res['tasks'][0]['result']) ? $res['tasks'][0]['result'] : [];
            self::log_write('info','task_get nodes', ['endpoint'=>$endpoint_base,'action'=>'task_get','task_id'=>$tid,'response'=>['nodes'=>count($nodes)]]);
            foreach ($nodes as $node){
                if (is_array($node)) { $node['id'] = $tid; }
                $handler($node);
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

        // Support both shapes: (a) container with 'items' array; (b) single item node
        $items = [];
        if (isset($result['items']) && is_array($result['items'])){
            $items = $result['items'];
        } elseif (isset($result['keyword'])) {
            $items = [$result];
        }
        self::log_write('info','ideas nodes', ['endpoint'=>'keywords_data/google_ads/keywords_for_keywords','action'=>'handle_result','term_id'=>$term_id,'lang'=>$lang,'task_id'=>$task_id,'response'=>['nodes'=>is_array($items)?count($items):0]]);

        $ins = 0;
        foreach ($items as $it){
            $kw = isset($it['keyword']) ? (string)$it['keyword'] : '';
            if ($kw==='') continue;
            // Prefer top-level fields if present, else fall back to keyword_info
            $volume = isset($it['search_volume']) ? (int)$it['search_volume'] : ( isset($it['keyword_info']['search_volume']) ? (int)$it['keyword_info']['search_volume'] : null );
            $comp   = isset($it['competition']) ? ( (string)$it['competition'] === 'LOW' || (string)$it['competition'] === 'MEDIUM' || (string)$it['competition'] === 'HIGH' ? null : (float)$it['competition'] ) : ( isset($it['keyword_info']['competition']) ? (float)$it['keyword_info']['competition'] : null );
            $cpc    = isset($it['cpc']) ? (float)$it['cpc'] : ( isset($it['keyword_info']['cpc']) ? (float)$it['keyword_info']['cpc'] : null );
            $bid_l  = isset($it['low_top_of_page_bid']) ? (float)$it['low_top_of_page_bid'] : ( isset($it['keyword_info']['low_top_of_page_bid']) ? (float)$it['keyword_info']['low_top_of_page_bid'] : null );
            $bid_h  = isset($it['high_top_of_page_bid']) ? (float)$it['high_top_of_page_bid'] : ( isset($it['keyword_info']['high_top_of_page_bid']) ? (float)$it['keyword_info']['high_top_of_page_bid'] : null );

            $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$tk} (term_id, lang, keyword, source, volume, competition, cpc, top_of_page_bid_low, top_of_page_bid_high, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE volume=COALESCE(VALUES(volume), volume), competition=COALESCE(VALUES(competition), competition), cpc=COALESCE(VALUES(cpc), cpc), top_of_page_bid_low=COALESCE(VALUES(top_of_page_bid_low), top_of_page_bid_low), top_of_page_bid_high=COALESCE(VALUES(top_of_page_bid_high), top_of_page_bid_high), updated_at=VALUES(updated_at)",
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

        $just_completed = false;
        if ($row){
            if ($row['status'] !== 'completed') { $just_completed = true; }
            $wpdb->update($tq, ['status'=>'completed','result_json'=>substr(wp_json_encode($result),0,20000),'updated_at'=>$now], ['id'=>(int)$row['id']]);
        }
        if ($term_id && $lang && $just_completed){
            $meta_key = 'ds_kw_ideas_first_done_'.$lang;
            if (!get_term_meta($term_id, $meta_key, true)){
                update_term_meta($term_id, $meta_key, $now);
            }
            self::maybe_trigger_followups($term_id, $lang);
        }
    }

    private static function enqueue_followups_for_term_lang($term_id, $lang){
        $auth = self::get_auth(); if (!$auth) return;
        $map = self::get_lang_map(); if (empty($map[$lang])) return;
        $limits = self::get_limits();
        $lang_code = (string)$map[$lang]['language_code'];
        $loc_code  = (int)$map[$lang]['location_code'];
        global $wpdb; $tk = self::table_keywords();
        $related_total = 0; $volume_total = 0; $paa_total = 0; $forecast_total = 0;
        self::log_write('info','followups start', ['endpoint'=>'ds-followups','action'=>'start','term_id'=>$term_id,'lang'=>$lang]);

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
        $expand_cap = max(1, (int)($limits['expansion_candidates_cap'] ?? 20));
        $use_filter = !empty($limits['expansion_token_filter']);
        foreach ($rows as $r){
            if (!$use_filter){
                $to_expand[] = $r['keyword'];
            } else {
                $kw = mb_strtolower($r['keyword']);
                foreach ($seed_tokens as $tok){
                    if ($tok && mb_stripos($kw, $tok) !== false){ $to_expand[] = $r['keyword']; break; }
                }
            }
            if (count($to_expand) >= $expand_cap) break; // cap via Limits JSON
        }

        // Related keywords (async, optional)
        self::log_write('info','related candidates', ['endpoint'=>'dataforseo_labs/google/related_keywords','action'=>'plan','term_id'=>$term_id,'lang'=>$lang,'response'=>['candidates'=>count($to_expand)]]);
        if (!empty($to_expand) && !empty($limits['expansion_enable'])){
            $mode = self::get_mode('related_mode');
            $rel_limit = max(1, min(100, (int)($limits['expand_related_limit_per_seed'] ?? 100)));
            if ($mode === 'live'){
                // Global aggregator path: collect across many term/lang and flush once per request
                if (self::agg_enabled()){
                    $saved_total = 0; $posted = 0;
                    foreach ($to_expand as $kw){
                        $payload_item = [
                            'keyword' => $kw,
                            'language_code' => $lang_code,
                            'location_code' => $loc_code,
                            'limit' => $rel_limit
                        ];
                        // Try reuse from logs first
                        $reuse_saved = self::reuse_related_from_logs($payload_item, $term_id, $lang);
                        if ($reuse_saved > 0){
                            $saved_total += $reuse_saved; $posted++;
                            self::log_write('info','related live reuse hit', ['endpoint'=>'dataforseo_labs/google/related_keywords/live','action'=>'reuse','term_id'=>$term_id,'lang'=>$lang,'response'=>['items'=>$reuse_saved]]);
                            continue;
                        }
                        self::agg_add_related($payload_item, $term_id, $lang);
                        $posted++;
                    }
                    // Register a once-per-request shutdown flush to send few big POSTs
                    self::agg_register_shutdown_once($auth);
                    self::log_write('info','related live summary (aggregated)', ['endpoint'=>'dataforseo_labs/google/related_keywords/live','action'=>'summary','term_id'=>$term_id,'lang'=>$lang,'response'=>['seeds_buffered'=>$posted,'saved_reused'=>$saved_total]]);
                } else {
                    // Fallback to immediate per-term live-batching
                    $saved_total = 0; $posted = 0; $errors = 0;
                    $max_batch = 100; // number of seed tasks per HTTP POST
                    $batch = [];
                    $seeds_in_batch = 0;

                    foreach ($to_expand as $kw){
                        $payload_item = [
                            'keyword' => $kw,
                            'language_code' => $lang_code,
                            'location_code' => $loc_code,
                            'limit' => $rel_limit
                        ];
                        // Try reuse from logs first
                        $reuse_saved = self::reuse_related_from_logs($payload_item, $term_id, $lang);
                        if ($reuse_saved > 0){
                            $saved_total += $reuse_saved; $posted++;
                            self::log_write('info','related live reuse hit', ['endpoint'=>'dataforseo_labs/google/related_keywords/live','action'=>'reuse','term_id'=>$term_id,'lang'=>$lang,'response'=>['items'=>$reuse_saved]]);
                            continue;
                        }
                        $batch[] = $payload_item;
                        $seeds_in_batch++;
                        $posted++;

                        if (count($batch) >= $max_batch){
                            self::log_write('info','related live batch request', ['endpoint'=>'dataforseo_labs/google/related_keywords/live','action'=>'live','term_id'=>$term_id,'lang'=>$lang,'request'=>$batch]);
                            list($json,$code,$err) = self::dfs_post('dataforseo_labs/google/related_keywords/live', $batch, $auth);
                            if ($err || $code < 200 || $code >= 300){
                                $errors++; self::log_write('warn','related live batch failed', ['endpoint'=>'dataforseo_labs/google/related_keywords/live','action'=>'live','http_code'=>$code,'error'=>$err,'term_id'=>$term_id,'lang'=>$lang,'response'=>$json]);
                            } else {
                                $tasks_arr = is_array($json['tasks'] ?? null) ? $json['tasks'] : [];
                                $normalized_total = 0; $nodes_total = 0; $saved_batch = 0;
                                foreach ($tasks_arr as $task){
                                    $items = (isset($task['result'][0]['items']) && is_array($task['result'][0]['items'])) ? $task['result'][0]['items'] : [];
                                    $nodes_count = is_array($items)?count($items):0;
                                    $nodes_total += $nodes_count;
                                    $norm = [];
                                    foreach ($items as $it){
                                        if (isset($it['keyword_data'])){
                                            $kd = $it['keyword_data'];
                                            $kw2 = isset($kd['keyword']) ? (string)$kd['keyword'] : '';
                                            $info = (isset($kd['keyword_info']) && is_array($kd['keyword_info'])) ? $kd['keyword_info'] : [];
                                            $norm[] = array_merge(['keyword'=>$kw2], $info);
                                        } elseif (isset($it['keyword'])) {
                                            $norm[] = $it;
                                        }
                                    }
                                    $normalized_total += count($norm);
                                    if (!empty($norm)){
                                        $saved_now = self::save_related_items($norm, $term_id, $lang);
                                        $saved_batch += $saved_now;
                                    }
                                }
                                self::log_write('info','related live batch saved', ['endpoint'=>'dataforseo_labs/google/related_keywords/live','action'=>'handle_result','term_id'=>$term_id,'lang'=>$lang,'response'=>['seeds'=>$seeds_in_batch,'nodes'=>$nodes_total,'normalized'=>$normalized_total,'items_saved'=>$saved_batch]]);
                                $saved_total += $saved_batch;
                            }
                            $batch = []; $seeds_in_batch = 0;
                        }
                    }
                    // flush final batch
                    if (!empty($batch)){
                        self::log_write('info','related live batch request', ['endpoint'=>'dataforseo_labs/google/related_keywords/live','action'=>'live','term_id'=>$term_id,'lang'=>$lang,'request'=>$batch]);
                        list($json,$code,$err) = self::dfs_post('dataforseo_labs/google/related_keywords/live', $batch, $auth);
                        if ($err || $code < 200 || $code >= 300){
                            $errors++; self::log_write('warn','related live batch failed', ['endpoint'=>'dataforseo_labs/google/related_keywords/live','action'=>'live','http_code'=>$code,'error'=>$err,'term_id'=>$term_id,'lang'=>$lang,'response'=>$json]);
                        } else {
                            $tasks_arr = is_array($json['tasks'] ?? null) ? $json['tasks'] : [];
                            $normalized_total = 0; $nodes_total = 0; $saved_batch = 0;
                            foreach ($tasks_arr as $task){
                                $items = (isset($task['result'][0]['items']) && is_array($task['result'][0]['items'])) ? $task['result'][0]['items'] : [];
                                $nodes_count = is_array($items)?count($items):0;
                                $nodes_total += $nodes_count;
                                $norm = [];
                                foreach ($items as $it){
                                    if (isset($it['keyword_data'])){
                                        $kd = $it['keyword_data'];
                                        $kw2 = isset($kd['keyword']) ? (string)$kd['keyword'] : '';
                                        $info = (isset($kd['keyword_info']) && is_array($kd['keyword_info'])) ? $kd['keyword_info'] : [];
                                        $norm[] = array_merge(['keyword'=>$kw2], $info);
                                    } elseif (isset($it['keyword'])) {
                                        $norm[] = $it;
                                    }
                                }
                                $normalized_total += count($norm);
                                if (!empty($norm)){
                                    $saved_now = self::save_related_items($norm, $term_id, $lang);
                                    $saved_batch += $saved_now;
                                }
                            }
                            self::log_write('info','related live batch saved', ['endpoint'=>'dataforseo_labs/google/related_keywords/live','action'=>'handle_result','term_id'=>$term_id,'lang'=>$lang,'response'=>['seeds'=>$seeds_in_batch,'nodes'=>$nodes_total,'normalized'=>$normalized_total,'items_saved'=>$saved_batch]]);
                            $saved_total += $saved_batch;
                        }
                    }
                    self::log_write('info','related live summary', ['endpoint'=>'dataforseo_labs/google/related_keywords/live','action'=>'summary','term_id'=>$term_id,'lang'=>$lang,'response'=>['seeds_posted'=>$posted,'saved'=>$saved_total,'errors'=>$errors]]);
                }
            } else {
                // Fallback: Labs Related has no async; treat as live even if mode != 'live'
                $saved_total = 0; $posted = 0; $errors = 0;
                if (self::agg_enabled()){
                    foreach ($to_expand as $kw){
                        $payload_item = [ 'keyword'=>$kw, 'language_code'=>$lang_code, 'location_code'=>$loc_code, 'limit'=>$rel_limit ];
                        $reuse_saved = self::reuse_related_from_logs($payload_item, $term_id, $lang);
                        if ($reuse_saved > 0){ $saved_total += $reuse_saved; $posted++; continue; }
                        self::agg_add_related($payload_item, $term_id, $lang); $posted++;
                    }
                    // Flush immediately so this run proceeds without waiting for poll
                    self::agg_flush_related($auth);
                    self::log_write('info','related live summary (aggregated)', ['endpoint'=>'dataforseo_labs/google/related_keywords/live','action'=>'summary','term_id'=>$term_id,'lang'=>$lang,'response'=>['seeds_buffered'=>$posted,'saved'=>$saved_total]]);
                } else {
                    // Immediate per-term live batch
                    $batch=[]; $seeds_in_batch=0; $max_batch=100;
                    foreach ($to_expand as $kw){
                        $payload_item = [ 'keyword'=>$kw, 'language_code'=>$lang_code, 'location_code'=>$loc_code, 'limit'=>$rel_limit ];
                        $reuse_saved = self::reuse_related_from_logs($payload_item, $term_id, $lang);
                        if ($reuse_saved > 0){ $saved_total += $reuse_saved; $posted++; continue; }
                        $batch[]=$payload_item; $seeds_in_batch++; $posted++;
                        if (count($batch) >= $max_batch){
                            self::log_write('info','related live batch request', ['endpoint'=>'dataforseo_labs/google/related_keywords/live','action'=>'live','term_id'=>$term_id,'lang'=>$lang,'request'=>$batch]);
                            list($json,$code,$err) = self::dfs_post('dataforseo_labs/google/related_keywords/live', $batch, $auth);
                            if ($err || $code < 200 || $code >= 300){ $errors++; self::log_write('warn','related live batch failed', ['endpoint'=>'dataforseo_labs/google/related_keywords/live','action'=>'live','http_code'=>$code,'error'=>$err,'term_id'=>$term_id,'lang'=>$lang,'response'=>$json]); }
                            else { $tasks_arr = is_array($json['tasks'] ?? null) ? $json['tasks'] : []; $nodes_total=0; $normalized_total=0; $saved_batch=0; foreach ($tasks_arr as $task){ $items=(isset($task['result'][0]['items'])&&is_array($task['result'][0]['items']))?$task['result'][0]['items']:[]; $nodes_total += is_array($items)?count($items):0; $norm=[]; foreach($items as $it){ if(isset($it['keyword_data'])){ $kd=$it['keyword_data']; $kw2=isset($kd['keyword'])?(string)$kd['keyword']:''; $info=(isset($kd['keyword_info'])&&is_array($kd['keyword_info']))?$kd['keyword_info']:[]; $norm[]=array_merge(['keyword'=>$kw2], $info);} elseif(isset($it['keyword'])){ $norm[]=$it; } } $normalized_total += count($norm); if(!empty($norm)){ $saved_now=self::save_related_items($norm,$term_id,$lang); $saved_batch += $saved_now; } } self::log_write('info','related live batch saved', ['endpoint'=>'dataforseo_labs/google/related_keywords/live','action'=>'handle_result','term_id'=>$term_id,'lang'=>$lang,'response'=>['seeds'=>$seeds_in_batch,'nodes'=>$nodes_total,'normalized'=>$normalized_total,'items_saved'=>$saved_batch]]); $saved_total += $saved_batch; }
                            $batch=[]; $seeds_in_batch=0;
                        }
                    }
                    if (!empty($batch)){
                        self::log_write('info','related live batch request', ['endpoint'=>'dataforseo_labs/google/related_keywords/live','action'=>'live','term_id'=>$term_id,'lang'=>$lang,'request'=>$batch]);
                        list($json,$code,$err) = self::dfs_post('dataforseo_labs/google/related_keywords/live', $batch, $auth);
                        if ($err || $code < 200 || $code >= 300){ $errors++; self::log_write('warn','related live batch failed', ['endpoint'=>'dataforseo_labs/google/related_keywords/live','action'=>'live','http_code'=>$code,'error'=>$err,'term_id'=>$term_id,'lang'=>$lang,'response'=>$json]); }
                        else { $tasks_arr = is_array($json['tasks'] ?? null) ? $json['tasks'] : []; $nodes_total=0; $normalized_total=0; $saved_batch=0; foreach ($tasks_arr as $task){ $items=(isset($task['result'][0]['items'])&&is_array($task['result'][0]['items']))?$task['result'][0]['items']:[]; $nodes_total += is_array($items)?count($items):0; $norm=[]; foreach($items as $it){ if(isset($it['keyword_data'])){ $kd=$it['keyword_data']; $kw2=isset($kd['keyword'])?(string)$kd['keyword']:''; $info=(isset($kd['keyword_info'])&&is_array($kd['keyword_info']))?$kd['keyword_info']:[]; $norm[]=array_merge(['keyword'=>$kw2], $info);} elseif(isset($it['keyword'])){ $norm[]=$it; } } $normalized_total += count($norm); if(!empty($norm)){ $saved_now=self::save_related_items($norm,$term_id,$lang); $saved_batch += $saved_now; } } self::log_write('info','related live batch saved', ['endpoint'=>'dataforseo_labs/google/related_keywords/live','action'=>'handle_result','term_id'=>$term_id,'lang'=>$lang,'response'=>['seeds'=>$seeds_in_batch,'nodes'=>$nodes_total,'normalized'=>$normalized_total,'items_saved'=>$saved_batch]]); $saved_total += $saved_batch; }
                    }
                    self::log_write('info','related live summary', ['endpoint'=>'dataforseo_labs/google/related_keywords/live','action'=>'summary','term_id'=>$term_id,'lang'=>$lang,'response'=>['seeds_posted'=>$posted,'saved'=>$saved_total,'errors'=>$errors]]);
                }
            }
        }

        // Build delta pools for intent & volume (process only missing data)
        $intent_rows = $wpdb->get_results($wpdb->prepare("SELECT DISTINCT keyword FROM {$tk} WHERE term_id=%d AND lang=%s AND (intent_main IS NULL OR intent_main='')", $term_id, $lang), ARRAY_A);
        $volume_rows = $wpdb->get_results($wpdb->prepare("SELECT DISTINCT keyword FROM {$tk} WHERE term_id=%d AND lang=%s AND volume IS NULL", $term_id, $lang), ARRAY_A);
        $pool_intent = array_values(array_unique(array_map(function($r){ return (string)$r['keyword']; }, $intent_rows)));
        $pool_volume = array_values(array_unique(array_map(function($r){ return (string)$r['keyword']; }, $volume_rows)));
        self::log_write('info','followups plan (delta)', ['endpoint'=>'ds-followups','action'=>'plan','term_id'=>$term_id,'lang'=>$lang,'response'=>['intent_missing'=>count($pool_intent),'volume_missing'=>count($pool_volume)]]);

        // ---- INTENT (LIVE) ----
        if (!empty($pool_intent)){
            $intent_chunks = array_chunk($pool_intent, 1000);
            foreach ($intent_chunks as $ch){
                $payload = [[ 'keywords'=>$ch, 'language_code'=>$lang_code, 'location_code'=>$loc_code ]];
                // Try reuse from logs first
                $reuse_saved = self::reuse_intent_from_logs($payload[0], $term_id, $lang);
                if ($reuse_saved > 0){
                    self::log_write('info','intent live reuse hit', ['endpoint'=>'dataforseo_labs/google/search_intent/live','action'=>'reuse','term_id'=>$term_id,'lang'=>$lang,'response'=>['items'=>$reuse_saved]]);
                    continue;
                }
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
        }

        // ---- VOLUME (ASYNC or LIVE) ----
        if (!empty($pool_volume)){
            $per_task = max(1, (int)($limits['volume_keywords_per_task'] ?? 1000));
            $chunks = array_chunk($pool_volume, $per_task);
            $mode = self::get_mode('volume_mode');
            if ($mode === 'live'){
                $saved_total = 0; $posted = 0; $errors = 0;
                foreach ($chunks as $ch){
                    $payload = [[ 'keywords'=>$ch, 'language_code'=>$lang_code, 'location_code'=>$loc_code ]];
                    // Try reuse from logs first
                    $reuse_saved = self::reuse_volume_from_logs($payload[0], $term_id, $lang);
                    if ($reuse_saved > 0){
                        $saved_total += $reuse_saved; $posted++;
                        self::log_write('info','volume live reuse hit', ['endpoint'=>'keywords_data/google_ads/search_volume/live','action'=>'reuse','term_id'=>$term_id,'lang'=>$lang,'response'=>['items'=>$reuse_saved]]);
                        continue;
                    }
                    self::log_write('info','volume live request', ['endpoint'=>'keywords_data/google_ads/search_volume/live','action'=>'live','term_id'=>$term_id,'lang'=>$lang,'request'=>$payload]);
                    list($json,$code,$err) = self::dfs_post('keywords_data/google_ads/search_volume/live', $payload, $auth);
                    if ($err || $code < 200 || $code >= 300){
                        $errors++; self::log_write('warn','volume live failed', ['endpoint'=>'keywords_data/google_ads/search_volume/live','action'=>'live','http_code'=>$code,'error'=>$err,'term_id'=>$term_id,'lang'=>$lang]);
                        continue;
                    }
                    $items = [];
                    if (isset($json['tasks'][0]['result'][0]['items']) && is_array($json['tasks'][0]['result'][0]['items'])) {
                        $items = $json['tasks'][0]['result'][0]['items'];
                    } elseif (isset($json['tasks'][0]['result']) && is_array($json['tasks'][0]['result'])) {
                        $res = $json['tasks'][0]['result'];
                        if (!empty($res) && isset($res[0]) && is_array($res[0]) && array_key_exists('keyword', $res[0])) {
                            $items = $res;
                        }
                    }
                    self::log_write('info','volume live nodes', ['endpoint'=>'keywords_data/google_ads/search_volume/live','action'=>'handle_result','term_id'=>$term_id,'lang'=>$lang,'response'=>['nodes'=>is_array($items)?count($items):0]]);
                    $saved = self::save_volume_items($items, $term_id, $lang);
                    $saved_total += $saved; $posted++;
                    self::log_write('info','volume live saved', ['endpoint'=>'keywords_data/google_ads/search_volume/live','action'=>'handle_result','term_id'=>$term_id,'lang'=>$lang,'response'=>['items'=>$saved]]);
                }
                self::log_write('info','volume live summary', ['endpoint'=>'keywords_data/google_ads/search_volume/live','action'=>'summary','term_id'=>$term_id,'lang'=>$lang,'response'=>['posted'=>$posted,'saved'=>$saved_total,'errors'=>$errors]]);
            } else {
                $tasks=[]; $ctxs=[];
                foreach ($chunks as $ch){
                    $payload_task = [ 'keywords'=>$ch, 'language_code'=>$lang_code, 'location_code'=>$loc_code ];
                    // Try reuse from logs to avoid posting duplicate async volume tasks
                    $reuse_saved = self::reuse_volume_from_logs($payload_task, $term_id, $lang);
                    if ($reuse_saved > 0){
                        self::log_write('info','volume async reuse hit', ['endpoint'=>'keywords_data/google_ads/search_volume/live','action'=>'reuse','term_id'=>$term_id,'lang'=>$lang,'response'=>['items'=>$reuse_saved]]);
                        continue;
                    }
                    if (self::agg_enabled()){
                        self::agg_add_volume_task($payload_task, ['endpoint'=>'keywords_data/google_ads/search_volume','term_id'=>$term_id,'lang'=>$lang,'tag'=>'volume:'.$term_id.':'.$lang]);
                    } else {
                        $tasks[] = $payload_task;
                        $ctxs[]  = ['endpoint'=>'keywords_data/google_ads/search_volume','term_id'=>$term_id,'lang'=>$lang,'tag'=>'volume:'.$term_id.':'.$lang];
                    }
                }
                if (self::agg_enabled()){
                    self::agg_register_shutdown_once($auth);
                    self::log_write('info','volume async summary (aggregated)', ['endpoint'=>'keywords_data/google_ads/search_volume','action'=>'summary','term_id'=>$term_id,'lang'=>$lang,'response'=>['tasks_buffered'=>count($chunks)]]);
                } else if ($tasks){
                    self::dfs_post_tasks('keywords_data/google_ads/search_volume/task_post', $tasks, $ctxs, $auth);
                }
            }
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

        // ---- Smart Fill (optional, top-up until target) ----
        self::smart_fill_term_lang($term_id, $lang, $auth, $limits, $lang_code, $loc_code, $seed_tokens);
        // Proactive flush so aggregated posts happen within this request too
        if (self::agg_enabled()) { self::agg_flush_all($auth); }
    }

    private static function smart_fill_term_lang($term_id, $lang, $auth, $limits, $lang_code, $loc_code, $seed_tokens){
        if (empty($limits['smart_fill_enable'])) return;
        global $wpdb; $tk = self::table_keywords();
        $target = max(1, (int)($limits['target_keywords_per_cat'] ?? 300));
        $max_cycles = max(1, (int)($limits['max_smart_cycles_per_run'] ?? 2));
        $rel_limit = max(1, min(100, (int)($limits['expand_related_limit_per_seed'] ?? 50)));
        $expand_cap = max(1, (int)($limits['expansion_candidates_cap'] ?? 60));
        $use_filter = !empty($limits['expansion_token_filter']);

        $expanded_key = 'ds_kw_related_expanded_'.$lang;
        $expanded_raw = (string) get_term_meta($term_id, $expanded_key, true);
        $expanded_list = [];
        if ($expanded_raw !== ''){
            $decoded = json_decode($expanded_raw, true);
            if (is_array($decoded)) $expanded_list = array_values(array_filter(array_map('strval', $decoded)));
        }

        $current_count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tk} WHERE term_id=%d AND lang=%s", $term_id, $lang));
        $cycle = 0;
        while ($current_count < $target && $cycle < $max_cycles){
            $cycle++;
            self::pipeline_log('smart_fill','cycle_start', $term_id, $lang, ['current'=>$current_count,'target'=>$target], $cycle);
            // Build seed candidates from existing keywords (ideas + related), favor higher volume
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT keyword, COALESCE(volume,0) vol, source FROM {$tk} WHERE term_id=%d AND lang=%s ORDER BY vol DESC, updated_at DESC LIMIT 200",
                $term_id, $lang
            ), ARRAY_A);
            $to_expand = [];
            foreach ($rows as $r){
                $kw = (string)$r['keyword']; if ($kw==='') continue;
                if ($use_filter){
                    $kw_l = mb_strtolower($kw);
                    $ok=false; foreach ($seed_tokens as $tok){ if ($tok && mb_stripos($kw_l, $tok)!==false){ $ok=true; break; } }
                    if (!$ok) continue;
                }
                if (in_array($kw, $expanded_list, true)) continue; // already expanded in previous runs
                $to_expand[] = $kw;
                if (count($to_expand) >= $expand_cap) break;
            }

            if (empty($to_expand)){
                self::pipeline_log('smart_fill','no_seeds', $term_id, $lang, ['reason'=>'no_candidates_after_filter_or_all_expanded'], $cycle);
                break;
            }

            // Post Related live in aggregated batches; try reuse first
            $posted = 0; $reused = 0;
            foreach ($to_expand as $kw){
                $payload_item = [
                    'keyword' => $kw,
                    'language_code' => $lang_code,
                    'location_code' => $loc_code,
                    'limit' => $rel_limit
                ];
                $reuse_saved = self::reuse_related_from_logs($payload_item, $term_id, $lang);
                if ($reuse_saved > 0){ $reused++; continue; }
                self::agg_add_related($payload_item, $term_id, $lang);
                $posted++;
            }
            // Force flush Related now so this cycle can see results soon
            self::pipeline_log('smart_fill','related_post_or_reuse', $term_id, $lang, ['posted'=>$posted,'reused'=>$reused], $cycle);
            self::agg_flush_related($auth);

            // Mark seeds as expanded to avoid duplicates next cycles/runs
            $expanded_list = array_values(array_unique(array_merge($expanded_list, $to_expand)));
            update_term_meta($term_id, $expanded_key, wp_json_encode($expanded_list));

            // Delta Intent (live)
            $intent_rows = $wpdb->get_results($wpdb->prepare("SELECT DISTINCT keyword FROM {$tk} WHERE term_id=%d AND lang=%s AND (intent_main IS NULL OR intent_main='') LIMIT 2000", $term_id, $lang), ARRAY_A);
            $pool_intent = array_values(array_unique(array_map(function($r){ return (string)$r['keyword']; }, $intent_rows)));
            if (!empty($pool_intent)){
                $intent_chunks = array_chunk($pool_intent, 1000);
                $intent_saved_total = 0; $intent_posts = 0; $intent_fail = 0;
                foreach ($intent_chunks as $ch){
                    $payload = [[ 'keywords'=>$ch, 'language_code'=>$lang_code, 'location_code'=>$loc_code ]];
                    $reuse_saved = self::reuse_intent_from_logs($payload[0], $term_id, $lang);
                    if ($reuse_saved > 0){ $intent_saved_total += $reuse_saved; $intent_posts++; continue; }
                    list($json,$code,$err) = self::dfs_post('dataforseo_labs/google/search_intent/live', $payload, $auth);
                    if ($err || $code < 200 || $code >= 300){ $intent_fail++; self::log_write('warn','intent live failed (smart_fill)', ['endpoint'=>'dataforseo_labs/google/search_intent/live','action'=>'live','http_code'=>$code,'error'=>$err,'term_id'=>$term_id,'lang'=>$lang,'request'=>$payload]); }
                    else { if (isset($json['tasks'][0]['result'][0]['items'])){ $items = $json['tasks'][0]['result'][0]['items']; self::handle_intent_items($items, $term_id, $lang); $intent_saved_total += is_array($items)?count($items):0; $intent_posts++; } }
                }
                self::pipeline_log('smart_fill','intent_saved', $term_id, $lang, ['posted_batches'=>$intent_posts,'items_saved'=>$intent_saved_total,'fail_batches'=>$intent_fail], $cycle);
            }

            // Delta Volume (async aggregated)
            $volume_rows = $wpdb->get_results($wpdb->prepare("SELECT DISTINCT keyword FROM {$tk} WHERE term_id=%d AND lang=%s AND volume IS NULL LIMIT 5000", $term_id, $lang), ARRAY_A);
            $pool_volume = array_values(array_unique(array_map(function($r){ return (string)$r['keyword']; }, $volume_rows)));
            if (!empty($pool_volume)){
                $per_task = max(1, (int)($limits['volume_keywords_per_task'] ?? 1000));
                $chunks = array_chunk($pool_volume, $per_task);
                $v_tasks = 0;
                foreach ($chunks as $ch){
                    $payload_task = [ 'keywords'=>$ch, 'language_code'=>$lang_code, 'location_code'=>$loc_code ];
                    $reuse_saved = self::reuse_volume_from_logs($payload_task, $term_id, $lang);
                    if ($reuse_saved > 0){ continue; }
                    self::agg_add_volume_task($payload_task, ['endpoint'=>'keywords_data/google_ads/search_volume','term_id'=>$term_id,'lang'=>$lang,'tag'=>'volume:'.$term_id.':'.$lang]);
                    $v_tasks++;
                }
                self::agg_flush_volume($auth);
                self::pipeline_log('smart_fill','volume_posted', $term_id, $lang, ['tasks'=>$v_tasks,'per_task'=>$per_task], $cycle);
            }

            // Update current count and decide whether to continue
            $new_count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tk} WHERE term_id=%d AND lang=%s", $term_id, $lang));
            self::pipeline_log('smart_fill','cycle_end', $term_id, $lang, ['before'=>$current_count,'after'=>$new_count], $cycle);
            $current_count = $new_count;
        }
        if ($current_count >= $target){ self::pipeline_log('smart_fill','target_reached', $term_id, $lang, ['count'=>$current_count,'target'=>$target]); }
        else { self::pipeline_log('smart_fill','stopped', $term_id, $lang, ['count'=>$current_count,'target'=>$target,'cycles'=>$cycle]); }
    }

    private static function handle_related_result($result){
        global $wpdb; $tk = self::table_keywords(); $tq = self::table_queue();
        $now = current_time('mysql');
        $task_id = (string)($result['id'] ?? '');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tq} WHERE endpoint=%s AND task_id=%s", 'dataforseo_labs/google/related_keywords', $task_id), ARRAY_A);
        $term_id = $row ? (int)$row['term_id'] : 0; $lang = $row ? (string)$row['lang'] : '';

        // Labs related: result node typically has items[], each with keyword_data.keyword and keyword_data.keyword_info
        $raw_items = (isset($result['items']) && is_array($result['items'])) ? $result['items'] : [];
        $norm = [];
        foreach ($raw_items as $it){
            if (isset($it['keyword_data']) && is_array($it['keyword_data'])){
                $kd = $it['keyword_data'];
                $kw = isset($kd['keyword']) ? (string)$kd['keyword'] : '';
                if ($kw==='') continue;
                $info = (isset($kd['keyword_info']) && is_array($kd['keyword_info'])) ? $kd['keyword_info'] : [];
                $norm[] = array_merge(['keyword'=>$kw], $info);
            } elseif (isset($it['keyword'])) {
                // Fallback flat shape
                $norm[] = $it;
            }
        }
        self::log_write('info','related nodes', ['endpoint'=>'dataforseo_labs/google/related_keywords','action'=>'handle_result','term_id'=>$term_id,'lang'=>$lang,'task_id'=>$task_id,'response'=>['nodes'=>count($raw_items),'normalized'=>count($norm)]]);

        $saved = self::save_related_items($norm, $term_id, $lang);
        self::log_write('info','related result saved', ['endpoint'=>'dataforseo_labs/google/related_keywords','action'=>'handle_result','term_id'=>$term_id,'lang'=>$lang,'task_id'=>$task_id,'response'=>['items'=>$saved]]);
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
                // Older/alternative shape
                $info = $it['search_intent_info'];
                $intent = (string)($info['main_intent'] ?? ($info['main'] ?? ''));
                $probs = wp_json_encode($info);
            } elseif (isset($it['keyword_intent'])){
                // Labs current shape
                $main = $it['keyword_intent'];
                $secondary = isset($it['secondary_keyword_intents']) ? $it['secondary_keyword_intents'] : null;
                $intent = (string)($main['label'] ?? '');
                $probs = wp_json_encode([
                    'keyword_intent' => $main,
                    'secondary_keyword_intents' => $secondary
                ]);
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

        // Support both shapes: container with items[] or flat item node
        $items = [];
        if (isset($result['items']) && is_array($result['items'])) {
            $items = $result['items'];
        } elseif (isset($result['keyword'])) {
            $items = [$result];
        }
        self::log_write('info','volume nodes', ['endpoint'=>'keywords_data/google_ads/search_volume','action'=>'handle_result','term_id'=>$term_id,'lang'=>$lang,'task_id'=>$task_id,'response'=>['nodes'=>is_array($items)?count($items):0]]);

        $ins = self::save_volume_items($items, $term_id, $lang);
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
        self::log_write('info','paa nodes', ['endpoint'=>'serp/google/organic','action'=>'handle_result','term_id'=>$term_id,'lang'=>$lang,'task_id'=>$task_id,'response'=>['nodes'=>is_array($items)?count($items):0]]);
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
        // Schedule daily diagnostics report (run ~08:00 site time)
        if (!as_next_scheduled_action(self::AS_REPORT, [], 'ds-keywords')) {
            $tz_offset = (int) (get_option('gmt_offset', 0) * HOUR_IN_SECONDS);
            $eight_am  = strtotime('tomorrow 08:00:00') - DAY_IN_SECONDS; // today 08:00 approx
            $start     = max(time()+600, $eight_am + $tz_offset);
            as_schedule_recurring_action($start, DAY_IN_SECONDS, self::AS_REPORT, [], 'ds-keywords');
        }
        // Clear WP-Cron duplicates if any
        if (function_exists('wp_next_scheduled')) {
            if (wp_next_scheduled(self::CRON_REFRESH)) { wp_clear_scheduled_hook(self::CRON_REFRESH); }
            if (wp_next_scheduled(self::CRON_POLL))    { wp_clear_scheduled_hook(self::CRON_POLL); }
        }
    }

    // ----- Mode & live saving helpers -----
    private static function get_mode($key){
        $limits = self::get_limits();
        $mode = isset($limits[$key]) ? strtolower((string)$limits[$key]) : 'async';
        return ($mode === 'live') ? 'live' : 'async';
    }

    private static function save_ideas_items($items, $term_id, $lang){
        if (!is_array($items) || empty($items)) return 0;
        global $wpdb; $tk = self::table_keywords(); $now = current_time('mysql');
        $ins = 0;
        foreach ($items as $it){
            if (!is_array($it)) continue;
            $kw = isset($it['keyword']) ? (string)$it['keyword'] : '';
            if ($kw==='') continue;
            $volume = isset($it['search_volume']) ? (int)$it['search_volume'] : ( isset($it['keyword_info']['search_volume']) ? (int)$it['keyword_info']['search_volume'] : null );
            $comp   = isset($it['competition']) ? ( (string)$it['competition'] === 'LOW' || (string)$it['competition'] === 'MEDIUM' || (string)$it['competition'] === 'HIGH' ? null : (float)$it['competition'] ) : ( isset($it['keyword_info']['competition']) ? (float)$it['keyword_info']['competition'] : null );
            $cpc    = isset($it['cpc']) ? (float)$it['cpc'] : ( isset($it['keyword_info']['cpc']) ? (float)$it['keyword_info']['cpc'] : null );
            $bid_l  = isset($it['low_top_of_page_bid']) ? (float)$it['low_top_of_page_bid'] : ( isset($it['keyword_info']['low_top_of_page_bid']) ? (float)$it['keyword_info']['low_top_of_page_bid'] : null );
            $bid_h  = isset($it['high_top_of_page_bid']) ? (float)$it['high_top_of_page_bid'] : ( isset($it['keyword_info']['high_top_of_page_bid']) ? (float)$it['keyword_info']['high_top_of_page_bid'] : null );
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$tk} (term_id, lang, keyword, source, volume, competition, cpc, top_of_page_bid_low, top_of_page_bid_high, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE volume=COALESCE(VALUES(volume), volume), competition=COALESCE(VALUES(competition), competition), cpc=COALESCE(VALUES(cpc), cpc), top_of_page_bid_low=COALESCE(VALUES(top_of_page_bid_low), top_of_page_bid_low), top_of_page_bid_high=COALESCE(VALUES(top_of_page_bid_high), top_of_page_bid_high), updated_at=VALUES(updated_at)",
                $term_id, $lang, $kw, 'ideas',
                is_null($volume)?null:$volume,
                is_null($comp)?null:$comp,
                is_null($cpc)?null:$cpc,
                is_null($bid_l)?null:$bid_l,
                is_null($bid_h)?null:$bid_h,
                $now, $now
            ));
            $ins++;
        }
        return $ins;
    }

    private static function save_volume_items($items, $term_id, $lang){
        if (!is_array($items) || empty($items)) return 0;
        global $wpdb; $tk = self::table_keywords(); $now = current_time('mysql');
        $ins = 0;
        foreach ($items as $it){
            if (!is_array($it)) continue;
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
        return $ins;
    }

    private static function save_related_items($items, $term_id, $lang){
        if (!is_array($items) || empty($items)) return 0;
        global $wpdb; $tk = self::table_keywords(); $now = current_time('mysql');
        $ins = 0;
        foreach ($items as $it){
            if (!is_array($it)) continue;
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
                is_null($volume)?null:$volume,
                is_null($comp)?null:$comp,
                is_null($cpc)?null:$cpc,
                $now, $now
            ));
            $ins++;
        }
        return $ins;
    }

    private static function save_forecast_items($items, $term_id, $lang){
        if (!is_array($items) || empty($items)) return 0;
        global $wpdb; $tf = self::table_ads_forecast(); $now = current_time('mysql');
        $ins = 0;
        foreach ($items as $it){
            if (!is_array($it)) continue;
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
        return $ins;
    }

    // ----- Follow-ups gating helpers -----
    private static function maybe_trigger_followups($term_id, $lang, $from_scheduler = false){
        $limits = self::get_limits();
        $mode = isset($limits['followups_mode']) ? strtolower((string)$limits['followups_mode']) : 'immediate';
        if ($mode !== 'gated'){
            // immediate mode: run now
            self::enqueue_followups_for_term_lang($term_id, $lang);
            return;
        }
        $settle_hours = max(1, (int)($limits['settle_hours'] ?? 24));
        $min_ready_ratio = max(0, min(1, (float)($limits['min_ready_ratio'] ?? 0.9)));
        global $wpdb; $tq = self::table_queue();
        // Count ideas tasks for this term/lang
        $endpoint = 'keywords_data/google_ads/keywords_for_keywords';
        $posted = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tq} WHERE endpoint=%s AND term_id=%d AND lang=%s AND status='posted'", $endpoint, $term_id, $lang));
        $done   = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tq} WHERE endpoint=%s AND term_id=%d AND lang=%s AND status='completed'", $endpoint, $term_id, $lang));
        $total = $posted + $done;
        $first_key = 'ds_kw_ideas_first_done_'.$lang;
        $first_done = (string) get_term_meta($term_id, $first_key, true);
        $age_hours = 0;
        if ($first_done){ $age_hours = max(0, (time() - strtotime($first_done)) / 3600.0); }
        $ratio = 0.0;
        if ($total > 0){ $ratio = $done / max(1, $total); }
        else { $ratio = $first_done ? 1.0 : 0.0; }

        $open = false;
        if ($ratio >= $min_ready_ratio) $open = true;
        if (!$open && $first_done && $age_hours >= $settle_hours) $open = true;

        if ($open){
            self::log_write('info','followups run (gate open)', ['endpoint'=>'ds-followups','action'=>'gate_run','term_id'=>$term_id,'lang'=>$lang,'response'=>['posted'=>$posted,'completed'=>$done,'ratio'=>$ratio,'age_h'=>$age_hours]]);
            self::enqueue_followups_for_term_lang($term_id, $lang);
            update_term_meta($term_id, 'ds_kw_followups_ran_'.$lang, current_time('mysql'));
        } else {
            // schedule a re-check
            $delay_min = max(30, min( (int)($settle_hours*60/4), 360 )); // 0.25 of settle, capped at 6h
            self::schedule_gate_check($term_id, $lang, $delay_min);
            self::log_write('info','followups defer (waiting for ideas)', ['endpoint'=>'ds-followups','action'=>'gate_defer','term_id'=>$term_id,'lang'=>$lang,'response'=>['remaining'=>$posted,'ratio'=>$ratio,'age_h'=>$age_hours,'next_check_min'=>$delay_min]]);
        }
    }

    private static function schedule_gate_check($term_id, $lang, $delay_minutes){
        $hook = 'ds_keywords_gate_check';
        // Avoid duplicate schedules for same args
        $ts = wp_next_scheduled($hook, [$term_id, $lang]);
        if ($ts === false){
            wp_schedule_single_event(time() + max(1,$delay_minutes)*60, $hook, [$term_id, $lang]);
            self::log_write('info','gate_check scheduled', ['endpoint'=>'ds-followups','action'=>'gate_schedule','term_id'=>$term_id,'lang'=>$lang,'response'=>['delay_min'=>$delay_minutes]]);
        }
    }

    public static function gate_check_handler($term_id, $lang){
        // Re-evaluate gate for this term/lang
        self::maybe_trigger_followups((int)$term_id, (string)$lang, true);
    }

    // ----- Scheduler self-healing -----
    public static function ensure_single_scheduler(){
        // If Action Scheduler exists, unschedule WP‑Cron duplicates for our hooks
        if (function_exists('as_schedule_recurring_action')){
            $hooks = [ self::CRON_REFRESH, self::CRON_POLL ];
            foreach ($hooks as $hook){
                while ($ts = wp_next_scheduled($hook)){
                    wp_unschedule_event($ts, $hook);
                }
            }
        }
    }

    // ----- Daily diagnostics & report -----
    public static function run_daily_diagnostics(){
        $report = self::build_diagnostics_report();
        // Email admin
        $to = get_option('admin_email');
        if ($to){
            $subject = 'Dreamli Keywords — Daily Report';
            $body = self::format_report_text($report);
            @wp_mail($to, $subject, $body);
        }
        // Log snapshot for UI/history
        self::log_write('info','daily report', ['endpoint'=>'ds-daily-report','action'=>'report:daily','response'=>$report]);
        // Optional conservative auto‑fixes
        self::autofix_issues($report);
    }

    private static function build_diagnostics_report(){
        return [
            'time'        => current_time('mysql'),
            'limits'      => self::get_limits(),
            'schedulers'  => self::detect_schedulers(),
            'endpoints'   => self::endpoint_metrics_last_24h(),
            'queue'       => self::queue_snapshot(),
            'coverage'    => self::coverage_by_language(),
            'bottlenecks' => [] // filled below
        ];
    }

    private static function detect_schedulers(){
        $has_as = function_exists('as_schedule_recurring_action');
        $wp_poll = wp_next_scheduled(self::CRON_POLL) ? true : false;
        $wp_refresh = wp_next_scheduled(self::CRON_REFRESH) ? true : false;
        return [ 'action_scheduler'=>$has_as, 'wp_cron_poll'=>$wp_poll, 'wp_cron_refresh'=>$wp_refresh ];
    }

    private static function queue_snapshot(){
        global $wpdb; $tq = self::table_queue();
        $rows = $wpdb->get_results("SELECT endpoint, status, COUNT(*) cnt FROM {$tq} GROUP BY endpoint, status", ARRAY_A);
        $old = $wpdb->get_results("SELECT endpoint, COUNT(*) cnt FROM {$tq} WHERE status='posted' AND created_at < DATE_SUB(NOW(), INTERVAL 2 HOUR) GROUP BY endpoint", ARRAY_A);
        return [ 'by_status'=>$rows, 'stale_posted_over_2h'=>$old ];
    }

    private static function coverage_by_language(){
        global $wpdb; $tk = self::table_keywords(); $tf = self::table_faq();
        $kw = $wpdb->get_results("SELECT lang, COUNT(*) cnt, SUM(CASE WHEN COALESCE(intent_main,'')<>'' THEN 1 ELSE 0 END) intent_cnt FROM {$tk} GROUP BY lang", ARRAY_A);
        $faq = $wpdb->get_results("SELECT lang, COUNT(*) cnt FROM {$tf} GROUP BY lang", ARRAY_A);
        $faq_map = [];
        foreach ($faq as $r){ $faq_map[$r['lang']] = (int)$r['cnt']; }
        $out = [];
        foreach ($kw as $r){
            $lang = (string)$r['lang'];
            $total = (int)$r['cnt'];
            $intent = (int)$r['intent_cnt'];
            $cov = $total>0 ? round(100.0*$intent/$total,1) : 0.0;
            $out[] = [ 'lang'=>$lang, 'keywords'=>$total, 'intent_covered_pct'=>$cov, 'faq'=> (int)($faq_map[$lang] ?? 0) ];
        }
        return $out;
    }

    private static function endpoint_metrics_last_24h(){
        global $wpdb; $tl = self::table_logs();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT endpoint, action, level, COUNT(*) cnt FROM {$tl} WHERE ts >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR) GROUP BY endpoint, action, level",
            24
        ), ARRAY_A);
        // Summaries per endpoint
        $by = [];
        foreach ($rows as $r){
            $ep = (string)$r['endpoint']; $ac = (string)$r['action']; $lv = (string)$r['level']; $cnt=(int)$r['cnt'];
            if (!isset($by[$ep])) $by[$ep] = [ 'post_tasks'=>0, 'handle_result'=>0, 'requests'=>0, 'responses'=>0, 'warn'=>0, 'error'=>0 ];
            if ($ac==='post_tasks') $by[$ep]['post_tasks'] += $cnt;
            if ($ac==='handle_result') $by[$ep]['handle_result'] += $cnt;
            if ($ac==='request') $by[$ep]['requests'] += $cnt;
            if ($ac==='response') $by[$ep]['responses'] += $cnt;
            if ($lv==='warn') $by[$ep]['warn'] += $cnt;
            if ($lv==='error') $by[$ep]['error'] += $cnt;
        }
        // Add poll skips vs starts
        $poll = $wpdb->get_results($wpdb->prepare("SELECT action, COUNT(*) cnt FROM {$tl} WHERE ts >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR) AND action IN ('cron_poll','poll_skip') GROUP BY action",24), ARRAY_A);
        $poll_map = []; foreach ($poll as $r){ $poll_map[$r['action']] = (int)$r['cnt']; }
        $by['ds-poll'] = [ 'starts'=> ($poll_map['cron_poll'] ?? 0), 'skips'=> ($poll_map['poll_skip'] ?? 0) ];
        return $by;
    }

    private static function detect_bottlenecks($report){
        $issues = [];
        // Duplicate schedulers
        if (!empty($report['schedulers']['action_scheduler']) && (!empty($report['schedulers']['wp_cron_poll']) || !empty($report['schedulers']['wp_cron_refresh']))){
            $issues[] = ['code'=>'duplicate_schedulers','severity'=>'warn','msg'=>'Both Action Scheduler and WP‑Cron are scheduled for ds_keywords_*; keep only AS to avoid duplicate pollers.','suggest'=>'Auto‑removed WP‑Cron duplicates'];
        }
        // Related async 404s
        global $wpdb; $tl = self::table_logs();
        $count404 = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$tl} WHERE ts >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 48 HOUR) AND endpoint='dataforseo_labs/google/related_keywords/task_post' AND http_code=404");
        if ($count404>0){ $issues[] = ['code'=>'labs_related_async_404','severity'=>'info','msg'=>'Labs Related async task_post returned 404 recently; Labs is live‑only.','suggest'=>'Force related_mode=live (autofix)']; }
        // Queue backlog (>2h posted)
        $stale_total = 0; foreach (($report['queue']['stale_posted_over_2h'] ?? []) as $r){ $stale_total += (int)$r['cnt']; }
        if ($stale_total>0){ $issues[] = ['code'=>'queue_backlog','severity'=>'warn','msg'=>'There are queued tasks posted >2h ago.','suggest'=>'Lower poll_min_interval temporarily or run poll now.']; }
        // High poll skip rate
        $skips = (int)($report['endpoints']['ds-poll']['skips'] ?? 0); $starts = (int)($report['endpoints']['ds-poll']['starts'] ?? 0);
        if ($skips > 3*$starts){ $issues[] = ['code'=>'poll_throttled','severity'=>'info','msg'=>'Polling is highly throttled (many skips vs starts).','suggest'=>'Reduce poll_min_interval_minutes for faster ingestion']; }
        return $issues;
    }

    private static function format_report_text($r){
        $lines = [];
        $lines[] = 'Time: '.$r['time'];
        $lines[] = '';
        $lines[] = 'Schedulers: AS='.( $r['schedulers']['action_scheduler']?'yes':'no' ).', WP‑Cron poll='.( $r['schedulers']['wp_cron_poll']?'yes':'no' ).', WP‑Cron refresh='.( $r['schedulers']['wp_cron_refresh']?'yes':'no' );
        $lines[] = '';
        $lines[] = 'Endpoints (last 24h):';
        foreach ($r['endpoints'] as $ep=>$m){
            if ($ep==='ds-poll') continue;
            $lines[] = sprintf(' - %s: post=%d, ingested=%d, warn=%d, error=%d', $ep, (int)($m['post_tasks']??0), (int)($m['handle_result']??0), (int)($m['warn']??0), (int)($m['error']??0));
        }
        if (isset($r['endpoints']['ds-poll'])){
            $lines[] = sprintf('Poller: starts=%d, skips=%d', (int)$r['endpoints']['ds-poll']['starts'], (int)$r['endpoints']['ds-poll']['skips']);
        }
        $lines[] = '';
        $lines[] = 'Coverage by language:';
        foreach ($r['coverage'] as $row){ $lines[] = sprintf(' - %s: %d keywords, intent %0.1f%%, %d FAQ', $row['lang'], $row['keywords'], $row['intent_covered_pct'], $row['faq']); }
        $r['bottlenecks'] = self::detect_bottlenecks($r);
        if (!empty($r['bottlenecks'])){
            $lines[] = '';
            $lines[] = 'Bottlenecks:';
            foreach ($r['bottlenecks'] as $b){ $lines[] = sprintf(' - [%s] %s (%s). Suggestion: %s', strtoupper($b['severity']), $b['code'], $b['msg'], $b['suggest']); }
        }
        return implode("\n", $lines);
    }

    private static function autofix_issues($report){
        // Always ensure single scheduler
        self::ensure_single_scheduler();
        // If Labs Related async 404 occurred, force related_mode to live
        $issues = self::detect_bottlenecks($report);
        $has404 = false; foreach ($issues as $b){ if ($b['code']==='labs_related_async_404'){ $has404=true; break; } }
        if ($has404){
            $raw = get_option(self::OPT_LIMITS,''); $limits = json_decode($raw, true); if (!is_array($limits)) $limits=[];
            if (!isset($limits['related_mode']) || strtolower((string)$limits['related_mode'])!=='live'){
                $limits['related_mode'] = 'live';
                update_option(self::OPT_LIMITS, wp_json_encode($limits));
                self::log_write('info','autofix: set related_mode=live', ['endpoint'=>'ds-autofix','action'=>'related_mode_live']);
            }
        }
        // For heavy queue backlog + very high poll throttle, only suggest (do not auto change limits)
        // Actions taken are logged above.
    }
}


    // ----- Scheduler self-healing -----
    public static function ensure_single_scheduler(){
        // If Action Scheduler exists, unschedule WP‑Cron duplicates for our hooks
        if (function_exists('as_schedule_recurring_action')){
            $hooks = [ self::CRON_REFRESH, self::CRON_POLL ];
            foreach ($hooks as $hook){
                while ($ts = wp_next_scheduled($hook)){
                    wp_unschedule_event($ts, $hook);
                }
            }
        }
    }

    // ----- Daily diagnostics & report -----
    public static function run_daily_diagnostics(){
        $report = self::build_diagnostics_report();
        // Email admin
        $to = get_option('admin_email');
        if ($to){
            $subject = 'Dreamli Keywords — Daily Report';
            $body = self::format_report_text($report);
            @wp_mail($to, $subject, $body);
        }
        // Log snapshot for UI/history
        self::log_write('info','daily report', ['endpoint'=>'ds-daily-report','action'=>'report:daily','response'=>$report]);
        // Optional conservative auto‑fixes
        self::autofix_issues($report);
    }

    private static function build_diagnostics_report(){
        return [
            'time'        => current_time('mysql'),
            'limits'      => self::get_limits(),
            'schedulers'  => self::detect_schedulers(),
            'endpoints'   => self::endpoint_metrics_last_24h(),
            'queue'       => self::queue_snapshot(),
            'coverage'    => self::coverage_by_language(),
            'bottlenecks' => [] // filled below
        ];
    }

    private static function detect_schedulers(){
        $has_as = function_exists('as_schedule_recurring_action');
        $wp_poll = wp_next_scheduled(self::CRON_POLL) ? true : false;
        $wp_refresh = wp_next_scheduled(self::CRON_REFRESH) ? true : false;
        return [ 'action_scheduler'=>$has_as, 'wp_cron_poll'=>$wp_poll, 'wp_cron_refresh'=>$wp_refresh ];
    }

    private static function queue_snapshot(){
        global $wpdb; $tq = self::table_queue();
        $rows = $wpdb->get_results("SELECT endpoint, status, COUNT(*) cnt FROM {$tq} GROUP BY endpoint, status", ARRAY_A);
        $old = $wpdb->get_results("SELECT endpoint, COUNT(*) cnt FROM {$tq} WHERE status='posted' AND created_at < DATE_SUB(NOW(), INTERVAL 2 HOUR) GROUP BY endpoint", ARRAY_A);
        return [ 'by_status'=>$rows, 'stale_posted_over_2h'=>$old ];
    }

    private static function coverage_by_language(){
        global $wpdb; $tk = self::table_keywords(); $tf = self::table_faq();
        $kw = $wpdb->get_results("SELECT lang, COUNT(*) cnt, SUM(CASE WHEN COALESCE(intent_main,'')<>'' THEN 1 ELSE 0 END) intent_cnt FROM {$tk} GROUP BY lang", ARRAY_A);
        $faq = $wpdb->get_results("SELECT lang, COUNT(*) cnt FROM {$tf} GROUP BY lang", ARRAY_A);
        $faq_map = [];
        foreach ($faq as $r){ $faq_map[$r['lang']] = (int)$r['cnt']; }
        $out = [];
        foreach ($kw as $r){
            $lang = (string)$r['lang'];
            $total = (int)$r['cnt'];
            $intent = (int)$r['intent_cnt'];
            $cov = $total>0 ? round(100.0*$intent/$total,1) : 0.0;
            $out[] = [ 'lang'=>$lang, 'keywords'=>$total, 'intent_covered_pct'=>$cov, 'faq'=> (int)($faq_map[$lang] ?? 0) ];
        }
        return $out;
    }

    private static function endpoint_metrics_last_24h(){
        global $wpdb; $tl = self::table_logs();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT endpoint, action, level, COUNT(*) cnt FROM {$tl} WHERE ts >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR) GROUP BY endpoint, action, level",
            24
        ), ARRAY_A);
        // Summaries per endpoint
        $by = [];
        foreach ($rows as $r){
            $ep = (string)$r['endpoint']; $ac = (string)$r['action']; $lv = (string)$r['level']; $cnt=(int)$r['cnt'];
            if (!isset($by[$ep])) $by[$ep] = [ 'post_tasks'=>0, 'handle_result'=>0, 'requests'=>0, 'responses'=>0, 'warn'=>0, 'error'=>0 ];
            if ($ac==='post_tasks') $by[$ep]['post_tasks'] += $cnt;
            if ($ac==='handle_result') $by[$ep]['handle_result'] += $cnt;
            if ($ac==='request') $by[$ep]['requests'] += $cnt;
            if ($ac==='response') $by[$ep]['responses'] += $cnt;
            if ($lv==='warn') $by[$ep]['warn'] += $cnt;
            if ($lv==='error') $by[$ep]['error'] += $cnt;
        }
        // Add poll skips vs starts
        $poll = $wpdb->get_results($wpdb->prepare("SELECT action, COUNT(*) cnt FROM {$tl} WHERE ts >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR) AND action IN ('cron_poll','poll_skip') GROUP BY action",24), ARRAY_A);
        $poll_map = []; foreach ($poll as $r){ $poll_map[$r['action']] = (int)$r['cnt']; }
        $by['ds-poll'] = [ 'starts'=> ($poll_map['cron_poll'] ?? 0), 'skips'=> ($poll_map['poll_skip'] ?? 0) ];
        return $by;
    }

    private static function detect_bottlenecks($report){
        $issues = [];
        // Duplicate schedulers
        if (!empty($report['schedulers']['action_scheduler']) && (!empty($report['schedulers']['wp_cron_poll']) || !empty($report['schedulers']['wp_cron_refresh']))){
            $issues[] = ['code'=>'duplicate_schedulers','severity'=>'warn','msg'=>'Both Action Scheduler and WP‑Cron are scheduled for ds_keywords_*; keep only AS to avoid duplicate pollers.','suggest'=>'Auto‑removed WP‑Cron duplicates'];
        }
        // Related async 404s
        global $wpdb; $tl = self::table_logs();
        $count404 = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$tl} WHERE ts >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 48 HOUR) AND endpoint='dataforseo_labs/google/related_keywords/task_post' AND http_code=404");
        if ($count404>0){ $issues[] = ['code'=>'labs_related_async_404','severity'=>'info','msg'=>'Labs Related async task_post returned 404 recently; Labs is live‑only.','suggest'=>'Force related_mode=live (autofix)']; }
        // Queue backlog (>2h posted)
        $stale_total = 0; foreach (($report['queue']['stale_posted_over_2h'] ?? []) as $r){ $stale_total += (int)$r['cnt']; }
        if ($stale_total>0){ $issues[] = ['code'=>'queue_backlog','severity'=>'warn','msg'=>'There are queued tasks posted >2h ago.','suggest'=>'Lower poll_min_interval temporarily or run poll now.']; }
        // High poll skip rate
        $skips = (int)($report['endpoints']['ds-poll']['skips'] ?? 0); $starts = (int)($report['endpoints']['ds-poll']['starts'] ?? 0);
        if ($skips > 3*$starts){ $issues[] = ['code'=>'poll_throttled','severity'=>'info','msg'=>'Polling is highly throttled (many skips vs starts).','suggest'=>'Reduce poll_min_interval_minutes for faster ingestion']; }
        return $issues;
    }

    private static function format_report_text($r){
        $lines = [];
        $lines[] = 'Time: '.$r['time'];
        $lines[] = '';
        $lines[] = 'Schedulers: AS='.( $r['schedulers']['action_scheduler']?'yes':'no' ).', WP‑Cron poll='.( $r['schedulers']['wp_cron_poll']?'yes':'no' ).', WP‑Cron refresh='.( $r['schedulers']['wp_cron_refresh']?'yes':'no' );
        $lines[] = '';
        $lines[] = 'Endpoints (last 24h):';
        foreach ($r['endpoints'] as $ep=>$m){
            if ($ep==='ds-poll') continue;
            $lines[] = sprintf(' - %s: post=%d, ingested=%d, warn=%d, error=%d', $ep, (int)($m['post_tasks']??0), (int)($m['handle_result']??0), (int)($m['warn']??0), (int)($m['error']??0));
        }
        if (isset($r['endpoints']['ds-poll'])){
            $lines[] = sprintf('Poller: starts=%d, skips=%d', (int)$r['endpoints']['ds-poll']['starts'], (int)$r['endpoints']['ds-poll']['skips']);
        }
        $lines[] = '';
        $lines[] = 'Coverage by language:';
        foreach ($r['coverage'] as $row){ $lines[] = sprintf(' - %s: %d keywords, intent %0.1f%%, %d FAQ', $row['lang'], $row['keywords'], $row['intent_covered_pct'], $row['faq']); }
        $r['bottlenecks'] = self::detect_bottlenecks($r);
        if (!empty($r['bottlenecks'])){
            $lines[] = '';
            $lines[] = 'Bottlenecks:';
            foreach ($r['bottlenecks'] as $b){ $lines[] = sprintf(' - [%s] %s (%s). Suggestion: %s', strtoupper($b['severity']), $b['code'], $b['msg'], $b['suggest']); }
        }
        return implode("\n", $lines);
    }

    private static function autofix_issues($report){
        // Always ensure single scheduler
        self::ensure_single_scheduler();
        // If Labs Related async 404 occurred, force related_mode to live
        $issues = self::detect_bottlenecks($report);
        $has404 = false; foreach ($issues as $b){ if ($b['code']==='labs_related_async_404'){ $has404=true; break; } }
        if ($has404){
            $raw = get_option(self::OPT_LIMITS,''); $limits = json_decode($raw, true); if (!is_array($limits)) $limits=[];
            if (!isset($limits['related_mode']) || strtolower((string)$limits['related_mode'])!=='live'){
                $limits['related_mode'] = 'live';
                update_option(self::OPT_LIMITS, wp_json_encode($limits));
                self::log_write('info','autofix: set related_mode=live', ['endpoint'=>'ds-autofix','action'=>'related_mode_live']);
            }
        }
        // For heavy queue backlog + very high poll throttle, only suggest (do not auto change limits)
        // Actions taken are logged above.
    }
