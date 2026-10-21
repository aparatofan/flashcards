<?php
/**
 * Card model: sanitising, the source rule, CSV in and CSV out.
 *
 * Source rule: a set whose `_tbt_fc_cards` meta decodes to a non-empty array
 * is an editor set. Anything else is a legacy set whose cards come from its
 * CSV (`_tbt_csv_url`, else `_tbt_csv_file`), exactly as in 1.0.0. Saving a
 * legacy set in the editor writes `_tbt_fc_cards` and never touches the CSV
 * meta, so rolling back to 1.0.0 still plays the original CSV.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TBT_FC_MAX_CARDS', 300 );

// The TTS proxy rejects text over 200 bytes (strlen), so the word is capped
// in bytes, cut between characters, and always passes the proxy.
define( 'TBT_FC_MAX_WORD_BYTES', 200 );
define( 'TBT_FC_MAX_FIELD_CHARS', 500 );

/**
 * Keep only valid cards: the four keys, sanitised and capped.
 *
 * @param mixed $raw Decoded JSON or request data.
 * @return array[] List of { word, phonetic, translation, example }.
 */
function tbt_fc_sanitize_cards( $raw ) {
    if ( ! is_array( $raw ) ) {
        return array();
    }

    $cards = array();
    foreach ( $raw as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }

        $word = isset( $row['word'] ) && is_scalar( $row['word'] ) ? sanitize_text_field( (string) $row['word'] ) : '';
        $word = trim( mb_strcut( $word, 0, TBT_FC_MAX_WORD_BYTES, 'UTF-8' ) );
        if ( '' === $word ) {
            continue;
        }

        $card = array( 'word' => $word );
        foreach ( array( 'phonetic', 'translation', 'example' ) as $key ) {
            $value        = isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ? sanitize_text_field( (string) $row[ $key ] ) : '';
            $card[ $key ] = trim( mb_substr( $value, 0, TBT_FC_MAX_FIELD_CHARS, 'UTF-8' ) );
        }

        $cards[] = $card;
        if ( count( $cards ) >= TBT_FC_MAX_CARDS ) {
            break;
        }
    }

    return $cards;
}

/**
 * Cards stored by the front-end editor, or an empty array.
 *
 * @param int $post_id Flashcard set ID.
 * @return array[]
 */
function tbt_fc_get_editor_cards( $post_id ) {
    $json = get_post_meta( $post_id, '_tbt_fc_cards', true );
    if ( ! is_string( $json ) || '' === $json ) {
        return array();
    }

    $decoded = json_decode( $json, true );
    return is_array( $decoded ) && ! empty( $decoded ) ? $decoded : array();
}

/**
 * Is this an editor set (cards in `_tbt_fc_cards`) rather than a legacy CSV set?
 *
 * @param int $post_id Flashcard set ID.
 * @return bool
 */
function tbt_fc_is_editor_set( $post_id ) {
    return ! empty( tbt_fc_get_editor_cards( $post_id ) );
}

/**
 * The CSV URL a legacy set reads from, or '' when it has none.
 *
 * @param int $post_id Flashcard set ID.
 * @return string
 */
function tbt_fc_get_legacy_csv_url( $post_id ) {
    $csv_url = get_post_meta( $post_id, '_tbt_csv_url', true );
    if ( ! empty( $csv_url ) ) {
        return (string) $csv_url;
    }

    $csv_file = get_post_meta( $post_id, '_tbt_csv_file', true );
    if ( ! empty( $csv_file ) ) {
        $url = tbt_flashcards_get_csv_url( $csv_file );
        return $url ? (string) $url : '';
    }

    return '';
}

/**
 * Cards for a set, applying the source rule. Memoised per request.
 *
 * @param int  $post_id Flashcard set ID.
 * @param bool $fresh   Skip (and refresh) the memo, e.g. right after a save.
 * @return array[]|WP_Error
 */
function tbt_fc_get_cards( $post_id, $fresh = false ) {
    static $memo = array();

    $post_id = (int) $post_id;
    if ( ! $fresh && isset( $memo[ $post_id ] ) ) {
        return $memo[ $post_id ];
    }

    $editor_cards = tbt_fc_get_editor_cards( $post_id );
    if ( ! empty( $editor_cards ) ) {
        $memo[ $post_id ] = tbt_fc_sanitize_cards( $editor_cards );
        return $memo[ $post_id ];
    }

    $url = tbt_fc_get_legacy_csv_url( $post_id );
    if ( '' === $url ) {
        $memo[ $post_id ] = new WP_Error( 'tbt_fc_no_csv', __( 'This set has no CSV file.', 'tbt-flashcards' ) );
        return $memo[ $post_id ];
    }

    $text = tbt_fc_read_csv_text( $url );
    $memo[ $post_id ] = is_wp_error( $text ) ? $text : tbt_fc_parse_csv( $text );
    return $memo[ $post_id ];
}

/**
 * Number of cards in a set, or 0 when its source cannot be read.
 *
 * @param int $post_id Flashcard set ID.
 * @return int
 */
function tbt_fc_card_count( $post_id ) {
    $cards = tbt_fc_get_cards( $post_id );
    return is_wp_error( $cards ) ? 0 : count( $cards );
}

/**
 * Read a legacy CSV: from disk when it lives in uploads, otherwise over HTTP.
 *
 * @param string $url CSV URL.
 * @return string|WP_Error
 */
function tbt_fc_read_csv_text( $url ) {
    $uploads = wp_upload_dir( null, false );

    if ( empty( $uploads['error'] ) && ! empty( $uploads['baseurl'] ) ) {
        // Compare without the scheme: the stored URL may be http while the
        // site now answers on https, or the other way round.
        $base     = preg_replace( '#^https?:#i', '', untrailingslashit( $uploads['baseurl'] ) ) . '/';
        $relative = preg_replace( '#^https?:#i', '', strtok( $url, '?#' ) );

        if ( 0 === strpos( $relative, $base ) ) {
            $basedir = realpath( $uploads['basedir'] );
            $path    = realpath( $uploads['basedir'] . '/' . rawurldecode( substr( $relative, strlen( $base ) ) ) );

            if ( $basedir && $path && 0 === strpos( $path, $basedir . DIRECTORY_SEPARATOR ) && is_readable( $path ) ) {
                $text = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                if ( false !== $text ) {
                    return $text;
                }
            }
        }
    }

    $response = wp_remote_get( $url, array( 'timeout' => 10 ) );
    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'tbt_fc_csv_unreachable', __( 'The CSV file could not be loaded.', 'tbt-flashcards' ) );
    }
    if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
        return new WP_Error( 'tbt_fc_csv_unreachable', __( 'The CSV file could not be loaded.', 'tbt-flashcards' ) );
    }

    return (string) wp_remote_retrieve_body( $response );
}

/**
 * Trim the way JavaScript's String.prototype.trim does (it also strips the
 * BOM and Unicode spaces, which PHP's trim() does not).
 *
 * @param string $value Value.
 * @return string
 */
function tbt_fc_js_trim( $value ) {
    $ws      = '[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';
    $trimmed = preg_replace( '/^' . $ws . '+|' . $ws . '+$/u', '', (string) $value );
    return null === $trimmed ? trim( (string) $value ) : $trimmed;
}

/**
 * Parse CSV text into cards.
 *
 * Mirrors parseCSV() in assets/tbt-flashcards.js so a legacy set has the same
 * cards on the server as in the browser: the same delimiter detection (tab,
 * then semicolon, then comma, from the header line), the same header aliases,
 * the same trimming, and rows without a word are skipped.
 *
 * @param string $text CSV text.
 * @return array[]
 */
function tbt_fc_parse_csv( $text ) {
    $lines = explode( "\n", tbt_fc_js_trim( $text ) );
    if ( count( $lines ) < 2 ) {
        return array();
    }

    $header    = rtrim( $lines[0], "\r" );
    $delimiter = false !== strpos( $header, "\t" ) ? "\t" : ( false !== strpos( $header, ';' ) ? ';' : ',' );
    $headers   = array_map(
        function ( $h ) {
            return str_replace( array( '"', "'" ), '', strtolower( tbt_fc_js_trim( $h ) ) );
        },
        explode( $delimiter, $header )
    );

    $find = function ( $aliases ) use ( $headers ) {
        foreach ( $aliases as $alias ) {
            $idx = array_search( $alias, $headers, true );
            if ( false !== $idx ) {
                return $idx;
            }
        }
        return -1;
    };

    $word_idx        = $find( array( 'word', 'english', 'en', 'front' ) );
    $translation_idx = $find( array( 'translation', 'polish', 'pl', 'back' ) );
    $phonetic_idx    = $find( array( 'phonetic', 'pronunciation', 'ipa' ) );
    $example_idx     = $find( array( 'example', 'sentence', 'context' ) );

    if ( -1 === $word_idx || -1 === $translation_idx ) {
        return array();
    }

    $cards = array();
    $count = count( $lines );
    for ( $i = 1; $i < $count; $i++ ) {
        $cols = array_map(
            function ( $s ) {
                return tbt_fc_js_trim( preg_replace( '/^"|"$/', '', (string) $s ) );
            },
            str_getcsv( rtrim( $lines[ $i ], "\r" ), $delimiter, '"', '' )
        );

        if ( count( $cols ) <= max( $word_idx, $translation_idx ) ) {
            continue;
        }

        $word = isset( $cols[ $word_idx ] ) ? $cols[ $word_idx ] : '';
        if ( '' === $word ) {
            continue;
        }

        $cards[] = array(
            'word'        => $word,
            'phonetic'    => $phonetic_idx >= 0 && isset( $cols[ $phonetic_idx ] ) ? $cols[ $phonetic_idx ] : '',
            'translation' => isset( $cols[ $translation_idx ] ) ? $cols[ $translation_idx ] : '',
            'example'     => $example_idx >= 0 && isset( $cols[ $example_idx ] ) ? $cols[ $example_idx ] : '',
        );
    }

    return $cards;
}

/**
 * One CSV field: quoted only when it holds a comma, quote or line break.
 *
 * Never use fputcsv(): it also quotes fields with spaces, which breaks the
 * format of Mariusz's existing files.
 *
 * @param mixed $v Value.
 * @return string
 */
function tbt_fc_csv_field( $v ) {
    $v = (string) $v;
    return preg_match( '/[",\r\n]/', $v ) ? '"' . str_replace( '"', '""', $v ) . '"' : $v;
}

/**
 * Serialise cards as CSV: UTF-8 without BOM, header
 * `word,phonetic,translation,example`, LF endings and a final newline.
 *
 * @param array $cards Cards.
 * @return string
 */
function tbt_fc_to_csv( array $cards ) {
    $out = "word,phonetic,translation,example\n";
    foreach ( $cards as $card ) {
        $row = array();
        foreach ( array( 'word', 'phonetic', 'translation', 'example' ) as $key ) {
            $row[] = tbt_fc_csv_field( isset( $card[ $key ] ) ? $card[ $key ] : '' );
        }
        $out .= implode( ',', $row ) . "\n";
    }
    return $out;
}
