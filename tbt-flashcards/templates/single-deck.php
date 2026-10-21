<?php
/**
 * Full-screen deck for a single flashcard set (/flashcards/{slug}/).
 *
 * Deliberately bare: no get_header()/get_footer(), so the theme's header,
 * post meta and sidebar never render. wp_head()/wp_footer() still run so the
 * player's assets, the admin bar and site-wide scripts load as usual.
 *
 * Themes may override this file at tbt-flashcards/single-deck.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <?php wp_head(); ?>
</head>
<body <?php body_class( 'tbtfc-deck-page' ); ?>>
<?php
if ( function_exists( 'wp_body_open' ) ) {
    wp_body_open();
}

while ( have_posts() ) {
    the_post();
    echo tbt_flashcards_render_from_post( get_the_ID(), 'deck' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the renderer.
}

wp_footer();
?>
</body>
</html>
