<?php
/**
 * Subscriptions: subscribe/unsubscribe to roadmap items and ideas.
 *
 * @package SimpleBoards_Roadmap
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles per-item subscribers (logged-in users + guest emails).
 *
 * Storage:
 * - Logged-in user IDs: post meta `_sbir_subscribers_users` (array of int).
 * - Guest emails:       post meta `_sbir_subscribers_emails` (array of email strings).
 *
 * Unsubscribe is token-based to keep one-click links safe in emails.
 */
class SBIR_Subscriptions {

    const META_USERS = '_sbir_subscribers_users';
    const META_EMAILS = '_sbir_subscribers_emails';

    /**
     * Register hooks.
     *
     * @return void
     */
    public function init() {
        add_action('wp_ajax_sbir_subscribe_item', array($this, 'ajax_subscribe'));
        add_action('wp_ajax_nopriv_sbir_subscribe_item', array($this, 'ajax_subscribe'));
        add_action('wp_ajax_sbir_unsubscribe_item', array($this, 'ajax_unsubscribe'));
        add_action('wp_ajax_nopriv_sbir_unsubscribe_item', array($this, 'ajax_unsubscribe'));
        add_action('template_redirect', array($this, 'maybe_handle_unsubscribe_link'), 1);
    }

    /**
     * AJAX: subscribe current visitor (or provided email) to an item.
     *
     * @return void
     */
    public function ajax_subscribe() {
        check_ajax_referer('sbir_public_nonce', 'nonce');

        $item_id = isset($_POST['item_id']) ? absint(wp_unslash($_POST['item_id'])) : 0;
        if ($item_id <= 0 || get_post_type($item_id) !== 'sbir_item') {
            wp_send_json_error(array('message' => __('Invalid item.', 'simpleboards-roadmap')));
        }

        if (!sbir_current_user_can_access_item($item_id, 'subscribe')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'simpleboards-roadmap')));
        }

        if (get_option('sbir_subscriptions_enabled', 'yes') !== 'yes') {
            wp_send_json_error(array('message' => __('Subscriptions are disabled.', 'simpleboards-roadmap')));
        }

        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            self::add_user_subscriber($item_id, $user_id);
            wp_send_json_success(array(
                'subscribed' => true,
                'message' => __('Subscribed for updates.', 'simpleboards-roadmap'),
            ));
        }

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        if ($email === '' || !is_email($email)) {
            wp_send_json_error(array('message' => __('Please enter a valid email address.', 'simpleboards-roadmap')));
        }
        self::add_email_subscriber($item_id, $email);
        wp_send_json_success(array(
            'subscribed' => true,
            'message' => __('Subscribed. We will email you when this item changes.', 'simpleboards-roadmap'),
        ));
    }

    /**
     * AJAX: unsubscribe current user (logged-in only convenience endpoint).
     *
     * @return void
     */
    public function ajax_unsubscribe() {
        check_ajax_referer('sbir_public_nonce', 'nonce');

        $item_id = isset($_POST['item_id']) ? absint(wp_unslash($_POST['item_id'])) : 0;
        if ($item_id <= 0 || get_post_type($item_id) !== 'sbir_item') {
            wp_send_json_error(array('message' => __('Invalid item.', 'simpleboards-roadmap')));
        }

        if (is_user_logged_in()) {
            self::remove_user_subscriber($item_id, get_current_user_id());
            wp_send_json_success(array(
                'subscribed' => false,
                'message' => __('Unsubscribed.', 'simpleboards-roadmap'),
            ));
        }

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        if ($email !== '') {
            self::remove_email_subscriber($item_id, $email);
            wp_send_json_success(array(
                'subscribed' => false,
                'message' => __('Unsubscribed.', 'simpleboards-roadmap'),
            ));
        }

        wp_send_json_error(array('message' => __('Unable to unsubscribe.', 'simpleboards-roadmap')));
    }

    /**
     * Handle one-click unsubscribe links in emails.
     *
     * Format: ?sbir_unsub=ITEM_ID&email=EMAIL&token=TOKEN
     *
     * @return void
     */
    public function maybe_handle_unsubscribe_link() {
        if (is_admin() || wp_doing_ajax()) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token-protected GET endpoint
        if (!isset($_GET['sbir_unsub'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token-protected
        $item_id = absint(wp_unslash($_GET['sbir_unsub']));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token-protected
        $email = isset($_GET['email']) ? sanitize_email(wp_unslash($_GET['email'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token-protected
        $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';

        if ($item_id <= 0 || $email === '' || $token === '') {
            return;
        }

        if (!hash_equals(self::generate_unsubscribe_token($item_id, $email), $token)) {
            return;
        }

        self::remove_email_subscriber($item_id, $email);

        $back = get_permalink($item_id);
        if (!$back) {
            $back = home_url('/');
        }
        wp_safe_redirect(add_query_arg('sbir_unsub_done', '1', $back));
        exit;
    }

    /**
     * Generate token for unsubscribe links.
     *
     * @param int    $item_id Item ID.
     * @param string $email   Email address.
     * @return string
     */
    public static function generate_unsubscribe_token($item_id, $email) {
        $secret = defined('AUTH_SALT') ? AUTH_SALT : (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : 'sbir');
        return hash_hmac('sha256', $item_id . '|' . strtolower((string) $email), (string) $secret);
    }

    /**
     * Build a one-click unsubscribe URL for a subscriber email.
     *
     * @param int    $item_id Item ID.
     * @param string $email   Email address.
     * @return string
     */
    public static function unsubscribe_url($item_id, $email) {
        $base = get_permalink($item_id);
        if (!$base) {
            $base = home_url('/');
        }
        return add_query_arg(
            array(
                'sbir_unsub' => (int) $item_id,
                'email' => rawurlencode((string) $email),
                'token' => self::generate_unsubscribe_token($item_id, $email),
            ),
            $base
        );
    }

    /**
     * Add a logged-in user subscriber.
     *
     * @param int $item_id Item ID.
     * @param int $user_id User ID.
     * @return void
     */
    public static function add_user_subscriber($item_id, $user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return;
        }
        $users = self::get_user_subscribers((int) $item_id);
        if (in_array($user_id, $users, true)) {
            return;
        }
        $users[] = $user_id;
        if (count($users) > 5000) {
            $users = array_slice($users, -5000);
        }
        update_post_meta((int) $item_id, self::META_USERS, $users);
    }

    /**
     * Remove a logged-in user subscriber.
     *
     * @param int $item_id Item ID.
     * @param int $user_id User ID.
     * @return void
     */
    public static function remove_user_subscriber($item_id, $user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return;
        }
        $users = self::get_user_subscribers((int) $item_id);
        $users = array_values(array_diff($users, array($user_id)));
        if (empty($users)) {
            delete_post_meta((int) $item_id, self::META_USERS);
            return;
        }
        update_post_meta((int) $item_id, self::META_USERS, $users);
    }

    /**
     * Add a guest email subscriber.
     *
     * @param int    $item_id Item ID.
     * @param string $email   Email.
     * @return void
     */
    public static function add_email_subscriber($item_id, $email) {
        $email = strtolower(trim((string) $email));
        if ($email === '' || !is_email($email)) {
            return;
        }
        $emails = self::get_email_subscribers((int) $item_id);
        if (in_array($email, $emails, true)) {
            return;
        }
        $emails[] = $email;
        if (count($emails) > 5000) {
            $emails = array_slice($emails, -5000);
        }
        update_post_meta((int) $item_id, self::META_EMAILS, $emails);
    }

    /**
     * Remove a guest email subscriber.
     *
     * @param int    $item_id Item ID.
     * @param string $email   Email.
     * @return void
     */
    public static function remove_email_subscriber($item_id, $email) {
        $email = strtolower(trim((string) $email));
        if ($email === '') {
            return;
        }
        $emails = self::get_email_subscribers((int) $item_id);
        $emails = array_values(array_diff($emails, array($email)));
        if (empty($emails)) {
            delete_post_meta((int) $item_id, self::META_EMAILS);
            return;
        }
        update_post_meta((int) $item_id, self::META_EMAILS, $emails);
    }

    /**
     * Get logged-in subscribers (user ids).
     *
     * @param int $item_id Item ID.
     * @return int[]
     */
    public static function get_user_subscribers($item_id) {
        $stored = get_post_meta((int) $item_id, self::META_USERS, true);
        if (!is_array($stored)) {
            return array();
        }
        return array_values(array_filter(array_map('intval', $stored)));
    }

    /**
     * Get guest email subscribers.
     *
     * @param int $item_id Item ID.
     * @return string[]
     */
    public static function get_email_subscribers($item_id) {
        $stored = get_post_meta((int) $item_id, self::META_EMAILS, true);
        if (!is_array($stored)) {
            return array();
        }
        $clean = array();
        foreach ($stored as $email) {
            $email = strtolower(trim((string) $email));
            if ($email !== '' && is_email($email)) {
                $clean[] = $email;
            }
        }
        return array_values(array_unique($clean));
    }

    /**
     * Whether a user is subscribed.
     *
     * @param int $item_id Item ID.
     * @param int $user_id User ID.
     * @return bool
     */
    public static function is_user_subscribed($item_id, $user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return false;
        }
        return in_array($user_id, self::get_user_subscribers((int) $item_id), true);
    }

    /**
     * Get a flat list of subscriber email addresses for sending.
     *
     * Returns associative array of email => display_name|''.
     *
     * @param int   $item_id  Item ID.
     * @param int[] $exclude_user_ids User IDs to exclude (e.g., the actor).
     * @return array<string,string>
     */
    public static function get_subscriber_emails_for_send($item_id, $exclude_user_ids = array()) {
        $exclude_user_ids = array_map('intval', (array) $exclude_user_ids);
        $emails = array();

        foreach (self::get_user_subscribers((int) $item_id) as $user_id) {
            if (in_array((int) $user_id, $exclude_user_ids, true)) {
                continue;
            }
            $user = get_userdata((int) $user_id);
            if (!$user || !$user->user_email) {
                continue;
            }
            $emails[strtolower($user->user_email)] = $user->display_name;
        }

        foreach (self::get_email_subscribers((int) $item_id) as $email) {
            if (!isset($emails[$email])) {
                $emails[$email] = '';
            }
        }

        return $emails;
    }

    /**
     * Render a subscribe button for an item (frontend).
     *
     * @param int $item_id Item ID.
     * @return void
     */
    public static function render_subscribe_button($item_id) {
        $item_id = (int) $item_id;
        if ($item_id <= 0 || get_option('sbir_subscriptions_enabled', 'yes') !== 'yes') {
            return;
        }

        $is_subscribed = is_user_logged_in() && self::is_user_subscribed($item_id, get_current_user_id());
        ?>
        <div class="sbir-subscribe sbir-subscribe-block" data-item-id="<?php echo esc_attr((string) $item_id); ?>">
            <button
                type="button"
                class="sbir-subscribe-btn<?php echo $is_subscribed ? ' is-subscribed' : ''; ?>"
                data-item-id="<?php echo esc_attr((string) $item_id); ?>"
                aria-pressed="<?php echo $is_subscribed ? 'true' : 'false'; ?>"
            >
                <span class="sbir-subscribe-icon" aria-hidden="true">🔔</span>
                <span class="sbir-subscribe-label"><?php echo $is_subscribed
                    ? esc_html__('Subscribed', 'simpleboards-roadmap')
                    : esc_html__('Subscribe', 'simpleboards-roadmap'); ?></span>
            </button>
            <?php if (!is_user_logged_in()) : ?>
                <div class="sbir-subscribe-guest" hidden>
                    <input type="email" class="sbir-subscribe-email" placeholder="<?php esc_attr_e('your@email.com', 'simpleboards-roadmap'); ?>" autocomplete="email">
                    <button type="button" class="sbir-subscribe-confirm sbir-btn sbir-btn-primary"><?php esc_html_e('Subscribe', 'simpleboards-roadmap'); ?></button>
                </div>
            <?php endif; ?>
            <p class="sbir-subscribe-message" aria-live="polite"></p>
        </div>
        <?php
    }
}
