<?php
/**
 * REST routes, namespace tbt-flashcards/v1.
 *
 * Authoring routes need the wp_rest nonce (WordPress cookie auth) and
 * tbt_fc_can_use_tools(). Single-set routes also need post_author to be the
 * current user, with no administrator bypass. GET /sets/{id}/cards is public
 * and serves published sets only.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TBT_FC_REST_NAMESPACE', 'tbt-flashcards/v1' );
define( 'TBT_FC_MAX_TITLE_CHARS', 200 );
define( 'TBT_FC_MAX_CONTEXT_CHARS', 200 );

/**
 * Register the routes.
 */
function tbt_fc_register_rest_routes() {
    register_rest_route( TBT_FC_REST_NAMESPACE, '/generate', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'tbt_fc_rest_generate',
        'permission_callback' => 'tbt_fc_rest_check_tools',
    ) );

    register_rest_route( TBT_FC_REST_NAMESPACE, '/sets', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'tbt_fc_rest_create_set',
        'permission_callback' => 'tbt_fc_rest_check_tools',
    ) );

    $id_arg = array(
        'id' => array(
            'validate_callback' => function ( $value ) {
                return is_numeric( $value ) && (int) $value > 0;
            },
        ),
    );

    register_rest_route( TBT_FC_REST_NAMESPACE, '/sets/(?P<id>\d+)', array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'tbt_fc_rest_get_set',
            'permission_callback' => 'tbt_fc_rest_check_owner',
            'args'                => $id_arg,
        ),
        array(
            'methods'             => 'PUT',
            'callback'            => 'tbt_fc_rest_update_set',
            'permission_callback' => 'tbt_fc_rest_check_owner',
            'args'                => $id_arg,
        ),
        array(
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => 'tbt_fc_rest_delete_set',
            'permission_callback' => 'tbt_fc_rest_check_owner',
            'args'                => $id_arg,
        ),
    ) );

    register_rest_route( TBT_FC_REST_NAMESPACE, '/sets/(?P<id>\d+)/csv', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'tbt_fc_rest_export_csv',
        'permission_callback' => 'tbt_fc_rest_check_owner',
        'args'                => $id_arg,
    ) );

    register_rest_route( TBT_FC_REST_NAMESPACE, '/sets/(?P<id>\d+)/cards', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'tbt_fc_rest_public_cards',
        'permission_callback' => '__return_true',
        'args'                => $id_arg,
    ) );
}
add_action( 'rest_api_init', 'tbt_fc_register_rest_routes' );

/**
 * URL of a set's public cards route.
 *
 * @param int $post_id Flashcard set ID.
 * @return string
 */
function tbt_fc_cards_rest_url( $post_id ) {
    return rest_url( TBT_FC_REST_NAMESPACE . '/sets/' . (int) $post_id . '/cards' );
}

/**
 * URL of a set's CSV export route, nonce included so a plain link works with
 * cookie auth.
 *
 * @param int $post_id Flashcard set ID.
 * @return string
 */
function tbt_fc_csv_rest_url( $post_id ) {
    return add_query_arg(
        '_wpnonce',
        wp_create_nonce( 'wp_rest' ),
        rest_url( TBT_FC_REST_NAMESPACE . '/sets/' . (int) $post_id . '/csv' )
    );
}

/**
 * nocache_headers() as REST response headers.
 *
 * wp_get_nocache_headers() flags Last-Modified as false (meaning "remove"),
 * which a REST response would send as an empty header, so it is dropped.
 *
 * @return array
 */
function tbt_fc_rest_nocache_headers() {
    return array_filter( wp_get_nocache_headers(), 'is_string' );
}

/**
 * A set's title as plain text.
 *
 * For users without unfiltered_html WordPress runs titles through kses on
 * save, which stores "&" as "&amp;". Decoding keeps the editor field and the
 * deck title showing what the teacher typed, and stops a re-save encoding it
 * twice.
 *
 * @param int $post_id Flashcard set ID.
 * @return string
 */
function tbt_fc_rest_title( $post_id ) {
    return html_entity_decode( (string) get_post_field( 'post_title', $post_id ), ENT_QUOTES, 'UTF-8' );
}

/**
 * Permission: logged in and allowed to use the tools.
 *
 * @return true|WP_Error
 */
function tbt_fc_rest_check_tools() {
    if ( ! is_user_logged_in() ) {
        return new WP_Error( 'tbt_fc_not_logged_in', __( 'Your session has expired. Refresh the page.', 'tbt-flashcards' ), array( 'status' => 401 ) );
    }
    if ( ! tbt_fc_can_use_tools() ) {
        return new WP_Error( 'tbt_fc_forbidden', __( 'Your account does not include the teaching tools.', 'tbt-flashcards' ), array( 'status' => 403 ) );
    }
    return true;
}

/**
 * Permission: tools access plus ownership of a live (not trashed) set.
 *
 * @param WP_REST_Request $request Request.
 * @return true|WP_Error
 */
function tbt_fc_rest_check_owner( WP_REST_Request $request ) {
    $access = tbt_fc_rest_check_tools();
    if ( is_wp_error( $access ) ) {
        return $access;
    }

    $post = get_post( absint( $request['id'] ) );
    if ( ! $post || 'tbt_flashcard_set' !== $post->post_type || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
        return new WP_Error( 'tbt_fc_not_found', __( "This set isn't yours or no longer exists.", 'tbt-flashcards' ), array( 'status' => 404 ) );
    }
    if ( ! tbt_fc_user_owns_set( $post->ID ) ) {
        return new WP_Error( 'tbt_fc_not_owner', __( "This set isn't yours or no longer exists.", 'tbt-flashcards' ), array( 'status' => 403 ) );
    }

    return true;
}

/**
 * Validate and sanitise the body of a create or update request.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error { title, context, cards }
 */
function tbt_fc_rest_read_set_body( WP_REST_Request $request ) {
    $title = $request->get_param( 'title' );
    $title = is_scalar( $title ) ? sanitize_text_field( (string) $title ) : '';
    $title = trim( mb_substr( $title, 0, TBT_FC_MAX_TITLE_CHARS, 'UTF-8' ) );
    if ( '' === $title ) {
        return new WP_Error( 'tbt_fc_title_required', __( 'Give the set a title.', 'tbt-flashcards' ), array( 'status' => 400 ) );
    }

    $context = $request->get_param( 'context' );
    $context = is_scalar( $context ) ? sanitize_text_field( (string) $context ) : '';
    $context = trim( mb_substr( $context, 0, TBT_FC_MAX_CONTEXT_CHARS, 'UTF-8' ) );

    $cards = tbt_fc_sanitize_cards( $request->get_param( 'cards' ) );
    if ( empty( $cards ) ) {
        return new WP_Error( 'tbt_fc_cards_required', __( 'Add at least one card with an English word.', 'tbt-flashcards' ), array( 'status' => 400 ) );
    }

    return array(
        'title'   => $title,
        'context' => $context,
        'cards'   => $cards,
    );
}

/**
 * Write the two editor meta keys. The legacy CSV meta is never touched.
 *
 * @param int   $post_id Flashcard set ID.
 * @param array $body    Sanitised body.
 */
function tbt_fc_write_set_meta( $post_id, array $body ) {
    update_post_meta( $post_id, '_tbt_fc_cards', wp_slash( wp_json_encode( $body['cards'] ) ) );
    update_post_meta( $post_id, '_tbt_fc_context', wp_slash( $body['context'] ) );
}

/**
 * The response for a saved set.
 *
 * @param int   $post_id Flashcard set ID.
 * @param array $cards   Saved cards.
 * @return array
 */
function tbt_fc_rest_saved_payload( $post_id, array $cards ) {
    return array(
        'id'          => (int) $post_id,
        'title'       => tbt_fc_rest_title( $post_id ),
        'link'        => get_permalink( $post_id ),
        'shortcode'   => '[tbt_flashcards id="' . (int) $post_id . '"]',
        'cards_count' => count( $cards ),
        'cards'       => $cards,
        'csv'         => tbt_fc_to_csv( $cards ),
        'csv_url'     => tbt_fc_csv_rest_url( $post_id ),
    );
}

/**
 * POST /generate
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function tbt_fc_rest_generate( WP_REST_Request $request ) {
    $raw_items = $request->get_param( 'items' );
    if ( ! is_array( $raw_items ) ) {
        $raw_items = array();
    }

    $items = array();
    foreach ( $raw_items as $item ) {
        if ( ! is_scalar( $item ) ) {
            continue;
        }
        $item = trim( mb_strcut( sanitize_text_field( (string) $item ), 0, TBT_FC_MAX_WORD_BYTES, 'UTF-8' ) );
        if ( '' !== $item ) {
            $items[] = $item;
        }
    }

    $context = $request->get_param( 'context' );
    $context = is_scalar( $context ) ? sanitize_text_field( (string) $context ) : '';
    $context = trim( mb_substr( $context, 0, TBT_FC_MAX_CONTEXT_CHARS, 'UTF-8' ) );

    $cards = tbt_fc_generate( $items, $context );
    if ( is_wp_error( $cards ) ) {
        return $cards;
    }

    return rest_ensure_response( array( 'cards' => $cards ) );
}

/**
 * POST /sets
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function tbt_fc_rest_create_set( WP_REST_Request $request ) {
    $body = tbt_fc_rest_read_set_body( $request );
    if ( is_wp_error( $body ) ) {
        return $body;
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

    $response = rest_ensure_response( tbt_fc_rest_saved_payload( $post_id, $body['cards'] ) );
    $response->set_status( 201 );
    return $response;
}

/**
 * GET /sets/{id}
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function tbt_fc_rest_get_set( WP_REST_Request $request ) {
    $post_id   = absint( $request['id'] );
    $is_legacy = ! tbt_fc_is_editor_set( $post_id );
    $cards     = tbt_fc_get_cards( $post_id );
    $warning   = '';

    if ( is_wp_error( $cards ) ) {
        $warning = $cards->get_error_message();
        $cards   = array();
    }

    return rest_ensure_response( array(
        'id'        => $post_id,
        'title'     => tbt_fc_rest_title( $post_id ),
        'context'   => (string) get_post_meta( $post_id, '_tbt_fc_context', true ),
        'cards'     => $cards,
        'is_legacy' => $is_legacy,
        'warning'   => $warning,
        'link'      => get_permalink( $post_id ),
        'shortcode' => '[tbt_flashcards id="' . $post_id . '"]',
        'csv_url'   => tbt_fc_csv_rest_url( $post_id ),
    ) );
}

/**
 * PUT /sets/{id}
 *
 * A legacy set becomes an editor set here; its CSV meta stays as it was.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function tbt_fc_rest_update_set( WP_REST_Request $request ) {
    $post_id = absint( $request['id'] );
    $body    = tbt_fc_rest_read_set_body( $request );
    if ( is_wp_error( $body ) ) {
        return $body;
    }

    $updated = wp_update_post( array(
        'ID'         => $post_id,
        'post_title' => wp_slash( $body['title'] ),
    ), true );

    if ( is_wp_error( $updated ) ) {
        return new WP_Error( 'tbt_fc_save_failed', __( 'The set could not be saved. Try again.', 'tbt-flashcards' ), array( 'status' => 500 ) );
    }

    tbt_fc_write_set_meta( $post_id, $body );
    tbt_fc_get_cards( $post_id, true );

    return rest_ensure_response( tbt_fc_rest_saved_payload( $post_id, $body['cards'] ) );
}

/**
 * DELETE /sets/{id} — moves the set to the Trash (recoverable in wp-admin).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function tbt_fc_rest_delete_set( WP_REST_Request $request ) {
    if ( ! wp_trash_post( absint( $request['id'] ) ) ) {
        return new WP_Error( 'tbt_fc_delete_failed', __( 'The set could not be deleted. Try again.', 'tbt-flashcards' ), array( 'status' => 500 ) );
    }

    return rest_ensure_response( array( 'deleted' => true ) );
}

/**
 * GET /sets/{id}/csv — the set as a CSV download.
 *
 * The body is printed raw by tbt_fc_rest_serve_csv() instead of being JSON
 * encoded.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function tbt_fc_rest_export_csv( WP_REST_Request $request ) {
    $post_id = absint( $request['id'] );
    $cards   = tbt_fc_get_cards( $post_id );
    if ( is_wp_error( $cards ) ) {
        return new WP_Error( 'tbt_fc_csv_unavailable', $cards->get_error_message(), array( 'status' => 404 ) );
    }

    $post     = get_post( $post_id );
    $filename = sanitize_file_name( ( $post && $post->post_name ) ? $post->post_name : 'flashcards-' . $post_id ) . '.csv';

    $response = new WP_REST_Response( tbt_fc_to_csv( $cards ), 200 );
    $response->set_headers( array_merge( tbt_fc_rest_nocache_headers(), array(
        'Content-Type'        => 'text/csv; charset=utf-8',
        'Content-Disposition' => 'attachment; filename="' . $filename . '"',
    ) ) );
    $response->header( 'X-TBT-FC-Raw', '1' );
    return $response;
}

/**
 * Print the CSV export as-is rather than as a JSON string.
 *
 * @param bool             $served  Whether the request has been served.
 * @param WP_HTTP_Response $result  Response.
 * @param WP_REST_Request  $request Request.
 * @return bool
 */
function tbt_fc_rest_serve_csv( $served, $result, $request ) {
    if ( $served || ! $result instanceof WP_HTTP_Response ) {
        return $served;
    }

    $route = $request instanceof WP_REST_Request ? $request->get_route() : '';
    if ( ! preg_match( '#^/' . preg_quote( TBT_FC_REST_NAMESPACE, '#' ) . '/sets/\d+/csv$#', $route ) || 200 !== $result->get_status() ) {
        return $served;
    }

    $headers = $result->get_headers();
    if ( empty( $headers['X-TBT-FC-Raw'] ) || ! is_string( $result->get_data() ) ) {
        return $served;
    }

    echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV file body.
    return true;
}
add_filter( 'rest_pre_serve_request', 'tbt_fc_rest_serve_csv', 10, 3 );

/**
 * GET /sets/{id}/cards — public, published sets only.
 *
 * Uncached, like Swipe's deck endpoint, so an edit shows at once in every
 * lesson and on the deck page.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function tbt_fc_rest_public_cards( WP_REST_Request $request ) {
    $post_id = absint( $request['id'] );
    $post    = get_post( $post_id );

    $headers = array_merge( tbt_fc_rest_nocache_headers(), array( 'X-LiteSpeed-Cache-Control' => 'no-cache' ) );

    if ( ! $post || 'tbt_flashcard_set' !== $post->post_type || 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
        $error = new WP_REST_Response( array(
            'code'    => 'tbt_fc_not_found',
            'message' => __( 'Flashcard set not found.', 'tbt-flashcards' ),
            'data'    => array( 'status' => 404 ),
        ), 404 );
        $error->set_headers( $headers );
        return $error;
    }

    $cards = tbt_fc_get_cards( $post_id );
    if ( is_wp_error( $cards ) ) {
        $cards = array();
    }

    $response = new WP_REST_Response( array(
        'title' => tbt_fc_rest_title( $post_id ),
        'cards' => $cards,
    ), 200 );
    $response->set_headers( $headers );
    return $response;
}
