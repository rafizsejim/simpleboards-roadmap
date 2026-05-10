<?php
/**
 * Shortcode handler for [sbir_board].
 *
 * @package SimpleBoards_Roadmap
 */
if (!defined('ABSPATH')) {
    exit;
}

class SBIR_Shortcodes {

    /**
     * Register shortcode.
     */
    public function init() {
        add_shortcode('sbir_board', array($this, 'render_shortcode'));
    }
    
    /**
     * Render [sbir_board] shortcode output.
     *
     * @param array $atts Shortcode attributes (product, board).
     * @return string
     */
    public function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'product' => '',
            'board' => ''
        ), $atts, 'sbir_board');
        
        ob_start();
        
        // If no product specified, show board selector
        if (empty($atts['product'])) {
            $this->render_board_selector();
        } else {
            // Get board by slug
            $board = get_page_by_path($atts['product'], OBJECT, 'sbir_board');
            
            if ($board) {
                if (!sbir_current_user_can_access_board((int) $board->ID, 'shortcode')) {
                    echo '<div class="sbir-notice">' . esc_html(apply_filters('sbir_private_board_notice', __('This board is private.', 'simpleboards-roadmap'), (int) $board->ID, 'shortcode')) . '</div>';
                    return ob_get_clean();
                }
                $view = !empty($atts['board']) ? $atts['board'] : 'both';
                $frontend = new SBIR_Frontend();
                $frontend->render_board_display($board->ID, $view);
            } else {
                echo '<div class="sbir-notice">' . esc_html__('Board not found.', 'simpleboards-roadmap') . '</div>';
            }
        }
        
        return ob_get_clean();
    }
    
    /**
     * Output board selector grid when no product is specified.
     */
    private function render_board_selector() {
        $boards = sbir_get_boards_list(200);
        
        if (empty($boards)) {
            echo '<div class="sbir-notice">' . esc_html__('No boards found.', 'simpleboards-roadmap') . '</div>';
            return;
        }
        
        ?>
        <div class="sbir-board-selector">
            <h3 class="sbir-selector-title"><?php esc_html_e('Select a Product', 'simpleboards-roadmap'); ?></h3>
            <div class="sbir-boards-grid">
                <?php foreach ($boards as $board) : ?>
                    <a href="<?php echo esc_url(get_permalink($board)); ?>" class="sbir-board-card">
                        <?php if (has_post_thumbnail($board)) : ?>
                            <?php echo get_the_post_thumbnail($board, 'medium'); ?>
                        <?php endif; ?>
                        <h4><?php echo esc_html($board->post_title); ?></h4>
                        <?php if ($board->post_excerpt) : ?>
                            <p><?php echo esc_html($board->post_excerpt); ?></p>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
}