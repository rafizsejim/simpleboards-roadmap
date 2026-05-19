<?php
/**
 * Plugin Name: SimpleBoards - Ideas and Roadmap Solution
 * Plugin URI: https://simpleboardswp.com
 * Description: A simple yet powerful roadmap and ideas management solution for WordPress. Collect user feedback, manage product roadmaps, and prioritize features.
 * Version: 1.0.3
 * Author: Rafiz Sejim
 * Author URI: https://simpleboardswp.com
 * License: GPL v2 or later
 * Text Domain: simpleboards-roadmap
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SBIR_VERSION', '1.0.3');
define('SBIR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SBIR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SBIR_PLUGIN_BASENAME', plugin_basename(__FILE__));
// Removed trialware-style free limits to comply with WordPress.org guidelines

/**
 * Main plugin class
 */
class SimpleBoards_Roadmap {
    
    /**
     * Single instance of the class
     */
    protected static $instance = null;
    
    /**
     * Main instance
     */
    public static function instance() {
        if (null == self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }
    
    /**
     * Load required dependencies
     */
    private function load_dependencies() {
        require_once SBIR_PLUGIN_DIR . 'includes/class-sbir-helpers.php';
        require_once SBIR_PLUGIN_DIR . 'includes/class-sbir-post-types.php';
        require_once SBIR_PLUGIN_DIR . 'includes/class-sbir-admin.php';
        require_once SBIR_PLUGIN_DIR . 'includes/class-sbir-frontend.php';
        require_once SBIR_PLUGIN_DIR . 'includes/class-sbir-shortcodes.php';
        require_once SBIR_PLUGIN_DIR . 'includes/class-sbir-voting.php';
        require_once SBIR_PLUGIN_DIR . 'includes/class-sbir-submission.php';
        require_once SBIR_PLUGIN_DIR . 'includes/class-sbir-subscriptions.php';
        require_once SBIR_PLUGIN_DIR . 'includes/class-sbir-settings.php';
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Initialize components
        add_action('plugins_loaded', array($this, 'init'));

        // Auto-flush rewrite rules once when plugin code changes rules.
        add_action('init', array($this, 'maybe_flush_rewrite_rules'), 99);
    }

    /**
     * Flush rewrite rules once after rule-affecting code changes.
     */
    public function maybe_flush_rewrite_rules() {
        $current = SBIR_VERSION . '-4';
        if (get_option('sbir_rewrite_version', '') !== $current) {
            flush_rewrite_rules();
            update_option('sbir_rewrite_version', $current, true);
        }
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Ensure CPTs and taxonomies are registered before flushing rewrites
        if (!class_exists('SBIR_Post_Types')) {
            require_once SBIR_PLUGIN_DIR . 'includes/class-sbir-post-types.php';
        }
        $post_types = new SBIR_Post_Types();
        $post_types->register_post_types();
        $post_types->register_taxonomies();

        // Create default status terms
        $this->create_default_statuses();

        // Set default options
        $this->set_default_options();
        
        // Index creation disabled to comply with WordPress.org guidelines
        
        // Create custom tables for voting system
        $this->create_voting_tables();

        // Flush rewrite rules after registering
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        flush_rewrite_rules();
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Register post types and taxonomies
        $post_types = new SBIR_Post_Types();
        $post_types->init();
        
        // Initialize admin
        if (is_admin()) {
            $admin = new SBIR_Admin();
            $admin->init();
            
            $settings = new SBIR_Settings();
            $settings->init();
        }
        
        // Initialize frontend on normal frontend requests
        if (!is_admin()) {
            $frontend = new SBIR_Frontend();
            $frontend->init();
            
            $shortcodes = new SBIR_Shortcodes();
            $shortcodes->init();
        }
        
        // Ensure AJAX actions are registered during admin-ajax requests
        if (wp_doing_ajax()) {
            $frontend_ajax = new SBIR_Frontend();
            $frontend_ajax->init();
        }
        
        // Initialize voting system
        $voting = new SBIR_Voting();
        $voting->init();
        
        // Initialize submission handler
        $submission = new SBIR_Submission();
        $submission->init();

        // Initialize subscriptions (subscribe button, AJAX, unsubscribe link)
        $subscriptions = new SBIR_Subscriptions();
        $subscriptions->init();

        // Initialize cache clearing hooks
        $this->init_cache_hooks();
    }
    
    
    /**
     * Create default status terms
     */
    private function create_default_statuses() {
        // Register taxonomy temporarily to create terms
        register_taxonomy('sbir_status', 'sbir_item', array());
        
        $default_statuses = array(
            'planned' => __('Planned', 'simpleboards-roadmap'),
            'in-progress' => __('In Progress', 'simpleboards-roadmap'),
            'done' => __('Done', 'simpleboards-roadmap')
        );
        
        foreach ($default_statuses as $slug => $name) {
            if (!term_exists($slug, 'sbir_status')) {
                wp_insert_term($name, 'sbir_status', array('slug' => $slug));
            }
        }
    }
    
    /**
     * Set default plugin options
     */
    private function set_default_options() {
        $workflow_defaults = sbir_get_workflow_email_template_defaults();

        $defaults = array(
            'sbir_permalink_base' => 'products',
            'sbir_enable_guest_submissions' => 'yes',
            'sbir_moderate_submissions' => 'yes',
            'sbir_submission_compose_button_text' => __('Share an idea...', 'simpleboards-roadmap'),
            'sbir_notification_email' => get_option('admin_email'),
            'sbir_email_new_submission' => 'yes',
            'sbir_email_rejected' => 'yes',
            'sbir_email_template_new_submission_subject' => __('[{site_name}] New idea submitted', 'simpleboards-roadmap'),
            'sbir_email_template_new_submission_body' => __("A new idea has been submitted.\n\nTitle: {title}\nDescription: {description}\nSubmitted by: {name} ({email})\n\nView all ideas: {admin_ideas_url}", 'simpleboards-roadmap'),
            'sbir_email_template_item_rejected_subject' => __('[{site_name}] Item rejected', 'simpleboards-roadmap'),
            'sbir_email_template_item_rejected_body' => __("An item has been rejected.\n\nItem: {title}\n\nReview item: {item_link}", 'simpleboards-roadmap'),
            'sbir_email_template_pro_vote_promoted_subject' => isset($workflow_defaults['pro_vote_promoted']['subject']) ? $workflow_defaults['pro_vote_promoted']['subject'] : __('[{site_name}] Idea moved to roadmap by workflow rule', 'simpleboards-roadmap'),
            'sbir_email_template_pro_vote_promoted_body' => isset($workflow_defaults['pro_vote_promoted']['body']) ? $workflow_defaults['pro_vote_promoted']['body'] : __("A workflow rule moved an idea to roadmap.\n\nItem: {item_title}\nBoard: {board_title}\nVotes: {votes}\nThreshold: {threshold}\n\nReview item: {item_link}", 'simpleboards-roadmap'),
            'sbir_email_template_pro_status_change_subject' => isset($workflow_defaults['pro_status_change']['subject']) ? $workflow_defaults['pro_status_change']['subject'] : __('[{site_name}] Workflow status rule executed', 'simpleboards-roadmap'),
            'sbir_email_template_pro_status_change_body' => isset($workflow_defaults['pro_status_change']['body']) ? $workflow_defaults['pro_status_change']['body'] : __("A workflow status rule ran.\n\nItem: {item_title}\nBoard: {board_title}\nFrom: {from_status}\nTo: {to_status}\nAssigned category: {category}\n\nReview item: {item_link}", 'simpleboards-roadmap'),
            'sbir_email_template_pro_overdue_subject' => isset($workflow_defaults['pro_overdue']['subject']) ? $workflow_defaults['pro_overdue']['subject'] : __('[{site_name}] Workflow overdue rule executed', 'simpleboards-roadmap'),
            'sbir_email_template_pro_overdue_body' => isset($workflow_defaults['pro_overdue']['body']) ? $workflow_defaults['pro_overdue']['body'] : __("A workflow overdue rule ran.\n\nItem: {item_title}\nBoard: {board_title}\nDays overdue: {days_overdue}\nRule threshold: {required_days}\nSet status: {status_name}\n\nReview item: {item_link}", 'simpleboards-roadmap'),
        );
        
        foreach ($defaults as $option => $value) {
            if (get_option($option) === false) {
                update_option($option, $value);
            }
        }
    }
    
    /**
     * Create custom voting tables for better performance
     */
    private function create_voting_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Votes table
        $votes_table = $wpdb->prefix . 'sbir_votes';
        $votes_sql = "CREATE TABLE $votes_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            item_id bigint(20) NOT NULL,
            user_identifier varchar(255) NOT NULL,
            vote_time datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_vote (item_id, user_identifier),
            KEY item_votes (item_id),
            KEY vote_time (vote_time)
        ) $charset_collate;";
        
        // Vote counts table
        $counts_table = $wpdb->prefix . 'sbir_vote_counts';
        $counts_sql = "CREATE TABLE $counts_table (
            item_id bigint(20) NOT NULL,
            vote_count int(11) DEFAULT 0,
            last_updated datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (item_id),
            KEY vote_count (vote_count),
            KEY last_updated (last_updated)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($votes_sql);
        dbDelta($counts_sql);
        
        // Tables are now required - no flag needed
    }
    
    /**
     * Initialize cache clearing hooks for immediate updates
     */
    private function init_cache_hooks() {
        // Clear item cache when posts are saved/updated/deleted
        add_action('save_post', array('SBIR_Cache_Helper', 'clear_item_cache'));
        add_action('delete_post', array('SBIR_Cache_Helper', 'clear_item_cache'));
        
        // Clear styles cache when status terms are modified
        add_action('create_term', array($this, 'maybe_clear_styles_cache'), 10, 2);
        add_action('edit_term', array($this, 'maybe_clear_styles_cache'), 10, 2);
        add_action('delete_term', array($this, 'maybe_clear_styles_cache'), 10, 2);
        
        // Clear all cache when plugin is deactivated
        add_action('switch_theme', array('SBIR_Cache_Helper', 'clear_all_cache'));
    }
    
    /**
     * Clear styles cache if status taxonomy is modified
     */
    public function maybe_clear_styles_cache($term_id, $taxonomy) {
        if ($taxonomy === 'sbir_status') {
            SBIR_Cache_Helper::clear_styles_cache();
        }
    }
}

// Initialize the plugin
SimpleBoards_Roadmap::instance();
