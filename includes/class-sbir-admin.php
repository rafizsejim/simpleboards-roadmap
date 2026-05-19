<?php
/**
 * Admin functionality for boards, items, and settings.
 *
 * @package SimpleBoards_Roadmap
 */
if (!defined('ABSPATH')) {
    exit;
}

class SBIR_Admin {
    /**
     * Prevent duplicate bulk-edit custom field rendering per request.
     *
     * @var bool
     */
    private $did_render_item_bulk_edit_fields = false;

    /**
     * Get board setup tabs for the add/edit board screen.
     *
     * @param int  $board_id Board ID when editing.
     * @param bool $is_edit  Whether edit mode is active.
     * @return array<string,string>
     */
    private function get_board_setup_tabs($board_id, $is_edit) {
        $tabs = array(
            'general' => __('General', 'simpleboards-roadmap'),
        );

        // Pro modules hook their own renderers into the actions below. Only
        // surface those tabs in the UI when a renderer is actually registered,
        // otherwise free-only installs see empty tab panels.
        if (has_action('sbir_render_board_workflow_tab_content')) {
            $tabs['workflow-automations'] = __('Workflow Automations', 'simpleboards-roadmap');
        }
        if (has_action('sbir_render_board_design_tab_content')) {
            $tabs['design'] = __('Design', 'simpleboards-roadmap');
        }

        $tabs = apply_filters('sbir_board_setup_tabs', $tabs, (int) $board_id, (bool) $is_edit);
        if (!is_array($tabs) || empty($tabs)) {
            return array('general' => __('General', 'simpleboards-roadmap'));
        }

        $normalized_tabs = array();
        foreach ($tabs as $tab_key => $tab_label) {
            $sanitized_key = sanitize_key((string) $tab_key);
            if ($sanitized_key === '') {
                continue;
            }
            $normalized_tabs[ $sanitized_key ] = is_scalar($tab_label) ? (string) $tab_label : ucwords(str_replace('-', ' ', $sanitized_key));
        }

        if (!isset($normalized_tabs['general'])) {
            $normalized_tabs = array('general' => __('General', 'simpleboards-roadmap')) + $normalized_tabs;
        }

        return $normalized_tabs;
    }
    
    /**
     * Register admin hooks and filters.
     */
    public function init() {
        add_action('admin_menu', array($this, 'add_menu_pages'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_post_sbir_create_board', array($this, 'handle_create_board'));
        add_action('admin_post_sbir_save_item', array($this, 'handle_save_item'));
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('add_meta_boxes', array($this, 'remove_nonessential_meta_boxes'), 20);
        add_action('save_post', array($this, 'save_meta_boxes'));
        add_filter('manage_sbir_item_posts_columns', array($this, 'add_item_columns'));
        add_action('manage_sbir_item_posts_custom_column', array($this, 'render_item_columns'), 10, 2);
        // Bulk-prime vote-count cache for the items list screen to avoid N+1 queries.
        add_filter('the_posts', array($this, 'prime_item_caches_for_admin_list'), 10, 2);
        add_filter('views_edit-sbir_item', array($this, 'add_items_views'));
        add_action('pre_get_posts', array($this, 'filter_items_admin_list'));
        add_action('restrict_manage_posts', array($this, 'render_board_filter_dropdown'), 10, 2);
        add_filter('post_row_actions', array($this, 'add_item_row_actions'), 10, 2);
        add_filter('quick_edit_show_taxonomy', array($this, 'hide_item_status_quick_edit_taxonomy'), 10, 3);
        add_action('admin_init', array($this, 'handle_item_row_action'));
        add_action('admin_init', array($this, 'redirect_default_authoring_screens'));
        add_filter('bulk_actions-edit-sbir_item', array($this, 'add_item_bulk_actions'));
        add_filter('handle_bulk_actions-edit-sbir_item', array($this, 'handle_item_bulk_actions'), 10, 3);
        add_action('bulk_edit_custom_box', array($this, 'render_item_bulk_edit_fields'), 10, 2);
        add_action('load-edit.php', array($this, 'handle_item_bulk_edit_submission'));
        add_filter('manage_sbir_board_posts_columns', array($this, 'add_board_columns'));
        add_action('manage_sbir_board_posts_custom_column', array($this, 'render_board_columns'), 10, 2);
        add_action('wp_ajax_sbir_status_reassign_impact', array($this, 'ajax_status_reassign_impact'));
        add_action('admin_head', array($this, 'hide_setup_submenus_in_sidebar'));
        add_action('admin_head', array($this, 'print_menu_icon_styles'));
        add_filter('parent_file', array($this, 'set_menu_highlight'));
        add_filter('submenu_file', array($this, 'set_submenu_highlight'), 10, 2);
    }
    
    /**
     * Hide default quick-edit taxonomy input for item status.
     *
     * @param bool   $show      Whether taxonomy should be shown.
     * @param string $taxonomy  Taxonomy slug.
     * @param string $post_type Post type slug.
     * @return bool
     */
    public function hide_item_status_quick_edit_taxonomy($show, $taxonomy, $post_type) {
        if ($post_type === 'sbir_item' && $taxonomy === 'sbir_status') {
            return false;
        }

        return $show;
    }
    
    /**
     * Add SimpleBoards menu and submenu pages.
     */
    public function add_menu_pages() {
        $icon = SBIR_PLUGIN_URL . 'admin/images/wp-menu-icon.svg';

        add_menu_page(
            __('SimpleBoards', 'simpleboards-roadmap'),
            __('SimpleBoards', 'simpleboards-roadmap'),
            'manage_options',
            'simpleboards-roadmap',
            array($this, 'render_dashboard'),
            $icon,
            25
        );
        
        // Replace first submenu
        global $submenu;
        $submenu['simpleboards-roadmap'][0] = array(
            __('Boards', 'simpleboards-roadmap'),
            'manage_options',
            'edit.php?post_type=sbir_board'
        );

        // Items
        add_submenu_page(
            'simpleboards-roadmap',
            __('Items', 'simpleboards-roadmap'),
            __('Items', 'simpleboards-roadmap'),
            'manage_options',
            'edit.php?post_type=sbir_item'
        );

        // Statuses (taxonomy)
        add_submenu_page(
            'simpleboards-roadmap',
            __('Statuses', 'simpleboards-roadmap'),
            __('Statuses', 'simpleboards-roadmap'),
            'manage_options',
            'edit-tags.php?taxonomy=sbir_status&post_type=sbir_item'
        );

        // Categories (taxonomy)
        add_submenu_page(
            'simpleboards-roadmap',
            __('Categories', 'simpleboards-roadmap'),
            __('Categories', 'simpleboards-roadmap'),
            'manage_options',
            'edit-tags.php?taxonomy=sbir_category&post_type=sbir_item'
        );
        
        // Settings (handled by SBIR_Settings class)
        add_submenu_page(
            'simpleboards-roadmap',
            __('Settings', 'simpleboards-roadmap'),
            __('Settings', 'simpleboards-roadmap'),
            'manage_options',
            'sbir-settings',
            array('SBIR_Settings', 'render_settings_page')
        );

        // Setup pages: registered under the real parent so WP expands the menu,
        // then removed from the visible submenu array below.
        add_submenu_page(
            'simpleboards-roadmap',
            __('Board Setup', 'simpleboards-roadmap'),
            __('Board Setup', 'simpleboards-roadmap'),
            'manage_options',
            'sbir-add-board',
            array($this, 'render_add_board_page')
        );

        add_submenu_page(
            'simpleboards-roadmap',
            __('Item Setup', 'simpleboards-roadmap'),
            __('Item Setup', 'simpleboards-roadmap'),
            'manage_options',
            'sbir-add-item',
            array($this, 'render_add_item_page')
        );

    }

    /**
     * Render the brand-colored menu icon at full opacity.
     *
     * Why: WordPress dims admin menu icons by default (opacity 0.6 on <img>,
     * mask-image on data-URI SVGs). Without this override the colored SVG
     * either flattens to a grey silhouette or appears washed out.
     */
    public function print_menu_icon_styles() {
        ?>
        <style id="sbir-menu-icon-style">
            #adminmenu li.toplevel_page_simpleboards-roadmap .wp-menu-image img {
                opacity: 1;
                padding: 6px 0 0 0;
                width: 20px;
                height: 20px;
            }
            #adminmenu li.toplevel_page_simpleboards-roadmap:hover .wp-menu-image img,
            #adminmenu li.toplevel_page_simpleboards-roadmap.current .wp-menu-image img,
            #adminmenu li.toplevel_page_simpleboards-roadmap.wp-has-current-submenu .wp-menu-image img {
                opacity: 1;
            }
        </style>
        <?php
    }

    /**
     * Redirect dashboard to Boards list.
     */
    public function render_dashboard() {
        wp_redirect(admin_url('edit.php?post_type=sbir_board'));
        exit;
    }

    /**
     * Render custom Add/Edit Board admin page.
     *
     * @return void
     */
    public function render_add_board_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'simpleboards-roadmap'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing state
        $board_id = isset($_GET['board_id']) ? absint(wp_unslash($_GET['board_id'])) : 0;
        $is_edit = $board_id > 0;
        $board = null;

        if ($is_edit) {
            $board = get_post($board_id);
            if (!$board || $board->post_type !== 'sbir_board') {
                $board_id = 0;
                $is_edit = false;
            }
        }

        $error = '';
        $updated = false;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice string
        if (isset($_GET['sbir_error'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice string
            $error = sanitize_text_field(wp_unslash($_GET['sbir_error']));
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag
        if (isset($_GET['sbir_updated']) && wp_unslash($_GET['sbir_updated']) === '1') {
            $updated = true;
        }

        $title_value = $is_edit ? $board->post_title : '';
        $description_value = $is_edit ? $board->post_content : '';
        $enable_ideas = $is_edit ? get_post_meta($board_id, '_sbir_enable_ideas', true) : 'yes';
        $enable_ideas = $enable_ideas ? $enable_ideas : 'yes';
        $default_tab_choices = sbir_get_default_tab_choices((int) $board_id);
        $default_tab = $is_edit ? (string) get_post_meta($board_id, '_sbir_default_tab', true) : 'roadmap';
        if (!isset($default_tab_choices[$default_tab])) {
            $default_tab = 'roadmap';
        }
        $hide_board_title = $is_edit ? get_post_meta($board_id, '_sbir_hide_board_title', true) : 'yes';
        $hide_board_title = ($hide_board_title === '') ? 'yes' : $hide_board_title;
        $display_roadmap_filter = $is_edit ? (string) get_post_meta($board_id, '_sbir_display_roadmap_filter', true) : 'yes';
        $display_roadmap_filter = ($display_roadmap_filter === '') ? 'yes' : $display_roadmap_filter;
        $display_roadmap_sort = $is_edit ? (string) get_post_meta($board_id, '_sbir_display_roadmap_sort', true) : 'yes';
        $display_roadmap_sort = ($display_roadmap_sort === '') ? 'yes' : $display_roadmap_sort;
        $display_ideas_filter = $is_edit ? (string) get_post_meta($board_id, '_sbir_display_ideas_filter', true) : 'yes';
        $display_ideas_filter = ($display_ideas_filter === '') ? 'yes' : $display_ideas_filter;
        $display_ideas_sort = $is_edit ? (string) get_post_meta($board_id, '_sbir_display_ideas_sort', true) : 'yes';
        $display_ideas_sort = ($display_ideas_sort === '') ? 'yes' : $display_ideas_sort;
        $comments_disabled = $is_edit ? (string) get_post_meta($board_id, '_sbir_board_comments_disabled', true) : 'no';
        $comments_disabled = ($comments_disabled === 'yes') ? 'yes' : 'no';
        $setup_tabs = $this->get_board_setup_tabs($board_id, $is_edit);
        $allowed_setup_tabs = array_keys($setup_tabs);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab state
        $active_setup_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general';
        if (!in_array($active_setup_tab, $allowed_setup_tabs, true)) {
            $active_setup_tab = 'general';
        }
        $shortcode_slug = '';
        if ($is_edit) {
            $shortcode_slug = (string) get_post_field('post_name', $board_id);
        }
        $shortcode_value = $shortcode_slug !== '' ? '[sbir_board product="' . $shortcode_slug . '"]' : '';
        $view_board_url = $is_edit ? get_permalink($board_id) : '';

        ?>
        <div class="wrap sbir-add-board-page">
            <h1>
                <?php echo esc_html($is_edit ? __('Edit Board', 'simpleboards-roadmap') : __('Add Board', 'simpleboards-roadmap')); ?>
                <?php if ($is_edit && $view_board_url) : ?>
                    <a class="page-title-action" href="<?php echo esc_url($view_board_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('View Board', 'simpleboards-roadmap'); ?></a>
                <?php endif; ?>
            </h1>
            <p class="description"><?php esc_html_e('Use this focused form to manage board setup without post-editor clutter.', 'simpleboards-roadmap'); ?></p>

            <?php if ($updated) : ?>
                <div class="notice notice-success"><p><?php esc_html_e('Board saved.', 'simpleboards-roadmap'); ?></p></div>
            <?php endif; ?>

            <?php if ($error !== '') : ?>
                <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sbir-add-board-form">
                <?php wp_nonce_field('sbir_create_board', 'sbir_create_board_nonce'); ?>
                <input type="hidden" name="action" value="sbir_create_board">
                <input type="hidden" name="board_id" value="<?php echo esc_attr($board_id); ?>">
                <input type="hidden" id="sbir_active_tab" name="sbir_active_tab" value="<?php echo esc_attr($active_setup_tab); ?>">

                <table class="form-table sbir-board-top-fields" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="sbir_new_board_title"><?php esc_html_e('Board Name', 'simpleboards-roadmap'); ?></label></th>
                            <td>
                                <input type="text" id="sbir_new_board_title" name="title" class="regular-text" value="<?php echo esc_attr($title_value); ?>" required>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sbir_new_board_description"><?php esc_html_e('Description', 'simpleboards-roadmap'); ?></label></th>
                            <td>
                                <textarea id="sbir_new_board_description" name="description" rows="3" class="large-text"><?php echo esc_textarea($description_value); ?></textarea>
                            </td>
                        </tr>
                        <?php if ($is_edit) : ?>
                        <tr>
                            <th scope="row"><label for="sbir_board_slug"><?php esc_html_e('Shortcode slug', 'simpleboards-roadmap'); ?></label></th>
                            <td>
                                <input type="text" id="sbir_board_slug" name="board_slug" class="regular-text code" value="<?php echo esc_attr($shortcode_slug); ?>" data-original-slug="<?php echo esc_attr($shortcode_slug); ?>">
                                <p class="description"><?php esc_html_e('Shortcode:', 'simpleboards-roadmap'); ?> <code id="sbir_board_shortcode_preview"><?php echo esc_html($shortcode_value); ?></code></p>
                                <p class="description" id="sbir_board_slug_warning" style="color:#b45309;display:none;"><strong><?php esc_html_e('Warning:', 'simpleboards-roadmap'); ?></strong> <?php esc_html_e('Changing the slug updates the shortcode and the board\'s permalink. Pages with the old shortcode will stop displaying this board, and old links will 404.', 'simpleboards-roadmap'); ?></p>
                                <script>
                                    (function(){
                                        var input = document.getElementById('sbir_board_slug');
                                        var preview = document.getElementById('sbir_board_shortcode_preview');
                                        var warning = document.getElementById('sbir_board_slug_warning');
                                        if (!input || !preview) { return; }
                                        var original = input.getAttribute('data-original-slug') || '';
                                        input.addEventListener('input', function(){
                                            var v = input.value.toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
                                            preview.textContent = v ? '[sbir_board product="' + v + '"]' : '';
                                            if (warning) {
                                                warning.style.display = (v !== original) ? '' : 'none';
                                            }
                                        });
                                    })();
                                </script>
                            </td>
                        </tr>
                        <?php else : ?>
                        <tr>
                            <th scope="row"><label for="sbir_board_shortcode"><?php esc_html_e('Shortcode', 'simpleboards-roadmap'); ?></label></th>
                            <td>
                                <input type="text" id="sbir_board_shortcode" class="regular-text code" value="<?php echo esc_attr($shortcode_value); ?>" readonly>
                                <p class="description"><?php esc_html_e('Shortcode preview updates from board name. The slug becomes editable after first save.', 'simpleboards-roadmap'); ?></p>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <h2 class="nav-tab-wrapper sbir-board-setup-tabs" style="margin-bottom:0;">
                    <?php foreach ($setup_tabs as $tab_key => $tab_label) : ?>
                        <a href="<?php echo esc_url(add_query_arg(array('page' => 'sbir-add-board', 'board_id' => $board_id, 'tab' => $tab_key), admin_url('admin.php'))); ?>" class="nav-tab <?php echo $active_setup_tab === $tab_key ? 'nav-tab-active' : ''; ?>" data-tab="<?php echo esc_attr($tab_key); ?>"><?php echo esc_html($tab_label); ?></a>
                    <?php endforeach; ?>
                </h2>

                <div class="sbir-board-tab-panels">
                    <div class="sbir-board-tab-panel <?php echo $active_setup_tab === 'general' ? 'active' : ''; ?>" data-tab-panel="general">
                        <h2><?php esc_html_e('General Settings', 'simpleboards-roadmap'); ?></h2>
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Enable Ideas Tab', 'simpleboards-roadmap'); ?></th>
                                    <td>
                                        <label for="sbir_enable_ideas">
                                            <input type="checkbox" id="sbir_enable_ideas" name="sbir_enable_ideas" value="yes" <?php checked($enable_ideas, 'yes'); ?>>
                                            <?php esc_html_e('Allow users to submit ideas for this board.', 'simpleboards-roadmap'); ?>
                                        </label>
                                    </td>
                                </tr>
                                <tr id="sbir-default-tab-field" <?php echo $enable_ideas !== 'yes' ? ' style="display:none;"' : ''; ?>>
                                    <th scope="row"><label for="sbir_default_tab"><?php esc_html_e('Default tab', 'simpleboards-roadmap'); ?></label></th>
                                    <td>
                                        <select name="sbir_default_tab" id="sbir_default_tab">
                                            <?php foreach ($default_tab_choices as $tab_value => $tab_label) : ?>
                                                <option value="<?php echo esc_attr($tab_value); ?>" <?php selected($default_tab, $tab_value); ?>><?php echo esc_html($tab_label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="description" id="sbir-default-tab-field-desc"><?php esc_html_e('Shown first and active when visitors open this board URL (unless they use a tab-specific link).', 'simpleboards-roadmap'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Board header', 'simpleboards-roadmap'); ?></th>
                                    <td>
                                        <label for="sbir_hide_board_title">
                                            <input type="checkbox" id="sbir_hide_board_title" name="sbir_hide_board_title" value="yes" <?php checked($hide_board_title, 'yes'); ?>>
                                            <?php esc_html_e('Hide board title and description', 'simpleboards-roadmap'); ?>
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Roadmap controls', 'simpleboards-roadmap'); ?></th>
                                    <td>
                                        <label for="sbir_display_roadmap_filter" style="margin-bottom:6px;">
                                            <input type="checkbox" id="sbir_display_roadmap_filter" name="sbir_display_roadmap_filter" value="yes" <?php checked($display_roadmap_filter, 'yes'); ?>>
                                            <?php esc_html_e('Display Filter', 'simpleboards-roadmap'); ?>
                                        </label>
                                        <label for="sbir_display_roadmap_sort">
                                            <input type="checkbox" id="sbir_display_roadmap_sort" name="sbir_display_roadmap_sort" value="yes" <?php checked($display_roadmap_sort, 'yes'); ?>>
                                            <?php esc_html_e('Display Sort By', 'simpleboards-roadmap'); ?>
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Ideas controls', 'simpleboards-roadmap'); ?></th>
                                    <td>
                                        <label for="sbir_display_ideas_filter" style="margin-bottom:6px;">
                                            <input type="checkbox" id="sbir_display_ideas_filter" name="sbir_display_ideas_filter" value="yes" <?php checked($display_ideas_filter, 'yes'); ?>>
                                            <?php esc_html_e('Display Filter', 'simpleboards-roadmap'); ?>
                                        </label>
                                        <label for="sbir_display_ideas_sort">
                                            <input type="checkbox" id="sbir_display_ideas_sort" name="sbir_display_ideas_sort" value="yes" <?php checked($display_ideas_sort, 'yes'); ?>>
                                            <?php esc_html_e('Display Sort By', 'simpleboards-roadmap'); ?>
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Comments', 'simpleboards-roadmap'); ?></th>
                                    <td>
                                        <label for="sbir_board_comments_disabled">
                                            <input type="checkbox" id="sbir_board_comments_disabled" name="sbir_board_comments_disabled" value="yes" <?php checked($comments_disabled, 'yes'); ?>>
                                            <?php esc_html_e('Disable comments for this board', 'simpleboards-roadmap'); ?>
                                        </label>
                                        <p class="description"><?php esc_html_e('Hides the discussion area on all items in this board.', 'simpleboards-roadmap'); ?></p>
                                    </td>
                                </tr>
                                <?php do_action('sbir_after_board_setup_fields', $board_id, $is_edit); ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (has_action('sbir_render_board_workflow_tab_content')) : ?>
                        <div class="sbir-board-tab-panel <?php echo $active_setup_tab === 'workflow-automations' ? 'active' : ''; ?>" data-tab-panel="workflow-automations">
                            <?php do_action('sbir_render_board_workflow_tab_content', $board_id, $is_edit); ?>
                            <?php do_action('sbir_after_board_general_settings_section', $board_id, $is_edit); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (has_action('sbir_render_board_design_tab_content')) : ?>
                        <div class="sbir-board-tab-panel <?php echo $active_setup_tab === 'design' ? 'active' : ''; ?>" data-tab-panel="design">
                            <?php do_action('sbir_render_board_design_tab_content', $board_id, $is_edit); ?>
                        </div>
                    <?php endif; ?>
                    <?php
                    $reserved_tabs = array('general', 'workflow-automations', 'design');
                    foreach ($setup_tabs as $tab_key => $tab_label) :
                        if (in_array($tab_key, $reserved_tabs, true)) {
                            continue;
                        }
                        ?>
                        <div class="sbir-board-tab-panel <?php echo $active_setup_tab === $tab_key ? 'active' : ''; ?>" data-tab-panel="<?php echo esc_attr($tab_key); ?>">
                            <?php do_action('sbir_render_board_setup_tab_content', $tab_key, $board_id, $is_edit, $tab_label); ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php submit_button($is_edit ? __('Save Board', 'simpleboards-roadmap') : __('Create Board', 'simpleboards-roadmap')); ?>
            </form>
            <script>
                (function($){
                    function sbirSwitchBoardTab(tabKey, tabHref) {
                        $('.sbir-board-setup-tabs .nav-tab').removeClass('nav-tab-active');
                        $('.sbir-board-setup-tabs .nav-tab[data-tab="' + tabKey + '"]').addClass('nav-tab-active');
                        $('.sbir-board-tab-panel').removeClass('active');
                        $('.sbir-board-tab-panel[data-tab-panel="' + tabKey + '"]').addClass('active');
                        $('#sbir_active_tab').val(tabKey);
                        if (tabHref) {
                            window.history.replaceState({}, '', tabHref);
                        }
                    }

                    $(document).on('click', '.sbir-board-setup-tabs .nav-tab', function(e) {
                        e.preventDefault();
                        sbirSwitchBoardTab($(this).data('tab'), $(this).attr('href'));
                    });

                    function sbirSlugify(text) {
                        return (text || '')
                            .toLowerCase()
                            .trim()
                            .replace(/[^a-z0-9\s-]/g, '')
                            .replace(/\s+/g, '-')
                            .replace(/-+/g, '-');
                    }

                    <?php if (!$is_edit) : ?>
                    function sbirUpdateShortcodePreview() {
                        var title = $('#sbir_new_board_title').val() || '';
                        var slug = sbirSlugify(title);
                        $('#sbir_board_shortcode').val(slug ? '[sbir_board product="' + slug + '"]' : '');
                    }
                    $(document).on('input', '#sbir_new_board_title', sbirUpdateShortcodePreview);
                    sbirUpdateShortcodePreview();
                    <?php endif; ?>
                })(jQuery);
            </script>
        </div>
        <?php
    }

    /**
     * Handle Add/Edit Board form submission.
     *
     * @return void
     */
    public function handle_create_board() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to perform this action.', 'simpleboards-roadmap'));
        }

        check_admin_referer('sbir_create_board', 'sbir_create_board_nonce');

        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
        $board_id = isset($_POST['board_id']) ? absint(wp_unslash($_POST['board_id'])) : 0;
        $enable_ideas = isset($_POST['sbir_enable_ideas']) ? 'yes' : 'no';
        $hide_board_title = isset($_POST['sbir_hide_board_title']) ? 'yes' : 'no';
        $display_roadmap_filter = isset($_POST['sbir_display_roadmap_filter']) ? 'yes' : 'no';
        $display_roadmap_sort = isset($_POST['sbir_display_roadmap_sort']) ? 'yes' : 'no';
        $display_ideas_filter = isset($_POST['sbir_display_ideas_filter']) ? 'yes' : 'no';
        $display_ideas_sort = isset($_POST['sbir_display_ideas_sort']) ? 'yes' : 'no';
        $comments_disabled = isset($_POST['sbir_board_comments_disabled']) ? 'yes' : 'no';
        $default_tab = isset($_POST['sbir_default_tab']) ? sanitize_key(wp_unslash($_POST['sbir_default_tab'])) : 'roadmap';
        $is_edit = $board_id > 0;
        $default_tab_choices = sbir_get_default_tab_choices((int) $board_id);
        if (!isset($default_tab_choices[$default_tab])) {
            $default_tab = 'roadmap';
        }
        $setup_tabs = $this->get_board_setup_tabs($board_id, $is_edit);
        $allowed_setup_tabs = array_keys($setup_tabs);
        $redirect_tab = isset($_POST['sbir_active_tab']) ? sanitize_key(wp_unslash($_POST['sbir_active_tab'])) : 'general';
        if (!in_array($redirect_tab, $allowed_setup_tabs, true)) {
            $redirect_tab = 'general';
        }

        if ($title === '') {
            $redirect = add_query_arg(
                array(
                    'page' => 'sbir-add-board',
                    'board_id' => $board_id,
                    'tab' => $redirect_tab,
                    'sbir_error' => __('Board name is required.', 'simpleboards-roadmap'),
                ),
                admin_url('admin.php')
            );
            wp_safe_redirect($redirect);
            exit;
        }

        if ($is_edit) {
            $existing = get_post($board_id);
            if (!$existing || $existing->post_type !== 'sbir_board') {
                $redirect = add_query_arg(
                    array(
                        'page' => 'sbir-add-board',
                        'tab' => $redirect_tab,
                        'sbir_error' => __('Board not found.', 'simpleboards-roadmap'),
                    ),
                    admin_url('admin.php')
                );
                wp_safe_redirect($redirect);
                exit;
            }

            if (!current_user_can('edit_post', $board_id)) {
                wp_die(esc_html__('Sorry, you are not allowed to edit this board.', 'simpleboards-roadmap'));
            }

            $update_args = array(
                'ID' => $board_id,
                'post_title' => $title,
                'post_content' => $description,
            );

            $posted_slug = isset($_POST['board_slug']) ? sanitize_title(wp_unslash($_POST['board_slug'])) : '';
            if ($posted_slug !== '' && $posted_slug !== (string) get_post_field('post_name', $board_id)) {
                $update_args['post_name'] = $posted_slug;
            }

            $result = wp_update_post($update_args, true);
        } else {
            $result = wp_insert_post(
                array(
                    'post_type' => 'sbir_board',
                    'post_title' => $title,
                    'post_content' => $description,
                    'post_status' => 'publish',
                ),
                true
            );
        }

        if (is_wp_error($result)) {
            $redirect = add_query_arg(
                array(
                    'page' => 'sbir-add-board',
                    'board_id' => $board_id,
                    'tab' => $redirect_tab,
                    'sbir_error' => $result->get_error_message(),
                ),
                admin_url('admin.php')
            );
            wp_safe_redirect($redirect);
            exit;
        }

        if (!$is_edit) {
            $board_id = (int) $result;
        }

        update_post_meta($board_id, '_sbir_enable_ideas', $enable_ideas);
        update_post_meta($board_id, '_sbir_hide_board_title', $hide_board_title);
        update_post_meta($board_id, '_sbir_display_roadmap_filter', $display_roadmap_filter);
        update_post_meta($board_id, '_sbir_display_roadmap_sort', $display_roadmap_sort);
        if ($comments_disabled === 'yes') {
            update_post_meta($board_id, '_sbir_board_comments_disabled', 'yes');
        } else {
            delete_post_meta($board_id, '_sbir_board_comments_disabled');
        }
        if ($enable_ideas === 'yes') {
            update_post_meta($board_id, '_sbir_default_tab', $default_tab);
            update_post_meta($board_id, '_sbir_display_ideas_filter', $display_ideas_filter);
            update_post_meta($board_id, '_sbir_display_ideas_sort', $display_ideas_sort);
        } else {
            delete_post_meta($board_id, '_sbir_default_tab');
            delete_post_meta($board_id, '_sbir_display_ideas_filter');
            delete_post_meta($board_id, '_sbir_display_ideas_sort');
        }

        do_action('sbir_after_save_board_settings', $board_id, $is_edit);

        $redirect = add_query_arg(
            array(
                'page' => 'sbir-add-board',
                'board_id' => (int) $board_id,
                'tab' => $redirect_tab,
                'sbir_updated' => 1,
            ),
            admin_url('admin.php')
        );
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Render custom Add/Edit Item admin page.
     *
     * @return void
     */
    public function render_add_item_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'simpleboards-roadmap'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing state
        $item_id = isset($_GET['item_id']) ? absint(wp_unslash($_GET['item_id'])) : 0;
        $is_edit = $item_id > 0;
        $item = null;

        if ($is_edit) {
            $item = get_post($item_id);
            if (!$item || $item->post_type !== 'sbir_item') {
                $item_id = 0;
                $is_edit = false;
            }
        }

        $error = '';
        $updated = false;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice string
        if (isset($_GET['sbir_error'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice string
            $error = sanitize_text_field(wp_unslash($_GET['sbir_error']));
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag
        if (isset($_GET['sbir_updated']) && wp_unslash($_GET['sbir_updated']) === '1') {
            $updated = true;
        }

        $title_value = $is_edit ? $item->post_title : '';
        $description_value = $is_edit ? $item->post_content : '';
        $board_id = $is_edit ? absint(get_post_meta($item_id, '_sbir_board_id', true)) : 0;
        // New items default to "Roadmap Item" since that's the primary use case.
        $is_roadmap = $is_edit ? (get_post_meta($item_id, '_sbir_is_roadmap', true) === 'yes' ? 'yes' : 'no') : 'yes';
        $status_terms = $is_edit ? wp_get_object_terms($item_id, 'sbir_status', array('fields' => 'ids')) : array();
        $current_status = !empty($status_terms) ? absint($status_terms[0]) : 0;
        $category_terms = $is_edit ? wp_get_object_terms($item_id, 'sbir_category', array('fields' => 'ids')) : array();
        $current_category = !empty($category_terms) ? absint($category_terms[0]) : 0;
        $deadline = $is_edit ? (string) get_post_meta($item_id, '_sbir_deadline', true) : '';
        $vote_count = $is_edit ? (int) sbir_get_vote_count($item_id) : 0;

        if (!$is_edit) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only defaults
            if (isset($_GET['sbir_board_id'])) {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only defaults
                $board_id = absint(wp_unslash($_GET['sbir_board_id']));
            }
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only defaults
            if (isset($_GET['sbir_is_roadmap'])) {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only defaults
                $is_roadmap = sanitize_text_field(wp_unslash($_GET['sbir_is_roadmap'])) === 'yes' ? 'yes' : 'no';
            }
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only defaults
            if (isset($_GET['sbir_status'])) {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only defaults
                $current_status = absint(wp_unslash($_GET['sbir_status']));
            }
        }

        $statuses = get_terms(array('taxonomy' => 'sbir_status', 'hide_empty' => false));
        if (is_wp_error($statuses)) {
            $statuses = array();
        }
        $status_data = array();
        foreach ($statuses as $status_term) {
            $belongs_to = (int) get_term_meta($status_term->term_id, '_sbir_status_board', true);
            $is_released_stage = get_term_meta($status_term->term_id, '_sbir_status_released', true) === 'yes';
            $status_data[] = array(
                'id' => (int) $status_term->term_id,
                'name' => (string) $status_term->name,
                'board' => $belongs_to,
                'released' => $is_released_stage,
            );
        }

        $categories = get_terms(array('taxonomy' => 'sbir_category', 'hide_empty' => false));
        if (is_wp_error($categories)) {
            $categories = array();
        }
        ?>
        <?php
        // Pre-fill the Add Item link with the current board (and item type)
        // so creating another item lands on the same board without picking
        // it again — the common case after saving.
        $add_item_args = array('page' => 'sbir-add-item');
        if ($board_id) {
            $add_item_args['sbir_board_id'] = (int) $board_id;
        }
        if ($is_roadmap === 'yes') {
            $add_item_args['sbir_is_roadmap'] = 'yes';
        }
        $add_item_url = add_query_arg($add_item_args, admin_url('admin.php'));
        ?>
        <div class="wrap sbir-add-item-page">
            <h1>
                <?php echo esc_html($is_edit ? __('Edit Item', 'simpleboards-roadmap') : __('Add Item', 'simpleboards-roadmap')); ?>
                <?php if ($is_edit) : ?>
                    <a class="page-title-action" href="<?php echo esc_url($add_item_url); ?>"><?php esc_html_e('Add Item', 'simpleboards-roadmap'); ?></a>
                <?php endif; ?>
            </h1>
            <p class="description"><?php esc_html_e('Use this focused form to create and edit roadmap items and ideas.', 'simpleboards-roadmap'); ?></p>

            <?php if ($updated) : ?>
                <div class="notice notice-success">
                    <p>
                        <?php esc_html_e('Item saved.', 'simpleboards-roadmap'); ?>
                        <a href="<?php echo esc_url($add_item_url); ?>" style="margin-left:8px;"><?php esc_html_e('Add another', 'simpleboards-roadmap'); ?></a>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($error !== '') : ?>
                <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sbir-add-item-form">
                <?php wp_nonce_field('sbir_save_item', 'sbir_save_item_nonce'); ?>
                <input type="hidden" name="action" value="sbir_save_item">
                <input type="hidden" name="item_id" value="<?php echo esc_attr($item_id); ?>">

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="sbir_item_title"><?php esc_html_e('Title', 'simpleboards-roadmap'); ?></label></th>
                            <td><input type="text" id="sbir_item_title" name="title" class="regular-text" value="<?php echo esc_attr($title_value); ?>" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sbir_item_description"><?php esc_html_e('Description', 'simpleboards-roadmap'); ?></label></th>
                            <td><textarea id="sbir_item_description" name="description" rows="6" class="large-text"><?php echo esc_textarea($description_value); ?></textarea></td>
                        </tr>
                    </tbody>
                </table>

                <h2><?php esc_html_e('Item Setup', 'simpleboards-roadmap'); ?></h2>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="sbir_board_id"><?php esc_html_e('Board', 'simpleboards-roadmap'); ?></label></th>
                            <td>
                                <select id="sbir_board_id" name="sbir_board_id" class="regular-text">
                                    <?php
                                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped options
                                    echo sbir_get_boards_dropdown($board_id);
                                    ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Item Type', 'simpleboards-roadmap'); ?></th>
                            <td>
                                <label><input type="radio" name="sbir_is_roadmap" value="yes" <?php checked($is_roadmap, 'yes'); ?>> <?php esc_html_e('Roadmap Item', 'simpleboards-roadmap'); ?></label><br>
                                <label><input type="radio" name="sbir_is_roadmap" value="no" <?php checked($is_roadmap, 'no'); ?>> <?php esc_html_e('Idea', 'simpleboards-roadmap'); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sbir_status_select"><?php esc_html_e('Status', 'simpleboards-roadmap'); ?></label></th>
                            <td>
                                <select id="sbir_status_select" name="sbir_status_select" data-statuses='<?php echo esc_attr(wp_json_encode($status_data)); ?>' <?php disabled($is_roadmap !== 'yes'); ?>>
                                    <option value=""><?php esc_html_e('Select Status', 'simpleboards-roadmap'); ?></option>
                                    <?php foreach ($statuses as $status_term) :
                                        $opt_released = get_term_meta($status_term->term_id, '_sbir_status_released', true) === 'yes';
                                    ?>
                                        <option value="<?php echo esc_attr($status_term->term_id); ?>" data-released="<?php echo $opt_released ? '1' : '0'; ?>" <?php selected($current_status, $status_term->term_id); ?>>
                                            <?php echo esc_html($status_term->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e('Enabled only for Roadmap Items.', 'simpleboards-roadmap'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sbir_category_select"><?php esc_html_e('Category', 'simpleboards-roadmap'); ?></label></th>
                            <td>
                                <select id="sbir_category_select" name="sbir_category_select" <?php disabled(empty($categories)); ?>>
                                    <?php if (!empty($categories)) : ?>
                                        <option value=""><?php esc_html_e('Select Category', 'simpleboards-roadmap'); ?></option>
                                        <?php foreach ($categories as $category_term) : ?>
                                            <option value="<?php echo esc_attr($category_term->term_id); ?>" <?php selected($current_category, $category_term->term_id); ?>>
                                                <?php echo esc_html($category_term->name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php else : ?>
                                        <option value="" selected><?php esc_html_e('Categories not available', 'simpleboards-roadmap'); ?></option>
                                    <?php endif; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="sbir_deadline">
                                    <span class="sbir-deadline-label-default" data-default-label="<?php esc_attr_e('Deadline', 'simpleboards-roadmap'); ?>" data-released-label="<?php esc_attr_e('Released', 'simpleboards-roadmap'); ?>"><?php esc_html_e('Deadline', 'simpleboards-roadmap'); ?></span>
                                </label>
                            </th>
                            <td><input type="date" id="sbir_deadline" name="deadline" value="<?php echo esc_attr($deadline); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sbir_vote_count"><?php esc_html_e('Votes', 'simpleboards-roadmap'); ?></label></th>
                            <td>
                                <input type="number" id="sbir_vote_count" name="vote_count" min="0" step="1" value="<?php echo esc_attr((string) $vote_count); ?>" class="small-text">
                                <p class="description"><?php esc_html_e('Set the total vote count. Use this to backfill items imported without vote data.', 'simpleboards-roadmap'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <script>
                    (function(){
                        // When the selected status is flagged as a release stage,
                        // swap the Deadline label to Released so admins see the
                        // same semantics here as on cards and in the drawer.
                        var select = document.getElementById('sbir_status_select');
                        var labelSpan = document.querySelector('.sbir-deadline-label-default');
                        if (!select || !labelSpan) { return; }
                        var defaultLabel = labelSpan.getAttribute('data-default-label') || labelSpan.textContent;
                        var releasedLabel = labelSpan.getAttribute('data-released-label') || defaultLabel;
                        function syncDeadlineLabel(){
                            var opt = select.options[select.selectedIndex];
                            var released = opt && opt.getAttribute('data-released') === '1';
                            labelSpan.textContent = released ? releasedLabel : defaultLabel;
                        }
                        select.addEventListener('change', syncDeadlineLabel);
                        syncDeadlineLabel();
                    })();
                </script>

                <?php do_action('sbir_render_item_setup_extra_fields', (int) $item_id, (bool) $is_edit, (string) $is_roadmap, (int) $board_id); ?>

                <?php submit_button($is_edit ? __('Save Item', 'simpleboards-roadmap') : __('Create Item', 'simpleboards-roadmap')); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Handle Add/Edit Item form submission.
     *
     * @return void
     */
    public function handle_save_item() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to perform this action.', 'simpleboards-roadmap'));
        }

        check_admin_referer('sbir_save_item', 'sbir_save_item_nonce');

        $item_id = isset($_POST['item_id']) ? absint(wp_unslash($_POST['item_id'])) : 0;
        $is_edit = $item_id > 0;
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
        $board_id = isset($_POST['sbir_board_id']) ? absint(wp_unslash($_POST['sbir_board_id'])) : 0;
        $is_roadmap = isset($_POST['sbir_is_roadmap']) && sanitize_text_field(wp_unslash($_POST['sbir_is_roadmap'])) === 'yes' ? 'yes' : 'no';
        $status_term = isset($_POST['sbir_status_select']) ? absint(wp_unslash($_POST['sbir_status_select'])) : 0;
        $category_term = isset($_POST['sbir_category_select']) ? absint(wp_unslash($_POST['sbir_category_select'])) : 0;
        $deadline = isset($_POST['deadline']) ? sanitize_text_field(wp_unslash($_POST['deadline'])) : '';

        if ($title === '' || !$board_id) {
            $redirect = add_query_arg(
                array(
                    'page' => 'sbir-add-item',
                    'item_id' => $item_id,
                    'sbir_error' => __('Title and Board are required.', 'simpleboards-roadmap'),
                ),
                admin_url('admin.php')
            );
            wp_safe_redirect($redirect);
            exit;
        }

        $result = null;
        $prev_board_id = 0;

        if ($is_edit) {
            $item = get_post($item_id);
            if (!$item || $item->post_type !== 'sbir_item') {
                $redirect = add_query_arg(
                    array(
                        'page' => 'sbir-add-item',
                        'sbir_error' => __('Item not found.', 'simpleboards-roadmap'),
                    ),
                    admin_url('admin.php')
                );
                wp_safe_redirect($redirect);
                exit;
            }
            if (!current_user_can('edit_post', $item_id)) {
                wp_die(esc_html__('Sorry, you are not allowed to edit this item.', 'simpleboards-roadmap'));
            }
            $prev_board_id = (int) get_post_meta($item_id, '_sbir_board_id', true);
            $result = wp_update_post(
                array(
                    'ID' => $item_id,
                    'post_title' => $title,
                    'post_content' => $description,
                ),
                true
            );
        } else {
            $result = wp_insert_post(
                array(
                    'post_type' => 'sbir_item',
                    'post_title' => $title,
                    'post_content' => $description,
                    'post_status' => 'publish',
                ),
                true
            );
        }

        if (is_wp_error($result)) {
            $redirect = add_query_arg(
                array(
                    'page' => 'sbir-add-item',
                    'item_id' => $item_id,
                    'sbir_error' => $result->get_error_message(),
                ),
                admin_url('admin.php')
            );
            wp_safe_redirect($redirect);
            exit;
        }

        if (!$is_edit) {
            $item_id = (int) $result;
        }

        update_post_meta($item_id, '_sbir_board_id', $board_id);
        update_post_meta($item_id, '_sbir_is_roadmap', $is_roadmap);

        if ($is_edit && $prev_board_id !== $board_id) {
            delete_post_meta($item_id, '_sbir_item_number');
        }

        if ($is_roadmap === 'yes' && $status_term) {
            $status = get_term($status_term, 'sbir_status');
            if ($status && !is_wp_error($status)) {
                $belongs_to = (int) get_term_meta($status->term_id, '_sbir_status_board', true);
                if ($belongs_to === 0 || $belongs_to === $board_id) {
                    wp_set_object_terms($item_id, array((int) $status_term), 'sbir_status', false);
                } else {
                    wp_set_object_terms($item_id, array(), 'sbir_status', false);
                }
            } else {
                wp_set_object_terms($item_id, array(), 'sbir_status', false);
            }
        } else {
            wp_set_object_terms($item_id, array(), 'sbir_status', false);
        }

        if ($category_term) {
            $category = get_term($category_term, 'sbir_category');
            if ($category && !is_wp_error($category)) {
                wp_set_object_terms($item_id, array((int) $category_term), 'sbir_category', false);
            } else {
                wp_set_object_terms($item_id, array(), 'sbir_category', false);
            }
        } else {
            wp_set_object_terms($item_id, array(), 'sbir_category', false);
        }

        if ($deadline !== '') {
            $deadline_ts = strtotime($deadline);
            if ($deadline_ts) {
                update_post_meta($item_id, '_sbir_deadline', gmdate('Y-m-d', $deadline_ts));
            } else {
                delete_post_meta($item_id, '_sbir_deadline');
            }
        } else {
            delete_post_meta($item_id, '_sbir_deadline');
        }

        if (isset($_POST['vote_count'])) {
            sbir_set_vote_count((int) $item_id, absint(wp_unslash($_POST['vote_count'])));
        }

        if (function_exists('sbir_get_item_number')) {
            sbir_get_item_number($item_id);
        }

        /**
         * Allow extensions to save extra values from custom admin Add/Edit Item form.
         *
         * @param int   $item_id Item ID.
         * @param array $request Request payload.
         */
        do_action('sbir_after_save_item_admin', (int) $item_id, isset($_POST) && is_array($_POST) ? wp_unslash($_POST) : array()); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- request validated by check_admin_referer above.

        $redirect = add_query_arg(
            array(
                'page' => 'sbir-add-item',
                'item_id' => (int) $item_id,
                'sbir_updated' => 1,
            ),
            admin_url('admin.php')
        );
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Redirect default board/item authoring screens to custom pages.
     *
     * @return void
     */
    public function redirect_default_authoring_screens() {
        global $pagenow;

        if (!current_user_can('manage_options')) {
            return;
        }

        if ($pagenow === 'post-new.php') {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only
            $post_type = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : '';
            if ($post_type === 'sbir_board') {
                wp_safe_redirect(admin_url('admin.php?page=sbir-add-board'));
                exit;
            }
            if ($post_type === 'sbir_item') {
                wp_safe_redirect(admin_url('admin.php?page=sbir-add-item'));
                exit;
            }
        }

        if ($pagenow === 'post.php') {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only
            $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only
            $post_id = isset($_GET['post']) ? absint(wp_unslash($_GET['post'])) : 0;
            if ($action === 'edit' && $post_id > 0) {
                $post = get_post($post_id);
                if ($post && $post->post_type === 'sbir_board') {
                    $target = add_query_arg(
                        array(
                            'page' => 'sbir-add-board',
                            'board_id' => $post_id,
                        ),
                        admin_url('admin.php')
                    );
                    wp_safe_redirect($target);
                    exit;
                }
                if ($post && $post->post_type === 'sbir_item') {
                    $target = add_query_arg(
                        array(
                            'page' => 'sbir-add-item',
                            'item_id' => $post_id,
                        ),
                        admin_url('admin.php')
                    );
                    wp_safe_redirect($target);
                    exit;
                }
            }
        }
    }

    /**
     * Highlight SimpleBoards menu on taxonomy screens
     */
    public function set_menu_highlight($parent_file) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- menu context only
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (
            in_array($page, array('sbir-add-board', 'sbir-add-item'), true)
            || (
                $screen && (
                    in_array($screen->taxonomy, array('sbir_status', 'sbir_category'), true)
                    || in_array($screen->post_type, array('sbir_board', 'sbir_item'), true)
                )
            )
        ) {
            return 'simpleboards-roadmap';
        }
        return $parent_file;
    }

    /**
     * Highlight correct submenu item on taxonomy screens
     */
    public function set_submenu_highlight($submenu_file, $parent_file) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- menu context only
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page === 'sbir-add-board') {
            return 'edit.php?post_type=sbir_board';
        }
        if ($page === 'sbir-add-item') {
            return 'edit.php?post_type=sbir_item';
        }
        if ($screen && in_array($screen->taxonomy, array('sbir_status', 'sbir_category'), true)) {
            $taxonomy = $screen->taxonomy;
            return 'edit-tags.php?taxonomy=' . $taxonomy . '&post_type=sbir_item';
        }
        if ($screen && in_array($screen->post_type, array('sbir_board', 'sbir_item'), true)) {
            return $screen->post_type === 'sbir_item'
                ? 'edit.php?post_type=sbir_item'
                : 'edit.php?post_type=sbir_board';
        }
        return $submenu_file;
    }

    /**
     * Hide setup-only submenu links from the sidebar.
     *
     * @return void
     */
    public function hide_setup_submenus_in_sidebar() {
        echo '<style>
            #toplevel_page_simpleboards-roadmap .wp-submenu a[href="admin.php?page=sbir-add-board"],
            #toplevel_page_simpleboards-roadmap .wp-submenu a[href="admin.php?page=sbir-add-item"] {
                display: none;
            }
        </style>';
    }

    
    // Removed upsell page
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_scripts($hook) {
        // Only load on our pages (boards/items screens or sbir_status taxonomy screens)
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $is_sbir_screen = (strpos($hook, 'sbir') !== false)
            || ($screen && ($screen->post_type === 'sbir_board' || $screen->post_type === 'sbir_item' || $screen->taxonomy === 'sbir_status'));
        if (!$is_sbir_screen) { return; }

        $css_path = SBIR_PLUGIN_DIR . 'admin/css/sbir-admin.css';
        $js_path  = SBIR_PLUGIN_DIR . 'admin/js/sbir-admin.js';
        $css_ver  = file_exists($css_path) ? (string) filemtime($css_path) : SBIR_VERSION;
        $js_ver   = file_exists($js_path)  ? (string) filemtime($js_path)  : SBIR_VERSION;

        wp_enqueue_style('sbir-admin', SBIR_PLUGIN_URL . 'admin/css/sbir-admin.css', array(), $css_ver);
        wp_enqueue_script('sbir-admin', SBIR_PLUGIN_URL . 'admin/js/sbir-admin.js', array('jquery'), $js_ver, true);
        wp_localize_script('sbir-admin', 'sbir_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sbir_admin_nonce'),
            'i18n' => array(
                'select_status' => __('Select Status', 'simpleboards-roadmap'),
            ),
        ));
    }
    
    /**
     * Add meta boxes
     */
    public function add_meta_boxes() {
        // Board settings
        add_meta_box(
            'sbir_board_settings',
            __('Board Setup', 'simpleboards-roadmap'),
            array($this, 'render_board_settings_meta_box'),
            'sbir_board',
            'normal',
            'high'
        );
        
        // Item settings
        add_meta_box(
            'sbir_item_settings',
            __('Item Details', 'simpleboards-roadmap'),
            array($this, 'render_item_settings_meta_box'),
            'sbir_item',
            'side',
            'default'
        );
    }
    
    /**
     * Remove nonessential core metaboxes for cleaner Board/Item editing.
     */
    public function remove_nonessential_meta_boxes() {
        // Keep the writing experience focused on plugin-managed fields.
        remove_meta_box('slugdiv', 'sbir_board', 'normal');
        remove_meta_box('slugdiv', 'sbir_item', 'normal');

        remove_meta_box('authordiv', 'sbir_board', 'normal');
        remove_meta_box('authordiv', 'sbir_item', 'normal');

        remove_meta_box('revisionsdiv', 'sbir_board', 'normal');
        remove_meta_box('revisionsdiv', 'sbir_item', 'normal');

        remove_meta_box('trackbacksdiv', 'sbir_board', 'normal');
        remove_meta_box('trackbacksdiv', 'sbir_item', 'normal');

        remove_meta_box('commentstatusdiv', 'sbir_board', 'normal');
        remove_meta_box('commentstatusdiv', 'sbir_item', 'normal');

        remove_meta_box('commentsdiv', 'sbir_board', 'normal');
        remove_meta_box('commentsdiv', 'sbir_item', 'normal');
    }
    
    /**
     * Output board settings meta box.
     *
     * @param WP_Post $post Board post.
     */
    public function render_board_settings_meta_box($post) {
        wp_nonce_field('sbir_board_settings', 'sbir_board_settings_nonce');
        
        $enable_ideas = get_post_meta($post->ID, '_sbir_enable_ideas', true);
        $enable_ideas = $enable_ideas ? $enable_ideas : 'yes';
        $default_tab_choices = sbir_get_default_tab_choices((int) $post->ID);
        $default_tab = (string) get_post_meta($post->ID, '_sbir_default_tab', true);
        if (!isset($default_tab_choices[$default_tab])) {
            $default_tab = 'roadmap';
        }
        $hide_board_title = get_post_meta($post->ID, '_sbir_hide_board_title', true);
        $hide_board_title = ($hide_board_title === '') ? 'yes' : $hide_board_title;
        ?>
        <p>
            <label for="sbir_enable_ideas">
                <input type="checkbox" id="sbir_enable_ideas" name="sbir_enable_ideas" value="yes" <?php checked($enable_ideas, 'yes'); ?>>
                <?php esc_html_e('Enable Ideas Tab', 'simpleboards-roadmap'); ?>
            </label>
        </p>
        <p class="description">
            <?php esc_html_e('Allow users to submit ideas for this board.', 'simpleboards-roadmap'); ?>
        </p>
        <p class="sbir-default-tab-field" id="sbir-default-tab-field" <?php echo $enable_ideas !== 'yes' ? ' style="display:none;"' : ''; ?>>
            <label for="sbir_default_tab"><strong><?php esc_html_e('Default tab', 'simpleboards-roadmap'); ?></strong></label><br>
            <select name="sbir_default_tab" id="sbir_default_tab" class="widefat">
                <?php foreach ($default_tab_choices as $tab_value => $tab_label) : ?>
                    <option value="<?php echo esc_attr($tab_value); ?>" <?php selected($default_tab, $tab_value); ?>><?php echo esc_html($tab_label); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p class="description sbir-default-tab-field-desc" id="sbir-default-tab-field-desc" <?php echo $enable_ideas !== 'yes' ? ' style="display:none;"' : ''; ?>>
            <?php esc_html_e('Shown first and active when visitors open this board URL (unless they use a tab-specific link).', 'simpleboards-roadmap'); ?>
        </p>
        <p>
            <label for="sbir_hide_board_title">
                <input type="checkbox" id="sbir_hide_board_title" name="sbir_hide_board_title" value="yes" <?php checked($hide_board_title, 'yes'); ?>>
                <?php esc_html_e('Hide board title and description', 'simpleboards-roadmap'); ?>
            </label>
        </p>
        <p class="description">
            <?php esc_html_e('Applies to this board only.', 'simpleboards-roadmap'); ?>
        </p>
        <?php
    }
    
    /**
     * Output item settings meta box (board, type, status).
     *
     * @param WP_Post $post Item post.
     */
    public function render_item_settings_meta_box($post) {
        wp_nonce_field('sbir_item_settings', 'sbir_item_settings_nonce');
        
        $board_id = get_post_meta($post->ID, '_sbir_board_id', true);
        $is_roadmap_meta = get_post_meta($post->ID, '_sbir_is_roadmap', true);
        $is_new_item = $post instanceof WP_Post && $post->post_status === 'auto-draft';
        // New items default to Roadmap Item; existing items respect their saved value.
        if ($is_roadmap_meta === 'yes') {
            $is_roadmap = 'yes';
        } elseif ($is_roadmap_meta === 'no') {
            $is_roadmap = 'no';
        } else {
            $is_roadmap = $is_new_item ? 'yes' : 'no';
        }

        $is_new = $is_new_item;
        if ($is_new) {
            // Preselect defaults from query args when creating from board
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if (!$board_id && isset($_GET['sbir_board_id'])) {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $board_id = absint($_GET['sbir_board_id']);
            }
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ($is_roadmap_meta === '' && isset($_GET['sbir_is_roadmap'])) {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $is_roadmap = sanitize_text_field(wp_unslash($_GET['sbir_is_roadmap'])) === 'yes' ? 'yes' : 'no';
            }
        }
        ?>
        <p>
            <label for="sbir_board_id"><?php esc_html_e('Board:', 'simpleboards-roadmap'); ?></label>
            <select name="sbir_board_id" id="sbir_board_id" class="widefat">
                <?php 
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sbir_get_boards_dropdown() returns pre-escaped HTML
                echo sbir_get_boards_dropdown($board_id); 
                ?>
            </select>
        </p>
        <p>
            <strong><?php esc_html_e('Item Type', 'simpleboards-roadmap'); ?>:</strong><br>
            <label>
                <input type="radio" name="sbir_is_roadmap" value="yes" <?php checked($is_roadmap, 'yes'); ?>>
                <?php esc_html_e('Roadmap Item', 'simpleboards-roadmap'); ?>
            </label><br>
            <label>
                <input type="radio" name="sbir_is_roadmap" value="no" <?php checked($is_roadmap, 'no'); ?>>
                <?php esc_html_e('Idea', 'simpleboards-roadmap'); ?>
            </label>
        </p>
        <?php
        // Status dropdown (always rendered; disabled unless Roadmap)
        $is_roadmap_bool = ($is_roadmap === 'yes');
        $current_terms = wp_get_object_terms($post->ID, 'sbir_status', array('fields' => 'ids'));
        $current_status = !empty($current_terms) ? (int) $current_terms[0] : 0;
        if ($is_new && !$current_status) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if (isset($_GET['sbir_status'])) {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $current_status = absint($_GET['sbir_status']);
            }
        }
        $terms = get_terms(array('taxonomy' => 'sbir_status', 'hide_empty' => false));
        $status_data = array();
        foreach ($terms as $t) {
            $bt = (int) get_term_meta($t->term_id, '_sbir_status_board', true);
            $status_data[] = array('id' => (int) $t->term_id, 'name' => $t->name, 'board' => $bt);
        }
        ?>
        <p>
            <label for="sbir_status_select"><strong><?php esc_html_e('Status', 'simpleboards-roadmap'); ?></strong></label>
            <select name="sbir_status_select" id="sbir_status_select" class="widefat" data-statuses='<?php echo esc_attr(wp_json_encode($status_data)); ?>' <?php disabled(!$is_roadmap_bool); ?>>
                <option value=""><?php esc_html_e('Select Status', 'simpleboards-roadmap'); ?></option>
                <?php foreach ($terms as $term) {
                    $belongs_to = (int) get_term_meta($term->term_id, '_sbir_status_board', true);
                    if ($board_id) {
                        if ($belongs_to === 0 || $belongs_to === (int) $board_id) {
                            echo '<option value="' . esc_attr($term->term_id) . '" ' . selected($current_status, $term->term_id, false) . '>' . esc_html($term->name) . '</option>';
                        }
                    } else {
                        if ($belongs_to === 0) {
                            echo '<option value="' . esc_attr($term->term_id) . '" ' . selected($current_status, $term->term_id, false) . '>' . esc_html($term->name) . '</option>';
                        }
                    }
                } ?>
            </select>
            <?php if (!$is_roadmap_bool) { echo '<span class="description">' . esc_html__('Select “Roadmap Item” to enable status.', 'simpleboards-roadmap') . '</span>'; } ?>
        </p>
        <div class="sbir-terms-checklist">
            <label><strong><?php esc_html_e('Categories', 'simpleboards-roadmap'); ?></strong></label>
            <div class="sbir-terms-box">
                <?php
                $category_terms = get_terms(array(
                    'taxonomy' => 'sbir_category',
                    'hide_empty' => false,
                    'fields' => 'ids',
                    'number' => 1,
                ));

                if (!is_wp_error($category_terms) && !empty($category_terms)) {
                // Render hierarchical checklist for sbir_category
                wp_terms_checklist($post->ID, array(
                    'taxonomy' => 'sbir_category',
                    'checked_ontop' => false
                ));
                } else {
                    echo '<p class="description">' . esc_html__('Categories not available. Create one from SimpleBoards > Categories.', 'simpleboards-roadmap') . '</p>';
                }
                ?>
            </div>
        </div>
        <?php
        ?>
        <p>
            <strong><?php esc_html_e('Votes:', 'simpleboards-roadmap'); ?></strong> 
            <?php echo esc_html(sbir_get_vote_count($post->ID)); ?>
        </p>
        <?php
    }
    
    /**
     * Save meta boxes
     */
    public function save_meta_boxes($post_id) {
        // Check nonce and autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Save board settings
        if (isset($_POST['sbir_board_settings_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['sbir_board_settings_nonce'])), 'sbir_board_settings')) {
            $enable_ideas = isset($_POST['sbir_enable_ideas']) ? 'yes' : 'no';
            update_post_meta($post_id, '_sbir_enable_ideas', $enable_ideas);
            $hide_board_title = isset($_POST['sbir_hide_board_title']) ? 'yes' : 'no';
            update_post_meta($post_id, '_sbir_hide_board_title', $hide_board_title);
            $display_roadmap_filter = isset($_POST['sbir_display_roadmap_filter']) ? 'yes' : 'no';
            update_post_meta($post_id, '_sbir_display_roadmap_filter', $display_roadmap_filter);
            $display_roadmap_sort = isset($_POST['sbir_display_roadmap_sort']) ? 'yes' : 'no';
            update_post_meta($post_id, '_sbir_display_roadmap_sort', $display_roadmap_sort);
            if ($enable_ideas === 'yes' && isset($_POST['sbir_default_tab'])) {
                $default_tab = sanitize_key(wp_unslash($_POST['sbir_default_tab']));
                $default_tab_choices = sbir_get_default_tab_choices((int) $post_id);
                if (!isset($default_tab_choices[$default_tab])) {
                    $default_tab = 'roadmap';
                }
                update_post_meta($post_id, '_sbir_default_tab', $default_tab);
                $display_ideas_filter = isset($_POST['sbir_display_ideas_filter']) ? 'yes' : 'no';
                update_post_meta($post_id, '_sbir_display_ideas_filter', $display_ideas_filter);
                $display_ideas_sort = isset($_POST['sbir_display_ideas_sort']) ? 'yes' : 'no';
                update_post_meta($post_id, '_sbir_display_ideas_sort', $display_ideas_sort);
            } else {
                delete_post_meta($post_id, '_sbir_default_tab');
                delete_post_meta($post_id, '_sbir_display_ideas_filter');
                delete_post_meta($post_id, '_sbir_display_ideas_sort');
            }
        }
        
        // Save item settings
        if (isset($_POST['sbir_item_settings_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['sbir_item_settings_nonce'])), 'sbir_item_settings')) {
            $prev_board_id = get_post_meta($post_id, '_sbir_board_id', true);
            if (isset($_POST['sbir_board_id'])) {
                $new_board_id = absint(wp_unslash($_POST['sbir_board_id']));
                update_post_meta($post_id, '_sbir_board_id', $new_board_id);
                // If board changed, reassign a unique item number scoped to the new board
                if ((string)$prev_board_id !== (string)$new_board_id) {
                    delete_post_meta($post_id, '_sbir_item_number');
                    // Use helper which assigns the next available number for the board
                    if (function_exists('sbir_get_item_number')) {
                        sbir_get_item_number($post_id);
                    }
                }
            }
            
            if (isset($_POST['sbir_is_roadmap'])) {
                $is_roadmap = (sanitize_text_field(wp_unslash($_POST['sbir_is_roadmap'])) === 'yes') ? 'yes' : 'no';
            } else {
                // Backward compatibility with old checkbox submissions
                $is_roadmap = 'no';
            }
            update_post_meta($post_id, '_sbir_is_roadmap', $is_roadmap);

            // Save status only if roadmap and a status was chosen
            if ($is_roadmap === 'yes' && isset($_POST['sbir_status_select'])) {
                $status_term = absint(wp_unslash($_POST['sbir_status_select']));
                if ($status_term) {
                    wp_set_object_terms($post_id, array($status_term), 'sbir_status', false);
                } else {
                    // Clear if none selected
                    wp_set_object_terms($post_id, array(), 'sbir_status', false);
                }
            } elseif ($is_roadmap !== 'yes') {
                // Ensure ideas have no status
                wp_set_object_terms($post_id, array(), 'sbir_status', false);
            }
        }
    }
    
    /**
     * Add custom columns to items list
     */
    public function add_item_columns($columns) {
        $new_columns = array();
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'title') {
                $new_columns['item_number'] = __('#', 'simpleboards-roadmap');
                $new_columns['board'] = __('Board', 'simpleboards-roadmap');
                $new_columns['votes'] = __('Votes', 'simpleboards-roadmap');
                $new_columns['type'] = __('Type', 'simpleboards-roadmap');
            }
        }
        return $new_columns;
    }
    
    /**
     * Render custom columns
     */
    public function render_item_columns($column, $post_id) {
        switch ($column) {
            case 'item_number':
                echo '#' . esc_html(sbir_get_item_number($post_id));
                break;
            case 'board':
                // Resolve the meta to a board, but only render it when the
                // target is still a published sbir_board. Otherwise items
                // can appear "attached" to whatever post happens to live at
                // that ID after a board has been trashed, deleted, or its
                // auto-increment slot recycled by MySQL.
                $board_id = (int) get_post_meta($post_id, '_sbir_board_id', true);
                if ($board_id > 0) {
                    $board = get_post($board_id);
                    if ($board && $board->post_type === 'sbir_board' && $board->post_status === 'publish') {
                        echo '<a href="' . esc_url(get_edit_post_link($board_id)) . '">' . esc_html($board->post_title) . '</a>';
                    }
                }
                break;
            case 'votes':
                echo esc_html(sbir_get_vote_count($post_id));
                break;
            case 'type':
                $is_roadmap = get_post_meta($post_id, '_sbir_is_roadmap', true);
                echo $is_roadmap === 'yes' ? esc_html__('Roadmap', 'simpleboards-roadmap') : esc_html__('Idea', 'simpleboards-roadmap');
                break;
        }
    }

    /**
     * Prime cached vote counts + board post cache for the Items admin list
     * in a single batched query. Eliminates the per-row N+1 pattern that
     * otherwise occurs when rendering the "votes" and "board" columns.
     *
     * @param WP_Post[] $posts    Queried posts.
     * @param WP_Query  $wp_query The query object.
     * @return WP_Post[]
     */
    public function prime_item_caches_for_admin_list($posts, $wp_query) {
        if (!is_admin() || empty($posts) || !$wp_query instanceof WP_Query) {
            return $posts;
        }

        $post_type = isset($wp_query->query_vars['post_type']) ? $wp_query->query_vars['post_type'] : '';
        if ($post_type !== 'sbir_item') {
            return $posts;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'edit-sbir_item') {
            return $posts;
        }

        // Collect IDs that don't already have a cached vote count.
        $missing_ids = array();
        foreach ($posts as $post) {
            if (!$post instanceof WP_Post) {
                continue;
            }
            $cached = wp_cache_get('vote_count_' . $post->ID, SBIR_Cache_Helper::CACHE_GROUP);
            if ($cached === false) {
                $missing_ids[] = (int) $post->ID;
            }
        }

        if (!empty($missing_ids)) {
            global $wpdb;
            $counts_table = $wpdb->prefix . 'sbir_vote_counts';
            $placeholders = implode(',', array_fill(0, count($missing_ids), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs only, single bulk prime query.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT item_id, vote_count FROM {$counts_table} WHERE item_id IN ({$placeholders})",
                    $missing_ids
                )
            );
            $by_id = array();
            foreach ((array) $rows as $row) {
                $by_id[(int) $row->item_id] = (int) $row->vote_count;
            }
            foreach ($missing_ids as $item_id) {
                $count = isset($by_id[$item_id]) ? $by_id[$item_id] : 0;
                wp_cache_set('vote_count_' . $item_id, $count, SBIR_Cache_Helper::CACHE_GROUP, 3600);
            }
        }

        // Prime the board-post cache for every _sbir_board_id referenced by
        // visible rows so the "board" column's get_post() call hits cache.
        $board_ids = array();
        foreach ($posts as $post) {
            if (!$post instanceof WP_Post) {
                continue;
            }
            $board_id = (int) get_post_meta($post->ID, '_sbir_board_id', true);
            if ($board_id > 0) {
                $board_ids[$board_id] = true;
            }
        }
        if (!empty($board_ids)) {
            _prime_post_caches(array_keys($board_ids), false, false);
        }

        return $posts;
    }

    /**
     * Add row actions for moderation workflow on Items table.
     *
     * @param array   $actions Existing actions.
     * @param WP_Post $post    Current post object.
     * @return array
     */
    public function add_item_row_actions($actions, $post) {
        if (!$post instanceof WP_Post || $post->post_type !== 'sbir_item' || !current_user_can('edit_post', $post->ID)) {
            return $actions;
        }

        if ($post->post_status !== 'publish') {
            $publish_url = wp_nonce_url(
                add_query_arg(
                    array(
                        'sbir_item_action' => 'publish',
                        'item_id' => $post->ID,
                    ),
                    admin_url('edit.php?post_type=sbir_item')
                ),
                'sbir_item_row_action_' . $post->ID
            );
            $actions['sbir_publish'] = '<a class="sbir-action-publish" href="' . esc_url($publish_url) . '">' . esc_html__('Publish', 'simpleboards-roadmap') . '</a>';
        }

        if ($post->post_status === 'pending') {
            $reject_url = wp_nonce_url(
                add_query_arg(
                    array(
                        'sbir_item_action' => 'reject',
                        'item_id' => $post->ID,
                    ),
                    admin_url('edit.php?post_type=sbir_item')
                ),
                'sbir_item_row_action_' . $post->ID
            );
            $actions['sbir_reject'] = '<a class="sbir-action-reject" href="' . esc_url($reject_url) . '">' . esc_html__('Reject', 'simpleboards-roadmap') . '</a>';
        }

        return $actions;
    }

    /**
     * Handle custom row action requests.
     *
     * @return void
     */
    public function handle_item_row_action() {
        if (!is_admin()) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked below
        $post_type = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : '';
        if ($post_type !== 'sbir_item') {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked below
        $action = isset($_GET['sbir_item_action']) ? sanitize_key(wp_unslash($_GET['sbir_item_action'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only until validation
        $item_id = isset($_GET['item_id']) ? absint(wp_unslash($_GET['item_id'])) : 0;
        if (!$item_id || !in_array($action, array('publish', 'reject'), true)) {
            return;
        }

        if (!current_user_can('edit_post', $item_id)) {
            return;
        }

        $nonce_action = 'sbir_item_row_action_' . $item_id;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- custom nonce field in URL
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), $nonce_action)) {
            return;
        }

        $target_status = $action === 'publish' ? 'publish' : 'draft';
        wp_update_post(
            array(
                'ID' => $item_id,
                'post_status' => $target_status,
            )
        );
        if ($action === 'reject') {
            $this->notify_item_rejected($item_id);
        }

        wp_safe_redirect(remove_query_arg(array('sbir_item_action', 'item_id', '_wpnonce')));
        exit;
    }

    /**
     * Register custom bulk actions for Items list.
     *
     * @param array $actions Existing actions.
     * @return array
     */
    public function add_item_bulk_actions($actions) {
        $actions['sbir_publish_items'] = __('Publish', 'simpleboards-roadmap');
        $actions['sbir_reject_items'] = __('Reject', 'simpleboards-roadmap');

        return $actions;
    }

    /**
     * Handle custom bulk actions.
     *
     * @param string $redirect_to Redirect URL.
     * @param string $doaction    Action name.
     * @param array  $post_ids    Selected post IDs.
     * @return string
     */
    public function handle_item_bulk_actions($redirect_to, $doaction, $post_ids) {
        if (!in_array($doaction, array('sbir_publish_items', 'sbir_reject_items'), true)) {
            return $redirect_to;
        }

        $updated = 0;
        $target_status = $doaction === 'sbir_publish_items' ? 'publish' : 'draft';
        foreach ((array) $post_ids as $post_id) {
            $post_id = absint($post_id);
            if (!$post_id || !current_user_can('edit_post', $post_id)) {
                continue;
            }

            wp_update_post(
                array(
                    'ID' => $post_id,
                    'post_status' => $target_status,
                )
            );
            if ($doaction === 'sbir_reject_items') {
                $this->notify_item_rejected($post_id);
            }
            $updated++;
        }

        return add_query_arg('sbir_bulk_updated', $updated, $redirect_to);
    }

    /**
     * Render focused bulk edit fields for Items.
     *
     * @param string $column_name Current column name.
     * @param string $post_type   Current post type.
     * @return void
     */
    public function render_item_bulk_edit_fields($column_name, $post_type) {
        if ($post_type !== 'sbir_item') {
            return;
        }

        if ($this->did_render_item_bulk_edit_fields) {
            return;
        }
        $this->did_render_item_bulk_edit_fields = true;

        $boards = $this->get_board_filter_board_ids();
        $statuses = get_terms(
            array(
                'taxonomy' => 'sbir_status',
                'hide_empty' => false,
            )
        );
        $categories = get_terms(
            array(
                'taxonomy' => 'sbir_category',
                'hide_empty' => false,
            )
        );
        ?>
        <fieldset class="inline-edit-col-left sbir-bulk-edit-fields">
            <div class="inline-edit-col">
                <h4><?php esc_html_e('SimpleBoards Options', 'simpleboards-roadmap'); ?></h4>

                <label>
                    <span class="title"><?php esc_html_e('Moderation', 'simpleboards-roadmap'); ?></span>
                    <select name="sbir_bulk_moderation">
                        <option value=""><?php esc_html_e('No Change', 'simpleboards-roadmap'); ?></option>
                        <option value="publish"><?php esc_html_e('Publish', 'simpleboards-roadmap'); ?></option>
                        <option value="draft"><?php esc_html_e('Reject', 'simpleboards-roadmap'); ?></option>
                    </select>
                </label>

                <label>
                    <span class="title"><?php esc_html_e('Board', 'simpleboards-roadmap'); ?></span>
                    <select name="sbir_bulk_board_id">
                        <option value=""><?php esc_html_e('No Change', 'simpleboards-roadmap'); ?></option>
                        <?php foreach ($boards as $board_id) : ?>
                            <option value="<?php echo esc_attr($board_id); ?>"><?php echo esc_html(get_the_title($board_id)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <span class="title"><?php esc_html_e('Type', 'simpleboards-roadmap'); ?></span>
                    <select name="sbir_bulk_type">
                        <option value=""><?php esc_html_e('No Change', 'simpleboards-roadmap'); ?></option>
                        <option value="idea"><?php esc_html_e('Idea', 'simpleboards-roadmap'); ?></option>
                        <option value="roadmap"><?php esc_html_e('Roadmap', 'simpleboards-roadmap'); ?></option>
                    </select>
                </label>

                <label>
                    <span class="title"><?php esc_html_e('Status', 'simpleboards-roadmap'); ?></span>
                    <select name="sbir_bulk_status_term">
                        <option value=""><?php esc_html_e('No Change', 'simpleboards-roadmap'); ?></option>
                        <option value="__clear"><?php esc_html_e('Clear Status', 'simpleboards-roadmap'); ?></option>
                        <?php if (!is_wp_error($statuses) && !empty($statuses)) : ?>
                            <?php foreach ($statuses as $status) : ?>
                                <option value="<?php echo esc_attr($status->term_id); ?>"><?php echo esc_html($status->name); ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </label>

                <label>
                    <span class="title"><?php esc_html_e('Categories', 'simpleboards-roadmap'); ?></span>
                    <select name="sbir_bulk_category_term">
                        <option value=""><?php esc_html_e('No Change', 'simpleboards-roadmap'); ?></option>
                        <option value="__clear"><?php esc_html_e('Clear Categories', 'simpleboards-roadmap'); ?></option>
                        <?php if (!is_wp_error($categories) && !empty($categories)) : ?>
                            <?php foreach ($categories as $category) : ?>
                                <option value="<?php echo esc_attr($category->term_id); ?>"><?php echo esc_html($category->name); ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </label>
            </div>
        </fieldset>
        <?php
    }

    /**
     * Process custom bulk edit fields submitted from Items list.
     *
     * @return void
     */
    public function handle_item_bulk_edit_submission() {
        if (!is_admin()) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked below
        $post_type = isset($_REQUEST['post_type']) ? sanitize_key(wp_unslash($_REQUEST['post_type'])) : '';
        if ($post_type !== 'sbir_item') {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check
        $bulk_edit = isset($_REQUEST['bulk_edit']) ? sanitize_text_field(wp_unslash($_REQUEST['bulk_edit'])) : '';
        if ($bulk_edit !== 'Update') {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below
        if (!isset($_REQUEST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])), 'bulk-posts')) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sanitized immediately
        $post_ids = isset($_REQUEST['post']) ? array_map('absint', (array) wp_unslash($_REQUEST['post'])) : array();
        if (empty($post_ids)) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sanitized below
        $bulk_board = isset($_REQUEST['sbir_bulk_board_id']) ? absint(wp_unslash($_REQUEST['sbir_bulk_board_id'])) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sanitized below
        $bulk_type = isset($_REQUEST['sbir_bulk_type']) ? sanitize_key(wp_unslash($_REQUEST['sbir_bulk_type'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sanitized below
        $bulk_status = isset($_REQUEST['sbir_bulk_status_term']) ? sanitize_text_field(wp_unslash($_REQUEST['sbir_bulk_status_term'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sanitized below
        $bulk_category = isset($_REQUEST['sbir_bulk_category_term']) ? sanitize_text_field(wp_unslash($_REQUEST['sbir_bulk_category_term'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sanitized below
        $bulk_moderation = isset($_REQUEST['sbir_bulk_moderation']) ? sanitize_key(wp_unslash($_REQUEST['sbir_bulk_moderation'])) : '';

        foreach ($post_ids as $post_id) {
            if (!$post_id || !current_user_can('edit_post', $post_id)) {
                continue;
            }

            if ($bulk_board > 0) {
                update_post_meta($post_id, '_sbir_board_id', $bulk_board);
            }

            if ($bulk_type === 'roadmap') {
                update_post_meta($post_id, '_sbir_is_roadmap', 'yes');
            } elseif ($bulk_type === 'idea') {
                update_post_meta($post_id, '_sbir_is_roadmap', 'no');
                wp_set_object_terms($post_id, array(), 'sbir_status', false);
            }

            if ($bulk_status === '__clear') {
                wp_set_object_terms($post_id, array(), 'sbir_status', false);
            } elseif (absint($bulk_status) > 0) {
                $is_roadmap = get_post_meta($post_id, '_sbir_is_roadmap', true) === 'yes';
                if ($is_roadmap) {
                    wp_set_object_terms($post_id, array(absint($bulk_status)), 'sbir_status', false);
                }
            }

            if ($bulk_category === '__clear') {
                wp_set_object_terms($post_id, array(), 'sbir_category', false);
            } elseif (absint($bulk_category) > 0) {
                wp_set_object_terms($post_id, array(absint($bulk_category)), 'sbir_category', false);
            }

            if (in_array($bulk_moderation, array('publish', 'draft'), true)) {
                wp_update_post(
                    array(
                        'ID' => $post_id,
                        'post_status' => $bulk_moderation,
                    )
                );
                if ($bulk_moderation === 'draft') {
                    $this->notify_item_rejected($post_id);
                }
            }
        }
    }

    /**
     * Add Items list views (lifecycle/moderation only).
     */
    public function add_items_views($views) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'edit-sbir_item') { return $views; }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter
        $current = isset($_GET['sbir_view']) ? sanitize_text_field(wp_unslash($_GET['sbir_view'])) : '';
        $base_url = admin_url('edit.php?post_type=sbir_item');
        // Preserve selected board when switching views
        $persist_args = array();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['board_filter'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $persist_args['board_filter'] = absint($_GET['board_filter']);
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['sbir_type'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $persist_args['sbir_type'] = sanitize_key(wp_unslash($_GET['sbir_type']));
        }

        $rejected_count = 0;
        
        // Always resolve rejected count so it never shows "?".
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter
        $rejected_board_filter = isset($_GET['board_filter']) ? absint($_GET['board_filter']) : null;
        $rejected_count = $this->get_rejected_items_count($rejected_board_filter);

        // Preserve existing WP views and add plugin moderation view.
        $rejected_url = add_query_arg(array_merge($persist_args, array('sbir_view' => 'rejected')), $base_url);
        $views['rejected'] = '<a href="' . esc_url($rejected_url) . '" class="' . ($current === 'rejected' ? 'current' : '') . '">' . esc_html__('Rejected', 'simpleboards-roadmap') . ' <span class="count">(' . $rejected_count . ')</span></a>';
        return $views;
    }

    /**
     * Count pending items for optional board scope.
     *
     * @param int|null $board_filter Board ID or null.
     * @return int
     */
    private function get_rejected_items_count($board_filter = null) {
        $args = array(
            'post_type' => 'sbir_item',
            'post_status' => 'draft',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'no_found_rows' => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        );

        if ($board_filter) {
            $args['meta_query'] = array(
                array(
                    'key' => '_sbir_board_id',
                    'value' => (int) $board_filter,
                    'compare' => '=',
                ),
            );
        }

        $query = new WP_Query($args);
        return (int) $query->found_posts;
    }

    /**
     * Apply filtering for Items views in admin list table
     */
    public function filter_items_admin_list($query) {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }
        
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'edit-sbir_item') {
            return;
        }
        
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $view = isset($_GET['sbir_view']) ? sanitize_text_field(wp_unslash($_GET['sbir_view'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $board = isset($_GET['board_filter']) ? absint($_GET['board_filter']) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $type_filter = isset($_GET['sbir_type']) ? sanitize_key(wp_unslash($_GET['sbir_type'])) : '';
        
        if (!$board && !$view && !$type_filter) {
            return;
        }

        if ($view === 'rejected') {
            $query->set('post_status', 'draft');
        }
        
        $meta_query = array('relation' => 'AND');
        
        if ($board) {
            $meta_query[] = array(
                'key' => '_sbir_board_id',
                'value' => $board,
                'compare' => '='
            );
        }
        
        if ($type_filter === 'roadmap') {
            $meta_query[] = SBIR_Query_Helper::get_roadmap_meta_query();
        } elseif ($type_filter === 'ideas') {
            $meta_query[] = SBIR_Query_Helper::get_ideas_meta_query();
        }
        
        $query->set('meta_query', $meta_query);
    }

    /**
     * Send rejection notification email when an item is rejected.
     *
     * @param int $item_id Item post ID.
     */
    private function notify_item_rejected($item_id) {
        $post = get_post($item_id);
        if (!$post || $post->post_type !== 'sbir_item') {
            return;
        }

        if (get_option('sbir_email_rejected', 'yes') !== 'yes') {
            return;
        }

        sbir_send_notification(
            'item_rejected',
            array(
                'title' => $post->post_title,
                'status' => __('Rejected', 'simpleboards-roadmap'),
                'link' => (string) get_edit_post_link($item_id, ''),
            )
        );
    }

    /**
     * Output Board and Type filter dropdowns on Items list screen.
     *
     * @param string $post_type Post type slug.
     * @param string $which     'top' or 'bottom'.
     */
    public function render_board_filter_dropdown($post_type, $which = '') {
        if ($post_type !== 'sbir_item') { return; }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter
        $selected = isset($_GET['board_filter']) ? absint($_GET['board_filter']) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter
        $selected_type = isset($_GET['sbir_type']) ? sanitize_key(wp_unslash($_GET['sbir_type'])) : '';

        $boards = $this->get_board_filter_board_ids();
        echo '<label class="screen-reader-text" for="filter-by-sbir-board">' . esc_html__('Filter by board', 'simpleboards-roadmap') . '</label>';
        // Ensure the control is tied to the main posts form even if markup shifts
        echo '<select name="board_filter" id="filter-by-sbir-board" form="posts-filter">';
        echo '<option value="">' . esc_html__('All Boards', 'simpleboards-roadmap') . '</option>';
        foreach ($boards as $bid) {
            $title = get_the_title($bid);
            echo '<option value="' . esc_attr($bid) . '" ' . selected($selected, $bid, false) . '>' . esc_html($title) . '</option>';
        }
        echo '</select>';

        echo '<label class="screen-reader-text" for="filter-by-sbir-type">' . esc_html__('Filter by item type', 'simpleboards-roadmap') . '</label>';
        echo '<select name="sbir_type" id="filter-by-sbir-type" form="posts-filter">';
        echo '<option value="">' . esc_html__('All Types', 'simpleboards-roadmap') . '</option>';
        echo '<option value="roadmap" ' . selected($selected_type, 'roadmap', false) . '>' . esc_html__('Roadmap', 'simpleboards-roadmap') . '</option>';
        echo '<option value="ideas" ' . selected($selected_type, 'ideas', false) . '>' . esc_html__('Ideas', 'simpleboards-roadmap') . '</option>';
        echo '</select>';
    }

    /**
     * Get board IDs for the Items filter dropdown in batches.
     */
    private function get_board_filter_board_ids() {
        $board_ids = array();
        $page = 1;
        $per_page = (int) apply_filters('sbir_admin_board_filter_batch_size', 200);
        if ($per_page < 1) {
            $per_page = 200;
        }

        while (true) {
            $batch = get_posts(array(
                'post_type' => 'sbir_board',
                'post_status' => array('publish', 'private'),
                'posts_per_page' => $per_page,
                'paged' => $page,
                'orderby' => 'title',
                'order' => 'ASC',
                'fields' => 'ids',
                'no_found_rows' => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ));

            if (empty($batch)) {
                break;
            }

            $board_ids = array_merge($board_ids, $batch);
            if (count($batch) < $per_page) {
                break;
            }

            $page++;
        }

        return $board_ids;
    }
    
    /**
     * Add custom columns to Boards list table.
     *
     * @param array $columns Existing columns.
     * @return array
     */
    public function add_board_columns($columns) {
        $new = array();
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'title') {
                $new['sbir_items_total'] = __('Items', 'simpleboards-roadmap');
                $new['sbir_ideas'] = __('Ideas', 'simpleboards-roadmap');
                $new['sbir_roadmap'] = __('Roadmap', 'simpleboards-roadmap');
                $new['sbir_shortcode'] = __('Shortcode', 'simpleboards-roadmap');
            }
        }
        return $new;
    }

    /**
     * Get board counts (cached per request to avoid multiple queries)
     */
    private static $board_counts_cache = null;
    
    private function get_board_counts() {
        if (self::$board_counts_cache !== null) {
            return self::$board_counts_cache;
        }
        
        global $wpdb;
        $statuses_arr = SBIR_Query_Helper::get_valid_post_statuses();
        $placeholders = implode(',', array_fill(0, count($statuses_arr), '%s'));
        $sql = "SELECT 
                    pm1.meta_value as board_id,
                    CASE WHEN pm2.meta_value = 'yes' THEN 'roadmap' ELSE 'ideas' END as type,
                    COUNT(*) as count
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_sbir_board_id'
                LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_sbir_is_roadmap'
                WHERE p.post_type = %s 
                AND p.post_status IN ($placeholders)
                GROUP BY board_id, type";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
        $results = $wpdb->get_results($wpdb->prepare($sql, array_merge(array('sbir_item'), $statuses_arr)));
        
        $counts = array();
        foreach ($results as $result) {
            $board_id = intval($result->board_id);
            if (!isset($counts[$board_id])) {
                $counts[$board_id] = array('total' => 0, 'roadmap' => 0, 'ideas' => 0);
            }
            $counts[$board_id][$result->type] = intval($result->count);
            $counts[$board_id]['total'] += intval($result->count);
        }
        
        self::$board_counts_cache = $counts;
        return $counts;
    }

    /**
     * Render custom column content for Boards list.
     *
     * @param string $column  Column name.
     * @param int    $post_id Board post ID.
     */
    public function render_board_columns($column, $post_id) {
        switch ($column) {
            case 'sbir_items_total':
            case 'sbir_ideas':
            case 'sbir_roadmap':
                $counts = $this->get_board_counts();
                $board_counts = isset($counts[$post_id]) ? $counts[$post_id] : array('total' => 0, 'roadmap' => 0, 'ideas' => 0);
                
                $count = 0;
                if ($column === 'sbir_items_total') {
                    $count = $board_counts['total'];
                } elseif ($column === 'sbir_ideas') {
                    $count = $board_counts['ideas'];
                } elseif ($column === 'sbir_roadmap') {
                    $count = $board_counts['roadmap'];
                }
                
                $base = admin_url('edit.php?post_type=sbir_item');
                $params = array('board_filter' => $post_id);
                if ($column === 'sbir_ideas') { $params['sbir_type'] = 'ideas'; }
                if ($column === 'sbir_roadmap') { $params['sbir_type'] = 'roadmap'; }
                $url = add_query_arg($params, $base);
                echo '<a href="' . esc_url($url) . '">' . esc_html($count) . '</a>';
                break;
            case 'sbir_shortcode':
                $slug = get_post_field('post_name', $post_id);
                echo '<code>[sbir_board product="' . esc_html($slug) . '"]</code>';
                break;
        }
    }
    /**
     * AJAX: Compute impact of reassigning a status to a different board.
     */
    public function ajax_status_reassign_impact() {
        check_ajax_referer('sbir_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_die(); }

        $term_id = isset($_POST['term_id']) ? absint(wp_unslash($_POST['term_id'])) : 0;
        $target_board_id = isset($_POST['target_board_id']) ? absint(wp_unslash($_POST['target_board_id'])) : 0; // 0 => global

        if (!$term_id) { wp_send_json_error(); }

        // Determine current board assignment
        $current_board_id = (int) get_term_meta($term_id, '_sbir_status_board', true);

        // If moving from global to a specific board, we need to find items on other boards
        $affected = array();
        if ($target_board_id && $target_board_id !== $current_board_id) {
            $query = new \WP_Query(array(
                'post_type' => 'sbir_item',
                'post_status' => SBIR_Query_Helper::get_valid_post_statuses(),
                'posts_per_page' => 10,
                'fields' => 'ids',
                'no_found_rows' => true,
                'tax_query' => array(
                    array('taxonomy' => 'sbir_status', 'field' => 'term_id', 'terms' => $term_id)
                ),
                'meta_query' => array(
                    array('key' => '_sbir_is_roadmap', 'value' => 'yes', 'compare' => '=')
                )
            ));
            $all_ids = $query->posts;
            // Filter to those NOT in the target board
            $filtered = array();
            foreach ($all_ids as $pid) {
                $b = (int) get_post_meta($pid, '_sbir_board_id', true);
                if ($b && $b !== $target_board_id) { $filtered[] = $pid; }
            }
            $affected = array_slice($filtered, 0, 5);
            $count = count($filtered);

            $items = array();
            foreach ($affected as $pid) {
                $items[] = array(
                    'id' => $pid,
                    'title' => get_the_title($pid),
                    'edit_link' => get_edit_post_link($pid, '')
                );
            }

            $board_title = $target_board_id ? get_the_title($target_board_id) : __('All Boards', 'simpleboards-roadmap');
            $message = sprintf(
                /* translators: 1: board title, 2: count */
                __('Moving this status to %1$s will affect %2$d items on other boards. These items will become unassigned.', 'simpleboards-roadmap'),
                $board_title,
                $count
            );

            wp_send_json_success(array('count' => $count, 'items' => $items, 'message' => $message));
        }

        wp_send_json_success(array('count' => 0, 'items' => array(), 'message' => ''));
    }

}