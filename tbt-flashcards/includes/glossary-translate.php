<?php
/**
 * Paste words: Polish translations by AI (2.4.2).
 *
 * Turns pasted cards (term, English definition, example) into one Polish
 * translation per term, with Flashcards' own OpenAI key and model and the
 * request tbt_fc_generate() uses (tbt_fc_openai_json_request()). Nothing
 * here stores anything: the screen puts "term<TAB>translation" lines in the
 * paste box, and Save stores them as before.
 *
 * The prompt builder and the alignment are pure (tested by
 * tests/test-glossary-import.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TBT_FC_TRANSLATE_BATCH', min( 40, TBT_FC_MAX_GENERATE_ITEMS ) );
define( 'TBT_FC_TRANSLATE_MAX', 300 );

/**
 * Developer prompt: the card generator's translation style, translation only.
 *
 * @return string
 */
function tbt_fc_translate_developer_prompt() {
    return implode(
        "\n",
        array(
            'You translate English vocabulary into Polish for a lesson glossary for Polish adult learners of English (B2–C1) at The Blue Tree.',
            'For every numbered item you receive, return one translation with the same index, in the same order.',
            '',
            'Each item is an English term, sometimes followed by " — " and its English definition or an example sentence. Translate only the term; the definition or example tells you which sense is meant.',
            '',
            'Rules:',
            '- translation: natural Polish. Translate the term as a whole, not word by word. Give one or two equivalents separated by ", " when that helps, e.g. "pilnie, starannie".',
            '- When a definition is given, the translation must match that sense, e.g. "content" defined as "feeling satisfied and happy with what you have" is "zadowolony", not "treść".',
            '- Keep the form of the term: a verb phrase becomes a Polish verb phrase, and "something" / "someone" become "coś" / "kogoś" in the right case, e.g. "bask in something" is "rozkoszować się czymś".',
            '- Plain text only: no markdown, no line breaks, no quotation marks, no explanations, never the English term itself.',
        )
    );
}

/**
 * User prompt: the lesson title as context and the numbered items, each as
 * "term — definition" (the example only when there is no definition).
 *
 * @param array[] $cards Cards { term, definition, example }.
 * @param string  $title Lesson title.
 * @return string
 */
function tbt_fc_translate_user_prompt( array $cards, $title ) {
    $one = function ( $value, $max ) {
        $value = trim( (string) preg_replace( '/\s+/u', ' ', (string) $value ) );
        return mb_substr( $value, 0, $max, 'UTF-8' );
    };

    $title   = $one( $title, 200 );
    $lines   = array();
    $lines[] = '' !== $title ? 'Lesson: ' . $title : 'Lesson: no title given.';
    $lines[] = '';
    $lines[] = 'Items:';
    foreach ( array_values( $cards ) as $i => $card ) {
        $term  = $one( isset( $card['term'] ) ? $card['term'] : '', 120 );
        $extra = $one( isset( $card['definition'] ) ? $card['definition'] : '', 400 );
        if ( '' === $extra ) {
            $extra = $one( isset( $card['example'] ) ? $card['example'] : '', 400 );
        }
        $lines[] = ( $i + 1 ) . '. ' . $term . ( '' !== $extra ? ' — ' . $extra : '' );
    }
    return implode( "\n", $lines );
}

/**
 * Strict JSON schema: {translations: [{index, translation}]}.
 *
 * @param int $count Number of items.
 * @return array
 */
function tbt_fc_translate_schema( $count ) {
    return array(
        'type'                 => 'object',
        'additionalProperties' => false,
        'required'             => array( 'translations' ),
        'properties'           => array(
            'translations' => array(
                'type'     => 'array',
                'maxItems' => $count,
                'items'    => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => array( 'index', 'translation' ),
                    'properties'           => array(
                        'index'       => array( 'type' => 'integer' ),
                        'translation' => array( 'type' => 'string' ),
                    ),
                ),
            ),
        ),
    );
}

/**
 * Line the model's translations up with the input: one string per item, in
 * input order, matched by index (1-based, the first one wins). An item the
 * model dropped comes back as ''. Plain text on one line, at most 190
 * characters (the glossary's translation limit).
 *
 * @param int   $count    Number of input items.
 * @param array $returned The model's translations.
 * @return string[]
 */
function tbt_fc_align_translations( $count, array $returned ) {
    $by_index = array();
    foreach ( $returned as $item ) {
        if ( ! is_array( $item ) || ! isset( $item['index'] ) || ! is_numeric( $item['index'] ) || ! isset( $item['translation'] ) || ! is_scalar( $item['translation'] ) ) {
            continue;
        }
        $index = (int) $item['index'];
        if ( ! isset( $by_index[ $index ] ) ) {
            $by_index[ $index ] = (string) $item['translation'];
        }
    }

    $out = array();
    for ( $i = 1; $i <= $count; $i++ ) {
        $value = isset( $by_index[ $i ] ) ? $by_index[ $i ] : '';
        $value = strip_tags( $value );
        $value = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
        $value = trim( (string) preg_replace( '/^["\'„”“]+|["\'„”“]+$/u', '', $value ) );
        $out[] = trim( mb_substr( $value, 0, 190, 'UTF-8' ) );
    }
    return $out;
}

/**
 * Polish translations for a batch of cards (at most TBT_FC_TRANSLATE_BATCH).
 *
 * @param array[] $cards        Cards { term, definition, example }.
 * @param string  $lesson_title Lesson title, as context.
 * @return string[]|WP_Error One translation per card, in input order.
 */
function tbt_fc_translate_terms( array $cards, $lesson_title ) {
    $cards = array_values( $cards );
    if ( ! $cards ) {
        return new WP_Error( 'tbt_fc_no_words', 'No words found.', array( 'status' => 400 ) );
    }
    if ( count( $cards ) > TBT_FC_TRANSLATE_BATCH ) {
        return new WP_Error( 'tbt_fc_too_many_items', sprintf( 'Translate at most %d words per request.', TBT_FC_TRANSLATE_BATCH ), array( 'status' => 400 ) );
    }

    $text = tbt_fc_openai_json_request(
        tbt_fc_translate_developer_prompt(),
        tbt_fc_translate_user_prompt( $cards, $lesson_title ),
        'tbt_glossary_translations',
        tbt_fc_translate_schema( count( $cards ) )
    );
    if ( is_wp_error( $text ) ) {
        return $text;
    }

    $data = json_decode( $text, true );
    if ( ! is_array( $data ) || ! isset( $data['translations'] ) || ! is_array( $data['translations'] ) ) {
        return new WP_Error( 'tbt_fc_openai_translations_json', 'The translations were not readable. Try again.', array( 'status' => 502 ) );
    }

    return tbt_fc_align_translations( count( $cards ), $data['translations'] );
}

/* ---------- REST ---------- */

/**
 * Register POST glossary-import/translate.
 */
function tbt_fc_translate_register_route() {
    register_rest_route( TBT_FC_REST_NAMESPACE, '/glossary-import/translate', array(
        'methods'             => 'POST',
        'callback'            => 'tbt_fc_translate_rest',
        'permission_callback' => function () {
            return current_user_can( 'manage_options' )
                ? true
                : new WP_Error( 'tbt_fc_forbidden', 'Only administrators can import glossaries.', array( 'status' => rest_authorization_required_code() ) );
        },
    ) );
}
add_action( 'rest_api_init', 'tbt_fc_translate_register_route' );

/**
 * POST glossary-import/translate {post_id, text, from}: translate the cards
 * from `from` (40 at most) and return their "term<TAB>translation" lines.
 * The browser repeats the call until `next` is null. Never stores anything.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function tbt_fc_translate_rest( WP_REST_Request $request ) {
    $post_id = absint( $request->get_param( 'post_id' ) );
    $text    = $request->get_param( 'text' );
    $text    = is_string( $text ) ? $text : '';
    $from    = absint( $request->get_param( 'from' ) );

    if ( count( tbt_fc_paste_lines( $text ) ) > TBT_FC_WDT_MAX_ROWS ) {
        return new WP_Error( 'tbt_fc_too_long', sprintf( 'Paste at most %d lines at a time.', TBT_FC_WDT_MAX_ROWS ), array( 'status' => 400 ) );
    }
    $cards = tbt_fc_paste_translatable( tbt_fc_parse_pasted( $text ) );
    $total = count( $cards );
    if ( ! $total ) {
        return new WP_Error( 'tbt_fc_no_words', 'No words found.', array( 'status' => 400 ) );
    }
    if ( $total > TBT_FC_TRANSLATE_MAX ) {
        return new WP_Error( 'tbt_fc_too_many_items', sprintf( 'Translate at most %d words at a time. Split the list and translate it in parts.', TBT_FC_TRANSLATE_MAX ), array( 'status' => 400 ) );
    }
    if ( $from >= $total ) {
        return new WP_Error( 'tbt_fc_bad_request', 'Nothing left to translate.', array( 'status' => 400 ) );
    }

    $post  = $post_id ? get_post( $post_id ) : null;
    $title = $post ? html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) : '';
    $chunk = array_slice( $cards, $from, TBT_FC_TRANSLATE_BATCH );

    $translations = tbt_fc_translate_terms( $chunk, $title );
    if ( is_wp_error( $translations ) ) {
        return $translations;
    }

    $lines = array();
    foreach ( $chunk as $i => $card ) {
        $lines[] = $card['term'] . "\t" . $translations[ $i ];
    }
    $next = $from + count( $chunk );

    return rest_ensure_response( array(
        'text'    => implode( "\n", $lines ),
        'count'   => count( $chunk ),
        'missing' => count( array_filter( $translations, function ( $t ) {
            return '' === $t;
        } ) ),
        'total'   => $total,
        'next'    => $next < $total ? $next : null,
    ) );
}
