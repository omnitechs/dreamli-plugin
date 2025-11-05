<?php
if (!defined('ABSPATH')) exit;

final class DS_Ads {
    const TABLE = 'ds_ad_campaigns';

    // Pause helpers for entitlement changes
    public static function pause_campaigns_for_product_user(int $product_id, int $user_id) {
        global $wpdb; $table = self::table();
        $wpdb->update($table, [ 'status'=>'paused', 'updated_at'=>DS_Helpers::now() ], [ 'product_id'=>$product_id, 'user_id'=>$user_id, 'status'=>'active' ], [ '%s','%s' ], [ '%d','%d','%s' ]);
    }
    public static function pause_campaigns_for_product_except(int $product_id, int $exclude_user_id = 0) {
        global $wpdb; $table = self::table();
        if ($exclude_user_id > 0) {
            $wpdb->query($wpdb->prepare("UPDATE {$table} SET status='paused', updated_at=%s WHERE product_id=%d AND user_id<>%d AND status='active'", DS_Helpers::now(), $product_id, $exclude_user_id));
        } else {
            $wpdb->update($table, [ 'status'=>'paused', 'updated_at'=>DS_Helpers::now() ], [ 'product_id'=>$product_id, 'status'=>'active' ], [ '%s','%s' ], [ '%d','%s' ]);
        }
    }

    // --- Per-visitor daily cap helpers ---
    private static function today_str() : string {
        return date('Ymd', current_time('timestamp'));
    }
    private static function cookie_key($cid) : string {
        $cid = (int)$cid; return 'ds_adc_' . $cid; // ds ad click
    }
    private static function ip_hash_today($cid) : string {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        $hash = wp_hash($ip);
        return 'dsadc_' . (int)$cid . '_' . substr($hash,0,12) . '_' . self::today_str();
    }
    private static function already_clicked_today($cid) : bool {
        $key = self::cookie_key($cid);
        if (!headers_sent() && isset($_COOKIE[$key])) {
            // Value holds Ymd to guard against very old cookies
            if (preg_match('/^\d{8}$/', (string)$_COOKIE[$key])) {
                return $_COOKIE[$key] === self::today_str();
            }
            return true;
        }
        // Fallback server-side transient (IP scoped)
        if (get_transient(self::ip_hash_today($cid))) return true;
        return false;
    }
    private static function mark_clicked_today($cid) : void {
        $cid = (int)$cid; if ($cid<=0) return;
        $now = current_time('timestamp');
        $exp = strtotime('tomorrow', $now) - 1; // end of site-local day
        $key = self::cookie_key($cid);
        $val = self::today_str();
        // Set cookie for frontend and admin area paths
        if (!headers_sent()) {
            $secure = is_ssl();
            $httponly = true;
            // COOKIEPATH/COOKIE_DOMAIN might be undefined early on some installs; use fallbacks
            $path = defined('COOKIEPATH') ? COOKIEPATH : '/';
            $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
            setcookie($key, $val, $exp, $path, $domain, $secure, $httponly);
            if (defined('SITECOOKIEPATH') && SITECOOKIEPATH !== $path) {
                setcookie($key, $val, $exp, SITECOOKIEPATH, $domain, $secure, $httponly);
            }
        }
        // Server-side transient (approx 24h) to mitigate cookie denial
        set_transient(self::ip_hash_today($cid), 1, max(60, $exp - $now));
    }

    public static function table() {
        global $wpdb; return $wpdb->prefix . self::TABLE;
    }

    public static function install() {
        global $wpdb;
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            placement VARCHAR(20) NOT NULL,
            category_id BIGINT UNSIGNED NULL,
            daily_budget DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            spent_today DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            today_clicks INT UNSIGNED NOT NULL DEFAULT 0,
            spent_date DATE NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            total_clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY user_idx (user_id),
            KEY product_idx (product_id),
            KEY placement_idx (placement),
            KEY status_idx (status),
            KEY category_idx (category_id)
        ) $charset;";
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function init() {
        add_shortcode('ds_promoted_products', [__CLASS__, 'shortcode']);
        add_action('template_redirect', [__CLASS__, 'handle_click']);
        add_action('init', [__CLASS__, 'register_query_vars']);
    }

    public static function register_query_vars() {
        add_filter('query_vars', function($vars){ $vars[] = 'ds_ad_click'; return $vars; });
    }

    public static function defaults_today_state($row) {
        // If spent_date is not today, consider counters reset
        $today = current_time('Y-m-d');
        if (!isset($row->spent_date) || !$row->spent_date || $row->spent_date !== $today) {
            $row->spent_today = '0.00';
            $row->today_clicks = 0;
        }
        return $row;
    }

    public static function eligible_campaigns($placement, $category_id = 0, $limit = 4) {
        global $wpdb;
        $table = self::table();
        $today = current_time('Y-m-d');
        $placement = $placement === 'category' ? 'category' : 'home';
        $where = "status='active' AND placement=%s";
        $args = [$placement];
        if ($placement === 'category') { $where .= " AND category_id=%d"; $args[] = (int)$category_id; }
        // Eligible if spent_today < daily_budget OR spent_date != today (reset due)
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where} AND (spent_date IS NULL OR spent_date<>%s OR spent_today < daily_budget) ORDER BY updated_at DESC LIMIT %d",
            array_merge($args, [$today, (int)$limit])
        );
        $rows = $wpdb->get_results($sql);
        if (!$rows) return [];
        return array_map([__CLASS__,'defaults_today_state'], $rows);
    }

    public static function cpc_for($placement) : float {
        $s = DS_Settings::get();
        if ($placement === 'category') return (float)($s['ads_cpc_category'] ?? 0);
        return (float)($s['ads_cpc_home'] ?? 0);
    }

    public static function handle_click() {
        $cid = get_query_var('ds_ad_click');
        if (!$cid) return;
        $cid = (int)$cid;
        if ($cid <= 0) return;

        global $wpdb;
        $table = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $cid));
        if (!$row) return; // Fallback to normal flow

        // Validate campaign
        if ($row->status !== 'active') { self::redirect_to_product($row->product_id); return; }

        // Per-visitor daily cap: if already clicked this campaign today, do not charge again
        if (self::already_clicked_today($cid)) { self::redirect_to_product($row->product_id); return; }

        // Lazy reset if day changed
        $today = current_time('Y-m-d');
        if (!$row->spent_date || $row->spent_date !== $today) {
            $wpdb->update($table, [
                'spent_today' => 0.00,
                'today_clicks' => 0,
                'spent_date' => $today,
                'updated_at' => DS_Helpers::now(),
            ], [ 'id' => $cid ], [ '%f','%d','%s','%s' ], [ '%d' ]);
            $row->spent_today = 0.00; $row->today_clicks = 0; $row->spent_date = $today;
        }

        $cpc = self::cpc_for($row->placement);
        if ($cpc <= 0) { self::redirect_to_product($row->product_id); return; }

        $remaining = (float)$row->daily_budget - (float)$row->spent_today;
        if ($remaining + 1e-9 < $cpc) { // insufficient budget
            self::redirect_to_product($row->product_id); return;
        }

        // Charge wallet and update counters
        $owner_id = (int)$row->user_id;
        $meta = [ 'campaign_id' => (int)$row->id, 'product_id' => (int)$row->product_id, 'placement' => $row->placement, 'category_id' => (int)$row->category_id ];
        $ref = 'ad_click:'.$row->id.':'.date('Ymd');
        DS_Wallet::add($owner_id, 'ad_click_charge', 0 - $cpc, $ref, 'posted', $meta);

        // Update counters atomically based on current row
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET spent_today = spent_today + %f, today_clicks = today_clicks + 1, total_clicks = total_clicks + 1, updated_at=%s WHERE id=%d",
            $cpc, DS_Helpers::now(), $cid
        ));

        // Mark this visitor as charged for this campaign today
        self::mark_clicked_today($cid);

        self::redirect_to_product($row->product_id);
    }

    private static function redirect_to_product($product_id) {
        $url = get_permalink((int)$product_id);
        if (!$url) $url = home_url('/');
        wp_safe_redirect($url);
        exit;
    }

    public static function shortcode($atts) {
        $atts = shortcode_atts([
            'placement' => 'auto', // auto|home|category
            'limit' => 4,
            'category' => '', // id or slug
            'class' => 'ds-promoted-grid'
        ], $atts, 'ds_promoted_products');

        $placement = $atts['placement'];
        $limit = max(1, (int)$atts['limit']);
        $cat_id = 0;

        if ($placement === 'auto') {
            if (function_exists('is_product_category') && is_product_category()) {
                $term = get_queried_object();
                if ($term && isset($term->term_id)) { $placement = 'category'; $cat_id = (int)$term->term_id; }
            }
            if ($placement === 'auto') $placement = 'home';
        } elseif ($placement === 'category') {
            $requested = trim((string)$atts['category']);
            if ($requested !== '') {
                if (ctype_digit($requested)) { $cat_id = (int)$requested; }
                else {
                    $term = get_term_by('slug', $requested, 'product_cat');
                    if ($term && !is_wp_error($term)) $cat_id = (int)$term->term_id;
                }
            } else {
                $term = function_exists('is_product_category') && is_product_category() ? get_queried_object() : null;
                if ($term && isset($term->term_id)) $cat_id = (int)$term->term_id;
            }
        } else {
            $placement = 'home';
        }

        if ($placement === 'category' && $cat_id <= 0) return '';

        $rows = self::eligible_campaigns($placement, $cat_id, $limit);
        if (empty($rows)) return '';

        // Render products
        $html = '<div class="'.esc_attr($atts['class']).'">';
        foreach ($rows as $r) {
            $pid = (int)$r->product_id;
            $link = add_query_arg('ds_ad_click', (int)$r->id, get_permalink($pid));
            $title = get_the_title($pid);
            $thumb = get_the_post_thumbnail($pid, 'medium');
            if (!$thumb) $thumb = '<div class="ds-thumb-placeholder" style="background:#f2f2f2;height:160px;"></div>';
            $price_html = function_exists('wc_get_product') ? (wc_get_product($pid) ? wc_get_product($pid)->get_price_html() : '') : '';
            $html .= '<div class="ds-promoted-item" style="display:inline-block;margin:8px;vertical-align:top;">';
            $html .= '<a href="'.esc_url($link).'" class="ds-promoted-link">'.$thumb.'<h3>'.esc_html($title).'</h3></a>';
            if ($price_html) $html .= '<div class="price">'.$price_html.'</div>';
            $html .= '</div>';
        }
        $html .= '</div>';
        return $html;
    }
}
