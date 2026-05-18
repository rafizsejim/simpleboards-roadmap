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
 * Classic themes: uses `get_header()` / `get_footer()` exactly as before — no
 * behaviour change.
 *
 * Block themes (Twenty Twenty-Four+, Twenty Twenty-Five, etc.): `get_header()`
 * in a block theme has no `header.php` to load and falls back to a minimal
 * stub that does NOT render the block theme's `parts/header.html` (where the
 * Site Title + Navigation blocks live). To restore the site nav on board
 * pages we open the document chrome manually and invoke
 * `block_template_part('header')` / `block_template_part('footer')` directly.
 *
 * @package SimpleBoards_Roadmap
 */

if (!defined('ABSPATH')) {
    exit;
}

$sbir_is_block_theme = function_exists('wp_is_block_theme') && wp_is_block_theme() && function_exists('block_template_part');

if ($sbir_is_block_theme) :
    ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php
    wp_body_open();
    block_template_part('header');
    ?>
    <main id="sbir-board-main" class="sbir-board-main" role="main">
        <?php
        while (have_posts()) {
            the_post();
            the_content();
        }
        ?>
    </main>
    <?php
    block_template_part('footer');
    wp_footer();
    ?>
</body>
</html>
<?php else :
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
endif;
