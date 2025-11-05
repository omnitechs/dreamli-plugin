<?php
if (!defined('ABSPATH')) exit;

final class DS_Helpers {
    static function now() { return current_time('mysql'); }

    static function user_plan($user_id = 0) : string {
        $u = $user_id ? get_userdata($user_id) : wp_get_current_user();
        if (!$u) return '';
        $roles = (array)$u->roles;
        if (in_array('ds_vendor_curated', $roles, true)) return 'curated';
        if (in_array('ds_vendor_open', $roles, true))    return 'open';
        return '';
    }

    static function is_vendor($user_id = 0) : bool {
        $u = $user_id ? get_userdata($user_id) : wp_get_current_user();
        if (!$u) return false;
        return (bool) array_intersect((array)$u->roles, ['ds_vendor_curated','ds_vendor_open']);
    }

    static function db_table_exists($table_name) : bool {
        global $wpdb;
        $like = $wpdb->esc_like($table_name);
        return (bool) $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $like));
    }
}
