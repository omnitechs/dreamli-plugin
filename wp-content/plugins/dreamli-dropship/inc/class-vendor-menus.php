<?php
if (!defined('ABSPATH')) exit;

final class DS_Vendor_Menus {
    static function init() {
        add_action('admin_menu', [__CLASS__, 'menus']);
    }

    static function menus() {
        if (!DS_Helpers::is_vendor()) return;

        add_menu_page('Dashboard', 'Dashboard', 'read', 'ds-vendor-dashboard', [__CLASS__, 'dashboard_page'], 'dashicons-dashboard', 3);
        add_menu_page('کیف پول من', 'کیف پول من', 'read', 'ds-my-wallet', [__CLASS__, 'wallet_page'], 'dashicons-money', 4);
        add_menu_page('فروش‌های من', 'فروش‌های من', 'edit_products', 'ds-my-sales', [__CLASS__, 'sales_page'], 'dashicons-cart', 5);
        add_menu_page('پروموت محصولات', 'پروموت محصولات', 'edit_products', 'ds-promoted', [__CLASS__, 'ads_page'], 'dashicons-megaphone', 6);
        add_menu_page('Leaderboard', 'Leaderboard', 'read', 'ds-leaderboard', [__CLASS__, 'leaderboard_page'], 'dashicons-chart-bar', 7);
    }

    static function ads_page() {
        if (!is_user_logged_in()) wp_die('No access.');
        if (!current_user_can('edit_products') || current_user_can('edit_others_products')) wp_die('دسترسی ندارید.');

        $uid = get_current_user_id();
        $user_pids = DS_Orders::user_product_ids($uid);
        $own_products = get_posts([
            'post_type' => 'product',
            'author' => $uid,
            'fields' => 'ids',
            'posts_per_page' => -1,
            'post_status' => ['publish','private','draft','pending','future'],
            'orderby' => 'title',
            'order' => 'ASC',
            'no_found_rows' => true,
        ]);

        echo '<div class="wrap"><h1>پروموت محصولات (CPC)</h1>';

        // Handle actions: create, toggle, delete
        if (isset($_POST['ds_ads_create']) && check_admin_referer('ds_ads_create')) {
            $product_id = (int)($_POST['product_id'] ?? 0);
            $placement = sanitize_text_field($_POST['placement'] ?? 'home');
            $cat_id = (int)($_POST['category_id'] ?? 0);
            $daily_budget = round((float)($_POST['daily_budget'] ?? 0), 2);
            $status = 'active';

            $errors = [];
            if ($product_id <= 0 || !in_array($product_id, $user_pids, true)) $errors[] = 'محصول نامعتبر است.';
            if (!in_array($placement, ['home','category'], true)) $placement = 'home';
            if ($placement === 'category' && $cat_id <= 0) $errors[] = 'دسته‌بندی لازم است.';
            if ($daily_budget <= 0) $errors[] = 'بودجه روزانه باید بیشتر از صفر باشد.';

            if (empty($errors)) {
                global $wpdb; $table = DS_Ads::table();
                $now = DS_Helpers::now();
                $wpdb->insert($table, [
                    'user_id' => $uid,
                    'product_id' => $product_id,
                    'placement' => $placement,
                    'category_id' => $placement==='category' ? $cat_id : null,
                    'daily_budget' => $daily_budget,
                    'spent_today' => 0.00,
                    'today_clicks' => 0,
                    'spent_date' => null,
                    'status' => $status,
                    'total_clicks' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], [ '%d','%d','%s','%d','%f','%f','%d','%s','%s','%d','%s','%s' ]);
                echo '<div class="notice notice-success"><p>کمپین ایجاد شد.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>'.esc_html(implode(' ', $errors)).'</p></div>';
            }
        }

        if (isset($_GET['toggle']) && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'ds_ads_toggle_'.$uid)) {
            global $wpdb; $id = (int)$_GET['toggle']; if ($id>0) {
                $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.DS_Ads::table().' WHERE id=%d AND user_id=%d', $id, $uid));
                if ($row) {
                    $new = $row->status==='active' ? 'paused' : 'active';
                    $wpdb->update(DS_Ads::table(), [ 'status'=>$new, 'updated_at'=>DS_Helpers::now() ], [ 'id'=>$id ], [ '%s','%s' ], [ '%d' ]);
                    echo '<div class="notice notice-success"><p>وضعیت کمپین تغییر کرد.</p></div>';
                }
            }
        }
        if (isset($_GET['delete']) && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'ds_ads_delete_'.$uid)) {
            global $wpdb; $id = (int)$_GET['delete']; if ($id>0) {
                $wpdb->delete(DS_Ads::table(), [ 'id'=>$id, 'user_id'=>$uid ], [ '%d','%d' ]);
                echo '<div class="notice notice-success"><p>کمپین حذف شد.</p></div>';
            }
        }

        $s = DS_Settings::get();
        printf('<p><b>هزینه هر کلیک</b>: خانه €%.2f | دسته‌بندی €%.2f</p>', (float)$s['ads_cpc_home'], (float)$s['ads_cpc_category']);

        echo '<h2>ایجاد کمپین جدید</h2>';
        echo '<form method="post" style="background:#fff;padding:12px;border:1px solid #ddd;max-width:780px;">';
        wp_nonce_field('ds_ads_create');
        // Product select
        echo '<p>محصول: <select name="product_id" required><option value="">— انتخاب محصول —</option>';
        foreach ($own_products as $pid) {
            printf('<option value="%d">%s</option>', (int)$pid, esc_html(get_the_title($pid)));
        }
        echo '</select></p>';
        echo '<p>جایگاه: <label><input type="radio" name="placement" value="home" checked> صفحه خانه</label> '
            .'<label style="margin-left:16px;"><input type="radio" name="placement" value="category"> صفحه دسته‌بندی</label></p>';

        // Category selector
        $cats = get_terms(['taxonomy'=>'product_cat','hide_empty'=>false]);
        echo '<p>دسته‌بندی (اگر جایگاه دسته‌بندی را انتخاب می‌کنید): <select name="category_id"><option value="">— انتخاب دسته —</option>';
        if (!is_wp_error($cats)) {
            foreach ($cats as $t) {
                printf('<option value="%d">%s</option>', (int)$t->term_id, esc_html($t->name));
            }
        }
        echo '</select></p>';

        echo '<p>بودجه روزانه (€): <input type="number" name="daily_budget" step="0.01" min="0.01" required></p>';
        echo '<p><button class="button button-primary" name="ds_ads_create" value="1">ایجاد کمپین</button></p>';
        echo '</form>';

        // List campaigns
        global $wpdb; $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM '.DS_Ads::table().' WHERE user_id=%d ORDER BY id DESC', $uid));
        echo '<h2 style="margin-top:24px;">کمپین‌های من</h2>';
        if ($rows) {
            echo '<table class="widefat striped"><thead><tr><th>ID</th><th>محصول</th><th>جایگاه</th><th>دسته</th><th>بودجه روز</th><th>خرج امروز</th><th>کلیک امروز</th><th>کلیک کل</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $r = DS_Ads::defaults_today_state($r);
                $prod = get_the_title((int)$r->product_id);
                $cat_name = $r->category_id ? ( ($t = get_term((int)$r->category_id, 'product_cat')) && !is_wp_error($t) ? $t->name : '-' ) : '-';
                $toggle_url = wp_nonce_url(add_query_arg(['page'=>'ds-promoted','toggle'=>(int)$r->id], admin_url('admin.php')), 'ds_ads_toggle_'.$uid);
                $delete_url = wp_nonce_url(add_query_arg(['page'=>'ds-promoted','delete'=>(int)$r->id], admin_url('admin.php')), 'ds_ads_delete_'.$uid);
                printf('<tr>'
                    .'<td>%d</td>'
                    .'<td>%s</td>'
                    .'<td>%s</td>'
                    .'<td>%s</td>'
                    .'<td>€%0.2f</td>'
                    .'<td>€%0.2f</td>'
                    .'<td>%d</td>'
                    .'<td>%d</td>'
                    .'<td>%s</td>'
                    .'<td><a class="button" href="%s">%s</a> <a class="button button-link-delete" href="%s" onclick="return confirm(\'حذف شود؟\');">حذف</a></td>'
                    .'</tr>',
                    (int)$r->id,
                    esc_html($prod ?: ('#'.$r->product_id)),
                    $r->placement==='category'?'دسته‌بندی':'خانه',
                    esc_html($cat_name),
                    (float)$r->daily_budget,
                    (float)$r->spent_today,
                    (int)$r->today_clicks,
                    (int)$r->total_clicks,
                    esc_html($r->status),
                    esc_url($toggle_url),
                    $r->status==='active'?'توقف':'فعال‌سازی',
                    esc_url($delete_url)
                );
            }
            echo '</tbody></table>';
        } else {
            echo '<p>هنوز کمپینی ایجاد نکرده‌اید.</p>';
        }

        echo '<div style="margin-top:24px;max-width:780px;background:#fff;border:1px solid #eee;padding:12px;">'
            .'<h3>نحوه نمایش</h3>'
            .'<p>برای نمایش در صفحه خانه یا دسته، از شورت‌کد زیر استفاده کنید:</p>'
            .'<pre>[ds_promoted_products placement="auto" limit="4"]</pre>'
            .'<p>یا برای یک دسته خاص:</p>'
            .'<pre>[ds_promoted_products placement="category" category="shoe" limit="6"]</pre>'
            .'</div>';

        echo '</div>';
    }

    static function wallet_page() {
        if (!is_user_logged_in()) wp_die('No access.');
        $s = DS_Settings::get();
        $uid = get_current_user_id();
        $balance = DS_Wallet::balance($uid);

        echo '<div class="wrap"><h1>کیف پول من</h1>';
        printf('<p><strong>موجودی:</strong> €%.2f</p>', $balance);
        printf('<p>حداقل برداشت: €%.2f</p>', (float)$s['withdraw_min']);

        echo '<form method="post" style="margin:12px 0">';
        wp_nonce_field('ds_withdraw_'.$uid);
        echo '<input type="number" name="amount" step="0.01" min="'.esc_attr($s['withdraw_min']).'" max="'.esc_attr($balance).'" placeholder="مثلاً 10.00" required>';
        echo ' <button class="button button-primary" name="ds_withdraw" value="1">درخواست برداشت</button>';
        echo '</form>';

        if (isset($_POST['ds_withdraw']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'ds_withdraw_'.$uid)) {
            $amt = (float) ($_POST['amount'] ?? 0);
            if ($amt >= (float)$s['withdraw_min'] && $amt <= $balance) {
                DS_Wallet::add($uid, 'withdraw', -$amt, 'req#'.uniqid(), 'pending', []);
                echo '<div class="notice notice-success"><p>درخواست برداشت ثبت شد.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>مبلغ نامعتبر.</p></div>';
            }
        }

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM ".DS_Wallet::table()." WHERE user_id=%d ORDER BY id DESC LIMIT 50", $uid
        ));

        echo '<h2>تراکنش‌ها</h2>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>نوع</th><th>مبلغ</th><th>وضعیت</th><th>ارجاع</th><th>تاریخ</th></tr></thead><tbody>';
        if ($rows) {
            foreach ($rows as $r) {
                printf('<tr><td>%d</td><td>%s</td><td>%0.2f</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                    $r->id, esc_html($r->type), $r->amount, esc_html($r->status), esc_html($r->ref_id), esc_html($r->created_at)
                );
            }
        } else echo '<tr><td colspan="6">رکوردی نیست.</td></tr>';
        echo '</tbody></table></div>';
    }

    static function sales_page() {
        if (!is_user_logged_in()) wp_die('No access.');
        if (!current_user_can('edit_products') || current_user_can('edit_others_products')) wp_die('دسترسی ندارید.');

        $user_pids = DS_Orders::user_product_ids(get_current_user_id());
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $paged  = max(1, intval($_GET['paged'] ?? 1));
        $per_page = 25;

        $order_ids = DS_Orders::order_ids_containing_products($user_pids, 1000);

        echo '<div class="wrap"><h1>فروش‌های من</h1>';

        echo '<form method="get" style="margin:12px 0">';
        echo '<input type="hidden" name="page" value="ds-my-sales">';
        echo '<label>وضعیت: <select name="status"><option value="">همه</option>';
        foreach (wc_get_order_statuses() as $key => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($key), selected($status,$key,false), esc_html($label));
        }
        echo '</select></label> <button class="button button-primary">اعمال</button></form>';

        if (empty($order_ids)) { echo '<p>سفارشی یافت نشد.</p></div>'; return; }

        $args = ['include'=>$order_ids,'limit'=>-1,'orderby'=>'date','order'=>'DESC','status'=>$status?[$status]:[],'return'=>'objects'];
        $orders = wc_get_orders($args);
        usort($orders, function($a,$b){ return $b->get_date_created()->getTimestamp() <=> $a->get_date_created()->getTimestamp(); });

        $total = count($orders);
        $pages = max(1, ceil($total / $per_page));
        $offset = ($paged - 1) * $per_page;
        $orders = array_slice($orders, $offset, $per_page);

        echo '<table class="widefat fixed striped"><thead><tr><th>#</th><th>تاریخ</th><th>وضعیت</th><th>مشتری</th><th>جمع متعلق به شما</th><th>آیتم‌های شما</th></tr></thead><tbody>';

        foreach ($orders as $order) {
            if (!DS_Orders::order_contains_user_products($order, $user_pids)) continue;

            $items_html = []; $my_total=0;
            foreach ($order->get_items('line_item') as $item) {
                $pid = $item->get_product_id() ?: $item->get_variation_id();
                if ($pid && in_array((int)$pid, $user_pids, true)) {
                    $qty = $item->get_quantity();
                    $name = $item->get_name();
                    $line_t = $item->get_total();
                    $items_html[] = sprintf('%s × %d (%.2f)', esc_html($name), intval($qty), floatval($line_t));
                    $my_total += floatval($line_t);
                }
            }

            printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><strong>%.2f</strong></td><td>%s</td></tr>',
                esc_html($order->get_order_number()),
                esc_html($order->get_date_created() ? $order->get_date_created()->date_i18n('Y-m-d H:i') : '-'),
                esc_html(wc_get_order_status_name($order->get_status())),
                esc_html(trim($order->get_formatted_billing_full_name()) ?: $order->get_billing_email()),
                $my_total,
                esc_html(implode('، ',$items_html) ?: '-')
            );
        }
        echo '</tbody></table>';

        if ($pages > 1) {
            echo '<p style="margin-top:12px;">';
            for ($i=1;$i<=$pages;$i++){
                $url = add_query_arg(['page'=>'ds-my-sales','status'=>$status,'paged'=>$i], admin_url('admin.php'));
                printf('<a class="button%s" href="%s" style="margin-right:6px;">%d</a>', $i===$paged?' button-primary':'', esc_url($url), $i);
            }
            echo '</p>';
        }
        echo '</div>';
    }

    static function leaderboard_page() {
        if (!is_user_logged_in()) wp_die('No access.');
        if (!DS_Helpers::is_vendor()) wp_die('No access.');

        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'earned';
        $preset = isset($_GET['preset']) ? sanitize_key($_GET['preset']) : 'weekly';
        $from = isset($_GET['from']) ? sanitize_text_field($_GET['from']) : '';
        $to   = isset($_GET['to']) ? sanitize_text_field($_GET['to']) : '';
        $paged = max(1, intval($_GET['paged'] ?? 1));

        list($start, $end) = DS_Leaderboard::get_period($preset, $from, $to);
        $rows = DS_Leaderboard::aggregate($start, $end);

        echo '<div class="wrap">';
        echo '<h1>Leaderboard</h1>';
        DS_Leaderboard::render_filters($tab, $preset, $start, $end);
        DS_Leaderboard::render_table($rows, $tab, $paged, 50);
        echo '<p style="margin-top:12px;color:#666;">Views counted once per viewer per product per day. Time range: '.esc_html($start).' → '.esc_html($end).'</p>';
        echo '</div>';
    }

    static function dashboard_page() {
        if (!is_user_logged_in()) wp_die('No access.');
        if (!DS_Helpers::is_vendor()) wp_die('No access.');
        $uid = get_current_user_id();

        $preset = isset($_GET['preset']) ? sanitize_key($_GET['preset']) : 'weekly';
        $from = isset($_GET['from']) ? sanitize_text_field($_GET['from']) : '';
        $to   = isset($_GET['to']) ? sanitize_text_field($_GET['to']) : '';
        list($start, $end) = DS_Leaderboard::get_period($preset, $from, $to);
        $from_d = substr($start,0,10); $to_d = substr($end,0,10);

        global $wpdb;
        $vt = class_exists('DS_Views') ? DS_Views::table() : '';
        $wt = DS_Wallet::table();

        // KPIs
        $views = $vt ? (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$vt} WHERE vendor_id=%d AND view_date BETWEEN %s AND %s", $uid, $from_d, $to_d)) : 0;
        $view_eur = (float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM {$wt} WHERE user_id=%d AND type='view_reward' AND status IN ('posted','paid') AND created_at BETWEEN %s AND %s", $uid, $start, $end));
        $products_created = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status IN ('publish','private','draft','pending','future') AND post_author=%d AND post_date BETWEEN %s AND %s", $uid, $start, $end));
        $wallet_balance = DS_Wallet::balance($uid);

        // Optional orders KPI (if WooCommerce available)
        $orders_count = 0; $orders_revenue = 0.0;
        if (function_exists('wc_get_orders') && class_exists('DS_Orders')) {
            $user_pids = DS_Orders::user_product_ids($uid);
            if ($user_pids) {
                $order_ids = DS_Orders::order_ids_containing_products($user_pids, 500);
                if ($order_ids) {
                    $orders = wc_get_orders(['include'=>$order_ids,'limit'=>-1,'return'=>'objects']);
                    foreach ($orders as $order) {
                        $dt = $order->get_date_created(); $ts = $dt ? $dt->getTimestamp() : 0;
                        if ($ts && $ts >= strtotime($start) && $ts <= strtotime($end)) {
                            $sum_vendor = 0.0; $has_item=false;
                            foreach ($order->get_items('line_item') as $item) {
                                $pid = $item->get_product_id() ?: $item->get_variation_id();
                                if ($pid && in_array((int)$pid, $user_pids, true)) {
                                    $sum_vendor += (float)$item->get_total();
                                    $has_item = true;
                                }
                            }
                            if ($has_item) { $orders_count++; $orders_revenue += $sum_vendor; }
                        }
                    }
                }
            }
        }

        echo '<div class="wrap">';
        echo '<h1>Vendor Dashboard</h1>';
        echo '<form method="get" style="margin:12px 0;">';
        echo '<input type="hidden" name="page" value="ds-vendor-dashboard">';
        echo '<label>Period: <select name="preset">';
        foreach (['daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly','custom'=>'Custom'] as $k=>$lab) {
            printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($preset,$k,false), esc_html($lab));
        }
        echo '</select></label> ';
        echo '<label>From: <input type="date" name="from" value="'.esc_attr($from_d).'"></label> ';
        echo '<label>To: <input type="date" name="to" value="'.esc_attr($to_d).'"></label> ';
        echo '<button class="button">Apply</button>';
        echo '</form>';

        echo '<div class="card" style="padding:12px;max-width:1100px;background:#fff;border:1px solid #e5e5e5;">';
        printf('<p><b>Views:</b> %d &nbsp; | &nbsp; <b>View Earnings:</b> €%0.2f &nbsp; | &nbsp; <b>Products Created:</b> %d &nbsp; | &nbsp; <b>Orders:</b> %d &nbsp; | &nbsp; <b>Orders Revenue:</b> €%0.2f &nbsp; | &nbsp; <b>Wallet Balance:</b> €%0.2f</p>', $views, $view_eur, $products_created, $orders_count, $orders_revenue, $wallet_balance);
        echo '</div>';

        // Top products by views
        if ($vt) {
            $top_products = $wpdb->get_results($wpdb->prepare("SELECT product_id, COUNT(*) AS views FROM {$vt} WHERE vendor_id=%d AND view_date BETWEEN %s AND %s GROUP BY product_id ORDER BY views DESC LIMIT 10", $uid, $from_d, $to_d));
            echo '<h2 style="margin-top:16px;">Top Products by Views</h2><table class="widefat striped"><thead><tr><th>Product</th><th>Views</th></tr></thead><tbody>';
            if ($top_products) {
                foreach ($top_products as $r) {
                    $title = get_the_title((int)$r->product_id) ?: ('#'.(int)$r->product_id);
                    printf('<tr><td>%s</td><td>%d</td></tr>', esc_html($title), (int)$r->views);
                }
            } else echo '<tr><td colspan="2">—</td></tr>';
            echo '</tbody></table>';
        }

        // Latest view rewards
        $latest = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wt} WHERE user_id=%d AND type='view_reward' AND status IN ('posted','paid') AND created_at BETWEEN %s AND %s ORDER BY id DESC LIMIT 25", $uid, $start, $end));
        echo '<h2 style="margin-top:16px;">Latest View Rewards</h2><table class="widefat striped"><thead><tr><th>ID</th><th>€</th><th>Ref</th><th>Created</th></tr></thead><tbody>';
        if ($latest) {
            foreach ($latest as $r) {
                printf('<tr><td>%d</td><td>€%0.4f</td><td>%s</td><td>%s</td></tr>', (int)$r->id, (float)$r->amount, esc_html($r->ref_id), esc_html($r->created_at));
            }
        } else echo '<tr><td colspan="4">—</td></tr>';
        echo '</tbody></table>';

        echo '</div>';
    }
}
