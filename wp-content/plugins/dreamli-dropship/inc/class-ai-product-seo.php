<?php
if (!defined('ABSPATH')) exit;

/* -------- Polyfills for older PHP -------- */
if (!function_exists('hash_equals')) {
    function hash_equals($a, $b) {
        if (!is_string($a) || !is_string($b)) return false;
        if (strlen($a) !== strlen($b)) return false;
        $res = 0;
        for ($i = 0; $i < strlen($a); $i++) $res |= ord($a[$i]) ^ ord($b[$i]);
        return $res === 0;
    }
}
if (!function_exists('fastcgi_finish_request')) {
    function fastcgi_finish_request() { /* noop */ }
}

if (!class_exists('DS_AI_Product_SEO')) :
final class DS_AI_Product_SEO {
    // ===== Options
    const OPT_API_KEY           = 'ds_ai_openai_api_key';
    const OPT_ALLOWED_MODELS    = 'ds_ai_allowed_models_csv'; // CSV
    const OPT_DEFAULT_MODEL     = 'ds_ai_default_model';
    const OPT_SYSTEM_PROMPT     = 'ds_ai_system_prompt';
    const OPT_PROMPT_GENERATE   = 'ds_ai_prompt_generate';
    const OPT_RELATED_LIMIT     = 'ds_ai_related_limit';       // چند محصول از کتگوری بخوانیم
    const OPT_CONTEXT_MAX_BYTES = 'ds_ai_context_max_bytes';   // سقف بایت JSON که به مدل می‌دهیم

    // Runner selection
    const OPT_RUNNER            = 'ds_ai_runner';  // 'as' (Action Scheduler) or 'direct' (Direct + WP-Cron)

    // Responses API specific
    const OPT_PROMPT_ID         = 'ds_ai_prompt_id';
    const OPT_PROMPT_VERSION    = 'ds_ai_prompt_version';
    const OPT_VECTOR_STORE_IDS  = 'ds_ai_vector_store_ids';    // CSV of vector_store_ids
    const OPT_ENABLE_WEB_SEARCH = 'ds_ai_enable_web_search';   // yes/no
    const OPT_SEARCH_CONTEXT    = 'ds_ai_search_context_size'; // small|medium|large
    const OPT_STORE_RESP        = 'ds_ai_store_response';      // yes/no
    const OPT_INCLUDE_FIELDS    = 'ds_ai_include_fields';      // CSV list of include fields

    // Pricing
    const OPT_GPT5_PRICE_EUR    = 'ds_ai_gpt5_price_eur';

    // ===== Ajax / hooks
    const NONCE        = 'ds_ai_nonce';
    const AJAX_ACTION  = 'ds_ai_product_ai';
    const AJAX_LOGS    = 'ds_ai_get_logs';
    const QUEUE_HOOK   = 'ds_ai_worker';

    // Direct async (no WP-Cron)
    const AJAX_DIRECT  = 'ds_ai_direct_worker';

    private static $err_trap_installed = false;

    public static function init(){
        // Settings
        add_action('admin_menu', [__CLASS__, 'settings_page'], 20);
        add_action('admin_init', [__CLASS__, 'register_settings']);

        // Product metabox
        add_action('add_meta_boxes_product', [__CLASS__, 'add_metabox']);
        add_action('save_post_product', [__CLASS__, 'save_product_overrides'], 10, 2);

        // Category fields (product_cat)
        add_action('product_cat_add_form_fields', [__CLASS__, 'cat_fields_add']);
        add_action('product_cat_edit_form_fields', [__CLASS__, 'cat_fields_edit'], 10, 2);
        add_action('created_product_cat', [__CLASS__, 'cat_fields_save']);
        add_action('edited_product_cat',  [__CLASS__, 'cat_fields_save']);

        // Ajax endpoints
        add_action('wp_ajax_' . self::AJAX_ACTION, [__CLASS__, 'handle']);
        add_action('wp_ajax_' . self::AJAX_LOGS,   [__CLASS__, 'handle_logs']);

        // Direct async worker (fire-and-forget)
        add_action('wp_ajax_' . self::AJAX_DIRECT, [__CLASS__, 'direct_worker']);
        add_action('wp_ajax_nopriv_' . self::AJAX_DIRECT, [__CLASS__, 'direct_worker']); // در صورت نیاز تست از فرانت

        // Optional: background hook (اگر بعداً خواستی AS استفاده کنی)
        add_action(self::QUEUE_HOOK, [__CLASS__, 'worker'], 10, 1);

        // Safer HTTP
        add_action('init', [__CLASS__, 'add_http_hardening']);

        // Allow very basic HTML in long desc
        add_filter('wp_kses_allowed_html', [__CLASS__, 'allow_basic_html'], 10, 2);
    }

    /* ===================== Settings ===================== */
    public static function settings_page(){
        // Move AI settings under Dropship admin menu
        add_submenu_page('ds-root', 'AI Generator', 'AI Generator', 'manage_options', 'ds-ai-generator', [__CLASS__, 'render_settings']);
    }
    public static function register_settings(){
        register_setting('ds_ai_generator', self::OPT_API_KEY, [
            'type'=>'string','sanitize_callback'=>'sanitize_text_field'
        ]);
        register_setting('ds_ai_generator', self::OPT_ALLOWED_MODELS, [
            'type'=>'string','sanitize_callback'=>'sanitize_text_field','default'=>'gpt-5,gpt-4o,gpt-4o-mini'
        ]);
        register_setting('ds_ai_generator', self::OPT_DEFAULT_MODEL, [
            'type'=>'string','sanitize_callback'=>'sanitize_text_field','default'=>'gpt-5'
        ]);
        register_setting('ds_ai_generator', self::OPT_SYSTEM_PROMPT, [
            'type'=>'string','sanitize_callback'=>'sanitize_textarea_field',
            'default'=>"You are an SEO copywriter for a WordPress WooCommerce shop. Create people-first content that answers real shopper questions, avoids keyword stuffing, and maximizes conversions."
        ]);
        register_setting('ds_ai_generator', self::OPT_PROMPT_GENERATE, [
            'type'=>'string','sanitize_callback'=>'wp_kses_post','default'=>self::default_generate_instructions()
        ]);
        register_setting('ds_ai_generator', self::OPT_RELATED_LIMIT, [
            'type'=>'integer','sanitize_callback'=>'absint','default'=>40
        ]);
        register_setting('ds_ai_generator', self::OPT_CONTEXT_MAX_BYTES, [
            'type'=>'integer','sanitize_callback'=>'absint','default'=>24000
        ]);

        // Responses API settings
        register_setting('ds_ai_generator', self::OPT_PROMPT_ID, [
            'type'=>'string','sanitize_callback'=>'sanitize_text_field','default'=>''
        ]);
        register_setting('ds_ai_generator', self::OPT_PROMPT_VERSION, [
            'type'=>'string','sanitize_callback'=>'sanitize_text_field','default'=>'4'
        ]);
        register_setting('ds_ai_generator', self::OPT_VECTOR_STORE_IDS, [
            'type'=>'string','sanitize_callback'=>'sanitize_text_field','default'=>''
        ]);
        register_setting('ds_ai_generator', self::OPT_ENABLE_WEB_SEARCH, [
            'type'=>'string','sanitize_callback'=>'sanitize_text_field','default'=>'yes'
        ]);
        register_setting('ds_ai_generator', self::OPT_SEARCH_CONTEXT, [
            'type'=>'string','sanitize_callback'=>'sanitize_text_field','default'=>'medium'
        ]);
        register_setting('ds_ai_generator', self::OPT_STORE_RESP, [
            'type'=>'string','sanitize_callback'=>'sanitize_text_field','default'=>'yes'
        ]);
        register_setting('ds_ai_generator', self::OPT_INCLUDE_FIELDS, [
            'type'=>'string','sanitize_callback'=>'sanitize_text_field','default'=>'reasoning.encrypted_content,web_search_call.action.sources'
        ]);
        register_setting('ds_ai_generator', self::OPT_GPT5_PRICE_EUR, [
            'type'=>'number','sanitize_callback'=>'floatval','default'=>0.00
        ]);
        // Runner selection (default to Action Scheduler)
        register_setting('ds_ai_generator', self::OPT_RUNNER, [
            'type'=>'string','sanitize_callback'=>'sanitize_text_field','default'=>'as'
        ]);
    }
    public static function render_settings(){
        if (!current_user_can('manage_options')) return;
        ?>
        <div class="wrap">
            <h1>DS AI Product Generator</h1>
            <form method="post" action="options.php">
                <?php settings_fields('ds_ai_generator'); ?>
                <?php do_settings_sections('ds_ai_generator'); ?>
                <table class="form-table" role="presentation">
                    <tr><th>API Key</th>
                        <td>
                            <input type="password" name="<?php echo esc_attr(self::OPT_API_KEY); ?>" value="<?php echo esc_attr(get_option(self::OPT_API_KEY,'')); ?>" style="width:420px" placeholder="sk-..." />
                            <p class="description">Alternatively define <code>OPENAI_API_KEY</code> in wp-config.php.</p>
                        </td></tr>
                    <tr><th>GPT‑5 price per request (EUR)</th>
                        <td><input type="number" name="<?php echo esc_attr(self::OPT_GPT5_PRICE_EUR); ?>" value="<?php echo esc_attr(get_option(self::OPT_GPT5_PRICE_EUR, 0)); ?>" step="0.01" min="0" style="width:140px" />
                            <p class="description">This amount will be charged to the product author's wallet for each AI generation request.</p></td></tr>
                    <tr><th>System Prompt</th>
                        <td><textarea name="<?php echo esc_attr(self::OPT_SYSTEM_PROMPT); ?>" rows="4" style="width:100%;max-width:800px"><?php echo esc_textarea(get_option(self::OPT_SYSTEM_PROMPT)); ?></textarea></td></tr>
                    <tr><th>Generation Instructions</th>
                        <td><textarea name="<?php echo esc_attr(self::OPT_PROMPT_GENERATE); ?>" rows="18" style="width:100%;max-width:800px"><?php echo esc_textarea(get_option(self::OPT_PROMPT_GENERATE, self::default_generate_instructions())); ?></textarea></td></tr>
                    <tr><th>Related product links (fetch limit)</th>
                        <td><input type="number" min="0" max="200" name="<?php echo esc_attr(self::OPT_RELATED_LIMIT); ?>"
                                   value="<?php echo (int) get_option(self::OPT_RELATED_LIMIT, 40); ?>" />
                            <p class="description">Max number of related products fetched from the same category.</p></td></tr>
                    <tr><th>Context budget for related list (bytes)</th>
                        <td><input type="number" min="1000" max="200000" step="1000" name="<?php echo esc_attr(self::OPT_CONTEXT_MAX_BYTES); ?>"
                                   value="<?php echo (int) get_option(self::OPT_CONTEXT_MAX_BYTES, 24000); ?>" />
                            <p class="description">We will trim the RELATED PRODUCTS JSON to stay under this byte budget before sending to the model.</p></td></tr>

                    <tr><th colspan="2"><h2 style="margin-top:20px">Responses API</h2></th></tr>
                    <tr><th>Prompt ID</th>
                        <td><input type="text" name="<?php echo esc_attr(self::OPT_PROMPT_ID); ?>" value="<?php echo esc_attr(get_option(self::OPT_PROMPT_ID,'')); ?>" style="width:420px" placeholder="pmpt_..." />
                            <p class="description">If set, we will call <code>/v1/responses</code> with this Prompt. If empty, we will still call <code>/v1/responses</code> but use GPT‑5.</p>
                        </td></tr>
                    <tr><th>Prompt Version</th>
                        <td><input type="text" name="<?php echo esc_attr(self::OPT_PROMPT_VERSION); ?>" value="<?php echo esc_attr(get_option(self::OPT_PROMPT_VERSION,'3')); ?>" style="width:120px" /></td></tr>
                    <tr><th>Vector Store IDs (CSV)</th>
                        <td><input type="text" name="<?php echo esc_attr(self::OPT_VECTOR_STORE_IDS); ?>" value="<?php echo esc_attr(get_option(self::OPT_VECTOR_STORE_IDS,'')); ?>" style="width:100%;max-width:800px" placeholder="vs_xxx,vs_yyy" /></td></tr>
                    <tr><th>Enable Web Search</th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPT_ENABLE_WEB_SEARCH); ?>" value="yes" <?php checked(get_option(self::OPT_ENABLE_WEB_SEARCH,'yes'),'yes'); ?>/> Allow web_search tool</label></td></tr>
                    <tr><th>Web Search Context Size</th>
                        <td><select name="<?php echo esc_attr(self::OPT_SEARCH_CONTEXT); ?>">
                            <?php foreach(['small','medium','large'] as $sz): ?>
                                <option value="<?php echo esc_attr($sz); ?>" <?php selected(get_option(self::OPT_SEARCH_CONTEXT,'medium'),$sz); ?>><?php echo esc_html(ucfirst($sz)); ?></option>
                            <?php endforeach; ?>
                        </select></td></tr>
                    <tr><th>Store Responses</th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPT_STORE_RESP); ?>" value="yes" <?php checked(get_option(self::OPT_STORE_RESP,'yes'),'yes'); ?>/> Store responses on OpenAI</label></td></tr>
                    <tr><th>Include fields (CSV)</th>
                    <td><input type="text" name="<?php echo esc_attr(self::OPT_INCLUDE_FIELDS); ?>" value="<?php echo esc_attr(get_option(self::OPT_INCLUDE_FIELDS,'reasoning.encrypted_content,web_search_call.action.sources')); ?>" style="width:100%;max-width:800px" />
                        <p class="description">Fields to include from the response object.</p></td></tr>

                    <tr><th colspan="2"><h2 style="margin-top:20px">Background Runner</h2></th></tr>
                    <tr><th>Runner</th>
                        <td>
                            <?php $runner = get_option(self::OPT_RUNNER, 'as'); $has_as = function_exists('as_enqueue_async_action'); ?>
                            <select name="<?php echo esc_attr(self::OPT_RUNNER); ?>">
                                <option value="as" <?php selected($runner,'as'); ?>>Action Scheduler <?php echo $has_as?'':'(not installed)'; ?></option>
                                <option value="direct" <?php selected($runner,'direct'); ?>>Direct + WP‑Cron fallback</option>
                            </select>
                            <p class="description">Recommended: Action Scheduler (visible jobs, concurrency control). If not installed, select Direct + WP‑Cron.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /* ===================== Category fields ===================== */
    public static function cat_fields_add(){ ?>
        <div class="form-field">
            <label for="ds_cat_keywords">Keywords (comma-separated)</label>
            <input type="text" name="ds_cat_keywords" id="ds_cat_keywords" value="" />
            <p class="description">مثلاً: vaas, tafel lamp, 3D print decor</p>
        </div>
        <div class="form-field">
            <label for="ds_cat_url">Category URL (internal)</label>
            <input type="text" name="ds_cat_url" id="ds_cat_url" value="" placeholder="/category/vases/"/>
            <p class="description">یک URL برای لینک داخلی. فرمت: /category/{type}/</p>
        </div>
    <?php }
    public static function cat_fields_edit($term, $taxonomy){
        $kws = get_term_meta($term->term_id, 'ds_cat_keywords', true);
        $url = get_term_meta($term->term_id, 'ds_cat_url', true); ?>
        <tr class="form-field">
            <th scope="row"><label for="ds_cat_keywords">Keywords (any language)</label></th>
            <td><input name="ds_cat_keywords" id="ds_cat_keywords" type="text" value="<?php echo esc_attr($kws); ?>" class="regular-text" /></td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="ds_cat_url">Category URL</label></th>
            <td><input name="ds_cat_url" id="ds_cat_url" type="text" value="<?php echo esc_attr($url); ?>" class="regular-text" placeholder="/category/vases/" /></td>
        </tr>
    <?php }
    public static function cat_fields_save($term_id){
        if (isset($_POST['ds_cat_keywords'])) update_term_meta($term_id, 'ds_cat_keywords', sanitize_text_field(wp_unslash($_POST['ds_cat_keywords'])));
        if (isset($_POST['ds_cat_url']))      update_term_meta($term_id, 'ds_cat_url', sanitize_text_field(wp_unslash($_POST['ds_cat_url'])));
    }

    /* ===================== Product Metabox ===================== */
    public static function add_metabox(){
        add_meta_box('ds_ai_box','AI: Product Content & Chat',[__CLASS__,'render_metabox'],'product','side','high');
    }
    public static function render_metabox($post){
        if (!current_user_can('edit_post', $post->ID)) { echo '<p>Insufficient permissions.</p>'; return; }
        wp_nonce_field(self::NONCE, self::NONCE);


        // overrides per product
        $ext_link = get_post_meta($post->ID, '_ds_ai_external_url', true);
        $ovr_kws  = get_post_meta($post->ID, '_ds_ai_dutch_kws', true);
        $ovr_story= get_post_meta($post->ID, '_ds_ai_story', true);

        $has_cat = !empty(wp_get_post_terms($post->ID, 'product_cat', ['fields'=>'ids']));
        $has_img = (bool) get_post_thumbnail_id($post->ID) || !empty(get_post_meta($post->ID,'_product_image_gallery',true));
        ?>
        <div style="margin-bottom:8px">
            <p style="margin:0 0 6px;">Category set: <strong><?php echo $has_cat?'Yes':'No'; ?></strong></p>
            <p style="margin:0 0 6px;">Has images: <strong><?php echo $has_img?'Yes':'No'; ?></strong></p>
        </div>


        <p><label><strong>External link (optional)</strong></label>
            <input type="text" id="ds-ai-external-link" style="width:100%" placeholder="https://example.com/..." value="<?php echo esc_attr($ext_link); ?>"/>
        </p>
        <p><label><strong>Additional keywords (optional)</strong></label>
            <textarea id="ds-ai-ovr-kws" rows="2" style="width:100%" placeholder="long-tail, material, style"><?php echo esc_textarea($ovr_kws); ?></textarea>
        </p>
        <p><label><strong>Personal story (optional)</strong></label>
            <textarea id="ds-ai-ovr-story" rows="2" style="width:100%" placeholder="1–3 sentences for human touch"><?php echo esc_textarea($ovr_story); ?></textarea>
        </p>

        <button type="button" class="button button-primary" id="ds-ai-run" <?php disabled(!$has_cat || !$has_img, true); ?>>Queue Generate</button>

        <div style="margin-top:10px">
            <strong>Live Log</strong>
            <pre id="ds-ai-live-log" style="white-space:pre-wrap;height:200px;overflow:auto;border:1px solid #e2e2e2;padding:6px;background:#0b0b0b;color:#00ff8a;font-family:monospace;font-size:12px"></pre>
        </div>

        <script>
        if (!window.__DS_AI_BOX__) { window.__DS_AI_BOX__ = true;
        (function(){
            const $ = (s,c=document)=>c.querySelector(s);
            const live = $('#ds-ai-live-log');
            const runBtn  = $('#ds-ai-run');
            const ext  = $('#ds-ai-external-link');
            const kws  = $('#ds-ai-ovr-kws');
            const story= $('#ds-ai-ovr-story');
            const postId = <?php echo (int)$post->ID; ?>;
            const nonce = '<?php echo esc_js(wp_create_nonce(self::NONCE)); ?>';
            let pollTimer = null, last = 0, currentRunId = null;

            function uid(){ return 'r'+Date.now().toString(36)+Math.random().toString(36).slice(2,8); }
            function log(t){ live.textContent += t+"\n"; live.scrollTop = live.scrollHeight; }
            function clearLog(){ live.textContent = ''; last = 0; }

            async function poll(){
                if (!currentRunId) return;
                try{
                    const fd = new FormData();
                    fd.append('action','<?php echo esc_js(self::AJAX_LOGS); ?>');
                    fd.append('<?php echo esc_js(self::NONCE); ?>', nonce);
                    fd.append('run_id', currentRunId);
                    fd.append('since', String(last));
                    const res = await fetch(ajaxurl,{method:'POST', body:fd});
                    const data = await res.json();
                    if (data?.success){
                        const lines = data.data?.lines || [];
                        if (lines.length){
                            lines.forEach(l=>log(l));
                            last = data.data.total || last;
                        }
                    }
                }catch(e){ /* ignore */ }
            }

            async function call(op){
                runBtn.disabled = true; runBtn.textContent = 'Queueing…';
                currentRunId = uid(); clearLog();
                try{
                    const fd = new FormData();
                    fd.append('action','<?php echo esc_js(self::AJAX_ACTION); ?>');
                    fd.append('<?php echo esc_js(self::NONCE); ?>', nonce);
                    fd.append('op', op);
                    fd.append('post_id', String(postId));
                    fd.append('run_id', currentRunId);
                    fd.append('external_link', ext.value);
                    fd.append('ovr_kws', kws.value);
                    fd.append('ovr_story', story.value);

                    if (pollTimer) clearInterval(pollTimer);
                    pollTimer = setInterval(poll, 2500);
                    poll();

                    const res = await fetch(ajaxurl,{method:'POST', body:fd});
                    const data = await res.json();
                    if (!data?.success) throw new Error(data?.message||'Queue failed');
                    log(data.data.msg || 'Queued.');
                } catch(e){
                    log('ERROR: '+ e.message);
                } finally{
                    runBtn.disabled = false; runBtn.textContent = 'Queue Generate';
                }
            }

            runBtn.addEventListener('click', ()=>call('generate'));
        })(); }
        </script>
        <?php
    }
    public static function save_product_overrides($post_id, $post){
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if ($post->post_type !== 'product') return;
    }

    /* ===================== Ajax: Logs ===================== */
    public static function handle_logs(){
        if (!current_user_can('edit_products')) self::json_error('Permission denied', 403);
        if (!wp_verify_nonce($_POST[self::NONCE] ?? '', self::NONCE)) self::json_error('Bad nonce', 400);
        $run_id = sanitize_text_field($_POST['run_id'] ?? '');
        $since  = intval($_POST['since'] ?? 0);
        $all = get_transient(self::log_key($run_id));
        if (!is_array($all)) $all = [];
        $slice = array_slice($all, $since);
        self::json_success(['lines'=>$slice,'total'=>count($all)]);
    }

    /* ===================== Ajax: Queue (spawns direct async) ===================== */
    public static function handle(){
        $run_id = sanitize_text_field($_POST['run_id'] ?? '');
        self::trap_start($run_id);

        if (!current_user_can('edit_products')) self::json_error('Permission denied', 403);
        if (!wp_verify_nonce($_POST[self::NONCE] ?? '', self::NONCE)) self::json_error('Bad nonce', 400);

        $post_id = (int)($_POST['post_id'] ?? 0);
        $model   = 'gpt-5';
        $op      = sanitize_text_field($_POST['op'] ?? 'generate');

        $external_link = sanitize_text_field($_POST['external_link'] ?? '');
        $ovr_kws       = sanitize_text_field($_POST['ovr_kws'] ?? '');
        $ovr_story     = sanitize_textarea_field($_POST['ovr_story'] ?? '');

        if (!$post_id) self::json_error('Missing post_id', 400);
        if ($op !== 'generate') self::json_error('Unsupported op', 400);

        // Persist overrides
        if ($external_link !== '') update_post_meta($post_id, '_ds_ai_external_url', $external_link);
        if ($ovr_kws !== '')       update_post_meta($post_id, '_ds_ai_dutch_kws', $ovr_kws);
        if ($ovr_story !== '')     update_post_meta($post_id, '_ds_ai_story', $ovr_story);

        self::log_start($run_id);
        self::log($run_id, date('H:i:s') . " — Queueing op={$op} model={$model} post_id={$post_id}");

        $args = ['post_id'=>$post_id,'model'=>$model,'op'=>$op,'run_id'=>$run_id];

        $runner = get_option(self::OPT_RUNNER, 'as');
        if ($runner === 'as' && function_exists('as_enqueue_async_action')) {
            // Enqueue via Action Scheduler
            $args['source'] = 'action-scheduler';
            try {
                $job_id = as_enqueue_async_action(self::QUEUE_HOOK, [$args], 'ds-ai');
                self::log($run_id, 'Enqueued Action Scheduler job #' . $job_id . ' (source=action-scheduler).');
                self::json_success(['msg'=>'Queued. Action Scheduler will process this job shortly…']);
            } catch (Exception $e) {
                self::log($run_id, 'Action Scheduler enqueue failed: ' . $e->getMessage() . ' — falling back to Direct + WP‑Cron.');
                // Fall through to direct path below
            }
        }

        // Fall back to Direct + WP‑Cron
        self::spawn_direct($args, $run_id);
        self::schedule_fallback($args, $run_id, 10);
        self::json_success(['msg'=>'Queued. Background worker will process…']);
    }

    /* ===================== Direct async worker endpoint ===================== */
    public static function direct_worker() {
        $rid = sanitize_text_field($_POST['run_id'] ?? '');
        self::trap_start($rid);
        ignore_user_abort(true);
        self::log($rid, 'Direct worker endpoint hit.');

        if (!empty($_SERVER['REQUEST_METHOD']) && strtolower($_SERVER['REQUEST_METHOD']) === 'post') {
            $post_id = (int)($_POST['post_id'] ?? 0);
            $model   = 'gpt-5';
            $op      = sanitize_text_field($_POST['op'] ?? '');
            $run_id  = $rid;
            $sig     = sanitize_text_field($_POST['sig'] ?? '');

            $args = ['post_id'=>$post_id,'model'=>$model,'op'=>$op,'run_id'=>$run_id];
            $sig_ok = self::verify_sig($args, $sig);
            self::log($run_id, 'Direct worker received args: post_id=' . $post_id . ' op=' . $op . ' sig_ok=' . ($sig_ok ? 'yes' : 'no'));

            if ($post_id && $op === 'generate' && $sig_ok) {
                header('Content-Type: text/plain; charset=utf-8');
                echo 'OK';
                @fastcgi_finish_request();
                $args['source'] = 'direct';
                self::log($run_id, 'Direct worker dispatching to worker(source=direct)…');
                self::worker($args);
                wp_die();
            } else {
                self::log($run_id, 'Direct worker early exit: invalid args or signature.');
                self::mark_failed($run_id, 'direct_invalid_args_or_sig');
            }
        } else {
            self::log($rid, 'Direct worker called with non-POST method.');
            self::mark_failed($rid, 'direct_non_post');
        }
    status_header(400);
    echo 'BAD';
    wp_die();
    }

    /* ===================== Worker ===================== */
    public static function worker($args){
        $run_id = sanitize_text_field($args['run_id'] ?? '');
        self::trap_start($run_id);

        // Prevent duplicate runs (direct spawn, WP‑Cron fallback, or AS)
        $src = isset($args['source']) ? (string)$args['source'] : 'unknown';
        $started_key = self::started_key($run_id);
        $done = get_transient(self::done_key($run_id));
        $failed = get_transient(self::failed_key($run_id));
        $prev = get_transient($started_key);
        if ($prev && !$failed && !$done) {
            $prev_by = is_array($prev) ? ($prev['by'] ?? 'unknown') : 'unknown';
            $prev_t  = (int) (is_array($prev) ? ($prev['t'] ?? 0) : 0);
            $age = time() - $prev_t;
            if ($age > 120) {
                self::log($run_id, 'No heartbeat/finish for >120s; allowing recovery retry. Previous by=' . $prev_by . ' at ' . ($prev_t?date('H:i:s',$prev_t):'n/a'));
                // Overwrite started latch for recovery
                set_transient($started_key, ['by'=>$src ?: 'unknown','t'=>time()], 60*MINUTE_IN_SECONDS);
            } else {
                self::log($run_id, 'Duplicate worker invocation detected; exiting. First started by=' . $prev_by . ' at ' . ($prev_t?date('H:i:s',$prev_t):'n/a'));
                return;
            }
        } else {
            if (!self::mark_started($run_id, $src)) {
                $prev = get_transient($started_key);
                $prev_by = is_array($prev) ? ($prev['by'] ?? 'unknown') : 'unknown';
                $prev_t  = (int) (is_array($prev) ? ($prev['t'] ?? 0) : 0);
                self::log($run_id, 'Duplicate worker invocation detected; exiting. First started by=' . $prev_by . ' at ' . ($prev_t?date('H:i:s',$prev_t):'n/a'));
                return;
            }
        }

        $post_id = (int)($args['post_id'] ?? 0);
        $model   = 'gpt-5';
        $op      = sanitize_text_field($args['op'] ?? 'generate');

        self::log($run_id,"Worker start op={$op} model={$model} post_id={$post_id} source={$src}");

        $api_key = self::get_api_key();
        if (!$api_key){
            self::log($run_id,'ERROR: API key missing (define OPENAI_API_KEY or set in settings).');
            self::mark_failed($run_id, 'missing_api_key');
            return;
        } else {
            self::log($run_id,'API key OK (masked): '.substr($api_key,0,4).'•••'.substr($api_key,-4));
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type!=='product'){ self::log($run_id,'ERROR: Invalid product'); self::mark_failed($run_id,'invalid_product'); return; }

        // Build context
        self::log($run_id,'Building context...');
        $ctx = self::build_context($post_id);
        if (!$ctx['has_cat'])   { self::log($run_id,'ERROR: No category on product'); self::mark_failed($run_id,'no_category'); return; }
        if (!$ctx['has_images']){ self::log($run_id,'ERROR: No images on product');   self::mark_failed($run_id,'no_images'); return; }
        self::log($run_id,'Context OK: images='.count($ctx['img_urls']).', cat_chains='.count($ctx['cat_names']).', kws=' . (!empty($ctx['keywords']) ? 1 : 0));
        self::log($run_id,'Category URL: ' . (!empty($ctx['category_url']) ? $ctx['category_url'] : '[none]'));

        // Messages
        self::log($run_id,'Building messages…');
        $system = (string) get_option(self::OPT_SYSTEM_PROMPT, "You are an SEO copywriter...");
        $gen    = (string) get_option(self::OPT_PROMPT_GENERATE, self::default_generate_instructions());

        $user_inputs = [
            "PRODUCT INFORMATION" => [
                "product_name"     => $post->post_title,
                "product_category" => $ctx['primary_cat_name'],
                "keywords"         => $ctx['keywords'],
                "note"             => "Images attached below. Analysis allowed."
            ],
            "INTERNAL LINKS (Optional)" => [
                "category_url" => $ctx['category_url'] ?? ''
            ],
            "EXTERNAL LINK (Optional)" => $ctx['external_link'],
            "USER EXPERIENCE INPUT (Optional)" => $ctx['story'] ?: ''
        ];
        $lines = [];
        $lines[] = "Follow the instructions strictly below.";
        $lines[] = "=== REQUIRED USER INPUTS (provided by system) ===";
        $lines[] = wp_json_encode($user_inputs, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $lines[] = "=== INSTRUCTIONS ===";
        $lines[] = trim($gen);

        $content = [
            ['type'=>'text', 'text'=> implode("\n\n", $lines)]
        ];
        // Replace localhost images with a test image URL when developing locally
        $test_img = 'https://shop.dreamli.nl/wp-content/uploads/2025/10/1642280007130.jpg';
        $img_urls_for_payload = [];
        foreach ($ctx['img_urls'] as $u) {
            $is_local = false;
            if (is_string($u)) {
                $host = parse_url($u, PHP_URL_HOST);
                if ($host) {
                    $host_l = strtolower($host);
                    if ($host_l === 'localhost' || $host_l === '127.0.0.1' || substr($host_l, -6) === '.local') {
                        $is_local = true;
                    }
                } elseif (stripos($u, 'localhost') !== false) {
                    // Fallback string match
                    $is_local = true;
                }
            }
            if ($is_local) {
                self::log($run_id, 'Dev mode image replacement: '.$u.' -> '.$test_img);
                $img_urls_for_payload[] = $test_img;
            } else {
                $img_urls_for_payload[] = $u;
            }
        }
        foreach($img_urls_for_payload as $u){
            $content[] = ['type'=>'image_url','image_url'=>['url'=>$u]];
        }

        $inst = 'TOP PRIORITY: Return ONE json object only. No prose, no Markdown, no code fences. '
               . 'Keys: title, short_description, long_description_html, meta_title, meta_description, focus_keywords (array of strings), slug. '
               . 'Place ALL HTML only inside long_description_html. Do not output any HTML anywhere else. '
               . 'Use internal links only if they are present in the provided context via tools (vector store or web search). Do not invent URLs. '
               . 'If a valid category_url is provided in inputs, include up to 2 internal links using exactly that URL: one in Product Details and one in CTA, with varied anchor text. '
               . 'If no category URL is provided in inputs or via tools context, do not insert internal links. '
               . 'If an external link is provided, you may include it naturally up to 1–2 times. '
               . 'Keep links HTML with <a href="...">...</a>.\n\n'
               . 'Example JSON: {"title":"...","short_description":"...","long_description_html":"<h2>...</h2><p>...</p>","meta_title":"...","meta_description":"...","focus_keywords":["...","..."],"slug":"..."}\n\n'
               . 'FINAL OUTPUT MUST BE VALID JSON ONLY.';

        $messages = [
            ['role'=>'system','content'=>$system],
            ['role'=>'user','content'=>$content],
            ['role'=>'system','content'=>$inst]
        ];

        // Build Responses API payload
        self::log($run_id,'Calling OpenAI /v1/responses…');

        $prompt_id      = trim((string)get_option(self::OPT_PROMPT_ID,''));
        $prompt_version = trim((string)get_option(self::OPT_PROMPT_VERSION,'3'));
        $vs_csv         = (string)get_option(self::OPT_VECTOR_STORE_IDS,'');
        $vs_ids         = array_values(array_filter(array_map('trim', explode(',', $vs_csv))));
        $enable_web     = (string)get_option(self::OPT_ENABLE_WEB_SEARCH,'yes') === 'yes';
        $search_size    = (string)get_option(self::OPT_SEARCH_CONTEXT,'medium');
        $store_resp     = (string)get_option(self::OPT_STORE_RESP,'yes') === 'yes';
        $include_csv    = (string)get_option(self::OPT_INCLUDE_FIELDS,'reasoning.encrypted_content,web_search_call.action.sources');
        $include_fields = array_values(array_filter(array_map('trim', explode(',', $include_csv))));

        // Compose input blocks (text + images)
        $full_text = implode("\n\n", $lines) . "\n\n" . $inst;
        $content_blocks = [ ['type'=>'input_text', 'text'=> $full_text] ];
        // Use dev-substituted list to avoid localhost images in payload
        foreach ($img_urls_for_payload as $u) { $content_blocks[] = ['type'=>'input_image','image_url'=>$u]; }
        // Log summary of images being sent
        $img_count = 0; $img_preview = [];
        foreach ($img_urls_for_payload as $iu) { $img_count++; if (count($img_preview)<2) { $img_preview[] = $iu; } }
        self::log($run_id, 'Input images sent: '. $img_count . ( $img_count ? (' [e.g., '.implode(', ', array_map(function($s){ return (strlen($s)>120?substr($s,0,117).'...':$s); }, $img_preview)).']') : '' ) );

        $input = [[ 'role'=>'user', 'content'=> $content_blocks ]];

        $tools = [];
        $tools[] = [ 'type'=>'file_search', 'vector_store_ids'=>$vs_ids ];
//        $tools[] = [
//            'type' => 'web_search',
//            'user_location' => [ 'type' => 'approximate' ],
//            'search_context_size' => in_array($search_size, ['small','medium','large'], true) ? $search_size : 'medium'
//        ];


        $payload = [
            'input'     => [],
            'reasoning' => [ 'summary'=>'auto' ],
            'tools'     => $tools,
            'store'     => $store_resp,
            'include'   => $include_fields,
        ];
        if ($prompt_id !== '') {
            $payload['prompt'] = ['id'=>$prompt_id,'version'=>$prompt_version?:'4'];
            $payload['input'] = $input; // include images + keywords + instructions
            self::log($run_id, 'Responses payload (prompt mode) with constructed input blocks (images+keywords).');
        } else {
            $payload['model'] = $model;
            $payload['instructions'] = $system;
            $payload['input'] = $input;
            self::log($run_id, 'Responses payload (model mode) with constructed input blocks.');
        }

        $t0 = microtime(true);
        list($parsed, $code, $err) = self::openai($api_key, $payload, $run_id);
        $dt = round(microtime(true)-$t0, 3);
        if ($err){
            // Fallback: Some upstreams reject text.format object; retry without it
            if ((int)$code === 400 && is_string($err) && stripos($err, 'text.format') !== false) {
                self::log($run_id, 'OpenAI 400 related to text.format — retrying once without text.format');
                unset($payload['text']);
                $t1 = microtime(true);
                list($parsed, $code, $err) = self::openai($api_key, $payload, $run_id);
                $dt = round(microtime(true)-$t1, 3);
                if ($err){
                    self::log($run_id,'OpenAI ERROR (after fallback): '.$err);
                    self::mark_failed($run_id,'openai_error_after_fallback');
                    return;
                }
            } else {
                self::log($run_id,'OpenAI ERROR: '.$err);
                self::mark_failed($run_id,'openai_error');
                return;
            }
        }
        self::log($run_id,'Returned in '.$dt.'s');

        // Responses API parsing: collect candidate texts and robustly extract a single JSON object
        $candidates = [];
        if (isset($parsed['output_text']) && is_string($parsed['output_text'])) {
            $candidates[] = $parsed['output_text'];
        }
        if (isset($parsed['output']) && is_array($parsed['output'])) {
            foreach ($parsed['output'] as $out) {
                if (isset($out['content']) && is_array($out['content'])) {
                    foreach ($out['content'] as $c) {
                        if (isset($c['text']) && is_string($c['text'])) {
                            $candidates[] = $c['text'];
                        }
                    }
                }
            }
        }
        if (isset($parsed['choices'][0]['message']['content'])) {
            $tmp = $parsed['choices'][0]['message']['content'];
            if (is_array($tmp)) {
                $candidates[] = isset($tmp['text']) ? (string)$tmp['text'] : wp_json_encode($tmp);
            } else {
                $candidates[] = (string)$tmp;
            }
        }
        // De-duplicate candidates
        $candidates = array_values(array_unique(array_map(function($s){ return is_string($s)?$s:''; }, $candidates)));

        $data = null;
        $debug_logged = false;
        foreach ($candidates as $idx => $cand) {
            if (!$debug_logged) {
                $dbg = substr($cand, 0, 400);
                self::log($run_id, 'Model text candidate #'.($idx+1).' snippet: '.preg_replace('/\s+/', ' ', $dbg));
                $debug_logged = true;
            }
            $raw = trim($cand);
            // Strip code fences if present
            if (strpos($raw, '```') !== false) {
                $raw = preg_replace('/^```[a-zA-Z]*\s*/', '', $raw);
                $raw = preg_replace('/\s*```$/', '', $raw);
                $raw = trim((string)$raw);
                self::log($run_id, 'Stripped code fences from model output.');
            }
            // If HTML blob, still try to extract JSON inside
            // Extract first JSON object substring if the string doesn't start with '{'
            $candidate_json = $raw;
            if ($raw === '' || $raw[0] !== '{') {
                $p1 = strpos($raw, '{');
                $p2 = strrpos($raw, '}');
                if ($p1 !== false && $p2 !== false && $p2 > $p1) {
                    $candidate_json = substr($raw, $p1, $p2 - $p1 + 1);
                    self::log($run_id, 'Extracted JSON object substring from surrounding text.');
                }
            }
            $decoded = json_decode($candidate_json, true);
            if (is_array($decoded)) { $data = $decoded; break; }
        }

        if (!is_array($data)){
            $snippet = count($candidates) ? substr($candidates[0],0,300) : '[no content]';
            self::log($run_id,'ERROR: Invalid JSON. Snippet: '.$snippet);
            self::mark_failed($run_id,'invalid_json');
            return;
        }

        // Update product + Rank Math
        self::log($run_id,'Updating post fields…');
        $update = [
            'ID'=>$post_id,
            'post_title'=> sanitize_text_field($data['title'] ?? $post->post_title),
            'post_excerpt'=> wp_kses_post($data['short_description'] ?? ''),
            'post_content'=> wp_kses_post($data['long_description_html'] ?? '')
        ];
        $raw_slug = isset($data['slug']) ? (string)$data['slug'] : '';
        $norm_slug = self::normalize_slug($raw_slug !== '' ? $raw_slug : $post->post_title);
        if ($raw_slug !== '') self::log($run_id, 'Slug normalized: "'.$raw_slug.'" -> "'.$norm_slug.'"');
        if (!empty($norm_slug)) $update['post_name'] = $norm_slug;
        wp_update_post($update);

        update_post_meta($post_id, 'rank_math_title', sanitize_text_field($data['meta_title'] ?? ''));
        update_post_meta($post_id, 'rank_math_description', sanitize_text_field($data['meta_description'] ?? ''));
        if (!empty($data['focus_keywords']) && is_array($data['focus_keywords'])){
            update_post_meta($post_id, 'rank_math_focus_keyword', implode(', ', array_map('sanitize_text_field',$data['focus_keywords'])));
        }

        self::log($run_id,'Post updated');

        // Charge user ledger for this successful OpenAI generation
        $price = (float) get_option(self::OPT_GPT5_PRICE_EUR, 0);
        $user_id = (int) get_post_field('post_author', $post_id);
        $guard_key = 'ds_ai_charge_' . $run_id;
        if ($price > 0 && $user_id > 0) {
            if (!get_transient($guard_key)) {
                $meta = [
                    'post_id' => $post_id,
                    'run_id'  => $run_id,
                    'model'   => $model,
                    'prompt_id' => $prompt_id,
                    'prompt_version' => $prompt_version,
                    'vector_store_ids' => $vs_ids,
                    'web_search' => $enable_web ? 1 : 0,
                    'store_response' => $store_resp ? 1 : 0,
                ];
                $ref = 'ai_seo:' . $post_id . ':' . $run_id;
                $ledger_id = DS_Wallet::add($user_id, 'ai_seo_request', 0 - $price, $ref, 'posted', $meta);
                set_transient($guard_key, $ledger_id, 30*MINUTE_IN_SECONDS);
                self::log($run_id, 'Ledger charged -' . number_format($price, 2) . ' EUR (entry #' . $ledger_id . ')');
            } else {
                self::log($run_id, 'Ledger charge already recorded for this run.');
            }
        } else {
            self::log($run_id, 'Ledger: no charge (price=0 or missing author).');
        }

        $u = $parsed['usage'] ?? [];
        self::log($run_id,'Usage: prompt='.intval($u['prompt_tokens']??0).' completion='.intval($u['completion_tokens']??0).' total='.intval($u['total_tokens']??0));
        self::mark_done($run_id);
        self::log($run_id,'Worker done');
    }

    /* ===================== Context helpers ===================== */
    private static function get_related_products_raw($post_id, $limit = 40){
        $limit = max(0, (int)$limit);
        if ($limit === 0) return [];

        $terms = wp_get_post_terms($post_id, 'product_cat', ['fields'=>'ids']);
        if (is_wp_error($terms) || empty($terms)) return [];

        $primary_term_id = (int) $terms[0];

        $q = new WP_Query([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'post__not_in'   => [$post_id],
            'orderby'        => 'date',
            'order'          => 'DESC',
            'tax_query'      => [[
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => [$primary_term_id],
                'include_children' => false,
            ]],
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ]);

        if (!$q->have_posts()) return [];
        $out = [];
        foreach ($q->posts as $pid){
            $url = get_permalink($pid);
            $ttl = get_the_title($pid);
            if ($url && $ttl){
                $out[] = ['title'=>$ttl, 'url'=>$url];
            }
        }
        return $out;
    }

    private static function cap_array_bytes($arr, $max_bytes){
        $max = max(1000, (int)$max_bytes);
        $out = [];
        foreach ($arr as $it){
            $cand = $out; $cand[] = $it;
            $bytes = strlen(wp_json_encode($cand, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            if ($bytes > $max) break;
            $out[] = $it;
        }
        return $out;
    }

    private static function build_context($post_id){
        $cat_terms = wp_get_post_terms($post_id, 'product_cat');
        $has_cat   = !is_wp_error($cat_terms) && !empty($cat_terms);
        $cat_names = [];
        $primary   = '';

        if ($has_cat){
            $primary = $cat_terms[0]->name;
            foreach ($cat_terms as $term){
                $chain = [$term->name];
                $anc = get_ancestors($term->term_id, 'product_cat');
                foreach(array_reverse($anc) as $aid){
                    $t = get_term($aid, 'product_cat');
                    if ($t && !is_wp_error($t)) array_unshift($chain, $t->name);
                }
                $cat_names[] = implode(' > ', $chain);
            }
        }

        // Category URL (optional)
        $category_url = '';
        if ($has_cat) {
            $term0 = $cat_terms[0];
            $meta_url = (string) get_term_meta($term0->term_id, 'ds_cat_url', true);
            if (!empty($meta_url)) {
                $category_url = $meta_url;
            } else {
                $link = get_term_link($term0, 'product_cat');
                if (!is_wp_error($link)) $category_url = $link;
            }
        }

        // External link (optional)
        $external_link = get_post_meta($post_id, '_ds_ai_external_url', true);

        // Keywords: category keywords + additional keywords (product-level), merged & deduped
        $cat_kws = '';
        if ($has_cat) {
            $cat_kws = (string) get_term_meta($cat_terms[0]->term_id, 'ds_cat_keywords', true);
        }
        $additional_kws = (string) get_post_meta($post_id, '_ds_ai_dutch_kws', true);
        $all = array_filter(array_map('trim', explode(',', $cat_kws . (strlen($cat_kws)&&strlen($additional_kws) ? ',' : '') . $additional_kws)));
        $all = array_values(array_unique($all));
        $keywords = implode(', ', $all);

        // Story
        $story = get_post_meta($post_id, '_ds_ai_story', true);

        // Images
        $img_urls = [];
        $thumb_id = get_post_thumbnail_id($post_id);
        if ($thumb_id){ $u = wp_get_attachment_image_url($thumb_id, 'full'); if ($u) $img_urls[]=$u; }
        $gallery = get_post_meta($post_id, '_product_image_gallery', true);
        if ($gallery){
            foreach(array_filter(array_map('absint', explode(',', $gallery))) as $gid){
                $u = wp_get_attachment_image_url($gid,'full'); if ($u) $img_urls[]=$u;
            }
        }
        $img_urls = array_values(array_unique($img_urls));

        return [
            'has_cat'          => $has_cat,
            'cat_names'        => $cat_names,
            'primary_cat_name' => $primary,
            'keywords'         => $keywords,
            'category_url'     => $category_url,
            'external_link'    => $external_link,
            'story'            => $story,
            'has_images'       => !empty($img_urls),
            'img_urls'         => $img_urls,
        ];
    }

    /* ===================== HTTP/OpenAI ===================== */
    private static function get_api_key(){
        if (defined('OPENAI_API_KEY') && OPENAI_API_KEY) return OPENAI_API_KEY;
        return (string) get_option(self::OPT_API_KEY, '');
    }
    public static function add_http_hardening(){
        add_filter('http_api_curl', function($handle){
            if (defined('CURLOPT_IPRESOLVE'))     @curl_setopt($handle, CURLOPT_IPRESOLVE, 1); // IPv4
            if (defined('CURLOPT_TCP_KEEPALIVE')) @curl_setopt($handle, CURLOPT_TCP_KEEPALIVE, 1);
            if (defined('CURLOPT_TCP_KEEPIDLE'))  @curl_setopt($handle, CURLOPT_TCP_KEEPIDLE, 30);
            if (defined('CURLOPT_TCP_KEEPINTVL')) @curl_setopt($handle, CURLOPT_TCP_KEEPINTVL, 15);
            if (defined('CURLOPT_CONNECTTIMEOUT'))@curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 25);
            if (defined('CURLOPT_TIMEOUT'))       @curl_setopt($handle, CURLOPT_TIMEOUT, 610); // ~10m
            if (defined('CURLOPT_HTTP_VERSION'))  @curl_setopt($handle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            if (defined('CURLOPT_FORBID_REUSE'))  @curl_setopt($handle, CURLOPT_FORBID_REUSE, false);
            if (defined('CURLOPT_FRESH_CONNECT')) @curl_setopt($handle, CURLOPT_FRESH_CONNECT, false);
        }, 10, 1);
    }

    private static function openai($api_key, $payload, $run_id = ''){
        error_log("AI SEO: started generate() at " . date('H:i:s'));
        @set_time_limit(900);

        $endpoint = 'https://api.openai.com/v1/responses';
        $body_json = wp_json_encode($payload);
        $model_for_log = isset($payload['model']) ? $payload['model'] : (isset($payload['prompt']['id']) ? ('prompt:' . $payload['prompt']['id']) : 'n/a');
        self::log($run_id, 'OpenAI payload bytes='.strlen($body_json).', target='.$model_for_log);

        $args = [
            'headers'=>[
                'Content-Type'=>'application/json',
                'Authorization'=>'Bearer '.$api_key,
                'Expect'=>''
            ],
            'body'=> $body_json,
            'timeout'=>610,
            'redirection'=>0,
            'sslverify'=>true
        ];

        // Pre-log DNS
        $ip = @gethostbyname('api.openai.com');
        self::log($run_id, 'DNS api.openai.com -> ' . ($ip ?: 'resolve-failed'));

        // simple retry for 429/5xx
        $attempts = 0;
        while ($attempts < 3) {
            $attempts++;
            self::log($run_id, "OpenAI try #{$attempts}");
            $res = wp_remote_post($endpoint, $args);

            if (is_wp_error($res)) {
                $msg = 'OpenAI request error: '.$res->get_error_message();
                self::log($run_id, $msg);
                if ($attempts < 3) sleep(min(10, $attempts * 3));
                else return [null, null, $msg];
                continue;
            }

            $code = wp_remote_retrieve_response_code($res);
            $body = wp_remote_retrieve_body($res);
            $snippet = substr((string)$body, 0, 400);
            self::log($run_id, "OpenAI HTTP {$code}, body snippet: ".preg_replace('/\s+/', ' ', $snippet));

            if ($code>=200 && $code<300){
                $lead = ltrim($body);
                if (strlen($lead) && $lead[0] === '<') {
                    return [null, $code, 'HTML page returned instead of JSON (likely upstream/server error).'];
                }
                $parsed = json_decode($body, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return [null, $code, 'JSON parse error: '.json_last_error_msg()];
                }
                return [$parsed, $code, null];
            }

            if ($code == 429 || ($code >= 500 && $code <= 599)) {
                if ($attempts < 3) { sleep(min(10, $attempts * 3)); continue; }
            }
            return [null, $code, "OpenAI HTTP {$code}: {$snippet}"];
        }

        return [null, null, 'OpenAI timeout/retry exhausted.'];
    }

    /* ===================== Error Trap ===================== */
    private static function trap_start($run_id = ''){
        if (self::$err_trap_installed) return;
        self::$err_trap_installed = true;

        set_error_handler(function($errno, $errstr, $errfile, $errline) use ($run_id){
            $label = $errno === E_WARNING ? 'WARNING' : ($errno === E_NOTICE ? 'NOTICE' : 'ERROR');
            self::log($run_id, "{$label}: {$errstr} in {$errfile}:{$errline}");
            return false;
        });

        register_shutdown_function(function() use ($run_id){
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR], true)) {
                $msg = "FATAL: {$err['message']} in {$err['file']} line {$err['line']}";
                self::log($run_id, $msg);
                error_log("AI SEO ".$msg);
            } else {
                error_log("AI SEO: shutdown clean");
            }
        });
    }

    /* ===================== HTML allow-list ===================== */
    public static function allow_basic_html($tags,$context){
        if ($context==='post'){
            $tags['h2'] = [];
            $tags['p']  = ['class'=>true];
            $tags['strong']= [];
            $tags['a']  = ['href'=>true,'title'=>true,'target'=>true,'rel'=>true];
            $tags['br'] = [];
            $tags['ul'] = [];
            $tags['ol'] = [];
            $tags['li'] = [];
        }
        return $tags;
    }

    /* ===================== Slug normalization ===================== */
    private static function normalize_slug($raw){
        $s = trim((string)$raw);
        if ($s === '') return '';
        $candidate = $s;
        // Extract from query string like ?product=foo-bar
        if (strpos($candidate, '?') !== false) {
            $q = substr($candidate, strpos($candidate, '?') + 1);
            if (is_string($q) && $q !== '') {
                parse_str($q, $arr);
                if (isset($arr['product']) && is_string($arr['product']) && $arr['product'] !== '') {
                    $candidate = $arr['product'];
                }
            }
        }
        // If still a URL, try to derive slug from URL components
        if (filter_var($candidate, FILTER_VALIDATE_URL)) {
            $p = wp_parse_url($candidate);
            if (isset($p['query'])) {
                parse_str($p['query'], $arr2);
                if (!empty($arr2['product'])) {
                    $candidate = (string)$arr2['product'];
                }
            }
            if ($candidate === $s || strpos($candidate, '/') !== false) {
                if (!empty($p['path'])) {
                    $base = basename($p['path']);
                    if ($base !== '' && $base !== false) {
                        $candidate = $base;
                    }
                }
            }
        }
        // Drop extension if present
        $candidate = preg_replace('/\.[a-z0-9]+$/i', '', (string)$candidate);
        // Fallback to product title if nothing usable
        if ($candidate === '' || $candidate === '/' || $candidate === '?') {
            return '';
        }
        return sanitize_title($candidate);
    }

    /* ===================== Direct spawn helpers ===================== */
    private static function sign_args($args) {
        $key = defined('AUTH_SALT') ? AUTH_SALT : (defined('LOGGED_IN_SALT') ? LOGGED_IN_SALT : ABSPATH);
        return hash_hmac('sha256', wp_json_encode($args), $key);
    }
    private static function verify_sig($args, $sig) {
        return hash_equals(self::sign_args($args), $sig);
    }
    private static function spawn_direct($args, $run_id) {
        $sig = self::sign_args($args);
        $url = admin_url('admin-ajax.php');
        $body = [
            'action' => self::AJAX_DIRECT,
            'post_id'=> $args['post_id'],
            'model'  => $args['model'],
            'op'     => $args['op'],
            'run_id' => $run_id,
            'sig'    => $sig,
        ];
        $resp = wp_remote_post($url, [
            'timeout'     => 0.01,
            'blocking'    => false,
            'sslverify'   => true,
            'body'        => $body,
            'redirection' => 0,
            'headers'     => ['Expect' => '']
        ]);
        self::log($run_id, 'Spawned direct async worker (no WP-Cron).');
    }

    /* ===================== Fallback & duplicate-run guard ===================== */
    private static function started_key($run_id){ return 'ds_ai_started_'.$run_id; }
    private static function done_key($run_id){ return 'ds_ai_done_'.$run_id; }
    private static function failed_key($run_id){ return 'ds_ai_failed_'.$run_id; }
    private static function mark_started($run_id, $by = 'unknown'){
        if (!$run_id) return true;
        $key = self::started_key($run_id);
        // If a previous run failed, allow starting again and overwrite the latch
        if (get_transient(self::failed_key($run_id))) {
            set_transient($key, ['by' => $by ?: 'unknown', 't' => time()], 60*MINUTE_IN_SECONDS);
            return true;
        }
        // If already started and not marked failed, block duplicates
        if (get_transient($key)) return false;
        set_transient($key, ['by' => $by ?: 'unknown', 't' => time()], 60*MINUTE_IN_SECONDS);
        return true;
    }
    private static function mark_done($run_id){
        if (!$run_id) return;
        set_transient(self::done_key($run_id), 1, 60*MINUTE_IN_SECONDS);
        // Clear any failed flag from prior attempts
        delete_transient(self::failed_key($run_id));
    }
    private static function mark_failed($run_id, $reason = ''){
        if (!$run_id) return;
        set_transient(self::failed_key($run_id), ['t'=>time(),'reason'=>$reason], 30*MINUTE_IN_SECONDS);
    }
    private static function schedule_fallback($args, $run_id, $delay = 10){
        $delay = max(1, (int)$delay);
        $when = time() + $delay;
        // Tag as coming from WP-Cron for clearer logs
        $args['source'] = 'wp-cron';
        // Ensure we don't schedule duplicates for the same run_id
        if (!wp_next_scheduled(self::QUEUE_HOOK, [$args])) {
            $ok = wp_schedule_single_event($when, self::QUEUE_HOOK, [$args]);
            self::log($run_id, $ok ? ("Scheduled WP-Cron fallback in {$delay}s.") : 'WP-Cron schedule failed.');
        } else {
            self::log($run_id, 'WP-Cron fallback already scheduled.');
        }
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            self::log($run_id, 'Warning: DISABLE_WP_CRON is true; fallback may not run.');
        }
    }

    /* ===================== Logging (transient) ===================== */
    public static function debug_log($run_id, $msg){ self::log($run_id,$msg); }
    private static function log_key($run_id){ return 'ds_ai_log_'.$run_id; }
    private static function log_start($run_id){ if(!$run_id) return; set_transient(self::log_key($run_id), [], 30*MINUTE_IN_SECONDS); }
    private static function log($run_id,$msg){
        if(!$run_id) return;
        $key = self::log_key($run_id);
        $arr = get_transient($key);
        if(!is_array($arr)) $arr = [];
        $arr[] = $msg;
        set_transient($key, $arr, 30*MINUTE_IN_SECONDS);
    }
    private static function json_success($data=[]){ header('Content-Type: application/json; charset=utf-8'); echo wp_json_encode(['success'=>true,'data'=>$data]); wp_die(); }
    private static function json_error($message,$code=400){ status_header((int)$code); header('Content-Type: application/json; charset=utf-8'); echo wp_json_encode(['success'=>false,'message'=>$message,'code'=>(int)$code]); wp_die(); }

    /* ===================== Legacy compat ===================== */
    public static function create_table(){
        // نسخه‌های قدیمی اینو صدا می‌زدند؛ الان چیزی لازم نداریم.
        return true;
    }

    /* ===================== Defaults ===================== */
    public static function default_generate_instructions(){
        return "REQUIRED USER INPUTS... (paste your full instruction text here if you prefer).";
    }
}
endif;
