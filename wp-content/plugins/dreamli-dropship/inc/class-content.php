<?php
if (!defined('ABSPATH')) exit;

final class DS_Content {
    static function init() {
        add_filter('wp_insert_post_data', [__CLASS__,'moderate_posts'], 10, 2);
        add_filter('wp_insert_post_data', [__CLASS__,'moderate_products'], 10, 2);
        add_action('transition_post_status', [__CLASS__,'email_on_post_pending'], 10, 3);
        add_action('transition_post_status', [__CLASS__,'credit_on_product_publish'], 10, 3);
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
