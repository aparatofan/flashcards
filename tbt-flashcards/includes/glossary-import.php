<?php
/**
 * wpDataTables glossary import (2.4.0).
 *
 * A one-off admin screen (Flashcards → Import glossaries) reads every old
 * lesson glossary from its source (Google Sheet, Excel/CSV file in uploads,
 * or a wpDataTables manual table), stores its rows on the lesson
 * (_tbt_fc_glossary) and swaps the shortcode for
 * [tbt_flashcards_glossary source="post"]. The old content is kept once in
 * _tbt_fc_wdt_backup for Undo.
 *
 * wpDataTables' tables are only ever read; its PHP code is never called.
 * When wpDataTables is inactive, [wpdatatable] renders nothing for visitors
 * (a grey note for admins) instead of raw shortcode text.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TBT_FC_WDT_MAX_ROWS', 2000 );
define( 'TBT_FC_WDT_PAGE', 'tbt-fc-import-glossaries' );

/* ---------- Readers ---------- */

/**
 * A wpDataTables table's settings row, or null.
 *
 * @param int $table_id Table ID.
 * @return array|null
 */
function tbt_fc_wdt_table_row( $table_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'wpdatatables';
    $prev  = $wpdb->suppress_errors( true );
    $row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, title, table_type, content, mysql_table_name FROM `{$table}` WHERE id = %d", $table_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->suppress_errors( $prev );
    return $row ? $row : null;
}

/**
 * The path shown in notes: from /uploads/ on when there is one.
 *
 * @param string $path Path or URL.
 * @return string
 */
function tbt_fc_wdt_short_path( $path ) {
    $at = stripos( (string) $path, '/uploads/' );
    return false === $at ? (string) $path : '…' . substr( (string) $path, $at );
}

/**
 * Read a glossary table from its source.
 *
 * Never throws: a failure is ok = false with a plain note. Results are kept
 * in a transient for an hour, so a rescan or the import does not fetch
 * Google again.
 *
 * @param int  $table_id Table ID.
 * @param bool $fresh    Ignore the transient.
 * @return array { ok, rows, type, title, source, note, header, columns }
 */
function tbt_fc_wdt_read( $table_id, $fresh = false ) {
    $table_id = (int) $table_id;
    $key      = 'tbt_fc_wdt_' . $table_id;
    if ( ! $fresh ) {
        $cached = get_transient( $key );
        if ( is_array( $cached ) && isset( $cached['ok'] ) ) {
            return $cached;
        }
    }

    try {
        $result = tbt_fc_wdt_read_source( $table_id );
    } catch ( Throwable $e ) {
        $result = array( 'ok' => false, 'rows' => array(), 'note' => 'Could not read the table: ' . $e->getMessage() );
    }
    $result = array_merge(
        array( 'ok' => false, 'rows' => array(), 'type' => '', 'title' => '', 'source' => '', 'note' => '', 'header' => null, 'columns' => array() ),
        $result
    );

    set_transient( $key, $result, HOUR_IN_SECONDS );
    return $result;
}

/**
 * The uncached read behind tbt_fc_wdt_read().
 *
 * @param int $table_id Table ID.
 * @return array
 */
function tbt_fc_wdt_read_source( $table_id ) {
    $row = tbt_fc_wdt_table_row( $table_id );
    if ( ! $row ) {
        return array( 'note' => sprintf( 'wpDataTables has no table %d', $table_id ) );
    }

    $type   = (string) $row['table_type'];
    $base   = array( 'type' => $type, 'title' => (string) $row['title'] );
    $header = true;

    switch ( $type ) {
        case 'manual':
            $raw    = tbt_fc_wdt_read_manual( (string) $row['mysql_table_name'] );
            $header = false; // Column names live in the schema, not in a row.
            $source = (string) $row['mysql_table_name'];
            break;
        case 'xls':
            $raw    = tbt_fc_wdt_read_file( (string) $row['content'] );
            $source = (string) $row['content'];
            break;
        case 'google_spreadsheet':
            $raw    = tbt_fc_wdt_read_google( (string) $row['content'] );
            $source = (string) $row['content'];
            break;
        default:
            return array_merge( $base, array( 'note' => 'Unknown table type' . ( '' !== $type ? ' (' . $type . ')' : '' ) ) );
    }

    $base['source'] = $source;
    if ( is_string( $raw ) ) {
        return array_merge( $base, array( 'note' => $raw ) );
    }

    $picked = tbt_fc_wdt_two_columns( array_slice( $raw, 0, TBT_FC_WDT_MAX_ROWS + 1 ), $header );
    $rows   = tbt_fc_wdt_clean_rows( $picked['rows'] );
    if ( ! $rows ) {
        return array_merge( $base, array( 'note' => 'The table is empty' ) );
    }

    return array_merge(
        $base,
        array(
            'ok'      => true,
            'rows'    => $rows,
            'header'  => $picked['header_dropped'],
            'columns' => $picked['columns_used'],
        )
    );
}

/**
 * Rows of a wpDataTables manual table, without its wdt_ columns.
 *
 * @param string $name MySQL table name.
 * @return array[]|string Rows, or a note.
 */
function tbt_fc_wdt_read_manual( $name ) {
    global $wpdb;
    if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $name ) ) {
        return sprintf( 'The data table name “%s” is not valid', $name );
    }
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) !== $name ) {
        return sprintf( 'The data table %s no longer exists', $name );
    }

    $prev = $wpdb->suppress_errors( true );
    $data = $wpdb->get_results( "SELECT * FROM `{$name}` ORDER BY wdt_ID LIMIT " . (int) TBT_FC_WDT_MAX_ROWS, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    if ( $wpdb->last_error ) {
        $data = $wpdb->get_results( "SELECT * FROM `{$name}` LIMIT " . (int) TBT_FC_WDT_MAX_ROWS, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
    $wpdb->suppress_errors( $prev );
    if ( ! is_array( $data ) ) {
        return sprintf( 'The data table %s could not be read', $name );
    }

    $rows = array();
    foreach ( $data as $record ) {
        $cells = array();
        foreach ( $record as $column => $value ) {
            if ( 0 === stripos( (string) $column, 'wdt_' ) ) {
                continue;
            }
            $cells[] = null === $value ? '' : (string) $value;
        }
        $rows[] = $cells;
    }
    return $rows;
}

/**
 * Rows of an Excel (.xlsx) or CSV file in uploads.
 *
 * @param string $content Path or URL as stored by wpDataTables.
 * @return array[]|string Rows, or a note.
 */
function tbt_fc_wdt_read_file( $content ) {
    $uploads = wp_upload_dir( null, false );
    $path    = tbt_fc_upload_path( $content, $uploads['basedir'], $uploads['baseurl'] );
    if ( '' === $path ) {
        return sprintf( 'Not a file in this site\'s uploads: %s', $content );
    }

    $real    = realpath( $path );
    $basedir = realpath( $uploads['basedir'] );
    if ( ! $real || ! $basedir || 0 !== strpos( $real, $basedir . DIRECTORY_SEPARATOR ) || ! is_readable( $real ) ) {
        return sprintf( 'File not found: %s', tbt_fc_wdt_short_path( $path ) );
    }

    $ext = strtolower( pathinfo( $real, PATHINFO_EXTENSION ) );
    if ( 'csv' === $ext ) {
        return tbt_fc_wdt_csv_rows( (string) file_get_contents( $real ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    }
    if ( 'xlsx' !== $ext ) {
        return 'xls' === $ext ? 'Old .xls format: please re-save as .xlsx' : sprintf( 'Unsupported file type: .%s', $ext );
    }
    if ( ! class_exists( 'ZipArchive' ) || ! function_exists( 'simplexml_load_string' ) ) {
        return 'This server cannot read .xlsx files (PHP zip or SimpleXML missing)';
    }

    $zip = new ZipArchive();
    if ( true !== $zip->open( $real ) ) {
        return sprintf( 'Not a valid .xlsx file: %s', tbt_fc_wdt_short_path( $path ) );
    }
    $sheet = tbt_fc_xlsx_first_sheet( (string) $zip->getFromName( 'xl/workbook.xml' ), (string) $zip->getFromName( 'xl/_rels/workbook.xml.rels' ) );
    $xml   = $zip->getFromName( $sheet );
    $rows  = false === $xml ? null : tbt_fc_xlsx_rows( (string) $zip->getFromName( 'xl/sharedStrings.xml' ), $xml, TBT_FC_WDT_MAX_ROWS + 1 );
    $zip->close();

    return null === $rows ? sprintf( 'The first sheet of %s could not be read', tbt_fc_wdt_short_path( $path ) ) : $rows;
}

/**
 * Rows of a Google Sheet, through its public CSV export.
 *
 * @param string $url Sheet URL.
 * @return array[]|string Rows, or a note.
 */
function tbt_fc_wdt_read_google( $url ) {
    $csv = tbt_fc_google_csv_url( $url );
    if ( '' === $csv ) {
        return sprintf( 'Not a Google Sheets link: %s', $url );
    }

    $response = wp_remote_get( $csv, array( 'timeout' => 15, 'redirection' => 5 ) );
    $code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
    $body     = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response );
    $type     = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_header( $response, 'content-type' );

    if ( 200 === $code && false === stripos( $type, 'text/html' ) && ! preg_match( '/^\s*<(?:!doctype|html)/i', $body ) ) {
        return tbt_fc_wdt_csv_rows( $body );
    }

    // wp_wpdatatables_cache was empty on the live site (Phase 0), so there
    // is no fallback here.
    if ( is_wp_error( $response ) ) {
        return sprintf( 'Google Sheet can\'t be read (%s)', $response->get_error_message() );
    }
    return sprintf( 'Google Sheet can\'t be read (not shared publicly?) — HTTP %d', $code );
}

/* ---------- Lessons ---------- */

/**
 * Glossary table IDs of a content: its wpDataTables minus the never-list.
 *
 * @param string $content Post content.
 * @return int[]
 */
function tbt_fc_wdt_glossary_ids( $content ) {
    return array_values( array_diff( tbt_fc_wdt_ids( $content ), TBT_FC_WDT_NEVER ) );
}

/**
 * Lessons (posts, not trash) that still use a wpDataTables glossary, and
 * lessons that have something to undo.
 *
 * @return array { lessons: array[], tables: int[] }
 */
function tbt_fc_wdt_scan() {
    global $wpdb;

    $lessons = array();
    $tables  = array();
    $like    = '%' . $wpdb->esc_like( '[wpdatatable' ) . '%';
    $found   = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status NOT IN ('trash','auto-draft','inherit') AND post_content LIKE %s ORDER BY post_title", $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    foreach ( $found as $post ) {
        $ids = tbt_fc_wdt_glossary_ids( $post->post_content );
        if ( ! $ids ) {
            continue;
        }
        $items = array();
        foreach ( $ids as $id ) {
            $items[]  = array( 'table' => $id, 'global' => tbt_fc_wdt_global_module( $post->post_content, $id ) );
            $tables[] = $id;
        }
        $lessons[ (int) $post->ID ] = tbt_fc_wdt_lesson_info( (int) $post->ID, $items ) + tbt_fc_wdt_set_links( $post->post_content );
    }

    $backed = $wpdb->get_col( "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_tbt_fc_wdt_backup' AND p.post_type = 'post'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    foreach ( $backed as $post_id ) {
        $post_id = (int) $post_id;
        $info    = isset( $lessons[ $post_id ] ) ? $lessons[ $post_id ] : tbt_fc_wdt_lesson_info( $post_id, array() );
        $info['imported'] = tbt_fc_wdt_imported_info( $post_id );
        foreach ( $info['imported']['tables'] as $id ) {
            $tables[] = $id;
        }
        $lessons[ $post_id ] = $info;
    }

    return array(
        'lessons' => array_values( $lessons ),
        'tables'  => array_values( array_unique( $tables ) ),
    );
}

/**
 * Quizlet sets and Multidecker lists linked in a lesson (Paste words).
 *
 * @param string $content Post content.
 * @return array { quizlet: string[], multidecker: string[] }
 */
function tbt_fc_wdt_set_links( $content ) {
    return array(
        'quizlet'     => tbt_fc_quizlet_ids( $content ),
        'multidecker' => tbt_fc_multidecker_slugs( $content ),
    );
}

/**
 * A lesson as the import screen shows it.
 *
 * @param int   $post_id Post ID.
 * @param array $items   Its glossary tables.
 * @return array
 */
function tbt_fc_wdt_lesson_info( $post_id, array $items ) {
    $post = get_post( $post_id );
    return array(
        'post'     => $post_id,
        'title'    => $post ? html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) : '#' . $post_id,
        'status'   => $post ? $post->post_status : '',
        'edit'     => (string) get_edit_post_link( $post_id, 'raw' ),
        'view'     => (string) get_permalink( $post_id ),
        'items'    => $items,
        'imported' => null,
    );
}

/**
 * What an imported lesson holds: its tables and row count.
 *
 * @param int $post_id Post ID.
 * @return array { tables: int[], rows: int, at: string }
 */
function tbt_fc_wdt_imported_info( $post_id ) {
    $src  = json_decode( (string) get_post_meta( $post_id, '_tbt_fc_glossary_src', true ), true );
    $list = is_array( $src ) && isset( $src['table_id'] ) ? array( $src ) : ( is_array( $src ) ? $src : array() );
    $ids  = array();
    $at   = '';
    foreach ( $list as $one ) {
        if ( is_array( $one ) && isset( $one['table_id'] ) ) {
            $ids[] = (int) $one['table_id'];
            $at    = isset( $one['imported_at'] ) ? (string) $one['imported_at'] : $at;
        }
    }
    $rows = tbt_fc_glossary_decode_stored( (string) get_post_meta( $post_id, '_tbt_fc_glossary', true ) );
    return array( 'tables' => $ids, 'rows' => count( $rows ), 'at' => $at );
}

/**
 * Domain colour of a lesson from its top-level category (Yoast's primary
 * category first, when set).
 *
 * @param int $post_id Post ID.
 * @return string le, gi, pd or ''.
 */
function tbt_fc_lesson_domain( $post_id ) {
    $ids     = array();
    $primary = (int) get_post_meta( $post_id, '_yoast_wpseo_primary_category', true );
    if ( $primary > 0 ) {
        $ids[] = $primary;
    }
    $ids = array_merge( $ids, wp_get_post_categories( $post_id ) );

    foreach ( array_unique( array_map( 'intval', $ids ) ) as $term_id ) {
        $ancestors = get_ancestors( $term_id, 'category', 'taxonomy' );
        $top       = get_term( $ancestors ? (int) end( $ancestors ) : $term_id, 'category' );
        if ( $top && ! is_wp_error( $top ) ) {
            $domain = tbt_fc_domain_for_category( (int) $top->term_id, (string) $top->name, (string) $top->slug );
            if ( '' !== $domain ) {
                return $domain;
            }
        }
    }
    return '';
}

/**
 * Import one lesson's ticked glossary tables.
 *
 * The rows are read on the server (transient, else source), never taken
 * from the browser. The content is saved with $wpdb (no revision, no
 * save_post), and only post_content plus this plugin's meta (and Divi's
 * _et_pb_old_content copy, when it carries the same table) change.
 *
 * @param int   $post_id   Post ID.
 * @param int[] $table_ids Ticked tables, in order.
 * @return array { post, ok, note, rows }
 */
function tbt_fc_wdt_import_post( $post_id, array $table_ids ) {
    $fail = function ( $note ) use ( $post_id ) {
        return array( 'post' => $post_id, 'ok' => false, 'note' => $note );
    };

    $post = get_post( $post_id );
    if ( ! $post || 'post' !== $post->post_type || 'trash' === $post->post_status ) {
        return $fail( 'Not a lesson' );
    }
    $content = (string) $post->post_content;
    $present = tbt_fc_wdt_glossary_ids( $content );
    $tables  = array_values( array_intersect( array_unique( array_map( 'intval', $table_ids ) ), $present ) );
    if ( ! $tables ) {
        return $fail( 'The lesson no longer uses these tables' );
    }

    $rows    = array();
    $sources = array();
    $now     = gmdate( 'c' );
    foreach ( $tables as $id ) {
        $global = tbt_fc_wdt_global_module( $content, $id );
        if ( $global ) {
            return $fail( sprintf( 'Table %d is inside a Divi global module (library item %d): fix by hand', $id, $global ) );
        }
        $read = tbt_fc_wdt_read( $id );
        if ( empty( $read['ok'] ) ) {
            return $fail( sprintf( 'Table %d: %s', $id, $read['note'] ) );
        }
        $rows      = array_merge( $rows, $read['rows'] );
        $sources[] = array(
            'table_id'    => $id,
            'type'        => (string) $read['type'],
            'source'      => (string) $read['source'],
            'rows'        => count( $read['rows'] ),
            'imported_at' => $now,
        );
    }

    return tbt_fc_wdt_store_rows( $post_id, $content, $tables, $rows, $sources );
}

/**
 * Store rows on one lesson: the import step shared by the wpDataTables
 * import and Paste words (2.4.1).
 *
 * Rows already stored on the lesson come first; the old content is backed
 * up once; the first table gets [tbt_flashcards_glossary source="post"],
 * the others go; the content is saved with $wpdb (no revision, no
 * save_post), and Divi's _et_pb_old_content copy likewise when it carries
 * the same table.
 *
 * @param int     $post_id Post ID.
 * @param string  $content The lesson's current post_content.
 * @param int[]   $tables  Glossary tables to replace, in order (non-empty).
 * @param array[] $rows    New rows.
 * @param array[] $sources Source entries for _tbt_fc_glossary_src.
 * @return array { post, ok, note, rows, imported }
 */
function tbt_fc_wdt_store_rows( $post_id, $content, array $tables, array $rows, array $sources ) {
    global $wpdb;

    // Rows already stored on the lesson (an earlier import) come first.
    $stored = tbt_fc_glossary_decode_stored( (string) get_post_meta( $post_id, '_tbt_fc_glossary', true ) );
    $rows   = tbt_fc_wdt_clean_rows( array_merge( $stored, $rows ) );
    $old    = json_decode( (string) get_post_meta( $post_id, '_tbt_fc_glossary_src', true ), true );
    if ( is_array( $old ) && $stored ) {
        $sources = array_merge( isset( $old['table_id'] ) ? array( $old ) : $old, $sources );
    }

    // 1. Backup, written once.
    if ( '' === (string) get_post_meta( $post_id, '_tbt_fc_wdt_backup', true ) ) {
        add_post_meta( $post_id, '_tbt_fc_wdt_backup', wp_slash( $content ), true );
    }

    // 3. One glossary: the first ticked table gets the shortcode, others go.
    $domain    = tbt_fc_lesson_domain( $post_id );
    $shortcode = '[tbt_flashcards_glossary source="post"' . ( $domain ? ' domain="' . $domain . '"' : '' ) . ']';
    $new       = $content;
    foreach ( $tables as $i => $id ) {
        $new = tbt_fc_replace_wdt( $new, $id, 0 === $i ? $shortcode : '' );
    }

    // 2. Rows and source.
    update_post_meta( $post_id, '_tbt_fc_glossary', wp_slash( wp_json_encode( $rows, JSON_UNESCAPED_UNICODE ) ) );
    update_post_meta( $post_id, '_tbt_fc_glossary_src', wp_slash( wp_json_encode( 1 === count( $sources ) ? $sources[0] : $sources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );

    // 4. Save without a revision or save_post.
    if ( false === $wpdb->update( $wpdb->posts, array( 'post_content' => $new ), array( 'ID' => $post_id ) ) ) {
        return array( 'post' => $post_id, 'ok' => false, 'note' => 'The lesson could not be saved' );
    }

    // Divi's pre-builder copy, when it carries the same table.
    $divi = get_post_meta( $post_id, '_et_pb_old_content', true );
    if ( is_string( $divi ) && '' !== $divi ) {
        $divi_new = $divi;
        foreach ( $tables as $i => $id ) {
            $divi_new = tbt_fc_replace_wdt( $divi_new, $id, 0 === $i ? $shortcode : '' );
        }
        if ( $divi_new !== $divi ) {
            if ( '' === (string) get_post_meta( $post_id, '_tbt_fc_wdt_backup_old', true ) ) {
                add_post_meta( $post_id, '_tbt_fc_wdt_backup_old', wp_slash( $divi ), true );
            }
            update_post_meta( $post_id, '_et_pb_old_content', wp_slash( $divi_new ) );
        }
    }

    tbt_fc_wdt_after_change( $post_id );

    return array(
        'post'     => $post_id,
        'ok'       => true,
        'note'     => '',
        'rows'     => count( $rows ),
        'imported' => tbt_fc_wdt_imported_info( $post_id ),
    );
}

/**
 * Undo one lesson's import: restore the backed-up content and delete the
 * glossary meta.
 *
 * @param int $post_id Post ID.
 * @return array { post, ok, note, items }
 */
function tbt_fc_wdt_undo_post( $post_id ) {
    global $wpdb;
    $post   = get_post( $post_id );
    $backup = get_post_meta( $post_id, '_tbt_fc_wdt_backup', true );
    if ( ! $post || 'post' !== $post->post_type || ! is_string( $backup ) || '' === $backup ) {
        return array( 'post' => $post_id, 'ok' => false, 'note' => 'Nothing to undo' );
    }

    if ( false === $wpdb->update( $wpdb->posts, array( 'post_content' => $backup ), array( 'ID' => $post_id ) ) ) {
        return array( 'post' => $post_id, 'ok' => false, 'note' => 'The lesson could not be saved' );
    }
    $divi = get_post_meta( $post_id, '_tbt_fc_wdt_backup_old', true );
    if ( is_string( $divi ) && '' !== $divi ) {
        update_post_meta( $post_id, '_et_pb_old_content', wp_slash( $divi ) );
    }
    foreach ( array( '_tbt_fc_wdt_backup', '_tbt_fc_wdt_backup_old', '_tbt_fc_glossary', '_tbt_fc_glossary_src' ) as $key ) {
        delete_post_meta( $post_id, $key );
    }

    tbt_fc_wdt_after_change( $post_id );

    $items = array();
    foreach ( tbt_fc_wdt_glossary_ids( $backup ) as $id ) {
        $items[] = array( 'table' => $id, 'global' => tbt_fc_wdt_global_module( $backup, $id ) );
    }
    return array( 'post' => $post_id, 'ok' => true, 'note' => '', 'items' => $items ) + tbt_fc_wdt_set_links( $backup );
}

/**
 * Caches to clear after a lesson changed.
 *
 * @param int $post_id Post ID.
 */
function tbt_fc_wdt_after_change( $post_id ) {
    clean_post_cache( $post_id );
    delete_transient( 'tbt_fc_wdt_menu' );
    do_action( 'litespeed_purge_post', $post_id ); // No-op without LiteSpeed Cache.
}

/* ---------- REST ---------- */

/**
 * Register the import routes.
 */
function tbt_fc_wdt_register_routes() {
    $admin = function () {
        return current_user_can( 'manage_options' )
            ? true
            : new WP_Error( 'tbt_fc_forbidden', 'Only administrators can import glossaries.', array( 'status' => rest_authorization_required_code() ) );
    };

    register_rest_route( TBT_FC_REST_NAMESPACE, '/glossary-import/read', array(
        'methods'             => 'POST',
        'callback'            => 'tbt_fc_wdt_rest_read',
        'permission_callback' => $admin,
    ) );
    register_rest_route( TBT_FC_REST_NAMESPACE, '/glossary-import/run', array(
        'methods'             => 'POST',
        'callback'            => 'tbt_fc_wdt_rest_run',
        'permission_callback' => $admin,
    ) );
    register_rest_route( TBT_FC_REST_NAMESPACE, '/glossary-import/undo', array(
        'methods'             => 'POST',
        'callback'            => 'tbt_fc_wdt_rest_undo',
        'permission_callback' => $admin,
    ) );
    register_rest_route( TBT_FC_REST_NAMESPACE, '/glossary-import/paste', array(
        'methods'             => 'POST',
        'callback'            => 'tbt_fc_wdt_rest_paste',
        'permission_callback' => $admin,
    ) );
    register_rest_route( TBT_FC_REST_NAMESPACE, '/glossary-import/rescan', array(
        'methods'             => 'POST',
        'callback'            => 'tbt_fc_wdt_rest_rescan',
        'permission_callback' => $admin,
    ) );
}
add_action( 'rest_api_init', 'tbt_fc_wdt_register_routes' );

/**
 * Positive integers from a request list, at most $max.
 *
 * @param mixed $value Raw value.
 * @param int   $max   Limit.
 * @return int[]|WP_Error
 */
function tbt_fc_wdt_int_list( $value, $max ) {
    if ( ! is_array( $value ) ) {
        return new WP_Error( 'tbt_fc_bad_request', 'Send a list of IDs.', array( 'status' => 400 ) );
    }
    $ids = array_values( array_unique( array_filter( array_map( 'absint', $value ) ) ) );
    if ( count( $ids ) > $max ) {
        return new WP_Error( 'tbt_fc_bad_request', sprintf( 'Send at most %d at a time.', $max ), array( 'status' => 400 ) );
    }
    return $ids;
}

/**
 * POST glossary-import/read {ids: int[≤10]}: read tables, return summaries.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function tbt_fc_wdt_rest_read( WP_REST_Request $request ) {
    $ids = tbt_fc_wdt_int_list( $request->get_param( 'ids' ), 10 );
    if ( is_wp_error( $ids ) ) {
        return $ids;
    }
    $out = array();
    foreach ( $ids as $id ) {
        $r       = tbt_fc_wdt_read( $id );
        $columns = array_map( function ( $c ) {
            return $c + 1;
        }, (array) $r['columns'] );
        $out[]   = array(
            'id'      => $id,
            'ok'      => (bool) $r['ok'],
            'title'   => html_entity_decode( (string) $r['title'], ENT_QUOTES, 'UTF-8' ),
            'type'    => (string) $r['type'],
            'rows'    => count( $r['rows'] ),
            'preview' => array_slice( $r['rows'], 0, 3 ),
            'header'  => $r['header'],
            'columns' => array( 1, 2 ) === $columns || array( 1 ) === $columns ? null : $columns,
            'note'    => (string) $r['note'],
        );
    }
    return rest_ensure_response( array( 'tables' => $out ) );
}

/**
 * POST glossary-import/run {items: [{post, tables: int[]}] ≤20}.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function tbt_fc_wdt_rest_run( WP_REST_Request $request ) {
    $items = $request->get_param( 'items' );
    if ( ! is_array( $items ) || count( $items ) > 20 ) {
        return new WP_Error( 'tbt_fc_bad_request', 'Send at most 20 lessons at a time.', array( 'status' => 400 ) );
    }
    $out = array();
    foreach ( $items as $item ) {
        $post_id = is_array( $item ) && isset( $item['post'] ) ? absint( $item['post'] ) : 0;
        $tables  = is_array( $item ) && isset( $item['tables'] ) ? tbt_fc_wdt_int_list( $item['tables'], 20 ) : array();
        if ( ! $post_id || is_wp_error( $tables ) || ! $tables ) {
            $out[] = array( 'post' => $post_id, 'ok' => false, 'note' => 'Bad request' );
            continue;
        }
        $out[] = tbt_fc_wdt_import_post( $post_id, $tables );
    }
    return rest_ensure_response( array( 'results' => $out ) );
}

/**
 * POST glossary-import/undo {posts: int[≤20]}.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function tbt_fc_wdt_rest_undo( WP_REST_Request $request ) {
    $ids = tbt_fc_wdt_int_list( $request->get_param( 'posts' ), 20 );
    if ( is_wp_error( $ids ) ) {
        return $ids;
    }
    return rest_ensure_response( array( 'results' => array_map( 'tbt_fc_wdt_undo_post', $ids ) ) );
}

/**
 * POST glossary-import/paste {post_id, text}: store words pasted from a
 * Quizlet export (or any word list) in a lesson that still shows a
 * wpDataTables glossary, exactly as the import stores read rows.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function tbt_fc_wdt_rest_paste( WP_REST_Request $request ) {
    $post_id = absint( $request->get_param( 'post_id' ) );
    $text    = $request->get_param( 'text' );
    $text    = is_string( $text ) ? $text : '';

    $post    = $post_id ? get_post( $post_id ) : null;
    $content = $post ? (string) $post->post_content : '';
    $tables  = tbt_fc_wdt_glossary_ids( $content );
    if ( ! $post || 'post' !== $post->post_type || 'trash' === $post->post_status || ! $tables ) {
        return new WP_Error( 'tbt_fc_no_glossary', 'This lesson has no old glossary left.', array( 'status' => 409 ) );
    }
    foreach ( $tables as $id ) {
        $global = tbt_fc_wdt_global_module( $content, $id );
        if ( $global ) {
            return new WP_Error( 'tbt_fc_global_module', sprintf( 'Table %d is inside a Divi global module (library item %d): fix by hand.', $id, $global ), array( 'status' => 409 ) );
        }
    }

    if ( count( tbt_fc_paste_lines( $text ) ) > TBT_FC_WDT_MAX_ROWS ) {
        return new WP_Error( 'tbt_fc_too_long', sprintf( 'Paste at most %d lines at a time.', TBT_FC_WDT_MAX_ROWS ), array( 'status' => 400 ) );
    }
    $picked = tbt_fc_wdt_two_columns( tbt_fc_paste_pairs( tbt_fc_parse_pasted( $text ) ) );
    $rows   = tbt_fc_paste_limit( tbt_fc_wdt_clean_rows( $picked['rows'] ) );
    if ( ! $rows ) {
        return new WP_Error( 'tbt_fc_no_words', 'No words found. Paste the Quizlet export: one word per line, word and translation separated by a tab.', array( 'status' => 400 ) );
    }

    $quizlet = tbt_fc_quizlet_ids( $content );
    $source  = array(
        'table_id'    => $tables[0],
        'type'        => 'paste',
        'source'      => $quizlet ? 'quizlet:' . implode( ',', $quizlet ) : 'paste',
        'rows'        => count( $rows ),
        'imported_at' => gmdate( 'c' ),
    );
    $result  = tbt_fc_wdt_store_rows( $post_id, $content, $tables, $rows, array( $source ) );
    if ( empty( $result['ok'] ) ) {
        return new WP_Error( 'tbt_fc_save_failed', $result['note'], array( 'status' => 500 ) );
    }
    return rest_ensure_response( array(
        'rows'     => count( $rows ),
        'imported' => true,
        'lesson'   => $result['imported'],
    ) );
}

/**
 * POST glossary-import/rescan: forget every cached read.
 *
 * @return WP_REST_Response
 */
function tbt_fc_wdt_rest_rescan() {
    $scan = tbt_fc_wdt_scan();
    foreach ( $scan['tables'] as $id ) {
        delete_transient( 'tbt_fc_wdt_' . (int) $id );
    }
    delete_transient( 'tbt_fc_wdt_menu' );
    return rest_ensure_response( array( 'cleared' => count( $scan['tables'] ) ) );
}

/* ---------- Admin screen ---------- */

/**
 * Whether the menu item shows: some lesson still uses a wpDataTables
 * glossary, or some import can be undone. Cached for an hour; cleared when
 * a post is saved, imported or undone.
 *
 * @return bool
 */
function tbt_fc_wdt_menu_needed() {
    $cached = get_transient( 'tbt_fc_wdt_menu' );
    if ( 'yes' === $cached || 'no' === $cached ) {
        return 'yes' === $cached;
    }
    $scan   = tbt_fc_wdt_scan();
    $needed = ! empty( $scan['lessons'] );
    set_transient( 'tbt_fc_wdt_menu', $needed ? 'yes' : 'no', HOUR_IN_SECONDS );
    return $needed;
}

/**
 * Forget the menu decision when a lesson is saved.
 */
function tbt_fc_wdt_forget_menu() {
    delete_transient( 'tbt_fc_wdt_menu' );
}
add_action( 'save_post_post', 'tbt_fc_wdt_forget_menu' );

/**
 * Flashcards → Import glossaries (under the TBT hub when it is active).
 */
function tbt_fc_wdt_register_page() {
    if ( ! current_user_can( 'manage_options' ) || ! tbt_fc_wdt_menu_needed() ) {
        return;
    }
    $hook = add_submenu_page(
        defined( 'TBT_HUB_SLUG' ) ? TBT_HUB_SLUG : 'edit.php?post_type=tbt_flashcard_set',
        'Import glossaries',
        'Import glossaries',
        'manage_options',
        TBT_FC_WDT_PAGE,
        'tbt_fc_wdt_render_page'
    );
    if ( $hook ) {
        add_action( 'admin_enqueue_scripts', function ( $current ) use ( $hook ) {
            if ( $current === $hook ) {
                tbt_fc_wdt_enqueue_page();
            }
        } );
    }
}
add_action( 'admin_menu', 'tbt_fc_wdt_register_page', 20 );

/**
 * Assets and data for the import screen.
 */
function tbt_fc_wdt_enqueue_page() {
    wp_enqueue_style( 'tbt-fc-glossary-import', TBT_FC_URL . 'assets/css/glossary-import.css', array(), TBT_FC_VERSION );
    wp_enqueue_script( 'tbt-fc-glossary-import', TBT_FC_URL . 'assets/js/glossary-import.js', array(), TBT_FC_VERSION, true );

    $scan = tbt_fc_wdt_scan();
    $data = array(
        'api'     => esc_url_raw( rest_url( TBT_FC_REST_NAMESPACE . '/glossary-import/' ) ),
        'nonce'   => wp_create_nonce( 'wp_rest' ),
        'lessons' => $scan['lessons'],
    );
    wp_add_inline_script( 'tbt-fc-glossary-import', 'window.tbtFcImport = ' . wp_json_encode( $data ) . ';', 'before' );
}

/**
 * The import screen's shell; assets/js/glossary-import.js fills it.
 */
function tbt_fc_wdt_render_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    ?>
<div class="wrap tbt-fc-imp">
    <h1>Import glossaries</h1>
    <nav class="nav-tab-wrapper" aria-label="Import glossaries">
        <a href="#tables" class="nav-tab nav-tab-active" data-tab="tables" aria-current="page">wpDataTables import</a>
        <a href="#paste-words" class="nav-tab" data-tab="paste">Paste words <span class="tbt-fc-imp-badge" id="tbt-fc-paste-badge"></span></a>
    </nav>
    <div class="tbt-fc-imp-panel" id="tbt-fc-imp-panel-tables">
    <p class="tbt-fc-imp-intro">Reads every lesson's old wpDataTables glossary (Google Sheet, Excel file or wpDataTables table), stores its rows in the lesson and swaps the shortcode for <code>[tbt_flashcards_glossary source="post"]</code>. Nothing is written until you press <strong>Import selected</strong>, and every import can be undone.</p>
    <div class="tbt-fc-imp-summary">
        <p class="tbt-fc-imp-counts" id="tbt-fc-imp-counts"></p>
        <p>
            <button type="button" class="button button-primary" id="tbt-fc-imp-read">Read all</button>
            <button type="button" class="button" id="tbt-fc-imp-rescan" hidden>Rescan</button>
        </p>
        <div class="tbt-fc-imp-progress" id="tbt-fc-imp-progress" hidden>
            <progress max="1" value="0"></progress> <span></span>
        </div>
        <div class="notice notice-error inline" id="tbt-fc-imp-error" hidden><p></p></div>
    </div>
    <div id="tbt-fc-imp-list" hidden>
        <div class="tbt-fc-imp-tools">
            <div class="tbt-fc-imp-chips" role="group" aria-label="Filter">
                <button type="button" class="button" data-filter="all" aria-pressed="true">All</button>
                <button type="button" class="button" data-filter="ready" aria-pressed="false">Ready</button>
                <button type="button" class="button" data-filter="failed" aria-pressed="false">Failed</button>
            </div>
            <span class="tbt-fc-imp-tally" id="tbt-fc-imp-tally" aria-live="polite"></span>
            <span class="tbt-fc-imp-actions">
                <button type="button" class="button" id="tbt-fc-imp-copy">Copy failed list</button>
                <button type="button" class="button" id="tbt-fc-imp-undo-all">Undo all</button>
                <button type="button" class="button button-primary" id="tbt-fc-imp-run">Import selected</button>
            </span>
        </div>
        <div class="tbt-fc-imp-confirm notice notice-warning inline" id="tbt-fc-imp-confirm" hidden>
            <p><strong></strong> Each lesson gets its old content back and loses its stored glossary.</p>
            <p><button type="button" class="button button-primary" data-confirm="yes">Undo all</button> <button type="button" class="button" data-confirm="no">Cancel</button></p>
        </div>
        <table class="widefat striped tbt-fc-imp-table">
            <thead><tr>
                <td class="check-column"><label class="screen-reader-text" for="tbt-fc-imp-all">Select all ready</label><input type="checkbox" id="tbt-fc-imp-all"></td>
                <th scope="col">Lesson</th>
                <th scope="col">Table</th>
                <th scope="col" class="num">Rows</th>
                <th scope="col">Preview</th>
                <th scope="col">Status</th>
            </tr></thead>
            <tbody id="tbt-fc-imp-rows"></tbody>
        </table>
    </div>
    </div>
    <div class="tbt-fc-imp-panel" id="tbt-fc-imp-panel-paste" hidden>
        <p class="tbt-fc-imp-intro tbt-fc-imp-counts" id="tbt-fc-paste-top"></p>
        <p class="tbt-fc-paste-search">
            <label class="screen-reader-text" for="tbt-fc-paste-search">Search lessons</label>
            <input type="search" id="tbt-fc-paste-search" class="regular-text" placeholder="Search lessons">
        </p>
        <table class="widefat striped tbt-fc-imp-table tbt-fc-paste-table" id="tbt-fc-paste-table">
            <thead><tr>
                <th scope="col">Lesson</th>
                <th scope="col">Quizlet / Multidecker</th>
                <th scope="col">Words</th>
                <td></td>
            </tr></thead>
            <tbody id="tbt-fc-paste-rows"></tbody>
        </table>
        <p class="tbt-fc-paste-none" id="tbt-fc-paste-none" hidden>No lesson matches the search.</p>
        <div class="notice notice-success inline" id="tbt-fc-paste-done" hidden><p>Every lesson's glossary is now stored in the lesson. You can remove wpDataTables once the non-lesson pages are gone.</p></div>
    </div>
</div>
    <?php
}

/* ---------- Safety net ---------- */

/**
 * When wpDataTables is not active, [wpdatatable] renders nothing for
 * visitors and a grey note for admins, instead of raw shortcode text.
 * While wpDataTables is active its own shortcode wins.
 */
function tbt_fc_wdt_register_safety_net() {
    if ( shortcode_exists( 'wpdatatable' ) ) {
        return;
    }
    add_shortcode( 'wpdatatable', 'tbt_fc_wdt_safety_net' );
}
add_action( 'init', 'tbt_fc_wdt_register_safety_net', 20 );

/**
 * The safety-net shortcode.
 *
 * @param array|string $atts Shortcode attributes.
 * @return string
 */
function tbt_fc_wdt_safety_net( $atts ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        return '';
    }
    $id = is_array( $atts ) && isset( $atts['id'] ) ? absint( preg_replace( '/\D/', '', (string) $atts['id'] ) ) : 0;
    return '<p class="tbt-fc-wdt-note" style="margin:0 0 16px;padding:8px 12px;border:1px dashed #c3c4c7;border-radius:6px;background:#f6f7f7;color:#646970;font:400 13px/1.5 system-ui,sans-serif;">'
        . esc_html( sprintf( 'Old wpDataTables table (id %d) — not shown', $id ) )
        . '</p>';
}
