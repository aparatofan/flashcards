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

// ElevenLabs voice ID used for all TTS requests (hardcoded — not user-configurable).
define( 'TBT_FC_ELEVENLABS_VOICE_ID', 'dPKFsZN0BnPRUfVI2DUW' );

// Load admin functionality (custom post type, meta boxes).
require_once plugin_dir_path( __FILE__ ) . 'admin/class-tbt-flashcards-admin.php';

/**
 * Flush rewrite rules on activation so the custom post type URLs work immediately.
 */
function tbt_flashcards_activate() {
    tbt_flashcards_register_post_type();
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'tbt_flashcards_activate' );

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
        'https://fonts.googleapis.com/css2?family=Inter:wght@300;500;700&display=swap',
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

    // Pass AJAX endpoint and nonce to the JS so it can call the TTS proxy.
    wp_localize_script( 'tbt-flashcards', 'tbtFcAjax', array(
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'tbt_fc_tts_nonce' ),
    ) );
}

/**
 * Register the Settings → TBT Flashcards admin page.
 */
function tbt_fc_register_settings_page() {
    add_options_page(
        __( 'TBT Flashcards', 'tbt-flashcards' ),
        __( 'TBT Flashcards', 'tbt-flashcards' ),
        'manage_options',
        'tbt-flashcards',
        'tbt_fc_render_settings_page'
    );
}
add_action( 'admin_menu', 'tbt_fc_register_settings_page' );

/**
 * Register the ElevenLabs API key option with the Settings API.
 */
function tbt_fc_register_settings() {
    register_setting(
        'tbt_fc_settings',
        'tbt_fc_elevenlabs_api_key',
        array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        )
    );
}
add_action( 'admin_init', 'tbt_fc_register_settings' );

/**
 * Render the TBT Flashcards settings page (API key field only).
 */
function tbt_fc_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $api_key = get_option( 'tbt_fc_elevenlabs_api_key', '' );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'TBT Flashcards', 'tbt-flashcards' ); ?></h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'tbt_fc_settings' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="tbt_fc_elevenlabs_api_key">
                            <?php esc_html_e( 'ElevenLabs API Key', 'tbt-flashcards' ); ?>
                        </label>
                    </th>
                    <td>
                        <input type="password"
                               id="tbt_fc_elevenlabs_api_key"
                               name="tbt_fc_elevenlabs_api_key"
                               value="<?php echo esc_attr( $api_key ); ?>"
                               class="regular-text"
                               autocomplete="off" />
                        <p class="description">
                            <?php esc_html_e( 'Enter your ElevenLabs API key. Get one at elevenlabs.io', 'tbt-flashcards' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

/**
 * Resolve (and create on first use) the audio cache directory.
 *
 * @return string|false Absolute path to the cache dir, or false on failure.
 */
function tbt_fc_get_audio_cache_dir() {
    $uploads = wp_upload_dir();
    if ( ! empty( $uploads['error'] ) ) {
        return false;
    }
    $dir = trailingslashit( $uploads['basedir'] ) . 'tbt-flashcards-audio';
    if ( ! file_exists( $dir ) ) {
        if ( ! wp_mkdir_p( $dir ) ) {
            return false;
        }
    }
    return $dir;
}

/**
 * Stream the given MP3 bytes to the browser as an audio/mpeg response.
 * Terminates the request.
 *
 * @param string $body Raw MP3 binary.
 * @param string $cache_status HIT or MISS, for debugging.
 */
function tbt_fc_send_audio_response( $body, $cache_status ) {
    nocache_headers();
    header( 'Content-Type: audio/mpeg' );
    header( 'Content-Length: ' . strlen( $body ) );
    header( 'X-TBT-FC-Cache: ' . $cache_status );
    echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary audio output.
    // Use die() directly so wp_ajax doesn't append a trailing "0" to the binary stream.
    die();
}

/**
 * AJAX proxy: receive text from the browser, call ElevenLabs, return MP3.
 * Caches every unique phrase on disk so each one is generated only once.
 */
function tbt_fc_tts_proxy() {
    // Nonce check.
    $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'tbt_fc_tts_nonce' ) ) {
        wp_send_json( array( 'error' => 'Invalid nonce' ), 403 );
    }

    // Text validation.
    $text = isset( $_POST['text'] ) ? wp_unslash( $_POST['text'] ) : '';
    $text = trim( sanitize_text_field( $text ) );

    if ( '' === $text ) {
        wp_send_json( array( 'error' => 'Empty text' ), 400 );
    }
    if ( strlen( $text ) > 200 ) {
        wp_send_json( array( 'error' => 'Text too long (max 200 characters)' ), 400 );
    }

    // API key must be configured.
    $api_key = get_option( 'tbt_fc_elevenlabs_api_key', '' );
    if ( empty( $api_key ) ) {
        wp_send_json( array( 'error' => 'TTS not configured' ), 503 );
    }

    // Cache lookup.
    $cache_dir = tbt_fc_get_audio_cache_dir();
    $cache_key = md5( $text );
    $cache_file = $cache_dir ? trailingslashit( $cache_dir ) . $cache_key . '.mp3' : '';

    if ( $cache_file && file_exists( $cache_file ) ) {
        $cached = file_get_contents( $cache_file );
        if ( false !== $cached && strlen( $cached ) > 0 ) {
            tbt_fc_send_audio_response( $cached, 'HIT' );
        }
    }

    // Cache miss — call ElevenLabs.
    $response = wp_remote_post(
        'https://api.elevenlabs.io/v1/text-to-speech/' . TBT_FC_ELEVENLABS_VOICE_ID,
        array(
            'timeout' => 30,
            'headers' => array(
                'xi-api-key'   => $api_key,
                'Content-Type' => 'application/json',
                'Accept'       => 'audio/mpeg',
            ),
            'body'    => wp_json_encode( array(
                'text'           => $text,
                'model_id'       => 'eleven_multilingual_v2',
                'voice_settings' => array(
                    'stability'         => 0.6,
                    'similarity_boost'  => 0.75,
                    'style'             => 0.0,
                    'use_speaker_boost' => true,
                ),
            ) ),
        )
    );

    if ( is_wp_error( $response ) ) {
        wp_send_json(
            array( 'error' => 'Upstream request failed: ' . $response->get_error_message() ),
            502
        );
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );

    if ( 200 !== $code || '' === $body ) {
        wp_send_json(
            array( 'error' => 'ElevenLabs returned status ' . $code ),
            $code ? $code : 502
        );
    }

    // Save to cache (best effort — if the write fails we still serve the audio).
    if ( $cache_file ) {
        @file_put_contents( $cache_file, $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    }

    tbt_fc_send_audio_response( $body, 'MISS' );
}
add_action( 'wp_ajax_tbt_fc_tts', 'tbt_fc_tts_proxy' );
add_action( 'wp_ajax_nopriv_tbt_fc_tts', 'tbt_fc_tts_proxy' );

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
        'id'     => '',
        'file'   => '',
        'url'    => '',
        'title'  => '',
        'height' => '595',
    ), $atts, 'tbt_flashcards' );

    // If an id is provided, render from the flashcard set post.
    if ( ! empty( $atts['id'] ) ) {
        $post_id = absint( $atts['id'] );
        if ( $post_id && 'tbt_flashcard_set' === get_post_type( $post_id ) ) {
            return tbt_flashcards_render_from_post( $post_id );
        }
        return '<p style="color:#c00;font-weight:bold;">Flashcard set not found: ' . esc_html( $atts['id'] ) . '</p>';
    }

    if ( ! empty( $atts['url'] ) ) {
        // url takes priority over file.
        $csv_url = esc_url_raw( $atts['url'] );
        $filename_for_title = basename( wp_parse_url( $atts['url'], PHP_URL_PATH ) );
    } elseif ( ! empty( $atts['file'] ) ) {
        $csv_url = tbt_flashcards_get_csv_url( $atts['file'] );
        if ( ! $csv_url ) {
            return '<p style="color:#c00;font-weight:bold;">Flashcard file not found: ' . esc_html( $atts['file'] ) . '</p>';
        }
        $filename_for_title = $atts['file'];
    } else {
        return '<p style="color:#c00;font-weight:bold;">TBT Flashcards error: No file or url specified.</p>';
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
        $title = pathinfo( $filename_for_title, PATHINFO_FILENAME );
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
        </div>

        <div class="fc-card-scene" style="height:<?php echo esc_attr( $height ); ?>px;">
            <div class="fc-card">
                <div class="fc-card-face fc-card-front">
                    <div class="fc-card-counter"><span class="fc-counter-current">0</span> / <span class="fc-counter-total">0</span></div>
                    <div class="fc-word">&mdash;</div>
                    <button class="fc-audio-btn" title="Listen">
                        <svg viewBox="0 0 24 24"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>
                    </button>
                </div>
                <div class="fc-card-face fc-card-back">
                    <div class="fc-card-counter"><span class="fc-counter-current">0</span> / <span class="fc-counter-total">0</span></div>
                    <div class="fc-translation">&mdash;</div>
                    <div class="fc-phonetic"></div>
                    <div class="fc-back-divider"></div>
                    <div class="fc-example"></div>
                </div>
            </div>
        </div>

        <div class="fc-nav">
            <button class="fc-nav-btn fc-prev-btn" title="Previous" disabled>
                <svg viewBox="0 0 24 24"><path d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6z"/></svg>
            </button>
            <button class="fc-nav-btn fc-next-btn" title="Next">
                <svg viewBox="0 0 24 24"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6z"/></svg>
            </button>
        </div>

        <canvas class="fc-confetti"></canvas>
    </div>
    <?php
    return ob_get_clean();
}
