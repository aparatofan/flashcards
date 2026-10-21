<?php
/**
 * Teacher tools: [tbt_flashcards_sets] (library) and
 * [tbt_flashcards_generator] (create / edit workspace).
 *
 * Both render nothing at all for users without tbt_fc_can_use_tools(), and
 * neither has a Tool Hero. Assets load only where the shortcodes are: early,
 * before wp_head, when the page content contains them, with an in-shortcode
 * fallback for builders that render shortcodes from elsewhere.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register the shortcodes.
 */
function tbt_fc_register_tool_shortcodes() {
    add_shortcode( 'tbt_flashcards_sets', 'tbt_fc_render_library' );
    add_shortcode( 'tbt_flashcards_generator', 'tbt_fc_render_generator' );
}
add_action( 'init', 'tbt_fc_register_tool_shortcodes' );

/**
 * Register tbt-tokens from the vendored copy unless TBT Hub already did.
 *
 * Same pattern as Matching Games' ensure_shared_styles(): with Hub active its
 * canonical file wins; with Hub deactivated the byte-identical copy in
 * assets/vendor/tbt/ keeps the tools styled and nothing 404s.
 */
function tbt_fc_ensure_shared_styles() {
    if ( wp_style_is( 'tbt-tokens', 'registered' ) ) {
        return;
    }

    $relative = 'assets/vendor/tbt/tbt-tokens.css';
    $mtime    = @filemtime( TBT_FC_DIR . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

    wp_register_style( 'tbt-tokens', TBT_FC_URL . $relative, array(), $mtime ? (string) $mtime : TBT_FC_VERSION );
}

/**
 * Enqueue the tools' CSS and JS once per request.
 */
function tbt_fc_enqueue_tools_assets() {
    static $done = false;
    if ( $done || ! tbt_fc_can_use_tools() ) {
        return;
    }
    $done = true;

    tbt_fc_register_fonts();
    tbt_fc_ensure_shared_styles();

    wp_enqueue_style( 'tbtfc-tools', TBT_FC_URL . 'assets/css/tools.css', array( 'tbt-flashcards-google-fonts', 'tbt-tokens' ), TBT_FC_VERSION );
    wp_enqueue_script( 'tbtfc-tools', TBT_FC_URL . 'assets/js/tools.js', array(), TBT_FC_VERSION, true );

    wp_localize_script( 'tbtfc-tools', 'tbtFcTools', array(
        'restBase' => esc_url_raw( rest_url( TBT_FC_REST_NAMESPACE ) ),
        'nonce'    => wp_create_nonce( 'wp_rest' ),
        'maxItems' => TBT_FC_MAX_GENERATE_ITEMS,
    ) );
}

/**
 * Enqueue before wp_head when the queried page contains a tool shortcode.
 */
function tbt_fc_maybe_enqueue_tools_early() {
    if ( ! is_singular() ) {
        return;
    }
    $post = get_queried_object();
    if ( ! $post instanceof WP_Post ) {
        return;
    }
    if ( has_shortcode( $post->post_content, 'tbt_flashcards_sets' ) || has_shortcode( $post->post_content, 'tbt_flashcards_generator' ) ) {
        tbt_fc_enqueue_tools_assets();
    }
}
add_action( 'wp_enqueue_scripts', 'tbt_fc_maybe_enqueue_tools_early' );

/**
 * Tool pages carry a REST nonce, so they must never be served from cache.
 */
function tbt_fc_tools_nocache() {
    if ( ! is_singular() || ! is_user_logged_in() ) {
        return;
    }
    $post = get_queried_object();
    if ( $post instanceof WP_Post && ( has_shortcode( $post->post_content, 'tbt_flashcards_sets' ) || has_shortcode( $post->post_content, 'tbt_flashcards_generator' ) ) ) {
        nocache_headers();
    }
}
add_action( 'template_redirect', 'tbt_fc_tools_nocache' );

/**
 * Resolve a page attribute (full URL or root-relative path); empty means the
 * current page, so both shortcodes can share one page.
 *
 * @param string $value Attribute value.
 * @return string Unescaped URL.
 */
function tbt_fc_tool_page_url( $value ) {
    $value = trim( (string) $value );
    if ( '' !== $value ) {
        return $value;
    }

    $queried = get_queried_object_id();
    if ( $queried ) {
        $link = get_permalink( $queried );
        if ( $link ) {
            return $link;
        }
    }

    $request = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    return remove_query_arg( 'set', home_url( strtok( $request, '?' ) ) );
}

/**
 * Library: [tbt_flashcards_sets generator="/path/"].
 *
 * @param array $atts Attributes.
 * @return string
 */
function tbt_fc_render_library( $atts ) {
    if ( ! tbt_fc_can_use_tools() ) {
        return '';
    }

    $atts = shortcode_atts( array( 'generator' => '' ), $atts, 'tbt_flashcards_sets' );
    tbt_fc_enqueue_tools_assets();

    $generator = tbt_fc_tool_page_url( $atts['generator'] );

    $query = new WP_Query( array(
        'post_type'      => 'tbt_flashcard_set',
        'post_status'    => 'publish',
        'author'         => get_current_user_id(),
        'orderby'        => 'date',
        'order'          => 'DESC',
        'posts_per_page' => 500,
        'no_found_rows'  => true,
    ) );

    $sets  = $query->posts;
    $total = count( $sets );
    $empty = 0 === $total;

    static $instance = 0;
    $instance++;
    $uid = 'tbtfc-lib-' . $instance;

    ob_start();
    ?>
    <div class="tbtfc-tool tbtfc-library" data-tbtfc-library data-total="<?php echo esc_attr( $total ); ?>">
        <div class="tbtfc-libbar<?php echo $empty ? ' is-empty' : ''; ?>">
            <div class="tbtfc-libbar__title">
                <h2 class="tbtfc-section-title"><?php esc_html_e( 'Your sets', 'tbt-flashcards' ); ?></h2>
                <span class="tbtfc-libbar__line" aria-hidden="true"></span>
            </div>
            <?php if ( ! $empty ) : ?>
                <div class="tbtfc-libbar__filter" role="search">
                    <div class="tbtfc-libbar__search">
                        <label class="tbtfc-sr-only" for="<?php echo esc_attr( $uid ); ?>-search"><?php esc_html_e( 'Search your sets', 'tbt-flashcards' ); ?></label>
                        <svg class="tbtfc-libbar__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
                            <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2"/>
                            <path d="m20 20-3.5-3.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        <input type="search" id="<?php echo esc_attr( $uid ); ?>-search" class="tbtfc-libbar__input" data-tbtfc-search
                               placeholder="<?php esc_attr_e( 'Search…', 'tbt-flashcards' ); ?>" autocomplete="off">
                        <button type="button" class="tbtfc-libbar__clear" data-tbtfc-search-clear aria-label="<?php esc_attr_e( 'Clear search', 'tbt-flashcards' ); ?>" hidden>&times;</button>
                    </div>
                    <span class="tbtfc-libbar__line tbtfc-libbar__line--end" aria-hidden="true"></span>
                </div>
            <?php endif; ?>
            <a class="tbtfc-button tbtfc-button--primary tbtfc-libbar__cta" href="<?php echo esc_url( remove_query_arg( 'set', $generator ) ); ?>"><?php esc_html_e( 'Create new set', 'tbt-flashcards' ); ?></a>
        </div>

        <p class="tbtfc-libbar__summary" data-tbtfc-summary aria-live="polite" hidden>
            <span data-tbtfc-summary-text></span>
            <button type="button" class="tbtfc-libbar__link" data-tbtfc-reset><?php esc_html_e( 'Clear filters', 'tbt-flashcards' ); ?></button>
        </p>

        <?php if ( ! $empty ) : ?>
            <div class="tbtfc-sets" data-tbtfc-sets>
                <?php foreach ( $sets as $set ) : ?>
                    <?php
                    $is_legacy = ! tbt_fc_is_editor_set( $set->ID );
                    $count     = tbt_fc_card_count( $set->ID );
                    $link      = get_permalink( $set );
                    ?>
                    <div class="tbtfc-set" data-tbtfc-set data-id="<?php echo esc_attr( $set->ID ); ?>" data-search="<?php echo esc_attr( mb_strtolower( $set->post_title, 'UTF-8' ) ); ?>">
                        <div class="tbtfc-set__main">
                            <h3 class="tbtfc-set__title"><?php echo esc_html( $set->post_title ); ?></h3>
                            <div class="tbtfc-set__meta">
                                <span>
                                    <?php
                                    /* translators: 1: number of cards, 2: date */
                                    echo esc_html( sprintf( _n( '%1$d card · %2$s', '%1$d cards · %2$s', $count, 'tbt-flashcards' ), $count, get_the_date( 'j M Y', $set ) ) );
                                    ?>
                                </span>
                                <?php if ( $is_legacy ) : ?>
                                    <span class="tbtfc-chip" title="<?php esc_attr_e( 'Made before 2.0 from a Media Library CSV. Saving it in the editor moves the cards into the set.', 'tbt-flashcards' ); ?>"><?php esc_html_e( 'From CSV file', 'tbt-flashcards' ); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="tbtfc-set__actions">
                            <a class="tbtfc-btn" href="<?php echo esc_url( $link ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open', 'tbt-flashcards' ); ?></a>
                            <button type="button" class="tbtfc-btn" data-tbtfc-copy="<?php echo esc_attr( '[tbt_flashcards id="' . $set->ID . '"]' ); ?>"><?php esc_html_e( 'Copy shortcode', 'tbt-flashcards' ); ?></button>
                            <button type="button" class="tbtfc-btn" data-tbtfc-copy="<?php echo esc_attr( $link ); ?>"><?php esc_html_e( 'Copy link', 'tbt-flashcards' ); ?></button>
                            <a class="tbtfc-btn" href="<?php echo esc_url( tbt_fc_csv_rest_url( $set->ID ) ); ?>" download><?php esc_html_e( 'Export CSV', 'tbt-flashcards' ); ?></a>
                            <a class="tbtfc-btn" href="<?php echo esc_url( add_query_arg( 'set', $set->ID, $generator ) ); ?>"><?php esc_html_e( 'Edit', 'tbt-flashcards' ); ?></a>
                            <button type="button" class="tbtfc-btn tbtfc-btn--danger" data-tbtfc-delete><?php esc_html_e( 'Delete', 'tbt-flashcards' ); ?></button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="tbtfc-empty" data-tbtfc-nomatch hidden><?php esc_html_e( 'No sets match your search.', 'tbt-flashcards' ); ?></p>
        <?php endif; ?>
        <p class="tbtfc-error" data-tbtfc-lib-error role="alert" hidden></p>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Workspace: [tbt_flashcards_generator library="/path/"].
 *
 * @param array $atts Attributes.
 * @return string
 */
function tbt_fc_render_generator( $atts ) {
    if ( ! tbt_fc_can_use_tools() ) {
        return '';
    }

    $atts = shortcode_atts( array( 'library' => '' ), $atts, 'tbt_flashcards_generator' );
    tbt_fc_enqueue_tools_assets();

    $library = trim( (string) $atts['library'] );
    $set_id  = isset( $_GET['set'] ) ? absint( $_GET['set'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; the REST route checks ownership.

    static $instance = 0;
    $instance++;
    $uid = 'tbtfc-ws-' . $instance;

    ob_start();
    ?>
    <div class="tbtfc-tool tbtfc-workspace" data-tbtfc-workspace data-set-id="<?php echo esc_attr( $set_id ); ?>">
        <?php if ( '' !== $library ) : ?>
            <a class="tbtfc-backlink" href="<?php echo esc_url( $library ); ?>">&larr; <?php esc_html_e( 'Back to sets', 'tbt-flashcards' ); ?></a>
        <?php endif; ?>

        <p class="tbtfc-error tbtfc-error--block" data-tbtfc-load-error role="alert" hidden></p>
        <p class="tbtfc-loading" data-tbtfc-loading <?php echo $set_id ? '' : 'hidden'; ?>><?php esc_html_e( 'Loading the set…', 'tbt-flashcards' ); ?></p>

        <div class="tbtfc-stages" data-tbtfc-stages <?php echo $set_id ? 'hidden' : ''; ?>>
            <section class="tbtfc-stage" data-tbtfc-stage="1" data-state="active">
                <div class="tbtfc-stage__card">
                    <h2 class="tbtfc-stage__title"><b>1.</b> <?php esc_html_e( 'The set', 'tbt-flashcards' ); ?></h2>
                    <p class="tbtfc-stage__sub"><?php esc_html_e( 'Name it, and tell AI what the words are about so it picks the right meaning.', 'tbt-flashcards' ); ?></p>
                    <div class="tbtfc-stage__body">
                        <div class="tbtfc-pair">
                            <div class="tbtfc-field">
                                <label class="tbtfc-label" for="<?php echo esc_attr( $uid ); ?>-title"><?php esc_html_e( 'Title', 'tbt-flashcards' ); ?></label>
                                <input class="tbtfc-input" id="<?php echo esc_attr( $uid ); ?>-title" type="text" maxlength="200" data-tbtfc-title
                                       placeholder="<?php esc_attr_e( 'e.g. Yalom and the Secret Ingredient', 'tbt-flashcards' ); ?>">
                            </div>
                            <div class="tbtfc-field">
                                <label class="tbtfc-label" for="<?php echo esc_attr( $uid ); ?>-context"><?php esc_html_e( 'Context', 'tbt-flashcards' ); ?> <span class="tbtfc-label__opt"><?php esc_html_e( '(optional)', 'tbt-flashcards' ); ?></span></label>
                                <input class="tbtfc-input tbtfc-input--ui" id="<?php echo esc_attr( $uid ); ?>-context" type="text" maxlength="200" data-tbtfc-context
                                       placeholder="<?php esc_attr_e( "e.g. psychotherapy, Yalom's The Gift of Therapy", 'tbt-flashcards' ); ?>">
                                <p class="tbtfc-help"><?php
                                    /* translators: the two <i> phrases are Polish translations and stay as they are. */
                                    echo wp_kses( __( 'Shapes the translation and the example. "Presence" becomes <i>uważna obecność (terapeutyczna)</i>, not <i>obecność</i>.', 'tbt-flashcards' ), array( 'i' => array() ) );
                                ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="tbtfc-stage" data-tbtfc-stage="2" data-state="active">
                <div class="tbtfc-stage__card">
                    <h2 class="tbtfc-stage__title"><b>2.</b> <?php esc_html_e( 'The cards', 'tbt-flashcards' ); ?></h2>
                    <p class="tbtfc-stage__sub"><?php esc_html_e( 'One English item per line. AI adds the phonetics, the Polish translation and an example. Then check every card.', 'tbt-flashcards' ); ?></p>
                    <div class="tbtfc-stage__body">
                        <div class="tbtfc-field">
                            <label class="tbtfc-label" for="<?php echo esc_attr( $uid ); ?>-items"><?php esc_html_e( 'English items', 'tbt-flashcards' ); ?></label>
                            <textarea class="tbtfc-textarea" id="<?php echo esc_attr( $uid ); ?>-items" data-tbtfc-items
                                      placeholder="<?php echo esc_attr( "Transference\nAt a profound level\nUnburden one's mind" ); ?>"></textarea>
                        </div>
                        <div class="tbtfc-gen-row">
                            <button type="button" class="tbtfc-button tbtfc-button--primary" data-tbtfc-generate disabled><?php esc_html_e( 'Generate cards', 'tbt-flashcards' ); ?></button>
                            <span class="tbtfc-gen-count" data-tbtfc-count><strong>0</strong> <?php esc_html_e( 'new items', 'tbt-flashcards' ); ?></span>
                        </div>
                        <p class="tbtfc-error" data-tbtfc-gen-error role="alert" hidden></p>

                        <div class="tbtfc-review" data-tbtfc-review hidden>
                            <div class="tbtfc-review__head">
                                <h3 class="tbtfc-review__title"><?php esc_html_e( 'Check the cards', 'tbt-flashcards' ); ?></h3>
                                <p class="tbtfc-help" data-tbtfc-review-count></p>
                            </div>
                            <div class="tbtfc-cols" aria-hidden="true">
                                <span></span>
                                <span><?php esc_html_e( 'Word', 'tbt-flashcards' ); ?></span>
                                <span><?php esc_html_e( 'Phonetic', 'tbt-flashcards' ); ?></span>
                                <span><?php esc_html_e( 'Translation', 'tbt-flashcards' ); ?></span>
                                <span><?php esc_html_e( 'Example', 'tbt-flashcards' ); ?></span>
                                <span></span>
                            </div>
                            <div class="tbtfc-rows" data-tbtfc-rows></div>
                        </div>
                        <div class="tbtfc-review__foot">
                            <button type="button" class="tbtfc-btn" data-tbtfc-add>+ <?php esc_html_e( 'Add card', 'tbt-flashcards' ); ?></button>
                            <p class="tbtfc-help tbtfc-help--inline" data-tbtfc-review-note hidden><?php esc_html_e( "Generating again adds new lines to the end and keeps the cards you've already checked.", 'tbt-flashcards' ); ?></p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="tbtfc-stage" data-tbtfc-stage="3" data-state="waiting">
                <div class="tbtfc-stage__card">
                    <h2 class="tbtfc-stage__title"><b>3.</b> <?php esc_html_e( 'Save and use', 'tbt-flashcards' ); ?></h2>
                    <p class="tbtfc-stage__wait"><?php esc_html_e( 'Unlocks once the set has a title and at least one card.', 'tbt-flashcards' ); ?></p>
                    <div class="tbtfc-stage__body">
                        <p class="tbtfc-stage__sub"><?php esc_html_e( "Saving keeps the same shortcode, so the set updates everywhere it's already embedded.", 'tbt-flashcards' ); ?></p>
                        <div class="tbtfc-gen-row tbtfc-gen-row--save">
                            <button type="button" class="tbtfc-button tbtfc-button--primary" data-tbtfc-save><?php esc_html_e( 'Save set', 'tbt-flashcards' ); ?></button>
                            <span class="tbtfc-gen-count" data-tbtfc-saved-at></span>
                        </div>
                        <p class="tbtfc-error" data-tbtfc-save-error role="alert" hidden></p>

                        <div class="tbtfc-saved-wrap">
                        <div class="tbtfc-saved" data-tbtfc-saved hidden>
                            <div class="tbtfc-use">
                                <h3><?php esc_html_e( 'Put it in a lesson', 'tbt-flashcards' ); ?></h3>
                                <p class="tbtfc-help"><?php esc_html_e( 'Paste into a Divi text or code module.', 'tbt-flashcards' ); ?></p>
                                <div class="tbtfc-copyrow">
                                    <input class="tbtfc-input tbtfc-input--code" readonly data-tbtfc-shortcode aria-label="<?php esc_attr_e( 'Shortcode', 'tbt-flashcards' ); ?>">
                                    <button type="button" class="tbtfc-btn" data-tbtfc-copy-from="shortcode"><?php esc_html_e( 'Copy', 'tbt-flashcards' ); ?></button>
                                </div>
                                <h3 class="tbtfc-use__gap"><?php esc_html_e( 'Share the deck', 'tbt-flashcards' ); ?></h3>
                                <p class="tbtfc-help"><?php esc_html_e( 'Opens full screen, with no sidebar.', 'tbt-flashcards' ); ?></p>
                                <div class="tbtfc-copyrow">
                                    <input class="tbtfc-input tbtfc-input--code" readonly data-tbtfc-link aria-label="<?php esc_attr_e( 'Deck link', 'tbt-flashcards' ); ?>">
                                    <button type="button" class="tbtfc-btn" data-tbtfc-copy-from="link"><?php esc_html_e( 'Copy', 'tbt-flashcards' ); ?></button>
                                    <a class="tbtfc-btn" data-tbtfc-open href="#" target="_blank" rel="noopener"><?php esc_html_e( 'Open', 'tbt-flashcards' ); ?></a>
                                </div>
                            </div>
                            <div class="tbtfc-use">
                                <h3><?php esc_html_e( 'Export CSV', 'tbt-flashcards' ); ?></h3>
                                <p class="tbtfc-help"><?php echo wp_kses( __( 'In the same format as your existing files: <code>word,phonetic,translation,example</code>, UTF-8.', 'tbt-flashcards' ), array( 'code' => array() ) ); ?></p>
                                <div class="tbtfc-copyrow">
                                    <a class="tbtfc-btn" data-tbtfc-export href="#" download><?php esc_html_e( 'Export CSV', 'tbt-flashcards' ); ?></a>
                                    <button type="button" class="tbtfc-btn tbtfc-btn--ghost" data-tbtfc-copy-csv><?php esc_html_e( 'Copy CSV text', 'tbt-flashcards' ); ?></button>
                                </div>
                                <pre class="tbtfc-csv" data-tbtfc-csv aria-label="<?php esc_attr_e( 'CSV preview', 'tbt-flashcards' ); ?>"></pre>
                            </div>
                        </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
        <div class="tbtfc-toast" data-tbtfc-toast role="status" aria-live="polite"></div>
    </div>
    <?php
    return ob_get_clean();
}
