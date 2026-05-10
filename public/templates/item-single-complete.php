<?php
/**
 * Single Item view template
 * Used for both drawer view and standalone single item pages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

global $post;
$item_id = get_the_ID();
$board_id = (int) get_post_meta($item_id, '_sbir_board_id', true);
$is_roadmap = get_post_meta($item_id, '_sbir_is_roadmap', true) === 'yes';

// Get current status
$current_status = 0;
$status_terms = wp_get_object_terms($item_id, 'sbir_status', array('fields' => 'all'));
$current_status_obj = !empty($status_terms) ? $status_terms[0] : null;
if ($current_status_obj) {
    $current_status = (int) $current_status_obj->term_id;
}

// Get current category
$current_category = 0;
$category_terms = wp_get_object_terms($item_id, 'sbir_category', array('fields' => 'all'));
$current_category_obj = (!is_wp_error($category_terms) && !empty($category_terms)) ? $category_terms[0] : null;
if ($current_category_obj) {
    $current_category = (int) $current_category_obj->term_id;
}

// Get deadline
$deadline = get_post_meta($item_id, '_sbir_deadline', true);
$deadline_ts = $deadline ? strtotime($deadline) : false;
$deadline_value = $deadline_ts ? gmdate('Y-m-d', $deadline_ts) : '';

// Get all statuses for dropdown
$statuses = get_terms(array(
    'taxonomy' => 'sbir_status',
    'hide_empty' => false,
    'orderby' => 'term_order',
    'order' => 'ASC'
));
$statuses = array_values(array_filter($statuses, function($term) use ($board_id) {
    $belongs_to = (int) get_term_meta($term->term_id, '_sbir_status_board', true);
    return $belongs_to === 0 || $belongs_to === $board_id;
}));

// Get all categories for dropdown
$categories = get_terms(array(
    'taxonomy' => 'sbir_category',
    'hide_empty' => false
));
$categories_available = !is_wp_error($categories) && !empty($categories);

$item_number = sbir_get_item_number($item_id);
$can_edit = current_user_can('edit_post', $item_id);

// Get status color
$status_color = '#3b82f6';
if ($current_status_obj) {
    $status_color = get_term_meta($current_status_obj->term_id, '_sbir_status_color', true);
    if (!$status_color) {
        $status_color = sbir_get_status_color($current_status_obj->slug);
    }
}
?>

<article id="post-<?php echo esc_attr($item_id); ?>" <?php post_class('sbir-item-single sbir-drawer-blend'); ?>>
    
    <!-- Header Section -->
    <header class="sbir-drawer-header">
        <div class="sbir-drawer-header-top">
            <div class="sbir-drawer-badges">
                <?php if ($is_roadmap) : ?>
                    <span class="sbir-type-badge"><?php esc_html_e('Roadmap', 'simpleboards-roadmap'); ?></span>
                <?php else : ?>
                    <span class="sbir-type-badge"><?php esc_html_e('Idea', 'simpleboards-roadmap'); ?></span>
                <?php endif; ?>
                <span class="sbir-item-id">#<?php echo esc_html($item_number); ?></span>
                <?php if ($can_edit && !$is_roadmap) : ?>
                    <button type="button" class="sbir-edit-btn-inline sbir-move-to-roadmap-btn sbir-move-to-roadmap-top" data-item-id="<?php echo esc_attr($item_id); ?>">
                        <span class="sbir-move-to-roadmap-label"><?php esc_html_e('Move to Roadmap', 'simpleboards-roadmap'); ?></span>
                        <span class="sbir-move-to-roadmap-icon" aria-hidden="true">&rarr;</span>
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <div class="sbir-drawer-header-main">
            <div class="sbir-drawer-title-row">
                <h1 class="sbir-drawer-title">
                    <span class="sbir-display-title"><?php the_title(); ?></span>
                    <?php if ($can_edit) : ?>
                        <input type="text" class="sbir-edit-field sbir-edit-title" name="title_display" value="<?php echo esc_attr(get_the_title($item_id)); ?>" data-title-source>
                    <?php endif; ?>
                </h1>
                <?php if ($can_edit) : ?>
                    <button type="button" class="sbir-edit-btn-inline sbir-edit-btn-drawer" data-item-id="<?php echo esc_attr($item_id); ?>">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        <?php esc_html_e('Edit', 'simpleboards-roadmap'); ?>
                    </button>
                <?php endif; ?>
            </div>
            <div class="sbir-drawer-header-right">
                <?php sbir_render_vote_button($item_id); ?>
                <?php if (class_exists('SBIR_Subscriptions')) { SBIR_Subscriptions::render_subscribe_button((int) $item_id); } ?>
            </div>
        </div>
    </header>

    <!-- Body Section -->
    <div class="sbir-drawer-body">
        
        <?php if ($can_edit) : ?>
        <form class="sbir-drawer-edit-form">
            <input type="hidden" name="item_id" value="<?php echo esc_attr($item_id); ?>">
            <input type="hidden" name="title" value="<?php echo esc_attr(get_the_title($item_id)); ?>" id="sbir-title-hidden">
        <?php endif; ?>
        
        <!-- Meta Info Panel -->
        <section class="sbir-meta-panel">
            <div class="sbir-meta-grid">
                <!-- Status -->
                <div class="sbir-meta-item">
                    <span class="sbir-meta-label"><?php esc_html_e('Status', 'simpleboards-roadmap'); ?></span>
                    <div class="sbir-meta-value">
                        <span class="sbir-display-value">
                            <?php if ($current_status_obj) : ?>
                                <span class="sbir-status-dot" style="background-color: <?php echo esc_attr($status_color); ?>;"></span>
                                <?php echo esc_html($current_status_obj->name); ?>
                            <?php else : ?>
                                <?php esc_html_e('Unassigned', 'simpleboards-roadmap'); ?>
                            <?php endif; ?>
                        </span>
                        <?php if ($can_edit) : ?>
                            <select class="sbir-edit-field" name="status_id">
                                <option value=""><?php esc_html_e('Unassigned', 'simpleboards-roadmap'); ?></option>
                                <?php foreach ($statuses as $s) : ?>
                                    <option value="<?php echo esc_attr($s->term_id); ?>" <?php selected($current_status, $s->term_id); ?>><?php echo esc_html($s->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Category -->
                <div class="sbir-meta-item">
                    <span class="sbir-meta-label"><?php esc_html_e('Category', 'simpleboards-roadmap'); ?></span>
                    <div class="sbir-meta-value">
                        <span class="sbir-display-value">
                            <svg class="sbir-meta-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                            <?php echo $current_category_obj ? esc_html($current_category_obj->name) : esc_html__('Uncategorized', 'simpleboards-roadmap'); ?>
                        </span>
                        <?php if ($can_edit) : ?>
                            <select class="sbir-edit-field" name="category">
                                <?php if ($categories_available) : ?>
                                    <option value=""><?php esc_html_e('Uncategorized', 'simpleboards-roadmap'); ?></option>
                                    <?php foreach ($categories as $cat) : ?>
                                        <option value="<?php echo esc_attr($cat->term_id); ?>" <?php selected($current_category, $cat->term_id); ?>><?php echo esc_html($cat->name); ?></option>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <option value="" selected disabled><?php esc_html_e('Categories not available', 'simpleboards-roadmap'); ?></option>
                                <?php endif; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Post Date -->
                <div class="sbir-meta-item">
                    <span class="sbir-meta-label"><?php esc_html_e('Posted', 'simpleboards-roadmap'); ?></span>
                    <div class="sbir-meta-value">
                        <?php echo esc_html(get_the_date(get_option('date_format'))); ?>
                    </div>
                </div>

                <!-- Due Date -->
                <div class="sbir-meta-item">
                    <span class="sbir-meta-label"><?php esc_html_e('Due', 'simpleboards-roadmap'); ?></span>
                    <div class="sbir-meta-value <?php echo !$deadline_ts ? 'sbir-meta-muted' : ''; ?>">
                        <span class="sbir-display-value">
                            <svg class="sbir-meta-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                            <?php if ($deadline_ts) : ?>
                                <?php echo esc_html(date_i18n(get_option('date_format'), $deadline_ts)); ?>
                            <?php else : ?>
                                <em><?php esc_html_e('Not set', 'simpleboards-roadmap'); ?></em>
                            <?php endif; ?>
                        </span>
                        <?php if ($can_edit) : ?>
                            <input class="sbir-edit-field" type="date" name="deadline" value="<?php echo esc_attr($deadline_value); ?>">
                        <?php endif; ?>
                    </div>
                </div>

                <?php do_action('sbir_render_item_meta_panel_extra_fields', (int) $item_id, (int) $board_id, (bool) $is_roadmap, (bool) $can_edit); ?>

            </div>
        </section>

        <!-- Description Section -->
        <section class="sbir-description-section">
            <h3 class="sbir-section-label">
                <svg class="sbir-section-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" x2="21" y1="6" y2="6"/><line x1="8" x2="21" y1="12" y2="12"/><line x1="8" x2="21" y1="18" y2="18"/><line x1="3" x2="3.01" y1="6" y2="6"/><line x1="3" x2="3.01" y1="12" y2="12"/><line x1="3" x2="3.01" y1="18" y2="18"/></svg>
                <?php esc_html_e('Description', 'simpleboards-roadmap'); ?>
            </h3>
            <div class="sbir-description-card">
                <div class="sbir-display-value sbir-description-content">
                    <?php 
                    $post_content = $post->post_content;
                    $post_content = wptexturize($post_content);
                    $post_content = convert_smilies($post_content);
                    $post_content = wpautop($post_content);
                    echo wp_kses_post($post_content);
                    ?>
                </div>
                <?php if ($can_edit) : ?>
                    <textarea class="sbir-edit-field" name="description" rows="6"><?php echo esc_textarea($post->post_content); ?></textarea>
                <?php endif; ?>
            </div>
        </section>

        <?php do_action('sbir_render_item_drawer_extra_fields', (int) $item_id, (int) $board_id, (bool) $is_roadmap, (bool) $can_edit); ?>

        <?php if ($can_edit) : ?>
        <div class="sbir-autosave-row" aria-live="polite">
            <span class="sbir-autosave-status" data-state="idle"><?php esc_html_e('Autosave on', 'simpleboards-roadmap'); ?></span>
        </div>
        </form>
        <?php endif; ?>

        <!-- Discussion Section -->
        <?php if (comments_open((int) $item_id) || get_comments_number((int) $item_id) > 0) : ?>
            <section class="sbir-discussion-section">
                <?php include SBIR_PLUGIN_DIR . 'public/templates/comments.php'; ?>
            </section>
        <?php endif; ?>
        
    </div>
</article>

<?php if ($can_edit && !$is_roadmap) : ?>
    <div class="sbir-roadmap-modal sbir-move-modal" aria-hidden="true">
        <div class="sbir-modal-overlay"></div>
        <div class="sbir-modal-panel" role="dialog" aria-modal="true" aria-labelledby="sbir-move-modal-title">
            <button type="button" class="sbir-modal-close" aria-label="<?php esc_attr_e('Close', 'simpleboards-roadmap'); ?>">×</button>
            <h3 id="sbir-move-modal-title" class="sbir-modal-title"><?php esc_html_e('Move to Roadmap', 'simpleboards-roadmap'); ?></h3>
            <form class="sbir-move-roadmap-form">
                <input type="hidden" name="item_id" value="<?php echo esc_attr($item_id); ?>">
                <div class="sbir-form-group">
                    <label for="sbir-move-status"><?php esc_html_e('Status', 'simpleboards-roadmap'); ?></label>
                    <select id="sbir-move-status" name="status_id">
                        <option value=""><?php esc_html_e('Unassigned', 'simpleboards-roadmap'); ?></option>
                        <?php foreach ($statuses as $s) : ?>
                            <option value="<?php echo esc_attr($s->term_id); ?>"><?php echo esc_html($s->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sbir-form-group">
                    <label for="sbir-move-category"><?php esc_html_e('Category', 'simpleboards-roadmap'); ?></label>
                    <select id="sbir-move-category" name="category" <?php disabled(!$categories_available); ?>>
                        <?php if ($categories_available) : ?>
                            <option value=""><?php esc_html_e('Select Category', 'simpleboards-roadmap'); ?></option>
                            <?php foreach ($categories as $cat) : ?>
                                <option value="<?php echo esc_attr($cat->term_id); ?>"><?php echo esc_html($cat->name); ?></option>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <option value="" selected><?php esc_html_e('Categories not available', 'simpleboards-roadmap'); ?></option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="sbir-form-actions">
                    <button type="submit" class="sbir-btn sbir-btn-primary"><?php esc_html_e('Move', 'simpleboards-roadmap'); ?></button>
                    <button type="button" class="sbir-btn sbir-btn-secondary sbir-modal-cancel"><?php esc_html_e('Cancel', 'simpleboards-roadmap'); ?></button>
                </div>
                <div class="sbir-form-message" aria-live="polite"></div>
            </form>
        </div>
    </div>
<?php endif; ?>
