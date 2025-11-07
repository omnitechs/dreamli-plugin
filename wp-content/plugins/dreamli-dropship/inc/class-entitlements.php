<?php
if (!defined('ABSPATH')) exit;

final class DS_Entitlements {
	private const CLAIM_FACTOR_TRANSIENT_PREFIX = 'ds_claim_factor_';
	private static $holder_cache = [];
	/** Table for monthly product entitlements */
	public static function table() {
		global $wpdb; return $wpdb->prefix . 'ds_product_entitlements';
	}

	public static function install() {
		global $wpdb; $table = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			month CHAR(7) NOT NULL, -- YYYY-MM
			views INT UNSIGNED NOT NULL DEFAULT 0,
			mean_views FLOAT NOT NULL DEFAULT 0,
			amount_due DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			status VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending|waived|paid|forfeited|cancelled
			confirm_due_at DATETIME NULL,
			charged_at DATETIME NULL,
			ref_id VARCHAR(64) NULL,
			meta LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_prod_month (product_id, month),
			KEY user_month_idx (user_id, month),
			KEY status_idx (status),
			KEY due_idx (confirm_due_at)
		) $charset;";
		require_once ABSPATH.'wp-admin/includes/upgrade.php';
		dbDelta($sql);
	}

	public static function init() {
		// Schedule crons
		add_action('init', [__CLASS__, 'schedule_cron']);
		add_action('ds_entitlements_daily', [__CLASS__, 'run_monthly_if_needed']);
		add_action('ds_entitlements_enforce', [__CLASS__, 'run_enforce']);
		// Claim expiry (hourly)
		add_action('ds_claim_expiry', [__CLASS__, 'run_claim_expiry']);
		// Clear claim flags when product is published
		add_action('transition_post_status', [__CLASS__, 'on_status_transition'], 10, 3);

		// Vendor actions
		add_action('admin_post_ds_entitlement_confirm', [__CLASS__, 'handle_confirm']);
		add_action('admin_post_ds_pool_claim', [__CLASS__, 'handle_claim']);

		// Admin actions: override + pool management
		add_action('admin_post_ds_entitlement_override_set', [__CLASS__, 'handle_override_set']);
		add_action('admin_post_ds_entitlement_override_clear', [__CLASS__, 'handle_override_clear']);
		add_action('admin_post_ds_pool_send', [__CLASS__, 'handle_pool_send']);
		add_action('admin_post_ds_pool_remove', [__CLASS__, 'handle_pool_remove']);
		add_action('admin_post_ds_entitlements_bulk', [__CLASS__, 'handle_bulk_form']);

		// Admin UI helpers: list columns and bulk actions
		if (is_admin()) {
			add_filter('manage_edit-product_columns', [__CLASS__, 'product_columns']);
			add_action('manage_product_posts_custom_column', [__CLASS__, 'render_product_column'], 10, 2);
			add_filter('bulk_actions-edit-product', [__CLASS__, 'register_bulk_actions']);
			add_filter('handle_bulk_actions-edit-product', [__CLASS__, 'handle_bulk_actions'], 10, 3);
			add_action('add_meta_boxes', [__CLASS__, 'add_meta_box']);
		}
	}

	/** Return current holder (user id) for a product, based on override/pool flags */
	public static function current_holder(int $product_id) : int {
		$product_id = (int)$product_id; if ($product_id<=0) return 0;
		if (isset(self::$holder_cache[$product_id])) return self::$holder_cache[$product_id];
		// 1) Admin override
		$override = (int) get_post_meta($product_id, '_ds_entitlement_override_user', true);
		if ($override > 0) { return self::$holder_cache[$product_id] = $override; }
		// 2) Pool user
		$in_pool = (int) get_post_meta($product_id, '_ds_pool', true) === 1;
		if ($in_pool) {
			$s = DS_Settings::get();
			$pool_uid = (int)($s['pool_user_id'] ?? 0);
			if ($pool_uid <= 0) {
				$admins = get_users(['role'=>'administrator','number'=>1,'fields'=>['ID']]);
				$pool_uid = $admins && isset($admins[0]) ? (int)$admins[0]->ID : 1;
			}
			return self::$holder_cache[$product_id] = $pool_uid;
		}
		// 3) Fallback author
		$author = (int) get_post_field('post_author', $product_id);
		return self::$holder_cache[$product_id] = $author;
	}

	/** Check if the previous month entitlement for product is pending (optionally for a specific user) */
 public static function is_pending_for_prev_month(int $product_id, int $user_id = 0) : bool {
        global $wpdb; $t = self::table();
        $prev_month = date('Y-m', strtotime('-1 month', current_time('timestamp')));
        $row = $wpdb->get_row($wpdb->prepare("SELECT user_id,status FROM {$t} WHERE product_id=%d AND month=%s", $product_id, $prev_month));
        if (!$row) return false;
        if ($row->status !== 'pending') return false;
        return $user_id > 0 ? ((int)$row->user_id === (int)$user_id) : true;
    }

    // ===== Dynamic claim/entitlement fee helpers =====
    private static function claim_settings() : array {
        $s = DS_Settings::get();
        return [
            'enable' => !empty($s['claim_fee_enable']),
            'ent_dynamic_enable' => !empty($s['entitlement_dynamic_fee_enable']),
            'period' => (string)($s['claim_fee_period'] ?? 'rolling_30d'),
            'price_basis' => (string)($s['claim_fee_price_basis'] ?? 'current'),
            'scope' => (string)($s['claim_fee_denominator_scope'] ?? 'published'),
            'min_pct' => (float)($s['claim_fee_min_pct'] ?? 0.0),
            'max_pct' => (float)($s['claim_fee_max_pct'] ?? 100.0),
            'min_eur' => (float)($s['claim_fee_min_eur'] ?? 0.0),
            'cache_min' => max(1, (int)($s['claim_fee_cache_minutes'] ?? 60)),
            'statuses' => (array)($s['claim_fee_statuses'] ?? ['processing','completed']),
        ];
    }

    private static function period_range(string $mode) : array {
        $now = current_time('timestamp');
        if ($mode === 'previous_month') {
            $start = date('Y-m-01', strtotime('first day of last month', $now));
            $end   = date('Y-m-t',  strtotime('last day of last month', $now));
        } elseif ($mode === 'month_to_date') {
            $start = date('Y-m-01', $now);
            $end   = date('Y-m-d', $now);
        } else { // rolling_30d
            $start = date('Y-m-d', strtotime('-30 days', $now));
            $end   = date('Y-m-d', $now);
        }
        return [$start, $end];
    }

    private static function map_wc_statuses(array $statuses) : array {
        $out = [];
        foreach ($statuses as $st) {
            $st = strtolower(trim((string)$st));
            if ($st === '') continue;
            if (strpos($st, 'wc-') === 0) $out[] = $st; else $out[] = 'wc-' . $st;
        }
        // Guard: default
        if (empty($out)) $out = ['wc-processing','wc-completed'];
        return array_values(array_unique($out));
    }

    private static function product_count_denominator(string $scope) : int {
        global $wpdb; $pt = 'product';
        if ($scope === 'all') {
            $statuses = ['publish','private','pending','draft','future'];
        } elseif ($scope === 'published_private') {
            $statuses = ['publish','private'];
        } else {
            $statuses = ['publish'];
        }
        $place = implode(',', array_fill(0, count($statuses), '%s'));
        $sql = $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=%s AND post_status IN ($place)", array_merge([$pt], $statuses));
        return (int)$wpdb->get_var($sql);
    }

    private static function total_units_sold(string $from_d, string $to_d, array $statuses) : float {
        // If Woo not present, return 0
        if (!function_exists('wc_get_orders')) return 0.0;
        global $wpdb;
        $wc_statuses = self::map_wc_statuses($statuses);
        $place = implode(',', array_fill(0, count($wc_statuses), '%s'));
        $from_dt = $from_d . ' 00:00:00';
        $to_dt   = $to_d   . ' 23:59:59';
        // Sum quantities from order items
        $sql = $wpdb->prepare(
            "SELECT COALESCE(SUM(CASE WHEN qty.meta_value REGEXP '^[0-9]+(\\.[0-9]+)?$' THEN qty.meta_value+0 ELSE 0 END),0)
             FROM {$wpdb->posts} o
             JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_id = o.ID AND oi.order_item_type='line_item'
             JOIN {$wpdb->prefix}woocommerce_order_itemmeta qty ON qty.order_item_id = oi.order_item_id AND qty.meta_key = '_qty'
             WHERE o.post_type='shop_order' AND o.post_status IN ($place) AND o.post_date BETWEEN %s AND %s",
            array_merge($wc_statuses, [$from_dt, $to_dt])
        );
        $sum = (float)$wpdb->get_var($sql);
        return $sum;
    }

    public static function claim_factor() : float {
        $cfg = self::claim_settings();
        list($from_d, $to_d) = self::period_range($cfg['period']);
        $key = self::CLAIM_FACTOR_TRANSIENT_PREFIX . md5($cfg['period'].'|'.implode(',',$cfg['statuses']).'|'.$cfg['scope'].'|'.$from_d.'|'.$to_d);
        $cached = get_transient($key);
        if ($cached !== false) return (float)$cached;
        $units = self::total_units_sold($from_d, $to_d, $cfg['statuses']);
        $count = self::product_count_denominator($cfg['scope']);
        $factor = ($count > 0) ? ($units / $count) : 0.0;
        set_transient($key, $factor, max(60, $cfg['cache_min'] * MINUTE_IN_SECONDS));
        return $factor;
    }

    public static function product_price(int $product_id) : float {
        $basis = self::claim_settings()['price_basis'];
        $price = 0.0;
        if (function_exists('wc_get_product')) {
            $prod = wc_get_product($product_id);
            if ($prod) {
                if ($basis === 'regular' && method_exists($prod,'get_regular_price')) $price = (float)$prod->get_regular_price();
                elseif ($basis === 'sale' && method_exists($prod,'get_sale_price')) $price = (float)$prod->get_sale_price();
                else if (method_exists($prod,'get_price')) $price = (float)$prod->get_price();
                if ($price <= 0 && method_exists($prod,'get_regular_price')) $price = (float)$prod->get_regular_price();
            }
        }
        if ($price <= 0) {
            $price = (float) get_post_meta($product_id, '_price', true);
        }
        return max(0.0, round($price, 2));
    }

    private static function clamp(float $v, float $min, float $max) : float { return max($min, min($max, $v)); }

    public static function claim_fee_breakdown(int $product_id) : array {
        $cfg = self::claim_settings();
        $factor = self::claim_factor();
        $pct = self::clamp($factor * 1.0, ($cfg['min_pct']/100.0), ($cfg['max_pct']/100.0));
        $price = self::product_price($product_id);
        $amount = max(round($price * $pct, 2), (float)$cfg['min_eur']);
        return [
            'factor' => $factor,
            'percent' => round($pct * 100.0, 2),
            'price' => $price,
            'amount' => $amount,
        ];
    }

   	public static function claim_fee_amount(int $product_id) : float {
		$b = self::claim_fee_breakdown($product_id); return (float)$b['amount'];
	}

	/** Count how many unpublished products (draft/pending/private) the user currently holds */
	public static function user_unpublished_count(int $user_id) : int {
		$q = new WP_Query([
			'post_type' => 'product',
			'post_status' => ['private','pending','draft'],
			'author' => (int)$user_id,
			'fields' => 'ids',
			'nopaging' => true,
			'no_found_rows' => true,
		]);
		return (int)$q->found_posts;
	}

	public static function schedule_cron() {
		if (!wp_next_scheduled('ds_entitlements_daily')) {
			wp_schedule_event(time() + 300, 'daily', 'ds_entitlements_daily');
		}
		if (!wp_next_scheduled('ds_entitlements_enforce')) {
			wp_schedule_event(time() + 600, 'hourly', 'ds_entitlements_enforce');
		}
		if (!wp_next_scheduled('ds_claim_expiry')) {
			wp_schedule_event(time() + 900, 'hourly', 'ds_claim_expiry');
		}
	}

	/** Run on first day of month (server-local time) to create previous month entitlements */
	public static function run_monthly_if_needed() {
		$now_ts = current_time('timestamp');
		if (date('d', $now_ts) !== '01') return; // only run on 1st of month
		$prev_month = date('Y-m', strtotime('-1 month', $now_ts));
		$last = get_option('ds_entitlements_last_month_processed');
		if ($last === $prev_month) return;
		self::compute_for_month($prev_month);
		update_option('ds_entitlements_last_month_processed', $prev_month);
	}

	public static function compute_for_month(string $ym) {
		$s = DS_Settings::get();
		$fee = isset($s['entitlement_fee_eur']) ? (float)$s['entitlement_fee_eur'] : 2.00;
		$confirm_days = isset($s['entitlement_confirm_days']) ? max(1, (int)$s['entitlement_confirm_days']) : 7;
		$enable = isset($s['entitlements_enable']) ? (int)$s['entitlements_enable'] : 1;
		$dyn_ent = !empty($s['entitlement_dynamic_fee_enable']);
		if (!$enable) return;

		$from = $ym . '-01';
		$to   = date('Y-m-t', strtotime($from));

		// 1) Collect views per product for the target month
		global $wpdb; $views_table = DS_Views::table(); $ent_table = self::table();
		$counts = [];
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT product_id, COUNT(*) AS c FROM {$views_table} WHERE view_date BETWEEN %s AND %s GROUP BY product_id",
			$from, $to
		));
		if ($rows) { foreach ($rows as $r) { $counts[(int)$r->product_id] = (int)$r->c; } }

		// 2) All products + authors
		$products = get_posts([
			'post_type' => 'product',
			'fields' => 'ids',
			'posts_per_page' => -1,
			'post_status' => ['publish','private','pending','draft','future'],
			'no_found_rows' => true,
		]);
		if (!$products) return;

		$total_views = 0; $n = count($products);
		foreach ($products as $pid) { $total_views += (int)($counts[(int)$pid] ?? 0); }
		$mean = $n > 0 ? ($total_views / $n) : 0.0;

		$now = DS_Helpers::now();
		$confirm_due = date('Y-m-d H:i:s', current_time('timestamp') + $confirm_days * DAY_IN_SECONDS);

		foreach ($products as $pid) {
			$pid = (int)$pid;
			$uid = (int) get_post_field('post_author', $pid);
			$views = (int)($counts[$pid] ?? 0);
			if ($views < $mean) {
				$due = $dyn_ent ? (float)self::claim_fee_amount($pid) : (float)$fee;
			} else {
				$due = 0.0;
			}
			$status = $due > 0 ? 'pending' : 'waived';
			$ref = 'entitlement:' . $pid . ':' . $ym;
			// Upsert
			$existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$ent_table} WHERE product_id=%d AND month=%s", $pid, $ym));
			$data = [
				'user_id' => $uid,
				'views' => $views,
				'mean_views' => $mean,
				'amount_due' => $due,
				'status' => $status,
				'confirm_due_at' => $status==='pending' ? $confirm_due : null,
				'updated_at' => $now,
				'ref_id' => $ref,
			];
			if ($existing) {
				$wpdb->update($ent_table, $data, [ 'id' => (int)$existing->id ]);
			} else {
				$wpdb->insert($ent_table, array_merge([
					'product_id' => $pid,
					'user_id' => $uid,
					'month' => $ym,
					'views' => $views,
					'mean_views' => $mean,
					'amount_due' => $due,
					'status' => $status,
					'confirm_due_at' => $status==='pending' ? $confirm_due : null,
					'charged_at' => null,
					'ref_id' => $ref,
					'created_at' => $now,
					'updated_at' => $now,
				], []));
			}
		}
	}

	public static function run_enforce() {
		$s = DS_Settings::get();
		$enable = isset($s['entitlements_enable']) ? (int)$s['entitlements_enable'] : 1;
		if (!$enable) return;
		global $wpdb; $ent_table = self::table();
		$now = DS_Helpers::now();
		$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$ent_table} WHERE status='pending' AND confirm_due_at IS NOT NULL AND confirm_due_at <= %s ORDER BY id ASC LIMIT 200", $now));
		if (!$rows) return;
		foreach ($rows as $r) {
			$fee = (float)$r->amount_due; if ($fee <= 0) { // nothing to charge, mark waived
				$wpdb->update($ent_table, ['status'=>'waived','updated_at'=>$now], ['id'=>(int)$r->id]);
				continue;
			}
			$uid = (int)$r->user_id; $pid = (int)$r->product_id; $ref = (string)$r->ref_id;
			$bal = DS_Wallet::balance($uid);
			if ($bal >= $fee) {
				self::charge_wallet_once($uid, $fee, $ref, ['product_id'=>$pid,'month'=>$r->month,'entitlement_id'=>(int)$r->id,'reason'=>'auto_enforce']);
				$wpdb->update($ent_table, ['status'=>'paid','charged_at'=>$now,'updated_at'=>$now], ['id'=>(int)$r->id]);
			} else {
				// Forfeit and move product to pool
				self::forfeit_to_pool($pid, $uid);
				$wpdb->update($ent_table, ['status'=>'forfeited','updated_at'=>$now], ['id'=>(int)$r->id]);
			}
		}
	}

	public static function handle_confirm() {
		if (!is_user_logged_in()) wp_die('No access');
		$uid = get_current_user_id();
		$ent_id = isset($_POST['ent_id']) ? (int)$_POST['ent_id'] : 0;
		$nonce = isset($_POST['_wpnonce']) ? $_POST['_wpnonce'] : '';
		if (!$ent_id || !wp_verify_nonce($nonce, 'ds_entitlement_confirm_'.$ent_id)) wp_die('Invalid request');
		global $wpdb; $t = self::table(); $now = DS_Helpers::now();
		$r = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d", $ent_id));
		if (!$r || (int)$r->user_id !== (int)$uid) wp_die('Not found');
		if ($r->status !== 'pending') {
			self::redirect_back('status', 'not_pending');
			return;
		}
		$fee = (float)$r->amount_due; if ($fee <= 0) { $wpdb->update($t, ['status'=>'waived','updated_at'=>$now], ['id'=>$ent_id]); self::redirect_back('status','waived'); return; }
		$bal = DS_Wallet::balance($uid);
		if ($bal < $fee) { self::redirect_back('status','insufficient'); return; }
		self::charge_wallet_once($uid, $fee, (string)$r->ref_id, ['product_id'=>(int)$r->product_id,'month'=>$r->month,'entitlement_id'=>$ent_id,'reason'=>'user_confirm']);
		$wpdb->update($t, ['status'=>'paid','charged_at'=>$now,'updated_at'=>$now], ['id'=>$ent_id]);
		self::redirect_back('status','paid');
	}

	public static function handle_claim() {
		if (!is_user_logged_in()) wp_die('No access');
		$uid = get_current_user_id();
		$pid = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
		$nonce = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';
		if ($pid <= 0 || !wp_verify_nonce($nonce, 'ds_pool_claim_'.$pid)) wp_die('Invalid request');
		if (!DS_Helpers::is_vendor($uid)) wp_die('No permission');
		// Verify pool status
		$in_pool = (int) get_post_meta($pid, '_ds_pool', true) === 1;
		if (!$in_pool) { self::redirect_back('claim', 'not_in_pool'); return; }

		// Compute claim fee (if enabled)
		$s = DS_Settings::get();
		$must_pay = !empty($s['claim_fee_enable']);
		$amount = 0.0; $breakdown = null;
		if ($must_pay) {
			$breakdown = self::claim_fee_breakdown($pid);
			$amount = (float)($breakdown['amount'] ?? 0);
		}
		if ($must_pay && $amount > 0) {
			$bal = DS_Wallet::balance($uid);
			if ($bal < $amount) { self::redirect_back('claim','insufficient'); return; }
			$ref = 'claim:' . $pid . ':' . date('Ymd', current_time('timestamp'));
			self::charge_wallet_once($uid, $amount, $ref, ['product_id'=>$pid,'breakdown'=>$breakdown], 'claim_fee');
			update_post_meta($pid, '_ds_last_claim_fee_eur', $amount);
		}

		// Enforce unpublished limit
		$limit = isset($s['claim_unpublished_limit']) ? (int)$s['claim_unpublished_limit'] : 3;
		if ($limit > 0) {
			$used = (int) self::user_unpublished_count($uid);
			if ($used >= $limit) { self::redirect_back('claim','limit'); return; }
		}

		// Transfer ownership
		wp_update_post(['ID'=>$pid, 'post_author'=>$uid]);
		delete_post_meta($pid, '_ds_pool');
		delete_post_meta($pid, '_ds_pool_since');
		update_post_meta($pid, '_ds_pool_claimed_by', $uid);
		update_post_meta($pid, '_ds_pool_claimed_at', DS_Helpers::now());
		// Auto-pause other users' campaigns if enabled
		$s = DS_Settings::get();
		if (!empty($s['ads_autopause_on_entitlement_loss']) && class_exists('DS_Ads') && method_exists('DS_Ads','pause_campaigns_for_product_except')) {
			DS_Ads::pause_campaigns_for_product_except($pid, $uid);
		}
		self::redirect_back('claim', 'ok');
	}

	private static function charge_wallet_once(int $user_id, float $fee, string $ref, array $meta = [], string $type = 'entitlement_fee') {
		global $wpdb; $w = DS_Wallet::table();
		$exists = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$w} WHERE ref_id=%s", $ref));
		if ($exists === 0) { DS_Wallet::add($user_id, $type, 0 - $fee, $ref, 'posted', $meta); }
	}

	public static function forfeit_to_pool(int $product_id, int $prev_owner) {
		$s = DS_Settings::get();
		$pool_uid = isset($s['pool_user_id']) ? (int)$s['pool_user_id'] : 0;
		if ($pool_uid <= 0) {
			// fallback: first admin
			$admins = get_users(['role'=>'administrator', 'number'=>1, 'fields'=>['ID']]);
			$pool_uid = $admins && isset($admins[0]) ? (int)$admins[0]->ID : 1;
		}
		wp_update_post(['ID'=>$product_id, 'post_author'=>$pool_uid]);
		update_post_meta($product_id, '_ds_pool', 1);
		update_post_meta($product_id, '_ds_pool_since', DS_Helpers::now());
		update_post_meta($product_id, '_ds_pool_prev_owner', $prev_owner);
		// Clear claim flags when moved to pool
		delete_post_meta($product_id, '_ds_pool_claimed_by');
		delete_post_meta($product_id, '_ds_pool_claimed_at');
		// Auto-pause campaigns not owned by new holder if enabled
		if (!empty($s['ads_autopause_on_entitlement_loss']) && class_exists('DS_Ads') && method_exists('DS_Ads','pause_campaigns_for_product_except')) {
			DS_Ads::pause_campaigns_for_product_except($product_id, $pool_uid);
		}
	}

	private static function redirect_back(string $key, string $val) {
		$ref = isset($_POST['_wp_http_referer']) ? $_POST['_wp_http_referer'] : (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : admin_url('admin.php'));
		$sep = strpos($ref, '?') === false ? '?' : '&';
		wp_safe_redirect($ref . $sep . rawurlencode($key) . '=' . rawurlencode($val));
		exit;
	}

	/** Helper: fetch current user's entitlements for latest month (previous month) */
	public static function user_entitlements_for_prev_month(int $user_id) : array {
		global $wpdb; $t = self::table();
		$prev_month = date('Y-m', strtotime('-1 month', current_time('timestamp')));
		$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE user_id=%d AND month=%s ORDER BY id DESC", $user_id, $prev_month));
		return is_array($rows) ? $rows : [];
	}

	/** Helper: list pool products */
	public static function pool_products(int $limit = 50) : array {
		$q = new WP_Query([
			'post_type' => 'product',
			'posts_per_page' => $limit,
			'post_status' => ['publish','private','pending','draft'],
			'meta_query' => [
				['key'=>'_ds_pool','value'=>1,'compare'=>'=']
			],
			'orderby' => 'date',
			'order' => 'DESC',
			'fields' => 'ids',
		]);
		return $q->posts;
	}

	public static function add_meta_box() {
		if (!current_user_can('edit_others_products')) return;
		add_meta_box('ds_entitlement_box','Entitlement',[__CLASS__,'render_meta_box'],'product','side','high');
	}
	public static function render_meta_box($post) {
		if (!current_user_can('edit_others_products')) { echo '<p>No access.</p>'; return; }
		$pid = (int)$post->ID;
		$holder = (int)self::current_holder($pid);
		$holder_name = $holder ? ( ($u=get_userdata($holder)) ? esc_html($u->display_name) : ('#'.$holder) ) : '-';
		$override = (int) get_post_meta($pid, '_ds_entitlement_override_user', true);
		$in_pool = (int) get_post_meta($pid, '_ds_pool', true) === 1;
		echo '<p><b>Current holder:</b> '.$holder_name.($override? ' <span style="color:#d63638;">(override)</span>':'').($in_pool?' <span style="color:#d63638;">(in pool)</span>':'').'</p>';
		// Info: current dynamic claim fee
		if (method_exists(__CLASS__, 'claim_fee_breakdown')) {
			$bd = self::claim_fee_breakdown($pid);
			$fee_t = '€'.number_format((float)($bd['amount'] ?? 0),2).' ('.number_format((float)($bd['percent'] ?? 0),2).'%)';
			echo '<p style="color:#666;">Claim fee today: '.esc_html($fee_t).'</p>';
		}
		// Set override
		printf('<form method="post" action="%s">', esc_url(admin_url('admin-post.php?action=ds_entitlement_override_set')));
		printf('<input type="hidden" name="product_id" value="%d">', (int)$pid);
		echo wp_nonce_field('ds_ent_override_'.$pid, '_ds_ent_nonce', true, false);
		echo '<p>User ID: <input type="number" name="user_id" min="1" step="1" style="width:100%"></p>';
		echo '<p><button class="button button-primary" type="submit">Set override</button></p>';
		echo '</form>';
		// Clear override
		printf('<form method="post" action="%s" style="margin-top:6px">', esc_url(admin_url('admin-post.php?action=ds_entitlement_override_clear')));
		printf('<input type="hidden" name="product_id" value="%d">', (int)$pid);
		echo wp_nonce_field('ds_ent_override_'.$pid, '_ds_ent_nonce', true, false);
		echo '<p><button class="button" type="submit">Clear override</button></p>';
		echo '</form>';
		// Pool controls
		if (!$in_pool) {
			printf('<form method="post" action="%s" style="margin-top:6px">', esc_url(admin_url('admin-post.php?action=ds_pool_send')));
			printf('<input type="hidden" name="product_id" value="%d">', (int)$pid);
			echo wp_nonce_field('ds_pool_'.$pid, '_ds_ent_nonce', true, false);
			echo '<p><button class="button" type="submit">Send to Pool</button></p>';
			echo '</form>';
		} else {
			printf('<form method="post" action="%s" style="margin-top:6px">', esc_url(admin_url('admin-post.php?action=ds_pool_remove')));
			printf('<input type="hidden" name="product_id" value="%d">', (int)$pid);
			echo wp_nonce_field('ds_pool_'.$pid, '_ds_ent_nonce', true, false);
			echo '<p>Restore to User ID (optional): <input type="number" name="user_id" min="1" step="1" style="width:100%"></p>';
			echo '<p><button class="button" type="submit">Remove from Pool</button></p>';
			echo '</form>';
		}
	}

	public static function product_columns($cols){
		$cols['ds_entitlement'] = 'Entitlement'; return $cols;
	}
	public static function render_product_column($col,$post_id){
		if ($col !== 'ds_entitlement') return;
		$holder = (int)self::current_holder((int)$post_id);
		$override = (int) get_post_meta($post_id, '_ds_entitlement_override_user', true);
		$in_pool = (int) get_post_meta($post_id, '_ds_pool', true) === 1;
		$name = $holder ? ( ($u=get_userdata($holder)) ? esc_html($u->display_name) : ('#'.$holder) ) : '-';
		echo esc_html($name);
		if ($override) echo ' <span class="dashicons dashicons-admin-network" title="override"></span>';
		if ($in_pool) echo ' <span class="dashicons dashicons-groups" title="pool"></span>';
	}
	public static function register_bulk_actions($actions){
		$actions['ds_send_to_pool'] = 'Send to Pool';
		$actions['ds_remove_from_pool'] = 'Remove from Pool';
		$actions['ds_clear_ent_override'] = 'Clear Entitlement Override';
		return $actions;
	}
	public static function handle_bulk_actions($redirect_to, $doaction, $post_ids){
		if (!current_user_can('edit_others_products')) return $redirect_to;
		$act = (string)$doaction;
		$count = 0;
		foreach ((array)$post_ids as $pid){
			$pid = (int)$pid; if ($pid<=0) continue;
			if ($act === 'ds_send_to_pool') { self::forfeit_to_pool($pid, (int)get_post_field('post_author',$pid)); $count++; unset(self::$holder_cache[$pid]); }
			elseif ($act === 'ds_remove_from_pool') { self::remove_from_pool($pid, 0); $count++; unset(self::$holder_cache[$pid]); }
			elseif ($act === 'ds_clear_ent_override') { delete_post_meta($pid, '_ds_entitlement_override_user'); $count++; unset(self::$holder_cache[$pid]); }
		}
		return add_query_arg(['ds_bulk_done'=>$act,'ds_count'=>$count], $redirect_to);
	}

	public static function handle_override_set(){
		if (!current_user_can('edit_others_products')) wp_die('No permission');
		$pid = (int)($_POST['product_id'] ?? 0);
		$uid = (int)($_POST['user_id'] ?? 0);
		check_admin_referer('ds_ent_override_'.$pid, '_ds_ent_nonce');
		if ($pid>0 && $uid>0) {
			update_post_meta($pid, '_ds_entitlement_override_user', $uid);
			unset(self::$holder_cache[$pid]);
			if (class_exists('DS_Ads') && method_exists('DS_Ads','pause_campaigns_for_product_except')) { DS_Ads::pause_campaigns_for_product_except($pid, $uid); }
		}
		wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=product'));
		exit;
	}
	public static function handle_override_clear(){
		if (!current_user_can('edit_others_products')) wp_die('No permission');
		$pid = (int)($_POST['product_id'] ?? 0);
		check_admin_referer('ds_ent_override_'.$pid, '_ds_ent_nonce');
		if ($pid>0) { delete_post_meta($pid, '_ds_entitlement_override_user'); unset(self::$holder_cache[$pid]); }
		wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=product'));
		exit;
	}
	public static function handle_pool_send(){
		if (!current_user_can('edit_others_products')) wp_die('No permission');
		$pid = (int)($_POST['product_id'] ?? 0);
		check_admin_referer('ds_pool_'.$pid, '_ds_ent_nonce');
		if ($pid>0) { self::forfeit_to_pool($pid, (int)get_post_field('post_author',$pid)); unset(self::$holder_cache[$pid]); }
		wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=product'));
		exit;
	}
	public static function handle_pool_remove(){
		if (!current_user_can('edit_others_products')) wp_die('No permission');
		$pid = (int)($_POST['product_id'] ?? 0);
		$uid = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
		check_admin_referer('ds_pool_'.$pid, '_ds_ent_nonce');
		if ($pid>0) { self::remove_from_pool($pid, $uid); unset(self::$holder_cache[$pid]); }
		wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=product'));
		exit;
	}
	public static function remove_from_pool(int $product_id, int $to_user_id = 0){
		// Choose target user
		$target = $to_user_id>0 ? $to_user_id : (int) get_post_meta($product_id, '_ds_pool_prev_owner', true);
		if ($target > 0) { wp_update_post(['ID'=>$product_id,'post_author'=>$target]); }
		delete_post_meta($product_id, '_ds_pool');
		delete_post_meta($product_id, '_ds_pool_since');
		// Keep prev_owner for audit; do not delete
		if ($target>0 && class_exists('DS_Ads') && method_exists('DS_Ads','pause_campaigns_for_product_except')) { DS_Ads::pause_campaigns_for_product_except($product_id, $target); }
	}

 public static function run_claim_expiry(){
 		$s = DS_Settings::get();
 		$days = isset($s['claim_expire_days']) ? (int)$s['claim_expire_days'] : 3;
 		if ($days <= 0) return;
 		$now_ts = current_time('timestamp');
 		$q = new WP_Query([
 			'post_type' => 'product',
 			'post_status' => ['private','pending','draft'],
 			'posts_per_page' => 200,
 			'fields' => 'ids',
 			'meta_query' => [
 				['key'=>'_ds_pool_claimed_by','compare'=>'EXISTS'],
 			],
 			'orderby' => 'date',
 			'order' => 'ASC',
 		]);
 		$posts = $q && isset($q->posts) ? (array)$q->posts : [];
 		foreach ($posts as $pid) {
 			$pid = (int)$pid;
 			$claimed_at = (string) get_post_meta($pid, '_ds_pool_claimed_at', true);
 			if (!$claimed_at) continue;
 			$ts = strtotime($claimed_at);
 			if (!$ts) continue;
 			if (($now_ts - $ts) >= ($days * DAY_IN_SECONDS)) {
 				$prev = (int) get_post_field('post_author', $pid);
 				self::forfeit_to_pool($pid, $prev);
 				delete_post_meta($pid, '_ds_pool_claimed_by');
 				delete_post_meta($pid, '_ds_pool_claimed_at');
 			}
 		}
 	}

 	public static function on_status_transition($new_status, $old_status, $post){
 		if (!is_object($post) || $post->post_type !== 'product') return;
 		if ($new_status === 'publish' && $old_status !== 'publish') {
 			delete_post_meta($post->ID, '_ds_pool_claimed_by');
 			delete_post_meta($post->ID, '_ds_pool_claimed_at');
 		}
 	}

 	public static function handle_bulk_form(){
		if (!current_user_can('edit_others_products')) wp_die('No permission');
		check_admin_referer('ds_ent_bulk');
		$act = sanitize_text_field($_POST['bulk_action'] ?? '');
		$ids_raw = (string)($_POST['product_ids'] ?? '');
		$user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
		$ids = array_values(array_filter(array_map('intval', preg_split('/[\s,;]+/', $ids_raw))));
		$count=0;
		foreach ($ids as $pid){
			if ($pid<=0) continue;
			if ($act==='set_override' && $user_id>0) { update_post_meta($pid,'_ds_entitlement_override_user',$user_id); unset(self::$holder_cache[$pid]); if (class_exists('DS_Ads') && method_exists('DS_Ads','pause_campaigns_for_product_except')) { DS_Ads::pause_campaigns_for_product_except($pid, $user_id); } $count++; }
			elseif ($act==='clear_override') { delete_post_meta($pid,'_ds_entitlement_override_user'); unset(self::$holder_cache[$pid]); $count++; }
			elseif ($act==='send_pool') { self::forfeit_to_pool($pid, (int)get_post_field('post_author',$pid)); unset(self::$holder_cache[$pid]); $count++; }
			elseif ($act==='remove_pool') { self::remove_from_pool($pid, $user_id); unset(self::$holder_cache[$pid]); $count++; }
		}
		$ref = wp_get_referer() ?: admin_url('admin.php?page=ds-entitlements-manager');
		$ref = add_query_arg(['bulk_done'=>$act,'bulk_count'=>$count], $ref);
		wp_safe_redirect($ref); exit;
	}
}
