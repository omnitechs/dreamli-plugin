<?php
if (!defined('ABSPATH')) exit;

final class DS_Roles {
    static function init() {
        add_action('init', [__CLASS__, 'register_roles']);
        add_action('pre_get_posts', [__CLASS__, 'limit_own_lists']);
        add_filter('ajax_query_attachments_args', [__CLASS__, 'limit_media']);
        add_action('admin_menu', [__CLASS__, 'clean_vendor_menus'], 999);
        add_action('admin_head', [__CLASS__, 'vendor_menu_css_fallback']);
    }

    static function register_roles() {
        add_post_type_support('product', 'author');

        if (!get_role('ds_vendor_curated')) {
            add_role('ds_vendor_curated', 'Vendor (Curated)', [
                'read' => true, 'upload_files' => true,
                'edit_products' => true, 'publish_products' => true, 'edit_published_products' => true,
                'delete_products' => true, 'delete_published_products' => true, 'read_product' => true,
                'assign_product_terms' => true,
                'edit_posts' => true, 'publish_posts' => true, 'delete_posts' => true, 'edit_published_posts' => true,
            ]);
        }
        if (!get_role('ds_vendor_open')) {
            add_role('ds_vendor_open', 'Vendor (Open)', [
                'read' => true, 'upload_files' => true,
                'edit_products' => true, 'publish_products' => true, 'edit_published_products' => true,
                'delete_products' => true, 'delete_published_products' => true, 'read_product' => true,
                'assign_product_terms' => true,
                'edit_posts' => true, 'publish_posts' => true, 'delete_posts' => true, 'edit_published_posts' => true,
            ]);
        }
        if (!get_role('ds_vendor_admin')) {
            add_role('ds_vendor_admin', 'Vendor Admin', [
                // Base
                'read' => true, 'upload_files' => true,
                // Product capabilities (own + others)
                'edit_products' => true, 'publish_products' => true, 'edit_published_products' => true,
                'delete_products' => true, 'delete_published_products' => true, 'read_product' => true,
                'assign_product_terms' => true,
                'edit_others_products' => true,
                // Post capabilities (blog) incl. others
                'edit_posts' => true, 'publish_posts' => true, 'delete_posts' => true, 'edit_published_posts' => true,
                'edit_others_posts' => true,
            ]);
        }
    }

    static function limit_own_lists($q) {
        if (!is_admin() || !$q->is_main_query()) return;
        $pt = $q->get('post_type');
        if (!in_array($pt, ['product','post'], true)) return;
        if (current_user_can('edit_others_products') || current_user_can('edit_others_posts')) return;
        $q->set('author', get_current_user_id());
    }

    static function limit_media($args) {
        if (current_user_can('edit_others_products') || current_user_can('edit_others_posts')) return $args;
        if (DS_Helpers::is_vendor()) $args['author'] = get_current_user_id();
        return $args;
    }

    static function clean_vendor_menus() {
    if (!DS_Helpers::is_vendor()) return;

    // موارد شناخته‌شده
    $known = [
        'woocommerce',
        'edit.php?post_type=shop_order',
        'edit.php?post_type=shop_coupon',
        'tools.php','plugins.php','themes.php','users.php','options-general.php',
        // Clarity احتمالی
        'clarity','msclarity','toplevel_page_clarity','microsoft-clarity','ms-clarity',
        // MegaMenu احتمالی
        'maxmegamenu','toplevel_page_maxmegamenu','edit.php?post_type=megamenu-items',
        'mega-menu','megamenu','wp-mega-menu'
    ];
    foreach ($known as $slug) { remove_menu_page($slug); }

    // حذف داینامیک بر اساس تطبیق عنوان/اسلاگ
    global $menu, $submenu;
    if (is_array($menu)) {
        $needles = ['clarity','msclarity','microsoft','mega-menu','megamenu','mega menu'];
        foreach ($menu as $idx => $m) {
            $title = strtolower( wp_strip_all_tags($m[0] ?? '') );
            $slug  = strtolower( $m[2] ?? '' );
            foreach ($needles as $n) {
                if (strpos($title,$n)!==false || strpos($slug,$n)!==false) {
                    remove_menu_page($m[2]);
                    unset($menu[$idx]);
                    break;
                }
            }
        }
    }
    if (is_array($submenu)) {
        $needles = ['clarity','msclarity','microsoft','mega-menu','megamenu'];
        foreach ($submenu as $parent_slug => &$items) {
            foreach ($items as $k => $sub) {
                $title = strtolower( wp_strip_all_tags($sub[0] ?? '') );
                $slug  = strtolower( $sub[2] ?? '' );
                foreach ($needles as $n) {
                    if (strpos($title,$n)!==false || strpos($slug,$n)!==false) {
                        remove_submenu_page($parent_slug, $sub[2]);
                        unset($items[$k]);
                        break;
                    }
                }
            }
        }
        unset($items);
    }
}

    static function vendor_menu_css_fallback() {
        if (!DS_Helpers::is_vendor()) return;
        echo '<style>
        #toplevel_page_clarity,#toplevel_page_msclarity,#toplevel_page_maxmegamenu,
        #menu-posts-megamenu-items{display:none!important;}
        </style>';
    }
}
