<?php
if (!defined('ABSPATH')) exit;

/**
 * Dreamli Embeddings: Index Products, Posts, and Site Options into OpenAI Vector Stores
 */
final class DS_Embeddings {
    // Options (settings)
    const OPT_VS_ID           = 'ds_embeddings_vector_store_id';
    const OPT_INCLUDE_PRODUCTS= 'ds_embeddings_include_products';
    const OPT_INCLUDE_POSTS   = 'ds_embeddings_include_posts';
    const OPT_MAX_DOC_BYTES   = 'ds_embeddings_max_doc_bytes';
    const OPT_MAX_FAQ         = 'ds_embeddings_max_faq';
    const OPT_BATCH_SIZE      = 'ds_embeddings_batch_size';
    const OPT_NIGHTLY_TIME    = 'ds_embeddings_nightly_time'; // HH:MM (24h)

    // Admin/AJAX
    const NONCE               = 'ds_embeddings_nonce';
    const ACTION_SYNC_NOW     = 'ds_embeddings_sync_now';

    // Status values
    const ST_NEW       = 'new';
    const ST_DIRTY     = 'dirty';
    const ST_QUEUED    = 'queued';
    const ST_INDEXING  = 'indexing';
    const ST_INDEXED   = 'indexed';
    const ST_ERROR     = 'error';
    const ST_TOMBSTONED= 'tombstoned';

    public static function init(){
        // Settings UI
        add_action('admin_menu', [__CLASS__, 'settings_page'], 25);
        add_action('admin_init', [__CLASS__, 'register_settings']);

        // Metaboxes (Products + Posts)
        add_action('add_meta_boxes_product', [__CLASS__, 'add_metabox_product']);
        add_action('add_meta_boxes_post',    [__CLASS__, 'add_metabox_post']);

        // Handle Sync Now
        add_action('admin_post_' . self::ACTION_SYNC_NOW, [__CLASS__, 'handle_sync_now']);
        add_action('admin_post_ds_embeddings_sync_site_options', [__CLASS__, 'handle_sync_site_options']);
        add_action('admin_post_ds_embeddings_clear_log', [__CLASS__, 'handle_clear_log']);

        // Dirty markers
        add_action('save_post', [__CLASS__, 'on_save_post'], 20, 2);
        add_action('trashed_post', [__CLASS__, 'on_trashed_post'], 10, 1);

        // ACF save hook (when available)
        if (function_exists('add_action')){
            add_action('acf/save_post', [__CLASS__, 'on_acf_save_post'], 20);
        }

        // Schedulers
        add_action('init', [__CLASS__, 'ensure_schedules']);
        add_action('ds_embeddings_nightly_event', [__CLASS__, 'nightly_run']);
        // Action Scheduler fallback/usage
        if (function_exists('as_enqueue_async_action')){
            add_action('ds_embeddings_enqueue_batch', [__CLASS__, 'run_batch_as']);
            add_action('ds_embeddings_poll_batch',    [__CLASS__, 'poll_batches_as']);
            add_action('ds_embeddings_poll_files',    [__CLASS__, 'poll_files_as']);
        }

        // Settings/Options change hooks (mark site options dirty)
        add_action('updated_option', function($option, $old, $new){
            if ($option === 'ds_settings') { self::mark_site_options_dirty(); }
        }, 10, 3);
    }

    /* ===================== Admin UI ===================== */
    public static function settings_page(){
        add_submenu_page('ds-root', 'Embeddings', 'Embeddings', 'manage_options', 'ds-embeddings', [__CLASS__, 'render_settings']);
        add_submenu_page('ds-root', 'Embeddings Logs', 'Embeddings Logs', 'manage_options', 'ds-embeddings-logs', [__CLASS__, 'render_logs_page']);
    }

    public static function register_settings(){
        register_setting('ds_embeddings', self::OPT_VS_ID, [ 'type'=>'string', 'sanitize_callback'=>'sanitize_text_field', 'default'=>'' ]);
        register_setting('ds_embeddings', self::OPT_INCLUDE_PRODUCTS, [ 'type'=>'string', 'sanitize_callback'=>'sanitize_text_field', 'default'=>'yes' ]);
        register_setting('ds_embeddings', self::OPT_INCLUDE_POSTS,    [ 'type'=>'string', 'sanitize_callback'=>'sanitize_text_field', 'default'=>'yes' ]);
        register_setting('ds_embeddings', self::OPT_MAX_DOC_BYTES,    [ 'type'=>'integer','sanitize_callback'=>'absint', 'default'=>60000 ]);
        register_setting('ds_embeddings', self::OPT_MAX_FAQ,          [ 'type'=>'integer','sanitize_callback'=>'absint', 'default'=>12 ]);
        register_setting('ds_embeddings', self::OPT_BATCH_SIZE,       [ 'type'=>'integer','sanitize_callback'=>'absint', 'default'=>150 ]);
        register_setting('ds_embeddings', self::OPT_NIGHTLY_TIME,     [ 'type'=>'string', 'sanitize_callback'=>function($v){ return preg_match('/^\d{2}:\d{2}$/',$v)?$v:'03:00'; }, 'default'=>'03:00' ]);
    }

    public static function render_settings(){
        if (!current_user_can('manage_options')) return;
        $vs_id = get_option(self::OPT_VS_ID, '');
        ?>
        <div class="wrap">
          <h1>Embeddings (OpenAI Vector Store)</h1>
          <form method="post" action="options.php">
            <?php settings_fields('ds_embeddings'); ?>
            <?php do_settings_sections('ds_embeddings'); ?>
            <table class="form-table" role="presentation">
              <tr>
                <th scope="row">Vector Store ID</th>
                <td>
                  <input type="text" name="<?php echo esc_attr(self::OPT_VS_ID); ?>" value="<?php echo esc_attr($vs_id); ?>" style="width:420px" placeholder="vs_..." />
                  <p class="description">Enter your OpenAI Vector Store ID. You can reuse the same ID as in AI Generator settings.</p>
                </td>
              </tr>
              <tr>
                <th scope="row">Include Products</th>
                <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPT_INCLUDE_PRODUCTS); ?>" value="yes" <?php checked(get_option(self::OPT_INCLUDE_PRODUCTS,'yes'),'yes'); ?>/> Yes</label></td>
              </tr>
              <tr>
                <th scope="row">Include Posts</th>
                <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPT_INCLUDE_POSTS); ?>" value="yes" <?php checked(get_option(self::OPT_INCLUDE_POSTS,'yes'),'yes'); ?>/> Yes</label></td>
              </tr>
              <tr>
                <th scope="row">Nightly Sync Time</th>
                <td>
                  <input type="text" name="<?php echo esc_attr(self::OPT_NIGHTLY_TIME); ?>" value="<?php echo esc_attr(get_option(self::OPT_NIGHTLY_TIME,'03:00')); ?>" placeholder="03:00" style="width:100px"/>
                  <p class="description">24h format HH:MM (server local time).</p>
                </td>
              </tr>
              <tr>
                <th scope="row">Batch Size</th>
                <td>
                  <input type="number" min="10" max="500" name="<?php echo esc_attr(self::OPT_BATCH_SIZE); ?>" value="<?php echo (int) get_option(self::OPT_BATCH_SIZE,150); ?>" />
                </td>
              </tr>
              <tr>
                <th scope="row">Max Doc Size (bytes)</th>
                <td>
                  <input type="number" min="10000" max="150000" step="1000" name="<?php echo esc_attr(self::OPT_MAX_DOC_BYTES); ?>" value="<?php echo (int) get_option(self::OPT_MAX_DOC_BYTES,60000); ?>" />
                </td>
              </tr>
              <tr>
                <th scope="row">Max FAQ items per product</th>
                <td>
                  <input type="number" min="1" max="50" name="<?php echo esc_attr(self::OPT_MAX_FAQ); ?>" value="<?php echo (int) get_option(self::OPT_MAX_FAQ,12); ?>" />
                </td>
              </tr>
            </table>
            <?php submit_button(); ?>
          </form>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:15px">
            <?php wp_nonce_field(self::NONCE, self::NONCE); ?>
            <input type="hidden" name="action" value="ds_embeddings_sync_site_options" />
            <button class="button">Queue Site Options for Sync</button>
          </form>
        </div>
        <?php
    }

    /* ===================== Logs ===================== */
    private static function log($msg){
        $ts = date('Y-m-d H:i:s');
        $line = '['.$ts.'] '.$msg;
        $buf = get_option('ds_embeddings_logs');
        if (!is_array($buf)) $buf = [];
        $buf[] = $line;
        if (count($buf) > 500) $buf = array_slice($buf, -500);
        update_option('ds_embeddings_logs', $buf, false);
    }
    public static function get_logs($limit = 200){
        $buf = get_option('ds_embeddings_logs');
        if (!is_array($buf)) $buf = [];
        $limit = max(1,(int)$limit);
        if (count($buf) > $limit) $buf = array_slice($buf, -$limit);
        return $buf;
    }
    public static function clear_logs(){ update_option('ds_embeddings_logs', [], false); }
    public static function render_logs_page(){
        if (!current_user_can('manage_options')) return;
        echo '<div class="wrap">';
        echo '<h1>Embeddings Logs</h1>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-bottom:10px">';
        wp_nonce_field(self::NONCE, self::NONCE);
        echo '<input type="hidden" name="action" value="ds_embeddings_clear_log" />';
        echo '<button class="button">Clear Logs</button>';
        echo '</form>';
        $lines = self::get_logs(400);
        echo '<pre style="max-height:520px;overflow:auto;background:#f6f7f7;border:1px solid #ddd;padding:10px;white-space:pre-wrap;">'.esc_html(implode("\n", $lines)).'</pre>';
        echo '</div>';
    }
    public static function handle_clear_log(){
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer(self::NONCE, self::NONCE);
        self::clear_logs();
        wp_redirect(wp_get_referer() ?: admin_url('admin.php?page=ds-embeddings-logs'));
        exit;
    }

    /* ===================== DB ===================== */
    public static function table_items(){ global $wpdb; return $wpdb->prefix . 'ds_embeddings'; }
    public static function table_batches(){ global $wpdb; return $wpdb->prefix . 'ds_embedding_batches'; }

    public static function install(){
        global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $t1 = self::table_items();
        $sql1 = "CREATE TABLE {$t1} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            doc_id VARCHAR(191) NOT NULL,
            post_id BIGINT UNSIGNED NULL,
            post_type VARCHAR(32) NULL,
            language VARCHAR(12) NULL,
            vector_store_id VARCHAR(64) NULL,
            file_id VARCHAR(64) NULL,
            batch_id VARCHAR(64) NULL,
            checksum CHAR(64) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'new',
            last_synced DATETIME NULL,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY doc_unique (doc_id),
            KEY post_lookup (post_id, post_type),
            KEY status_idx (status),
            KEY vs_idx (vector_store_id)
        ) $charset;";
        dbDelta($sql1);

        $t2 = self::table_batches();
        $sql2 = "CREATE TABLE {$t2} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vector_store_id VARCHAR(64) NOT NULL,
            batch_id VARCHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL,
            counts JSON NULL,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY batch_unique (batch_id),
            KEY vs_idx (vector_store_id)
        ) $charset;";
        dbDelta($sql2);
    }

    /* ===================== Metaboxes ===================== */
    public static function add_metabox_product(){
        add_meta_box('ds-emb-status', __('Embeddings Status','dreamli-dropship'), [__CLASS__, 'render_metabox'], 'product', 'side', 'high');
    }
    public static function add_metabox_post(){
        add_meta_box('ds-emb-status', __('Embeddings Status','dreamli-dropship'), [__CLASS__, 'render_metabox'], 'post', 'side', 'high');
    }

    public static function render_metabox($post){
        $row = self::get_row_for_post($post->ID, $post->post_type);
        $lang = self::get_post_language($post->ID);
        $cur_checksum = self::compute_checksum_for_post($post->ID, $post->post_type, $lang);
        $needs = ($row && $row->checksum && $cur_checksum && $row->checksum !== $cur_checksum) || (!$row);
        $status = $row ? $row->status : self::ST_NEW;
        $last_synced = $row ? $row->last_synced : '';
        $file_id = $row ? $row->file_id : '';
        $err = $row ? $row->last_error : '';
        $vs_id = get_option(self::OPT_VS_ID,'');
        ?>
        <p><strong>Status:</strong> <?php echo esc_html(strtoupper($status)); ?></p>
        <p><strong>Vector Store:</strong> <?php echo $vs_id? esc_html($vs_id) : '<em>not set</em>'; ?></p>
        <p><strong>Has file:</strong> <?php echo $file_id? 'Yes' : 'No'; ?></p>
        <p><strong>Needs update:</strong> <?php echo $needs? '<span style="color:#d63638">Yes</span>' : 'No'; ?></p>
        <?php if ($last_synced): ?><p><strong>Last synced:</strong> <?php echo esc_html($last_synced); ?></p><?php endif; ?>
        <?php if ($err): ?><p style="color:#d63638"><strong>Error:</strong> <?php echo esc_html(mb_substr($err,0,200)); ?></p><?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=' . self::ACTION_SYNC_NOW)); ?>">
            <?php wp_nonce_field(self::NONCE, self::NONCE); ?>
            <input type="hidden" name="post_id" value="<?php echo (int)$post->ID; ?>" />
            <input type="hidden" name="post_type" value="<?php echo esc_attr($post->post_type); ?>" />
            <button class="button button-primary" <?php disabled(!$vs_id); ?>><?php echo $row? 'Sync now' : 'Index now'; ?></button>
        </form>
        <?php
    }

    public static function handle_sync_now(){
        if (!current_user_can('edit_posts')) wp_die('Forbidden');
        check_admin_referer(self::NONCE, self::NONCE);
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';
        if ($post_id && $post_type){
            $lang = self::get_post_language($post_id);
            $doc_id = self::doc_id_for_post($post_id, $post_type, $lang);
            $checksum = self::compute_checksum_for_post($post_id, $post_type, $lang);
            self::upsert_row([ 'doc_id'=>$doc_id, 'post_id'=>$post_id, 'post_type'=>$post_type, 'language'=>$lang, 'checksum'=>$checksum, 'status'=>self::ST_QUEUED, 'last_error'=>null ]);
            self::log('SyncNow queued doc='.$doc_id.' pt='.$post_type.' pid='.$post_id.' checksum='.substr($checksum,0,8));
            // Immediately enqueue a batch run via Action Scheduler (if available), else run a short inline batch.
            if (function_exists('as_enqueue_async_action')) {
                try { as_enqueue_async_action('ds_embeddings_enqueue_batch', [], 'ds-embeddings'); self::log('Enqueued ds_embeddings_enqueue_batch via Action Scheduler'); }
                catch (\Throwable $e) { self::log('Enqueue ds_embeddings_enqueue_batch failed: '.substr($e->getMessage(),0,180)); }
            } else {
                self::log('Action Scheduler not available; running inline for 15s');
                if (method_exists(__CLASS__, 'run_batch_inline')) { self::run_batch_inline(15); }
            }
        }
        wp_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    public static function handle_sync_site_options(){
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer(self::NONCE, self::NONCE);
        $vs = get_option(self::OPT_VS_ID,'');
        if ($vs){
            $doc_id = 'site:options';
            self::upsert_row([ 'doc_id'=>$doc_id, 'post_id'=>null, 'post_type'=>null, 'language'=>null, 'checksum'=>hash('sha256', wp_json_encode(self::build_site_options_doc())), 'status'=>self::ST_QUEUED, 'last_error'=>null ]);
        }
        wp_redirect(wp_get_referer() ?: admin_url('admin.php?page=ds-embeddings'));
        exit;
    }

    /* ===================== Dirty markers ===================== */
    public static function on_save_post($post_id, $post){
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;
        $pt = $post->post_type;
        if (!in_array($pt, ['product','post'], true)) return;
        $opt_prod = get_option(self::OPT_INCLUDE_PRODUCTS,'yes') === 'yes';
        $opt_post = get_option(self::OPT_INCLUDE_POSTS,'yes') === 'yes';
        if (($pt==='product' && !$opt_prod) || ($pt==='post' && !$opt_post)) return;
        $lang = self::get_post_language($post_id);
        $checksum = self::compute_checksum_for_post($post_id, $pt, $lang);
        $doc_id = self::doc_id_for_post($post_id, $pt, $lang);
        self::mark_dirty($doc_id, [ 'post_id'=>$post_id, 'post_type'=>$pt, 'language'=>$lang, 'checksum'=>$checksum ]);
    }

    public static function on_acf_save_post($post_id){
        // If ACF updates a product/post, mark it dirty as well; if options page saved, mark site options dirty
        if ($post_id === 'options') {
            self::mark_site_options_dirty();
            return;
        }
        if (is_numeric($post_id)){
            $pid = (int)$post_id;
            $pt = get_post_type($pid);
            if (in_array($pt, ['product','post'], true)){
                $lang = self::get_post_language($pid);
                $checksum = self::compute_checksum_for_post($pid, $pt, $lang);
                $doc_id = self::doc_id_for_post($pid, $pt, $lang);
                self::mark_dirty($doc_id, [ 'post_id'=>$pid, 'post_type'=>$pt, 'language'=>$lang, 'checksum'=>$checksum ]);
            }
        }
    }

    public static function on_trashed_post($post_id){
        $pt = get_post_type($post_id);
        if (!in_array($pt, ['product','post'], true)) return;
        $lang = self::get_post_language($post_id);
        $doc_id = self::doc_id_for_post($post_id, $pt, $lang);
        self::update_row($doc_id, [ 'status'=> self::ST_TOMBSTONED ]);
    }

    /* ===================== Builders & Checksums ===================== */
    public static function doc_id_for_post($post_id, $post_type, $lang){
        $prefix = $post_type === 'product' ? 'product:' : 'post:';
        return $prefix . $post_id; // post IDs are per-language in Polylang; still add language as field
    }

    public static function compute_checksum_for_post($post_id, $post_type, $lang=''){
        // Build the full document and hash it to detect any relevant change
        if ($post_type === 'product'){
            $doc = self::build_product_doc($post_id, $lang);
        } else {
            $doc = self::build_post_doc($post_id, $lang);
        }
        if (!$doc){ return ''; }
        $doc = self::cap_doc_sizes($doc, (int)get_option(self::OPT_MAX_DOC_BYTES,60000));
        $json = wp_json_encode($doc);
        return hash('sha256', (string)$json);
    }

    public static function get_post_language($post_id){
        // Polylang integration if available
        if (function_exists('pll_get_post_language')){
            $lang = pll_get_post_language($post_id, 'slug');
            if ($lang) return (string)$lang;
        }
        return '';
    }

    /* ===================== Table helpers ===================== */
    public static function get_row_for_post($post_id, $post_type){
        global $wpdb; $t = self::table_items();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE post_id=%d AND post_type=%s ORDER BY id DESC LIMIT 1", $post_id, $post_type));
    }

    public static function upsert_row($arr){
        global $wpdb; $t = self::table_items();
        $arr = wp_parse_args($arr, [ 'doc_id'=>'', 'post_id'=>null, 'post_type'=>null, 'language'=>null, 'vector_store_id'=>get_option(self::OPT_VS_ID,''), 'file_id'=>null, 'batch_id'=>null, 'checksum'=>null, 'status'=>self::ST_NEW, 'last_synced'=>null, 'last_error'=>null ]);
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE doc_id=%s", $arr['doc_id']));
        if ($existing){
            $wpdb->update($t, [
                'post_id'=>$arr['post_id'], 'post_type'=>$arr['post_type'], 'language'=>$arr['language'], 'vector_store_id'=>$arr['vector_store_id'],
                'checksum'=>$arr['checksum'], 'status'=>$arr['status'], 'last_error'=>$arr['last_error']
            ], [ 'doc_id'=>$arr['doc_id'] ]);
        } else {
            $wpdb->insert($t, $arr);
        }
    }

    public static function update_row($doc_id, $data){
        global $wpdb; $t = self::table_items();
        $wpdb->update($t, $data, [ 'doc_id'=>$doc_id ]);
    }

    public static function mark_dirty($doc_id, $base){
        $row = self::get_row_by_doc($doc_id);
        if ($row){
            self::update_row($doc_id, [ 'checksum'=>$base['checksum'] ?? null, 'status'=> self::ST_DIRTY ]);
        } else {
            $base['doc_id'] = $doc_id;
            $base['status'] = self::ST_DIRTY;
            self::upsert_row($base);
        }
    }

    public static function get_row_by_doc($doc_id){
        global $wpdb; $t = self::table_items();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE doc_id=%s", $doc_id));
    }
    /* ===================== Scheduler ===================== */
    public static function ensure_schedules(){
        // Ensure a daily WP-Cron event at configured time
        $time = get_option(self::OPT_NIGHTLY_TIME,'03:00');
        if (!preg_match('/^(\d{2}):(\d{2})$/', $time, $m)) $time = '03:00';
        $h = (int)$m[1]; $min = (int)$m[2];
        $now = current_time('timestamp');
        $today = strtotime(date('Y-m-d ', $now) . sprintf('%02d:%02d:00', $h, $min), $now);
        if ($today <= $now) { $today = strtotime('+1 day', $today); }
        if (!wp_next_scheduled('ds_embeddings_nightly_event')){
            wp_schedule_event($today, 'daily', 'ds_embeddings_nightly_event');
        }
    }

    public static function nightly_run(){
        if (!self::acquire_lock('ds_embeddings_nightly', 90)) return;
        // Bootstrap: seed missing rows so backlog can be processed gradually
        self::seed_missing_rows(300);
        // Attempt to process tombstones (deletions)
        self::process_tombstones(20);
        // At 03:00 daily, enqueue a batch run (Action Scheduler if present), else do a tiny inline run
        if (function_exists('as_enqueue_async_action')){
            as_enqueue_async_action('ds_embeddings_enqueue_batch', [], 'ds-embeddings');
        } else {
            self::run_batch_inline(30); // 30s budget inline
        }
        self::release_lock('ds_embeddings_nightly');
    }

    /* ===================== Batch Runner ===================== */
    private static function get_api_key(){
        if (defined('OPENAI_API_KEY') && OPENAI_API_KEY) return OPENAI_API_KEY;
        if (class_exists('DS_AI_Product_SEO') && method_exists('DS_AI_Product_SEO','get_api_key')){
            // Reuse AI Generator key if set there
            $ref = @call_user_func(['DS_AI_Product_SEO','get_api_key']);
            if (!empty($ref)) return $ref;
        }
        return (string) get_option('ds_ai_openai_api_key','');
    }

    private static function http_json($method, $url, $payload, &$code_out=null){
        $api_key = self::get_api_key();
        $args = [
            'method' => $method,
            'headers'=> [
                'Content-Type'=>'application/json',
                'Authorization'=>'Bearer '.$api_key,
                'Expect'=>''
            ],
            'body'   => $payload!==null ? wp_json_encode($payload) : null,
            'timeout'=> 45,
            'sslverify'=> true,
        ];
        $res = wp_remote_request($url, $args);
        if (is_wp_error($res)) return [null, $res->get_error_message()];
        $code = wp_remote_retrieve_response_code($res);
        $code_out = $code;
        $body = wp_remote_retrieve_body($res);
        $parsed = json_decode($body, true);
        if ($code >=200 && $code<300) return [$parsed, null];
        return [null, $body ?: ('HTTP '.$code)];
    }

    private static function http_multipart_file_create($url, $filename, $content, $purpose='assistants', &$code_out=null){
        $api_key = self::get_api_key();
        $boundary = '--------------------------'.wp_generate_password(24,false,false);
        $eol = "\r\n";
        $parts  = '';
        $parts .= '--'.$boundary.$eol;
        $parts .= 'Content-Disposition: form-data; name="purpose"'.$eol.$eol;
        $parts .= $purpose.$eol;
        $parts .= '--'.$boundary.$eol;
        $parts .= 'Content-Disposition: form-data; name="file"; filename="'.basename($filename).'"'.$eol;
        $parts .= 'Content-Type: application/json'.$eol.$eol;
        $parts .= $content.$eol;
        $parts .= '--'.$boundary.'--'.$eol;

        $args = [
            'method' => 'POST',
            'headers'=> [
                'Content-Type'=>'multipart/form-data; boundary='.$boundary,
                'Authorization'=>'Bearer '.$api_key,
                'Expect'=>''
            ],
            'body'   => $parts,
            'timeout'=> 90,
            'sslverify'=> true,
        ];
        $res = wp_remote_post($url, $args);
        if (is_wp_error($res)) return [null, $res->get_error_message()];
        $code = wp_remote_retrieve_response_code($res);
        $code_out = $code;
        $body = wp_remote_retrieve_body($res);
        $parsed = json_decode($body, true);
        if ($code >=200 && $code<300) return [$parsed, null];
        return [null, $body ?: ('HTTP '.$code)];
    }

    private static function select_dirty_rows($limit){
        global $wpdb; $t = self::table_items(); $limit = max(1, (int)$limit);
        $vs = get_option(self::OPT_VS_ID,''); if (!$vs) return [];
        $sql = $wpdb->prepare("SELECT * FROM {$t} WHERE vector_store_id=%s AND status IN (%s,%s,%s) ORDER BY updated_at ASC LIMIT %d",
            $vs, self::ST_NEW, self::ST_DIRTY, self::ST_QUEUED, $limit);
        // Manual placeholders for IN due to prepare limitations
        $sql = str_replace(['%s,%s,%s'], ["'".esc_sql(self::ST_NEW)."','".esc_sql(self::ST_DIRTY)."','".esc_sql(self::ST_QUEUED)."'"], $sql);
        return $wpdb->get_results($sql);
    }

    public static function run_batch_as(){ self::run_batch_inline(25); }

    private static function run_batch_inline($time_budget_sec=25){
        $start = microtime(true);
        $vs_id = get_option(self::OPT_VS_ID,'');
        if (!$vs_id) { self::log('Batch skip: no Vector Store ID set.'); return; }
        $limit = (int) get_option(self::OPT_BATCH_SIZE, 150);
        self::log('Batch start vs='.$vs_id.' limit='.$limit);
        $rows = self::select_dirty_rows($limit);
        self::log('Batch selected '.count($rows).' row(s).');
        if (!$rows) { self::log('Batch: no dirty rows to process.'); return; }

        $file_ids = [];
        $processed_docs = [];
        foreach ($rows as $row){
            // Build JSON content
            $json = self::build_document_json($row);
            if (!$json) { self::update_row($row->doc_id, ['status'=>self::ST_ERROR,'last_error'=>'build_failed']); continue; }

            // Cap bytes
            $max = (int) get_option(self::OPT_MAX_DOC_BYTES, 60000);
            if (strlen($json) > $max) $json = substr($json, 0, $max - 3) . '...';

            // Upload file
            $code = null;
            list($resp, $err) = self::http_multipart_file_create('https://api.openai.com/v1/files', $row->doc_id.'.json', $json, 'assistants', $code);
            if ($err || !$resp || empty($resp['id'])){
                self::log('Upload failed doc='.$row->doc_id.' http='.(string)$code.' err='.substr((string)$err,0,160));
                self::update_row($row->doc_id, ['status'=>self::ST_ERROR, 'last_error'=> (string)$err ]);
                continue;
            }
            $file_id = (string)$resp['id'];
            self::log('Upload ok doc='.$row->doc_id.' file_id='.$file_id.' http='.(string)$code);
            self::update_row($row->doc_id, ['file_id'=>$file_id, 'status'=>self::ST_INDEXING]);
            $file_ids[] = $file_id;

            if ((microtime(true)-$start) > $time_budget_sec) break;
        }

        if ($file_ids){
            // Attach via vector store batch
            $payload = [ 'file_ids' => array_values(array_unique($file_ids)) ];
            $url = 'https://api.openai.com/v1/vector_stores/'.rawurlencode($vs_id).'/file_batches';
            $code = null; list($resp, $err) = self::http_json('POST', $url, $payload, $code);
            if ($err || !$resp || empty($resp['id'])){
                self::log('Attach failed vs_id='.$vs_id.' http='.(string)$code.' err='.substr((string)$err,0,160));
                // Rollback statuses
                foreach ($rows as $row){ if (in_array($row->file_id, $file_ids, true)) self::update_row($row->doc_id, ['status'=>self::ST_ERROR, 'last_error'=>'attach_failed: '.$err]); }
                return;
            }
            $batch_id = (string)$resp['id'];
            self::log('Attach ok vs_id='.$vs_id.' batch_id='.$batch_id.' http='.(string)$code.' files='.count($file_ids));
            // Update rows with batch_id
            foreach ($rows as $row){ if ($row->file_id && in_array($row->file_id, $file_ids, true)) self::update_row($row->doc_id, ['batch_id'=>$batch_id, 'status'=>self::ST_INDEXING]); }
            // Record batch
            self::upsert_batch_row($vs_id, $batch_id, 'in_progress');

            // Schedule poll in ~2 minutes
            if (function_exists('as_schedule_single_action')){
                // Schedule a timed poll (visible as Pending) and also enqueue an immediate poll to get faster feedback.
                as_schedule_single_action(time()+120, 'ds_embeddings_poll_batch', ['vector_store_id'=>$vs_id, 'batch_id'=>$batch_id], 'ds-embeddings');
                if (function_exists('as_enqueue_async_action')) {
                    as_enqueue_async_action('ds_embeddings_poll_batch', ['vector_store_id'=>$vs_id, 'batch_id'=>$batch_id], 'ds-embeddings');
                }
            } elseif (function_exists('as_enqueue_async_action')) {
                // Fallback: enqueue an immediate poll (no delay capability here)
                as_enqueue_async_action('ds_embeddings_poll_batch', ['vector_store_id'=>$vs_id, 'batch_id'=>$batch_id], 'ds-embeddings');
            } else {
                // Final fallback: WP‑Cron
                wp_schedule_single_event(time()+120, 'ds_embeddings_poll_batch', ['vector_store_id'=>$vs_id, 'batch_id'=>$batch_id]);
            }
        }
    }

    private static function upsert_batch_row($vs_id, $batch_id, $status, $counts=null, $last_error=null){
        global $wpdb; $t = self::table_batches();
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE batch_id=%s", $batch_id));
        $data = [ 'vector_store_id'=>$vs_id, 'status'=>$status, 'last_error'=>$last_error ];
        if ($counts !== null) $data['counts'] = wp_json_encode($counts);
        if ($exists){ $wpdb->update($t, $data, ['batch_id'=>$batch_id]); }
        else { $wpdb->insert($t, array_merge(['batch_id'=>$batch_id], $data)); }
    }

    public static function poll_batches_as($args){
        $vs_id = isset($args['vector_store_id']) ? (string)$args['vector_store_id'] : get_option(self::OPT_VS_ID,'');
        $batch_id = isset($args['batch_id']) ? (string)$args['batch_id'] : '';
        if (!$vs_id || !$batch_id) { self::log('Poll skip: missing vs_id or batch_id'); return; }
        $url = 'https://api.openai.com/v1/vector_stores/'.rawurlencode($vs_id).'/file_batches/'.rawurlencode($batch_id);
        $code=null; list($resp, $err) = self::http_json('GET',$url,null,$code);
        if ($err || !$resp){
            self::log('Poll error vs_id='.$vs_id.' batch_id='.$batch_id.' http='.(string)$code.' err='.substr((string)$err,0,160));
            self::upsert_batch_row($vs_id, $batch_id, 'error', null, $err);
            return;
        }
        $status = (string)($resp['status'] ?? '');
        $counts = $resp['counts'] ?? null;
        self::log('Poll ok vs_id='.$vs_id.' batch_id='.$batch_id.' http='.(string)$code.' status='.( $status ?: 'unknown' ).' counts='.json_encode($counts));
        self::upsert_batch_row($vs_id, $batch_id, $status ?: 'unknown', $counts, null);

        if ($status === 'completed' || $status === 'failed'){
            self::log('Poll: batch finished; reconciling files for batch_id='.$batch_id);
            // Reconcile per-file statuses by checking each row with this batch
            self::reconcile_batch($vs_id, $batch_id);
        } else {
            self::log('Poll: batch still in progress; scheduling another poll in 120s for batch_id='.$batch_id);
            // Re-schedule poll in ~2 minutes
            if (function_exists('as_schedule_single_action')){
                as_schedule_single_action(time()+120, 'ds_embeddings_poll_batch', ['vector_store_id'=>$vs_id, 'batch_id'=>$batch_id], 'ds-embeddings');
            } elseif (function_exists('as_enqueue_async_action')) {
                // Fallback: immediate async poll
                as_enqueue_async_action('ds_embeddings_poll_batch', ['vector_store_id'=>$vs_id, 'batch_id'=>$batch_id], 'ds-embeddings');
            } else {
                wp_schedule_single_event(time()+120, 'ds_embeddings_poll_batch', ['vector_store_id'=>$vs_id, 'batch_id'=>$batch_id]);
            }
        }
    }

    private static function reconcile_batch($vs_id, $batch_id){
        global $wpdb; $t = self::table_items();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE vector_store_id=%s AND batch_id=%s", $vs_id, $batch_id));
        if (!$rows) return;
        foreach ($rows as $row){
            if (!$row->file_id) { self::update_row($row->doc_id, ['status'=>self::ST_ERROR,'last_error'=>'missing_file_id']); continue; }
            $url = 'https://api.openai.com/v1/vector_stores/'.rawurlencode($vs_id).'/files/'.rawurlencode($row->file_id);
            $code=null; list($resp, $err) = self::http_json('GET',$url,null,$code);
            if ($err || !$resp){ self::update_row($row->doc_id, ['status'=>self::ST_ERROR,'last_error'=>'file_check_failed: '.$err]); continue; }
            $st = (string)($resp['status'] ?? '');
            if ($st === 'completed'){
                self::update_row($row->doc_id, ['status'=>self::ST_INDEXED, 'last_synced'=>DS_Helpers::now(), 'last_error'=>null]);
            } elseif ($st === 'failed') {
                $le = (string)($resp['last_error'] ?? '');
                self::update_row($row->doc_id, ['status'=>self::ST_ERROR, 'last_error'=>$le]);
            } else {
                // still processing; schedule another poll (2 minutes)
                if (function_exists('as_schedule_single_action')){
                    as_schedule_single_action(time()+120, 'ds_embeddings_poll_batch', ['vector_store_id'=>$vs_id, 'batch_id'=>$batch_id], 'ds-embeddings');
                } elseif (function_exists('as_enqueue_async_action')){
                    as_enqueue_async_action('ds_embeddings_poll_batch', ['vector_store_id'=>$vs_id, 'batch_id'=>$batch_id], 'ds-embeddings');
                }
            }
        }
    }

    /* ===================== Document Builders ===================== */
    private static function build_document_json($row){
        $doc = null;
        if ($row->doc_id === 'site:options'){
            $doc = self::build_site_options_doc();
        } elseif ($row->post_type === 'product'){
            $doc = self::build_product_doc((int)$row->post_id, (string)$row->language);
        } else {
            $doc = self::build_post_doc((int)$row->post_id, (string)$row->language);
        }
        if (!$doc) return '';
        // Enforce size limits on large text fields
        $doc = self::cap_doc_sizes($doc, (int)get_option(self::OPT_MAX_DOC_BYTES,60000));
        return wp_json_encode($doc);
    }

    private static function build_product_doc($post_id, $lang=''){
        if (!function_exists('wc_get_product')) return null;
        $p = get_post($post_id); if (!$p) return null;
        $prod = wc_get_product($post_id); if (!$prod) return null;
        $site_id = parse_url(home_url('/'), PHP_URL_HOST) ?: 'site';
        $permalink = get_permalink($post_id);
        $title = get_the_title($post_id);
        $short = wp_strip_all_tags(get_the_excerpt($post_id));
        $content = wp_strip_all_tags($p->post_content);
        $slug = $p->post_name;
        // Breadcrumbs (product_cat)
        $breadcrumbs = [];
        $terms = wp_get_post_terms($post_id, 'product_cat');
        if (!is_wp_error($terms) && !empty($terms)){
            $t = $terms[0]; $chain = [$t->name]; $anc = get_ancestors($t->term_id, 'product_cat');
            foreach(array_reverse($anc) as $aid){ $tt = get_term($aid, 'product_cat'); if ($tt && !is_wp_error($tt)) array_unshift($chain, $tt->name); }
            $breadcrumbs = $chain;
        }
        // Attributes (global/custom)
        $attributes = [];
        foreach ($prod->get_attributes() as $tax=>$attr){
            $name = is_string($tax) ? $tax : (method_exists($attr,'get_name')?$attr->get_name():'');
            $vals = [];
            if (method_exists($attr,'is_taxonomy') && $attr->is_taxonomy()){
                $tax_name = method_exists($attr,'get_name') ? $attr->get_name() : $name;
                $terms = wp_get_post_terms($post_id, $tax_name, ['fields'=>'names']);
                if (!is_wp_error($terms)) $vals = array_map('strval',$terms);
            } else if (method_exists($attr,'get_options')) {
                $opts = (array)$attr->get_options();
                $vals = array_map('strval', $opts);
            }
            if ($name) $attributes[$name] = $vals;
        }
        // Variations default attributes
        $default_atts = method_exists($prod,'get_default_attributes') ? (array)$prod->get_default_attributes() : [];
        // Specs
        $sku = (string) $prod->get_sku();
        $brand = '';
        if (taxonomy_exists('product_brand')){
            $brand_terms = wp_get_post_terms($post_id, 'product_brand', ['fields'=>'names']);
            if (!is_wp_error($brand_terms) && !empty($brand_terms)) $brand = (string)$brand_terms[0];
        }
        $weight = method_exists($prod,'get_weight') ? (string)$prod->get_weight() : '';
        $dimensions = method_exists($prod,'get_dimensions') ? wc_format_dimensions($prod->get_dimensions(false)) : '';
        // Commerce
        $price = (float)$prod->get_price();
        $regular_price = (float)$prod->get_regular_price();
        $sale_price = (float)$prod->get_sale_price();
        $currency = get_woocommerce_currency();
        $stock_status = (string)$prod->get_stock_status();
        $rating_avg = method_exists($prod,'get_average_rating') ? (float)$prod->get_average_rating() : 0.0;
        $rating_count = method_exists($prod,'get_rating_count') ? (int)$prod->get_rating_count() : 0;
        // Media alts
        $image_alts = [];
        $thumb_id = get_post_thumbnail_id($post_id);
        if ($thumb_id){ $alt = get_post_meta($thumb_id, '_wp_attachment_image_alt', true); if ($alt) $image_alts[] = (string)$alt; }
        $gallery = get_post_meta($post_id, '_product_image_gallery', true);
        if ($gallery){ foreach(array_filter(array_map('absint', explode(',', $gallery))) as $gid){ $alt = get_post_meta($gid, '_wp_attachment_image_alt', true); if ($alt) $image_alts[]=(string)$alt; if (count($image_alts)>=3) break; } }
        // ACF FAQ
        $faq_pairs = [];
        if (function_exists('get_field')){
            $faq = get_field('product_faq', $post_id);
            if (is_array($faq)){
                $max = max(1, (int) get_option(self::OPT_MAX_FAQ, 12));
                foreach ($faq as $row){
                    if (count($faq_pairs) >= $max) break;
                    $q = isset($row['question']) ? (string)$row['question'] : (isset($row['field_68d2304ed7bb8']) ? (string)$row['field_68d2304ed7bb8'] : '');
                    $a = isset($row['answer'])   ? (string)$row['answer']   : (isset($row['field_68d23092d7bb9']) ? (string)$row['field_68d23092d7bb9'] : '');
                    if ($q !== '' && $a !== '') $faq_pairs[] = ['q'=>$q,'a'=>$a];
                }
            }
        }
        $doc = [
            'doc_id' => 'product:'.$post_id,
            'site_id'=> $site_id,
            'post_id'=> $post_id,
            'post_type'=>'product',
            'language'=> $lang ?: '',
            'translation_group'=> function_exists('pll_get_post_translations') ? array_keys((array)pll_get_post_translations($post_id)) : [],
            'slug'   => $slug,
            'permalink'=> $permalink,
            'title'  => $title,
            'short_description'=> $short,
            'long_description' => $content,
            'breadcrumbs'=> $breadcrumbs,
            'attributes' => $attributes,
            'default_attributes' => $default_atts,
            'sku' => $sku,
            'brand'=> $brand,
            'weight'=> $weight,
            'dimensions'=> $dimensions,
            'price'=> $price,
            'regular_price'=> $regular_price,
            'sale_price'=> $sale_price,
            'currency'=> $currency,
            'stock_status'=> $stock_status,
            'rating_avg'=> $rating_avg,
            'rating_count'=> $rating_count,
            'image_alts'=> $image_alts,
            'faq'=> $faq_pairs,
            'modified_gmt'=> get_post_modified_time('c', true, $post_id),
        ];
        return $doc;
    }

    private static function build_post_doc($post_id, $lang=''){
        $p = get_post($post_id); if (!$p) return null;
        $site_id = parse_url(home_url('/'), PHP_URL_HOST) ?: 'site';
        $permalink = get_permalink($post_id);
        $title = get_the_title($post_id);
        $excerpt = wp_strip_all_tags(get_the_excerpt($post_id));
        $content = wp_strip_all_tags($p->post_content);
        $cats = wp_get_post_terms($post_id, 'category', ['fields'=>'names']); if (is_wp_error($cats)) $cats=[];
        $tags = wp_get_post_terms($post_id, 'post_tag', ['fields'=>'names']); if (is_wp_error($tags)) $tags=[];
        $doc = [
            'doc_id'=>'post:'.$post_id,
            'site_id'=>$site_id,
            'post_id'=>$post_id,
            'post_type'=>'post',
            'language'=> $lang ?: '',
            'translation_group'=> function_exists('pll_get_post_translations') ? array_keys((array)pll_get_post_translations($post_id)) : [],
            'slug'=>$p->post_name,
            'permalink'=>$permalink,
            'title'=>$title,
            'excerpt'=>$excerpt,
            'content'=>$content,
            'categories'=>$cats,
            'tags'=>$tags,
            'modified_gmt'=> get_post_modified_time('c', true, $post_id),
        ];
        return $doc;
    }

    private static function build_site_options_doc(){
        $site_id = parse_url(home_url('/'), PHP_URL_HOST) ?: 'site';
        $settings = class_exists('DS_Settings') && method_exists('DS_Settings','get') ? (array)DS_Settings::get() : [];
        $acf_opts = [];
        if (function_exists('get_fields')){
            $all = get_fields('option');
            if (is_array($all)){
                // Include everything as requested (no exclusions); keep it shallow to avoid binary blobs
                foreach ($all as $k=>$v){ if (is_scalar($v) || is_array($v)) $acf_opts[$k] = $v; }
            }
        }
        return [
            'doc_id'=>'site:options',
            'site_id'=>$site_id,
            'acf_options'=>$acf_opts,
            'ds_settings'=>$settings,
            'generated_at'=> current_time('c')
        ];
    }

    private static function cap_doc_sizes($doc, $max_bytes){
        // Trim very large strings and arrays to respect total size; heuristic
        $limits = [ 'long_description'=> 20000, 'content'=> 20000, 'short_description'=> 4000 ];
        foreach ($limits as $k=>$lim){ if (isset($doc[$k]) && is_string($doc[$k]) && strlen($doc[$k])>$lim) $doc[$k] = substr($doc[$k],0,$lim).'...'; }
        // FAQ
        if (!empty($doc['faq']) && is_array($doc['faq'])){
            $max = (int) get_option(self::OPT_MAX_FAQ, 12);
            if (count($doc['faq'])>$max) $doc['faq'] = array_slice($doc['faq'],0,$max);
            foreach ($doc['faq'] as &$qa){ if (is_string($qa['a']) && strlen($qa['a'])>2000) $qa['a']=substr($qa['a'],0,2000).'...'; }
        }
        // Loop reduce until total size under cap
        $json = wp_json_encode($doc);
        while (strlen($json) > $max_bytes){
            // progressively trim content first
            if (isset($doc['long_description']) && is_string($doc['long_description']) && strlen($doc['long_description'])>5000){
                $doc['long_description'] = substr($doc['long_description'],0, (int)(strlen($doc['long_description'])*0.7));
            } elseif (isset($doc['content']) && is_string($doc['content']) && strlen($doc['content'])>5000){
                $doc['content'] = substr($doc['content'],0, (int)(strlen($doc['content'])*0.7));
            } else {
                break;
            }
            $json = wp_json_encode($doc);
        }
        return $doc;
    }

    /* ===================== Seeding, Options & Deletions ===================== */
    private static function mark_site_options_dirty(){
        $vs = get_option(self::OPT_VS_ID,''); if (!$vs) return;
        $doc_id = 'site:options';
        $row = self::get_row_by_doc($doc_id);
        $checksum = hash('sha256', wp_json_encode(self::build_site_options_doc()));
        if ($row){
            self::update_row($doc_id, ['checksum'=>$checksum, 'status'=> self::ST_DIRTY]);
        } else {
            self::upsert_row([
                'doc_id'=>$doc_id,
                'post_id'=>null,
                'post_type'=>null,
                'language'=>null,
                'checksum'=>$checksum,
                'status'=> self::ST_DIRTY,
            ]);
        }
    }

    private static function seed_missing_rows($limit=300){
        $vs = get_option(self::OPT_VS_ID,''); if (!$vs) return 0;
        $seeded = 0;
        // Ensure site options exists
        self::mark_site_options_dirty();

        global $wpdb; $t = self::table_items();
        $limit = max(1,(int)$limit);
        // Products
        if (get_option(self::OPT_INCLUDE_PRODUCTS,'yes')==='yes'){
            $sql = $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$t} e ON (e.post_id=p.ID AND e.post_type=%s) WHERE p.post_type='product' AND p.post_status='publish' AND e.id IS NULL LIMIT %d",
                'product', $limit
            );
            $ids = $wpdb->get_col($sql);
            foreach ((array)$ids as $pid){
                $lang = self::get_post_language((int)$pid);
                $doc_id = self::doc_id_for_post((int)$pid, 'product', $lang);
                $checksum = self::compute_checksum_for_post((int)$pid, 'product', $lang);
                self::upsert_row([ 'doc_id'=>$doc_id, 'post_id'=>(int)$pid, 'post_type'=>'product', 'language'=>$lang, 'checksum'=>$checksum, 'status'=> self::ST_NEW ]);
                $seeded++;
                if ($seeded >= $limit) return $seeded;
            }
        }
        // Posts
        if (get_option(self::OPT_INCLUDE_POSTS,'yes')==='yes' && $seeded < $limit){
            $left = $limit - $seeded;
            $sql = $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$t} e ON (e.post_id=p.ID AND e.post_type=%s) WHERE p.post_type='post' AND p.post_status='publish' AND e.id IS NULL LIMIT %d",
                'post', $left
            );
            $ids = $wpdb->get_col($sql);
            foreach ((array)$ids as $pid){
                $lang = self::get_post_language((int)$pid);
                $doc_id = self::doc_id_for_post((int)$pid, 'post', $lang);
                $checksum = self::compute_checksum_for_post((int)$pid, 'post', $lang);
                self::upsert_row([ 'doc_id'=>$doc_id, 'post_id'=>(int)$pid, 'post_type'=>'post', 'language'=>$lang, 'checksum'=>$checksum, 'status'=> self::ST_NEW ]);
                $seeded++;
                if ($seeded >= $limit) return $seeded;
            }
        }
        return $seeded;
    }

    private static function process_tombstones($limit=20){
        global $wpdb; $t = self::table_items();
        $vs = get_option(self::OPT_VS_ID,''); if (!$vs) return 0;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE vector_store_id=%s AND status=%s AND file_id IS NOT NULL LIMIT %d", $vs, self::ST_TOMBSTONED, (int)$limit));
        if (!$rows) return 0;
        $done = 0;
        foreach ($rows as $row){
            $url = 'https://api.openai.com/v1/vector_stores/'.rawurlencode($vs).'/files/'.rawurlencode($row->file_id);
            $code=null; list($resp, $err) = self::http_json('DELETE', $url, null, $code);
            if ($code === 404 || ($code>=200 && $code<300)){
                self::update_row($row->doc_id, ['file_id'=>null, 'last_synced'=>DS_Helpers::now(), 'last_error'=>null]);
                $done++;
            } else {
                self::update_row($row->doc_id, ['last_error'=>'delete_failed: '.(string)$err]);
            }
        }
        return $done;
    }

    /* ===================== Run Locks ===================== */
    private static function acquire_lock($key='ds_embeddings_lock', $ttl=60){
        if (get_transient($key)) return false;
        set_transient($key, 1, $ttl);
        return true;
    }
    private static function release_lock($key='ds_embeddings_lock'){ delete_transient($key); }
}


