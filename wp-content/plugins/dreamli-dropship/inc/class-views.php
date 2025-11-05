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
            KEY product_date_idx (product_id, view_date),
            KEY ip_product_day_idx (product_id, view_date, ip_hash),
            KEY ip_day_idx (view_date, ip_hash)
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
        $s = DS_Settings::get();
        $vendor_id = (int)$post->post_author;
        if (!empty($s['entitlement_controls_payouts_ads'])) {
            if (class_exists('DS_Entitlements') && method_exists('DS_Entitlements','current_holder')) {
                $holder = (int) DS_Entitlements::current_holder($product_id);
                if ($holder > 0) { $vendor_id = $holder; }
            }
        }
        if ($product_id <= 0 || $vendor_id <= 0) return;

        // Consent gate: Complianz 'statistics' consent if available; else fallback to Elementor cookie cm_choice_v1=accept_analytics
        $consented = false;
        if (function_exists('cmplz_has_consent')) {
            // Complianz categories: use 'statistics' for analytics-like tracking
            $consented = (bool) cmplz_has_consent('statistics');
        } else {
            if (isset($_COOKIE['cm_choice_v1'])) {
                $val = sanitize_text_field((string)$_COOKIE['cm_choice_v1']);
                if ($val === 'accept_analytics') { $consented = true; }
            }
        }
        // Allow theme/CMP plugins to override via filter
        $consented = (bool) apply_filters('ds_can_track_views', $consented, $product_id);
        if (!$consented) return;

        $viewer_key = self::viewer_key();
        if ($viewer_key === '') return;

        $today = current_time('Y-m-d');
        $ip = self::client_ip();
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
        $ip_hash = $ip ? sha1($ip) : null;
        $ua_hash = $ua ? sha1($ua) : null;

        // Settings and bot filtering
        $s = DS_Settings::get();
        $ua_deny = array_map('strtolower', (array)($s['view_ua_denylist'] ?? []));
        $ua_is_bot = false;
        if ($ua && $ua_deny) {
            $ua_lower = strtolower($ua);
            foreach ($ua_deny as $needle) { if ($needle!=='' && strpos($ua_lower, $needle) !== false) { $ua_is_bot = true; break; } }
        }
        if ($ua_is_bot && empty($s['view_record_bots'])) {
            // Skip both recording and paying
            return;
        }

        // Insert once per product+viewer+day
        global $wpdb; $table = self::table();
        $now = DS_Helpers::now();
        $sql = $wpdb->prepare(
            "INSERT INTO {$table} (product_id, vendor_id, viewer_key, ip_hash, ua_hash, view_date, created_at)
             VALUES (%d,%d,%s,%s,%s,%s,%s)
             ON DUPLICATE KEY UPDATE product_id = product_id",
            $product_id, $vendor_id, $viewer_key, $ip_hash, $ua_hash, $today, $now
        );
        $wpdb->query($sql);
        $is_new = (int)$wpdb->insert_id > 0;

        // Payout logic (only on newly inserted rows)
        if (!$is_new) return;
        $rate = (float)($s['view_payout_rate_eur'] ?? 0);
        $enable = !empty($s['enable_view_payouts']);
        if (!$enable || $rate <= 0) return;
        // Optional: pause payouts while entitlement is pending
        if (!empty($s['entitlement_controls_payouts_ads']) && !empty($s['pause_payouts_while_pending'])) {
            if (class_exists('DS_Entitlements') && method_exists('DS_Entitlements','is_pending_for_prev_month')) {
                if (DS_Entitlements::is_pending_for_prev_month($product_id, $vendor_id)) {
                    return; // still record the view but do not pay while pending
                }
            }
        }
        if ($ua_is_bot && empty($s['view_pay_for_bots'])) return;
        // Exclusions
        if (!empty($s['view_excluded_vendors']) && in_array($vendor_id, (array)$s['view_excluded_vendors'], true)) return;
        if (!empty($s['view_excluded_products']) && in_array($product_id, (array)$s['view_excluded_products'], true)) return;

        // Apply count-based caps
        $from_day = $today; $to_day = $today;
        $month_start = date('Y-m-01', current_time('timestamp'));
        $month_end   = date('Y-m-t',  current_time('timestamp'));

        // Helper lambdas
        $count_views = function($where_sql, $params) use ($wpdb, $table) {
            $q = $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params);
            return (int)$wpdb->get_var($q);
        };

        // Per IP per product per day
        $cap = (int)($s['view_cap_per_ip_per_product_per_day'] ?? 0);
        if ($cap > 0 && $ip_hash) {
            $c = $count_views('product_id=%d AND view_date=%s AND ip_hash=%s', [$product_id, $from_day, $ip_hash]);
            if ($c > $cap) return; // over cap → no pay
        }
        // Per IP per day sitewide
        $cap = (int)($s['view_cap_per_ip_per_day_sitewide'] ?? 0);
        if ($cap > 0 && $ip_hash) {
            $c = $count_views('view_date=%s AND ip_hash=%s', [$from_day, $ip_hash]);
            if ($c > $cap) return;
        }
        // Per viewer per day sitewide
        $cap = (int)($s['view_cap_per_viewer_per_day_sitewide'] ?? 0);
        if ($cap > 0) {
            $c = $count_views('view_date=%s AND viewer_key=%s', [$from_day, $viewer_key]);
            if ($c > $cap) return;
        }
        // Per product per day
        $cap = (int)($s['view_cap_per_product_per_day'] ?? 0);
        if ($cap > 0) {
            $c = $count_views('view_date=%s AND product_id=%d', [$from_day, $product_id]);
            if ($c > $cap) return;
        }
        // Per vendor per day
        $cap = (int)($s['view_cap_per_vendor_per_day'] ?? 0);
        if ($cap > 0) {
            $c = $count_views('view_date=%s AND vendor_id=%d', [$from_day, $vendor_id]);
            if ($c > $cap) return;
        }
        // Per vendor per month
        $cap = (int)($s['view_cap_per_vendor_per_month'] ?? 0);
        if ($cap > 0) {
            $q = $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE vendor_id=%d AND view_date BETWEEN %s AND %s", $vendor_id, $month_start, $month_end);
            $c = (int)$wpdb->get_var($q);
            if ($c > $cap) return;
        }
        // Sitewide per day
        $cap = (int)($s['view_cap_sitewide_per_day'] ?? 0);
        if ($cap > 0) {
            $c = $count_views('view_date=%s', [$from_day]);
            if ($c > $cap) return;
        }
        // Sitewide per month
        $cap = (int)($s['view_cap_sitewide_per_month'] ?? 0);
        if ($cap > 0) {
            $q = $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE view_date BETWEEN %s AND %s", $month_start, $month_end);
            $c = (int)$wpdb->get_var($q);
            if ($c > $cap) return;
        }

        // Payout € caps using ledger sums
        $wallet = DS_Wallet::table();
        $sum_amount = function($where_sql, $params) use ($wpdb, $wallet) {
            $q = $wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM {$wallet} WHERE {$where_sql}", $params);
            return (float)$wpdb->get_var($q);
        };
        // Vendor per day
        $cap_e = (float)($s['payout_cap_per_vendor_per_day_eur'] ?? 0);
        if ($cap_e > 0) {
            $sum = $sum_amount("type='view_reward' AND status IN ('posted','paid') AND user_id=%d AND created_at BETWEEN %s AND %s", [$vendor_id, $from_day.' 00:00:00', $to_day.' 23:59:59']);
            if ($sum + $rate > $cap_e + 1e-9) return;
        }
        // Vendor per month
        $cap_e = (float)($s['payout_cap_per_vendor_per_month_eur'] ?? 0);
        if ($cap_e > 0) {
            $sum = $sum_amount("type='view_reward' AND status IN ('posted','paid') AND user_id=%d AND created_at BETWEEN %s AND %s", [$vendor_id, $month_start.' 00:00:00', $month_end.' 23:59:59']);
            if ($sum + $rate > $cap_e + 1e-9) return;
        }
        // Sitewide per day
        $cap_e = (float)($s['payout_cap_sitewide_per_day_eur'] ?? 0);
        if ($cap_e > 0) {
            $sum = $sum_amount("type='view_reward' AND status IN ('posted','paid') AND created_at BETWEEN %s AND %s", [$from_day.' 00:00:00', $to_day.' 23:59:59']);
            if ($sum + $rate > $cap_e + 1e-9) return;
        }
        // Sitewide per month
        $cap_e = (float)($s['payout_cap_sitewide_per_month_eur'] ?? 0);
        if ($cap_e > 0) {
            $sum = $sum_amount("type='view_reward' AND status IN ('posted','paid') AND created_at BETWEEN %s AND %s", [$month_start.' 00:00:00', $month_end.' 23:59:59']);
            if ($sum + $rate > $cap_e + 1e-9) return;
        }

        // Idempotent ledger add using stable ref
        $ref = 'view:' . $product_id . ':' . $today . ':' . $viewer_key;
        $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wallet} WHERE ref_id=%s", $ref));
        if ($exists === 0) {
            $meta = ['product_id'=>$product_id,'viewer_key'=>$viewer_key,'view_date'=>$today,'ip_hash'=>$ip_hash,'ua_hash'=>$ua_hash];
            DS_Wallet::add($vendor_id, 'view_reward', $rate, $ref, 'posted', $meta);
        }
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
