<?php
/**
 * OpenAI generation: phonetics, Polish translation and an example per item.
 *
 * Request shape, JSON-only output and error mapping mirror TBT Matching
 * Games' client. The API key is resolved on the server and never reaches the
 * browser.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TBT_FC_MAX_GENERATE_ITEMS', 40 );

/**
 * The OpenAI API key, or '' when none is configured.
 *
 * Same order as Matching Games after our own constant, so both tools always
 * use the same key.
 *
 * @return string
 */
function tbt_fc_openai_api_key() {
    $key = '';
    if ( defined( 'TBT_FLASHCARDS_OPENAI_API_KEY' ) && TBT_FLASHCARDS_OPENAI_API_KEY ) {
        $key = TBT_FLASHCARDS_OPENAI_API_KEY;
    } elseif ( defined( 'TBT_MATCHING_GAMES_OPENAI_API_KEY' ) && TBT_MATCHING_GAMES_OPENAI_API_KEY ) {
        $key = TBT_MATCHING_GAMES_OPENAI_API_KEY;
    } elseif ( defined( 'OPENAI_API_KEY' ) && OPENAI_API_KEY ) {
        $key = OPENAI_API_KEY;
    }

    return trim( (string) apply_filters( 'tbt_fc_openai_api_key', $key ) );
}

/**
 * The model used for generation.
 *
 * @return string
 */
function tbt_fc_openai_model() {
    $model = defined( 'TBT_FLASHCARDS_OPENAI_MODEL' ) && TBT_FLASHCARDS_OPENAI_MODEL ? TBT_FLASHCARDS_OPENAI_MODEL : 'gpt-5-mini';
    return (string) apply_filters( 'tbt_fc_openai_model', $model );
}

/**
 * Developer prompt. The rules come from Mariusz's Yalom file.
 *
 * @return string
 */
function tbt_fc_openai_developer_prompt() {
    return implode(
        "\n",
        array(
            'You write vocabulary flashcards for Polish adult learners of English (B2–C1) at The Blue Tree.',
            'For every numbered English item you receive, return one card with the same index, in the same order.',
            '',
            'Rules:',
            '- word: copy the item exactly as given. Never change, correct or re-capitalise it.',
            '- phonetic: British English IPA with stress marks, wrapped in slashes, covering the whole item, e.g. /ət ə prəˈfaʊnd ˈlevəl/.',
            '- translation: natural Polish. Translate the item as a whole, not word by word. Give one or two equivalents separated by ", " when that helps, e.g. "pilnie, starannie". When the item has a specialised sense in the given context, add it in parentheses, e.g. "przeniesienie (w psychoterapii)".',
            '- example: one natural English sentence that uses the item, fits the context if one is given, has at most about 20 words, is at B2–C1 level and ends with a full stop.',
            '- Plain text only: no markdown, no line breaks, no quotation marks around the fields.',
        )
    );
}

/**
 * User prompt: the context and the numbered items.
 *
 * @param string[] $items   Items.
 * @param string   $context Optional context.
 * @return string
 */
function tbt_fc_openai_user_prompt( array $items, $context ) {
    $lines   = array();
    $lines[] = '' !== $context ? 'Context: ' . $context : 'Context: none given (use the most common meaning).';
    $lines[] = '';
    $lines[] = 'Items:';
    foreach ( array_values( $items ) as $i => $item ) {
        $lines[] = ( $i + 1 ) . '. ' . $item;
    }
    return implode( "\n", $lines );
}

/**
 * Strict JSON schema for the response.
 *
 * @param int $count Number of items.
 * @return array
 */
function tbt_fc_openai_schema( $count ) {
    $string = array( 'type' => 'string' );

    return array(
        'type'                 => 'object',
        'additionalProperties' => false,
        'required'             => array( 'cards' ),
        'properties'           => array(
            'cards' => array(
                'type'     => 'array',
                'maxItems' => $count,
                'items'    => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => array( 'index', 'word', 'phonetic', 'translation', 'example' ),
                    'properties'           => array(
                        'index'       => array( 'type' => 'integer' ),
                        'word'        => $string,
                        'phonetic'    => $string,
                        'translation' => $string,
                        'example'     => $string,
                    ),
                ),
            ),
        ),
    );
}

/**
 * Generate cards for a batch of items.
 *
 * Returns one card per item in input order, with `word` exactly as typed. An
 * item the model drops comes back with empty fields instead of failing the
 * whole batch.
 *
 * @param string[] $items   Items, already sanitised.
 * @param string   $context Optional context.
 * @return array[]|WP_Error
 */
function tbt_fc_generate( array $items, $context = '' ) {
    $items = array_values( $items );

    if ( empty( $items ) ) {
        return new WP_Error( 'tbt_fc_no_items', __( 'Type at least one English item, one per line.', 'tbt-flashcards' ), array( 'status' => 400 ) );
    }
    if ( count( $items ) > TBT_FC_MAX_GENERATE_ITEMS ) {
        return new WP_Error(
            'tbt_fc_too_many_items',
            /* translators: %d = maximum number of items per generation */
            sprintf( __( 'You can generate up to %d items at a time. Split the list and generate it in parts.', 'tbt-flashcards' ), TBT_FC_MAX_GENERATE_ITEMS ),
            array( 'status' => 400 )
        );
    }

    $api_key = tbt_fc_openai_api_key();
    if ( '' === $api_key ) {
        return new WP_Error( 'tbt_fc_missing_api_key', __( 'The OpenAI API key could not be found. Ask the site administrator to add it.', 'tbt-flashcards' ), array( 'status' => 500 ) );
    }

    $endpoint = (string) apply_filters( 'tbt_fc_openai_endpoint', 'https://api.openai.com/v1/responses' );
    $timeout  = absint( apply_filters( 'tbt_fc_openai_timeout', 90 ) );

    $body = array(
        'model'             => tbt_fc_openai_model(),
        'store'             => false,
        'max_output_tokens' => 16000,
        'input'             => array(
            array(
                'role'    => 'developer',
                'content' => array(
                    array(
                        'type' => 'input_text',
                        'text' => tbt_fc_openai_developer_prompt(),
                    ),
                ),
            ),
            array(
                'role'    => 'user',
                'content' => array(
                    array(
                        'type' => 'input_text',
                        'text' => tbt_fc_openai_user_prompt( $items, $context ),
                    ),
                ),
            ),
        ),
        'text'              => array(
            'format' => array(
                'type'   => 'json_schema',
                'name'   => 'tbt_flashcards',
                'strict' => true,
                'schema' => tbt_fc_openai_schema( count( $items ) ),
            ),
        ),
    );

    $response = wp_remote_post(
        $endpoint,
        array(
            'timeout' => max( 15, $timeout ),
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
        )
    );

    if ( is_wp_error( $response ) ) {
        return new WP_Error(
            'tbt_fc_openai_connection',
            __( 'The server could not reach OpenAI. Try again in a moment.', 'tbt-flashcards' ),
            array( 'status' => 502 )
        );
    }

    $status_code = (int) wp_remote_retrieve_response_code( $response );
    $decoded     = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $status_code < 200 || $status_code >= 300 ) {
        return tbt_fc_openai_api_error( $status_code, is_array( $decoded ) ? $decoded : array() );
    }

    if ( ! is_array( $decoded ) ) {
        return new WP_Error( 'tbt_fc_openai_invalid_json', __( 'OpenAI returned an unreadable response. Try again.', 'tbt-flashcards' ), array( 'status' => 502 ) );
    }

    $text = tbt_fc_openai_output_text( $decoded );
    if ( is_wp_error( $text ) ) {
        return $text;
    }

    $data = json_decode( $text, true );
    if ( ! is_array( $data ) || ! isset( $data['cards'] ) || ! is_array( $data['cards'] ) ) {
        return new WP_Error( 'tbt_fc_openai_cards_json', __( 'The generated cards were not readable. Try again.', 'tbt-flashcards' ), array( 'status' => 502 ) );
    }

    return tbt_fc_align_generated( $items, $data['cards'] );
}

/**
 * Line the model's cards up with the input items.
 *
 * Matches by index first, then by the echoed word; whatever is still missing
 * comes back empty. The word is always the item as typed.
 *
 * @param string[] $items     Input items.
 * @param array    $generated Model cards.
 * @return array[]
 */
function tbt_fc_align_generated( array $items, array $generated ) {
    $by_index = array();
    $by_word  = array();
    foreach ( $generated as $card ) {
        if ( ! is_array( $card ) ) {
            continue;
        }
        if ( isset( $card['index'] ) && is_numeric( $card['index'] ) && ! isset( $by_index[ (int) $card['index'] ] ) ) {
            $by_index[ (int) $card['index'] ] = $card;
        }
        if ( isset( $card['word'] ) && is_string( $card['word'] ) ) {
            $key = mb_strtolower( trim( $card['word'] ), 'UTF-8' );
            if ( ! isset( $by_word[ $key ] ) ) {
                $by_word[ $key ] = $card;
            }
        }
    }

    $cards = array();
    foreach ( array_values( $items ) as $i => $item ) {
        $key   = mb_strtolower( trim( $item ), 'UTF-8' );
        $match = null;
        if ( isset( $by_index[ $i + 1 ] ) && isset( $by_index[ $i + 1 ]['word'] ) && mb_strtolower( trim( (string) $by_index[ $i + 1 ]['word'] ), 'UTF-8' ) === $key ) {
            $match = $by_index[ $i + 1 ];
        } elseif ( isset( $by_word[ $key ] ) ) {
            $match = $by_word[ $key ];
        } elseif ( isset( $by_index[ $i + 1 ] ) ) {
            $match = $by_index[ $i + 1 ];
        }

        $card = array( 'word' => $item );
        foreach ( array( 'phonetic', 'translation', 'example' ) as $field ) {
            $value          = $match && isset( $match[ $field ] ) && is_scalar( $match[ $field ] ) ? sanitize_text_field( (string) $match[ $field ] ) : '';
            $card[ $field ] = trim( mb_substr( $value, 0, TBT_FC_MAX_FIELD_CHARS, 'UTF-8' ) );
        }
        $cards[] = $card;
    }

    return $cards;
}

/**
 * Pull the text output out of a Responses API response.
 *
 * @param array $response Decoded response.
 * @return string|WP_Error
 */
function tbt_fc_openai_output_text( array $response ) {
    if ( ! empty( $response['output'] ) && is_array( $response['output'] ) ) {
        foreach ( $response['output'] as $item ) {
            if ( ! is_array( $item ) || 'message' !== ( isset( $item['type'] ) ? $item['type'] : '' ) || empty( $item['content'] ) || ! is_array( $item['content'] ) ) {
                continue;
            }
            foreach ( $item['content'] as $content ) {
                if ( ! is_array( $content ) ) {
                    continue;
                }
                $type = isset( $content['type'] ) ? $content['type'] : '';
                if ( 'refusal' === $type ) {
                    return new WP_Error( 'tbt_fc_openai_refusal', __( 'OpenAI declined to generate these cards. Try changing the items or the context.', 'tbt-flashcards' ), array( 'status' => 502 ) );
                }
                if ( 'output_text' === $type && isset( $content['text'] ) ) {
                    return (string) $content['text'];
                }
            }
        }
    }

    return new WP_Error( 'tbt_fc_openai_missing_text', __( 'OpenAI returned no cards. Try again.', 'tbt-flashcards' ), array( 'status' => 502 ) );
}

/**
 * Turn an OpenAI API failure into a safe error.
 *
 * Statuses are mapped so an OpenAI 401/403 is never mistaken in the browser
 * for an expired WordPress session.
 *
 * @param int   $status_code HTTP status.
 * @param array $decoded     Decoded body.
 * @return WP_Error
 */
function tbt_fc_openai_api_error( $status_code, array $decoded ) {
    $message = __( 'OpenAI could not generate the cards. Try again.', 'tbt-flashcards' );
    $code    = 'tbt_fc_openai_error';
    $status  = 502;

    if ( 401 === $status_code || 403 === $status_code ) {
        $message = __( 'OpenAI rejected the API key. Ask the site administrator to check it.', 'tbt-flashcards' );
        $code    = 'tbt_fc_openai_auth';
    } elseif ( 429 === $status_code ) {
        $message = __( 'The OpenAI limit was reached. Try again in a minute.', 'tbt-flashcards' );
        $code    = 'tbt_fc_openai_rate_limit';
        $status  = 429;
    } elseif ( $status_code >= 500 ) {
        $message = __( 'OpenAI is temporarily unavailable. Try again.', 'tbt-flashcards' );
        $code    = 'tbt_fc_openai_server';
    } elseif ( ! empty( $decoded['error']['message'] ) && current_user_can( 'manage_options' ) ) {
        $message = sanitize_text_field( (string) $decoded['error']['message'] );
    }

    return new WP_Error( $code, $message, array( 'status' => $status ) );
}
