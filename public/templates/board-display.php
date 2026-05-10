<?php
/**
 * Board display template
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

$board_id = isset($board_id) ? $board_id : get_the_ID();
$view = isset($view) ? $view : 'both';
$enable_ideas = isset($enable_ideas) ? $enable_ideas : true;

$default_tab = 'roadmap';
if ($enable_ideas) {
    $stored_default = get_post_meta($board_id, '_sbir_default_tab', true);
    $default_tab = ($stored_default === 'ideas') ? 'ideas' : 'roadmap';
}

// Allow plugins to override the default tab (see sbir_board_default_tab in class-sbir-frontend.php).
$default_tab = apply_filters('sbir_board_default_tab', $default_tab, $board_id);

$active_tab = 'roadmap';
$requested_item_id = 0;
$requested_tab = sanitize_key((string) get_query_var('sbir_tab'));
$hide_board_title = get_post_meta($board_id, '_sbir_hide_board_title', true);
$hide_board_title = ($hide_board_title === '') ? 'yes' : $hide_board_title;
$show_board_header = ($hide_board_title !== 'yes');

if ($view === 'ideas' && $enable_ideas) {
    $active_tab = 'ideas';
} elseif ($view === 'roadmap') {
    $active_tab = 'roadmap';
} elseif ($view === 'both' && $enable_ideas) {
    if ($requested_tab === 'ideas' || $requested_tab === 'roadmap') {
        $active_tab = $requested_tab;
    } else {
        $active_tab = $default_tab;
    }
}

/**
 * Filter active board tab so add-ons can provide extra tabs.
 *
 * @param string $active_tab    Current active tab.
 * @param string $requested_tab Requested tab from URL.
 * @param int    $board_id      Board ID.
 * @param string $view          Current view mode.
 * @param bool   $enable_ideas  Whether ideas are enabled.
 * @param string $default_tab   Default tab for this board.
 */
$active_tab = apply_filters('sbir_board_active_tab', $active_tab, $requested_tab, (int) $board_id, $view, $enable_ideas, $default_tab);

$requested_item_slug = sanitize_title((string) get_query_var('sbir_item_slug'));
if ($requested_item_slug) {
    $requested_item = get_page_by_path($requested_item_slug, OBJECT, 'sbir_item');
    if ($requested_item instanceof WP_Post) {
        $requested_item_board_id = (int) get_post_meta($requested_item->ID, '_sbir_board_id', true);
        if ($requested_item_board_id === (int) $board_id) {
            $requested_item_id = (int) $requested_item->ID;
        }
    }
}

$tabs_uid = function_exists('wp_unique_id') ? wp_unique_id('sbir-tabs-') : ('sbir-tabs-' . absint($board_id));
$roadmap_pane_id = $tabs_uid . '-roadmap';
$ideas_pane_id = $tabs_uid . '-ideas';
$board_url = get_permalink($board_id);
$ideas_url = $board_url ? user_trailingslashit(trailingslashit($board_url) . 'ideas') : '';
$roadmap_url = $board_url ? user_trailingslashit(trailingslashit($board_url) . 'roadmap') : '';
$board_title = (string) get_post_field('post_title', $board_id);
$board_description = '';
$board_post = get_post($board_id);
if ($board_post instanceof WP_Post) {
    if (!empty($board_post->post_excerpt)) {
        $board_description = wp_kses_post($board_post->post_excerpt);
    } elseif (!empty($board_post->post_content)) {
        $board_description = wp_kses_post(wp_trim_words(wp_strip_all_tags($board_post->post_content), 40));
    }
}

$tab_order = ($enable_ideas && $default_tab === 'ideas')
    ? array('ideas', 'roadmap')
    : array('roadmap', 'ideas');
$display_roadmap_filter = (string) get_post_meta((int) $board_id, '_sbir_display_roadmap_filter', true);
$display_roadmap_filter = ($display_roadmap_filter === '') ? 'yes' : $display_roadmap_filter;
$display_roadmap_sort = (string) get_post_meta((int) $board_id, '_sbir_display_roadmap_sort', true);
$display_roadmap_sort = ($display_roadmap_sort === '') ? 'yes' : $display_roadmap_sort;
$display_ideas_filter = (string) get_post_meta((int) $board_id, '_sbir_display_ideas_filter', true);
$display_ideas_filter = ($display_ideas_filter === '') ? 'yes' : $display_ideas_filter;
$display_ideas_sort = (string) get_post_meta((int) $board_id, '_sbir_display_ideas_sort', true);
$display_ideas_sort = ($display_ideas_sort === '') ? 'yes' : $display_ideas_sort;
$board_container_attrs = apply_filters(
    'sbir_board_container_attributes',
    array(),
    (int) $board_id
);
?>

<div class="sbir-board-container" data-board-id="<?php echo esc_attr($board_id); ?>" data-requested-item-id="<?php echo esc_attr($requested_item_id); ?>" data-default-tab="<?php echo esc_attr($default_tab); ?>"<?php
if (!empty($board_container_attrs) && is_array($board_container_attrs)) {
    foreach ($board_container_attrs as $attr_key => $attr_value) {
        $attr_name = sanitize_key((string) $attr_key);
        if ($attr_name === '') {
            continue;
        }
        echo ' ' . esc_attr($attr_name) . '="' . esc_attr((string) $attr_value) . '"';
    }
}
?>>

    <?php if ($show_board_header) : ?>
        <header class="sbir-board-header">
            <h1 class="sbir-board-title"><?php echo esc_html($board_title); ?></h1>
            <?php if ($board_description !== '') : ?>
                <p class="sbir-board-description"><?php echo esc_html($board_description); ?></p>
            <?php endif; ?>
        </header>
    <?php endif; ?>
    
    <?php if ($view === 'both') : ?>
        <div class="sbir-board-toolbar">
            <div class="sbir-tabs sbir-board-nav" role="tablist" aria-label="<?php esc_attr_e('Board sections', 'simpleboards-roadmap'); ?>">
                <?php
                foreach ($tab_order as $tab_key) {
                    if ($tab_key === 'ideas' && !$enable_ideas) {
                        continue;
                    }
                    if ($tab_key === 'ideas') {
                        ?>
                    <a
                        class="sbir-tab <?php echo $active_tab === 'ideas' ? 'active' : ''; ?>"
                        href="<?php echo esc_url($ideas_url); ?>"
                        data-tab="ideas"
                        role="tab"
                        aria-selected="<?php echo $active_tab === 'ideas' ? 'true' : 'false'; ?>"
                        aria-controls="<?php echo esc_attr($ideas_pane_id); ?>"
                    >
                        <?php esc_html_e('Ideas', 'simpleboards-roadmap'); ?>
                    </a>
                        <?php
                    } else {
                        ?>
                    <a
                        class="sbir-tab <?php echo $active_tab === 'roadmap' ? 'active' : ''; ?>"
                        href="<?php echo esc_url($roadmap_url); ?>"
                        data-tab="roadmap"
                        role="tab"
                        aria-selected="<?php echo $active_tab === 'roadmap' ? 'true' : 'false'; ?>"
                        aria-controls="<?php echo esc_attr($roadmap_pane_id); ?>"
                    >
                <?php esc_html_e('Roadmap', 'simpleboards-roadmap'); ?>
                    </a>
                        <?php
                    }
                }
                ?>
                <?php do_action('sbir_render_board_extra_tabs_nav', (int) $board_id, (string) $active_tab, (string) $tabs_uid, (string) $board_url, (string) $view, (bool) $enable_ideas); ?>
            </div>
            <div class="sbir-board-toolbar-controls">
                <div class="sbir-board-search">
                    <label class="screen-reader-text" for="<?php echo esc_attr($tabs_uid); ?>-search"><?php esc_html_e('Search board items', 'simpleboards-roadmap'); ?></label>
                    <input
                        type="search"
                        id="<?php echo esc_attr($tabs_uid); ?>-search"
                        class="sbir-board-search-input"
                        placeholder="<?php echo $active_tab === 'ideas' ? esc_attr__('Search ideas...', 'simpleboards-roadmap') : esc_attr__('Search roadmap items...', 'simpleboards-roadmap'); ?>"
                        autocomplete="off"
                    >
                </div>
                <div class="sbir-board-filters" aria-label="<?php esc_attr_e('Board filters and sorting', 'simpleboards-roadmap'); ?>">
                    <?php if ($display_roadmap_filter === 'yes') : ?>
                        <div class="sbir-board-filter sbir-board-filter-roadmap">
                            <label class="screen-reader-text" for="<?php echo esc_attr($tabs_uid); ?>-card-filter"><?php esc_html_e('Filter roadmap cards', 'simpleboards-roadmap'); ?></label>
                            <select id="<?php echo esc_attr($tabs_uid); ?>-card-filter" class="sbir-board-filter-select sbir-board-card-filter" data-filter-type="roadmap">
                                <option value="all"><?php esc_html_e('All cards', 'simpleboards-roadmap'); ?></option>
                            </select>
                        </div>
                    <?php endif; ?>
                    <?php if ($display_roadmap_sort === 'yes') : ?>
                        <div class="sbir-board-sort sbir-board-sort-roadmap">
                            <label class="screen-reader-text" for="<?php echo esc_attr($tabs_uid); ?>-roadmap-sort"><?php esc_html_e('Sort roadmap cards', 'simpleboards-roadmap'); ?></label>
                            <select id="<?php echo esc_attr($tabs_uid); ?>-roadmap-sort" class="sbir-board-filter-select sbir-board-roadmap-sort" data-sort-type="roadmap">
                                <option value="default"><?php esc_html_e('Sort: Default', 'simpleboards-roadmap'); ?></option>
                                <option value="newest"><?php esc_html_e('Sort: Newest', 'simpleboards-roadmap'); ?></option>
                                <option value="oldest"><?php esc_html_e('Sort: Oldest', 'simpleboards-roadmap'); ?></option>
                                <option value="votes"><?php esc_html_e('Sort: Most voted', 'simpleboards-roadmap'); ?></option>
                            </select>
                        </div>
                    <?php endif; ?>
                    <?php if ($display_ideas_filter === 'yes') : ?>
                        <div class="sbir-board-filter sbir-board-filter-ideas">
                            <label class="screen-reader-text" for="<?php echo esc_attr($tabs_uid); ?>-ideas-filter"><?php esc_html_e('Filter ideas', 'simpleboards-roadmap'); ?></label>
                            <select id="<?php echo esc_attr($tabs_uid); ?>-ideas-filter" class="sbir-board-filter-select sbir-board-ideas-filter" data-filter-type="ideas">
                                <option value="all"><?php esc_html_e('All ideas', 'simpleboards-roadmap'); ?></option>
                            </select>
                        </div>
                    <?php endif; ?>
                    <?php if ($display_ideas_sort === 'yes') : ?>
                        <div class="sbir-board-sort sbir-board-sort-ideas">
                            <label class="screen-reader-text" for="<?php echo esc_attr($tabs_uid); ?>-ideas-sort"><?php esc_html_e('Sort ideas', 'simpleboards-roadmap'); ?></label>
                            <select id="<?php echo esc_attr($tabs_uid); ?>-ideas-sort" class="sbir-board-filter-select sbir-board-ideas-sort" data-sort-type="ideas">
                                <option value="default"><?php esc_html_e('Sort: Default', 'simpleboards-roadmap'); ?></option>
                                <option value="newest"><?php esc_html_e('Sort: Newest', 'simpleboards-roadmap'); ?></option>
                                <option value="oldest"><?php esc_html_e('Sort: Oldest', 'simpleboards-roadmap'); ?></option>
                                <option value="votes"><?php esc_html_e('Sort: Most voted', 'simpleboards-roadmap'); ?></option>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
    
    <div class="sbir-tab-content">
        <?php
        $show_ideas_pane = $enable_ideas && ($view === 'ideas' || $view === 'both');
        $ideas_first = $show_ideas_pane && ($default_tab === 'ideas');

        if ($ideas_first) :
            ?>
        <div
            id="<?php echo esc_attr($ideas_pane_id); ?>"
            class="sbir-tab-pane <?php echo ($active_tab === 'ideas' || $view === 'ideas') ? 'active' : ''; ?>"
            data-tab="ideas"
            role="tabpanel"
        >
            <?php include SBIR_PLUGIN_DIR . 'public/templates/ideas-view.php'; ?>
        </div>
            <?php
        endif;

        ?>
        <div
            id="<?php echo esc_attr($roadmap_pane_id); ?>"
            class="sbir-tab-pane <?php echo ($active_tab === 'roadmap' || $view === 'roadmap' || !$enable_ideas) ? 'active' : ''; ?>"
            data-tab="roadmap"
            role="tabpanel"
        >
            <?php include SBIR_PLUGIN_DIR . 'public/templates/roadmap-view.php'; ?>
        </div>
        <?php
        if ($show_ideas_pane && !$ideas_first) :
            ?>
        <div
            id="<?php echo esc_attr($ideas_pane_id); ?>"
            class="sbir-tab-pane <?php echo ($active_tab === 'ideas' || $view === 'ideas') ? 'active' : ''; ?>"
            data-tab="ideas"
            role="tabpanel"
        >
                <?php include SBIR_PLUGIN_DIR . 'public/templates/ideas-view.php'; ?>
            </div>
        <?php endif; ?>
        <?php do_action('sbir_render_board_extra_tab_panes', (int) $board_id, (string) $active_tab, (string) $tabs_uid, (string) $view, (bool) $enable_ideas); ?>
    </div>
</div>
