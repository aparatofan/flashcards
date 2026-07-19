<?php
/**
 * Admin functionality for TBT Flashcards.
 *
 * Registers the Flashcard Set custom post type, meta boxes,
 * and handles front-end rendering of single flashcard set posts.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register the Flashcard Set custom post type.
 */
function tbt_flashcards_register_post_type() {
    register_post_type( 'tbt_flashcard_set', array(
        'labels' => array(
            'name'               => __( 'Flashcards', 'tbt-flashcards' ),
            'singular_name'      => __( 'Flashcard Set', 'tbt-flashcards' ),
            'add_new'            => __( 'Add New', 'tbt-flashcards' ),
            'add_new_item'       => __( 'Add New Flashcard Set', 'tbt-flashcards' ),
            'edit_item'          => __( 'Edit Flashcard Set', 'tbt-flashcards' ),
            'new_item'           => __( 'New Flashcard Set', 'tbt-flashcards' ),
            'view_item'          => __( 'View Flashcard Set', 'tbt-flashcards' ),
            'search_items'       => __( 'Search Flashcard Sets', 'tbt-flashcards' ),
            'not_found'          => __( 'No flashcard sets found.', 'tbt-flashcards' ),
            'not_found_in_trash' => __( 'No flashcard sets found in Trash.', 'tbt-flashcards' ),
            'menu_name'          => __( 'Flashcards', 'tbt-flashcards' ),
        ),
        'public'       => true,
        'has_archive'  => true,
        // Nest under the TBT hub menu when it is active; fall back to a
        // top-level menu of its own when the hub is deactivated.
        'show_in_menu' => defined( 'TBT_HUB_SLUG' ) ? TBT_HUB_SLUG : true,
        'menu_icon'    => 'dashicons-welcome-learn-more',
        'supports'     => array( 'title' ),
        'rewrite'      => array( 'slug' => 'flashcards' ),
    ) );
}
add_action( 'init', 'tbt_flashcards_register_post_type' );

/**
 * Add meta boxes for flashcard set settings.
 */
function tbt_flashcards_add_meta_boxes() {
    add_meta_box(
        'tbt_flashcards_settings',
        __( 'Flashcard Settings', 'tbt-flashcards' ),
        'tbt_flashcards_settings_meta_box',
        'tbt_flashcard_set',
        'normal',
        'high'
    );
}
add_action( 'add_meta_boxes', 'tbt_flashcards_add_meta_boxes' );

/**
 * Render the flashcard settings meta box.
 *
 * @param WP_Post $post The current post.
 */
function tbt_flashcards_settings_meta_box( $post ) {
    wp_nonce_field( 'tbt_flashcards_save', 'tbt_flashcards_nonce' );

    $csv_file = get_post_meta( $post->ID, '_tbt_csv_file', true );
    $csv_url  = get_post_meta( $post->ID, '_tbt_csv_url', true );
    $height   = get_post_meta( $post->ID, '_tbt_height', true );

    if ( empty( $height ) ) {
        $height = 595;
    }
    ?>
    <table class="form-table">
        <tr>
            <th><label for="tbt_csv_file"><?php esc_html_e( 'CSV Filename', 'tbt-flashcards' ); ?></label></th>
            <td>
                <input type="text" id="tbt_csv_file" name="tbt_csv_file"
                       value="<?php echo esc_attr( $csv_file ); ?>" class="regular-text" />
                <p class="description">
                    <?php esc_html_e( 'Enter the filename of a CSV uploaded to the Media Library (e.g. vocabulary.csv).', 'tbt-flashcards' ); ?>
                </p>
            </td>
        </tr>
        <tr>
            <th><label for="tbt_csv_url"><?php esc_html_e( 'CSV URL', 'tbt-flashcards' ); ?></label></th>
            <td>
                <input type="url" id="tbt_csv_url" name="tbt_csv_url"
                       value="<?php echo esc_attr( $csv_url ); ?>" class="large-text" />
                <p class="description">
                    <?php esc_html_e( 'Or enter a direct URL to a CSV file. This takes priority over the filename above.', 'tbt-flashcards' ); ?>
                </p>
            </td>
        </tr>
        <tr>
            <th><label for="tbt_height"><?php esc_html_e( 'Card Height (px)', 'tbt-flashcards' ); ?></label></th>
            <td>
                <input type="number" id="tbt_height" name="tbt_height"
                       value="<?php echo esc_attr( $height ); ?>" min="100" step="1" class="small-text" />
            </td>
        </tr>
    </table>
    <?php if ( 'auto-draft' !== $post->post_status ) : ?>
    <h3><?php esc_html_e( 'Shortcode', 'tbt-flashcards' ); ?></h3>
    <p>
        <code>[tbt_flashcards id="<?php echo esc_attr( $post->ID ); ?>"]</code>
        &mdash; <?php esc_html_e( 'Use this shortcode to embed this flashcard set in any page or post.', 'tbt-flashcards' ); ?>
    </p>
    <?php endif; ?>
    <?php
}

/**
 * Save flashcard set meta data.
 *
 * @param int $post_id The post ID being saved.
 */
function tbt_flashcards_save_meta( $post_id ) {
    if ( ! isset( $_POST['tbt_flashcards_nonce'] ) ||
         ! wp_verify_nonce( $_POST['tbt_flashcards_nonce'], 'tbt_flashcards_save' ) ) {
        return;
    }

    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    if ( isset( $_POST['tbt_csv_file'] ) ) {
        update_post_meta( $post_id, '_tbt_csv_file', sanitize_file_name( $_POST['tbt_csv_file'] ) );
    }

    if ( isset( $_POST['tbt_csv_url'] ) ) {
        update_post_meta( $post_id, '_tbt_csv_url', esc_url_raw( $_POST['tbt_csv_url'] ) );
    }

    if ( isset( $_POST['tbt_height'] ) ) {
        update_post_meta( $post_id, '_tbt_height', absint( $_POST['tbt_height'] ) );
    }
}
add_action( 'save_post_tbt_flashcard_set', 'tbt_flashcards_save_meta' );

/**
 * Add custom columns to the flashcard sets list table.
 *
 * @param array $columns Existing columns.
 * @return array Modified columns.
 */
function tbt_flashcards_admin_columns( $columns ) {
    $new_columns = array();
    foreach ( $columns as $key => $label ) {
        $new_columns[ $key ] = $label;
        if ( 'title' === $key ) {
            $new_columns['tbt_csv_source'] = __( 'CSV Source', 'tbt-flashcards' );
            $new_columns['tbt_shortcode']  = __( 'Shortcode', 'tbt-flashcards' );
        }
    }
    return $new_columns;
}
add_filter( 'manage_tbt_flashcard_set_posts_columns', 'tbt_flashcards_admin_columns' );

/**
 * Render custom column content for the flashcard sets list table.
 *
 * @param string $column  The column name.
 * @param int    $post_id The post ID.
 */
function tbt_flashcards_admin_column_content( $column, $post_id ) {
    if ( 'tbt_csv_source' === $column ) {
        $csv_url  = get_post_meta( $post_id, '_tbt_csv_url', true );
        $csv_file = get_post_meta( $post_id, '_tbt_csv_file', true );
        if ( ! empty( $csv_url ) ) {
            echo esc_html( $csv_url );
        } elseif ( ! empty( $csv_file ) ) {
            echo esc_html( $csv_file );
        } else {
            echo '&mdash;';
        }
    } elseif ( 'tbt_shortcode' === $column ) {
        echo '<code>[tbt_flashcards id="' . esc_attr( $post_id ) . '"]</code>';
    }
}
add_action( 'manage_tbt_flashcard_set_posts_custom_column', 'tbt_flashcards_admin_column_content', 10, 2 );

/**
 * Render flashcards on single flashcard set posts.
 *
 * @param string $content The post content.
 * @return string Modified content.
 */
function tbt_flashcards_filter_content( $content ) {
    if ( ! is_singular( 'tbt_flashcard_set' ) || ! in_the_loop() || ! is_main_query() ) {
        return $content;
    }

    $post_id = get_the_ID();

    return tbt_flashcards_render_from_post( $post_id );
}
add_filter( 'the_content', 'tbt_flashcards_filter_content' );

/**
 * Build shortcode output from a flashcard set post.
 *
 * @param int $post_id The flashcard set post ID.
 * @return string Shortcode HTML or empty string.
 */
function tbt_flashcards_render_from_post( $post_id ) {
    $csv_url  = get_post_meta( $post_id, '_tbt_csv_url', true );
    $csv_file = get_post_meta( $post_id, '_tbt_csv_file', true );
    $height   = get_post_meta( $post_id, '_tbt_height', true );

    $atts = array();

    if ( ! empty( $csv_url ) ) {
        $atts['url'] = $csv_url;
    } elseif ( ! empty( $csv_file ) ) {
        $atts['file'] = $csv_file;
    } else {
        return '';
    }

    $atts['title'] = get_the_title( $post_id );

    if ( ! empty( $height ) ) {
        $atts['height'] = $height;
    }

    return tbt_flashcards_render( $atts );
}
