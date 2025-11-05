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
    }
    public static function render_settings(){
        if (!current_user_can('manage_options')) return;
        $allowed = array_filter(array_map('trim', explode(',', get_option(self::OPT_ALLOWED_MODELS, 'gpt-5,gpt-4o,gpt-4o-mini'))));
        $default = get_option(self::OPT_DEFAULT_MODEL, 'gpt-5');
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
                    <tr><th>Allowed Models (CSV)</th>
                        <td><input type="text" name="<?php echo esc_attr(self::OPT_ALLOWED_MODELS); ?>" value="<?php echo esc_attr(get_option(self::OPT_ALLOWED_MODELS)); ?>" style="width:420px" /></td></tr>
                    <tr><th>Default Model</th>
                        <td><select name="<?php echo esc_attr(self::OPT_DEFAULT_MODEL); ?>">
                            <?php foreach($allowed as $m): ?>
                                <option value="<?php echo esc_attr($m); ?>" <?php selected($default, $m); ?>><?php echo esc_html($m); ?></option>
                            <?php endforeach; ?>
                        </select></td></tr>
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
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /* ===================== Category fields ===================== */
    public static function cat_fields_add(){ ?>
        <div class="form-field">
            <label for="ds_cat_keywords">Dutch keywords (comma-separated)</label>
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
            <th scope="row"><label for="ds_cat_keywords">Dutch keywords</label></th>
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

        $allowed = array_filter(array_map('trim', explode(',', get_option(self::OPT_ALLOWED_MODELS, 'gpt-5,gpt-4o,gpt-4o-mini'))));
        $default = get_option(self::OPT_DEFAULT_MODEL, 'gpt-5');

        // overrides per product
        $ovr_url  = get_post_meta($post->ID, '_ds_ai_cat_url', true);
        $ovr_kws  = get_post_meta($post->ID, '_ds_ai_dutch_kws', true);
        $ovr_story= get_post_meta($post->ID, '_ds_ai_story', true);

        $has_cat = !empty(wp_get_post_terms($post->ID, 'product_cat', ['fields'=>'ids']));
        $has_img = (bool) get_post_thumbnail_id($post->ID) || !empty(get_post_meta($post->ID,'_product_image_gallery',true));
        ?>
        <div style="margin-bottom:8px">
            <p style="margin:0 0 6px;">Category set: <strong><?php echo $has_cat?'Yes':'No'; ?></strong></p>
            <p style="margin:0 0 6px;">Has images: <strong><?php echo $has_img?'Yes':'No'; ?></strong></p>
        </div>

        <p><label><strong>Model</strong></label><br/>
            <select id="ds-ai-model" style="width:100%"><?php foreach($allowed as $m): ?>
                <option value="<?php echo esc_attr($m); ?>" <?php selected($default,$m); ?>><?php echo esc_html($m); ?></option>
            <?php endforeach; ?></select>
        </p>

        <p><label><strong>Override Category URL (optional)</strong></label>
            <input type="text" id="ds-ai-ovr-url" style="width:100%" placeholder="/category/vases/" value="<?php echo esc_attr($ovr_url); ?>"/>
        </p>
        <p><label><strong>Dutch keywords (optional)</strong></label>
            <textarea id="ds-ai-ovr-kws" rows="2" style="width:100%" placeholder="vaas, modern, 3D print"><?php echo esc_textarea($ovr_kws); ?></textarea>
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
            const model= $('#ds-ai-model');
            const url  = $('#ds-ai-ovr-url');
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
                    fd.append('model', model.value);
                    fd.append('run_id', currentRunId);
                    fd.append('ovr_url', url.value);
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
        $model   = sanitize_text_field($_POST['model'] ?? get_option(self::OPT_DEFAULT_MODEL, 'gpt-5'));
        $op      = sanitize_text_field($_POST['op'] ?? 'generate');

        $ovr_url   = sanitize_text_field($_POST['ovr_url'] ?? '');
        $ovr_kws   = sanitize_text_field($_POST['ovr_kws'] ?? '');
        $ovr_story = sanitize_textarea_field($_POST['ovr_story'] ?? '');

        if (!$post_id) self::json_error('Missing post_id', 400);
        if ($op !== 'generate') self::json_error('Unsupported op', 400);

        // Persist overrides
        if ($ovr_url !== '')   update_post_meta($post_id, '_ds_ai_cat_url', $ovr_url);
        if ($ovr_kws !== '')   update_post_meta($post_id, '_ds_ai_dutch_kws', $ovr_kws);
        if ($ovr_story !== '') update_post_meta($post_id, '_ds_ai_story', $ovr_story);

        self::log_start($run_id);
        self::log($run_id, date('H:i:s') . " — Queueing op={$op} model={$model} post_id={$post_id}");

        // Spawn direct async worker (no WP-Cron / no runner)
        $args = ['post_id'=>$post_id,'model'=>$model,'op'=>$op,'run_id'=>$run_id];
        self::spawn_direct($args, $run_id);

        self::json_success(['msg'=>'Queued. Background worker will process…']);
    }

    /* ===================== Direct async worker endpoint ===================== */
    public static function direct_worker() {
        self::trap_start(sanitize_text_field($_POST['run_id'] ?? ''));
        ignore_user_abort(true);

        if (!empty($_SERVER['REQUEST_METHOD']) && strtolower($_SERVER['REQUEST_METHOD']) === 'post') {
            $post_id = (int)($_POST['post_id'] ?? 0);
            $model   = sanitize_text_field($_POST['model'] ?? '');
            $op      = sanitize_text_field($_POST['op'] ?? '');
            $run_id  = sanitize_text_field($_POST['run_id'] ?? '');
            $sig     = sanitize_text_field($_POST['sig'] ?? '');

            $args = ['post_id'=>$post_id,'model'=>$model,'op'=>$op,'run_id'=>$run_id];

            if ($post_id && $op === 'generate' && self::verify_sig($args, $sig)) {
                header('Content-Type: text/plain; charset=utf-8');
                echo 'OK';
                @fastcgi_finish_request();
                self::worker($args);
                wp_die();
            }
        }
        status_header(400);
        echo 'BAD';
        wp_die();
    }

    /* ===================== Worker ===================== */
    public static function worker($args){
        $run_id = sanitize_text_field($args['run_id'] ?? '');
        self::trap_start($run_id);

        $post_id = (int)($args['post_id'] ?? 0);
        $model   = sanitize_text_field($args['model'] ?? get_option(self::OPT_DEFAULT_MODEL,'gpt-5'));
        $op      = sanitize_text_field($args['op'] ?? 'generate');

        self::log($run_id,"Worker start op={$op} model={$model} post_id={$post_id}");

        $api_key = self::get_api_key();
        if (!$api_key){
            self::log($run_id,'ERROR: API key missing (define OPENAI_API_KEY or set in settings).');
            return;
        } else {
            self::log($run_id,'API key OK (masked): '.substr($api_key,0,4).'•••'.substr($api_key,-4));
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type!=='product'){ self::log($run_id,'ERROR: Invalid product'); return; }

        // Build context
        self::log($run_id,'Building context...');
        $ctx = self::build_context($post_id);
        if (!$ctx['has_cat'])   { self::log($run_id,'ERROR: No category on product'); return; }
        if (!$ctx['has_images']){ self::log($run_id,'ERROR: No images on product');   return; }
        self::log($run_id,'Context OK: images='.count($ctx['img_urls']).', cat_chains='.count($ctx['cat_names']).', kws='.($ctx['dutch_keywords']?1:0).', related='.(isset($ctx['related_products'])?count($ctx['related_products']):0));

        // Messages
        self::log($run_id,'Building messages…');
        $system = (string) get_option(self::OPT_SYSTEM_PROMPT, "You are an SEO copywriter...");
        $gen    = (string) get_option(self::OPT_PROMPT_GENERATE, self::default_generate_instructions());

        $user_inputs = [
            "PRODUCT INFORMATION" => [
                "product_name"    => $post->post_title,
                "product_category"=> $ctx['primary_cat_name'],
                "dutch_keywords"  => $ctx['dutch_keywords'],
                "note"            => "Images attached below. Analysis allowed."
            ],
            "INTERNAL LINK URL (Required)" => $ctx['category_url'],
            "RELATED PRODUCTS (use for internal links)" => isset($ctx['related_products']) ? $ctx['related_products'] : [],
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
        foreach($ctx['img_urls'] as $u){
            $content[] = ['type'=>'image_url','image_url'=>['url'=>$u]];
        }

        $inst = 'Return a single JSON object only (valid JSON, no markdown). Keys: title, short_description, long_description_html, meta_title, meta_description, focus_keywords (array of strings), slug. '
               . '- Use the provided category_url exactly 3 times across the copy (1× in short_description, 2× in long_description_html). '
               . '- Also insert 3–7 natural internal links to product pages, ONLY from the provided "RELATED PRODUCTS" list (use their url; anchor text = product title or a natural phrase). '
               . '- Do not invent URLs. Keep links HTML with <a href="...">...</a>.';

        $messages = [
            ['role'=>'system','content'=>$system],
            ['role'=>'user','content'=>$content],
            ['role'=>'system','content'=>$inst]
        ];

        // Call OpenAI
        self::log($run_id,'Calling OpenAI (json)…');
        $payload = [
            'model'=>$model,
            'response_format'=>['type'=>'json_object'],
            'messages'=>$messages
        ];
        $t0 = microtime(true);
        list($parsed, $code, $err) = self::openai($api_key, $payload, $run_id);
        $dt = round(microtime(true)-$t0, 3);
        if ($err){
            self::log($run_id,'OpenAI ERROR: '.$err);
            return;
        }
        self::log($run_id,'Returned in '.$dt.'s');

        $content = isset($parsed['choices'][0]['message']['content']) ? $parsed['choices'][0]['message']['content'] : '';
        if (is_array($content)) {
            $content = isset($content['text']) ? (string)$content['text'] : wp_json_encode($content);
        }
        if (!is_string($content)) {
            $content = '';
        }

        if (is_string($content) && strlen($content) > 0 && substr($content,0,1) === '<'){
            self::log($run_id,'ERROR: HTML returned instead of JSON. Snippet: '.substr($content,0,200));
            return;
        }

        $data = json_decode($content, true);
        if (!is_array($data)){
            $snippet = is_string($content) ? substr($content,0,300) : '[no content]';
            self::log($run_id,'ERROR: Invalid JSON. Snippet: '.$snippet);
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
        if (!empty($data['slug'])) $update['post_name'] = sanitize_title($data['slug']);
        wp_update_post($update);

        update_post_meta($post_id, 'rank_math_title', sanitize_text_field($data['meta_title'] ?? ''));
        update_post_meta($post_id, 'rank_math_description', sanitize_text_field($data['meta_description'] ?? ''));
        if (!empty($data['focus_keywords']) && is_array($data['focus_keywords'])){
            update_post_meta($post_id, 'rank_math_focus_keyword', implode(', ', array_map('sanitize_text_field',$data['focus_keywords'])));
        }

        self::log($run_id,'Post updated');
        $u = $parsed['usage'] ?? [];
        self::log($run_id,'Usage: prompt='.intval($u['prompt_tokens']??0).' completion='.intval($u['completion_tokens']??0).' total='.intval($u['total_tokens']??0));
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
        $cat_url   = '';

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

                if (!$cat_url){
                    $stored_url = get_term_meta($term->term_id, 'ds_cat_url', true);
                    if ($stored_url) $cat_url = $stored_url;
                }
            }
        }

        // Product overrides
        $ovr_url   = get_post_meta($post_id, '_ds_ai_cat_url', true);
        if ($ovr_url) $cat_url = $ovr_url;

        // Dutch keywords
        $dutch = get_post_meta($post_id, '_ds_ai_dutch_kws', true);
        if (!$dutch && $has_cat){
            $dutch = get_term_meta($cat_terms[0]->term_id, 'ds_cat_keywords', true);
        }

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

        // Related products with byte-bounded context
        $raw_related = self::get_related_products_raw($post_id, (int)get_option(self::OPT_RELATED_LIMIT, 40));
        $related     = self::cap_array_bytes($raw_related, (int)get_option(self::OPT_CONTEXT_MAX_BYTES, 24000));

        return [
            'has_cat'          => $has_cat,
            'cat_names'        => $cat_names,
            'primary_cat_name' => $primary,
            'category_url'     => $cat_url,
            'dutch_keywords'   => $dutch,
            'story'            => $story,
            'has_images'       => !empty($img_urls),
            'img_urls'         => $img_urls,
            'related_products' => $related
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

        $endpoint = 'https://api.openai.com/v1/chat/completions';
        $body_json = wp_json_encode($payload);
        self::log($run_id, 'OpenAI payload bytes='.strlen($body_json).', model='.$payload['model']);

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
