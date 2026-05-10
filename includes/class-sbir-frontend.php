<?php
/**
 * Frontend functionality for boards, items, and drawer.
 *
 * @package SimpleBoards_Roadmap
 */
if (!defined('ABSPATH')) {
    exit;
}

class SBIR_Frontend {

    /**
     * Register frontend hooks and filters.
     */
    public function init() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_filter('the_content', array($this, 'board_content_filter'));
        // Add dynamic inline CSS via proper enqueue API after base style is enqueued
        add_action('wp_enqueue_scripts', array($this, 'add_inline_styles'), 20);
        add_filter('template_include', array($this, 'template_loader'));
        add_filter('body_class', array($this, 'body_class_filter'));
        add_filter('comments_open', array($this, 'force_item_comments_open'), 10, 2);
        add_action('template_redirect', array($this, 'redirect_item_single_to_board'), 1);
        add_action('template_redirect', array($this, 'enforce_board_tab_canonical_url'), 2);
        add_action('template_redirect', array($this, 'redirect_board_default_tab_to_pretty_url'), 3);
        
        // Prevent post navigation for sbir_item posts
        add_action('template_redirect', array($this, 'disable_post_navigation'));

        // Prevent theme-rendered comments; we'll render our own inside content
        add_action('template_redirect', array($this, 'disable_theme_comments'));

        // Suppress board title/meta according to settings
        add_action('template_redirect', array($this, 'filter_board_title_and_meta'));

        // AJAX for frontend kanban updates (admins only)
        add_action('wp_ajax_sbir_update_status_front', array($this, 'ajax_update_status_front'));
        
        // AJAX for lazy loading board content
        add_action('wp_ajax_sbir_load_board_content', array($this, 'ajax_load_board_content'));
        add_action('wp_ajax_nopriv_sbir_load_board_content', array($this, 'ajax_load_board_content'));

        // AJAX for drawer content (faster than full page load)
        add_action('wp_ajax_sbir_get_item_drawer', array($this, 'ajax_get_item_drawer'));
        add_action('wp_ajax_nopriv_sbir_get_item_drawer', array($this, 'ajax_get_item_drawer'));

        // AJAX comment owner actions
        add_action('wp_ajax_sbir_edit_comment', array($this, 'ajax_edit_comment'));
        add_action('wp_ajax_sbir_delete_comment', array($this, 'ajax_delete_comment'));

        // Notification triggers
        add_action('transition_post_status', array($this, 'on_item_transition_status'), 10, 3);
        add_action('updated_post_meta', array($this, 'on_item_meta_updated'), 10, 4);
        add_action('set_object_terms', array($this, 'on_item_status_terms_set'), 20, 6);
        add_action('comment_post', array($this, 'on_item_comment_posted'), 10, 3);
        // AJAX comment like toggle
        add_action('wp_ajax_sbir_toggle_comment_like', array($this, 'ajax_toggle_comment_like'));
        add_action('wp_ajax_nopriv_sbir_toggle_comment_like', array($this, 'ajax_toggle_comment_like'));
    }

    /**
     * Add theme-compatibility body classes.
     *
     * Supported modes (via `sbir_theme_compat_mode` filter):
     *   - 'shield'  (default) Scoped CSS boundary blocks theme overrides.
     *   - 'inherit'           Opt-in: adopt theme typography for board UI.
     *   - 'shadow'            Reserved for future Shadow DOM isolation of
     *                          volatile components (drawer/modal). No-op today.
     *
     * @param array $classes Body classes.
     * @return array
     */
    public function body_class_filter($classes) {
        if (!(is_singular('sbir_board') || is_singular('sbir_item'))) {
            return $classes;
        }

        $default_mode = get_option('sbir_inherit_theme_styles', 'no') === 'yes' ? 'inherit' : 'shield';

        /**
         * Filter the theme compatibility mode for the current board view.
         *
         * Return one of: 'shield', 'inherit', 'shadow'.
         *
         * @param string $mode Current mode.
         */
        $mode = apply_filters('sbir_theme_compat_mode', $default_mode);
        $mode = in_array($mode, array('shield', 'inherit', 'shadow'), true) ? $mode : 'shield';

        if ($mode === 'inherit') {
            $classes[] = 'sbir-inherit-theme';
        }

        $classes[] = 'sbir-theme-compat-' . sanitize_html_class($mode);

        return $classes;
    }

    /**
     * Keep comments available for roadmap items, unless globally or per-board
     * disabled in SimpleBoards settings.
     *
     * @param bool $open    Whether comments are open.
     * @param int  $post_id Post ID.
     * @return bool
     */
    public function force_item_comments_open($open, $post_id) {
        if (get_post_type($post_id) !== 'sbir_item') {
            return $open;
        }

        if (get_option('sbir_comments_enabled', 'yes') !== 'yes') {
            return false;
        }

        $board_id = (int) get_post_meta((int) $post_id, '_sbir_board_id', true);
        if ($board_id > 0 && get_post_meta($board_id, '_sbir_board_comments_disabled', true) === 'yes') {
            return false;
        }

        return true;
    }


    /**
     * Route item permalink visits to board view and open drawer.
     */
    public function redirect_item_single_to_board() {
        if (!is_singular('sbir_item') || is_admin() || wp_doing_ajax()) {
            return;
        }

        $item_id = get_queried_object_id();
        if (!$item_id || is_preview()) {
            return;
        }

        $status = get_post_status($item_id);
        if ($status !== 'publish' && !current_user_can('read_post', $item_id)) {
            return;
        }

        $board_id = (int) get_post_meta($item_id, '_sbir_board_id', true);
        if (!sbir_current_user_can_access_item($item_id, 'single_redirect')) {
            wp_die(
                esc_html(apply_filters('sbir_private_board_notice', __('This board is private.', 'simpleboards-roadmap'), $board_id, 'item_single_redirect')),
                esc_html__('Private Board', 'simpleboards-roadmap'),
                array('response' => 403)
            );
        }

        $target_url = get_permalink($item_id);
        if (!$target_url) {
            return;
        }

        wp_safe_redirect($target_url, 302);
        exit;
    }

    /**
     * Redirect to roadmap tab when Ideas tab is disabled and /ideas/ is requested.
     */
    public function enforce_board_tab_canonical_url() {
        if (is_admin() || wp_doing_ajax() || !is_singular('sbir_board')) {
            return;
        }

        $board_id = (int) get_queried_object_id();
        if (!$board_id) {
            return;
        }

        $enable_ideas = get_post_meta($board_id, '_sbir_enable_ideas', true) !== 'no';
        if ($enable_ideas) {
            return;
        }

        $requested_tab = sanitize_key((string) get_query_var('sbir_tab'));

        if ($requested_tab !== 'ideas') {
            return;
        }

        $board_url = get_permalink($board_id);
        if (!$board_url) {
            return;
        }

        $target_url = user_trailingslashit(trailingslashit($board_url) . 'roadmap');
        $item_slug = sanitize_title((string) get_query_var('sbir_item_slug'));
        if ($item_slug !== '') {
            $target_url = user_trailingslashit(trailingslashit($target_url) . $item_slug);
        }

        wp_safe_redirect($target_url, 302);
        exit;
    }

    /**
     * Redirect base board URL to /ideas/ when default tab is Ideas (pretty URL).
     *
     * Uses the sbir_board_default_tab filter (same as the board template).
     */
    public function redirect_board_default_tab_to_pretty_url() {
        if (is_admin() || wp_doing_ajax() || !is_singular('sbir_board')) {
            return;
        }

        $board_id = (int) get_queried_object_id();
        if (!$board_id) {
            return;
        }

        $enable_ideas = get_post_meta($board_id, '_sbir_enable_ideas', true) !== 'no';
        if (!$enable_ideas) {
            return;
        }

        $tab_from_url = sanitize_key((string) get_query_var('sbir_tab'));
        if ($tab_from_url !== '') {
            return;
        }

        $item_slug = sanitize_title((string) get_query_var('sbir_item_slug'));
        if ($item_slug !== '') {
            return;
        }

        $default_tab = (string) get_post_meta($board_id, '_sbir_default_tab', true);
        $choices = function_exists('sbir_get_default_tab_choices') ? sbir_get_default_tab_choices((int) $board_id) : array('roadmap' => '', 'ideas' => '');
        if (!isset($choices[$default_tab])) {
            $default_tab = 'roadmap';
        }
        $default_tab = apply_filters('sbir_board_default_tab', $default_tab, $board_id);

        if ($default_tab === '' || $default_tab === 'roadmap') {
            return;
        }

        $board_url = get_permalink($board_id);
        if (!$board_url) {
            return;
        }

        $target = user_trailingslashit(trailingslashit($board_url) . sanitize_title($default_tab));
        wp_safe_redirect($target, 302);
        exit;
    }

    /**
     * AJAX: Edit a comment (owner or moderator).
     */
    public function ajax_edit_comment() {
        check_ajax_referer('sbir_public_nonce', 'nonce');
        $comment_id = isset($_POST['comment_id']) ? absint(wp_unslash($_POST['comment_id'])) : 0;
        $content = isset($_POST['content']) ? wp_kses_post(wp_unslash($_POST['content'])) : '';
        if (!$comment_id || $content === '') {
            wp_send_json_error(__('Invalid data', 'simpleboards-roadmap'));
        }
        $comment = get_comment($comment_id);
        if (!$comment) {
            wp_send_json_error(__('Comment not found', 'simpleboards-roadmap'));
        }
        $item_id = (int) $comment->comment_post_ID;
        if (!sbir_current_user_can_access_item($item_id, 'edit_comment')) {
            wp_send_json_error(__('Unauthorized', 'simpleboards-roadmap'));
        }
        $current_user_id = get_current_user_id();
        if (!$current_user_id || ( (int)$comment->user_id !== (int)$current_user_id && !current_user_can('edit_comment', $comment_id) && !current_user_can('moderate_comments') )) {
            wp_send_json_error(__('Unauthorized', 'simpleboards-roadmap'));
        }
        $updated = wp_update_comment(array(
            'comment_ID' => $comment_id,
            'comment_content' => $content,
        ));
        if (!$updated) {
            wp_send_json_error(__('Could not update comment', 'simpleboards-roadmap'));
        }
        wp_send_json_success(true);
    }

    /**
     * AJAX: Delete a comment (owner or moderator).
     */
    public function ajax_delete_comment() {
        check_ajax_referer('sbir_public_nonce', 'nonce');
        $comment_id = isset($_POST['comment_id']) ? absint(wp_unslash($_POST['comment_id'])) : 0;
        if (!$comment_id) {
            wp_send_json_error(__('Invalid data', 'simpleboards-roadmap'));
        }
        $comment = get_comment($comment_id);
        if (!$comment) {
            wp_send_json_error(__('Comment not found', 'simpleboards-roadmap'));
        }
        $item_id = (int) $comment->comment_post_ID;
        if (!sbir_current_user_can_access_item($item_id, 'delete_comment')) {
            wp_send_json_error(__('Unauthorized', 'simpleboards-roadmap'));
        }
        $current_user_id = get_current_user_id();
        if (!$current_user_id || ( (int)$comment->user_id !== (int)$current_user_id && !current_user_can('edit_comment', $comment_id) && !current_user_can('moderate_comments') )) {
            wp_send_json_error(__('Unauthorized', 'simpleboards-roadmap'));
        }
        $deleted = wp_delete_comment($comment_id, true);
        if (!$deleted) {
            wp_send_json_error(__('Could not delete comment', 'simpleboards-roadmap'));
        }
        wp_send_json_success(true);
    }

    /**
     * AJAX: Toggle like on a comment for the current user.
     */
    public function ajax_toggle_comment_like() {
        check_ajax_referer('sbir_public_nonce', 'nonce');
        $comment_id = isset($_POST['comment_id']) ? absint(wp_unslash($_POST['comment_id'])) : 0;
        if (!$comment_id) {
            wp_send_json_error(__('Invalid data', 'simpleboards-roadmap'));
        }
        $comment = get_comment($comment_id);
        if (!$comment) {
            wp_send_json_error(__('Comment not found', 'simpleboards-roadmap'));
        }
        $item_id = (int) $comment->comment_post_ID;
        if (!sbir_current_user_can_access_item($item_id, 'toggle_comment_like')) {
            wp_send_json_error(__('Unauthorized', 'simpleboards-roadmap'));
        }
        $user_identifier = SBIR_Cache_Helper::get_cached_user_identifier();
        $key = '_sbir_liked_' . md5($user_identifier);
        $liked = get_comment_meta($comment_id, $key, true) === '1';
        $count = sbir_get_comment_like_count($comment_id);
        if ($liked) {
            delete_comment_meta($comment_id, $key);
            $count = max(0, $count - 1);
        } else {
            update_comment_meta($comment_id, $key, '1');
            $count = $count + 1;
        }
        update_comment_meta($comment_id, '_sbir_like_count', $count);
        wp_send_json_success(array('liked' => !$liked, 'count' => $count));
    }

    /**
     * Determine whether current request needs board assets.
     *
     * @return bool
     */
    private function should_load_board_assets() {
        if (is_singular('sbir_board') || is_singular('sbir_item')) {
            return true;
        }

        $post_id = get_queried_object_id();
        if (!$post_id) {
            return false;
        }

        $content = get_post_field('post_content', $post_id);
        return is_string($content) && has_shortcode($content, 'sbir_board');
    }
    
    /**
     * Enqueue frontend scripts and styles
     */
    public function enqueue_scripts() {
        if ($this->should_load_board_assets()) {
            $css_path          = SBIR_PLUGIN_DIR . 'public/css/sbir-public.css';
            $js_core_path      = SBIR_PLUGIN_DIR . 'public/js/sbir-public.js';
            $js_comments_path  = SBIR_PLUGIN_DIR . 'public/js/sbir-public-comments.js';
            $css_ver           = file_exists($css_path) ? filemtime($css_path) : SBIR_VERSION;
            $js_core_ver       = file_exists($js_core_path) ? filemtime($js_core_path) : SBIR_VERSION;
            $js_comments_ver   = file_exists($js_comments_path) ? filemtime($js_comments_path) : SBIR_VERSION;

            wp_enqueue_style('sbir-public', SBIR_PLUGIN_URL . 'public/css/sbir-public.css', array(), $css_ver);
            wp_enqueue_script('jquery-ui-sortable');
            wp_enqueue_script('sbir-public', SBIR_PLUGIN_URL . 'public/js/sbir-public.js', array('jquery', 'jquery-ui-sortable'), $js_core_ver, true);
            wp_enqueue_script('sbir-public-comments', SBIR_PLUGIN_URL . 'public/js/sbir-public-comments.js', array('sbir-public'), $js_comments_ver, true);
            // Ensure WP's reply handler is available for drawer-loaded comments
            if (!wp_script_is('comment-reply', 'enqueued')) {
                wp_enqueue_script('comment-reply');
            }
            wp_localize_script('sbir-public', 'sbir_public', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('sbir_public_nonce'),
                'current_user_can_manage' => current_user_can('edit_posts'),
                'loading_text' => __('Loading...', 'simpleboards-roadmap'),
                'i18n' => array(
                    'submitting' => __('Submitting...', 'simpleboards-roadmap'),
                    'submit_idea' => __('Submit Idea', 'simpleboards-roadmap'),
                    'error_submitting_idea' => __('Error submitting idea', 'simpleboards-roadmap'),
                    'connection_error' => __('Connection error. Please try again.', 'simpleboards-roadmap'),
                    'add_roadmap_item' => __('Add Roadmap Item', 'simpleboards-roadmap'),
                    'moving' => __('Moving...', 'simpleboards-roadmap'),
                    'move' => __('Move', 'simpleboards-roadmap'),
                    'moved' => __('Moved', 'simpleboards-roadmap'),
                    'error_moving' => __('Error moving', 'simpleboards-roadmap'),
                    'saving' => __('Saving...', 'simpleboards-roadmap'),
                    'all_changes_saved' => __('All changes saved', 'simpleboards-roadmap'),
                    'subscribe' => __('Subscribe', 'simpleboards-roadmap'),
                    'subscribed' => __('Subscribed', 'simpleboards-roadmap'),
                    'subscribe_email_required' => __('Please enter a valid email address.', 'simpleboards-roadmap'),
                    'subscribe_success' => __('Subscribed for updates.', 'simpleboards-roadmap'),
                    'unsubscribe_success' => __('Unsubscribed.', 'simpleboards-roadmap'),
                    'saved' => __('Saved', 'simpleboards-roadmap'),
                    'error_saving' => __('Error saving', 'simpleboards-roadmap'),
                    'create_item' => __('Create Item', 'simpleboards-roadmap'),
                    'created' => __('Created', 'simpleboards-roadmap'),
                    'error_creating_item' => __('Error creating item', 'simpleboards-roadmap'),
                    'close' => __('Close', 'simpleboards-roadmap'),
                    'delete_comment_confirm' => __('Delete this comment?', 'simpleboards-roadmap'),
                    'write_reply_placeholder' => __('Write a reply...', 'simpleboards-roadmap'),
                    'edit_comment_placeholder' => __('Edit comment...', 'simpleboards-roadmap'),
                    'cancel' => __('Cancel', 'simpleboards-roadmap'),
                    'reply' => __('Reply', 'simpleboards-roadmap'),
                    'save' => __('Save', 'simpleboards-roadmap'),
                    'bold' => __('Bold', 'simpleboards-roadmap'),
                    'italic' => __('Italic', 'simpleboards-roadmap'),
                    'underline' => __('Underline', 'simpleboards-roadmap'),
                    'link' => __('Link', 'simpleboards-roadmap'),
                    'enter_url' => __('Enter URL', 'simpleboards-roadmap'),
                    'no_search_matches' => __('No matching items found.', 'simpleboards-roadmap'),
                    'search_ideas_placeholder' => __('Search ideas...', 'simpleboards-roadmap'),
                    'search_roadmap_placeholder' => __('Search roadmap items...', 'simpleboards-roadmap'),
                    'search_announcement_placeholder' => __('Search announcements...', 'simpleboards-roadmap'),
                    'all_cards' => __('All cards', 'simpleboards-roadmap'),
                    'all_ideas' => __('All ideas', 'simpleboards-roadmap'),
                    'uncategorized' => __('Uncategorized', 'simpleboards-roadmap'),
                    /* translators: short connector used in pagination summary: "1-10 of 42". */
                    'of' => __('of', 'simpleboards-roadmap'),
                ),
            ));
        }
    }

    /**
     * Load custom templates for plugin post types.
     *
     * For `sbir_board` singular views we return our own minimal template by
     * default, so the theme's entry wrappers (featured image, entry-header,
     * author/date meta, entry-footer, post navigation, comments) do not
     * render on board pages. Themes can opt out via `sbir_use_custom_board_template`.
     *
     * @param string $template Resolved template path from WP template hierarchy.
     * @return string
     */
    public function template_loader($template) {
        if (is_singular('sbir_item')) {
            // Always inject our content template; never use legacy page template
            add_filter('the_content', array($this, 'item_content_filter'), 999);
            return $template;
        }

        if (is_singular('sbir_board')) {
            /**
             * Filter whether to use the plugin's minimal single template for boards.
             *
             * Returning false falls back to the theme's template. Useful for themes
             * that need to control the full page structure themselves.
             *
             * @param bool $use_custom Default true.
             */
            $use_custom = (bool) apply_filters('sbir_use_custom_board_template', true);
            if ($use_custom) {
                $custom_template = SBIR_PLUGIN_DIR . 'public/templates/single-sbir-board.php';
                if (file_exists($custom_template)) {
                    return $custom_template;
                }
            }
        }

        return $template;
    }
    
    /**
     * Add inline styles for status colors (cached for performance)
     */
    public function add_inline_styles() {
        if ($this->should_load_board_assets()) {
            $cache_key = 'sbir_status_styles_' . get_current_blog_id();
            $cached_css = get_transient($cache_key);
            
            if ($cached_css === false) {
                $statuses = get_terms(array(
                    'taxonomy' => 'sbir_status',
                    'hide_empty' => false
                ));
                
                if (!empty($statuses) && !is_wp_error($statuses)) {
                    $css = '';
                    foreach ($statuses as $status) {
                        $color = get_term_meta($status->term_id, '_sbir_status_color', true);
                        if (!$color) { $color = sbir_get_status_color($status->slug); }
                        $css .= '.sbir-status-' . esc_attr($status->slug) . ' { --status-color: ' . esc_attr($color) . '; }';
                    }
                    // Cache for 24 hours
                    set_transient($cache_key, $css, DAY_IN_SECONDS);
                    $cached_css = $css;
                } else {
                    $cached_css = '';
                }
            }
            
            if ($cached_css !== '') {
                // Attach inline CSS to our base public style handle
                wp_add_inline_style('sbir-public', $cached_css);
            }
        }
    }
    
    /**
     * Filter board content to add our display
     */
    public function board_content_filter($content) {
        if (is_singular('sbir_board') && in_the_loop() && is_main_query()) {
            $board_id = get_the_ID();
            if (!sbir_current_user_can_access_board((int) $board_id, 'board_content')) {
                return '<div class="sbir-notice">' . esc_html(apply_filters('sbir_private_board_notice', __('This board is private.', 'simpleboards-roadmap'), (int) $board_id, 'board_content')) . '</div>';
            }
            ob_start();
            $this->render_board_display($board_id);
            $board_content = ob_get_clean();
            return $board_content;
        }
        return $content;
    }

    /**
     * Flag to prevent infinite recursion in content filter
     */
    private $rendering_item_content = false;

    /**
     * Filter item content for block themes
     */
    public function item_content_filter($content) {
        if (is_singular('sbir_item') && in_the_loop() && is_main_query() && !$this->rendering_item_content) {
            // Set flag to prevent recursion
            $this->rendering_item_content = true;
            
            // Temporarily remove this filter to prevent infinite loop
            remove_filter('the_content', array($this, 'item_content_filter'), 999);
            
            ob_start();
            include SBIR_PLUGIN_DIR . 'public/templates/item-single-complete.php';
            $item_content = ob_get_clean();
            
            // Re-add the filter
            add_filter('the_content', array($this, 'item_content_filter'), 999);
            
            // Reset flag
            $this->rendering_item_content = false;
            
            return $item_content;
        }
        return $content;
    }
    
    /**
     * Render board display template.
     *
     * @param int    $board_id Board post ID.
     * @param string $view     'both', 'roadmap', or 'ideas'.
     */
    public function render_board_display($board_id, $view = 'both') {
        $enable_ideas = get_post_meta($board_id, '_sbir_enable_ideas', true);
        $enable_ideas = $enable_ideas !== 'no';
        
        include SBIR_PLUGIN_DIR . 'public/templates/board-display.php';
    }

    /**
     * AJAX: Update item status when dragging in Kanban.
     */
    public function ajax_update_status_front() {
        check_ajax_referer('sbir_public_nonce', 'nonce');
        $item_id = isset($_POST['item_id']) ? absint(wp_unslash($_POST['item_id'])) : 0;
        if (!$item_id || !current_user_can('edit_post', $item_id)) {
            wp_send_json_error(__('Unauthorized', 'simpleboards-roadmap'));
        }
        $board_id = (int) get_post_meta($item_id, '_sbir_board_id', true);
        if (!sbir_current_user_can_access_item($item_id, 'ajax_update_status_front')) {
            wp_send_json_error(__('Unauthorized', 'simpleboards-roadmap'));
        }
        $status_slug = isset($_POST['status']) ? sanitize_title(wp_unslash($_POST['status'])) : '';
        if (!$item_id) {
            wp_send_json_error(__('Invalid request', 'simpleboards-roadmap'));
        }
        // Special case: unassigned → clear status terms
        if ($status_slug === 'unassigned') {
            $result = wp_set_object_terms($item_id, array(), 'sbir_status', false);
        } else {
            $term = get_term_by('slug', $status_slug, 'sbir_status');
            if (!$term || is_wp_error($term)) {
                wp_send_json_error(__('Status not found', 'simpleboards-roadmap'));
            }
            $status_board_id = (int) get_term_meta((int) $term->term_id, '_sbir_status_board', true);
            if ($status_board_id > 0 && $board_id > 0 && $status_board_id !== $board_id) {
                wp_send_json_error(__('Status not found', 'simpleboards-roadmap'));
            }
            // Avoid duplicate terms on object; replace existing
            $result = wp_set_object_terms($item_id, array((int)$term->term_id), 'sbir_status', false);
        }
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        wp_send_json_success(array('item_id' => $item_id, 'status' => $status_slug));
    }

    /**
     * Disable post navigation for sbir_item posts.
     */
    public function disable_post_navigation() {
        if (is_singular('sbir_item')) {
            // Remove WordPress core navigation functions
            add_filter('get_previous_post', '__return_false');
            add_filter('get_next_post', '__return_false');
            
            // Remove navigation template functions
            add_filter('the_post_navigation', '__return_empty_string');
            add_filter('get_the_post_navigation', '__return_empty_string');
            
            // Remove adjacent post links from head
            remove_action('wp_head', 'adjacent_posts_rel_link_wp_head', 10, 0);
            
            // Remove common theme actions that add navigation
            remove_action('wp_footer', 'the_post_navigation');
            remove_action('get_footer', 'the_post_navigation');
            
            // Block theme support - remove navigation blocks
            add_filter('render_block_core/post-navigation-link', '__return_empty_string');
            add_filter('render_block_core/post-navigation', '__return_empty_string');
            
            // Generic removal of theme navigation hooks
            $this->remove_theme_navigation_hooks();
        }
    }
    
    /**
     * Remove theme-specific post navigation hooks.
     */
    private function remove_theme_navigation_hooks() {
        global $wp_filter;
        
        // Common hooks where themes add post navigation
        $navigation_hooks = array(
            'wp_footer',
            'get_footer', 
            'wp_body_open',
            'after_single_post_summary',
            'after_content_area'
        );
        
        foreach ($navigation_hooks as $hook) {
            if (isset($wp_filter[$hook])) {
                foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
                    foreach ($callbacks as $callback) {
                        if (is_array($callback['function']) && 
                            is_string($callback['function'][1]) && 
                            (strpos($callback['function'][1], 'navigation') !== false ||
                             strpos($callback['function'][1], 'nav') !== false)) {
                            remove_action($hook, $callback['function'], $priority);
                        }
                    }
                }
            }
        }
    }

    /**
     * Disable theme-rendered comments for block themes on sbir_item
     * We'll render our own comments markup inside the template
     */
    public function disable_theme_comments() {
        if (is_singular('sbir_item')) {
            add_filter('render_block_core/comments', '__return_empty_string');
            add_filter('render_block_core/comments-title', '__return_empty_string');
            add_filter('render_block_core/comments-query-loop', '__return_empty_string');
            add_filter('render_block_core/comment-template', '__return_empty_string');
            add_filter('render_block_core/post-comments-form', '__return_empty_string');
        }
    }

    /**
     * Hide Board title (optional via setting) and always remove theme meta (author/date) for sbir_board
     */
    public function filter_board_title_and_meta() {
        if (!is_singular('sbir_board')) {
            return;
        }

        // Always suppress theme title for sbir_board and render our own styled heading in template.
        add_filter('the_title', function($title, $post_id){
            if (is_singular('sbir_board') && (int)$post_id === (int)get_queried_object_id()) {
                return '';
            }
            return $title;
        }, 10, 2);

        // Block themes: remove title/meta blocks; template controls title visibility.
        add_filter('render_block_core/post-title', '__return_empty_string');
        add_filter('render_block_core/post-author', '__return_empty_string');
        add_filter('render_block_core/post-date', '__return_empty_string');
        add_filter('render_block_core/post-terms', '__return_empty_string');
        add_filter('render_block_core/post-excerpt', '__return_empty_string');
        add_filter('render_block_core/query-title', '__return_empty_string');

        // Classic themes: blank meta template tags on sbir_board
        add_filter('the_author', function($text){ return is_singular('sbir_board') ? '' : $text; });
        add_filter('get_the_date', function($text, $format, $post){
            if (is_singular('sbir_board') && $post instanceof WP_Post && $post->post_type === 'sbir_board') {
                return '';
            }
            return $text;
        }, 10, 3);
        add_filter('the_terms', function($terms){ return is_singular('sbir_board') ? '' : $terms; });

        // Some block themes leave literal helper text like "Written by" and "in" as standalone paragraphs
        add_filter('render_block', function($content, $block){
            if (!is_singular('sbir_board')) { return $content; }
            if (!is_array($block) || empty($block['blockName'])) { return $content; }
            if ($block['blockName'] === 'core/paragraph') {
                $text = trim(wp_strip_all_tags($content));
                if ($text === 'Written by' || $text === 'in') {
                    return '';
                }
            }
            // If a group becomes empty (after removing inner meta paragraphs), drop it to remove theme padding/margins
            if ($block['blockName'] === 'core/group') {
                $text = trim(wp_strip_all_tags($content));
                if ($text === '') {
                    return '';
                }
            }
            // Spacer blocks above content – remove on board pages
            if ($block['blockName'] === 'core/spacer') {
                return '';
            }
            return $content;
        }, 10, 2);
    }

    /**
     * AJAX handler for lazy loading board content
     */
    public function ajax_load_board_content() {
        check_ajax_referer('sbir_public_nonce', 'nonce');
        
        $board_id = isset($_POST['board_id']) ? absint(wp_unslash($_POST['board_id'])) : 0;
        $view_type = isset($_POST['view_type']) ? sanitize_text_field(wp_unslash($_POST['view_type'])) : 'roadmap';
        
        if (!$board_id) {
            wp_send_json_error(__('Invalid board ID', 'simpleboards-roadmap'));
        }

        if (!sbir_current_user_can_access_board((int) $board_id, 'ajax_load_board_content')) {
            wp_send_json_error(__('Unauthorized', 'simpleboards-roadmap'));
        }
        
        ob_start();
        
        if ($view_type === 'roadmap') {
            // Set variables for roadmap template
            $items_query = SBIR_Cache_Helper::get_cached_board_items($board_id, 'roadmap');
            include SBIR_PLUGIN_DIR . 'public/templates/roadmap-view.php';
        } else {
            // Set variables for ideas template
            $ideas_query = SBIR_Cache_Helper::get_cached_board_items($board_id, 'ideas');
            include SBIR_PLUGIN_DIR . 'public/templates/ideas-view.php';
        }
        
        $content = ob_get_clean();
        
        wp_send_json_success(array(
            'content' => $content,
            'view_type' => $view_type
        ));
    }

    /**
     * AJAX handler for drawer content
     */
    public function ajax_get_item_drawer() {
        check_ajax_referer('sbir_public_nonce', 'nonce');

        $item_id = isset($_POST['item_id']) ? absint(wp_unslash($_POST['item_id'])) : 0;
        if (!$item_id) {
            wp_send_json_error(array('message' => __('Invalid item.', 'simpleboards-roadmap')));
        }

        $post = get_post($item_id);
        if (!$post || $post->post_type !== 'sbir_item') {
            wp_send_json_error(array('message' => __('Item not found.', 'simpleboards-roadmap')));
        }

        if ($post->post_status !== 'publish' && !current_user_can('read_post', $item_id)) {
            wp_send_json_error(array('message' => __('Unauthorized', 'simpleboards-roadmap')));
        }

        $board_id = (int) get_post_meta($item_id, '_sbir_board_id', true);
        if (!sbir_current_user_can_access_item($item_id, 'ajax_get_item_drawer')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'simpleboards-roadmap')));
        }

        global $post;
        $post = get_post($item_id);
        setup_postdata($post);
        ob_start();
        include SBIR_PLUGIN_DIR . 'public/templates/item-single-complete.php';
        $content = ob_get_clean();
        wp_reset_postdata();

        wp_send_json_success(array('content' => $content));
    }

    /**
     * Notify on idea publish/reject when status transitions.
     *
     * @param string  $new_status New status.
     * @param string  $old_status Old status.
     * @param WP_Post $post       Post object.
     * @return void
     */
    public function on_item_transition_status($new_status, $old_status, $post) {
        if (!$post instanceof WP_Post || $post->post_type !== 'sbir_item') {
            return;
        }
        if ($new_status === $old_status) {
            return;
        }

        $author_email = get_the_author_meta('user_email', (int) $post->post_author);
        $author_name = get_the_author_meta('display_name', (int) $post->post_author);

        if ($new_status === 'publish' && in_array($old_status, array('pending', 'draft', 'auto-draft'), true)) {
            sbir_send_notification('idea_published', array(
                'item_id' => (int) $post->ID,
                'title' => get_the_title($post),
                'name' => $author_name,
                'email' => $author_email,
                'item_link' => get_permalink($post),
                'board_title' => sbir_get_item_board_title((int) $post->ID),
            ));
            return;
        }

        if (in_array($new_status, array('trash', 'draft'), true) && $old_status === 'pending') {
            sbir_send_notification('idea_rejected_user', array(
                'item_id' => (int) $post->ID,
                'title' => get_the_title($post),
                'name' => $author_name,
                'email' => $author_email,
                'item_link' => get_permalink($post),
            ));
        }
    }

    /**
     * Notify when an idea gets promoted to roadmap (meta change).
     *
     * @param int    $meta_id    Meta ID.
     * @param int    $object_id  Post ID.
     * @param string $meta_key   Meta key.
     * @param mixed  $meta_value New value.
     * @return void
     */
    public function on_item_meta_updated($meta_id, $object_id, $meta_key, $meta_value) {
        unset($meta_id);
        if ($meta_key !== '_sbir_is_roadmap') {
            return;
        }
        if ($meta_value !== 'yes') {
            return;
        }
        $post = get_post((int) $object_id);
        if (!$post || $post->post_type !== 'sbir_item') {
            return;
        }

        $author_email = get_the_author_meta('user_email', (int) $post->post_author);
        $author_name = get_the_author_meta('display_name', (int) $post->post_author);

        sbir_send_notification('idea_promoted', array(
            'item_id' => (int) $post->ID,
            'title' => get_the_title($post),
            'name' => $author_name,
            'email' => $author_email,
            'item_link' => get_permalink($post),
            'board_title' => sbir_get_item_board_title((int) $post->ID),
        ));
    }

    /**
     * Notify subscribers when an item status taxonomy term changes.
     *
     * @param int    $object_id  Object ID.
     * @param array  $terms      New terms.
     * @param array  $tt_ids     Term taxonomy IDs.
     * @param string $taxonomy   Taxonomy.
     * @param bool   $append     Append flag.
     * @param array  $old_tt_ids Old term taxonomy IDs.
     * @return void
     */
    public function on_item_status_terms_set($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {
        unset($tt_ids, $append);
        if ($taxonomy !== 'sbir_status') {
            return;
        }
        $item_id = (int) $object_id;
        if ($item_id <= 0 || get_post_type($item_id) !== 'sbir_item') {
            return;
        }

        $to_term = is_array($terms) && !empty($terms) ? get_term((int) reset($terms), 'sbir_status') : null;
        $from_term = is_array($old_tt_ids) && !empty($old_tt_ids) ? get_term_by('term_taxonomy_id', (int) reset($old_tt_ids), 'sbir_status') : null;

        $to_name = ($to_term && !is_wp_error($to_term)) ? $to_term->name : '';
        $from_name = ($from_term && !is_wp_error($from_term)) ? $from_term->name : '';

        if ($to_name === $from_name) {
            return;
        }

        $item_post = get_post($item_id);
        $submitter_email = '';
        $submitter_name = '';
        if ($item_post instanceof WP_Post && (int) $item_post->post_author > 0) {
            $submitter_email = (string) get_the_author_meta('user_email', (int) $item_post->post_author);
            $submitter_name = (string) get_the_author_meta('display_name', (int) $item_post->post_author);
        }

        sbir_send_item_subscriber_notification('item_status_changed', $item_id, array(
            'title' => get_the_title($item_id),
            'item_title' => get_the_title($item_id),
            'item_link' => get_permalink($item_id),
            'from_status' => $from_name !== '' ? $from_name : __('Unassigned', 'simpleboards-roadmap'),
            'to_status' => $to_name !== '' ? $to_name : __('Unassigned', 'simpleboards-roadmap'),
        ));

        if (get_post_meta($item_id, '_sbir_is_roadmap', true) === 'yes' && $submitter_email !== '' && is_email($submitter_email)) {
            sbir_send_notification('submitter_status_changed', array(
                'item_id' => $item_id,
                'title' => get_the_title($item_id),
                'item_title' => get_the_title($item_id),
                'item_link' => get_permalink($item_id),
                'name' => $submitter_name !== '' ? $submitter_name : $submitter_email,
                'email' => $submitter_email,
                'from_status' => $from_name !== '' ? $from_name : __('Unassigned', 'simpleboards-roadmap'),
                'to_status' => $to_name !== '' ? $to_name : __('Unassigned', 'simpleboards-roadmap'),
            ));
        }
    }

    /**
     * Notify item subscribers (and parent commenter on reply) when a comment is posted.
     *
     * @param int   $comment_id  Comment ID.
     * @param int   $approved    Approval state (1, 0, 'spam').
     * @param array $commentdata Raw comment data.
     * @return void
     */
    public function on_item_comment_posted($comment_id, $approved, $commentdata) {
        if ((int) $approved !== 1) {
            return;
        }
        $post_id = isset($commentdata['comment_post_ID']) ? (int) $commentdata['comment_post_ID'] : 0;
        if ($post_id <= 0 || get_post_type($post_id) !== 'sbir_item') {
            return;
        }

        // Invalidate board-scoped caches (top-voted, recent-discussed widgets)
        // so new comments immediately influence sidebar widget ordering.
        if (class_exists('SBIR_Cache_Helper')) {
            SBIR_Cache_Helper::clear_item_cache($post_id);
        }

        $comment = get_comment($comment_id);
        if (!$comment) {
            return;
        }

        $title = get_the_title($post_id);
        $link = get_permalink($post_id);
        $excerpt = wp_html_excerpt(wp_strip_all_tags((string) $comment->comment_content), 200, '…');
        $commenter = $comment->comment_author !== '' ? $comment->comment_author : __('Someone', 'simpleboards-roadmap');
        $actor_user_id = (int) $comment->user_id;

        // Reply: notify parent commenter (if different person).
        if ((int) $comment->comment_parent > 0) {
            $parent = get_comment((int) $comment->comment_parent);
            if ($parent && (int) $parent->user_id !== $actor_user_id) {
                $parent_email = (string) $parent->comment_author_email;
                $parent_name = $parent->comment_author !== '' ? $parent->comment_author : '';
                if ($parent_email !== '' && is_email($parent_email)) {
                    sbir_send_notification('comment_reply', array(
                        'item_id' => $post_id,
                        'title' => $title,
                        'item_link' => $link,
                        'name' => $parent_name,
                        'email' => $parent_email,
                        'commenter_name' => $commenter,
                        'comment_excerpt' => $excerpt,
                    ));
                }
            }
        }

        // Subscribers + admin notification of new comment.
        sbir_send_notification('admin_new_comment', array(
            'item_id' => $post_id,
            'title' => $title,
            'item_title' => $title,
            'item_link' => $link,
            'commenter_name' => $commenter,
            'comment_excerpt' => $excerpt,
            'board_title' => sbir_get_item_board_title((int) $post_id),
        ));

        sbir_send_item_subscriber_notification('new_comment', $post_id, array(
            'title' => $title,
            'item_title' => $title,
            'item_link' => $link,
            'commenter_name' => $commenter,
            'comment_excerpt' => $excerpt,
        ), $actor_user_id);
    }
}

if (!function_exists('sbir_get_item_board_title')) {
    /**
     * Helper: get the board title for an item.
     *
     * @param int $item_id Item ID.
     * @return string
     */
    function sbir_get_item_board_title($item_id) {
        $board_id = (int) get_post_meta((int) $item_id, '_sbir_board_id', true);
        if ($board_id <= 0) {
            return '';
        }
        $title = get_the_title($board_id);
        return $title ? $title : '';
    }
}