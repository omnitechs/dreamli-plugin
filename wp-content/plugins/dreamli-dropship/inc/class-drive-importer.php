<?php
if (!defined('ABSPATH')) exit;

/**
 * DS_Drive_Importer
 * - Imports product images from a shared Google Drive folder (service account auth)
 * - Traverses nested folders until it finds images; for each folder with images → create/update a private product
 * - Stores Drive folder ID and link on the product; sends product to Pool so vendors/admins can claim it
 * - Runs weekly via Action Scheduler at 02:00 (site timezone) with small batches to avoid load
 */
final class DS_Drive_Importer {

    const OPT_STATE = 'ds_drive_state';
    const OPT_LOG   = 'ds_drive_log';
    const MAX_DEPTH_DEFAULT = 10; // go at least 10 levels deep by default

    public static function init() {
        add_action('admin_post_ds_drive_sync_now', [__CLASS__, 'handle_sync_now']);
        add_action('admin_post_ds_drive_test', [__CLASS__, 'handle_test']);
        add_action('admin_post_ds_drive_reset', [__CLASS__, 'handle_reset']);
        add_action('admin_post_ds_drive_clean', [__CLASS__, 'handle_clean']);

        // Admin UI: show Drive folder on products
        add_action('add_meta_boxes_product', [__CLASS__, 'add_drive_metabox']);
        add_filter('manage_edit-product_columns', [__CLASS__, 'add_list_column']);
        add_action('manage_product_posts_custom_column', [__CLASS__, 'render_list_column'], 10, 2);

        // Front-end (optional): show Drive link to authorized users on single product
        add_action('woocommerce_single_product_summary', [__CLASS__, 'render_frontend_drive_panel'], 7);

        // Scheduling hooks
        add_action('action_scheduler_init', [__CLASS__, 'schedule_weekly'], 20);
        add_action('ds_drive_weekly', [__CLASS__, 'enqueue_full_scan']);
        add_action('ds_drive_process_batch', [__CLASS__, 'process_batch']);
        add_action('ds_drive_runner', [__CLASS__, 'process_batch']); // recurring runner hook
        add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
        // Safety: if a scan is pending (queue not empty), ensure the runner is active after any restart
        add_action('init', [__CLASS__, 'resume_if_pending'], 50);
    }

    /* -------------------------- Scheduling -------------------------- */

    public static function schedule_weekly() {
        // Per user preference, disable automatic weekly syncs.
        // If any were previously scheduled, unschedule them now.
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('ds_drive_weekly', [], 'ds_drive');
        }
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook('ds_drive_weekly');
        }
        return;
    }

    // Add a minutely interval for WP-Cron fallback
    public static function cron_schedules($schedules){
        if (!isset($schedules['ds_minutely'])){
            $schedules['ds_minutely'] = [ 'interval' => 60, 'display' => 'Every Minute (DS)' ];
        }
        return $schedules;
    }

    // Ensure a periodic runner is scheduled to process Drive batches until the queue empties
    private static function ensure_runner(){
        // If already scheduled, do nothing
        if (self::is_runner_scheduled()) return;
        // Only schedule if there is actual work and not paused
        $state = get_option(self::OPT_STATE, []);
        $queue = is_array($state) ? ($state['queue'] ?? []) : [];
        $paused = is_array($state) ? !empty($state['paused']) : false;
        if (empty($queue) || $paused) return;
        $start = time() + 10;
        if (function_exists('as_next_scheduled_action')){
            as_schedule_recurring_action($start, 60, 'ds_drive_runner', [], 'ds_drive');
        } else {
            if (!wp_next_scheduled('ds_drive_runner')){
                wp_schedule_event($start, 'ds_minutely', 'ds_drive_runner');
            }
        }
    }

    // Unschedule the periodic runner
    private static function stop_runner(){
        if (function_exists('as_unschedule_all_actions')){
            as_unschedule_all_actions('ds_drive_runner', [], 'ds_drive');
        }
        if (function_exists('wp_clear_scheduled_hook')){
            wp_clear_scheduled_hook('ds_drive_runner');
        }
    }

    // Check if runner is scheduled
    private static function is_runner_scheduled(){
        if (function_exists('as_next_scheduled_action')){
            $ts = as_next_scheduled_action('ds_drive_runner', [], 'ds_drive');
            if ($ts) return true;
        }
        if (function_exists('wp_next_scheduled')){
            return (bool) wp_next_scheduled('ds_drive_runner');
        }
        return false;
    }

    // On init, if a scan is pending (queue not empty), ensure the runner is alive
    public static function resume_if_pending(){
        $state = get_option(self::OPT_STATE, []);
        $paused = is_array($state) ? !empty($state['paused']) : false;
        if (is_array($state) && !empty($state['queue']) && !$paused){
            self::ensure_runner();
        }
        // If runner is scheduled but no work or paused, ensure it's stopped
        if ((!is_array($state) || empty($state['queue']) || $paused) && self::is_runner_scheduled()){
            self::stop_runner();
        }
    }

    public static function enqueue_full_scan() {
        // Reset queue to just the root folder; runner will process batches
        $s = DS_Settings::get();
        $root = trim((string)($s['drive_folder_id'] ?? ''));
        if ($root === '') return;

        $state = [
            'queue' => [ ['id' => $root, 'd' => 0] ],
            'visited' => [], // folderId => 1
            'processed' => 0,
            'started_at' => time(),
        ];
        update_option(self::OPT_STATE, $state, false);
        // Ensure periodic runner is active until scan completes
        self::ensure_runner();
    }

    public static function handle_sync_now() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('ds_drive_actions');
        self::enqueue_full_scan();
        // Start/ensure the periodic runner
        self::ensure_runner();
        wp_redirect(add_query_arg(['ds_drive_msg'=>'sync_started'], wp_get_referer() ?: admin_url('admin.php?page=ds-settings')));
        exit;
    }

    public static function handle_test() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('ds_drive_actions');
        $ok = self::test_connection();
        $msg = $ok === true ? 'ok' : 'err';
        if ($ok !== true && is_wp_error($ok)) {
            $m = $ok->get_error_message();
            $m = urlencode($m);
            wp_redirect(add_query_arg(['ds_drive_msg'=>$msg,'ds_drive_err'=>$m], wp_get_referer() ?: admin_url('admin.php?page=ds-settings')));
        } else {
            wp_redirect(add_query_arg(['ds_drive_msg'=>$msg], wp_get_referer() ?: admin_url('admin.php?page=ds-settings')));
        }
        exit;
    }

    public static function handle_reset() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('ds_drive_actions');
        self::reset_state();
        wp_redirect(add_query_arg(['ds_drive_msg' => 'reset_ok'], wp_get_referer() ?: admin_url('admin.php?page=ds-settings')));
        exit;
    }

    public static function handle_clean() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('ds_drive_actions');
        self::clean_all_drive_data();
        wp_redirect(add_query_arg(['ds_drive_msg' => 'clean_ok'], wp_get_referer() ?: admin_url('admin.php?page=ds-settings')));
        exit;
    }

    private static function clean_all_drive_data(){
        // Unschedule all known hooks (Action Scheduler + WP-Cron fallback)
        if (function_exists('as_unschedule_all_actions')){
            as_unschedule_all_actions('ds_drive_runner', [], 'ds_drive');
            as_unschedule_all_actions('ds_drive_weekly', [], 'ds_drive');
            as_unschedule_all_actions('ds_drive_process_batch', [], 'ds_drive');
        }
        if (function_exists('wp_clear_scheduled_hook')){
            wp_clear_scheduled_hook('ds_drive_runner');
            wp_clear_scheduled_hook('ds_drive_weekly');
            wp_clear_scheduled_hook('ds_drive_process_batch');
        }
        // Remove all related options/transients
        delete_option(self::OPT_STATE);
        delete_option(self::OPT_LOG);
        delete_transient('ds_drive_proc_lock');
        delete_transient('ds_drive_token');
        // In case future keys are added, pattern-delete older transients via direct SQL (safe no-op if no access to $wpdb)
        global $wpdb;
        if ($wpdb && property_exists($wpdb, 'options')){
            $like = $wpdb->esc_like('_transient_ds_drive_') . '%';
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
            $like2 = $wpdb->esc_like('_transient_timeout_ds_drive_') . '%';
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like2));
        }
        self::log('info','clean',[]);
    }

    private static function reset_state() {
        // Stop any scheduled runners
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('ds_drive_runner', [], 'ds_drive');
            // Also unschedule any related hooks just in case
            as_unschedule_all_actions('ds_drive_weekly', [], 'ds_drive');
            as_unschedule_all_actions('ds_drive_process_batch', [], 'ds_drive');
        }
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook('ds_drive_runner');
            wp_clear_scheduled_hook('ds_drive_weekly');
            wp_clear_scheduled_hook('ds_drive_process_batch');
        }
        // Clear state + logs + locks + cached token
        delete_option(self::OPT_STATE);              // ds_drive_state
        delete_option(self::OPT_LOG);                // ds_drive_log
        delete_transient('ds_drive_proc_lock');      // batch lock
        delete_transient('ds_drive_token');          // access token cache
        self::log('info', 'reset', []);
    }

    /* -------------------------- Batch processor -------------------------- */

    public static function process_batch() {
        // Lock to avoid overlapping runners
        $lock_key = 'ds_drive_proc_lock';
        if (get_transient($lock_key)) { return; }
        set_transient($lock_key, 1, 55);
        try {
            $s = DS_Settings::get();
            $max = isset($s['drive_batch_size']) ? max(1, min(100, (int)$s['drive_batch_size'])) : 20;

            $state = get_option(self::OPT_STATE, []);
            if (!is_array($state)) { $state = []; }

            $queue = (array)($state['queue'] ?? []);
            $visited = is_array($state['visited'] ?? null) ? $state['visited'] : [];
            $processed = (int)($state['processed'] ?? 0);

            if (empty($queue)) {
                // Nothing to do — stop runner if it exists
                self::stop_runner();
                return;
            }

            $token = self::get_access_token();
            if (is_wp_error($token)) {
                self::log('error','token_error',['err'=>$token->get_error_message()]);
                // Pause and stop the runner so it doesn't keep re-scheduling
                $state['paused'] = true;
                $state['last_error'] = $token->get_error_message();
                update_option(self::OPT_STATE, $state, false);
                self::stop_runner();
                return;
            }

            $done_in_this_batch = 0;
            $maxDepth = self::max_depth();
            while ($done_in_this_batch < $max && !empty($queue)) {
                $entry = array_shift($queue);
                if (is_array($entry)) { $folderId = (string)($entry['id'] ?? ''); $depth = isset($entry['d']) ? (int)$entry['d'] : 0; }
                else { $folderId = (string)$entry; $depth = 0; }
                if ($folderId === '') { continue; }
                if (isset($visited[$folderId])) continue; // skip already visited in this run
                $visited[$folderId] = 1;

                $children = self::list_children($token, $folderId);
                if (is_wp_error($children)) { self::log('error','list_children',['folder'=>$folderId,'err'=>$children->get_error_message()]); continue; }

                $imgs = array_values(array_filter($children, function($f){ return self::is_image($f); }));
                $subs = array_values(array_filter($children, function($f){ return ($f['mimeType'] ?? '') === 'application/vnd.google-apps.folder'; }));

                if (!empty($imgs)) {
                    // Qualifying folder → upsert product
                    $title = self::folder_name($token, $folderId);
                    $pid = self::upsert_product_from_folder($token, $folderId, $title, $imgs);
                    if (is_wp_error($pid)) {
                        self::log('error','upsert_product',['folder'=>$folderId,'err'=>$pid->get_error_message()]);
                    } else {
                        $processed++; $done_in_this_batch++;
                    }
                    // Continue descending if depth limit allows
                    if ($depth < $maxDepth) {
                        $nextDepth = $depth + 1;
                        foreach ($subs as $sf) { $queue[] = ['id' => $sf['id'], 'd' => $nextDepth]; }
                    }
                } else {
                    // No images here → add subfolders to queue to go deeper
                    if ($depth < $maxDepth) {
                        $nextDepth = $depth + 1;
                        foreach ($subs as $sf) { $queue[] = ['id' => $sf['id'], 'd' => $nextDepth]; }
                    }
                }
            }

            // Save state; runner continues to call until queue empties
            $state['queue'] = $queue;
            $state['visited'] = $visited;
            $state['processed'] = $processed;
            update_option(self::OPT_STATE, $state, false);

            if (empty($queue)) {
                self::log('info','scan_complete',['processed'=>$processed]);
                self::stop_runner();
                delete_option(self::OPT_STATE);
            }
        } finally {
            delete_transient($lock_key);
        }
    }

    /* -------------------------- Google Drive API -------------------------- */

    private static function test_connection() {
        $token = self::get_access_token();
        if (is_wp_error($token)) return $token;
        $s = DS_Settings::get();
        $root = trim((string)($s['drive_folder_id'] ?? ''));
        if ($root === '') return new WP_Error('drive','Missing folder ID');
        // Include supportsAllDrives for shared drives compatibility
        $resp = self::get("https://www.googleapis.com/drive/v3/files/{$root}?fields=id,name,mimeType&supportsAllDrives=true", $token);
        if (is_wp_error($resp)) return $resp;
        return true;
    }

    private static function get_access_token() {
        $cached = get_transient('ds_drive_token');
        if (is_string($cached) && $cached !== '') return $cached;
        $s = DS_Settings::get();
        $json = trim((string)($s['drive_sa_json'] ?? ''));
        if ($json === '') return new WP_Error('drive','Missing service account JSON in settings');
        $key = json_decode($json, true);
        if (!is_array($key) || empty($key['client_email']) || empty($key['private_key'])) return new WP_Error('drive','Invalid service account JSON');

        $now = time();
        $hdr = self::b64url(json_encode(['alg'=>'RS256','typ'=>'JWT']));
        $claims = [
            'iss' => $key['client_email'],
            'scope' => 'https://www.googleapis.com/auth/drive.readonly',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ];
        $pld = self::b64url(json_encode($claims));
        $to_sign = $hdr.'.'.$pld;
        $sig = '';
        $ok = openssl_sign($to_sign, $sig, $key['private_key'], 'sha256');
        if (!$ok) return new WP_Error('drive','Failed to sign JWT');
        $jwt = $to_sign.'.'.self::b64url($sig);

        $resp = wp_remote_post('https://oauth2.googleapis.com/token', [
            'headers' => ['Content-Type'=>'application/x-www-form-urlencoded'],
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ],
            'timeout' => 20,
        ]);
        if (is_wp_error($resp)) return $resp;
        $code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code !== 200 || empty($body['access_token'])) {
            return new WP_Error('drive','Token error: '.($body['error_description'] ?? $body['error'] ?? 'HTTP '.$code));
        }
        $token = (string)$body['access_token'];
        $ttl = max(300, (int)($body['expires_in'] ?? 3600) - 120);
        set_transient('ds_drive_token', $token, $ttl);
        return $token;
    }

    private static function list_children($token, $folderId) {
        $out = [];
        $pageToken = null;
        $base = 'https://www.googleapis.com/drive/v3/files';
        do {
            $qs = [
                'q' => sprintf("'%s' in parents and trashed=false", $folderId),
                'fields' => 'nextPageToken,files(id,name,mimeType,md5Checksum,modifiedTime,size)',
                'pageSize' => 200,
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
                'pageToken' => $pageToken,
            ];
            $url = $base.'?'.http_build_query(array_filter($qs, function($v){ return $v !== null && $v !== ''; }));
            $resp = self::get($url, $token);
            if (is_wp_error($resp)) return $resp;
            $out = array_merge($out, (array)($resp['files'] ?? []));
            $pageToken = $resp['nextPageToken'] ?? null;
        } while (!empty($pageToken));
        return $out;
    }

    private static function folder_name($token, $folderId) {
        // Include supportsAllDrives for shared drives compatibility
        $resp = self::get("https://www.googleapis.com/drive/v3/files/{$folderId}?fields=id,name&supportsAllDrives=true", $token);
        if (is_wp_error($resp)) return $folderId;
        return (string)($resp['name'] ?? $folderId);
    }

    private static function get($url, $token) {
        $resp = wp_remote_get($url, [
            'headers' => ['Authorization' => 'Bearer '.$token],
            'timeout' => 30,
        ]);
        if (is_wp_error($resp)) return $resp;
        $code = wp_remote_retrieve_response_code($resp);
        $body_raw = wp_remote_retrieve_body($resp);
        if ($code !== 200) {
            // Try to extract Google Drive error details for better diagnostics
            $err = json_decode($body_raw, true);
            $msg = 'HTTP ' . $code;
            if (isset($err['error'])) {
                $e = $err['error'];
                $em = is_string($e['message'] ?? null) ? $e['message'] : '';
                $st = is_string($e['status'] ?? null) ? $e['status'] : '';
                $rsn = '';
                if (!empty($e['errors']) && is_array($e['errors'])) {
                    $first = $e['errors'][0];
                    $rsn = (string)($first['reason'] ?? '');
                }
                $parts = array_filter([$msg, $st, $rsn, $em]);
                $msg = implode(' | ', $parts);
            }
            return new WP_Error('drive_http', $msg);
        }
        $json = json_decode($body_raw, true);
        return is_array($json) ? $json : new WP_Error('drive_json','Invalid JSON');
    }

    private static function download_bytes($fileId, $token) {
        $url = "https://www.googleapis.com/drive/v3/files/{$fileId}?alt=media";
        $resp = wp_remote_get($url, [
            'headers' => ['Authorization'=>'Bearer '.$token],
            'timeout' => 60,
        ]);
        if (is_wp_error($resp)) return $resp;
        $code = wp_remote_retrieve_response_code($resp);
        if ($code !== 200) return new WP_Error('drive_http','HTTP '.$code);
        return wp_remote_retrieve_body($resp);
    }

    private static function is_image($f) {
        $mt = strtolower((string)($f['mimeType'] ?? ''));
        if ($mt === 'application/vnd.google-apps.folder') return false;
        // Accept common image types incl. AVIF and SVG
        if (strpos($mt, 'image/') === 0) return true;
        // Some files may have octet-stream; fall back to extension check
        $name = strtolower((string)($f['name'] ?? ''));
        return preg_match('/\.(jpe?g|png|webp|gif|bmp|tiff?|svg|avif)$/i', $name) === 1;
    }

    /* -------------------------- WooCommerce product -------------------------- */

    private static function upsert_product_from_folder($token, $folderId, $title, array $imgs) {
        // Idempotency: find existing by _ds_drive_folder_id across any post status (private/publish/etc.)
        $existing = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'any',
            'meta_key'       => '_ds_drive_folder_id',
            'meta_value'     => $folderId,
            'fields'         => 'ids',
            'numberposts'    => 1,
            'suppress_filters' => true,
        ]);
        $pid = $existing ? (int)$existing[0] : 0;
        $was_new = false;

        if (!$pid) {
            $pid = wp_insert_post([
                'post_type' => 'product',
                'post_status' => 'private',
                'post_title' => $title,
                'post_content' => '',
            ]);
            if (is_wp_error($pid) || !$pid) return is_wp_error($pid) ? $pid : new WP_Error('wp','Insert failed');
            $was_new = true;
            // Mark into Pool so vendors/admins see it there
            if (class_exists('DS_Entitlements') && method_exists('DS_Entitlements','forfeit_to_pool')) {
                DS_Entitlements::forfeit_to_pool($pid, 0);
            } else {
                update_post_meta($pid, '_ds_pool', 1);
                update_post_meta($pid, '_ds_pool_since', self::now());
            }
            update_post_meta($pid, '_ds_drive_folder_id', $folderId);
            update_post_meta($pid, '_ds_drive_link', 'https://drive.google.com/drive/folders/'.$folderId);
        } else {
            // Ensure remains private + in pool
            wp_update_post(['ID'=>$pid,'post_status'=>'private']);
            if ((int)get_post_meta($pid,'_ds_pool',true)!==1) {
                update_post_meta($pid,'_ds_pool',1);
            }
        }

        // Deterministic ordering to keep hash stable across runs
        $imgs_sorted = $imgs;
        usort($imgs_sorted, function($a,$b){
            $ai = (string)($a['id'] ?? '');
            $bi = (string)($b['id'] ?? '');
            if ($ai === $bi) return 0;
            return $ai < $bi ? -1 : 1;
        });

        // Skip unchanged? Compute hash of image IDs + modified in stable order
        $hash_base = implode('|', array_map(function($f){ return ($f['id']??'').':'.($f['modifiedTime']??''); }, $imgs_sorted));
        $hash = md5($hash_base);
        $prev = (string)get_post_meta($pid, '_ds_drive_hash', true);
        if ($prev === $hash) { self::log('info','skipped_unchanged',['pid'=>$pid,'folder'=>$folderId]); return $pid; }

        // Download and attach images (first as featured, rest as gallery)
        $attach_ids = [];
        foreach ($imgs_sorted as $i => $file) {
            $bytes = self::download_bytes($file['id'], $token);
            if (is_wp_error($bytes)) { self::log('error','download',['file'=>$file['id'],'err'=>$bytes->get_error_message()]); continue; }
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = sanitize_file_name($file['name']);
            $bits = wp_upload_bits($filename, null, $bytes);
            if (!empty($bits['error'])) { self::log('error','upload_bits',['err'=>$bits['error']]); continue; }
            $filetype = wp_check_filetype($bits['file'], null);
            $attachment = [
                'post_mime_type' => $filetype['type'] ?: 'image/jpeg',
                'post_title' => sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
                'post_status' => 'inherit',
            ];
            $aid = wp_insert_attachment($attachment, $bits['file'], $pid);
            if ($aid) {
                require_once ABSPATH.'wp-admin/includes/image.php';
                $attach_data = @wp_generate_attachment_metadata($aid, $bits['file']);
                if (!is_wp_error($attach_data) && is_array($attach_data)) {
                    wp_update_attachment_metadata($aid, $attach_data);
                }
                $attach_ids[] = (int)$aid;
            }
        }
        if (!empty($attach_ids)) {
            set_post_thumbnail($pid, $attach_ids[0]);
            if (count($attach_ids) > 1) {
                update_post_meta($pid, '_product_image_gallery', implode(',', array_slice($attach_ids, 1)));
            }
        }

        update_post_meta($pid, '_ds_drive_hash', $hash);
        if ($was_new) {
            self::log('info','upsert_create',['pid'=>$pid,'folder'=>$folderId]);
        } else {
            self::log('info','upsert_update',['pid'=>$pid,'folder'=>$folderId]);
        }
        return $pid;
    }

    /* -------------------------- Helpers -------------------------- */

    private static function max_depth() {
        // Allow overriding via filter; default is 10 levels
        $d = (int) apply_filters('ds_drive_max_depth', self::MAX_DEPTH_DEFAULT);
        return max(0, $d);
    }

    private static function now() { return current_time('mysql'); }

    private static function b64url($data) {
        $b = is_string($data) ? $data : (string)$data;
        return rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
    }

    private static function log($level, $msg, array $ctx = []) {
        $log = get_option(self::OPT_LOG, []);
        if (!is_array($log)) $log = [];
        $log[] = ['t'=>time(),'level'=>$level,'msg'=>$msg,'ctx'=>$ctx];
        // keep last 200
        if (count($log) > 200) { $log = array_slice($log, -200); }
        update_option(self::OPT_LOG, $log, false);
    }

    /* -------------------------- Admin UI (Drive link visibility) -------------------------- */
    public static function add_drive_metabox() {
        add_meta_box(
            'ds-drive-folder',
            'Google Drive Folder',
            [__CLASS__, 'render_drive_metabox'],
            'product',
            'side',
            'default'
        );
    }

    public static function render_drive_metabox($post) {
        $pid = is_object($post) ? (int)$post->ID : (int)$post;
        $fid = get_post_meta($pid, '_ds_drive_folder_id', true);
        $link = get_post_meta($pid, '_ds_drive_link', true);
        echo '<div style="font-size:12px;line-height:1.5">';
        if (!$fid && !$link) {
            echo '<p>No Drive folder linked. This is set by the importer when images are found in a Drive folder.</p>';
        } else {
            if ($link) {
                $safe = esc_url($link);
                echo '<p><a class="button" href="'.$safe.'" target="_blank" rel="noopener noreferrer">Open in Drive</a></p>';
                echo '<p style="word-break:break-all"><strong>Folder URL:</strong><br><code>'.$safe.'</code></p>';
            }
            if ($fid) {
                echo '<p><strong>Folder ID:</strong><br><code>'.esc_html($fid).'</code></p>';
            }
        }
        echo '</div>';
    }

    public static function add_list_column($columns) {
        // Insert the Drive column near the title if possible
        $new = [];
        foreach ($columns as $k => $v) {
            $new[$k] = $v;
            if ($k === 'name' || $k === 'title') { $new['ds_drive'] = 'Drive'; }
        }
        if (!isset($new['ds_drive'])) { $new['ds_drive'] = 'Drive'; }
        return $new;
    }

    public static function render_list_column($column, $post_id) {
        if ($column !== 'ds_drive') return;
        $link = get_post_meta((int)$post_id, '_ds_drive_link', true);
        $fid  = get_post_meta((int)$post_id, '_ds_drive_folder_id', true);
        if ($link) {
            echo '<a href="'.esc_url($link).'" target="_blank" rel="noopener" title="'.esc_attr($fid).'">Drive</a>';
        } else {
            echo '&ndash;';
        }
    }

    public static function render_frontend_drive_panel() {
        if (!function_exists('is_product') || !is_product()) return;
        // Only for authorized users (admins, vendors, or anyone with read_private_products)
        $can = current_user_can('manage_options') || current_user_can('read_private_products') || (class_exists('DS_Helpers') && method_exists('DS_Helpers','is_vendor') && DS_Helpers::is_vendor());
        if (!$can) return;
        global $product; if (!$product || !is_object($product)) return;
        if (!method_exists($product, 'get_id')) return;
        $pid  = (int)$product->get_id();
        $link = get_post_meta($pid, '_ds_drive_link', true);
        $fid  = get_post_meta($pid, '_ds_drive_folder_id', true);
        if (!$link && !$fid) return;
        echo '<div class="ds-drive-panel" style="padding:10px;border:1px solid #e0e0e0;margin:10px 0;background:#fafafa;">';
        echo '<strong>Google Drive folder</strong><br/>';
        if ($link) {
            echo '<a href="'.esc_url($link).'" target="_blank" rel="noopener">Open in Drive</a>';
        }
        if ($fid) {
            echo '<div style="font-size:12px;color:#666;margin-top:4px;word-break:break-all">ID: <code>'.esc_html($fid).'</code></div>';
        }
        echo '</div>';
    }
}