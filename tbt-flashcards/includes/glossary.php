<?php
/**
 * [tbt_flashcards_glossary ids="361,362" urls="https://…/a.csv" domain="gi"]
 *
 * A lesson's glossary, read live from its flashcard sets and from CSV files
 * in this site's uploads (sets first, then files): expression and
 * Polish translation. The server builds the rows and embeds them as JSON on
 * the root; assets/js/glossary.js renders search, sort and paging from that
 * data. Read-only, no REST call.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register the glossary shortcode.
 */
function tbt_fc_register_glossary_shortcode() {
    add_shortcode( 'tbt_flashcards_glossary', 'tbt_fc_render_glossary' );
}
add_action( 'init', 'tbt_fc_register_glossary_shortcode' );

/**
 * Glossary rows from the cards of several sets, in lesson order.
 *
 * Pure (no WordPress). Sets in the given order, cards in set order. A word
 * seen before (trimmed, case-insensitive) is dropped, so it stays at its
 * first place. Cards without a word are skipped; a missing translation is
 * kept as ''. Anything that is not a list of cards (e.g. a WP_Error) counts
 * as an empty set.
 *
 * @param array $sets_of_cards List of card lists.
 * @return array[] List of array( word, translation ).
 */
function tbt_fc_glossary_rows( array $sets_of_cards ) {
    $rows = array();
    $seen = array();

    foreach ( $sets_of_cards as $cards ) {
        if ( ! is_array( $cards ) ) {
            continue;
        }
        foreach ( $cards as $card ) {
            if ( ! is_array( $card ) || ! isset( $card['word'] ) || ! is_scalar( $card['word'] ) ) {
                continue;
            }
            $word = trim( (string) $card['word'] );
            if ( '' === $word ) {
                continue;
            }
            $key = mb_strtolower( $word, 'UTF-8' );
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;

            $translation = isset( $card['translation'] ) && is_scalar( $card['translation'] ) ? trim( (string) $card['translation'] ) : '';
            $rows[]      = array( $word, $translation );
        }
    }

    return $rows;
}

/**
 * Set IDs from the ids attribute, in order, without duplicates.
 *
 * Only digit runs are read, so curly quotes or entities that Divi leaves
 * around a value do no harm.
 *
 * @param string $value Attribute value.
 * @return int[]
 */
function tbt_fc_glossary_parse_ids( $value ) {
    preg_match_all( '/\d+/', (string) $value, $matches );
    $ids = array();
    foreach ( $matches[0] as $id ) {
        $id = (int) $id;
        if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
            $ids[] = $id;
        }
    }
    return $ids;
}

/**
 * CSV URLs from the urls attribute, in order, without empties or duplicates.
 *
 * Straight or curly quotes (or their entities) that Divi leaves around the
 * value are trimmed first, as tbt_fc_glossary_parse_ids() ignores them.
 *
 * @param string $value Attribute value.
 * @return string[]
 */
function tbt_fc_glossary_parse_urls( $value ) {
    $urls = array();
    foreach ( explode( ',', (string) $value ) as $url ) {
        $url = preg_replace( '/^(?:\s|["\'\x{201C}\x{201D}\x{2033}]|&#82(?:20|21|43);|&quot;)+|(?:\s|["\'\x{201C}\x{201D}\x{2033}]|&#82(?:20|21|43);|&quot;)+$/u', '', $url );
        $url = esc_url_raw( trim( (string) $url ) );
        if ( '' !== $url && ! in_array( $url, $urls, true ) ) {
            $urls[] = $url;
        }
    }
    return $urls;
}

/**
 * Card lists from CSV files, read from disk only (never over HTTP) and
 * parsed like the url flashcards. A URL outside uploads, or a file that
 * cannot be read, is skipped.
 *
 * @param string[] $urls CSV URLs.
 * @return array[] List of card lists.
 */
function tbt_fc_glossary_url_sets( array $urls ) {
    $sets = array();
    foreach ( $urls as $url ) {
        $text = tbt_fc_read_csv_text( $url, true );
        if ( is_wp_error( $text ) ) {
            continue;
        }
        $sets[] = tbt_fc_parse_csv( (string) $text );
    }
    return $sets;
}

/**
 * Enqueue the glossary's CSS and JS once per request.
 */
function tbt_fc_enqueue_glossary_assets() {
    static $done = false;
    if ( $done ) {
        return;
    }
    $done = true;

    tbt_fc_register_fonts();
    tbt_fc_ensure_shared_styles();

    wp_enqueue_style( 'tbt-fc-glossary', TBT_FC_URL . 'assets/css/glossary.css', array( 'tbt-flashcards-google-fonts', 'tbt-tokens' ), TBT_FC_VERSION );
    wp_enqueue_script( 'tbt-fc-glossary', TBT_FC_URL . 'assets/js/glossary.js', array(), TBT_FC_VERSION, true );
}

/**
 * Enqueue before wp_head when the queried post contains a glossary, so the
 * stylesheet loads in the head rather than the footer.
 */
function tbt_fc_maybe_enqueue_glossary_early() {
    if ( ! is_singular() ) {
        return;
    }
    $post = get_queried_object();
    if ( $post instanceof WP_Post && has_shortcode( $post->post_content, 'tbt_flashcards_glossary' ) ) {
        tbt_fc_enqueue_glossary_assets();
    }
}
add_action( 'wp_enqueue_scripts', 'tbt_fc_maybe_enqueue_glossary_early' );

/**
 * Render the glossary shortcode.
 *
 * @param array $atts Shortcode attributes.
 * @return string HTML.
 */
function tbt_fc_render_glossary( $atts ) {
    $atts = shortcode_atts( array(
        'ids'    => '',
        'urls'   => '',
        'domain' => '',
    ), $atts, 'tbt_flashcards_glossary' );

    $sets = array();
    foreach ( tbt_fc_glossary_parse_ids( $atts['ids'] ) as $id ) {
        $post = get_post( $id );
        if ( ! $post || 'tbt_flashcard_set' !== $post->post_type || 'publish' !== $post->post_status ) {
            continue;
        }
        $sets[] = tbt_fc_get_cards( $id );
    }
    $sets = array_merge( $sets, tbt_fc_glossary_url_sets( tbt_fc_glossary_parse_urls( $atts['urls'] ) ) );

    $rows = tbt_fc_glossary_rows( $sets );
    if ( empty( $rows ) ) {
        if ( current_user_can( 'manage_options' ) ) {
            return '<p class="tbt-fc-gl-notice">' . esc_html__( 'Glossary: no flashcards found for these sets.', 'tbt-flashcards' ) . '</p>';
        }
        return '';
    }

    $domain = strtolower( preg_replace( '/[^a-z]/i', '', (string) $atts['domain'] ) );
    if ( ! in_array( $domain, array( 'gi', 'pd', 'le' ), true ) ) {
        $domain = '';
    }

    tbt_fc_enqueue_glossary_assets();

    $uid  = wp_unique_id( 'tbt-fc-gl-' );
    $i18n = array(
        'count'      => __( '%d expressions', 'tbt-flashcards' ),
        'countOf'    => __( '%1$d of %2$d expressions', 'tbt-flashcards' ),
        'range'      => __( '%1$d–%2$d of %3$d', 'tbt-flashcards' ),
        'next'       => __( 'Next %d', 'tbt-flashcards' ),
        'showAll'    => __( 'Show all %d', 'tbt-flashcards' ),
        'showPaged'  => __( 'Show 10 at a time', 'tbt-flashcards' ),
        'noMatch'    => __( 'No expressions match “%s”.', 'tbt-flashcards' ),
        'clear'      => __( 'Clear search', 'tbt-flashcards' ),
    );

    $search_icon = '<svg class="tbt-fc-gl-search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>';

    ob_start();
    ?>
<div class="tbt-fc-gl"<?php echo $domain ? ' data-domain="' . esc_attr( $domain ) . '"' : ''; ?> data-rows="<?php echo esc_attr( wp_json_encode( $rows ) ); ?>" data-i18n="<?php echo esc_attr( wp_json_encode( $i18n ) ); ?>">
    <div class="tbt-fc-gl-bar">
        <div class="tbt-fc-gl-search">
            <?php echo $search_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup ?>
            <label class="tbt-fc-gl-vh" for="<?php echo esc_attr( $uid ); ?>-q"><?php esc_html_e( 'Search the glossary', 'tbt-flashcards' ); ?></label>
            <input class="tbt-fc-gl-input" id="<?php echo esc_attr( $uid ); ?>-q" type="search" placeholder="<?php esc_attr_e( 'Search in English or Polish', 'tbt-flashcards' ); ?>" autocomplete="off">
            <button type="button" class="tbt-fc-gl-clear" aria-label="<?php esc_attr_e( 'Clear search', 'tbt-flashcards' ); ?>" hidden>×</button>
        </div>
        <span class="tbt-fc-gl-count" aria-live="polite"></span>
    </div>
    <div class="tbt-fc-gl-tablebox">
        <table class="tbt-fc-gl-table">
            <thead><tr>
                <th scope="col" data-col="0" aria-sort="none"><button type="button" class="tbt-fc-gl-sort"><?php esc_html_e( 'Expression', 'tbt-flashcards' ); ?> <span class="tbt-fc-gl-arrow" aria-hidden="true">↕</span></button></th>
                <th scope="col" data-col="1" aria-sort="none"><button type="button" class="tbt-fc-gl-sort"><?php esc_html_e( 'Polish translation', 'tbt-flashcards' ); ?> <span class="tbt-fc-gl-arrow" aria-hidden="true">↕</span></button></th>
            </tr></thead>
            <tbody class="tbt-fc-gl-rows"></tbody>
        </table>
    </div>
    <div class="tbt-fc-gl-pager" hidden>
        <div class="tbt-fc-gl-pages">
            <button type="button" class="tbt-fc-gl-pbtn tbt-fc-gl-prev"><?php esc_html_e( 'Previous', 'tbt-flashcards' ); ?></button>
            <span class="tbt-fc-gl-range"></span>
            <button type="button" class="tbt-fc-gl-pbtn tbt-fc-gl-next"></button>
        </div>
        <button type="button" class="tbt-fc-gl-linkbtn tbt-fc-gl-all"></button>
    </div>
</div>
    <?php
    return (string) ob_get_clean();
}
