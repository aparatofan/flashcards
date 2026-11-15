<?php
/**
 * PHP API for other TBT plugins (TBT Lesson Builder).
 *
 * The functions live here so the OpenAI and ElevenLabs keys and models stay
 * in this plugin; callers check function_exists() first. All three use the
 * same gate as the REST routes, tbt_fc_can_use_tools().
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TBT_FC_MAX_TTS_MP3_CHARS', 2000 );

/**
 * The shared permission error, or null when the current user may use the API.
 *
 * @return WP_Error|null
 */
function tbt_fc_api_forbidden() {
    if ( tbt_fc_can_use_tools() ) {
        return null;
    }
    return new WP_Error( 'tbt_fc_forbidden', __( 'Your account does not include the teaching tools.', 'tbt-flashcards' ), array( 'status' => 403 ) );
}

/**
 * Sanitise an optional AI context the way the REST routes do.
 *
 * @param mixed $context Raw context.
 * @return string
 */
function tbt_fc_api_clean_context( $context ) {
    $context = is_scalar( $context ) ? sanitize_text_field( (string) $context ) : '';
    return trim( mb_substr( $context, 0, TBT_FC_MAX_CONTEXT_CHARS, 'UTF-8' ) );
}

/**
 * Create a published flashcard set owned by the current user.
 *
 * Does exactly what POST /sets does: same post type, status, author and
 * meta writer, same title and context limits.
 *
 * @param string  $title   Set title.
 * @param array[] $cards   { word, phonetic, translation, example } — run through tbt_fc_sanitize_cards().
 * @param string  $context Optional AI context, as the teacher tools store it.
 * @return int|WP_Error Set ID.
 */
function tbt_fc_create_set( $title, array $cards, $context = '' ) {
    $forbidden = tbt_fc_api_forbidden();
    if ( $forbidden ) {
        return $forbidden;
    }

    $title = is_scalar( $title ) ? sanitize_text_field( (string) $title ) : '';
    $title = trim( mb_substr( $title, 0, TBT_FC_MAX_TITLE_CHARS, 'UTF-8' ) );
    if ( '' === $title ) {
        return new WP_Error( 'tbt_fc_title_required', __( 'Give the set a title.', 'tbt-flashcards' ), array( 'status' => 400 ) );
    }

    $body = array(
        'title'   => $title,
        'context' => tbt_fc_api_clean_context( $context ),
        'cards'   => tbt_fc_sanitize_cards( $cards ),
    );
    if ( empty( $body['cards'] ) ) {
        return new WP_Error( 'tbt_fc_no_cards', __( 'Add at least one card with an English word.', 'tbt-flashcards' ), array( 'status' => 400 ) );
    }

    // wp_insert_post() unslashes its input, so the title is slashed first.
    $post_id = wp_insert_post( array(
        'post_type'   => 'tbt_flashcard_set',
        'post_status' => 'publish',
        'post_title'  => wp_slash( $body['title'] ),
        'post_author' => get_current_user_id(),
    ), true );

    if ( is_wp_error( $post_id ) || ! $post_id ) {
        return new WP_Error( 'tbt_fc_save_failed', __( 'The set could not be saved. Try again.', 'tbt-flashcards' ), array( 'status' => 500 ) );
    }

    tbt_fc_write_set_meta( $post_id, $body );

    return (int) $post_id;
}

/**
 * Fill cards with AI. Wraps tbt_fc_generate(); same limits and errors.
 *
 * Items are cleaned as POST /generate cleans them: sanitised, capped at
 * TBT_FC_MAX_WORD_BYTES, empty ones dropped.
 *
 * @param string[] $items   English words or expressions.
 * @param string   $context Optional context (the builder passes the lesson title).
 * @return array[]|WP_Error Cards in the same order as $items.
 */
function tbt_fc_fill_cards( array $items, $context = '' ) {
    $forbidden = tbt_fc_api_forbidden();
    if ( $forbidden ) {
        return $forbidden;
    }

    $clean = array();
    foreach ( $items as $item ) {
        if ( ! is_scalar( $item ) ) {
            continue;
        }
        $item = trim( mb_strcut( sanitize_text_field( (string) $item ), 0, TBT_FC_MAX_WORD_BYTES, 'UTF-8' ) );
        if ( '' !== $item ) {
            $clean[] = $item;
        }
    }

    return tbt_fc_generate( $clean, tbt_fc_api_clean_context( $context ) );
}

/**
 * One ElevenLabs MP3 for a longer text in a given voice. No caching.
 *
 * Copies the TTS proxy's request (stored key, TBT_FC_ELEVENLABS_MODEL_ID,
 * language code and voice settings) but takes the voice as an argument. It
 * never touches the proxy, its constants or its audio cache.
 *
 * @param string $text     Up to 2000 characters.
 * @param string $voice_id ElevenLabs voice ID ([A-Za-z0-9]{10,40}).
 * @return string|WP_Error MP3 bytes.
 */
function tbt_fc_tts_mp3( $text, $voice_id ) {
    $forbidden = tbt_fc_api_forbidden();
    if ( $forbidden ) {
        return $forbidden;
    }

    $text = is_scalar( $text ) ? trim( sanitize_text_field( (string) $text ) ) : '';
    if ( '' === $text ) {
        return new WP_Error( 'tbt_fc_tts_no_text', __( 'There is no text to read aloud.', 'tbt-flashcards' ), array( 'status' => 400 ) );
    }
    if ( mb_strlen( $text, 'UTF-8' ) > TBT_FC_MAX_TTS_MP3_CHARS ) {
        return new WP_Error(
            'tbt_fc_tts_too_long',
            /* translators: %d = maximum number of characters */
            sprintf( __( 'The text is too long to read aloud (max %d characters).', 'tbt-flashcards' ), TBT_FC_MAX_TTS_MP3_CHARS ),
            array( 'status' => 400 )
        );
    }

    $voice_id = is_string( $voice_id ) ? $voice_id : '';
    if ( ! preg_match( '/^[A-Za-z0-9]{10,40}$/', $voice_id ) ) {
        return new WP_Error( 'tbt_fc_invalid_voice', __( 'The voice ID is not valid.', 'tbt-flashcards' ), array( 'status' => 400 ) );
    }

    $api_key = get_option( 'tbt_fc_elevenlabs_api_key', '' );
    if ( empty( $api_key ) ) {
        return new WP_Error( 'tbt_fc_missing_api_key', __( 'The ElevenLabs API key could not be found. Ask the site administrator to add it.', 'tbt-flashcards' ), array( 'status' => 503 ) );
    }

    // Longer texts than the proxy's, so 60 s here; the proxy keeps 30 s.
    $response = wp_remote_post(
        'https://api.elevenlabs.io/v1/text-to-speech/' . $voice_id,
        array(
            'timeout' => 60,
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

    // Error data carries the HTTP status only: never the key or the request.
    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'tbt_fc_tts_failed', __( 'The server could not reach ElevenLabs. Try again in a moment.', 'tbt-flashcards' ), array( 'status' => 0 ) );
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
    $body = (string) wp_remote_retrieve_body( $response );

    if ( 200 !== $code || 0 !== strpos( $type, 'audio/' ) || '' === $body ) {
        return new WP_Error(
            'tbt_fc_tts_failed',
            /* translators: %d = HTTP status code */
            sprintf( __( 'ElevenLabs could not create the audio (status %d).', 'tbt-flashcards' ), $code ),
            array( 'status' => $code )
        );
    }

    return $body;
}
