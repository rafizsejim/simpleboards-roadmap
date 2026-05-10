<?php
// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Options to remove
$options = array(
    'sbir_permalink_base',
    'sbir_inherit_theme_styles',
    'sbir_enable_guest_submissions',
    'sbir_moderate_submissions',
    'sbir_submission_form_title',
    'sbir_submission_form_description',
    'sbir_submission_compose_button_text',
    'sbir_notification_email',
    'sbir_email_new_submission',
    'sbir_email_rejected',
    'sbir_subscriptions_enabled',
    'sbir_email_template_new_submission_subject',
    'sbir_email_template_new_submission_body',
    'sbir_email_template_item_rejected_subject',
    'sbir_email_template_item_rejected_body',
    'sbir_email_template_pro_vote_promoted_subject',
    'sbir_email_template_pro_vote_promoted_body',
    'sbir_email_template_pro_status_change_subject',
    'sbir_email_template_pro_status_change_body',
    'sbir_email_template_pro_overdue_subject',
    'sbir_email_template_pro_overdue_body',
    'sbir_rewrite_version',
);
foreach ($options as $opt) {
    delete_option($opt);
}

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$option_names = $wpdb->get_col(
    "SELECT option_name
    FROM {$wpdb->options}
    WHERE option_name LIKE 'sbir_email_%'
       OR option_name LIKE 'sbir_email_template_%'"
);
foreach ((array) $option_names as $option_name) {
    delete_option((string) $option_name);
}

// Transients for styles and board caches
$blog_id = get_current_blog_id();
delete_transient('sbir_status_styles_' . $blog_id);

// Drop table operations disabled to avoid direct DB warnings and data loss.

// Comment like meta is intentionally preserved to avoid destructive data removal.

// No further action; posts and terms remain by design.
?>


