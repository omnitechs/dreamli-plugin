<?php
if (!defined('ABSPATH')) exit;

final class DS_Vendor_Menus {
    static function init() {
        add_action('admin_menu', [__CLASS__, 'menus']);
    }

    static function menus() {
        if (!DS_Helpers::is_vendor()) return;

        add_menu_page('کیف پول من', 'کیف پول من', 'read', 'ds-my-wallet', [__CLASS__, 'wallet_page'], 'dashicons-money', 4);
        add_menu_page('فروش‌های من', 'فروش‌های من', 'edit_products', 'ds-my-sales', [__CLASS__, 'sales_page'], 'dashicons-cart', 5);
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
}
