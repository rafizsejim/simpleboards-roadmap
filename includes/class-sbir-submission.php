<?php
/**
 * Idea and roadmap item submission handlers.
 *
 * @package SimpleBoards_Roadmap
 */
if (!defined('ABSPATH')) {
    exit;
}

class SBIR_Submission {

    /**
     * Register AJAX handlers for submissions and updates.
     */
    public function init() {
        add_action('wp_ajax_sbir_submit_idea', array($this, 'handle_submission'));
        add_action('wp_ajax_nopriv_sbir_submit_idea', array($this, 'handle_submission'));
        add_action('wp_ajax_sbir_add_roadmap_item', array($this, 'handle_roadmap_creation'));
        add_action('wp_ajax_sbir_update_roadmap_item', array($this, 'handle_update_roadmap_item'));
        add_action('wp_ajax_sbir_move_to_roadmap_front', array($this, 'handle_move_to_roadmap'));
    }
    
    /**
     * Handle idea submission from frontend form.
     */
    public function handle_submission() {
        check_ajax_referer('sbir_public_nonce', 'nonce');
        
        // Check if guest submissions are allowed
        if (!is_user_logged_in() && get_option('sbir_enable_guest_submissions') !== 'yes') {
            wp_send_json_error(__('You must be logged in to submit ideas.', 'simpleboards-roadmap'));
        }
        
        // Validate and sanitize data (process only required fields)
        $data = array(
            'title'       => isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '',
            'description' => isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '',
            'name'        => isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '',
            'email'       => isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '',
            'category'    => isset($_POST['category']) ? absint(wp_unslash($_POST['category'])) : 0,
            'board_id'    => isset($_POST['board_id']) ? absint(wp_unslash($_POST['board_id'])) : 0,
        );
        
        // Validate required fields
        if (empty($data['title']) || empty($data['description']) || empty($data['board_id'])) {
            wp_send_json_error(__('Please fill in all required fields.', 'simpleboards-roadmap'));
        }

        if (!sbir_current_user_can_access_board((int) $data['board_id'], 'submit_idea')) {
            wp_send_json_error(__('Unauthorized', 'simpleboards-roadmap'));
        }
        
        if (!is_user_logged_in() && (empty($data['name']) || empty($data['email']))) {
            wp_send_json_error(__('Name and email are required for guest submissions.', 'simpleboards-roadmap'));
        }
        
        // Create the item
        $post_status = get_option('sbir_moderate_submissions') === 'yes' ? 'pending' : 'publish';

        $item_args = array(
            'post_title' => $data['title'],
            'post_content' => $data['description'],
            'post_type' => 'sbir_item',
            'post_status' => $post_status
        );

        if (is_user_logged_in()) {
            $item_args['post_author'] = get_current_user_id();
        } else {
            // Guest ideas need a valid author so WP queries, caches, and author
            // lookups work. Fall back to the board owner, then to any admin user.
            $fallback_author = (int) get_post_field('post_author', (int) $data['board_id']);
            if ($fallback_author <= 0) {
                $admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
                if (!empty($admins)) {
                    $fallback_author = (int) $admins[0];
                }
            }
            if ($fallback_author > 0) {
                $item_args['post_author'] = $fallback_author;
            }
        }

        $item_id = wp_insert_post($item_args);

        if (is_wp_error($item_id)) {
            wp_send_json_error(__('Failed to submit idea. Please try again.', 'simpleboards-roadmap'));
        }

        // Set meta data
        update_post_meta($item_id, '_sbir_board_id', $data['board_id']);
        update_post_meta($item_id, '_sbir_is_roadmap', 'no');

        // Store guest attribution so we can show the submitter name on cards.
        if (!is_user_logged_in()) {
            if (!empty($data['name'])) {
                update_post_meta($item_id, '_sbir_guest_name', $data['name']);
            }
            if (!empty($data['email'])) {
                update_post_meta($item_id, '_sbir_guest_email', $data['email']);
            }
        }
        
        // Set category if provided
        if (!empty($data['category'])) {
            wp_set_object_terms($item_id, array($data['category']), 'sbir_category');
        }
        
        // Generate item number
        sbir_get_item_number($item_id);
        
        // Send notification
        sbir_send_notification('new_submission', array(
            'title' => $data['title'],
            'description' => $data['description'],
            'name' => !empty($data['name']) ? $data['name'] : get_the_author_meta('display_name', get_current_user_id()),
            'email' => !empty($data['email']) ? $data['email'] : get_the_author_meta('user_email', get_current_user_id())
        ));
        
        do_action('sbir_after_idea_submission', $item_id, $data);
        
        $message = $post_status === 'pending' 
            ? __('Thank you! Your idea has been submitted and is awaiting moderation.', 'simpleboards-roadmap')
            : __('Thank you! Your idea has been submitted successfully.', 'simpleboards-roadmap');
        
        wp_send_json_success(array(
            'message' => $message,
            'item_id' => $item_id
        ));
    }

    /**
     * Handle roadmap item creation from frontend (editors only).
     */
    public function handle_roadmap_creation() {
        check_ajax_referer('sbir_public_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'simpleboards-roadmap')));
        }

        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
        $board_id = isset($_POST['board_id']) ? absint(wp_unslash($_POST['board_id'])) : 0;
        $status_id = isset($_POST['status_id']) ? absint(wp_unslash($_POST['status_id'])) : 0;
        $category_id = isset($_POST['category']) ? absint(wp_unslash($_POST['category'])) : 0;
        $deadline = isset($_POST['deadline']) ? sanitize_text_field(wp_unslash($_POST['deadline'])) : '';

        if (!$title || !$board_id) {
            wp_send_json_error(array('message' => __('Please fill in all required fields.', 'simpleboards-roadmap')));
        }

        $board = get_post($board_id);
        if (!$board || $board->post_type !== 'sbir_board') {
            wp_send_json_error(array('message' => __('Invalid board.', 'simpleboards-roadmap')));
        }

        if (!sbir_current_user_can_access_board((int) $board_id, 'add_roadmap_item')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'simpleboards-roadmap')));
        }

        $item_id = wp_insert_post(array(
            'post_title'   => $title,
            'post_content' => $description,
            'post_type'    => 'sbir_item',
            'post_status'  => 'publish',
            'post_author'  => get_current_user_id(),
        ));

        if (is_wp_error($item_id)) {
            wp_send_json_error(array('message' => __('Failed to create item.', 'simpleboards-roadmap')));
        }

        update_post_meta($item_id, '_sbir_board_id', $board_id);
        update_post_meta($item_id, '_sbir_is_roadmap', 'yes');

        if ($status_id) {
            $term = get_term($status_id, 'sbir_status');
            if ($term && !is_wp_error($term)) {
                $belongs_to = (int) get_term_meta($term->term_id, '_sbir_status_board', true);
                if ($belongs_to === 0 || $belongs_to === (int) $board_id) {
                    wp_set_object_terms($item_id, array((int) $status_id), 'sbir_status', false);
                }
            }
        }

        if ($category_id) {
            $cat_term = get_term($category_id, 'sbir_category');
            if ($cat_term && !is_wp_error($cat_term)) {
                wp_set_object_terms($item_id, array((int) $category_id), 'sbir_category', false);
            }
        }

        if ($deadline) {
            $deadline_ts = strtotime($deadline);
            if ($deadline_ts) {
                update_post_meta($item_id, '_sbir_deadline', gmdate('Y-m-d', $deadline_ts));
            }
        }

        sbir_get_item_number($item_id);

        do_action('sbir_after_create_roadmap_item_front', (int) $item_id, isset($_POST) && is_array($_POST) ? wp_unslash($_POST) : array()); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- request already nonce-validated above.

        wp_send_json_success(array(
            'message' => __('Roadmap item created.', 'simpleboards-roadmap'),
            'item_id' => $item_id
        ));
    }

    /**
     * Handle roadmap item update from drawer edit form.
     */
    public function handle_update_roadmap_item() {
        check_ajax_referer('sbir_public_nonce', 'nonce');

        $item_id = isset($_POST['item_id']) ? absint(wp_unslash($_POST['item_id'])) : 0;
        if (!$item_id || !current_user_can('edit_post', $item_id)) {
            wp_send_json_error(array('message' => __('Unauthorized', 'simpleboards-roadmap')));
        }

        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
        $status_id = isset($_POST['status_id']) ? absint(wp_unslash($_POST['status_id'])) : 0;
        $category_id = isset($_POST['category']) ? absint(wp_unslash($_POST['category'])) : 0;
        $deadline = isset($_POST['deadline']) ? sanitize_text_field(wp_unslash($_POST['deadline'])) : '';

        if (!$title) {
            wp_send_json_error(array('message' => __('Please fill in all required fields.', 'simpleboards-roadmap')));
        }

        $post = get_post($item_id);
        if (!$post || $post->post_type !== 'sbir_item') {
            wp_send_json_error(array('message' => __('Invalid item.', 'simpleboards-roadmap')));
        }

        // Read the current type BEFORE touching the post. The endpoint is
        // historically named `sbir_update_roadmap_item` but the drawer reuses
        // it for both roadmap items and ideas, so we must preserve whichever
        // type the item already is. Unconditionally setting 'yes' here was
        // silently promoting ideas to roadmap on every drawer autosave, which
        // made them vanish from the Ideas tab. Explicit promotion lives in
        // `handle_move_to_roadmap()`.
        $current_is_roadmap = get_post_meta($item_id, '_sbir_is_roadmap', true) === 'yes';

        wp_update_post(array(
            'ID' => $item_id,
            'post_title' => $title,
            'post_content' => $description,
        ));

        $board_id = (int) get_post_meta($item_id, '_sbir_board_id', true);
        if ($board_id > 0 && !sbir_current_user_can_access_board($board_id, 'update_roadmap_item')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'simpleboards-roadmap')));
        }

        // Status only applies to roadmap items. For ideas, skip the term
        // assignment entirely; the `enforce_status_only_for_roadmap` save
        // hook would clear it anyway, but skipping the write keeps the
        // intent explicit and saves a query.
        if ($current_is_roadmap) {
            if ($status_id) {
                $term = get_term($status_id, 'sbir_status');
                if ($term && !is_wp_error($term)) {
                    $belongs_to = (int) get_term_meta($term->term_id, '_sbir_status_board', true);
                    if ($belongs_to === 0 || $belongs_to === $board_id) {
                        wp_set_object_terms($item_id, array((int) $status_id), 'sbir_status', false);
                    }
                }
            } else {
                wp_set_object_terms($item_id, array(), 'sbir_status', false);
            }
        }

        if ($category_id) {
            $cat_term = get_term($category_id, 'sbir_category');
            if ($cat_term && !is_wp_error($cat_term)) {
                wp_set_object_terms($item_id, array((int) $category_id), 'sbir_category', false);
            }
        } else {
            wp_set_object_terms($item_id, array(), 'sbir_category', false);
        }

        if ($deadline) {
            $deadline_ts = strtotime($deadline);
            if ($deadline_ts) {
                update_post_meta($item_id, '_sbir_deadline', gmdate('Y-m-d', $deadline_ts));
            }
        } else {
            delete_post_meta($item_id, '_sbir_deadline');
        }

        /**
         * Allow extensions to persist additional frontend drawer edit fields.
         *
         * @param int   $item_id Item ID.
         * @param array $request Raw request payload.
         */
        do_action('sbir_after_update_roadmap_item_front', (int) $item_id, isset($_POST) && is_array($_POST) ? wp_unslash($_POST) : array()); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- request already nonce-validated above.

        wp_send_json_success(array(
            'message' => __('Roadmap item updated.', 'simpleboards-roadmap'),
            'item_id' => $item_id
        ));
    }

    /**
     * Handle move idea to roadmap from drawer.
     */
    public function handle_move_to_roadmap() {
        check_ajax_referer('sbir_public_nonce', 'nonce');

        $item_id = isset($_POST['item_id']) ? absint(wp_unslash($_POST['item_id'])) : 0;
        $status_id = isset($_POST['status_id']) ? absint(wp_unslash($_POST['status_id'])) : 0;
        $category_id = isset($_POST['category']) ? absint(wp_unslash($_POST['category'])) : 0;
        if (!$item_id || !current_user_can('edit_post', $item_id)) {
            wp_send_json_error(array('message' => __('Unauthorized', 'simpleboards-roadmap')));
        }

        $post = get_post($item_id);
        if (!$post || $post->post_type !== 'sbir_item') {
            wp_send_json_error(array('message' => __('Invalid item.', 'simpleboards-roadmap')));
        }

        update_post_meta($item_id, '_sbir_is_roadmap', 'yes');
        $board_id = (int) get_post_meta($item_id, '_sbir_board_id', true);
        if ($board_id > 0 && !sbir_current_user_can_access_board($board_id, 'move_to_roadmap')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'simpleboards-roadmap')));
        }

        if ($status_id) {
            $term = get_term($status_id, 'sbir_status');
            if ($term && !is_wp_error($term)) {
                $belongs_to = (int) get_term_meta($term->term_id, '_sbir_status_board', true);
                if ($belongs_to === 0 || $belongs_to === $board_id) {
                    wp_set_object_terms($item_id, array((int) $status_id), 'sbir_status', false);
                }
            }
        } else {
            wp_set_object_terms($item_id, array(), 'sbir_status', false);
        }

        if ($category_id) {
            $cat_term = get_term($category_id, 'sbir_category');
            if ($cat_term && !is_wp_error($cat_term)) {
                wp_set_object_terms($item_id, array((int) $category_id), 'sbir_category', false);
            }
        } else {
            wp_set_object_terms($item_id, array(), 'sbir_category', false);
        }

        wp_send_json_success(array('message' => __('Moved to roadmap.', 'simpleboards-roadmap')));
    }
}