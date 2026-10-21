<?php
/**
 * Front-end access gate for the teacher tools.
 *
 * Same rule as TBT Matching Games, so one grant opens every TBT teaching
 * tool. The post type keeps its default capabilities; wp-admin is untouched.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Can the current user use the front-end flashcard tools?
 *
 * Logged-out visitors are always denied. Everyone else needs the shared
 * teaching-tools capability, Swipe's teacher capability, or manage_options.
 *
 * @return bool
 */
function tbt_fc_can_use_tools() {
    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        return false;
    }

    $allowed = current_user_can( 'tbt_use_teaching_tools' )
        || current_user_can( 'tbts_manage' )
        || current_user_can( 'manage_options' );

    /**
     * Filter whether a user may use the front-end flashcard tools.
     *
     * @param bool $allowed Capability result.
     * @param int  $user_id Current user ID.
     */
    return (bool) apply_filters( 'tbt_fc_can_use_tools', $allowed, $user_id );
}

/**
 * Does the current user own this flashcard set?
 *
 * No administrator bypass: the front end is owner-scoped for everyone, and
 * wp-admin stays the cross-user view.
 *
 * @param int $post_id Flashcard set ID.
 * @return bool
 */
function tbt_fc_user_owns_set( $post_id ) {
    $post    = get_post( $post_id );
    $user_id = get_current_user_id();

    return $post
        && 'tbt_flashcard_set' === $post->post_type
        && $user_id
        && (int) $post->post_author === (int) $user_id;
}
