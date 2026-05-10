<?php
/**
 * Roadmap Kanban view template
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

$board_id = isset($board_id) ? $board_id : get_the_ID();

// Get all statuses
$statuses = get_terms(array(
    'taxonomy' => 'sbir_status',
    'hide_empty' => false,
    'orderby' => 'term_order',
    'order' => 'ASC'
));

// Filter statuses: only those assigned to this board or global (no board set)
$statuses = array_values(array_filter($statuses, function($term) use ($board_id) {
    $belongs_to = get_term_meta($term->term_id, '_sbir_status_board', true);
    return empty($belongs_to) || (int) $belongs_to === (int) $board_id;
}));

if (!empty($statuses)) {
    $position_meta_key = '_sbirp_column_position_board_' . (int) $board_id;
    $status_positions = array();
    $has_custom_positions = false;
    foreach ($statuses as $status_index => $status_term) {
        $saved_position = (int) get_term_meta((int) $status_term->term_id, $position_meta_key, true);
        if ($saved_position > 0) {
            $has_custom_positions = true;
            $status_positions[(int) $status_term->term_id] = $saved_position;
        } else {
            $status_positions[(int) $status_term->term_id] = (int) $status_index + 1;
        }
    }

    if ($has_custom_positions) {
        usort($statuses, static function($left, $right) use ($status_positions) {
            $left_position = isset($status_positions[(int) $left->term_id]) ? (int) $status_positions[(int) $left->term_id] : 9999;
            $right_position = isset($status_positions[(int) $right->term_id]) ? (int) $status_positions[(int) $right->term_id] : 9999;

            if ($left_position === $right_position) {
                return strcasecmp((string) $left->name, (string) $right->name);
            }
            return ($left_position < $right_position) ? -1 : 1;
        });
    }
}

// Categories for optional assignment
$all_categories = get_terms(array(
    'taxonomy' => 'sbir_category',
    'hide_empty' => false
));
$categories_available = !is_wp_error($all_categories) && !empty($all_categories);

if (empty($statuses) || is_wp_error($statuses)) {
    echo '<p class="sbir-notice">' . esc_html__('No statuses configured. Please configure statuses first.', 'simpleboards-roadmap') . '</p>';
    return;
}

// Load all roadmap items once, then group by status in memory
if (isset($items_query) && $items_query instanceof WP_Query) {
    $items = $items_query->posts;
} else {
    $items = array();
    $page = 1;
    $batch_size = (int) apply_filters('sbir_frontend_roadmap_batch_size', 200);
    if ($batch_size < 1) {
        $batch_size = 200;
    }

    while (true) {
        $items_query = sbir_get_board_items($board_id, 'roadmap', $batch_size, $page);
        if (!$items_query->have_posts()) {
            break;
        }

        $items = array_merge($items, $items_query->posts);
        if (count($items_query->posts) < $batch_size) {
            break;
        }

        $page++;
    }
}
wp_reset_postdata();

$items_by_id = array();
foreach ($items as $item) {
    $items_by_id[$item->ID] = $item;
}

/**
 * Allow extensions (Pro modules) to batch-prime roadmap card data.
 *
 * @param int[] $item_ids Item IDs visible in roadmap columns.
 * @param int   $board_id Current board ID.
 */
do_action('sbir_before_render_roadmap_cards', array_keys($items_by_id), (int) $board_id);

$items_by_status = array();
$assigned_items = array();
if (!empty($items_by_id)) {
    $term_links = wp_get_object_terms(array_keys($items_by_id), 'sbir_status', array('fields' => 'all_with_object_id'));
    if (!is_wp_error($term_links)) {
        foreach ($term_links as $term) {
            if (!isset($items_by_status[$term->term_id])) {
                $items_by_status[$term->term_id] = array();
            }
            $items_by_status[$term->term_id][] = (int) $term->object_id;
            $assigned_items[(int) $term->object_id] = true;
        }
    }
}

$render_kanban_item = static function($item, $status_slug, $board_id) {
    if (!$item) {
        return;
    }
    $created_ts = (int) get_post_time('U', true, $item->ID);

    // Category chips move to the top of the card as the visual anchor.
    $categories = get_the_terms($item->ID, 'sbir_category');
    $has_categories = $categories && !is_wp_error($categories);

    // Skip the excerpt block entirely when there's no body content (avoids
    // the dead vertical space that made empty cards taller than necessary).
    $excerpt_text = '';
    if ($item->post_excerpt) {
        $excerpt_text = $item->post_excerpt;
    } else {
        $stripped = trim(wp_strip_all_tags((string) $item->post_content));
        if ($stripped !== '') {
            $excerpt_text = wp_trim_words($stripped, 18);
        }
    }
    ?>
    <a class="sbir-kanban-item" href="<?php echo esc_url(get_permalink($item->ID)); ?>" data-item-id="<?php echo esc_attr($item->ID); ?>" data-status="<?php echo esc_attr($status_slug); ?>" data-created-ts="<?php echo esc_attr($created_ts); ?>">
        <div class="sbir-card-top">
            <div class="sbir-card-top-left">
                <?php if ($has_categories) : ?>
                    <?php foreach ($categories as $cat) :
                        $cat_color = get_term_meta((int) $cat->term_id, '_sbir_category_color', true);
                        $cat_attrs = '';
                        $cat_class = 'sbir-chip';
                        if ($cat_color) {
                            $cat_attrs = ' style="--sbir-cat-color: ' . esc_attr($cat_color) . ';"';
                            $cat_class .= ' sbir-chip--colored';
                        }
                    ?>
                        <span class="<?php echo esc_attr($cat_class); ?>"<?php echo $cat_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html($cat->name); ?></span>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="sbir-card-top-right">
                <div class="sbir-kanban-vote" aria-label="<?php esc_attr_e('Votes', 'simpleboards-roadmap'); ?>" onclick="return false;">
                    <?php sbir_render_vote_button($item->ID); ?>
                </div>
            </div>
        </div>
        <div class="sbir-card-title-wrap">
            <span class="sbir-card-number">#<?php echo esc_html(sbir_get_item_number($item->ID)); ?></span>
            <h4 class="sbir-item-title"><?php echo esc_html($item->post_title); ?></h4>
        </div>
        <?php if ($excerpt_text !== '') : ?>
            <p class="sbir-item-excerpt"><?php echo esc_html($excerpt_text); ?></p>
        <?php endif; ?>
        <?php do_action('sbir_render_item_card_progress', (int) $item->ID, (int) $board_id); ?>
        <?php sbir_render_item_meta($item->ID); ?>
    </a>
    <?php
};
?>

<div class="sbir-roadmap">
    <div class="sbir-kanban">
        <?php foreach ($statuses as $status) : 
            $items = array();
            if (!empty($items_by_status[$status->term_id])) {
                foreach ($items_by_status[$status->term_id] as $item_id) {
                    if (isset($items_by_id[$item_id])) {
                        $items[] = $items_by_id[$item_id];
                    }
                }
            }
            $color = get_term_meta($status->term_id, '_sbir_status_color', true);
            if (!$color) {
                $color = sbir_get_status_color($status->slug);
            }
        ?>
            <div class="sbir-kanban-column sbir-status-<?php echo esc_attr($status->slug); ?>" data-status="<?php echo esc_attr($status->slug); ?>" style="--status-color: <?php echo esc_attr($color); ?>;">
                <div class="sbir-kanban-header sbir-kanban-header--minimal">
                    <div class="sbir-kanban-head-left">
                        <span class="sbir-status-dot" aria-hidden="true"></span>
                        <h3 class="sbir-head-title"><?php echo esc_html($status->name); ?></h3>
                        <span class="sbir-chip sbir-chip-neutral"><?php echo count($items); ?></span>
                    </div>
                    <?php if (current_user_can('edit_posts')) : ?>
                        <button type="button" class="sbir-add-btn" aria-label="<?php esc_attr_e('Add item', 'simpleboards-roadmap'); ?>" data-status-id="<?php echo esc_attr($status->term_id); ?>">
                            <?php echo sbir_get_svg_icon('plus', array('width' => '16', 'height' => '16')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </button>
                    <?php endif; ?>
                </div>
                
                <div class="sbir-kanban-items">
                    <?php foreach ($items as $item) : ?>
                        <?php $render_kanban_item($item, $status->slug, $board_id); ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php
        // Unassigned column: items on this board that are roadmap items but have NO status term
        $unassigned_items = array();
        if (!empty($items_by_id)) {
            foreach ($items_by_id as $item) {
                if (!isset($assigned_items[$item->ID])) {
                    $unassigned_items[] = $item;
                }
            }
        }
        $unassigned_color = '#94a3b8'; // neutral slate
        ?>
        <?php if (current_user_can('edit_posts') || !empty($unassigned_items)) : ?>
            <div class="sbir-kanban-column sbir-status-unassigned" data-status="unassigned" style="--status-color: <?php echo esc_attr($unassigned_color); ?>;">
                <div class="sbir-kanban-header sbir-kanban-header--minimal">
                    <div class="sbir-kanban-head-left">
                        <span class="sbir-status-dot" aria-hidden="true"></span>
                        <h3 class="sbir-head-title"><?php echo esc_html__('Unassigned', 'simpleboards-roadmap'); ?></h3>
                        <span class="sbir-chip sbir-chip-neutral"><?php echo count($unassigned_items); ?></span>
                    </div>
                    <span aria-hidden="true"></span>
                </div>
                <div class="sbir-kanban-items">
                    <?php foreach ($unassigned_items as $item) : ?>
                        <?php $render_kanban_item($item, 'unassigned', $board_id); ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (current_user_can('edit_posts')) : ?>
    <div class="sbir-roadmap-modal" aria-hidden="true">
        <div class="sbir-modal-overlay"></div>
        <div class="sbir-modal-panel" role="dialog" aria-modal="true" aria-labelledby="sbir-roadmap-modal-title">
            <button type="button" class="sbir-modal-close" aria-label="<?php esc_attr_e('Close', 'simpleboards-roadmap'); ?>"><?php echo sbir_get_svg_icon('x', array('width' => '16', 'height' => '16')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
            <h3 id="sbir-roadmap-modal-title" class="sbir-modal-title"><?php esc_html_e('Add Roadmap Item', 'simpleboards-roadmap'); ?></h3>
            <form class="sbir-roadmap-form">
                <input type="hidden" name="board_id" value="<?php echo esc_attr($board_id); ?>">
                <input type="hidden" name="item_id" value="">
                <div class="sbir-form-group">
                    <label for="sbir-roadmap-status"><?php esc_html_e('Status', 'simpleboards-roadmap'); ?></label>
                    <select id="sbir-roadmap-status" name="status_id">
                        <option value=""><?php esc_html_e('Unassigned', 'simpleboards-roadmap'); ?></option>
                        <?php foreach ($statuses as $s) : ?>
                            <option value="<?php echo esc_attr($s->term_id); ?>"><?php echo esc_html($s->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sbir-form-group">
                    <label for="sbir-roadmap-title"><?php esc_html_e('Title', 'simpleboards-roadmap'); ?> <span class="required">*</span></label>
                    <input type="text" id="sbir-roadmap-title" name="title" required>
                </div>
                <div class="sbir-form-group">
                    <label for="sbir-roadmap-description"><?php esc_html_e('Description', 'simpleboards-roadmap'); ?></label>
                    <textarea id="sbir-roadmap-description" name="description" rows="4"></textarea>
                </div>
                <div class="sbir-form-group">
                    <label for="sbir-roadmap-category"><?php esc_html_e('Category', 'simpleboards-roadmap'); ?></label>
                    <select id="sbir-roadmap-category" name="category" <?php disabled(!$categories_available); ?>>
                        <?php if ($categories_available) : ?>
                            <option value=""><?php esc_html_e('Select Category', 'simpleboards-roadmap'); ?></option>
                            <?php foreach ($all_categories as $cat) : ?>
                                <option value="<?php echo esc_attr($cat->term_id); ?>"><?php echo esc_html($cat->name); ?></option>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <option value="" selected><?php esc_html_e('Categories not available', 'simpleboards-roadmap'); ?></option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="sbir-form-group">
                    <label for="sbir-roadmap-deadline"><?php esc_html_e('Deadline', 'simpleboards-roadmap'); ?></label>
                    <input type="date" id="sbir-roadmap-deadline" name="deadline">
                </div>
                <?php do_action('sbir_render_roadmap_create_extra_fields', (int) $board_id); ?>
                <div class="sbir-form-actions">
                    <button type="submit" class="sbir-btn sbir-btn-primary"><?php esc_html_e('Create Item', 'simpleboards-roadmap'); ?></button>
                    <button type="button" class="sbir-btn sbir-btn-secondary sbir-modal-cancel"><?php esc_html_e('Cancel', 'simpleboards-roadmap'); ?></button>
                </div>
                <div class="sbir-form-message" aria-live="polite"></div>
            </form>
        </div>
    </div>
<?php endif; ?>