<?php
/**
 * Settings page and option registration.
 *
 * @package SimpleBoards_Roadmap
 */
if (!defined('ABSPATH')) {
    exit;
}

class SBIR_Settings {

    /**
     * Register settings and rewrite flush hook.
     */
    public function init() {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('updated_option', array($this, 'maybe_flush_rewrite_rules'), 10, 3);
        add_action('admin_post_sbir_import_loopedin', array($this, 'handle_import_loopedin'));
    }
    
    /**
     * Register plugin options and sanitize callbacks.
     */
    public function register_settings() {
        // General settings
        register_setting('sbir_general_settings', 'sbir_permalink_base', array(
            'type' => 'string',
            'sanitize_callback' => array(__CLASS__, 'sanitize_slug_base'),
            'default' => 'products',
        ));
        register_setting('sbir_general_settings', 'sbir_inherit_theme_styles', array(
            'type' => 'string',
            'sanitize_callback' => array(__CLASS__, 'sanitize_yes_no'),
            'default' => 'no',
        ));
        
        // Submission settings
        register_setting('sbir_submission_settings', 'sbir_enable_guest_submissions', array(
            'type' => 'string',
            'sanitize_callback' => array(__CLASS__, 'sanitize_yes_no'),
            'default' => 'yes',
        ));
        register_setting('sbir_submission_settings', 'sbir_moderate_submissions', array(
            'type' => 'string',
            'sanitize_callback' => array(__CLASS__, 'sanitize_yes_no'),
            'default' => 'yes',
        ));
        register_setting('sbir_submission_settings', 'sbir_submission_form_title', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => __('Submit Your Idea', 'simpleboards-roadmap'),
        ));
        register_setting('sbir_submission_settings', 'sbir_submission_form_description', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => '',
        ));
        register_setting('sbir_submission_settings', 'sbir_submission_compose_button_text', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => __('Share an idea...', 'simpleboards-roadmap'),
        ));

        // Comment settings
        register_setting('sbir_comment_settings', 'sbir_comments_enabled', array(
            'type' => 'string',
            'sanitize_callback' => array(__CLASS__, 'sanitize_yes_no'),
            'default' => 'yes',
        ));
        register_setting('sbir_comment_settings', 'sbir_comments_allow_guests', array(
            'type' => 'string',
            'sanitize_callback' => array(__CLASS__, 'sanitize_yes_no'),
            'default' => 'yes',
        ));
        
        // Notification settings
        register_setting('sbir_notification_settings', 'sbir_notification_email', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_email',
            'default' => get_option('admin_email'),
        ));
        register_setting('sbir_notification_settings', 'sbir_email_new_submission', array(
            'type' => 'string',
            'sanitize_callback' => array(__CLASS__, 'sanitize_yes_no'),
            'default' => 'yes',
        ));
        register_setting('sbir_notification_settings', 'sbir_email_rejected', array(
            'type' => 'string',
            'sanitize_callback' => array(__CLASS__, 'sanitize_yes_no'),
            'default' => 'yes',
        ));
        register_setting('sbir_notification_settings', 'sbir_email_template_new_submission_subject', array(
            'type' => 'string',
            'sanitize_callback' => array(__CLASS__, 'sanitize_email_subject'),
            'default' => __('[{site_name}] New idea submitted', 'simpleboards-roadmap'),
        ));
        register_setting('sbir_notification_settings', 'sbir_email_template_new_submission_body', array(
            'type' => 'string',
            'sanitize_callback' => array(__CLASS__, 'sanitize_email_body'),
            'default' => __("A new idea has been submitted.\n\nTitle: {title}\nDescription: {description}\nSubmitted by: {name} ({email})\n\nView all ideas: {admin_ideas_url}", 'simpleboards-roadmap'),
        ));
        register_setting('sbir_notification_settings', 'sbir_email_template_item_rejected_subject', array(
            'type' => 'string',
            'sanitize_callback' => array(__CLASS__, 'sanitize_email_subject'),
            'default' => __('[{site_name}] Item rejected', 'simpleboards-roadmap'),
        ));
        register_setting('sbir_notification_settings', 'sbir_email_template_item_rejected_body', array(
            'type' => 'string',
            'sanitize_callback' => array(__CLASS__, 'sanitize_email_body'),
            'default' => __("An item has been rejected.\n\nItem: {title}\n\nReview item: {item_link}", 'simpleboards-roadmap'),
        ));
        $workflow_defaults = sbir_get_workflow_email_template_defaults();
        register_setting('sbir_notification_settings', 'sbir_email_template_pro_vote_promoted_subject', array(
            'type' => 'string',
            'sanitize_callback' => array(__CLASS__, 'sanitize_email_subject'),
            'default' => isset($workflow_defaults['pro_vote_promoted']['subject']) ? $workflow_defaults['pro_vote_promoted']['subject'] : __('[{site_name}] Idea moved to roadmap by workflow rule', 'simpleboards-roadmap'),
        ));
        register_setting('sbir_notification_settings', 'sbir_email_template_pro_vote_promoted_body', array(
            'type' => 'string',
            'sanitize_callback' => array(__CLASS__, 'sanitize_email_body'),
            'default' => isset($workflow_defaults['pro_vote_promoted']['body']) ? $workflow_defaults['pro_vote_promoted']['body'] : __("A workflow rule moved an idea to roadmap.\n\nItem: {item_title}\nBoard: {board_title}\nVotes: {votes}\nThreshold: {threshold}\n\nReview item: {item_link}", 'simpleboards-roadmap'),
        ));
        register_setting('sbir_notification_settings', 'sbir_email_template_pro_status_change_subject', array(
            'type' => 'string',
            'sanitize_callback' => array(__CLASS__, 'sanitize_email_subject'),
            'default' => isset($workflow_defaults['pro_status_change']['subject']) ? $workflow_defaults['pro_status_change']['subject'] : __('[{site_name}] Workflow status rule executed', 'simpleboards-roadmap'),
        ));
        register_setting('sbir_notification_settings', 'sbir_email_template_pro_status_change_body', array(
            'type' => 'string',
            'sanitize_callback' => array(__CLASS__, 'sanitize_email_body'),
            'default' => isset($workflow_defaults['pro_status_change']['body']) ? $workflow_defaults['pro_status_change']['body'] : __("A workflow status rule ran.\n\nItem: {item_title}\nBoard: {board_title}\nFrom: {from_status}\nTo: {to_status}\nAssigned category: {category}\n\nReview item: {item_link}", 'simpleboards-roadmap'),
        ));
        register_setting('sbir_notification_settings', 'sbir_email_template_pro_overdue_subject', array(
            'type' => 'string',
            'sanitize_callback' => array(__CLASS__, 'sanitize_email_subject'),
            'default' => isset($workflow_defaults['pro_overdue']['subject']) ? $workflow_defaults['pro_overdue']['subject'] : __('[{site_name}] Workflow overdue rule executed', 'simpleboards-roadmap'),
        ));
        register_setting('sbir_notification_settings', 'sbir_email_template_pro_overdue_body', array(
            'type' => 'string',
            'sanitize_callback' => array(__CLASS__, 'sanitize_email_body'),
            'default' => isset($workflow_defaults['pro_overdue']['body']) ? $workflow_defaults['pro_overdue']['body'] : __("A workflow overdue rule ran.\n\nItem: {item_title}\nBoard: {board_title}\nDays overdue: {days_overdue}\nRule threshold: {required_days}\nSet status: {status_name}\n\nReview item: {item_link}", 'simpleboards-roadmap'),
        ));

        register_setting('sbir_notification_settings', 'sbir_subscriptions_enabled', array(
            'type' => 'string',
            'sanitize_callback' => array(__CLASS__, 'sanitize_yes_no'),
            'default' => 'yes',
        ));

        if (function_exists('sbir_get_notification_config')) {
            $config = sbir_get_notification_config();
            foreach ($config as $type_key => $entry) {
                register_setting('sbir_notification_settings', $entry['option_toggle'], array(
                    'type' => 'string',
                    'sanitize_callback' => array(__CLASS__, 'sanitize_yes_no'),
                    'default' => isset($entry['default_toggle']) ? $entry['default_toggle'] : 'yes',
                ));
                register_setting('sbir_notification_settings', $entry['subject_option'], array(
                    'type' => 'string',
                    'sanitize_callback' => array(__CLASS__, 'sanitize_email_subject'),
                    'default' => isset($entry['default_subject']) ? $entry['default_subject'] : '',
                ));
                register_setting('sbir_notification_settings', $entry['body_option'], array(
                    'type' => 'string',
                    'sanitize_callback' => array(__CLASS__, 'sanitize_email_body'),
                    'default' => isset($entry['default_body']) ? $entry['default_body'] : '',
                ));
            }
        }
    }
    
    /**
     * Output settings page markup.
     */
    public static function render_settings_page() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'general';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('SimpleBoards Settings', 'simpleboards-roadmap'); ?></h1>
            
            <h2 class="nav-tab-wrapper">
                <a href="?page=sbir-settings&tab=general" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('General', 'simpleboards-roadmap'); ?>
                </a>
                <a href="?page=sbir-settings&tab=submissions" class="nav-tab <?php echo $active_tab === 'submissions' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Submissions', 'simpleboards-roadmap'); ?>
                </a>
                <a href="?page=sbir-settings&tab=comments" class="nav-tab <?php echo $active_tab === 'comments' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Comments', 'simpleboards-roadmap'); ?>
                </a>
                <a href="?page=sbir-settings&tab=notifications" class="nav-tab <?php echo $active_tab === 'notifications' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Notifications', 'simpleboards-roadmap'); ?>
                </a>
                <a href="?page=sbir-settings&tab=import" class="nav-tab <?php echo $active_tab === 'import' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Import', 'simpleboards-roadmap'); ?>
                </a>
            </h2>
            
            <?php if ($active_tab === 'general') : ?>
                <form method="post" action="options.php">
                    <?php settings_fields('sbir_general_settings'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="sbir_permalink_base"><?php esc_html_e('Permalink Base', 'simpleboards-roadmap'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="sbir_permalink_base" name="sbir_permalink_base" 
                                       value="<?php echo esc_attr(get_option('sbir_permalink_base', 'products')); ?>" class="regular-text">
                                <p class="description">
                                    <?php esc_html_e('The base slug for board URLs. Default is "products".', 'simpleboards-roadmap'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Inherit Theme Styles', 'simpleboards-roadmap'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="sbir_inherit_theme_styles" value="yes" <?php checked(get_option('sbir_inherit_theme_styles', 'no'), 'yes'); ?>>
                                    <?php esc_html_e('Strictly use theme typography/colors for board, ideas, and drawer (default: off)', 'simpleboards-roadmap'); ?>
                                </label>
                            </td>
                        </tr>
                        <?php
                        /**
                         * Fires inside the General settings table so add-ons can
                         * inject extra rows (e.g. Pro AI toggle).
                         */
                        do_action('sbir_general_settings_after_fields');
                        ?>
                    </table>
                    <?php submit_button(); ?>
                </form>
            
            <?php elseif ($active_tab === 'submissions') : ?>
                <form method="post" action="options.php">
                    <?php settings_fields('sbir_submission_settings'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Guest Submissions', 'simpleboards-roadmap'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="sbir_enable_guest_submissions" value="yes" 
                                           <?php checked(get_option('sbir_enable_guest_submissions', 'yes'), 'yes'); ?>>
                                    <?php esc_html_e('Allow guest users to submit ideas', 'simpleboards-roadmap'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Moderation', 'simpleboards-roadmap'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="sbir_moderate_submissions" value="yes" 
                                           <?php checked(get_option('sbir_moderate_submissions', 'yes'), 'yes'); ?>>
                                    <?php esc_html_e('Require admin approval for new submissions', 'simpleboards-roadmap'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="sbir_submission_form_title"><?php esc_html_e('Form Title', 'simpleboards-roadmap'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="sbir_submission_form_title" name="sbir_submission_form_title" 
                                       value="<?php echo esc_attr(get_option('sbir_submission_form_title', __('Submit Your Idea', 'simpleboards-roadmap'))); ?>" 
                                       class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="sbir_submission_form_description"><?php esc_html_e('Form Description', 'simpleboards-roadmap'); ?></label>
                            </th>
                            <td>
                                <textarea id="sbir_submission_form_description" name="sbir_submission_form_description" 
                                          rows="3" class="large-text"><?php echo esc_textarea(get_option('sbir_submission_form_description', '')); ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="sbir_submission_compose_button_text"><?php esc_html_e('Sidebar Button Text', 'simpleboards-roadmap'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="sbir_submission_compose_button_text" name="sbir_submission_compose_button_text"
                                       value="<?php echo esc_attr(get_option('sbir_submission_compose_button_text', __('Share an idea...', 'simpleboards-roadmap'))); ?>"
                                       class="regular-text">
                                <p class="description"><?php esc_html_e('Text shown on the ideas sidebar submit button.', 'simpleboards-roadmap'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(); ?>
                </form>
            
            <?php elseif ($active_tab === 'comments') : ?>
                <form method="post" action="options.php">
                    <?php settings_fields('sbir_comment_settings'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Comments', 'simpleboards-roadmap'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="sbir_comments_enabled" value="yes"
                                        <?php checked(get_option('sbir_comments_enabled', 'yes'), 'yes'); ?>>
                                    <?php esc_html_e('Enable comments on board items', 'simpleboards-roadmap'); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('When off, the discussion area is hidden on all boards. You can still disable per board from that board\'s settings.', 'simpleboards-roadmap'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Guest Comments', 'simpleboards-roadmap'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="sbir_comments_allow_guests" value="yes"
                                        <?php checked(get_option('sbir_comments_allow_guests', 'yes'), 'yes'); ?>>
                                    <?php esc_html_e('Allow visitors to comment without logging in', 'simpleboards-roadmap'); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('When off, only logged-in users can post comments.', 'simpleboards-roadmap'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(); ?>
                </form>

            <?php elseif ($active_tab === 'notifications') : ?>
                <?php self::render_notifications_tab(); ?>

            <?php elseif ($active_tab === 'import') : ?>
                <?php self::render_import_tab(); ?>

            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render LoopedIn import tab UI.
     *
     * @return void
     */
    private static function render_import_tab() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice params.
        $imported_ideas = isset($_GET['imported_ideas']) ? absint(wp_unslash($_GET['imported_ideas'])) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice params.
        $imported_roadmap = isset($_GET['imported_roadmap']) ? absint(wp_unslash($_GET['imported_roadmap'])) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice params.
        $skipped = isset($_GET['skipped']) ? absint(wp_unslash($_GET['skipped'])) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice params.
        $errors = isset($_GET['errors']) ? absint(wp_unslash($_GET['errors'])) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice params.
        $message = isset($_GET['import_message']) ? sanitize_text_field(wp_unslash($_GET['import_message'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice params.
        $result = isset($_GET['import_result']) ? sanitize_key(wp_unslash($_GET['import_result'])) : '';
        $boards = function_exists('sbir_get_boards_list') ? sbir_get_boards_list(2000) : array();

        if ($result !== '') :
            ?>
            <div class="notice <?php echo $result === 'success' ? 'notice-success' : 'notice-error'; ?> is-dismissible">
                <p>
                    <?php
                    if ($message !== '') {
                        echo esc_html($message);
                    } else {
                        printf(
                            /* translators: 1: imported ideas, 2: imported roadmap items, 3: skipped rows, 4: failed rows */
                            esc_html__('%1$d ideas imported, %2$d roadmap items imported, %3$d skipped, %4$d failed.', 'simpleboards-roadmap'),
                            (int) $imported_ideas,
                            (int) $imported_roadmap,
                            (int) $skipped,
                            (int) $errors
                        );
                    }
                    ?>
                </p>
            </div>
            <?php
        endif;
        ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
            <?php wp_nonce_field('sbir_import_loopedin_action', 'sbir_import_loopedin_nonce'); ?>
            <input type="hidden" name="action" value="sbir_import_loopedin">

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="sbir_import_board_id"><?php esc_html_e('Target Board', 'simpleboards-roadmap'); ?></label>
                    </th>
                    <td>
                        <select id="sbir_import_board_id" name="sbir_import_board_id" required>
                            <option value=""><?php esc_html_e('Select board', 'simpleboards-roadmap'); ?></option>
                            <?php foreach ((array) $boards as $board) : ?>
                                <option value="<?php echo esc_attr((int) $board->ID); ?>"><?php echo esc_html($board->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Imported ideas and roadmap cards will be attached to this board.', 'simpleboards-roadmap'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="sbir_import_feedback_csv"><?php esc_html_e('LoopedIn Ideas CSV', 'simpleboards-roadmap'); ?></label>
                    </th>
                    <td>
                        <input type="file" id="sbir_import_feedback_csv" name="sbir_import_feedback_csv" accept=".csv,text/csv">
                        <p class="description"><?php esc_html_e('Upload the LoopedIn feedback export (ideas).', 'simpleboards-roadmap'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="sbir_import_roadmap_csv"><?php esc_html_e('LoopedIn Roadmap CSV', 'simpleboards-roadmap'); ?></label>
                    </th>
                    <td>
                        <input type="file" id="sbir_import_roadmap_csv" name="sbir_import_roadmap_csv" accept=".csv,text/csv">
                        <p class="description"><?php esc_html_e('Upload the LoopedIn roadmap cards export (roadmap items).', 'simpleboards-roadmap'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Duplicate Handling', 'simpleboards-roadmap'); ?></th>
                    <td>
                        <label style="display:block;margin-bottom:6px;">
                            <input type="checkbox" name="sbir_import_allow_duplicates" value="yes">
                            <?php esc_html_e('Allow duplicate titles (always create new items)', 'simpleboards-roadmap'); ?>
                        </label>
                        <label style="display:block;">
                            <input type="checkbox" name="sbir_import_overwrite_existing" value="yes">
                            <?php esc_html_e('Overwrite existing item when title already exists on this board', 'simpleboards-roadmap'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('If both options are unchecked, duplicate titles are skipped. If both are checked, overwrite runs first for matched titles.', 'simpleboards-roadmap'); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button(__('Import CSV', 'simpleboards-roadmap')); ?>
        </form>
        <?php
    }

    /**
     * Render the Notifications tab UI grouped by audience.
     *
     * @return void
     */
    private static function render_notifications_tab() {
        $config = function_exists('sbir_get_notification_config') ? sbir_get_notification_config() : array();

        $groups = array(
            'admin' => array(
                'title' => __('Notify Admin', 'simpleboards-roadmap'),
                'description' => __('Sent to the notification email when these events happen.', 'simpleboards-roadmap'),
                'types' => array(),
                'labels' => array(
                    'new_submission' => __('New idea submission', 'simpleboards-roadmap'),
                    'item_rejected' => __('Item rejected (admin record)', 'simpleboards-roadmap'),
                    'admin_new_comment' => __('New comment published', 'simpleboards-roadmap'),
                ),
            ),
            'submitter' => array(
                'title' => __('Notify Submitter', 'simpleboards-roadmap'),
                'description' => __('Sent to the user who originally submitted the idea.', 'simpleboards-roadmap'),
                'types' => array(),
                'labels' => array(
                    'idea_published' => __('Idea approved/published', 'simpleboards-roadmap'),
                    'idea_rejected_user' => __('Idea was not approved', 'simpleboards-roadmap'),
                    'idea_promoted' => __('Idea moved to roadmap', 'simpleboards-roadmap'),
                    'submitter_status_changed' => __('Roadmap item status changed', 'simpleboards-roadmap'),
                ),
            ),
            'subscribers' => array(
                'title' => __('Notify Subscribers', 'simpleboards-roadmap'),
                'description' => __('Sent to users who subscribed to the item via the Subscribe button.', 'simpleboards-roadmap'),
                'types' => array(),
                'labels' => array(
                    'item_status_changed' => __('Item status changed', 'simpleboards-roadmap'),
                    'new_comment' => __('New comment on item', 'simpleboards-roadmap'),
                    'comment_reply' => __('Reply to a comment', 'simpleboards-roadmap'),
                ),
            ),
            'workflow' => array(
                'title' => __('Workflow Automations (Pro)', 'simpleboards-roadmap'),
                'description' => __('Triggered by Pro workflow automation rules.', 'simpleboards-roadmap'),
                'types' => array(),
                'labels' => array(
                    'pro_vote_promoted' => __('Vote rule moved item to roadmap', 'simpleboards-roadmap'),
                    'pro_status_change' => __('Status rule ran', 'simpleboards-roadmap'),
                    'pro_overdue' => __('Overdue rule ran', 'simpleboards-roadmap'),
                ),
            ),
        );

        // Map workflow templates (no toggle in core dispatcher; settings still editable here).
        $workflow_pro_keys = array(
            'pro_vote_promoted' => 'sbir_email_template_pro_vote_promoted',
            'pro_status_change' => 'sbir_email_template_pro_status_change',
            'pro_overdue' => 'sbir_email_template_pro_overdue',
        );
        $workflow_defaults = sbir_get_workflow_email_template_defaults();

        foreach ($config as $type_key => $entry) {
            $audience = isset($entry['audience']) ? $entry['audience'] : 'admin';
            if ($audience === 'comment_parent') {
                $audience = 'subscribers';
            }
            if (!isset($groups[$audience])) {
                $audience = 'admin';
            }
            $groups[$audience]['types'][$type_key] = $entry;
        }
        ?>
        <form method="post" action="options.php" class="sbir-notifications-form">
            <?php settings_fields('sbir_notification_settings'); ?>

            <h2><?php esc_html_e('Recipients & Subscriptions', 'simpleboards-roadmap'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="sbir_notification_email"><?php esc_html_e('Notification Email', 'simpleboards-roadmap'); ?></label></th>
                    <td>
                        <input type="email" id="sbir_notification_email" name="sbir_notification_email"
                            value="<?php echo esc_attr(get_option('sbir_notification_email', get_option('admin_email'))); ?>"
                            class="regular-text">
                        <p class="description"><?php esc_html_e('Email address to receive admin notifications.', 'simpleboards-roadmap'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Subscribe Feature', 'simpleboards-roadmap'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="sbir_subscriptions_enabled" value="yes"
                                <?php checked(get_option('sbir_subscriptions_enabled', 'yes'), 'yes'); ?>>
                            <?php esc_html_e('Allow users to subscribe to roadmap items and ideas.', 'simpleboards-roadmap'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Logged-in users subscribe with one click. Guests can subscribe with their email.', 'simpleboards-roadmap'); ?></p>
                    </td>
                </tr>
            </table>

            <?php foreach ($groups as $group_key => $group) : ?>
                <?php if ($group_key !== 'workflow' && empty($group['types'])) { continue; } ?>
                <h2 style="margin-top:24px;"><?php echo esc_html($group['title']); ?></h2>
                <p class="description" style="margin:-8px 0 12px;"><?php echo esc_html($group['description']); ?></p>

                <?php if ($group_key !== 'workflow') : ?>
                    <table class="form-table">
                        <?php foreach ($group['types'] as $type_key => $entry) : ?>
                            <tr>
                                <th scope="row"><?php echo esc_html(isset($group['labels'][$type_key]) ? $group['labels'][$type_key] : $type_key); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="<?php echo esc_attr($entry['option_toggle']); ?>" value="yes"
                                            <?php checked(get_option($entry['option_toggle'], $entry['default_toggle']), 'yes'); ?>>
                                        <?php esc_html_e('Enable this notification', 'simpleboards-roadmap'); ?>
                                    </label>
                                    <details class="sbir-template-details" style="margin-top:8px;">
                                        <summary style="cursor:pointer;color:#475569;font-size:12px;"><?php esc_html_e('Customize email template', 'simpleboards-roadmap'); ?></summary>
                                        <p style="margin:8px 0 4px;"><label for="<?php echo esc_attr($entry['subject_option']); ?>"><strong><?php esc_html_e('Subject', 'simpleboards-roadmap'); ?></strong></label></p>
                                        <input type="text" id="<?php echo esc_attr($entry['subject_option']); ?>" name="<?php echo esc_attr($entry['subject_option']); ?>"
                                            value="<?php echo esc_attr(get_option($entry['subject_option'], $entry['default_subject'])); ?>"
                                            class="regular-text">
                                        <p style="margin:10px 0 4px;"><label for="<?php echo esc_attr($entry['body_option']); ?>"><strong><?php esc_html_e('Body', 'simpleboards-roadmap'); ?></strong></label></p>
                                        <textarea id="<?php echo esc_attr($entry['body_option']); ?>" name="<?php echo esc_attr($entry['body_option']); ?>"
                                            rows="5" class="large-text"><?php echo esc_textarea(get_option($entry['body_option'], $entry['default_body'])); ?></textarea>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php else : ?>
                    <table class="form-table">
                        <?php foreach ($workflow_pro_keys as $row_key => $option_prefix) :
                            $subject_opt = $option_prefix . '_subject';
                            $body_opt = $option_prefix . '_body';
                            $default_subject = isset($workflow_defaults[$row_key]['subject']) ? (string) $workflow_defaults[$row_key]['subject'] : '';
                            $default_body = isset($workflow_defaults[$row_key]['body']) ? (string) $workflow_defaults[$row_key]['body'] : '';
                            ?>
                            <tr>
                                <th scope="row"><?php echo esc_html(isset($group['labels'][$row_key]) ? $group['labels'][$row_key] : $row_key); ?></th>
                                <td>
                                    <details class="sbir-template-details">
                                        <summary style="cursor:pointer;color:#475569;font-size:12px;"><?php esc_html_e('Customize email template', 'simpleboards-roadmap'); ?></summary>
                                        <p style="margin:8px 0 4px;"><label for="<?php echo esc_attr($subject_opt); ?>"><strong><?php esc_html_e('Subject', 'simpleboards-roadmap'); ?></strong></label></p>
                                        <input type="text" id="<?php echo esc_attr($subject_opt); ?>" name="<?php echo esc_attr($subject_opt); ?>"
                                            value="<?php echo esc_attr(get_option($subject_opt, $default_subject)); ?>" class="regular-text">
                                        <p style="margin:10px 0 4px;"><label for="<?php echo esc_attr($body_opt); ?>"><strong><?php esc_html_e('Body', 'simpleboards-roadmap'); ?></strong></label></p>
                                        <textarea id="<?php echo esc_attr($body_opt); ?>" name="<?php echo esc_attr($body_opt); ?>"
                                            rows="5" class="large-text"><?php echo esc_textarea(get_option($body_opt, $default_body)); ?></textarea>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            <?php endforeach; ?>

            <h2 style="margin-top:24px;"><?php esc_html_e('Template Tags', 'simpleboards-roadmap'); ?></h2>
            <p class="description">
                <code>{site_name}</code>, <code>{title}</code>, <code>{item_title}</code>, <code>{board_title}</code>,
                <code>{name}</code>, <code>{email}</code>, <code>{item_link}</code>, <code>{admin_ideas_url}</code>,
                <code>{from_status}</code>, <code>{to_status}</code>, <code>{commenter_name}</code>, <code>{comment_excerpt}</code>,
                <code>{votes}</code>, <code>{threshold}</code>, <code>{category}</code>, <code>{days_overdue}</code>, <code>{required_days}</code>, <code>{status_name}</code>
            </p>

            <?php submit_button(); ?>
        </form>
        <?php
    }

    /**
     * Handle LoopedIn CSV import request.
     *
     * @return void
     */
    public function handle_import_loopedin() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized.', 'simpleboards-roadmap'));
        }

        check_admin_referer('sbir_import_loopedin_action', 'sbir_import_loopedin_nonce');

        $board_id = isset($_POST['sbir_import_board_id']) ? absint(wp_unslash($_POST['sbir_import_board_id'])) : 0;
        $board = $board_id > 0 ? get_post($board_id) : null;
        if (!$board || $board->post_type !== 'sbir_board') {
            $this->redirect_import_result(array(
                'result' => 'error',
                'import_message' => __('Please select a valid board.', 'simpleboards-roadmap'),
            ));
        }

        $feedback_rows = $this->read_uploaded_csv_rows('sbir_import_feedback_csv');
        $roadmap_rows = $this->read_uploaded_csv_rows('sbir_import_roadmap_csv');
        $allow_duplicates = isset($_POST['sbir_import_allow_duplicates']) && wp_unslash($_POST['sbir_import_allow_duplicates']) === 'yes';
        $overwrite_existing = isset($_POST['sbir_import_overwrite_existing']) && wp_unslash($_POST['sbir_import_overwrite_existing']) === 'yes';

        if (is_wp_error($feedback_rows)) {
            $this->redirect_import_result(array(
                'result' => 'error',
                'import_message' => $feedback_rows->get_error_message(),
            ));
        }
        if (is_wp_error($roadmap_rows)) {
            $this->redirect_import_result(array(
                'result' => 'error',
                'import_message' => $roadmap_rows->get_error_message(),
            ));
        }
        if (empty($feedback_rows) && empty($roadmap_rows)) {
            $this->redirect_import_result(array(
                'result' => 'error',
                'import_message' => __('Please upload at least one CSV file.', 'simpleboards-roadmap'),
            ));
        }

        $stats = array(
            'imported_ideas' => 0,
            'imported_roadmap' => 0,
            'skipped' => 0,
            'errors' => 0,
        );

        $existing_idea_titles = $this->get_existing_item_id_index($board_id, 'no');
        $existing_roadmap_titles = $this->get_existing_item_id_index($board_id, 'yes');

        if (!empty($feedback_rows)) {
            $idea_stats = $this->import_loopedin_feedback_rows($feedback_rows, $board_id, $existing_idea_titles, $allow_duplicates, $overwrite_existing);
            $stats['imported_ideas'] += (int) $idea_stats['imported_ideas'];
            $stats['skipped'] += (int) $idea_stats['skipped'];
            $stats['errors'] += (int) $idea_stats['errors'];
        }

        if (!empty($roadmap_rows)) {
            $roadmap_stats = $this->import_loopedin_roadmap_rows($roadmap_rows, $board_id, $existing_roadmap_titles, $allow_duplicates, $overwrite_existing);
            $stats['imported_roadmap'] += (int) $roadmap_stats['imported_roadmap'];
            $stats['skipped'] += (int) $roadmap_stats['skipped'];
            $stats['errors'] += (int) $roadmap_stats['errors'];
        }

        if (class_exists('SBIR_Cache_Helper')) {
            SBIR_Cache_Helper::clear_all_cache();
        }

        $stats['result'] = $stats['errors'] > 0 ? 'error' : 'success';
        $this->redirect_import_result($stats);
    }

    /**
     * Parse uploaded CSV file into associative rows.
     *
     * @param string $input_key Input field key.
     * @return array|WP_Error
     */
    private function read_uploaded_csv_rows($input_key) {
        if (empty($_FILES[$input_key]) || !is_array($_FILES[$input_key])) {
            return array();
        }

        $file = $_FILES[$input_key];
        $error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            return array();
        }
        if ($error !== UPLOAD_ERR_OK) {
            return new WP_Error('upload_failed', __('CSV upload failed. Please try again.', 'simpleboards-roadmap'));
        }

        $tmp_name = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
        $file_name = isset($file['name']) ? (string) $file['name'] : '';
        if ($tmp_name === '' || !is_uploaded_file($tmp_name)) {
            return new WP_Error('invalid_upload', __('Uploaded CSV file is invalid.', 'simpleboards-roadmap'));
        }
        if (strtolower((string) pathinfo($file_name, PATHINFO_EXTENSION)) !== 'csv') {
            return new WP_Error('invalid_file_type', __('Only CSV files are supported for import.', 'simpleboards-roadmap'));
        }

        $handle = fopen($tmp_name, 'r');
        if ($handle === false) {
            return new WP_Error('csv_open_failed', __('Unable to read CSV file.', 'simpleboards-roadmap'));
        }

        $rows = array();
        $headers = array();
        $line = 0;
        while (($data = fgetcsv($handle, 0, ',')) !== false) {
            $line++;
            if ($line === 1) {
                foreach ($data as $header) {
                    $header = is_string($header) ? $header : '';
                    $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);
                    $headers[] = $this->normalize_import_header($header);
                }
                continue;
            }

            if (empty($headers)) {
                continue;
            }

            $row = array();
            $has_value = false;
            foreach ($headers as $index => $header_key) {
                if ($header_key === '') {
                    continue;
                }
                $value = isset($data[$index]) ? (string) $data[$index] : '';
                $value = trim($value);
                if ($value !== '') {
                    $has_value = true;
                }
                $row[$header_key] = $value;
            }

            if ($has_value) {
                $rows[] = $row;
            }
        }

        fclose($handle);
        return $rows;
    }

    /**
     * Import LoopedIn feedback export rows as ideas.
     *
     * @param array $rows                CSV rows.
     * @param int   $board_id            Target board ID.
     * @param array $existing_title_keys Existing title-key => item ID index.
     * @param bool  $allow_duplicates    Whether to allow duplicate titles.
     * @param bool  $overwrite_existing  Whether to overwrite existing item on match.
     * @return array
     */
    private function import_loopedin_feedback_rows($rows, $board_id, &$existing_title_keys, $allow_duplicates = false, $overwrite_existing = false) {
        $stats = array(
            'imported_ideas' => 0,
            'skipped' => 0,
            'errors' => 0,
        );

        foreach ((array) $rows as $row) {
            $title = isset($row['title']) ? sanitize_text_field((string) $row['title']) : '';
            $title_key = $this->normalize_import_title_key($title);
            if ($title === '' || $title_key === '') {
                $stats['skipped']++;
                continue;
            }
            $existing_item_id = isset($existing_title_keys[$title_key]) ? (int) $existing_title_keys[$title_key] : 0;
            if ($existing_item_id > 0 && !$allow_duplicates && !$overwrite_existing) {
                $stats['skipped']++;
                continue;
            }

            $description_raw = isset($row['description']) ? (string) $row['description'] : '';
            $description = $this->clean_import_text_content($description_raw);
            $created_gmt = isset($row['created_date']) ? $this->parse_import_datetime_gmt((string) $row['created_date']) : '';
            $postarr = array(
                'post_type' => 'sbir_item',
                'post_status' => 'publish',
                'post_author' => get_current_user_id(),
                'post_title' => $title,
                'post_content' => $description,
            );
            if ($created_gmt !== '') {
                $postarr['post_date_gmt'] = $created_gmt;
                $postarr['post_date'] = get_date_from_gmt($created_gmt, 'Y-m-d H:i:s');
            }

            if ($overwrite_existing && $existing_item_id > 0) {
                $postarr['ID'] = $existing_item_id;
                $item_id = wp_update_post($postarr, true);
            } else {
                $item_id = wp_insert_post($postarr, true);
            }
            if (is_wp_error($item_id) || !$item_id) {
                $stats['errors']++;
                continue;
            }

            update_post_meta((int) $item_id, '_sbir_board_id', (int) $board_id);
            update_post_meta((int) $item_id, '_sbir_is_roadmap', 'no');
            if (function_exists('sbir_get_item_number')) {
                sbir_get_item_number((int) $item_id);
            }

            $category_name = isset($row['category']) ? sanitize_text_field((string) $row['category']) : '';
            $category_term_id = $this->get_or_create_category_term($category_name);
            if ($category_term_id > 0) {
                wp_set_object_terms((int) $item_id, array((int) $category_term_id), 'sbir_category', false);
            }

            $votes = $this->parse_import_votes_from_row($row);
            if ($votes !== null) {
                $this->upsert_import_vote_count((int) $item_id, (int) $votes);
            }

            $existing_title_keys[$title_key] = (int) $item_id;
            $stats['imported_ideas']++;
        }

        return $stats;
    }

    /**
     * Import LoopedIn roadmap export rows as roadmap items.
     *
     * @param array $rows                CSV rows.
     * @param int   $board_id            Target board ID.
     * @param array $existing_title_keys Existing title-key => item ID index.
     * @param bool  $allow_duplicates    Whether to allow duplicate titles.
     * @param bool  $overwrite_existing  Whether to overwrite existing item on match.
     * @return array
     */
    private function import_loopedin_roadmap_rows($rows, $board_id, &$existing_title_keys, $allow_duplicates = false, $overwrite_existing = false) {
        $stats = array(
            'imported_roadmap' => 0,
            'skipped' => 0,
            'errors' => 0,
        );
        $status_lookup = $this->get_or_create_status_lookup($board_id);

        foreach ((array) $rows as $row) {
            $title = isset($row['title']) ? sanitize_text_field((string) $row['title']) : '';
            $title_key = $this->normalize_import_title_key($title);
            if ($title === '' || $title_key === '') {
                $stats['skipped']++;
                continue;
            }
            $existing_item_id = isset($existing_title_keys[$title_key]) ? (int) $existing_title_keys[$title_key] : 0;
            if ($existing_item_id > 0 && !$allow_duplicates && !$overwrite_existing) {
                $stats['skipped']++;
                continue;
            }

            $summary = isset($row['summary']) ? (string) $row['summary'] : '';
            $description_raw = isset($row['description']) ? (string) $row['description'] : '';
            $content = $description_raw !== '' ? $description_raw : $summary;
            $content = $this->clean_import_text_content((string) $content);

            $created_gmt = isset($row['created_date']) ? $this->parse_import_datetime_gmt((string) $row['created_date']) : '';
            $postarr = array(
                'post_type' => 'sbir_item',
                'post_status' => 'publish',
                'post_author' => get_current_user_id(),
                'post_title' => $title,
                'post_content' => $content,
            );
            if ($created_gmt !== '') {
                $postarr['post_date_gmt'] = $created_gmt;
                $postarr['post_date'] = get_date_from_gmt($created_gmt, 'Y-m-d H:i:s');
            }

            if ($overwrite_existing && $existing_item_id > 0) {
                $postarr['ID'] = $existing_item_id;
                $item_id = wp_update_post($postarr, true);
            } else {
                $item_id = wp_insert_post($postarr, true);
            }
            if (is_wp_error($item_id) || !$item_id) {
                $stats['errors']++;
                continue;
            }

            update_post_meta((int) $item_id, '_sbir_board_id', (int) $board_id);
            update_post_meta((int) $item_id, '_sbir_is_roadmap', 'yes');
            if (function_exists('sbir_get_item_number')) {
                sbir_get_item_number((int) $item_id);
            }

            $planned_date = isset($row['planned_release_date']) ? $this->parse_import_date((string) $row['planned_release_date']) : '';
            if ($planned_date !== '') {
                update_post_meta((int) $item_id, '_sbir_deadline', $planned_date);
            }

            $status_name = isset($row['roadmap_column']) ? sanitize_text_field((string) $row['roadmap_column']) : '';
            $status_key = $this->normalize_import_title_key($status_name);
            if ($status_key !== '' && isset($status_lookup[$status_key])) {
                wp_set_object_terms((int) $item_id, array((int) $status_lookup[$status_key]), 'sbir_status', false);
            }

            $objective = isset($row['roadmap_objective']) ? sanitize_text_field((string) $row['roadmap_objective']) : '';
            $category_term_id = $this->get_or_create_category_term($objective);
            if ($category_term_id > 0) {
                wp_set_object_terms((int) $item_id, array((int) $category_term_id), 'sbir_category', false);
            }

            $votes = $this->parse_import_votes_from_row($row);
            if ($votes !== null) {
                $this->upsert_import_vote_count((int) $item_id, (int) $votes);
            }

            $existing_title_keys[$title_key] = (int) $item_id;
            $stats['imported_roadmap']++;
        }

        return $stats;
    }

    /**
     * Build existing normalized title => item ID index for duplicates.
     *
     * @param int    $board_id    Board ID.
     * @param string $is_roadmap  yes/no.
     * @return array
     */
    private function get_existing_item_id_index($board_id, $is_roadmap) {
        global $wpdb;

        $board_id = (int) $board_id;
        $is_roadmap = $is_roadmap === 'yes' ? 'yes' : 'no';
        $sql = "
            SELECT p.ID, p.post_title
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_board
                ON pm_board.post_id = p.ID
                AND pm_board.meta_key = '_sbir_board_id'
            LEFT JOIN {$wpdb->postmeta} pm_type
                ON pm_type.post_id = p.ID
                AND pm_type.meta_key = '_sbir_is_roadmap'
            WHERE p.post_type = 'sbir_item'
              AND p.post_status IN ('publish','pending','draft')
              AND pm_board.meta_value = %d
              AND COALESCE(pm_type.meta_value, 'no') = %s
        ";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, $board_id, $is_roadmap), ARRAY_A);
        $index = array();
        foreach ((array) $rows as $row) {
            $key = $this->normalize_import_title_key(isset($row['post_title']) ? (string) $row['post_title'] : '');
            if ($key !== '') {
                $index[$key] = isset($row['ID']) ? (int) $row['ID'] : 0;
            }
        }
        return $index;
    }

    /**
     * Clean imported text content and remove raw HTML tags.
     *
     * @param string $value Raw content.
     * @return string
     */
    private function clean_import_text_content($value) {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }
        $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        $value = preg_replace('/<br\s*\/?>/i', "\n", $value);
        $value = preg_replace('/<\/p>/i', "\n\n", $value);
        $value = preg_replace('/<[^>]+>/', '', $value);
        $value = preg_replace('/\n{3,}/', "\n\n", (string) $value);
        return sanitize_textarea_field(trim((string) $value));
    }

    /**
     * Extract and normalize votes count from a CSV row.
     *
     * @param array $row CSV row data.
     * @return int|null Null when votes column is missing/empty.
     */
    private function parse_import_votes_from_row($row) {
        if (!is_array($row) || !isset($row['votes'])) {
            return null;
        }
        $raw_votes = trim((string) $row['votes']);
        if ($raw_votes === '') {
            return null;
        }
        $normalized = preg_replace('/[^0-9]/', '', $raw_votes);
        if ($normalized === '') {
            return 0;
        }
        return absint($normalized);
    }

    /**
     * Upsert vote count for an imported item into vote counts table.
     *
     * @param int $item_id    Item ID.
     * @param int $vote_count Vote count.
     * @return void
     */
    private function upsert_import_vote_count($item_id, $vote_count) {
        global $wpdb;

        $item_id = absint($item_id);
        if ($item_id <= 0) {
            return;
        }
        $vote_count = max(0, absint($vote_count));

        $table = $wpdb->prefix . 'sbir_vote_counts';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO `{$table}` (item_id, vote_count) VALUES (%d, %d)
                 ON DUPLICATE KEY UPDATE vote_count = VALUES(vote_count)",
                $item_id,
                $vote_count
            )
        );

        if (class_exists('SBIR_Cache_Helper')) {
            SBIR_Cache_Helper::clear_vote_cache($item_id);
        } else {
            wp_cache_delete('vote_count_' . $item_id, 'sbir_plugin');
        }
    }

    /**
     * Get (or create) category term by name.
     *
     * @param string $name Category name.
     * @return int
     */
    private function get_or_create_category_term($name) {
        $name = sanitize_text_field((string) $name);
        if ($name === '') {
            return 0;
        }

        $term = get_term_by('name', $name, 'sbir_category');
        if ($term && !is_wp_error($term)) {
            return (int) $term->term_id;
        }

        $created = wp_insert_term($name, 'sbir_category');
        if (is_wp_error($created) || empty($created['term_id'])) {
            return 0;
        }
        return (int) $created['term_id'];
    }

    /**
     * Build status lookup for board, creating terms if needed.
     *
     * @param int $board_id Board ID.
     * @return array
     */
    private function get_or_create_status_lookup($board_id) {
        $lookup = array();
        $terms = get_terms(array(
            'taxonomy' => 'sbir_status',
            'hide_empty' => false,
        ));
        foreach ((array) $terms as $term) {
            if (!$term || is_wp_error($term)) {
                continue;
            }
            $belongs_to = (int) get_term_meta((int) $term->term_id, '_sbir_status_board', true);
            if ($belongs_to !== 0 && $belongs_to !== (int) $board_id) {
                continue;
            }
            $lookup[$this->normalize_import_title_key((string) $term->name)] = (int) $term->term_id;
        }

        $required_statuses = array('up next', 'in progress', 'done');
        foreach ($required_statuses as $status_name) {
            $status_key = $this->normalize_import_title_key($status_name);
            if ($status_key === '' || isset($lookup[$status_key])) {
                continue;
            }
            $created = wp_insert_term(ucwords($status_name), 'sbir_status');
            if (is_wp_error($created) || empty($created['term_id'])) {
                continue;
            }
            $term_id = (int) $created['term_id'];
            update_term_meta($term_id, '_sbir_status_board', (int) $board_id);
            $lookup[$status_key] = $term_id;
        }

        return $lookup;
    }

    /**
     * Normalize CSV header.
     *
     * @param string $header Header text.
     * @return string
     */
    private function normalize_import_header($header) {
        $header = strtolower((string) $header);
        $header = preg_replace('/[^a-z0-9]+/', '_', $header);
        return trim((string) $header, '_');
    }

    /**
     * Normalize title keys used for duplicate checks.
     *
     * @param string $title Title text.
     * @return string
     */
    private function normalize_import_title_key($title) {
        $title = strtolower(trim((string) $title));
        $title = preg_replace('/[^a-z0-9\s]+/', ' ', $title);
        $title = preg_replace('/\s+/', ' ', $title);
        return trim((string) $title);
    }

    /**
     * Parse date value into Y-m-d.
     *
     * @param string $value Date string.
     * @return string
     */
    private function parse_import_date($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $timestamp = strtotime($value);
        if (!$timestamp) {
            return '';
        }
        return gmdate('Y-m-d', $timestamp);
    }

    /**
     * Parse date/time value into UTC datetime string.
     *
     * @param string $value Datetime string.
     * @return string
     */
    private function parse_import_datetime_gmt($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $timestamp = strtotime($value);
        if (!$timestamp) {
            return '';
        }
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Redirect back to Import tab with result query args.
     *
     * @param array $stats Import result stats.
     * @return void
     */
    private function redirect_import_result($stats) {
        $stats = is_array($stats) ? $stats : array();
        $args = array(
            'page' => 'sbir-settings',
            'tab' => 'import',
            'import_result' => isset($stats['result']) ? sanitize_key((string) $stats['result']) : 'success',
            'imported_ideas' => isset($stats['imported_ideas']) ? (int) $stats['imported_ideas'] : 0,
            'imported_roadmap' => isset($stats['imported_roadmap']) ? (int) $stats['imported_roadmap'] : 0,
            'skipped' => isset($stats['skipped']) ? (int) $stats['skipped'] : 0,
            'errors' => isset($stats['errors']) ? (int) $stats['errors'] : 0,
        );
        if (!empty($stats['import_message'])) {
            $args['import_message'] = sanitize_text_field((string) $stats['import_message']);
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    /**
     * Sanitize yes/no toggles
     */
    public static function sanitize_yes_no($value) {
        return $value === 'yes' ? 'yes' : 'no';
    }

    /**
     * Sanitize permalink base (slug-like, but allow basic text)
     */
    public static function sanitize_slug_base($value) {
        $value = sanitize_title($value);
        return $value ? $value : 'products';
    }

    /**
     * Sanitize email subject text.
     *
     * @param string $value Subject text.
     * @return string
     */
    public static function sanitize_email_subject($value) {
        return sanitize_text_field($value);
    }

    /**
     * Sanitize email body text while preserving line breaks.
     *
     * @param string $value Body text.
     * @return string
     */
    public static function sanitize_email_body($value) {
        return sanitize_textarea_field($value);
    }

    /**
     * Flush rewrite rules when permalink base changes.
     *
     * @param string $option    Updated option name.
     * @param mixed  $old_value Previous value.
     * @param mixed  $value     New value.
     * @return void
     */
    public function maybe_flush_rewrite_rules($option, $old_value, $value) {
        if ($option !== 'sbir_permalink_base') {
            return;
        }

        if ((string) $old_value === (string) $value) {
            return;
        }

        flush_rewrite_rules();
    }
}