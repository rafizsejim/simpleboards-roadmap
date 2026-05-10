<?php
/**
 * Minimal single template for `sbir_board`.
 *
 * Bypasses the theme's entry wrappers (featured image banner, entry-header,
 * entry-meta, author/date/comments, post navigation) so that board pages show
 * only the plugin UI inside the site header/footer.
 *
 * Enabled via the `sbir_use_custom_board_template` filter (default: true).
 *
 * @package SimpleBoards_Roadmap
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>
<main id="sbir-board-main" class="sbir-board-main" role="main">
    <?php
    while (have_posts()) {
        the_post();
        /*
         * the_content() runs the `the_content` filter where the plugin
         * injects the board display markup. Nothing else from the theme's
         * entry wrappers is rendered on this template.
         */
        the_content();
    }
    ?>
</main>
<?php
get_footer();
