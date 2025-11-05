<?php
if (!defined('ABSPATH')) exit;

final class DS_Leaderboard {
    public static function get_period($preset = 'weekly', $from = '', $to = '') : array {
        $now_ts = current_time('timestamp');
        $tz = wp_timezone();
        $today = new DateTimeImmutable('now', $tz);
        $start = $end = null;
        $preset = in_array($preset, ['daily','weekly','monthly','custom'], true) ? $preset : 'weekly';
        if ($preset === 'daily') {
            $start = $today->setTime(0,0,0);
            $end   = $today->setTime(23,59,59);
        } elseif ($preset === 'weekly') {
            // From Monday of this week to now
            $dow = (int)$today->format('N'); // 1..7
            $start = $today->modify('-'.($dow-1).' days')->setTime(0,0,0);
            $end   = $today->setTime(23,59,59);
        } elseif ($preset === 'monthly') {
            $start = $today->modify('first day of this month')->setTime(0,0,0);
            $end   = $today->setTime(23,59,59);
        } else { // custom
            $from_d = $from ? date_create_immutable($from, $tz) : null;
            $to_d   = $to   ? date_create_immutable($to, $tz)   : null;
            if (!$from_d || !$to_d) {
                // fallback weekly
                $dow = (int)$today->format('N');
                $start = $today->modify('-'.($dow-1).' days')->setTime(0,0,0);
                $end   = $today->setTime(23,59,59);
            } else {
                if ($from_d > $to_d) { $tmp=$from_d; $from_d=$to_d; $to_d=$tmp; }
                $start = $from_d->setTime(0,0,0);
                $end   = $to_d->setTime(23,59,59);
            }
        }
        return [ $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s') ];
    }

    public static function vendors_list() : array {
        $args = [
            'role__in' => ['ds_vendor_open','ds_vendor_curated'],
            'fields' => ['ID','user_login','display_name'],
            'number' => -1
        ];
        $users = get_users($args);
        $list = [];
        foreach ($users as $u) {
            $list[(int)$u->ID] = [
                'user_id' => (int)$u->ID,
                'username' => $u->user_login,
                'display_name' => $u->display_name ?: $u->user_login,
            ];
        }
        return $list;
    }

    public static function aggregate($from, $to) : array {
        global $wpdb;
        $vendors = self::vendors_list();
        $rows = [];
        foreach ($vendors as $uid => $info) {
            $rows[$uid] = [
                'user_id' => $uid,
                'username' => $info['username'],
                'display_name' => $info['display_name'],
                'earned' => 0.0,
                'spent' => 0.0,
                'volume' => 0.0,
                'products' => 0,
                'views' => 0,
            ];
        }
        if (empty($rows)) return [];

        // Ledger sums
        $ledger_table = DS_Wallet::table();
        $q1 = $wpdb->prepare(
            "SELECT user_id,
                SUM(CASE WHEN amount>0 THEN amount ELSE 0 END) AS earned,
                SUM(CASE WHEN amount<0 THEN -amount ELSE 0 END) AS spent,
                SUM(CASE WHEN amount<0 THEN -amount ELSE amount END) AS volume
             FROM {$ledger_table}
             WHERE status IN ('posted','paid') AND created_at BETWEEN %s AND %s
             GROUP BY user_id",
            $from, $to
        );
        foreach ($wpdb->get_results($q1) as $r) {
            $uid = (int)$r->user_id; if (!isset($rows[$uid])) continue;
            $rows[$uid]['earned'] = round((float)$r->earned, 2);
            $rows[$uid]['spent']  = round((float)$r->spent, 2);
            $rows[$uid]['volume'] = round((float)$r->volume, 2);
        }

        // Products created in period
        $statuses = ["publish","private","draft","pending","future"];
        $in_status = "('".implode("','", array_map('esc_sql', $statuses))."')";
        $q2 = $wpdb->prepare(
            "SELECT post_author AS user_id, COUNT(*) AS products
             FROM {$wpdb->posts}
             WHERE post_type='product' AND post_status IN {$in_status}
               AND post_date BETWEEN %s AND %s
             GROUP BY post_author",
             $from, $to
        );
        foreach ($wpdb->get_results($q2) as $r) {
            $uid = (int)$r->user_id; if (!isset($rows[$uid])) continue;
            $rows[$uid]['products'] = (int)$r->products;
        }

        // Views per vendor
        if (class_exists('DS_Views')) {
            $vt = DS_Views::table();
            // Use DATE range; view_date is DATE, convert $from,$to to date strings
            $from_d = substr($from,0,10); $to_d = substr($to,0,10);
            $q3 = $wpdb->prepare(
                "SELECT vendor_id AS user_id, COUNT(*) AS views
                 FROM {$vt}
                 WHERE view_date BETWEEN %s AND %s
                 GROUP BY vendor_id",
                 $from_d, $to_d
            );
            foreach ($wpdb->get_results($q3) as $r) {
                $uid = (int)$r->user_id; if (!isset($rows[$uid])) continue;
                $rows[$uid]['views'] = (int)$r->views;
            }
        }

        return array_values($rows);
    }

    public static function render_filters($active_tab, $preset, $from, $to) {
        $tabs = [
            'earned' => 'Money Made',
            'spent' => 'Money Spent',
            'volume' => 'Transactions Volume',
            'products' => 'Products Created',
            'views' => 'Product Views',
        ];
        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $k=>$label) {
            $cls = $active_tab===$k ? ' nav-tab nav-tab-active' : ' nav-tab';
            $url = add_query_arg(['tab'=>$k]);
            printf('<a class="%s" href="%s">%s</a>', esc_attr($cls), esc_url($url), esc_html($label));
        }
        echo '</h2>';

        echo '<form method="get" style="margin:12px 0;">';
        foreach (['page','tab'] as $keep) {
            if (isset($_GET[$keep])) printf('<input type="hidden" name="%s" value="%s">', esc_attr($keep), esc_attr($_GET[$keep]));
        }
        $preset = in_array($preset, ['daily','weekly','monthly','custom'], true) ? $preset : 'weekly';
        echo '<label>Period: <select name="preset">';
        foreach (['daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly','custom'=>'Custom'] as $k=>$lab) {
            printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($preset,$k,false), esc_html($lab));
        }
        echo '</select></label> ';
        echo '<label>From: <input type="date" id="ds_from" name="from" value="'.esc_attr(substr($from,0,10)).'"></label> ';
        echo '<label>To: <input type="date" id="ds_to" name="to" value="'.esc_attr(substr($to,0,10)).'"></label> ';
        echo '<button class="button">Apply</button>';
        echo '</form>';
    }

    public static function render_table($rows, $active_tab, $paged = 1, $per_page = 50) {
        // Sort by active tab desc
        $key = in_array($active_tab, ['earned','spent','volume','products','views'], true) ? $active_tab : 'earned';
        usort($rows, function($a,$b) use($key){
            $va = $a[$key] ?? 0; $vb = $b[$key] ?? 0; if ($va==$vb) return 0; return ($va<$vb)?1:-1; // desc
        });
        $total = count($rows);
        $pages = max(1, (int)ceil($total / $per_page));
        $paged = max(1, min($pages, (int)$paged));
        $offset = ($paged-1)*$per_page;
        $slice = array_slice($rows, $offset, $per_page);

        echo '<table class="widefat striped"><thead><tr>'
            .'<th>#</th><th>User</th><th>Made (+)</th><th>Spent (-)</th><th>Volume</th><th>Products</th><th>Views</th>'
            .'</tr></thead><tbody>';
        $i = $offset + 1;
        foreach ($slice as $r) {
            printf('<tr>'
                .'<td>%d</td>'
                .'<td>%s</td>'
                .'<td>€%0.2f</td>'
                .'<td>€%0.2f</td>'
                .'<td>€%0.2f</td>'
                .'<td>%d</td>'
                .'<td>%d</td>'
                .'</tr>',
                $i++, esc_html($r['display_name'].' (@'.$r['username'].')'),
                (float)$r['earned'], (float)$r['spent'], (float)$r['volume'], (int)$r['products'], (int)$r['views']
            );
        }
        if (!$slice) echo '<tr><td colspan="7">No data for selected period.</td></tr>';
        echo '</tbody></table>';

        // Pagination
        if ($pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            $base = remove_query_arg('paged');
            for ($p=1; $p<=$pages; $p++) {
                $url = add_query_arg('paged', $p, $base);
                printf('<a class="%s" style="margin-right:6px;" href="%s">%s</a>', $p==$paged?'button-primary button':'button', esc_url($url), $p);
            }
            echo '</div></div>';
        }
    }
}
