<?php
/**
 * Helper functions and cache/query utilities.
 *
 * @package SimpleBoards_Roadmap
 */
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Object cache and transient helpers for board items and vote counts.
 */
class SBIR_Cache_Helper {
    
    /**
     * Cache group for object cache
     */
    const CACHE_GROUP = 'sbir_plugin';
    
    
    /**
     * Get cached user identifier to avoid repeated calls
     */
    private static $user_identifier_cache = null;

    /**
     * Get cache scope for board item collections.
     *
     * @return string
     */
    private static function get_board_items_cache_scope() {
        return current_user_can('edit_posts') ? 'editor' : 'public';
    }
    
    public static function get_cached_user_identifier() {
        if (self::$user_identifier_cache === null) {
            self::$user_identifier_cache = sbir_get_user_identifier();
        }
        return self::$user_identifier_cache;
    }
    
    /**
     * Clear vote related caches (including top-voted / recent-discussed widgets).
     */
    public static function clear_vote_cache($item_id, $user_identifier = null) {
        wp_cache_delete('vote_count_' . $item_id, self::CACHE_GROUP);

        if ($user_identifier) {
            wp_cache_delete('user_vote_' . $item_id . '_' . md5($user_identifier), self::CACHE_GROUP);
        }

        // Clear board items and widget object caches.
        $board_id = get_post_meta($item_id, '_sbir_board_id', true);
        if ($board_id) {
            self::clear_board_caches_for($board_id);
        }
    }

    /**
     * Clear every cache entry scoped to a given board.
     *
     * Centralizes invalidation so new cached keys only need to be added here.
     *
     * @param int $board_id Board ID.
     * @return void
     */
    public static function clear_board_caches_for($board_id) {
        $board_id = (int) $board_id;
        if ($board_id <= 0) {
            return;
        }

        // Board items caches (scoped + legacy unscoped keys).
        wp_cache_delete('board_items_' . $board_id, self::CACHE_GROUP);
        wp_cache_delete('board_items_' . $board_id . '_roadmap', self::CACHE_GROUP);
        wp_cache_delete('board_items_' . $board_id . '_ideas', self::CACHE_GROUP);
        foreach (array('public', 'editor') as $scope) {
            wp_cache_delete('board_items_' . $board_id . '_all_' . $scope, self::CACHE_GROUP);
            wp_cache_delete('board_items_' . $board_id . '_roadmap_' . $scope, self::CACHE_GROUP);
            wp_cache_delete('board_items_' . $board_id . '_ideas_' . $scope, self::CACHE_GROUP);
        }

        // Widget caches — use the known limits referenced by sidebar widgets.
        foreach (array(3, 5, 10) as $limit) {
            wp_cache_delete('sbir_top_voted_ideas_' . $board_id . '_' . $limit, self::CACHE_GROUP);
            wp_cache_delete('sbir_recent_discussed_ideas_' . $board_id . '_' . $limit, self::CACHE_GROUP);
        }
    }
    
    /**
     * Get cached board items with better performance
     */
    public static function get_cached_board_items($board_id, $type = 'all', $cache_time = 1800) {
        $cache_scope = self::get_board_items_cache_scope();
        $cache_key = 'board_items_' . $board_id . '_' . $type . '_' . $cache_scope;
        
        $items = wp_cache_get($cache_key, self::CACHE_GROUP);
        if ($items === false) {
            $items = sbir_get_board_items($board_id, $type);
            wp_cache_set($cache_key, $items, self::CACHE_GROUP, $cache_time);
        }
        return $items;
    }
    
    /**
     * Clear all plugin caches
     */
    public static function clear_all_cache() {
        // wp_cache_flush_group() is not a core WordPress function
        // Use targeted cache clearing instead
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group(self::CACHE_GROUP);
        } else {
            // Fallback when grouped flushing is unavailable.
            wp_cache_flush();
        }
        
        // Clear transients
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_sbir_%' OR option_name LIKE '_transient_timeout_sbir_%'");
    }
    
    /**
     * Clear cache when items are modified.
     */
    public static function clear_item_cache($post_id) {
        if (get_post_type($post_id) === 'sbir_item') {
            $board_id = get_post_meta($post_id, '_sbir_board_id', true);
            if ($board_id) {
                self::clear_board_caches_for($board_id);
            }
        }
    }
    
    /**
     * Clear status color styles transient when status terms are modified.
     */
    public static function clear_styles_cache() {
        delete_transient('sbir_status_styles_' . get_current_blog_id());
    }
}

/**
 * Reusable meta query fragments for roadmap/ideas filtering.
 */
class SBIR_Query_Helper {
    
    /**
     * Get valid post statuses for sbir_item queries.
     *
     * @return string[]
     */
    public static function get_valid_post_statuses() {
        return array('publish', 'pending', 'draft', 'private');
    }
    
    /**
     * Get meta query for ideas (non-roadmap) items.
     *
     * @return array
     */
    public static function get_ideas_meta_query() {
        return array(
            'relation' => 'OR',
            array('key' => '_sbir_is_roadmap', 'value' => 'yes', 'compare' => '!='),
            array('key' => '_sbir_is_roadmap', 'compare' => 'NOT EXISTS')
        );
    }
    
    /**
     * Get meta query for roadmap items.
     *
     * @return array
     */
    public static function get_roadmap_meta_query() {
        return array('key' => '_sbir_is_roadmap', 'value' => 'yes', 'compare' => '=');
    }
}

/**
 * Get the permalink base slug for board URLs.
 *
 * @return string
 */
function sbir_get_permalink_base() {
    return get_option('sbir_permalink_base', 'products');
}

/**
 * Get board items with pagination and type filtering.
 *
 * @param int    $board_id Board post ID.
 * @param string $type     'roadmap', 'ideas', or 'all'.
 * @param int    $per_page Posts per page.
 * @param int    $paged    Page number.
 * @return WP_Query
 */
function sbir_get_board_items($board_id, $type = 'all', $per_page = 100, $paged = 1) {
    $allowed_statuses = current_user_can('edit_posts')
        ? array('publish', 'pending')
        : array('publish');

    $args = array(
        'post_type' => 'sbir_item',
        'posts_per_page' => $per_page,
        'paged' => max(1, (int) $paged),
        'meta_key' => '_sbir_board_id',
        'meta_value' => $board_id,
        'post_status' => apply_filters('sbir_board_items_post_status', $allowed_statuses, $board_id, $type),
        'orderby' => 'menu_order date',
        'order' => 'ASC',
        'no_found_rows' => true
    );
    
    if ($type === 'roadmap') {
        $args['meta_query'] = array(
            array(
                'key' => '_sbir_is_roadmap',
                'value' => 'yes',
                'compare' => '='
            )
        );
    } elseif ($type === 'ideas') {
        // Use optimized ideas query
        $args['meta_query'] = SBIR_Query_Helper::get_ideas_meta_query();
    }
    
    return new WP_Query(apply_filters('sbir_board_items_query', $args, $board_id, $type));
}

/**
 * Get vote count for an item from custom vote_counts table.
 *
 * @param int $item_id Item post ID.
 * @return int
 */
function sbir_get_vote_count($item_id) {
    $count = wp_cache_get('vote_count_' . $item_id, SBIR_Cache_Helper::CACHE_GROUP);
    if ($count === false) {
        global $wpdb;
        $counts_table = $wpdb->prefix . 'sbir_vote_counts';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count = (int) $wpdb->get_var($wpdb->prepare("SELECT vote_count FROM {$counts_table} WHERE item_id = %d", $item_id));
        wp_cache_set('vote_count_' . $item_id, $count, SBIR_Cache_Helper::CACHE_GROUP, 3600);
    }
    
    return $count;
}

/**
 * Build shared inline SVG markup used across plugin UI.
 *
 * @param string $icon  Icon slug.
 * @param array  $attrs Optional SVG attributes.
 * @return string
 */
function sbir_get_svg_icon($icon, $attrs = array()) {
    $icon = sanitize_key((string) $icon);
    $paths = array(
        'vote_up' => '<path d="m18 15-6-6-6 6"/>',
        'comments' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'calendar' => '<rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/>',
        'user' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'check_square' => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'check_circle' => '<circle cx="12" cy="12" r="10"/><path d="M9 12l2 2 4-4"/>',
        'circle' => '<circle cx="12" cy="12" r="10"/>',
        'pin' => '<path d="M12 17v5"/><path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z"/>',
        'x' => '<path d="M18 6L6 18"/><path d="M6 6l12 12"/>',
    );
    if (!isset($paths[$icon])) {
        return '';
    }

    $defaults = array(
        'viewBox' => '0 0 24 24',
        'fill' => 'none',
        'stroke' => 'currentColor',
        'stroke-width' => '2',
        'aria-hidden' => 'true',
    );
    $attrs = is_array($attrs) ? array_merge($defaults, $attrs) : $defaults;
    $allowed_attr_keys = array('class', 'width', 'height', 'viewBox', 'fill', 'stroke', 'stroke-width', 'aria-hidden', 'focusable');
    $attr_string = '';
    foreach ($allowed_attr_keys as $attr_key) {
        if (!isset($attrs[$attr_key]) || $attrs[$attr_key] === '') {
            continue;
        }
        $attr_string .= ' ' . $attr_key . '="' . esc_attr((string) $attrs[$attr_key]) . '"';
    }

    return '<svg' . $attr_string . '>' . $paths[$icon] . '</svg>';
}

/**
 * Output the vote button markup for an item.
 *
 * @param int $item_id Item post ID.
 */
function sbir_render_vote_button($item_id) {
    $has_voted = sbir_user_has_voted($item_id);
    $classes = 'sbir-vote-btn' . ($has_voted ? ' voted' : '');
    $count = (int) sbir_get_vote_count($item_id);
    echo '<button type="button" class="' . esc_attr($classes) . '" data-item-id="' . esc_attr($item_id) . '">';
    echo sbir_get_svg_icon('vote_up', array('class' => 'sbir-vote-icon')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo '<span class="sbir-vote-count sbir-vote-count-num">' . esc_html($count) . '</span>';
    echo '</button>';
}

/**
 * Check whether the current user has voted for an item.
 *
 * @param int         $item_id          Item post ID.
 * @param string|null $user_identifier  Optional. Override user identifier.
 * @return bool
 */
function sbir_user_has_voted($item_id, $user_identifier = null) {
    if (!$user_identifier) {
        $user_identifier = SBIR_Cache_Helper::get_cached_user_identifier();
    }
    
    $cache_key = 'user_vote_' . $item_id . '_' . md5($user_identifier);
    $has_voted = wp_cache_get($cache_key, SBIR_Cache_Helper::CACHE_GROUP);
    
    if ($has_voted === false) {
        global $wpdb;
        $votes_table = $wpdb->prefix . 'sbir_votes';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $has_voted = (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$votes_table} WHERE item_id = %d AND user_identifier = %s", $item_id, $user_identifier));
        wp_cache_set($cache_key, $has_voted, SBIR_Cache_Helper::CACHE_GROUP, 3600);
    }
    
    return (bool) $has_voted;
}

/**
 * Get a stable user identifier for vote tracking (logged-in user or cookie).
 *
 * @return string
 */
function sbir_get_user_identifier() {
    if (is_user_logged_in()) {
        return 'user_' . get_current_user_id();
    }
    // Prefer a stable first-party cookie for anonymous users
    $cookie_key = 'sbir_uid';
    $uid = '';
    if (isset($_COOKIE[$cookie_key])) {
        $uid = sanitize_text_field(wp_unslash($_COOKIE[$cookie_key]));
    }
    if ($uid === '' || strlen($uid) > 64) {
        if (function_exists('wp_generate_uuid4')) {
            $uid = wp_generate_uuid4();
        } else {
            $uid = wp_generate_password(20, false, false);
        }
        // Best-effort cookie set; okay if headers already sent for non-AJAX
        $expires = time() + YEAR_IN_SECONDS;
        $path = defined('COOKIEPATH') ? COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
        $secure = is_ssl();
        // Avoid HttpOnly so client JS can read if ever needed (not required now)
        @setcookie($cookie_key, $uid, $expires, $path, $domain, $secure, false);
        // Mirror for admin-ajax path if different
        if (defined('SITECOOKIEPATH') && SITECOOKIEPATH !== $path) {
            @setcookie($cookie_key, $uid, $expires, SITECOOKIEPATH, $domain, $secure, false);
        }
    }
    return 'cookie_' . $uid;
}

/**
 * Get boards as HTML option elements for a dropdown.
 *
 * @param string $selected Selected board ID.
 * @return string HTML options markup.
 */
function sbir_get_boards_dropdown($selected = '') {
    $cache_key = 'sbir_boards_dropdown';
    $boards = wp_cache_get($cache_key, SBIR_Cache_Helper::CACHE_GROUP);
    if ($boards === false) {
        $query_args = array(
            'post_type' => 'sbir_board',
            'posts_per_page' => 100, // Reasonable limit instead of -1
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC'
        );
        $query_args = apply_filters('sbir_boards_dropdown_query_args', $query_args, $selected);
        $boards = get_posts($query_args);
        wp_cache_set($cache_key, $boards, SBIR_Cache_Helper::CACHE_GROUP, 300);
    }
    
    $options = '<option value="">' . __('Select Board', 'simpleboards-roadmap') . '</option>';
    foreach ($boards as $board) {
        $options .= sprintf(
            '<option value="%d" %s>%s</option>',
            $board->ID,
            selected($selected, $board->ID, false),
            esc_html($board->post_title)
        );
    }
    
    return $options;
}

/**
 * Get list of published boards for selectors.
 *
 * @param int $limit Max number of boards.
 * @return WP_Post[]
 */
function sbir_get_boards_list($limit = 100) {
    $cache_key = 'sbir_boards_list_' . (int)$limit;
    $boards = wp_cache_get($cache_key, SBIR_Cache_Helper::CACHE_GROUP);
    if ($boards === false) {
        $query_args = array(
            'post_type' => 'sbir_board',
            'posts_per_page' => $limit,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC'
        );
        $query_args = apply_filters('sbir_boards_list_query_args', $query_args, $limit);
        $boards = get_posts($query_args);
        wp_cache_set($cache_key, $boards, SBIR_Cache_Helper::CACHE_GROUP, 300);
    }
    return apply_filters('sbir_boards_list', $boards, $limit);
}

/**
 * Check whether current visitor can access a board.
 *
 * @param int    $board_id Board post ID.
 * @param string $context  Access context (view/ajax/shortcode/etc).
 * @return bool
 */
function sbir_current_user_can_access_board($board_id, $context = 'view') {
    $board_id = (int) $board_id;
    if ($board_id <= 0) {
        return false;
    }

    return (bool) apply_filters('sbir_can_access_board', true, $board_id, (string) $context);
}

/**
 * Get available default-tab choices for a board.
 *
 * Core provides Roadmap and Ideas. Add-ons (e.g., Pro Announcements) can extend
 * this list via the `sbir_default_tab_choices` filter.
 *
 * @param int $board_id Board ID.
 * @return array<string,string> Map of tab_key => label.
 */
function sbir_get_default_tab_choices($board_id = 0) {
    $choices = array(
        'roadmap' => __('Roadmap', 'simpleboards-roadmap'),
        'ideas' => __('Ideas', 'simpleboards-roadmap'),
    );

    return (array) apply_filters('sbir_default_tab_choices', $choices, (int) $board_id);
}

/**
 * Check whether current visitor can access an item.
 *
 * @param int    $item_id  Item post ID.
 * @param string $context  Access context (view/ajax/vote/etc).
 * @return bool
 */
function sbir_current_user_can_access_item($item_id, $context = 'view') {
    $item_id = (int) $item_id;
    if ($item_id <= 0) {
        return false;
    }

    $item = get_post($item_id);
    if (!$item || $item->post_type !== 'sbir_item') {
        return false;
    }

    $board_id = (int) get_post_meta($item_id, '_sbir_board_id', true);
    $can_access = true;
    if ($board_id > 0) {
        $can_access = sbir_current_user_can_access_board($board_id, 'item_' . (string) $context);
    }

    return (bool) apply_filters('sbir_can_access_item', $can_access, $item_id, (string) $context, $board_id);
}

/**
 * Replace placeholder tags in email templates.
 *
 * @param string $template Template text containing {placeholders}.
 * @param array  $data     Placeholder values.
 * @return string
 */
function sbir_render_email_template($template, $data = array()) {
    $template = is_string($template) ? $template : '';
    if ($template === '') {
        return '';
    }

    $replacements = array();
    foreach ((array) $data as $key => $value) {
        $tag = '{' . sanitize_key((string) $key) . '}';
        $replacements[$tag] = is_scalar($value) ? (string) $value : '';
    }

    return strtr($template, $replacements);
}

/**
 * Get default workflow automation email templates.
 *
 * @return array<string,array{subject:string,body:string}>
 */
function sbir_get_workflow_email_template_defaults() {
    return array(
        'pro_vote_promoted' => array(
            'subject' => __('[{site_name}] Idea moved to roadmap by workflow rule', 'simpleboards-roadmap'),
            'body' => __("A workflow rule moved an idea to roadmap.\n\nItem: {item_title}\nBoard: {board_title}\nVotes: {votes}\nThreshold: {threshold}\n\nReview item: {item_link}", 'simpleboards-roadmap'),
        ),
        'pro_status_change' => array(
            'subject' => __('[{site_name}] Workflow status rule executed', 'simpleboards-roadmap'),
            'body' => __("A workflow status rule ran.\n\nItem: {item_title}\nBoard: {board_title}\nFrom: {from_status}\nTo: {to_status}\nAssigned category: {category}\n\nReview item: {item_link}", 'simpleboards-roadmap'),
        ),
        'pro_overdue' => array(
            'subject' => __('[{site_name}] Workflow overdue rule executed', 'simpleboards-roadmap'),
            'body' => __("A workflow overdue rule ran.\n\nItem: {item_title}\nBoard: {board_title}\nDays overdue: {days_overdue}\nRule threshold: {required_days}\nSet status: {status_name}\n\nReview item: {item_link}", 'simpleboards-roadmap'),
        ),
    );
}

/**
 * Get the notification config map.
 *
 * Each entry describes:
 * - option_toggle:    option key for the on/off setting
 * - default_toggle:   default for that setting if missing
 * - subject_option:   option key for editable subject template
 * - body_option:      option key for editable body template
 * - default_subject:  default subject text
 * - default_body:     default body text
 * - audience:         'admin' | 'submitter' | 'subscribers' | 'comment_parent' (used for default recipient resolution)
 *
 * @return array
 */
function sbir_get_notification_config() {
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $config = array(
        'new_submission' => array(
            'option_toggle' => 'sbir_email_new_submission',
            'default_toggle' => 'yes',
            'subject_option' => 'sbir_email_template_new_submission_subject',
            'body_option' => 'sbir_email_template_new_submission_body',
            'default_subject' => __('[{site_name}] New idea submitted', 'simpleboards-roadmap'),
            'default_body' => __("A new idea has been submitted.\n\nTitle: {title}\nDescription: {description}\nSubmitted by: {name} ({email})\n\nView all ideas: {admin_ideas_url}", 'simpleboards-roadmap'),
            'audience' => 'admin',
        ),
        'item_rejected' => array(
            'option_toggle' => 'sbir_email_rejected',
            'default_toggle' => 'yes',
            'subject_option' => 'sbir_email_template_item_rejected_subject',
            'body_option' => 'sbir_email_template_item_rejected_body',
            'default_subject' => __('[{site_name}] Item rejected', 'simpleboards-roadmap'),
            'default_body' => __("An item has been rejected.\n\nItem: {title}\n\nReview item: {item_link}", 'simpleboards-roadmap'),
            'audience' => 'admin',
        ),
        'admin_new_comment' => array(
            'option_toggle' => 'sbir_email_admin_new_comment',
            'default_toggle' => 'yes',
            'subject_option' => 'sbir_email_template_admin_new_comment_subject',
            'body_option' => 'sbir_email_template_admin_new_comment_body',
            'default_subject' => __('[{site_name}] New comment on {title}', 'simpleboards-roadmap'),
            'default_body' => __("{commenter_name} commented on \"{title}\".\n\nComment:\n{comment_excerpt}\n\nView item: {item_link}", 'simpleboards-roadmap'),
            'audience' => 'admin',
        ),
        'idea_published' => array(
            'option_toggle' => 'sbir_email_idea_published',
            'default_toggle' => 'yes',
            'subject_option' => 'sbir_email_template_idea_published_subject',
            'body_option' => 'sbir_email_template_idea_published_body',
            'default_subject' => __('[{site_name}] Your idea is now published', 'simpleboards-roadmap'),
            'default_body' => __("Hi {name},\n\nYour idea \"{title}\" is now live on {board_title}.\n\nView it: {item_link}", 'simpleboards-roadmap'),
            'audience' => 'submitter',
        ),
        'idea_rejected_user' => array(
            'option_toggle' => 'sbir_email_idea_rejected_user',
            'default_toggle' => 'yes',
            'subject_option' => 'sbir_email_template_idea_rejected_user_subject',
            'body_option' => 'sbir_email_template_idea_rejected_user_body',
            'default_subject' => __('[{site_name}] Your idea was not approved', 'simpleboards-roadmap'),
            'default_body' => __("Hi {name},\n\nYour idea \"{title}\" was not approved this time. Thanks for sharing it.", 'simpleboards-roadmap'),
            'audience' => 'submitter',
        ),
        'idea_promoted' => array(
            'option_toggle' => 'sbir_email_idea_promoted',
            'default_toggle' => 'yes',
            'subject_option' => 'sbir_email_template_idea_promoted_subject',
            'body_option' => 'sbir_email_template_idea_promoted_body',
            'default_subject' => __('[{site_name}] Your idea is on the roadmap', 'simpleboards-roadmap'),
            'default_body' => __("Hi {name},\n\nYour idea \"{title}\" has been moved to the roadmap on {board_title}.\n\nFollow it: {item_link}", 'simpleboards-roadmap'),
            'audience' => 'submitter',
        ),
        'submitter_status_changed' => array(
            'option_toggle' => 'sbir_email_submitter_status_changed',
            'default_toggle' => 'yes',
            'subject_option' => 'sbir_email_template_submitter_status_changed_subject',
            'body_option' => 'sbir_email_template_submitter_status_changed_body',
            'default_subject' => __('[{site_name}] Roadmap update: {title}', 'simpleboards-roadmap'),
            'default_body' => __("Hi {name},\n\nYour roadmap item \"{title}\" changed status.\n\nFrom: {from_status}\nTo: {to_status}\n\nView item: {item_link}", 'simpleboards-roadmap'),
            'audience' => 'submitter',
        ),
        'item_status_changed' => array(
            'option_toggle' => 'sbir_email_item_status_changed',
            'default_toggle' => 'yes',
            'subject_option' => 'sbir_email_template_item_status_changed_subject',
            'body_option' => 'sbir_email_template_item_status_changed_body',
            'default_subject' => __('[{site_name}] Status updated: {title}', 'simpleboards-roadmap'),
            'default_body' => __("Status changed for \"{title}\".\n\nFrom: {from_status}\nTo: {to_status}\n\nView item: {item_link}", 'simpleboards-roadmap'),
            'audience' => 'subscribers',
        ),
        'new_comment' => array(
            'option_toggle' => 'sbir_email_new_comment',
            'default_toggle' => 'yes',
            'subject_option' => 'sbir_email_template_new_comment_subject',
            'body_option' => 'sbir_email_template_new_comment_body',
            'default_subject' => __('[{site_name}] New comment on {title}', 'simpleboards-roadmap'),
            'default_body' => __("{commenter_name} commented on \"{title}\":\n\n{comment_excerpt}\n\nView discussion: {item_link}", 'simpleboards-roadmap'),
            'audience' => 'subscribers',
        ),
        'comment_reply' => array(
            'option_toggle' => 'sbir_email_comment_reply',
            'default_toggle' => 'yes',
            'subject_option' => 'sbir_email_template_comment_reply_subject',
            'body_option' => 'sbir_email_template_comment_reply_body',
            'default_subject' => __('[{site_name}] Reply on {title}', 'simpleboards-roadmap'),
            'default_body' => __("{commenter_name} replied to your comment on \"{title}\":\n\n{comment_excerpt}\n\nView reply: {item_link}", 'simpleboards-roadmap'),
            'audience' => 'comment_parent',
        ),
    );

    return apply_filters('sbir_notification_config', $config);
}

/**
 * Send email notification for plugin events.
 *
 * @param string $type Notification type (see sbir_get_notification_config()).
 * @param array  $data Notification data (title, description, link, recipients, etc.).
 *                     Optional 'recipients' => string|string[] overrides default routing.
 *                     Optional 'unsubscribe_token_email' => string to append unsubscribe footer.
 * @return void
 */
function sbir_send_notification($type, $data = array()) {
    $config = sbir_get_notification_config();
    if (!isset($config[$type])) {
        return;
    }

    $entry = $config[$type];

    if (get_option($entry['option_toggle'], $entry['default_toggle']) !== 'yes') {
        return;
    }

    $admin_email = get_option('sbir_notification_email', get_option('admin_email'));
    $recipients = isset($data['recipients']) ? (array) $data['recipients'] : array();

    if (empty($recipients)) {
        switch ($entry['audience']) {
            case 'admin':
                $recipients = array($admin_email);
                break;
            case 'submitter':
                if (!empty($data['email'])) {
                    $recipients = array($data['email']);
                }
                break;
            case 'subscribers':
                $recipients = isset($data['recipients_explicit']) ? (array) $data['recipients_explicit'] : array();
                break;
            case 'comment_parent':
                if (!empty($data['email'])) {
                    $recipients = array($data['email']);
                }
                break;
        }
    }

    $recipients = array_values(array_unique(array_filter(array_map('sanitize_email', $recipients))));
    if (empty($recipients)) {
        do_action('sbir_after_notification', $type, $data);
        return;
    }

    $tags = array_merge(
        array(
            'site_name' => get_bloginfo('name'),
            'admin_ideas_url' => admin_url('edit.php?post_type=sbir_item&sbir_type=ideas'),
        ),
        is_array($data) ? $data : array()
    );

    $subject_template = get_option($entry['subject_option'], $entry['default_subject']);
    $body_template = get_option($entry['body_option'], $entry['default_body']);

    $subject = sbir_render_email_template($subject_template, $tags);
    $message = sbir_render_email_template($body_template, $tags);

    if ($subject === '' || $message === '') {
        do_action('sbir_after_notification', $type, $data);
        return;
    }

    foreach ($recipients as $recipient) {
        $body = $message;
        if (!empty($data['append_unsubscribe_for']) && $data['append_unsubscribe_for'] === $recipient && !empty($data['item_id']) && class_exists('SBIR_Subscriptions')) {
            $unsub_url = SBIR_Subscriptions::unsubscribe_url((int) $data['item_id'], $recipient);
            /* translators: %s: unsubscribe URL */
            $body .= "\n\n" . sprintf(__('Unsubscribe from this item: %s', 'simpleboards-roadmap'), $unsub_url);
        }
        wp_mail($recipient, $subject, $body);
    }

    do_action('sbir_after_notification', $type, $data);
}

/**
 * Convenience: send a per-item notification to all current subscribers.
 *
 * Splits per-recipient so each guest email gets a unique unsubscribe footer.
 *
 * @param string $type            Notification type.
 * @param int    $item_id         Item ID.
 * @param array  $base_tags       Base placeholder tags.
 * @param int    $exclude_user_id User ID to exclude (e.g., the actor).
 * @return void
 */
function sbir_send_item_subscriber_notification($type, $item_id, $base_tags = array(), $exclude_user_id = 0) {
    if (!class_exists('SBIR_Subscriptions')) {
        return;
    }
    $emails = SBIR_Subscriptions::get_subscriber_emails_for_send((int) $item_id, array((int) $exclude_user_id));
    if (empty($emails)) {
        return;
    }

    foreach ($emails as $email => $name) {
        $tags = $base_tags;
        if (!isset($tags['name']) || $tags['name'] === '') {
            $tags['name'] = $name !== '' ? $name : $email;
        }
        sbir_send_notification(
            $type,
            array_merge(
                $tags,
                array(
                    'item_id' => (int) $item_id,
                    'recipients' => array($email),
                    'append_unsubscribe_for' => $email,
                )
            )
        );
    }
}

/**
 * Get default color for a status slug.
 *
 * @param string $status_slug Status term slug.
 * @return string Hex color.
 */
function sbir_get_status_color($status_slug) {
    $colors = array(
        'planned' => '#94a3b8',
        'in-progress' => '#3b82f6',
        'done' => '#10b981'
    );
    
    return isset($colors[$status_slug]) ? $colors[$status_slug] : '#6b7280';
}

/**
 * Output item meta markup (comments, date, author, deadline).
 *
 * @param int $item_id Item post ID.
 */
function sbir_render_item_meta($item_id) {
    echo '<div class="sbir-task-footer">';
    echo '<div class="sbir-task-meta-left">';
    if (comments_open($item_id)) {
        echo '<span class="sbir-meta-comments">'
            . sbir_get_svg_icon('comments', array('class' => 'sbir-meta-icon', 'width' => '11', 'height' => '11'))
            . esc_html(get_comments_number($item_id))
            . '</span>';
    }
    echo '</div>';
    echo '<div class="sbir-task-meta-right">';
    $deadline = get_post_meta($item_id, '_sbir_deadline', true);
    $deadline_ts = $deadline ? strtotime($deadline) : false;
    if ($deadline_ts) {
        echo '<span class="sbir-meta-deadline-inline">'
            . sbir_get_svg_icon('calendar', array('class' => 'sbir-meta-icon', 'width' => '11', 'height' => '11'))
            . esc_html(date_i18n(get_option('date_format'), $deadline_ts))
            . '</span>';
    }
    echo '<span class="sbir-meta-date">'
        . sbir_get_svg_icon('calendar', array('class' => 'sbir-meta-icon', 'width' => '11', 'height' => '11'))
        . esc_html(get_the_date('M j', $item_id))
        . '</span>';
    $guest_name = (string) get_post_meta($item_id, '_sbir_guest_name', true);
    $author_name = $guest_name !== ''
        ? $guest_name
        : (string) get_the_author_meta('display_name', get_post_field('post_author', $item_id));
    if ($author_name !== '') {
        echo '<span class="sbir-meta-author">'
            . sbir_get_svg_icon('user', array('class' => 'sbir-meta-icon', 'width' => '11', 'height' => '11'))
            . esc_html($author_name)
            . '</span>';
    }
    echo '</div>';
    echo '</div>';
}

/**
 * Get top voted idea items for a board.
 *
 * @param int $board_id Board ID.
 * @param int $limit    Max number of items.
 * @return array[] List of arrays with item_id and vote_count.
 */
function sbir_get_board_top_voted_ideas($board_id, $limit = 5) {
    $board_id = (int) $board_id;
    $limit = max(1, (int) $limit);
    $cache_key = 'sbir_top_voted_ideas_' . $board_id . '_' . $limit;
    $cached = wp_cache_get($cache_key, SBIR_Cache_Helper::CACHE_GROUP);
    if ($cached !== false && is_array($cached)) {
        return $cached;
    }

    global $wpdb;
    $statuses_arr = current_user_can('edit_posts') ? array('publish', 'pending') : array('publish');
    $status_placeholders = implode(',', array_fill(0, count($statuses_arr), '%s'));
    $vote_counts_table = $wpdb->prefix . 'sbir_vote_counts';

    $sql = "
        SELECT p.ID AS item_id, COALESCE(vc.vote_count, 0) AS vote_count
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm_board
            ON p.ID = pm_board.post_id
            AND pm_board.meta_key = '_sbir_board_id'
            AND pm_board.meta_value = %d
        LEFT JOIN {$wpdb->postmeta} pm_type
            ON p.ID = pm_type.post_id
            AND pm_type.meta_key = '_sbir_is_roadmap'
        LEFT JOIN {$vote_counts_table} vc
            ON p.ID = vc.item_id
        WHERE p.post_type = 'sbir_item'
            AND p.post_status IN ({$status_placeholders})
            AND (pm_type.meta_value IS NULL OR pm_type.meta_value != 'yes')
        ORDER BY vote_count DESC, p.post_date_gmt DESC
        LIMIT %d
    ";

    $prepare_args = array_merge(array($board_id), $statuses_arr, array($limit));
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
    $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $prepare_args), ARRAY_A);

    $items = array();
    foreach ($rows as $row) {
        $item_id = isset($row['item_id']) ? (int) $row['item_id'] : 0;
        if ($item_id <= 0) {
            continue;
        }
        $items[] = array(
            'item_id' => $item_id,
            'vote_count' => isset($row['vote_count']) ? (int) $row['vote_count'] : 0,
        );
    }

    wp_cache_set($cache_key, $items, SBIR_Cache_Helper::CACHE_GROUP, 300);
    return $items;
}

/**
 * Get recently discussed idea items for a board.
 *
 * @param int $board_id Board ID.
 * @param int $limit    Max number of items.
 * @return array[] List of arrays with item_id, comment_count, latest_comment_gmt.
 */
function sbir_get_board_recently_discussed_ideas($board_id, $limit = 5) {
    $board_id = (int) $board_id;
    $limit = max(1, (int) $limit);
    $cache_key = 'sbir_recent_discussed_ideas_' . $board_id . '_' . $limit;
    $cached = wp_cache_get($cache_key, SBIR_Cache_Helper::CACHE_GROUP);
    if ($cached !== false && is_array($cached)) {
        return $cached;
    }

    global $wpdb;
    $statuses_arr = current_user_can('edit_posts') ? array('publish', 'pending') : array('publish');
    $status_placeholders = implode(',', array_fill(0, count($statuses_arr), '%s'));

    $sql = "
        SELECT p.ID AS item_id,
               COUNT(c.comment_ID) AS comment_count,
               MAX(c.comment_date_gmt) AS latest_comment_gmt
        FROM {$wpdb->comments} c
        INNER JOIN {$wpdb->posts} p
            ON p.ID = c.comment_post_ID
        INNER JOIN {$wpdb->postmeta} pm_board
            ON p.ID = pm_board.post_id
            AND pm_board.meta_key = '_sbir_board_id'
            AND pm_board.meta_value = %d
        LEFT JOIN {$wpdb->postmeta} pm_type
            ON p.ID = pm_type.post_id
            AND pm_type.meta_key = '_sbir_is_roadmap'
        WHERE p.post_type = 'sbir_item'
            AND p.post_status IN ({$status_placeholders})
            AND c.comment_approved = '1'
            AND (pm_type.meta_value IS NULL OR pm_type.meta_value != 'yes')
        GROUP BY p.ID
        ORDER BY latest_comment_gmt DESC
        LIMIT %d
    ";

    $prepare_args = array_merge(array($board_id), $statuses_arr, array($limit));
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
    $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $prepare_args), ARRAY_A);

    $items = array();
    foreach ($rows as $row) {
        $item_id = isset($row['item_id']) ? (int) $row['item_id'] : 0;
        if ($item_id <= 0) {
            continue;
        }
        $items[] = array(
            'item_id' => $item_id,
            'comment_count' => isset($row['comment_count']) ? (int) $row['comment_count'] : 0,
            'latest_comment_gmt' => isset($row['latest_comment_gmt']) ? sanitize_text_field((string) $row['latest_comment_gmt']) : '',
        );
    }

    wp_cache_set($cache_key, $items, SBIR_Cache_Helper::CACHE_GROUP, 300);
    return $items;
}

/**
 * Get or assign unique item number scoped to board.
 *
 * @param int $item_id Item post ID.
 * @return int
 */
function sbir_get_item_number($item_id) {
    $number = get_post_meta($item_id, '_sbir_item_number', true);
    $board_id = (int) get_post_meta($item_id, '_sbir_board_id', true);

    // If no valid board, do not assign; return existing or 0
    if (!$board_id) {
        return (int) $number;
    }

    // Check if number is already set and unique
    $number_is_valid = false;
    if ($number) {
        global $wpdb;
        $statuses_arr = SBIR_Query_Helper::get_valid_post_statuses();
        $placeholders = implode(',', array_fill(0, count($statuses_arr), '%s'));
        $sql = "
            SELECT COUNT(*) 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_sbir_board_id'
            INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_sbir_item_number'
            WHERE p.post_type = 'sbir_item'
            AND p.post_status IN ($placeholders)
            AND pm1.meta_value = %d
            AND pm2.meta_value = %d
            AND p.ID != %d
        ";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
        $existing = $wpdb->get_var($wpdb->prepare($sql, array_merge($statuses_arr, array($board_id, $number, $item_id))));
        
        $number_is_valid = ($existing == 0);
    }

    // If number not set or duplicated, assign a new unique number scoped to board
    if (!$number_is_valid) {
        global $wpdb;
        
        // Get the next available number using a single query
        $statuses_arr = SBIR_Query_Helper::get_valid_post_statuses();
        $placeholders = implode(',', array_fill(0, count($statuses_arr), '%s'));
        $sql = "
            SELECT COALESCE(MAX(CAST(pm.meta_value AS UNSIGNED)), 0) + 1
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_sbir_board_id'
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sbir_item_number'
            WHERE p.post_type = 'sbir_item'
            AND p.post_status IN ($placeholders)
            AND pm1.meta_value = %d
            AND pm.meta_value REGEXP '^[0-9]+$'
        ";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
        $next_number = $wpdb->get_var($wpdb->prepare($sql, array_merge($statuses_arr, array($board_id))));
        
        $number = intval($next_number);
        update_post_meta($item_id, '_sbir_item_number', $number);
    }

    return (int) $number;
}

/**
 * Get like count for a comment.
 *
 * @param int $comment_id Comment ID.
 * @return int
 */
function sbir_get_comment_like_count($comment_id) {
    $count = get_comment_meta($comment_id, '_sbir_like_count', true);
    $count = is_numeric($count) ? (int) $count : 0;
    return max(0, $count);
}

/**
 * Check whether the current user has liked a comment.
 *
 * @param int         $comment_id       Comment ID.
 * @param string|null $user_identifier  Optional. Override user identifier.
 * @return bool
 */
function sbir_user_liked_comment($comment_id, $user_identifier = null) {
    if (!$user_identifier) {
        $user_identifier = SBIR_Cache_Helper::get_cached_user_identifier();
    }
    $key = '_sbir_liked_' . md5($user_identifier);
    $liked = get_comment_meta($comment_id, $key, true);
    return $liked === '1' || $liked === 1;
}