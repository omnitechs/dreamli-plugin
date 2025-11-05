<?php
if (!defined('ABSPATH')) exit;

final class DS_Content {
    private static function add_notice($msg){ if (!is_admin()) return; $u = get_current_user_id(); if (!$u) return; set_transient('ds_notice_'.$u, (string)$msg, 60); }
    public static function render_notices(){ if (!is_admin()) return; $u = get_current_user_id(); if (!$u) return; $msg = get_transient('ds_notice_'.$u); if ($msg){ delete_transient('ds_notice_'.$u); echo '<div class="notice notice-warning"><p>'.esc_html($msg).'</p></div>'; } }
    static function init() {
        add_filter('wp_insert_post_data', [__CLASS__,'moderate_posts'], 10, 2);
        add_filter('wp_insert_post_data', [__CLASS__,'moderate_products'], 10, 2);
        // Protections: prevent hollowing out and status demotion
        add_filter('wp_insert_post_data', [__CLASS__,'protect_product_integrity'], 9, 2);
        // Block trash/delete for vendors
        add_filter('pre_trash_post', [__CLASS__,'block_vendor_trash'], 10, 2);
        add_filter('pre_delete_post', [__CLASS__,'block_vendor_delete'], 10, 2);
        add_action('save_post_product', [__CLASS__,'ensure_required_terms'], 20, 3);
        add_action('transition_post_status', [__CLASS__,'email_on_post_pending'], 10, 3);
        add_action('transition_post_status', [__CLASS__,'credit_on_product_publish'], 10, 3);
        // Reviewer reward when Vendor Admin publishes others' products
        add_action('transition_post_status', [__CLASS__,'reward_on_review_publish'], 10, 3);
        add_action('admin_notices', [__CLASS__,'render_notices']);
        // Admin restore button on product edit screen
        add_action('post_submitbox_misc_actions', [__CLASS__,'render_restore_button']);
    }

    // Posts → Pending for vendors (if enabled)
    static function moderate_posts($data, $postarr) {
        if (!is_admin() || $data['post_type'] !== 'post') return $data;
        $s = DS_Settings::get();
        if (!$s['posts_pending']) return $data;
        if (DS_Helpers::is_vendor() && $data['post_status'] === 'publish') {
            $data['post_status'] = 'pending';
        }
        return $data;
    }

    // Products → Pending for vendors (if enabled)
    static function moderate_products($data, $postarr) {
        if (!is_admin() || $data['post_type'] !== 'product') return $data;
        $s = DS_Settings::get();
        if (!$s['products_pending']) return $data;
        if (DS_Helpers::is_vendor() && $data['post_status'] === 'publish') {
            $data['post_status'] = 'pending';
        }
        return $data;
    }

    // Protect product completeness and prevent status demotion/hollowing by vendors
    static function protect_product_integrity($data, $postarr) {
        if (!is_admin() || ($data['post_type'] ?? '') !== 'product') return $data;
        if (!DS_Helpers::is_vendor()) return $data;
        $s = DS_Settings::get();
        $post_id = isset($postarr['ID']) ? (int)$postarr['ID'] : 0;
        $is_update = $post_id > 0;
        $orig = $is_update ? get_post($post_id) : null;

        // Block status demotion for vendors (publish -> draft/private)
        if (!empty($s['protect_status_demotion']) && $is_update && $orig && $orig->post_status === 'publish') {
            if (in_array($data['post_status'], ['draft','private','trash','auto-draft'], true)) {
                // Keep original status
                $data['post_status'] = $orig->post_status;
                self::add_notice(__('You cannot change the status of a published product. Contact support for unpublishing.', 'dreamli-dropship'));
            }
        }

        // Enforce minimal completeness on updates (vendors cannot hollow out)
        if ($is_update) {
            $min_title = max(0, (int)($s['min_title_len'] ?? 0));
            $min_content = max(0, (int)($s['min_content_len'] ?? 0));
            // Title
            $new_title = isset($data['post_title']) ? trim(wp_strip_all_tags($data['post_title'])) : '';
            if ($min_title > 0 && strlen($new_title) < $min_title && $orig) {
                $data['post_title'] = $orig->post_title;
                self::add_notice(sprintf(__('Title must be at least %d characters. Your change was not saved.', 'dreamli-dropship'), $min_title));
            }
            // Content
            $new_content_txt = isset($data['post_content']) ? trim(wp_strip_all_tags($data['post_content'])) : '';
            if ($min_content > 0 && strlen($new_content_txt) < $min_content && $orig) {
                $data['post_content'] = $orig->post_content;
                self::add_notice(sprintf(__('Content must be at least %d characters. Your change was not saved.', 'dreamli-dropship'), $min_content));
            }
            // Excerpt: allow empty, do not enforce
        }
        return $data;
    }

    static function block_vendor_trash($trash, $post) {
        if (!is_admin() || !$post || $post->post_type !== 'product') return $trash;
        if (!DS_Helpers::is_vendor()) return $trash;
        self::add_notice(__('Deleting products is not allowed. The product was not trashed.', 'dreamli-dropship'));
        return false; // prevent trash
    }

    static function block_vendor_delete($delete, $post) {
        if (!is_admin() || !$post || $post->post_type !== 'product') return $delete;
        if (!DS_Helpers::is_vendor()) return $delete;
        self::add_notice(__('Deleting products is not allowed. The product was not deleted.', 'dreamli-dropship'));
        return false; // prevent delete
    }

    static function ensure_required_terms($post_id, $post, $update) {
        if (!is_admin() || $post->post_type !== 'product') return;
        if (!DS_Helpers::is_vendor()) return;
        $s = DS_Settings::get();
        // Featured image requirement: if missing, try to restore from last snapshot
        if (!empty($s['require_featured_image']) && !has_post_thumbnail($post_id)) {
            if (class_exists('DS_Snapshots') && method_exists('DS_Snapshots','latest_snapshot')) {
                $snap = DS_Snapshots::latest_snapshot($post_id);
                $thumb = (int)($snap['media']['thumbnail_id'] ?? 0);
                if ($thumb > 0) {
                    set_post_thumbnail($post_id, $thumb);
                    self::add_notice(__('Featured image is required. We restored the previous image.', 'dreamli-dropship'));
                } else {
                    self::add_notice(__('Featured image is required. Please set one.', 'dreamli-dropship'));
                }
            } else {
                self::add_notice(__('Featured image is required. Please set one.', 'dreamli-dropship'));
            }
        }
        // Require at least one category
        if (!empty($s['require_product_category'])) {
            $terms = wp_get_post_terms($post_id, 'product_cat', ['fields'=>'ids']);
            if (empty($terms)) {
                $default = (int) get_option('default_product_cat'); // WooCommerce option, may not exist
                if ($default > 0) {
                    wp_set_post_terms($post_id, [$default], 'product_cat');
                    self::add_notice(__('At least one category is required. We assigned a default category.', 'dreamli-dropship'));
                } else {
                    self::add_notice(__('At least one category is required. Please assign a category.', 'dreamli-dropship'));
                }
            }
        }
    }

    public static function render_restore_button($post) {
        if (!current_user_can('manage_options')) return;
        if (!$post || $post->post_type !== 'product') return;
        if (!class_exists('DS_Snapshots') || !method_exists('DS_Snapshots','latest_snapshot')) return;
        $snap = DS_Snapshots::latest_snapshot((int)$post->ID);
        if (!$snap) return;
        $url = wp_nonce_url(add_query_arg(['action'=>'ds_restore_last_snapshot','product_id'=>(int)$post->ID], admin_url('admin-post.php')), 'ds_restore_last_'.(int)$post->ID);
        echo '<div class="misc-pub-section">';
        echo '<a href="'.esc_url($url).'" class="button">Restore last DS snapshot</a>';
        echo '</div>';
    }

    // Email admin when a post becomes pending
    static function email_on_post_pending($new, $old, $post) {
        if ($post->post_type !== 'post') return;
        if ($new === 'pending' && $old !== 'pending') {
            $to   = get_option('admin_email');
            $subj = 'Pending post for review';
            $url  = admin_url('post.php?post='.$post->ID.'&action=edit');
            $msg  = "A vendor submitted a post for review:\n\nTitle: {$post->post_title}\nAuthor: " . get_the_author_meta('user_login', $post->post_author) . "\nReview: {$url}";
            wp_mail($to, $subj, $msg);
        }
    }

    // Curated €2 credit on first publish (after admin approves)
    static function credit_on_product_publish($new, $old, $post) {
        if ($post->post_type !== 'product') return;
        if ($new !== 'publish' || $old === 'publish') return;

        $s = DS_Settings::get();
        if (!$s['curated_credit_enable']) return;

        $author = (int)$post->post_author;
        if (DS_Helpers::user_plan($author) !== 'curated') return;

        // Quality checks (optional)
        if ($s['credit_min_images'] > 0 && self::product_images_count($post->ID) < (int)$s['credit_min_images']) return;
        if ($s['credit_min_words']  > 0 && self::product_word_count($post->ID)   < (int)$s['credit_min_words']) return;
        if ($s['credit_min_rankmath'] > 0 && self::product_rankmath_score($post->ID) < (int)$s['credit_min_rankmath']) return;

        if (get_post_meta($post->ID, '_ds_curated_credit_done', true)) return;

        DS_Wallet::add($author, 'credit_product', (float)$s['curated_credit_amount'], 'product#'.$post->ID, 'posted', ['reason'=>'curated_publish']);
        update_post_meta($post->ID, '_ds_curated_credit_done', 1);
    }

    static function reward_on_review_publish($new, $old, $post) {
        if ($post->post_type !== 'product') return;
        if ($new !== 'publish' || $old === 'publish') return;
        $reviewer = get_current_user_id(); if ($reviewer <= 0) return;
        if (!DS_Helpers::is_vendor_admin($reviewer)) return; // only Vendor Admins earn this reward
        $author = (int)$post->post_author; if ($author === $reviewer) return; // publishing own product – no reward
        $s = DS_Settings::get(); $amount = (float)($s['vendor_admin_publish_reward_eur'] ?? 0);
        if ($amount <= 0) return;
        global $wpdb; $w = DS_Wallet::table();
        $ref = 'review_reward:'.$post->ID.':'.$reviewer;
        $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$w} WHERE ref_id=%s", $ref));
        if ($exists === 0) {
            DS_Wallet::add($reviewer, 'review_publish_reward', $amount, $ref, 'posted', ['product_id'=>$post->ID,'author'=>$author]);
        }
    }

    static function product_images_count($post_id) {
        $c = 0;
        if (has_post_thumbnail($post_id)) $c++;
        $gallery = get_post_meta($post_id, '_product_image_gallery', true);
        if (!empty($gallery)) $c += count(array_filter(array_map('intval', explode(',', $gallery))));
        return $c;
    }
    static function product_word_count($post_id) {
        $content = wp_strip_all_tags(get_post_field('post_content', $post_id));
        $content = trim(preg_replace('/\s+/', ' ', $content));
        return $content ? count(preg_split('/\s+/', $content)) : 0;
    }
    static function product_rankmath_score($post_id) {
        $score = (int) get_post_meta($post_id, 'rank_math_seo_score', true);
        if (!$score && function_exists('rank_math_get_post_score')) $score = (int) rank_math_get_post_score($post_id);
        return $score;
    }
}
