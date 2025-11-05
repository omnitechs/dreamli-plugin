<?php
if (!defined('ABSPATH')) exit;

final class DS_Wallet {
    static function table() {
        global $wpdb; return $wpdb->prefix . 'ds_wallet_ledger';
    }

    static function create_table() {
        global $wpdb;
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(50) NOT NULL,
            ref_id VARCHAR(64) NULL,
            amount DECIMAL(14,2) NOT NULL,
            currency CHAR(3) NOT NULL DEFAULT 'EUR',
            status VARCHAR(20) NOT NULL DEFAULT 'posted',
            meta LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY user_idx (user_id),
            KEY type_idx (type),
            KEY status_idx (status)
        ) $charset;";
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    static function add($user_id, $type, $amount, $ref_id=null, $status='posted', $meta=[]) {
        global $wpdb;
        $wpdb->insert(self::table(), [
            'user_id' => (int)$user_id,
            'type'    => sanitize_text_field($type),
            'ref_id'  => $ref_id ? sanitize_text_field((string)$ref_id) : null,
            'amount'  => (float)$amount,
            'currency'=> 'EUR',
            'status'  => sanitize_text_field($status),
            'meta'    => $meta ? wp_json_encode($meta) : null,
            'created_at' => DS_Helpers::now(),
        ]);
        return $wpdb->insert_id;
    }

    static function balance($user_id) : float {
        global $wpdb;
        $sum = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM ".self::table()." WHERE user_id=%d AND status IN ('posted','paid')",
            (int)$user_id
        ));
        return round($sum, 2);
    }

    static function init() { /* no-op */ }
}
