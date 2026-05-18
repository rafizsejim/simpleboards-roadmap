<?php
/**
 * Custom post types, taxonomies, and permalinks.
 *
 * @package SimpleBoards_Roadmap
 */
if (!defined('ABSPATH')) {
    exit;
}

class SBIR_Post_Types {

    /**
     * Register post types, taxonomies, and rewrite rules.
     */
    public function init() {
        add_action('init', array($this, 'register_post_types'));
        add_action('init', array($this, 'register_taxonomies'));
        add_action('init', array($this, 'register_taxonomies_extra_fields'));
        add_filter('query_vars', array($this, 'register_query_vars'));
        add_filter('use_block_editor_for_post_type', array($this, 'disable_block_editor_for_plugin_cpts'), 10, 2);
        add_filter('wp_insert_post_data', array($this, 'ensure_item_comments_open_on_save'), 10, 2);
        add_filter('post_type_link', array($this, 'custom_permalinks'), 10, 2);
        add_action('pre_get_posts', array($this, 'modify_queries'));
        add_action('init', array($this, 'add_rewrite_rules'), 11);
        
        // Admin: add Board column to Status taxonomy list table
        add_filter('manage_edit-sbir_status_columns', array($this, 'status_columns'));
        add_filter('manage_sbir_status_custom_column', array($this, 'render_status_columns'), 10, 3);

        // Enforce: ideas (non-roadmap) must not have a status
        add_action('save_post_sbir_item', array($this, 'enforce_status_only_for_roadmap'), 20, 3);
    }

    /**
     * Register custom rewrite rules for board item deep-links.
     */
    public function add_rewrite_rules() {
        $base = sbir_get_permalink_base();
        $tab_slugs = $this->get_public_tab_slugs();
        $tab_regex = implode('|', array_map(function($slug) {
            return preg_quote($slug, '/');
        }, $tab_slugs));
        if ($tab_regex === '') {
            $tab_regex = 'roadmap|ideas';
        }
        // Pretty board tab link:
        // /{base}/{board-slug}/{tab-slug}
        add_rewrite_rule(
            '^' . preg_quote($base, '/') . '/([^/]+)/(' . $tab_regex . ')/?$',
            'index.php?post_type=sbir_board&name=$matches[1]&sbir_tab=$matches[2]',
            'top'
        );
        // Pretty item deep-link inside board:
        // /{base}/{board-slug}/{tab-slug}/{item-slug}
        add_rewrite_rule(
            '^' . preg_quote($base, '/') . '/([^/]+)/(' . $tab_regex . ')/([^/]+)/?$',
            'index.php?post_type=sbir_board&name=$matches[1]&sbir_tab=$matches[2]&sbir_item_slug=$matches[3]',
            'top'
        );
    }

    /**
     * Get public board tab slugs used for pretty rewrites.
     *
     * @return string[]
     */
    private function get_public_tab_slugs() {
        $tab_slugs = apply_filters('sbir_public_board_tabs', array('roadmap', 'ideas'));
        if (!is_array($tab_slugs)) {
            return array('roadmap', 'ideas');
        }

        $tab_slugs = array_values(array_unique(array_filter(array_map('sanitize_key', $tab_slugs))));
        if (empty($tab_slugs)) {
            return array('roadmap', 'ideas');
        }

        return $tab_slugs;
    }

    /**
     * Register custom query vars used by pretty board item links.
     *
     * @param array $vars Public query vars.
     * @return array
     */
    public function register_query_vars($vars) {
        $vars[] = 'sbir_tab';
        $vars[] = 'sbir_item_slug';

        return $vars;
    }
    
    /**
     * Register custom post types
     */
    public function register_post_types() {
        // Determine base slug and avoid conflicts with existing pages
        $base_slug = sbir_get_permalink_base();
        $conflict_page = function_exists('get_page_by_path') ? get_page_by_path($base_slug) : null;
        if ($conflict_page) {
            $base_slug = 'boards';
            if (get_option('sbir_permalink_base') !== 'boards') {
                update_option('sbir_permalink_base', 'boards');
                flush_rewrite_rules();
            }
        }

        // Register Board CPT
        $board_labels = array(
            'name' => __('Boards', 'simpleboards-roadmap'),
            'singular_name' => __('Board', 'simpleboards-roadmap'),
            'add_new' => __('Add New Board', 'simpleboards-roadmap'),
            'add_new_item' => __('Add New Board', 'simpleboards-roadmap'),
            'edit_item' => __('Edit Board', 'simpleboards-roadmap'),
            'new_item' => __('New Board', 'simpleboards-roadmap'),
            'view_item' => __('View Board', 'simpleboards-roadmap'),
            'search_items' => __('Search Boards', 'simpleboards-roadmap'),
            'not_found' => __('No boards found', 'simpleboards-roadmap'),
            'not_found_in_trash' => __('No boards found in Trash', 'simpleboards-roadmap'),
            'menu_name' => __('Boards', 'simpleboards-roadmap')
        );
        
        $board_args = array(
            'labels' => $board_labels,
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_menu' => false, 
            'query_var' => true,
            'rewrite' => array(
                'slug' => $base_slug,
                'with_front' => false
            ),
            'capability_type' => 'post',
            'has_archive' => true,
            'hierarchical' => false,
            'menu_position' => null,
            'supports' => array('title', 'editor', 'thumbnail'),
            'show_in_rest' => true
        );
        
        register_post_type('sbir_board', apply_filters('sbir_board_post_type_args', $board_args));
        
        // Register Item CPT
        $item_labels = array(
            'name' => __('Items', 'simpleboards-roadmap'),
            'singular_name' => __('Item', 'simpleboards-roadmap'),
            'add_new' => __('Add New Item', 'simpleboards-roadmap'),
            'add_new_item' => __('Add New Item', 'simpleboards-roadmap'),
            'edit_item' => __('Edit Item', 'simpleboards-roadmap'),
            'new_item' => __('New Item', 'simpleboards-roadmap'),
            'view_item' => __('View Item', 'simpleboards-roadmap'),
            'search_items' => __('Search Items', 'simpleboards-roadmap'),
            'not_found' => __('No items found', 'simpleboards-roadmap'),
            'not_found_in_trash' => __('No items found in Trash', 'simpleboards-roadmap'),
            'menu_name' => __('Items', 'simpleboards-roadmap')
        );
        
        $item_args = array(
            'labels' => $item_labels,
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_menu' => false,
            'query_var' => true,
            'rewrite' => array(
                'slug' => $base_slug . '/item',
                'with_front' => false
            ),
            'capability_type' => 'post',
            'has_archive' => false,
            'hierarchical' => false,
            'menu_position' => null,
            'supports' => array('title', 'editor', 'comments'),
            'show_in_rest' => true
        );
        
        register_post_type('sbir_item', apply_filters('sbir_item_post_type_args', $item_args));
    }
    
    /**
     * Register taxonomies
     */
    public function register_taxonomies() {
        // Register Category taxonomy
        $category_labels = array(
            'name' => __('Categories', 'simpleboards-roadmap'),
            'singular_name' => __('Category', 'simpleboards-roadmap'),
            'search_items' => __('Search Categories', 'simpleboards-roadmap'),
            'all_items' => __('All Categories', 'simpleboards-roadmap'),
            'edit_item' => __('Edit Category', 'simpleboards-roadmap'),
            'update_item' => __('Update Category', 'simpleboards-roadmap'),
            'add_new_item' => __('Add New Category', 'simpleboards-roadmap'),
            'new_item_name' => __('New Category Name', 'simpleboards-roadmap'),
            'menu_name' => __('Categories', 'simpleboards-roadmap'),
            'back_to_items' => __('← Go to Categories', 'simpleboards-roadmap'),
            'not_found' => __('No categories found.', 'simpleboards-roadmap'),
            'no_terms' => __('No categories', 'simpleboards-roadmap'),
            'items_list' => __('Categories list', 'simpleboards-roadmap'),
            'items_list_navigation' => __('Categories list navigation', 'simpleboards-roadmap'),
        );
        
        $category_args = array(
            'labels' => $category_labels,
            'hierarchical' => true,
            'public' => true,
            'show_ui' => true,
            'show_admin_column' => true,
            'query_var' => true,
            'rewrite' => array('slug' => 'item-category'),
            // Hide default block-editor taxonomy panel to avoid duplication
            'show_in_rest' => false,
            // Hide default panel; we'll render inside Item Settings
            'meta_box_cb' => false
        );
        
        register_taxonomy('sbir_category', 'sbir_item', apply_filters('sbir_category_taxonomy_args', $category_args));
        
        // Register Status taxonomy
        $status_labels = array(
            'name' => __('Statuses', 'simpleboards-roadmap'),
            'singular_name' => __('Status', 'simpleboards-roadmap'),
            'search_items' => __('Search Statuses', 'simpleboards-roadmap'),
            'all_items' => __('All Statuses', 'simpleboards-roadmap'),
            'edit_item' => __('Edit Status', 'simpleboards-roadmap'),
            'update_item' => __('Update Status', 'simpleboards-roadmap'),
            'add_new_item' => __('Add New Status', 'simpleboards-roadmap'),
            'new_item_name' => __('New Status Name', 'simpleboards-roadmap'),
            'menu_name' => __('Statuses', 'simpleboards-roadmap'),
            // Without these, WP falls back to generic tag strings — e.g. the
            // post-edit confirmation links read "← Go to Tags" instead of
            // "← Go to Statuses".
            'back_to_items' => __('← Go to Statuses', 'simpleboards-roadmap'),
            'not_found' => __('No statuses found.', 'simpleboards-roadmap'),
            'no_terms' => __('No statuses', 'simpleboards-roadmap'),
            'items_list' => __('Statuses list', 'simpleboards-roadmap'),
            'items_list_navigation' => __('Statuses list navigation', 'simpleboards-roadmap'),
            'parent_item' => null,
            'parent_item_colon' => null,
        );
        
        $status_args = array(
            'labels' => $status_labels,
            'hierarchical' => false,
            'public' => true,
            'show_ui' => true,
            'show_admin_column' => true,
            'query_var' => true,
            'rewrite' => array('slug' => 'item-status'),
            // Hide default taxonomy panel; status will be managed in Item Settings meta box
            'show_in_rest' => false,
            'meta_box_cb' => false
        );
        
        register_taxonomy('sbir_status', 'sbir_item', apply_filters('sbir_status_taxonomy_args', $status_args));
    }

    /**
     * Keep board/item authoring in classic editor for low-friction admin UX.
     *
     * @param bool   $use_block_editor Whether to use block editor.
     * @param string $post_type        Current post type.
     * @return bool
     */
    public function disable_block_editor_for_plugin_cpts($use_block_editor, $post_type) {
        if (in_array($post_type, array('sbir_board', 'sbir_item'), true)) {
            return false;
        }

        return $use_block_editor;
    }

    /**
     * Keep discussion enabled for roadmap items.
     *
     * @param array $data    Sanitized post data.
     * @param array $postarr Raw post array.
     * @return array
     */
    public function ensure_item_comments_open_on_save($data, $postarr) {
        if (isset($data['post_type']) && $data['post_type'] === 'sbir_item') {
            $data['comment_status'] = 'open';
        }

        return $data;
    }
    
    // Removed unused status_meta_box; status selection is handled in Item Settings meta box

    /**
     * Add board select when creating/editing a Status term
     */
    public function register_taxonomies_extra_fields() {
        add_action('sbir_status_add_form_fields', array($this, 'status_add_fields'));
        add_action('sbir_status_edit_form_fields', array($this, 'status_edit_fields'));
        add_action('created_sbir_status', array($this, 'save_status_fields'));
        add_action('edited_sbir_status', array($this, 'save_status_fields'));

        // Categories: same Board + Color contract as statuses.
        add_action('sbir_category_add_form_fields', array($this, 'category_add_fields'));
        add_action('sbir_category_edit_form_fields', array($this, 'category_edit_fields'));
        add_action('created_sbir_category', array($this, 'save_category_fields'));
        add_action('edited_sbir_category', array($this, 'save_category_fields'));
    }

    public function status_add_fields() {
        $boards = sbir_get_boards_list(200);
        ?>
        <div class="form-field">
            <?php wp_nonce_field('sbir_save_status_fields', 'sbir_status_nonce'); ?>
            <label for="sbir_status_board"><?php esc_html_e('Belongs to Board', 'simpleboards-roadmap'); ?></label>
            <select name="sbir_status_board" id="sbir_status_board">
                <option value=""><?php esc_html_e('All Boards (global)', 'simpleboards-roadmap'); ?></option>
                <?php foreach ($boards as $board) : ?>
                    <option value="<?php echo esc_attr($board->ID); ?>"><?php echo esc_html($board->post_title); ?></option>
                <?php endforeach; ?>
            </select>
            <p class="description"><?php esc_html_e('Associate this status with a specific board (optional).', 'simpleboards-roadmap'); ?></p>
        </div>
        <div class="form-field">
            <label for="sbir_status_color"><?php esc_html_e('Status Color', 'simpleboards-roadmap'); ?></label>
            <input type="color" name="sbir_status_color" id="sbir_status_color" value="#94a3b8" />
            <p class="description"><?php esc_html_e('Choose the color shown for this status on the board.', 'simpleboards-roadmap'); ?></p>
        </div>
        <div class="form-field">
            <label for="sbir_status_released">
                <input type="checkbox" name="sbir_status_released" id="sbir_status_released" value="yes">
                <?php esc_html_e('Items in this status are released', 'simpleboards-roadmap'); ?>
            </label>
            <p class="description"><?php esc_html_e('The date label shows as Released instead of Due.', 'simpleboards-roadmap'); ?></p>
        </div>
        <?php
    }

    public function status_edit_fields($term) {
        $boards = $this->get_boards_for_status_fields();
        $value = get_term_meta($term->term_id, '_sbir_status_board', true);
        $color = get_term_meta($term->term_id, '_sbir_status_color', true);
        $released = get_term_meta($term->term_id, '_sbir_status_released', true) === 'yes';

        // (Server-rendered impact notice removed; handled live via AJAX on change.)
        ?>
        <input type="hidden" id="sbir_status_term_id" value="<?php echo esc_attr($term->term_id); ?>" />
        <tr class="form-field">
            <th scope="row"><label for="sbir_status_board"><?php esc_html_e('Belongs to Board', 'simpleboards-roadmap'); ?></label></th>
            <td>
                <?php wp_nonce_field('sbir_save_status_fields', 'sbir_status_nonce'); ?>
                <select name="sbir_status_board" id="sbir_status_board">
                    <option value=""><?php esc_html_e('All Boards (global)', 'simpleboards-roadmap'); ?></option>
                    <?php foreach ($boards as $board) : ?>
                        <option value="<?php echo esc_attr($board->ID); ?>" <?php selected($value, $board->ID); ?>><?php echo esc_html($board->post_title); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php esc_html_e('Associate this status with a specific board (optional).', 'simpleboards-roadmap'); ?></p>
                
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="sbir_status_color"><?php esc_html_e('Status Color', 'simpleboards-roadmap'); ?></label></th>
            <td>
                <input type="color" name="sbir_status_color" id="sbir_status_color" value="<?php echo esc_attr($color ? $color : '#94a3b8'); ?>" />
                <p class="description"><?php esc_html_e('Choose the color shown for this status on the board.', 'simpleboards-roadmap'); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><?php esc_html_e('Release stage', 'simpleboards-roadmap'); ?></th>
            <td>
                <label for="sbir_status_released">
                    <input type="checkbox" name="sbir_status_released" id="sbir_status_released" value="yes" <?php checked($released); ?>>
                    <?php esc_html_e('Items in this status are released', 'simpleboards-roadmap'); ?>
                </label>
                <p class="description"><?php esc_html_e('The date label shows as Released instead of Due.', 'simpleboards-roadmap'); ?></p>
            </td>
        </tr>
        <?php
    }

    public function save_status_fields($term_id) {
        $term_id = absint($term_id);
        if (!$term_id) {
            return;
        }
        // Capability check (manage terms for this taxonomy)
        $tax_obj = get_taxonomy('sbir_status');
        $manage_cap = is_object($tax_obj) && isset($tax_obj->cap->manage_terms) ? $tax_obj->cap->manage_terms : 'manage_categories';
        if (!current_user_can($manage_cap)) {
            return;
        }

        // Nonce check for origin validation
        if (!isset($_POST['sbir_status_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['sbir_status_nonce'])), 'sbir_save_status_fields')) {
            return;
        }

        $old_board_id = (int) get_term_meta($term_id, '_sbir_status_board', true);
        $new_board_id = isset($_POST['sbir_status_board']) ? absint(wp_unslash($_POST['sbir_status_board'])) : 0;

        // Update board assignment meta
        if ($new_board_id) {
            update_term_meta($term_id, '_sbir_status_board', $new_board_id);
        } else {
            delete_term_meta($term_id, '_sbir_status_board');
        }

        // If restricting to a specific board (or changing board), unassign this status from items on other boards
        if ($new_board_id && $new_board_id !== $old_board_id) {
            $affected_board_ids = array();
            $batch_size = (int) apply_filters('sbir_status_reassign_batch_size', 200);
            if ($batch_size < 1) {
                $batch_size = 200;
            }

            // Re-query first page each time because each iteration mutates the term relationships.
            while (true) {
                $items_query = new WP_Query(array(
                    'post_type' => 'sbir_item',
                    'post_status' => SBIR_Query_Helper::get_valid_post_statuses(),
                    'posts_per_page' => $batch_size,
                    'fields' => 'ids',
                    'no_found_rows' => true,
                    'tax_query' => array(
                        array('taxonomy' => 'sbir_status', 'field' => 'term_id', 'terms' => $term_id)
                    ),
                    'meta_query' => array(
                        array('key' => '_sbir_is_roadmap', 'value' => 'yes', 'compare' => '=')
                    ),
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                ));

                if (empty($items_query->posts)) {
                    break;
                }

                foreach ($items_query->posts as $pid) {
                    $item_board = (int) get_post_meta($pid, '_sbir_board_id', true);
                    if ($item_board && $item_board !== $new_board_id) {
                        wp_remove_object_terms($pid, $term_id, 'sbir_status');
                        $affected_board_ids[$item_board] = true;
                    }
                }
            }

        }

        if (isset($_POST['sbir_status_color'])) {
            $color = sanitize_hex_color(wp_unslash($_POST['sbir_status_color']));
            if ($color) {
                update_term_meta($term_id, '_sbir_status_color', $color);
            } else {
                delete_term_meta($term_id, '_sbir_status_color');
            }
        }

        if (isset($_POST['sbir_status_released']) && wp_unslash($_POST['sbir_status_released']) === 'yes') {
            update_term_meta($term_id, '_sbir_status_released', 'yes');
        } else {
            delete_term_meta($term_id, '_sbir_status_released');
        }
    }

    /**
     * Render Add Category form fields (Board + Color).
     */
    public function category_add_fields() {
        $boards = sbir_get_boards_list(200);
        ?>
        <div class="form-field">
            <?php wp_nonce_field('sbir_save_category_fields', 'sbir_category_nonce'); ?>
            <label for="sbir_category_board"><?php esc_html_e('Belongs to Board', 'simpleboards-roadmap'); ?></label>
            <select name="sbir_category_board" id="sbir_category_board">
                <option value=""><?php esc_html_e('All Boards (global)', 'simpleboards-roadmap'); ?></option>
                <?php foreach ($boards as $board) : ?>
                    <option value="<?php echo esc_attr($board->ID); ?>"><?php echo esc_html($board->post_title); ?></option>
                <?php endforeach; ?>
            </select>
            <p class="description"><?php esc_html_e('Associate this category with a specific board (optional).', 'simpleboards-roadmap'); ?></p>
        </div>
        <div class="form-field">
            <label for="sbir_category_color"><?php esc_html_e('Category Color', 'simpleboards-roadmap'); ?></label>
            <input type="color" name="sbir_category_color" id="sbir_category_color" value="#94a3b8" />
            <p class="description"><?php esc_html_e('Choose the color shown for this category chip on cards.', 'simpleboards-roadmap'); ?></p>
        </div>
        <?php
    }

    /**
     * Render Edit Category form fields (Board + Color).
     */
    public function category_edit_fields($term) {
        $boards = $this->get_boards_for_status_fields();
        $value = get_term_meta($term->term_id, '_sbir_category_board', true);
        $color = get_term_meta($term->term_id, '_sbir_category_color', true);
        ?>
        <tr class="form-field">
            <th scope="row"><label for="sbir_category_board"><?php esc_html_e('Belongs to Board', 'simpleboards-roadmap'); ?></label></th>
            <td>
                <?php wp_nonce_field('sbir_save_category_fields', 'sbir_category_nonce'); ?>
                <select name="sbir_category_board" id="sbir_category_board">
                    <option value=""><?php esc_html_e('All Boards (global)', 'simpleboards-roadmap'); ?></option>
                    <?php foreach ($boards as $board) : ?>
                        <option value="<?php echo esc_attr($board->ID); ?>" <?php selected($value, $board->ID); ?>><?php echo esc_html($board->post_title); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php esc_html_e('Associate this category with a specific board (optional).', 'simpleboards-roadmap'); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="sbir_category_color"><?php esc_html_e('Category Color', 'simpleboards-roadmap'); ?></label></th>
            <td>
                <input type="color" name="sbir_category_color" id="sbir_category_color" value="<?php echo esc_attr($color ? $color : '#94a3b8'); ?>" />
                <p class="description"><?php esc_html_e('Choose the color shown for this category chip on cards.', 'simpleboards-roadmap'); ?></p>
            </td>
        </tr>
        <?php
    }

    /**
     * Persist Category Board + Color meta.
     */
    public function save_category_fields($term_id) {
        $term_id = absint($term_id);
        if (!$term_id) {
            return;
        }
        $tax_obj = get_taxonomy('sbir_category');
        $manage_cap = is_object($tax_obj) && isset($tax_obj->cap->manage_terms) ? $tax_obj->cap->manage_terms : 'manage_categories';
        if (!current_user_can($manage_cap)) {
            return;
        }
        if (!isset($_POST['sbir_category_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['sbir_category_nonce'])), 'sbir_save_category_fields')) {
            return;
        }

        $new_board_id = isset($_POST['sbir_category_board']) ? absint(wp_unslash($_POST['sbir_category_board'])) : 0;
        if ($new_board_id) {
            update_term_meta($term_id, '_sbir_category_board', $new_board_id);
        } else {
            delete_term_meta($term_id, '_sbir_category_board');
        }

        if (isset($_POST['sbir_category_color'])) {
            $color = sanitize_hex_color(wp_unslash($_POST['sbir_category_color']));
            if ($color) {
                update_term_meta($term_id, '_sbir_category_color', $color);
            } else {
                delete_term_meta($term_id, '_sbir_category_color');
            }
        }
    }

    /**
     * Load boards for status fields in batched queries.
     */
    private function get_boards_for_status_fields() {
        $boards = array();
        $page = 1;
        $per_page = (int) apply_filters('sbir_status_boards_batch_size', 200);
        if ($per_page < 1) {
            $per_page = 200;
        }

        while (true) {
            $batch = get_posts(array(
                'post_type' => 'sbir_board',
                'posts_per_page' => $per_page,
                'paged' => $page,
                'post_status' => array('publish', 'private'),
                'orderby' => 'title',
                'order' => 'ASC',
                'no_found_rows' => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ));

            if (empty($batch)) {
                break;
            }

            $boards = array_merge($boards, $batch);
            if (count($batch) < $per_page) {
                break;
            }

            $page++;
        }

        return $boards;
    }
    
    /**
     * Custom permalinks for hierarchical structure
     */
    public function custom_permalinks($post_link, $post) {
        if ($post->post_type === 'sbir_item') {
            $board_id = (int) get_post_meta($post->ID, '_sbir_board_id', true);
            if ($board_id) {
                $board_url = get_permalink($board_id);

                if ($board_url) {
                    $tab = get_post_meta($post->ID, '_sbir_is_roadmap', true) === 'yes' ? 'roadmap' : 'ideas';
                    $post_link = user_trailingslashit(
                        trailingslashit($board_url) . $tab . '/' . $post->post_name
                    );
                }
            }
        }

        return $post_link;
    }
    
    /**
     * Modify queries for proper hierarchy
     */
    public function modify_queries($query) {
        if (!is_admin() && $query->is_main_query()) {
            if ($query->is_post_type_archive('sbir_board')) {
                $query->set('posts_per_page', 12);
            }
        }
    }
    
    
    /**
     * Add Board column for Status taxonomy list
     */
    public function status_columns($columns) {
        $new = array();
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'name') {
                $new['sbir_status_board'] = __('Board', 'simpleboards-roadmap');
            }
        }
        return $new;
    }

    public function render_status_columns($content, $column, $term_id) {
        if ($column === 'sbir_status_board') {
            $board_id = get_term_meta($term_id, '_sbir_status_board', true);
            if ($board_id) {
                $board = get_post($board_id);
                if ($board) { $content = esc_html($board->post_title); }
            } else {
                $content = __('All Boards', 'simpleboards-roadmap');
            }
        }
        return $content;
    }
    
    
    /**
     * Ensure ideas (non-roadmap) do not retain a status term.
     */
    public function enforce_status_only_for_roadmap($post_id, $post, $update) {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) { return; }
        if ($post->post_type !== 'sbir_item') { return; }
        $is_roadmap = get_post_meta($post_id, '_sbir_is_roadmap', true) === 'yes';
        if (!$is_roadmap) {
            // Clear any status terms assigned accidentally
            wp_set_object_terms($post_id, array(), 'sbir_status', false);
        }
    }
}