<?php
/**
 * Voting system using custom tables for performance.
 *
 * Uses direct DB queries for atomic vote toggling and count consistency.
 * All queries use prepared statements.
 *
 * @package SimpleBoards_Roadmap
 */
if (!defined('ABSPATH')) {
    exit;
}

class SBIR_Voting {

    /**
     * Register AJAX handlers for voting.
     */
    public function init() {
        add_action('wp_ajax_sbir_vote', array($this, 'handle_vote'));
        add_action('wp_ajax_nopriv_sbir_vote', array($this, 'handle_vote'));
        // Read-only vote status endpoint
        add_action('wp_ajax_sbir_vote_status', array($this, 'vote_status'));
        add_action('wp_ajax_nopriv_sbir_vote_status', array($this, 'vote_status'));
    }
    
    /**
     * Handle vote submission (custom tables only)
     * 
     * Note: This method uses direct database queries intentionally for:
     * 1. Custom table operations (not covered by WordPress APIs)
     * 2. Atomic vote operations with transactions
     * 3. Performance optimization for high-frequency voting
     * 4. Preventing race conditions in concurrent voting scenarios
     * 
     * All queries use proper prepared statements for security.
     */
    public function handle_vote() {
        check_ajax_referer('sbir_public_nonce', 'nonce');
        
        $item_id = isset($_POST['item_id']) ? absint(wp_unslash($_POST['item_id'])) : 0;
        
        if (!$item_id) {
            wp_send_json_error(__('Invalid item.', 'simpleboards-roadmap'));
        }

        if (!sbir_current_user_can_access_item($item_id, 'vote')) {
            wp_send_json_error(__('Unauthorized', 'simpleboards-roadmap'));
        }
        
        // Get user identifier (cached)
        $user_identifier = SBIR_Cache_Helper::get_cached_user_identifier();
        
        global $wpdb;

        // Table creation is handled on plugin activation via dbDelta. No runtime DDL here.
        
        // Optimistic toggle in 2 queries without pre-select
        $added = false;
        $error = false;
        
        // Start lightweight transaction to keep count consistent
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query('START TRANSACTION');
        
        // Try to insert; if row inserted -> added, else already exists -> remove
        // Insert attempt
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO `{$wpdb->prefix}sbir_votes` (item_id, user_identifier, vote_time) VALUES (%d, %s, %s)",
                $item_id, $user_identifier, current_time('mysql')
            )
        );
        if ($inserted === false) {
            $error = true;
        }
        
        if ((int)$inserted === 1) {
            // Newly added
            $added = true;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $updated = $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO `{$wpdb->prefix}sbir_vote_counts` (item_id, vote_count) VALUES (%d, 1)
                     ON DUPLICATE KEY UPDATE vote_count = vote_count + 1",
                    $item_id
                )
            );
            if ($updated === false) {
                $error = true;
            }
        } else {
            // Already existed: remove and decrement
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $deleted = $wpdb->delete(
                $wpdb->prefix . 'sbir_votes', 
                array(
                    'item_id' => $item_id,
                    'user_identifier' => $user_identifier
                ), 
                array('%d', '%s')
            );
            if ($deleted === false) {
                $error = true;
            }
            
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $updated = $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO `{$wpdb->prefix}sbir_vote_counts` (item_id, vote_count) VALUES (%d, 0)
                     ON DUPLICATE KEY UPDATE vote_count = GREATEST(vote_count - 1, 0)",
                    $item_id
                )
            );
            if ($updated === false) {
                $error = true;
            }
        }
        if ($error) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query('ROLLBACK');
            wp_send_json_error(__('Vote could not be saved. Please try again.', 'simpleboards-roadmap'));
        }
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query('COMMIT');
        
        // Get updated count (fresh from counts table)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $new_votes = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT vote_count FROM `{$wpdb->prefix}sbir_vote_counts` WHERE item_id = %d",
                $item_id
            )
        );
        
        // Clear caches
        SBIR_Cache_Helper::clear_vote_cache($item_id, $user_identifier);
        wp_cache_set('vote_count_' . $item_id, $new_votes, SBIR_Cache_Helper::CACHE_GROUP, 300);
        
        do_action('sbir_after_vote', $item_id, $user_identifier);

        wp_send_json_success(array(
            'votes' => (int) $new_votes,
            'voted' => $added,
            'message' => $added ? __('Vote added', 'simpleboards-roadmap') : __('Vote removed', 'simpleboards-roadmap')
        ));
    }
    
    // removed private get_user_identifier(); using helper instead

    /**
     * Read-only vote status: return current count and whether user has voted
     */
    public function vote_status() {
        check_ajax_referer('sbir_public_nonce', 'nonce');

        $item_id = isset($_POST['item_id']) ? absint(wp_unslash($_POST['item_id'])) : 0;
        if (!$item_id) {
            wp_send_json_error(__('Invalid item.', 'simpleboards-roadmap'));
        }

        if (!sbir_current_user_can_access_item($item_id, 'vote_status')) {
            wp_send_json_error(__('Unauthorized', 'simpleboards-roadmap'));
        }

        $user_identifier = SBIR_Cache_Helper::get_cached_user_identifier();
        

        // Use helper functions to fetch current count and status
        $count = (int) sbir_get_vote_count($item_id);
        $voted = (bool) sbir_user_has_voted($item_id, $user_identifier);
        
        

        wp_send_json_success(array(
            'votes' => $count,
            'voted' => $voted
        ));
    }
}