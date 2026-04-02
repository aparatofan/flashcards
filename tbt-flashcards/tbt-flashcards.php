<?php
/**
 * Plugin Name: TBT Flashcards
 * Description: Interactive flashcard widget for The Blue Tree English lessons.
 * Version: 1.0.0
 * Author: Mariusz Mirecki
 * Text Domain: tbt-flashcards
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register the [tbt_flashcards] shortcode.
 */
function tbt_flashcards_register_shortcode() {
    add_shortcode( 'tbt_flashcards', 'tbt_flashcards_render' );
}
add_action( 'init', 'tbt_flashcards_register_shortcode' );

/**
 * Track whether assets have been enqueued this request.
 */
function tbt_flashcards_enqueue_assets() {
    static $enqueued = false;
    if ( $enqueued ) {
        return;
    }
    $enqueued = true;

    wp_enqueue_style(
        'tbt-flashcards-google-fonts',
        'https://fonts.googleapis.com/css2?family=Roboto+Slab:wght@300;700&display=swap',
        array(),
        null
    );

    wp_enqueue_style(
        'tbt-flashcards',
        plugin_dir_url( __FILE__ ) . 'assets/tbt-flashcards.css',
        array(),
        '1.0.0'
    );

    wp_enqueue_script(
        'tbt-flashcards',
        plugin_dir_url( __FILE__ ) . 'assets/tbt-flashcards.js',
        array(),
        '1.0.0',
        true
    );
}

/**
 * Look up a CSV attachment in the Media Library by filename.
 *
 * @param string $filename The filename to search for.
 * @return string|false The attachment URL or false if not found.
 */
function tbt_flashcards_get_csv_url( $filename ) {
    $filename = sanitize_file_name( $filename );

    $attachments = get_posts( array(
        'post_type'      => 'attachment',
        'post_mime_type' => 'text/csv',
        'posts_per_page' => -1,
        'post_status'    => 'inherit',
    ) );

    foreach ( $attachments as $attachment ) {
        $attached_file = get_attached_file( $attachment->ID );
        if ( basename( $attached_file ) === $filename ) {
            return wp_get_attachment_url( $attachment->ID );
        }
    }

    return false;
}

/**
 * Render the flashcard shortcode.
 *
 * @param array $atts Shortcode attributes.
 * @return string HTML output.
 */
function tbt_flashcards_render( $atts ) {
    $atts = shortcode_atts( array(
        'file'   => '',
        'title'  => '',
        'height' => '595',
    ), $atts, 'tbt_flashcards' );

    if ( empty( $atts['file'] ) ) {
        return '<p style="color:#c00;font-weight:bold;">TBT Flashcards error: No file specified.</p>';
    }

    $csv_url = tbt_flashcards_get_csv_url( $atts['file'] );

    if ( ! $csv_url ) {
        return '<p style="color:#c00;font-weight:bold;">Flashcard file not found: ' . esc_html( $atts['file'] ) . '</p>';
    }

    // Enqueue assets only when shortcode is used.
    tbt_flashcards_enqueue_assets();

    // Generate unique instance ID.
    static $instance_counter = 0;
    $instance_counter++;
    $instance_id = 'tbt-fc-' . $instance_counter;

    // Derive title from filename if not provided.
    $title = $atts['title'];
    if ( empty( $title ) ) {
        $title = pathinfo( $atts['file'], PATHINFO_FILENAME );
        $title = str_replace( array( '-', '_' ), ' ', $title );
        $title = ucwords( $title );
    }

    $height = intval( $atts['height'] );
    if ( $height < 100 ) {
        $height = 340;
    }

    ob_start();
    ?>
    <div class="tbt-flashcard-app" id="<?php echo esc_attr( $instance_id ); ?>"
         data-csv-url="<?php echo esc_url( $csv_url ); ?>"
         data-title="<?php echo esc_attr( $title ); ?>"
         data-height="<?php echo esc_attr( $height ); ?>">

        <div class="fc-header">
            <div class="fc-set-title"><?php echo esc_html( $title ); ?></div>
            <div class="fc-counter">
                <span class="fc-counter-current">0</span> / <span class="fc-counter-total">0</span>
            </div>
        </div>

        <div class="fc-card-scene" style="height:<?php echo esc_attr( $height ); ?>px;">
            <div class="fc-card">
                <div class="fc-card-face fc-card-front">
                    <div class="fc-word">&mdash;</div>
                    <button class="fc-audio-btn" title="Listen">
                        <svg viewBox="0 0 24 24"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>
                    </button>
                    <div class="fc-tap-hint">tap to flip</div>
                </div>
                <div class="fc-card-face fc-card-back">
                    <div class="fc-translation">&mdash;</div>
                    <div class="fc-phonetic"></div>
                    <div class="fc-example"></div>
                </div>
            </div>
        </div>

        <div class="fc-nav">
            <button class="fc-nav-btn fc-prev-btn" title="Previous" disabled>
                <svg viewBox="0 0 24 24"><path d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6z"/></svg>
            </button>
            <button class="fc-flip-btn">Flip</button>
            <button class="fc-nav-btn fc-next-btn" title="Next">
                <svg viewBox="0 0 24 24"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6z"/></svg>
            </button>
        </div>

        <canvas class="fc-confetti"></canvas>
    </div>
    <?php
    return ob_get_clean();
}
