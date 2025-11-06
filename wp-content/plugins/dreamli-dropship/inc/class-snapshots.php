<?php
if (!defined('ABSPATH')) exit;

final class DS_Snapshots {
    public static function table() {
        global $wpdb; return $wpdb->prefix . 'ds_product_snapshots';
    }

    public static function install() {
        global $wpdb; $t = self::table(); $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$t} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(32) NOT NULL,
            post_status VARCHAR(20) NULL,
            snapshot_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY product_idx (product_id),
            KEY user_idx (user_id),
            KEY created_idx (created_at)
        ) $charset;";
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function init() {
        add_action('pre_post_update', [__CLASS__,'snapshot_on_pre_update'], 10, 2);
        add_action('pre_trash_post', [__CLASS__,'snapshot_on_trash'], 10, 1);
        add_action('admin_post_ds_restore_last_snapshot', [__CLASS__,'handle_restore_latest']);
        add_action('init', [__CLASS__,'schedule_purge']);
        add_action('ds_snapshots_purge', [__CLASS__,'purge_old']);
    }

    public static function latest_snapshot(int $product_id) : ?array {
        global $wpdb; $t = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE product_id=%d ORDER BY id DESC LIMIT 1", $product_id));
        if (!$row) return null;
        $snap = json_decode((string)$row->snapshot_json, true);
        return is_array($snap) ? $snap : null;
    }

    public static function schedule_purge() {
        if (!wp_next_scheduled('ds_snapshots_purge')) {
            wp_schedule_event(time() + 900, 'daily', 'ds_snapshots_purge');
        }
    }

    public static function purge_old() {
        $s = DS_Settings::get(); $days = max(1, (int)($s['snapshot_retention_days'] ?? 90));
        $cut = date('Y-m-d H:i:s', current_time('timestamp') - $days * DAY_IN_SECONDS);
        global $wpdb; $t = self::table();
        $wpdb->query($wpdb->prepare("DELETE FROM {$t} WHERE created_at < %s", $cut));
    }

    public static function snapshot_on_pre_update($post_id, $data) {
        $post = get_post($post_id); if (!$post || $post->post_type !== 'product') return;
        if (!is_admin()) return;
        if (!DS_Helpers::is_vendor()) return;
        // Make a snapshot of the current persisted state (before update)
        self::capture($post_id, 'pre_save');
    }

    public static function snapshot_on_trash($post_id) {
        $post = get_post($post_id); if (!$post || $post->post_type !== 'product') return;
        if (!is_admin()) return;
        if (!DS_Helpers::is_vendor()) return;
        self::capture($post_id, 'pre_trash');
    }

    public static function capture(int $post_id, string $action) {
        $p = get_post($post_id); if (!$p) return;
        $uid = get_current_user_id();
        $snap = self::build_snapshot($post_id, $p);
        global $wpdb; $t = self::table();
        $wpdb->insert($t, [
            'product_id' => $post_id,
            'user_id' => $uid ?: 0,
            'action' => sanitize_text_field($action),
            'post_status' => $p->post_status,
            'snapshot_json' => wp_json_encode($snap),
            'created_at' => DS_Helpers::now(),
        ]);
    }

    private static function build_snapshot(int $post_id, WP_Post $p) : array {
        $thumb_id = (int) get_post_thumbnail_id($post_id);
        $gallery = (string) get_post_meta($post_id, '_product_image_gallery', true);
        $rank_title = get_post_meta($post_id, 'rank_math_title', true);
        $rank_desc  = get_post_meta($post_id, 'rank_math_description', true);
        $rank_focus = get_post_meta($post_id, 'rank_math_focus_keyword', true);
        $faq = function_exists('get_field') ? get_field('field_68d22fead7bb7', $post_id) : null; // ACF FAQ repeater if available
        $sku = get_post_meta($post_id, '_sku', true);
        $price = get_post_meta($post_id, '_price', true);
        $po_meta_key = DS_Settings::get()['po_meta_key'] ?? '';
        $po_meta = $po_meta_key ? get_post_meta($post_id, $po_meta_key, true) : null;
        return [
            'core' => [
                'post_title' => $p->post_title,
                'post_name' => $p->post_name,
                'post_excerpt' => $p->post_excerpt,
                'post_content' => $p->post_content,
                'post_status' => $p->post_status,
            ],
            'media' => [ 'thumbnail_id' => $thumb_id, 'gallery' => $gallery ],
            'seo' => [ 'rank_math_title' => $rank_title, 'rank_math_description' => $rank_desc, 'rank_math_focus_keyword' => $rank_focus ],
            'faq' => $faq,
            'woo' => [ 'sku' => $sku, 'price' => $price ],
            'options' => [ 'po_meta_key' => $po_meta_key, 'po_meta' => $po_meta ],
        ];
    }

    public static function handle_restore_latest() {
        if (!current_user_can('manage_options')) wp_die('No permission');
        $pid = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
        check_admin_referer('ds_restore_last_'.$pid);
        if ($pid <= 0) wp_die('Invalid');
        global $wpdb; $t = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE product_id=%d ORDER BY id DESC LIMIT 1", $pid));
        if (!$row) wp_die('No snapshot');
        $snap = json_decode((string)$row->snapshot_json, true);
        if (!is_array($snap) || empty($snap['core'])) wp_die('Bad snapshot');
        self::apply_snapshot($pid, $snap);
        // Log restore
        $wpdb->insert($t, [
            'product_id' => $pid,
            'user_id' => get_current_user_id(),
            'action' => 'manual_restore',
            'post_status' => get_post_status($pid),
            'snapshot_json' => json_encode(['restored_from' => (int)$row->id]),
            'created_at' => DS_Helpers::now(),
        ]);
        wp_safe_redirect(admin_url('post.php?post='.$pid.'&action=edit&restored=1'));
        exit;
    }

    public static function apply_snapshot(int $post_id, array $snap) {
        $core = $snap['core'];
        $update = [
            'ID' => $post_id,
            'post_title' => $core['post_title'] ?? '',
            'post_name' => $core['post_name'] ?? '',
            'post_excerpt' => $core['post_excerpt'] ?? '',
            'post_content' => $core['post_content'] ?? '',
        ];
        wp_update_post($update);
        if (isset($snap['media']['thumbnail_id'])) {
            set_post_thumbnail($post_id, (int)$snap['media']['thumbnail_id']);
        }
        if (isset($snap['media']['gallery'])) {
            update_post_meta($post_id, '_product_image_gallery', (string)$snap['media']['gallery']);
        }
        if (!empty($snap['seo'])) {
            foreach (['rank_math_title','rank_math_description','rank_math_focus_keyword'] as $k) {
                if (array_key_exists($k, $snap['seo'])) update_post_meta($post_id, $k, $snap['seo'][$k]);
            }
        }
        if (isset($snap['faq']) && function_exists('update_field')) {
            update_field('field_68d22fead7bb7', $snap['faq'], $post_id);
        }
        if (!empty($snap['woo'])) {
            if (array_key_exists('sku', $snap['woo'])) update_post_meta($post_id, '_sku', $snap['woo']['sku']);
            if (array_key_exists('price', $snap['woo'])) update_post_meta($post_id, '_price', $snap['woo']['price']);
        }
        if (!empty($snap['options'])) {
            $key = $snap['options']['po_meta_key'] ?? '';
            if ($key) update_post_meta($post_id, $key, $snap['options']['po_meta']);
        }
    }
}
