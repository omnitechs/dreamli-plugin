<?php
if (!defined('ABSPATH')) exit;

final class DS_Views {
    static function table() {
        global $wpdb; return $wpdb->prefix . 'ds_product_views';
    }

    static function install() {
        global $wpdb;
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            vendor_id BIGINT UNSIGNED NOT NULL,
            viewer_key VARCHAR(64) NOT NULL,
            ip_hash CHAR(40) NULL,
            ua_hash CHAR(40) NULL,
            view_date DATE NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_view (product_id, viewer_key, view_date),
            KEY vendor_date_idx (vendor_id, view_date),
            KEY product_date_idx (product_id, view_date)
        ) $charset;";
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    static function init() {
        // Frontend tracker on product pages
        add_action('template_redirect', [__CLASS__,'maybe_track_view']);
    }

    static function maybe_track_view() {
        if (is_admin()) return;
        if (!function_exists('is_singular') || !is_singular('product')) return;
        global $post; if (!$post || $post->post_type !== 'product') return;

        $product_id = (int)$post->ID;
        $vendor_id = (int)$post->post_author;
        if ($product_id <= 0 || $vendor_id <= 0) return;

        $viewer_key = self::viewer_key();
        if ($viewer_key === '') return;

        $today = current_time('Y-m-d');
        $ip = self::client_ip();
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
        $ip_hash = $ip ? sha1($ip) : null;
        $ua_hash = $ua ? sha1($ua) : null;

        global $wpdb; $table = self::table();
        $now = DS_Helpers::now();
        // Insert once per product+viewer+day
        $sql = $wpdb->prepare(
            "INSERT INTO {$table} (product_id, vendor_id, viewer_key, ip_hash, ua_hash, view_date, created_at)
             VALUES (%d,%d,%s,%s,%s,%s,%s)
             ON DUPLICATE KEY UPDATE product_id = product_id",
            $product_id, $vendor_id, $viewer_key, $ip_hash, $ua_hash, $today, $now
        );
        $wpdb->query($sql);
    }

    static function viewer_key() : string {
        // Logged-in users: stable user id
        if (is_user_logged_in()) {
            return 'u:' . get_current_user_id();
        }
        // Anonymous: persistent cookie
        $cookie_name = 'ds_vk';
        $val = isset($_COOKIE[$cookie_name]) ? preg_replace('/[^a-zA-Z0-9]/','', (string)$_COOKIE[$cookie_name]) : '';
        if ($val === '' || strlen($val) < 16) {
            $val = substr(bin2hex(random_bytes(16)), 0, 32);
            // 1 year
            $expire = time() + 31536000;
            $secure = is_ssl();
            $httponly = true;
            $path = defined('COOKIEPATH') ? COOKIEPATH : '/';
            $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
            setcookie($cookie_name, $val, [ 'expires'=>$expire, 'path'=>$path, 'domain'=>$domain ?: '', 'secure'=>$secure, 'httponly'=>$httponly, 'samesite'=>'Lax' ]);
            $_COOKIE[$cookie_name] = $val; // make available for this request
        }
        return 'g:' . substr($val, 0, 32);
    }

    static function client_ip() : string {
        $keys = ['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_CLIENT_IP','REMOTE_ADDR'];
        foreach ($keys as $k) {
            if (!empty($_SERVER[$k])) {
                $v = trim((string)$_SERVER[$k]);
                // If list, take first
                if (strpos($v, ',') !== false) $v = trim(explode(',', $v)[0]);
                return $v;
            }
        }
        return '';
    }
}
