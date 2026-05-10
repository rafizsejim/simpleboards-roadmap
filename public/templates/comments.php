<?php
/**
 * Styled comments template for SimpleBoards items
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

if (post_password_required()) { return; }

// Custom renderer for each comment
if (!function_exists('sbir_render_comment')) {
    function sbir_render_comment($comment, $args, $depth) {
        $author = get_comment_author($comment);
        $avatar_size = ($depth > 1) ? 32 : 36; // Smaller avatar for nested replies
        $avatar = get_avatar($comment, $avatar_size, '', '', array('class' => 'sbir-comment-avatar'));
        $timestamp = (int) get_comment_time('U', false, $comment);
        $time_iso = get_comment_date('c', $comment);
        /* translators: %s: human-readable time difference, e.g., '2 hours' */
        $time_relative = sprintf( esc_html__('%s ago', 'simpleboards-roadmap'), human_time_diff( $timestamp, current_time('timestamp') ) );
        $current_user_id = get_current_user_id();
        $can_manage = $current_user_id && ( (int)$comment->user_id === (int)$current_user_id || current_user_can('edit_comment', $comment->comment_ID) || current_user_can('moderate_comments') );
        ?>
        <li id="comment-<?php comment_ID(); ?>" <?php comment_class('sbir-comment-card'); ?> data-comment-id="<?php echo (int)$comment->comment_ID; ?>" data-comment-time="<?php echo esc_attr($timestamp); ?>">
            <div class="sbir-comment-row">
                <div class="sbir-avatar-wrap"><?php echo wp_kses_post($avatar); ?></div>
                <div class="sbir-comment-main">
                    <div class="sbir-comment-header">
                        <div class="sbir-comment-meta">
                            <span class="sbir-author-name"><?php echo esc_html($author); ?></span>
                            <span class="sbir-comment-time"><time datetime="<?php echo esc_attr($time_iso); ?>"><?php echo esc_html($time_relative); ?></time></span>
                        </div>
                        <?php if ($can_manage) : ?>
                        <div class="sbir-comment-menu-wrap">
                            <button type="button" class="sbir-comment-menu" aria-haspopup="menu" aria-expanded="false" aria-label="<?php esc_attr_e('Comment settings', 'simpleboards-roadmap'); ?>" title="<?php esc_attr_e('Comment settings', 'simpleboards-roadmap'); ?>">
                                <svg width="16" height="16" viewBox="0 0 16 3" fill="currentColor" aria-hidden="true"><path d="M2 0a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Zm6.041 0a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3ZM14 0a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Z"/></svg>
                            </button>
                            <div class="sbir-comment-menu-popover" role="menu">
                                <button type="button" class="sbir-menu-item sbir-comment-edit" role="menuitem" data-comment-id="<?php echo (int)$comment->comment_ID; ?>"><?php esc_html_e('Edit', 'simpleboards-roadmap'); ?></button>
                                <button type="button" class="sbir-menu-item sbir-comment-delete" role="menuitem" data-comment-id="<?php echo (int)$comment->comment_ID; ?>"><?php esc_html_e('Delete', 'simpleboards-roadmap'); ?></button>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="sbir-comment-content">
                        <?php if ('0' === $comment->comment_approved) : ?>
                            <em class="comment-awaiting-moderation"><?php esc_html_e('Your comment is awaiting moderation.', 'simpleboards-roadmap'); ?></em>
                        <?php endif; ?>
                        <?php comment_text(); ?>
                    </div>
                    <div class="sbir-comment-actions">
                        <?php $like_count = sbir_get_comment_like_count($comment->comment_ID); $liked = sbir_user_liked_comment($comment->comment_ID); ?>
                        <button type="button" class="sbir-comment-like" data-comment-id="<?php echo (int)$comment->comment_ID; ?>" aria-pressed="<?php echo $liked ? 'true' : 'false'; ?>">
                            <svg class="sbir-like-icon" width="16" height="16" viewBox="0 0 24 24" fill="<?php echo $liked ? 'currentColor' : 'none'; ?>" stroke="currentColor" stroke-width="2"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/></svg>
                            <span class="sbir-like-count"><?php echo (int)$like_count; ?></span>
                        </button>
                        <span class="sbir-comment-reply">
                            <a href="#" class="sbir-reply-btn" data-comment-id="<?php echo (int)$comment->comment_ID; ?>">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/></svg>
                                <span class="sbir-reply-label"><?php esc_html_e('Reply', 'simpleboards-roadmap'); ?></span>
                            </a>
                        </span>
                    </div>
                </div>
            </div>
        </li>
        <?php
    }
}

$comments_number = get_comments_number();
$sbir_guest_comments_enabled = get_option('sbir_comments_allow_guests', 'yes') === 'yes';
$requires_login = !is_user_logged_in() && (
    (bool) get_option('comment_registration')
    || !$sbir_guest_comments_enabled
);
$can_post_comment = comments_open() && !$requires_login;
?>

<div class="sbir-discussion" aria-labelledby="sbir-discussion-title">
    <!-- Discussion Header -->
    <div class="sbir-discussion-header">
        <h2 id="sbir-discussion-title" class="sbir-discussion-title">
            <?php echo esc_html__('Discussion', 'simpleboards-roadmap'); ?>
            <span class="sbir-count-badge"><?php echo esc_html(number_format_i18n($comments_number)); ?></span>
        </h2>
        <div class="sbir-comment-sort" role="group" aria-label="<?php esc_attr_e('Sort comments', 'simpleboards-roadmap'); ?>">
            <button type="button" class="sbir-sort-btn active" data-order="desc"><?php esc_html_e('Most recent', 'simpleboards-roadmap'); ?></button>
            <button type="button" class="sbir-sort-btn" data-order="asc"><?php esc_html_e('Oldest', 'simpleboards-roadmap'); ?></button>
        </div>
    </div>

    <!-- Comment Form Card -->
    <div class="sbir-comment-form-card">
        <?php if ($can_post_comment) : ?>
            <?php
            $current_user = wp_get_current_user();
            $user_avatar = get_avatar($current_user->ID, 44, '', '', array('class' => 'sbir-form-avatar'));
            ?>
            <div class="sbir-comment-form-top">
                <div class="sbir-form-avatar-wrap">
                    <?php if ($current_user->ID) : ?>
                        <?php echo wp_kses_post($user_avatar); ?>
                    <?php else : ?>
                        <div class="sbir-avatar-placeholder">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        </div>
                    <?php endif; ?>
                </div>
                <?php
                $sbir_guest_fields = array();
                if (!is_user_logged_in()) {
                    $commenter_data = wp_get_current_commenter();
                    $sbir_guest_fields['author'] = '<p class="comment-form-author sbir-guest-field">'
                        . '<label for="author">' . esc_html__('Name', 'simpleboards-roadmap') . ' <span class="required">*</span></label> '
                        . '<input id="author" name="author" type="text" value="' . esc_attr($commenter_data['comment_author']) . '" autocomplete="name" required />'
                        . '</p>';
                    $sbir_guest_fields['email'] = '<p class="comment-form-email sbir-guest-field">'
                        . '<label for="email">' . esc_html__('Email', 'simpleboards-roadmap') . ' <span class="required">*</span></label> '
                        . '<input id="email" name="email" type="email" value="' . esc_attr($commenter_data['comment_author_email']) . '" autocomplete="email" required />'
                        . '</p>';
                }

                comment_form(array(
                    'class_form'   => 'sbir-comment-form',
                    'title_reply'  => '',
                    'title_reply_to' => '',
                    'label_submit' => __('Publish', 'simpleboards-roadmap'),
                    'cancel_reply_link' => '',
                    'comment_field' => '<div class="sbir-comment-input-wrap">'
                        . '<div class="sbir-comment-editor" contenteditable="true" data-placeholder="' . esc_attr__('Write a comment...', 'simpleboards-roadmap') . '" data-target="comment"></div>'
                        . '<textarea id="comment" name="comment" rows="2" class="sbir-comment-textarea sbir-hidden-textarea" required></textarea>'
                        . '</div>',
                    'fields' => $sbir_guest_fields,
                    'submit_button' => '',
                    'submit_field'  => '<div class="sbir-hidden">%2$s</div>',
                    'logged_in_as'  => '',
                    'comment_notes_before' => '',
                    'comment_notes_after'  => ''
                ));
                ?>
            </div>
            <div class="sbir-comment-toolbar">
                <div class="sbir-toolbar-left">
                    <button type="button" class="sbir-toolbar-btn" data-action="bold" title="<?php esc_attr_e('Bold', 'simpleboards-roadmap'); ?>"><strong>B</strong></button>
                    <button type="button" class="sbir-toolbar-btn" data-action="italic" title="<?php esc_attr_e('Italic', 'simpleboards-roadmap'); ?>"><em>I</em></button>
                    <button type="button" class="sbir-toolbar-btn" data-action="underline" title="<?php esc_attr_e('Underline', 'simpleboards-roadmap'); ?>"><span style="text-decoration: underline;">U</span></button>
                    <button type="button" class="sbir-toolbar-btn" data-action="link" title="<?php esc_attr_e('Link', 'simpleboards-roadmap'); ?>"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></button>
                </div>
                <div class="sbir-toolbar-right">
                    <button type="submit" form="commentform" class="sbir-btn sbir-btn-primary sbir-publish-btn"><?php esc_html_e('Publish', 'simpleboards-roadmap'); ?></button>
                </div>
            </div>
            <?php
            if (!is_user_logged_in() && (bool) get_option('show_comments_cookies_opt_in')) :
                $commenter = wp_get_current_commenter();
                $consent_checked = !empty($commenter['comment_author_email']);
                ?>
                <p class="comment-form-cookies-consent sbir-comment-consent">
                    <input id="wp-comment-cookies-consent" name="wp-comment-cookies-consent" type="checkbox" value="yes" form="commentform"<?php checked($consent_checked); ?>>
                    <label for="wp-comment-cookies-consent"><?php esc_html_e('Save my name and email in this browser for the next time I comment.', 'simpleboards-roadmap'); ?></label>
                </p>
            <?php endif; ?>
        <?php else : ?>
            <div class="sbir-comment-form-top">
                <p class="sbir-notice">
                    <?php
                    if ($requires_login) {
                        esc_html_e('Please log in to join the discussion.', 'simpleboards-roadmap');
                    } else {
                        esc_html_e('Comments are closed for this item.', 'simpleboards-roadmap');
                    }
                    ?>
                </p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Comments List -->
    <?php
    $sbir_comments = get_comments(array(
        'post_id' => get_the_ID(),
        'status'  => 'approve',
        'orderby' => 'comment_date_gmt',
        'order'   => 'DESC'
    ));
    if (!empty($sbir_comments)) : ?>
        <ol class="sbir-comment-list">
            <?php
            wp_list_comments(array(
                'style'       => 'ol',
                'avatar_size' => 36,
                'callback'    => 'sbir_render_comment',
                'short_ping'  => true,
                'max_depth'   => 3
            ), $sbir_comments);
            ?>
        </ol>
    <?php endif; ?>

    <?php if (!comments_open() && get_comments_number()) : ?>
        <p class="sbir-no-comments"><?php esc_html_e('Comments are closed.', 'simpleboards-roadmap'); ?></p>
    <?php endif; ?>
</div>
