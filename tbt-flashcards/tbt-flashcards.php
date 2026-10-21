<?php
/**
 * Plugin Name: TBT Flashcards
 * Description: Interactive flashcard widget for The Blue Tree English lessons.
 * Version: 2.0.0
 * Author: Mariusz Mirecki
 * Text Domain: tbt-flashcards
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TBT_FC_VERSION', '2.0.0' );
define( 'TBT_FC_DIR', plugin_dir_path( __FILE__ ) );
define( 'TBT_FC_URL', plugin_dir_url( __FILE__ ) );

// ElevenLabs voice ID used for all TTS requests (hardcoded — not user-configurable).
define( 'TBT_FC_ELEVENLABS_VOICE_ID', 'dPKFsZN0BnPRUfVI2DUW' );

// ElevenLabs model. eleven_turbo_v2_5 supports the language_code parameter,
// which lets us lock pronunciation to a specific language (see below).
define( 'TBT_FC_ELEVENLABS_MODEL_ID', 'eleven_turbo_v2_5' );

// Force English pronunciation regardless of how the source word is spelled.
// Some words (e.g. "accentuate") were being interpreted as non-English by
// eleven_multilingual_v2, which has no way to lock the language.
define( 'TBT_FC_ELEVENLABS_LANGUAGE_CODE', 'en' );

// Load admin functionality (custom post type, meta boxes).
require_once TBT_FC_DIR . 'admin/class-tbt-flashcards-admin.php';

// 2.0: access gate, card model, AI generation, REST routes, teacher tools.
require_once TBT_FC_DIR . 'includes/access.php';
require_once TBT_FC_DIR . 'includes/cards.php';
require_once TBT_FC_DIR . 'includes/openai.php';
require_once TBT_FC_DIR . 'includes/rest.php';
require_once TBT_FC_DIR . 'includes/tools.php';

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

    tbt_fc_register_fonts();
    wp_enqueue_style( 'tbt-flashcards-google-fonts' );

    wp_enqueue_style(
        'tbt-flashcards',
        TBT_FC_URL . 'assets/tbt-flashcards.css',
        array( 'tbt-flashcards-google-fonts' ),
        TBT_FC_VERSION
    );

    wp_enqueue_script(
        'tbt-flashcards',
        TBT_FC_URL . 'assets/tbt-flashcards.js',
        array(),
        TBT_FC_VERSION,
        true
    );

    // Pass AJAX endpoint and nonce to the JS so it can call the TTS proxy.
    wp_localize_script( 'tbt-flashcards', 'tbtFcAjax', array(
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'tbt_fc_tts_nonce' ),
    ) );
}

/**
 * Register the one Google Fonts request shared by the player and the tools.
 */
function tbt_fc_register_fonts() {
    if ( wp_style_is( 'tbt-flashcards-google-fonts', 'registered' ) ) {
        return;
    }
    wp_register_style(
        'tbt-flashcards-google-fonts',
        'https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&family=Roboto+Mono:wght@500;700&family=Roboto+Slab:wght@400;500;600;700&display=swap',
        array(),
        null
    );
}

/**
 * Register the flashcards settings page.
 *
 * Under the TBT hub (as "TBT Flashcards Settings") when the hub is active;
 * otherwise a top-level menu of its own so settings are never unreachable.
 */
function tbt_fc_register_settings_page() {
    if ( defined( 'TBT_HUB_SLUG' ) ) {
        add_submenu_page(
            TBT_HUB_SLUG,
            __( 'TBT Flashcards Settings', 'tbt-flashcards' ),
            __( 'TBT Flashcards Settings', 'tbt-flashcards' ),
            'manage_options',
            'tbt-flashcards',
            'tbt_fc_render_settings_page'
        );
    } else {
        add_menu_page(
            __( 'TBT Flashcards Settings', 'tbt-flashcards' ),
            __( 'TBT Flashcards Settings', 'tbt-flashcards' ),
            'manage_options',
            'tbt-flashcards',
            'tbt_fc_render_settings_page',
            'dashicons-welcome-learn-more',
            3
        );
    }
}
add_action( 'admin_menu', 'tbt_fc_register_settings_page' );

/**
 * Register this plugin on the TBT Hub Overview page.
 *
 * The card links to the Flashcard Sets list (the custom post type where the
 * actual content is authored) via the `url` key, rather than the settings
 * page above.
 *
 * @param array $items Existing hub items.
 * @return array
 */
function tbt_fc_register_hub_item( $items ) {
    $items[] = array(
        'slug'        => 'tbt-flashcards',
        'title'       => 'TBT Flashcards',
        'description' => 'Lesson flashcards with AI-filled cards and ElevenLabs audio.',
        'capability'  => 'edit_posts',
        'url'         => admin_url( 'edit.php?post_type=tbt_flashcard_set' ),
    );
    return $items;
}
add_filter( 'tbt_hub_items', 'tbt_fc_register_hub_item' );

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
 * Delete every .mp3 file in the audio cache directory. Returns the number
 * of files removed (0 if the directory doesn't exist or is empty).
 *
 * @return int
 */
function tbt_fc_purge_audio_cache() {
    $dir = tbt_fc_get_audio_cache_dir();
    if ( ! $dir || ! is_dir( $dir ) ) {
        return 0;
    }
    $deleted = 0;
    foreach ( glob( trailingslashit( $dir ) . '*.mp3' ) ?: array() as $file ) {
        if ( @unlink( $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            $deleted++;
        }
    }
    return $deleted;
}

/**
 * Render the TBT Flashcards settings page (API key field + cache purge).
 */
function tbt_fc_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Handle the "purge audio cache" admin action.
    $purge_notice = '';
    if (
        isset( $_POST['tbt_fc_action'] )
        && 'purge_audio_cache' === $_POST['tbt_fc_action']
        && check_admin_referer( 'tbt_fc_purge_audio_cache' )
    ) {
        $deleted = tbt_fc_purge_audio_cache();
        $purge_notice = sprintf(
            /* translators: %d = number of cached MP3 files removed */
            _n( 'Removed %d cached audio file.', 'Removed %d cached audio files.', $deleted, 'tbt-flashcards' ),
            $deleted
        );
    }

    $api_key = get_option( 'tbt_fc_elevenlabs_api_key', '' );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'TBT Flashcards', 'tbt-flashcards' ); ?></h1>
        <?php if ( $purge_notice ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $purge_notice ); ?></p></div>
        <?php endif; ?>
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

        <h2><?php esc_html_e( 'AI card generation', 'tbt-flashcards' ); ?></h2>
        <p>
            <?php
            if ( '' !== tbt_fc_openai_api_key() ) {
                esc_html_e( 'OpenAI API key detected', 'tbt-flashcards' );
            } else {
                esc_html_e( 'OpenAI API key not detected', 'tbt-flashcards' );
            }
            ?>
        </p>
        <p class="description">
            <?php esc_html_e( 'The key is read from wp-config.php (TBT_FLASHCARDS_OPENAI_API_KEY, TBT_MATCHING_GAMES_OPENAI_API_KEY or OPENAI_API_KEY), the same key TBT Matching Games uses.', 'tbt-flashcards' ); ?>
        </p>

        <hr />

        <h2><?php esc_html_e( 'Audio cache', 'tbt-flashcards' ); ?></h2>
        <p>
            <?php
            printf(
                /* translators: %s = absolute filesystem path of the audio cache dir */
                esc_html__( 'Cached MP3s are stored in: %s', 'tbt-flashcards' ),
                '<code>' . esc_html( tbt_fc_get_audio_cache_dir() ?: '(unavailable)' ) . '</code>'
            );
            ?>
        </p>
        <p class="description">
            <?php esc_html_e( 'Use this after changing the TTS model or language to remove orphaned files generated by previous settings.', 'tbt-flashcards' ); ?>
        </p>
        <form method="post">
            <?php wp_nonce_field( 'tbt_fc_purge_audio_cache' ); ?>
            <input type="hidden" name="tbt_fc_action" value="purge_audio_cache" />
            <?php submit_button( __( 'Purge audio cache', 'tbt-flashcards' ), 'delete', 'submit', false ); ?>
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

    // Cache lookup. The cache key includes model_id and language_code so that
    // changing either invalidates old (potentially mispronounced) entries.
    $cache_dir = tbt_fc_get_audio_cache_dir();
    $cache_key = md5(
        $text
        . TBT_FC_ELEVENLABS_VOICE_ID
        . TBT_FC_ELEVENLABS_MODEL_ID
        . TBT_FC_ELEVENLABS_LANGUAGE_CODE
    );
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
                'model_id'       => TBT_FC_ELEVENLABS_MODEL_ID,
                'language_code'  => TBT_FC_ELEVENLABS_LANGUAGE_CODE,
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
            // Only published sets play in lessons, so a trashed or drafted
            // set disappears for visitors; editors still see the notice.
            if ( 'publish' === get_post_status( $post_id ) ) {
                return tbt_flashcards_render_from_post( $post_id );
            }
            if ( ! current_user_can( 'edit_posts' ) ) {
                return '';
            }
        }
        return '<p style="color:#c00;font-weight:bold;">Flashcard set not found: ' . esc_html( $atts['id'] ) . '</p>';
    }

    return tbt_flashcards_render_csv( $atts );
}

/**
 * Render a CSV-backed widget from `url` or `file` (the 1.0.0 behaviour).
 *
 * @param array  $atts Attributes: file, url, title, height.
 * @param string $mode 'inline' (lessons) or 'deck' (the set's own page).
 * @return string HTML output.
 */
function tbt_flashcards_render_csv( $atts, $mode = 'inline' ) {
    $atts = wp_parse_args( $atts, array(
        'file'   => '',
        'url'    => '',
        'title'  => '',
        'height' => '595',
    ) );

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

    // Derive title from filename if not provided.
    $title = $atts['title'];
    if ( empty( $title ) ) {
        $title = pathinfo( $filename_for_title, PATHINFO_FILENAME );
        $title = str_replace( array( '-', '_' ), ' ', $title );
        $title = ucwords( $title );
    }

    return tbt_flashcards_render_app( array(
        'csv_url' => $csv_url,
        'title'   => $title,
        'height'  => $atts['height'],
        'mode'    => $mode,
    ) );
}

/**
 * Print the widget markup.
 *
 * Exactly one card source is set: `csv_url` (legacy sets and file/url
 * shortcodes, parsed in the browser as in 1.0.0) or `cards_url` (editor sets,
 * served by the public REST route).
 *
 * @param array $args csv_url, cards_url, title, height, mode.
 * @return string HTML output.
 */
function tbt_flashcards_render_app( $args ) {
    $args = wp_parse_args( $args, array(
        'csv_url'   => '',
        'cards_url' => '',
        'title'     => '',
        'height'    => '595',
        'mode'      => 'inline',
    ) );

    // Enqueue assets only when shortcode is used.
    tbt_flashcards_enqueue_assets();

    // Generate unique instance ID.
    static $instance_counter = 0;
    $instance_counter++;
    $instance_id = 'tbt-fc-' . $instance_counter;

    $title   = $args['title'];
    $is_deck = 'deck' === $args['mode'];

    $height = intval( $args['height'] );
    if ( $height < 100 ) {
        $height = 340;
    }

    ob_start();
    ?>
    <div class="tbt-flashcard-app<?php echo $is_deck ? ' is-deck' : ''; ?>" id="<?php echo esc_attr( $instance_id ); ?>"
         <?php if ( $args['cards_url'] ) : ?>data-cards-url="<?php echo esc_url( $args['cards_url'] ); ?>"<?php else : ?>data-csv-url="<?php echo esc_url( $args['csv_url'] ); ?>"<?php endif; ?>
         data-title="<?php echo esc_attr( $title ); ?>"
         data-height="<?php echo esc_attr( $height ); ?>"
         data-mode="<?php echo esc_attr( $is_deck ? 'deck' : 'inline' ); ?>">

        <div class="fc-header">
            <div class="fc-set-title"><?php echo esc_html( $title ); ?></div>
            <?php if ( $is_deck ) : ?>
                <div class="fc-deck-brand">THE BLUE TREE</div>
            <?php endif; ?>
        </div>

        <div class="fc-card-scene"<?php echo $is_deck ? '' : ' style="height:' . esc_attr( $height ) . 'px;"'; ?>>
            <div class="fc-card">
                <div class="fc-card-face fc-card-front">
                    <div class="fc-card-counter"><span class="fc-counter-current">0</span> / <span class="fc-counter-total">0</span></div>
                    <div class="fc-word">&mdash;</div>
                    <button class="fc-audio-btn" title="Listen">
                        <svg viewBox="0 0 24 24"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>
                    </button>
                    <div class="fc-hint">Click to flip</div>
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
            <div class="fc-progress" aria-live="polite">0 / 0</div>
            <button class="fc-nav-btn fc-next-btn" title="Next">
                <svg viewBox="0 0 24 24"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6z"/></svg>
            </button>
        </div>

        <?php if ( $is_deck ) : ?>
            <div class="fc-deck-bar" aria-hidden="true"><span class="fc-deck-bar-fill"></span></div>
        <?php endif; ?>

        <canvas class="fc-confetti"></canvas>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Use the bare deck template for a single flashcard set.
 *
 * Themes may override it at tbt-flashcards/single-deck.php.
 *
 * @param string $template Template path.
 * @return string
 */
function tbt_fc_deck_template_include( $template ) {
    if ( ! is_singular( 'tbt_flashcard_set' ) ) {
        return $template;
    }

    $theme_template = locate_template( 'tbt-flashcards/single-deck.php' );
    if ( $theme_template ) {
        return $theme_template;
    }

    return TBT_FC_DIR . 'templates/single-deck.php';
}
add_filter( 'template_include', 'tbt_fc_deck_template_include', 99 );

/**
 * The deck page is never cached: edits must show at once, and a cached copy
 * would also freeze the TTS nonce.
 */
function tbt_fc_deck_nocache() {
    if ( is_singular( 'tbt_flashcard_set' ) ) {
        nocache_headers();
        header( 'X-LiteSpeed-Cache-Control: no-cache' );
    }
}
add_action( 'template_redirect', 'tbt_fc_deck_nocache' );

/**
 * Enqueue the player before wp_head on the deck page, so its styles print in
 * the head rather than the footer.
 */
function tbt_fc_deck_enqueue_early() {
    if ( is_singular( 'tbt_flashcard_set' ) ) {
        tbt_flashcards_enqueue_assets();
    }
}
add_action( 'wp_enqueue_scripts', 'tbt_fc_deck_enqueue_early' );
