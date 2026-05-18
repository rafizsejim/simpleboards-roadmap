<?php
/**
 * Minimal single template for `sbir_board` — CLASSIC THEMES ONLY.
 *
 * Bypasses the theme's entry wrappers (featured image banner, entry-header,
 * entry-meta, author/date/comments, post navigation) so that board pages show
 * only the plugin UI inside the site header/footer.
 *
 * Enabled via the `sbir_use_custom_board_template` filter (default: true).
 *
 * BLOCK THEMES are intentionally NOT routed here. The template override in
 * `SBIR_Frontend::template_loader()` is gated on `!wp_is_block_theme()`, so
 * block themes render their own `single.html` / `page.html` exactly as they
 * do for any other post type. `board_content_filter()` (attached to
 * `the_content`) already swaps in the board markup at the post-content slot,
 * and a `render_block` filter strips leftover entry-meta blocks on board
 * pages — together that gives block themes a header / nav / footer that
 * matches every other page on the site, with no rebuild needed here.
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
