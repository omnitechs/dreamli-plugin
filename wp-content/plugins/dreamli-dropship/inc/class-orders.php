<?php
if (!defined('ABSPATH')) exit;

final class DS_Orders {
    static function init() {
        add_action('woocommerce_order_status_changed', [__CLASS__,'log_platform_fee'], 10, 3);
    }

    static function log_platform_fee($order_id, $from, $to) {
        if (!function_exists('wc_get_order')) return;
        if (!in_array($to, ['processing','completed'], true)) return;

        $s = DS_Settings::get();
        $order = wc_get_order($order_id);
        if (!$order) return;

        $platform_fee_total = 0.0;

        foreach ($order->get_items('line_item') as $item_id => $item) {
            $pid = $item->get_product_id() ?: $item->get_variation_id();
            $qty = (int)$item->get_quantity();
            if (!$pid || $qty <= 0) continue;

            $author = (int) get_post_field('post_author', $pid);
            if (!$author) continue;

            $plan = DS_Helpers::user_plan($author);
            if ($plan === 'open') {
                $platform_fee_total += (float)$s['open_fee_per_item'] * $qty;

            } elseif ($plan === 'curated') {
                $count_prev = (int) get_user_meta($author, '_ds_curated_item_sales_count', true);
                $remaining_half = max(0, (int)$s['curated_first_n'] - $count_prev);

                if ($remaining_half >= $qty) {
                    $platform_fee_total += (float)$s['curated_first_fee'] * $qty;
                    $count_prev += $qty;
                } else {
                    if ($remaining_half > 0) {
                        $platform_fee_total += (float)$s['curated_first_fee'] * $remaining_half;
                    }
                    $rest = $qty - $remaining_half;
                    if ($rest > 0) $platform_fee_total += (float)$s['curated_after_fee'] * $rest;
                    $count_prev += $qty;
                }
                update_user_meta($author, '_ds_curated_item_sales_count', $count_prev);
            }
        }

        if ($platform_fee_total > 0) {
            update_post_meta($order_id, '_ds_platform_fee_total_eur', number_format($platform_fee_total, 2, '.', ''));
        }
    }

    // Helpers used by vendor reports
    static function user_product_ids($user_id) {
        $ids = get_posts([
            'post_type' => 'product',
            'author' => $user_id,
            'fields' => 'ids',
            'posts_per_page' => -1,
            'post_status' => ['publish','private','draft','pending','future'],
            'no_found_rows' => true,
        ]);
        return array_map('intval', $ids);
    }

    static function order_ids_containing_products(array $product_ids, $limit=1000) {
        global $wpdb; if (empty($product_ids)) return [];
        $in = implode(',', array_map('intval', $product_ids));

        $oi_legacy  = $wpdb->prefix . 'woocommerce_order_items';
        $oim_legacy = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $oi_hpos    = $wpdb->prefix . 'wc_order_items';
        $oim_hpos   = $wpdb->prefix . 'wc_order_itemmeta';

        if (DS_Helpers::db_table_exists($oi_hpos) && DS_Helpers::db_table_exists($oim_hpos)) { $oi=$oi_hpos; $oim=$oim_hpos; }
        else { $oi=$oi_legacy; $oim=$oim_legacy; }

        $sql = "
            SELECT DISTINCT oi.order_id
            FROM {$oi} AS oi
            INNER JOIN {$oim} AS oim ON oi.order_item_id = oim.order_item_id
            WHERE oi.order_item_type = 'line_item'
              AND oim.meta_key IN ('_product_id','_variation_id')
              AND CAST(oim.meta_value AS UNSIGNED) IN ({$in})
            ORDER BY oi.order_id DESC
            LIMIT %d
        ";
        return array_map('intval', $wpdb->get_col($wpdb->prepare($sql, intval($limit))));
    }

    static function order_contains_user_products($order, $user_product_ids) : bool {
        foreach ($order->get_items('line_item') as $item) {
            $pid = $item->get_product_id() ?: $item->get_variation_id();
            if ($pid && in_array((int)$pid, $user_product_ids, true)) return true;
        }
        return false;
    }
}
