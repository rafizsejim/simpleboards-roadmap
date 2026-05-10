<?php
/**
 * Ideas list view template
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

$board_id = isset($board_id) ? $board_id : get_the_ID();

if (!isset($ideas_query) || !($ideas_query instanceof WP_Query)) {
    $ideas_query = sbir_get_board_items($board_id, 'ideas');
}

$idea_posts = ($ideas_query instanceof WP_Query && !empty($ideas_query->posts)) ? $ideas_query->posts : array();
$idea_posts = array_values(array_filter($idea_posts, function($idea_post) {
    $item_id = isset($idea_post->ID) ? (int) $idea_post->ID : 0;
    return $item_id > 0 && sbir_current_user_can_access_item($item_id, 'ideas_view');
}));
$idea_ids = wp_list_pluck($idea_posts, 'ID');

$categories_by_item = array();
$category_counts = array();
if (!empty($idea_ids)) {
    $term_links = wp_get_object_terms($idea_ids, 'sbir_category', array('fields' => 'all_with_object_id'));
    if (!is_wp_error($term_links) && !empty($term_links)) {
        foreach ($term_links as $term_link) {
            $item_id = isset($term_link->object_id) ? (int) $term_link->object_id : 0;
            $term_id = isset($term_link->term_id) ? (int) $term_link->term_id : 0;
            if ($item_id <= 0 || $term_id <= 0) {
                continue;
            }
            if (!isset($categories_by_item[$item_id])) {
                $categories_by_item[$item_id] = array();
            }
            if (!isset($categories_by_item[$item_id][$term_id])) {
                $categories_by_item[$item_id][$term_id] = array(
                    'term_id' => $term_id,
                    'name' => isset($term_link->name) ? $term_link->name : '',
                );
                if (!isset($category_counts[$term_id])) {
                    $category_counts[$term_id] = array(
                        'term_id' => $term_id,
                        'name' => isset($term_link->name) ? $term_link->name : '',
                        'count' => 0,
                    );
                }
                $category_counts[$term_id]['count']++;
            }
        }
    }
}

if (!empty($category_counts)) {
    uasort($category_counts, function($a, $b) {
        return ((int) $b['count']) <=> ((int) $a['count']);
    });
}
$category_counts = array_slice(array_values($category_counts), 0, 8);

$top_voted = sbir_get_board_top_voted_ideas($board_id, 5);
$recent_discussed = sbir_get_board_recently_discussed_ideas($board_id, 5);
$compose_button_text = (string) get_option('sbir_submission_compose_button_text', __('Share an idea...', 'simpleboards-roadmap'));
if ($compose_button_text === '') {
    $compose_button_text = __('Share an idea...', 'simpleboards-roadmap');
}
$top_voted = array_values(array_filter($top_voted, function($row) {
    $item_id = isset($row['item_id']) ? (int) $row['item_id'] : 0;
    return $item_id > 0 && sbir_current_user_can_access_item($item_id, 'ideas_top_voted');
}));
$recent_discussed = array_values(array_filter($recent_discussed, function($row) {
    $item_id = isset($row['item_id']) ? (int) $row['item_id'] : 0;
    return $item_id > 0 && sbir_current_user_can_access_item($item_id, 'ideas_recent_discussed');
}));

$widget_item_ids = array();
foreach ($top_voted as $row) {
    if (!empty($row['item_id'])) {
        $widget_item_ids[] = (int) $row['item_id'];
    }
}
foreach ($recent_discussed as $row) {
    if (!empty($row['item_id'])) {
        $widget_item_ids[] = (int) $row['item_id'];
    }
}
$widget_item_ids = array_values(array_unique(array_filter(array_map('intval', $widget_item_ids))));
$widget_posts_map = array();
if (!empty($widget_item_ids)) {
    $widget_statuses = current_user_can('edit_posts') ? array('publish', 'pending') : array('publish');
    $widget_posts = get_posts(array(
        'post_type' => 'sbir_item',
        'post__in' => $widget_item_ids,
        'posts_per_page' => count($widget_item_ids),
        'post_status' => $widget_statuses,
        'orderby' => 'post__in',
    ));
    foreach ($widget_posts as $widget_post) {
        $widget_posts_map[(int) $widget_post->ID] = $widget_post;
    }
}
?>

<?php
/**
 * Ideas per-page value (client-side paginates already-loaded posts).
 * Filterable so hosts can tune for large boards.
 */
$ideas_per_page = (int) apply_filters('sbir_frontend_ideas_per_page', 15, (int) $board_id);
if ($ideas_per_page < 1) {
    $ideas_per_page = 15;
}
?>
<div class="sbir-ideas sbir-ideas-layout">
    <div class="sbir-ideas-main">
        <div class="sbir-ideas-list" data-ideas-per-page="<?php echo esc_attr((string) $ideas_per_page); ?>">
            <?php if (!empty($idea_posts)) : ?>
                <?php foreach ($idea_posts as $idea_post) : ?>
                    <?php
                    $item_id = (int) $idea_post->ID;
                    $item_title = get_the_title($item_id);
                    $item_permalink = get_permalink($item_id);
                    $created_ts = (int) get_post_time('U', true, $item_id);
                    $summary = wp_trim_words(wp_strip_all_tags((string) $idea_post->post_content), 30);
                    $item_categories = isset($categories_by_item[$item_id]) ? array_values($categories_by_item[$item_id]) : array();
                    ?>
                    <div class="sbir-idea-item" data-item-id="<?php echo esc_attr($item_id); ?>" data-created-ts="<?php echo esc_attr($created_ts); ?>">
                        <div class="sbir-idea-vote">
                            <?php sbir_render_vote_button($item_id); ?>
                        </div>

                        <div class="sbir-idea-content">
                            <div class="sbir-idea-header">
                                <h3 class="sbir-idea-title">
                                    <a class="sbir-open-drawer" href="<?php echo esc_url($item_permalink); ?>" data-item-id="<?php echo esc_attr($item_id); ?>"><?php echo esc_html($item_title); ?></a>
                                </h3>
                                <span class="sbir-idea-number">#<?php echo esc_html(sbir_get_item_number($item_id)); ?></span>
                            </div>

                            <div class="sbir-idea-description"><?php echo esc_html($summary); ?></div>

                            <?php if (!empty($item_categories)) : ?>
                                <div class="sbir-idea-meta">
                                    <?php foreach ($item_categories as $cat) : ?>
                                        <span class="sbir-category"><?php echo esc_html(isset($cat['name']) ? $cat['name'] : ''); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php sbir_render_item_meta($item_id); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else : ?>
                <div class="sbir-no-ideas">
                    <p><?php esc_html_e('No ideas submitted yet. Be the first to submit an idea!', 'simpleboards-roadmap'); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <nav class="sbir-ideas-pagination" aria-label="<?php esc_attr_e('Ideas pagination', 'simpleboards-roadmap'); ?>" hidden>
            <button type="button" class="sbir-pagination-btn sbir-pagination-prev" data-direction="prev" aria-label="<?php esc_attr_e('Previous page', 'simpleboards-roadmap'); ?>">
                <?php esc_html_e('Previous', 'simpleboards-roadmap'); ?>
            </button>
            <ol class="sbir-pagination-pages" role="list"></ol>
            <button type="button" class="sbir-pagination-btn sbir-pagination-next" data-direction="next" aria-label="<?php esc_attr_e('Next page', 'simpleboards-roadmap'); ?>">
                <?php esc_html_e('Next', 'simpleboards-roadmap'); ?>
            </button>
            <span class="sbir-pagination-summary" aria-live="polite"></span>
        </nav>
    </div>

    <aside class="sbir-ideas-sidebar" aria-label="<?php esc_attr_e('Ideas widgets', 'simpleboards-roadmap'); ?>">
        <div class="sbir-widget sbir-widget-compose">
            <button type="button" class="sbir-btn sbir-btn-primary sbir-submit-idea-btn sbir-idea-compose-trigger" aria-label="<?php echo esc_attr($compose_button_text); ?>">
                <?php echo esc_html($compose_button_text); ?>
            </button>
        </div>

        <div class="sbir-widget">
            <h4 class="sbir-widget-title"><?php esc_html_e('Top voted', 'simpleboards-roadmap'); ?></h4>
            <?php if (!empty($top_voted)) : ?>
                <ul class="sbir-widget-list">
                    <?php foreach ($top_voted as $row) : ?>
                        <?php
                        $item_id = isset($row['item_id']) ? (int) $row['item_id'] : 0;
                        if (!$item_id || !isset($widget_posts_map[$item_id])) {
                            continue;
                        }
                        $item = $widget_posts_map[$item_id];
                        ?>
                        <li>
                            <a class="sbir-widget-link sbir-open-drawer" href="<?php echo esc_url(get_permalink($item_id)); ?>" data-item-id="<?php echo esc_attr($item_id); ?>">
                                <span class="sbir-widget-link-title"><?php echo esc_html(get_the_title($item)); ?></span>
                                <span class="sbir-widget-link-meta"><?php echo esc_html((int) $row['vote_count']); ?> <?php esc_html_e('votes', 'simpleboards-roadmap'); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <p class="sbir-widget-empty"><?php esc_html_e('No votes yet.', 'simpleboards-roadmap'); ?></p>
            <?php endif; ?>
        </div>

        <div class="sbir-widget">
            <h4 class="sbir-widget-title"><?php esc_html_e('Recently discussed', 'simpleboards-roadmap'); ?></h4>
            <?php if (!empty($recent_discussed)) : ?>
                <ul class="sbir-widget-list">
                    <?php foreach ($recent_discussed as $row) : ?>
                        <?php
                        $item_id = isset($row['item_id']) ? (int) $row['item_id'] : 0;
                        if (!$item_id || !isset($widget_posts_map[$item_id])) {
                            continue;
                        }
                        $latest_gmt = isset($row['latest_comment_gmt']) ? (string) $row['latest_comment_gmt'] : '';
                        $latest_ts = $latest_gmt ? strtotime($latest_gmt . ' GMT') : 0;
                        ?>
                        <li>
                            <a class="sbir-widget-link sbir-open-drawer" href="<?php echo esc_url(get_permalink($item_id)); ?>" data-item-id="<?php echo esc_attr($item_id); ?>">
                                <span class="sbir-widget-link-title"><?php echo esc_html(get_the_title($widget_posts_map[$item_id])); ?></span>
                                <span class="sbir-widget-link-meta">
                                    <?php echo esc_html((int) $row['comment_count']); ?> <?php esc_html_e('comments', 'simpleboards-roadmap'); ?>
                                    <?php if ($latest_ts > 0) : ?>
                                        <?php
                                        /* translators: %s: relative time difference, e.g. 2 hours */
                                        echo esc_html(' - ' . sprintf(esc_html__('%s ago', 'simpleboards-roadmap'), human_time_diff($latest_ts, current_time('timestamp'))));
                                        ?>
                                    <?php endif; ?>
                                </span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <p class="sbir-widget-empty"><?php esc_html_e('No discussions yet.', 'simpleboards-roadmap'); ?></p>
            <?php endif; ?>
        </div>

        <div class="sbir-widget">
            <h4 class="sbir-widget-title"><?php esc_html_e('Categories in this view', 'simpleboards-roadmap'); ?></h4>
            <?php if (!empty($category_counts)) : ?>
                <div class="sbir-widget-categories">
                    <?php foreach ($category_counts as $cat_row) : ?>
                        <span class="sbir-category">
                            <?php echo esc_html(isset($cat_row['name']) ? $cat_row['name'] : ''); ?>
                            <span class="sbir-widget-count"><?php echo esc_html((int) $cat_row['count']); ?></span>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <p class="sbir-widget-empty"><?php esc_html_e('No categories yet.', 'simpleboards-roadmap'); ?></p>
            <?php endif; ?>
        </div>
    </aside>
</div>

<?php
$form_title = get_option('sbir_submission_form_title', __('Submit Your Idea', 'simpleboards-roadmap'));
$form_description = get_option('sbir_submission_form_description', '');
$categories = get_terms(array(
    'taxonomy' => 'sbir_category',
    'hide_empty' => false
));
$categories_available = !is_wp_error($categories) && !empty($categories);
?>

<div class="sbir-idea-modal" aria-hidden="true">
    <div class="sbir-modal-overlay"></div>
    <div class="sbir-modal-panel" role="dialog" aria-modal="true" aria-labelledby="sbir-idea-modal-title">
        <button type="button" class="sbir-modal-close" aria-label="<?php esc_attr_e('Close', 'simpleboards-roadmap'); ?>">×</button>
        <h3 id="sbir-idea-modal-title" class="sbir-modal-title"><?php echo esc_html($form_title); ?></h3>
        <?php if ($form_description) : ?>
            <p class="sbir-form-description"><?php echo esc_html($form_description); ?></p>
        <?php endif; ?>
        <form id="sbir-idea-form" method="post">
            <input type="hidden" name="board_id" value="<?php echo esc_attr($board_id); ?>">
            <div class="sbir-form-group">
                <label for="sbir-title"><?php esc_html_e('Title', 'simpleboards-roadmap'); ?> <span class="required">*</span></label>
                <input type="text" id="sbir-title" name="title" required>
            </div>
            <div class="sbir-form-group">
                <label for="sbir-description"><?php esc_html_e('Description', 'simpleboards-roadmap'); ?> <span class="required">*</span></label>
                <textarea id="sbir-description" name="description" rows="5" required></textarea>
            </div>
            <div class="sbir-form-group">
                <label for="sbir-category"><?php esc_html_e('Category', 'simpleboards-roadmap'); ?></label>
                <select id="sbir-category" name="category" <?php disabled(!$categories_available); ?>>
                    <?php if ($categories_available) : ?>
                        <option value=""><?php esc_html_e('Select Category', 'simpleboards-roadmap'); ?></option>
                        <?php foreach ($categories as $category) : ?>
                            <option value="<?php echo esc_attr($category->term_id); ?>">
                                <?php echo esc_html($category->name); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <option value="" selected><?php esc_html_e('Categories not available', 'simpleboards-roadmap'); ?></option>
                    <?php endif; ?>
                </select>
            </div>
            <?php if (!is_user_logged_in() && get_option('sbir_enable_guest_submissions') === 'yes') : ?>
                <div class="sbir-form-group">
                    <label for="sbir-name"><?php esc_html_e('Name', 'simpleboards-roadmap'); ?> <span class="required">*</span></label>
                    <input type="text" id="sbir-name" name="name" required>
                </div>
                <div class="sbir-form-group">
                    <label for="sbir-email"><?php esc_html_e('Email', 'simpleboards-roadmap'); ?> <span class="required">*</span></label>
                    <input type="email" id="sbir-email" name="email" required>
                </div>
            <?php endif; ?>
            <div class="sbir-form-actions">
                <button type="submit" class="sbir-btn sbir-btn-primary"><?php esc_html_e('Submit Idea', 'simpleboards-roadmap'); ?></button>
                <button type="button" class="sbir-btn sbir-btn-secondary sbir-modal-cancel"><?php esc_html_e('Cancel', 'simpleboards-roadmap'); ?></button>
            </div>
            <div class="sbir-form-message" aria-live="polite"></div>
        </form>
    </div>
</div>