<?php
/**
 * Pure helpers for the wpDataTables glossary import (2.4.0).
 *
 * No WordPress here: shortcode parsing and replacing, Google Sheets CSV
 * links, uploads paths (including the old host's), CSV and .xlsx parsing,
 * column and header choice, and row cleaning. Tested by
 * tests/test-glossary-import.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Table IDs that are never lesson glossaries (catalogues and libraries).
 */
define( 'TBT_FC_WDT_NEVER', array( 117, 185, 277, 278, 280, 281, 282, 289, 291 ) );

/**
 * The regex for one [wpdatatable …] shortcode. Group 1 is its attributes.
 * "[wpdatatable_sum …]" and the like are not matched.
 *
 * @return string
 */
function tbt_fc_wdt_pattern() {
    return '/\[wpdatatable(?=[\s\]])([^\]]*)\]/u';
}

/**
 * The ID in a shortcode's attributes: id=N, id="N", id='N', id=&quot;N&quot;
 * or curly quotes (raw or as entities, which Divi leaves behind).
 *
 * @param string $atts Attribute text.
 * @return int 0 when there is none.
 */
function tbt_fc_wdt_attr_id( $atts ) {
    $quote = '(?:&quot;|&#0?34;|&#0?39;|&#82(?:16|17|20|21|42|43);|&[lr][sd]quo;|["\'\x{2018}\x{2019}\x{201C}\x{201D}\x{2032}\x{2033}])';
    if ( preg_match( '/(?<![\w-])id\s*=\s*' . $quote . '?\s*(\d+)/u', (string) $atts, $m ) ) {
        return (int) $m[1];
    }
    return 0;
}

/**
 * Every wpDataTables ID in the content, in order, without duplicates.
 *
 * @param string $content Post content.
 * @return int[]
 */
function tbt_fc_wdt_ids( $content ) {
    $ids = array();
    if ( preg_match_all( tbt_fc_wdt_pattern(), (string) $content, $matches ) ) {
        foreach ( $matches[1] as $atts ) {
            $id = tbt_fc_wdt_attr_id( $atts );
            if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
                $ids[] = $id;
            }
        }
    }
    return $ids;
}

/**
 * Replace every [wpdatatable …] whose ID is $table_id (and only those).
 *
 * @param string $content  Post content.
 * @param int    $table_id Table ID.
 * @param string $with     Replacement text.
 * @return string
 */
function tbt_fc_replace_wdt( $content, $table_id, $with ) {
    $table_id = (int) $table_id;
    $out      = preg_replace_callback(
        tbt_fc_wdt_pattern(),
        function ( $m ) use ( $table_id, $with ) {
            return tbt_fc_wdt_attr_id( $m[1] ) === $table_id ? $with : $m[0];
        },
        (string) $content
    );
    return null === $out ? (string) $content : $out;
}

/**
 * The Divi library item (global module) a table's shortcode sits in, or 0.
 *
 * Divi renders a global module from the library, not from the post, so a
 * table inside one cannot be fixed by changing the post.
 *
 * @param string $content  Post content.
 * @param int    $table_id Table ID.
 * @return int
 */
function tbt_fc_wdt_global_module( $content, $table_id ) {
    $content = (string) $content;
    $spots   = array();
    if ( preg_match_all( tbt_fc_wdt_pattern(), $content, $matches, PREG_OFFSET_CAPTURE ) ) {
        foreach ( $matches[1] as $i => $atts ) {
            if ( tbt_fc_wdt_attr_id( $atts[0] ) === (int) $table_id ) {
                $spots[] = $matches[0][ $i ][1];
            }
        }
    }
    if ( ! $spots ) {
        return 0;
    }

    if ( ! preg_match_all( '/\[(et_pb_\w+)\b[^\]]*?\bglobal_module=(?:&quot;|&#82(?:21|43);|["\'\x{201D}\x{2033}])?(\d+)/u', $content, $opens, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
        return 0;
    }
    foreach ( $opens as $open ) {
        $start = $open[0][1];
        $close = strpos( $content, '[/' . $open[1][0] . ']', $start );
        if ( false === $close ) {
            continue;
        }
        foreach ( $spots as $at ) {
            if ( $at > $start && $at < $close ) {
                return (int) $open[2][0];
            }
        }
    }
    return 0;
}

/**
 * The CSV export link of a Google Sheet, or '' when it is not one.
 *
 * @param string $url Sheet URL as stored by wpDataTables.
 * @return string
 */
function tbt_fc_google_csv_url( $url ) {
    $url = html_entity_decode( trim( (string) $url ), ENT_QUOTES, 'UTF-8' );
    if ( ! preg_match( '#^(?:https?:)?//docs\.google\.com/spreadsheets/d/(e/)?([A-Za-z0-9_-]+)#i', $url, $m ) ) {
        return '';
    }
    $gid = preg_match( '/[?#&]gid=(\d+)/', $url, $g ) ? $g[1] : '0';

    if ( $m[1] ) {
        return 'https://docs.google.com/spreadsheets/d/e/' . $m[2] . '/pub?gid=' . $gid . '&single=true&output=csv';
    }
    return 'https://docs.google.com/spreadsheets/d/' . $m[2] . '/export?format=csv&gid=' . $gid;
}

/**
 * The local file for an uploads URL or path, or ''.
 *
 * A URL under $baseurl (http or https) maps into $basedir. Any path or URL
 * with /wp-content/uploads/ in it maps the part after that into $basedir,
 * which fixes paths saved on the old host. Paths that climb out (..) give ''.
 *
 * @param string $content A URL or an absolute path.
 * @param string $basedir Uploads folder.
 * @param string $baseurl Uploads URL.
 * @return string
 */
function tbt_fc_upload_path( $content, $basedir, $baseurl ) {
    $value = trim( html_entity_decode( (string) $content, ENT_QUOTES, 'UTF-8' ) );
    $value = preg_replace( '/[?#].*$/s', '', $value );
    if ( '' === $value ) {
        return '';
    }
    $basedir = rtrim( (string) $basedir, '/\\' );
    $rest    = null;

    $base = preg_replace( '#^https?:#i', '', rtrim( (string) $baseurl, '/' ) ) . '/';
    $bare = preg_replace( '#^https?:#i', '', $value );
    if ( '/' !== $base && 0 === stripos( $bare, $base ) ) {
        $rest = substr( $bare, strlen( $base ) );
    } elseif ( false !== ( $at = stripos( str_replace( '\\', '/', $value ), '/wp-content/uploads/' ) ) ) {
        $rest = substr( str_replace( '\\', '/', $value ), $at + strlen( '/wp-content/uploads/' ) );
    }
    if ( null === $rest || '' === $rest ) {
        return '';
    }

    $rest = ltrim( rawurldecode( $rest ), '/' );
    if ( '' === $rest || preg_match( '#(^|/)\.\.(/|$)#', $rest ) ) {
        return '';
    }
    return $basedir . '/' . $rest;
}

/**
 * Parse CSV text into rows of strings. Quoted commas and newlines work; a
 * UTF-8 BOM is stripped.
 *
 * @param string $text CSV text.
 * @return array[]
 */
function tbt_fc_wdt_csv_rows( $text ) {
    $text = (string) $text;
    if ( 0 === strncmp( $text, "\xEF\xBB\xBF", 3 ) ) {
        $text = substr( $text, 3 );
    }
    $handle = fopen( 'php://temp', 'r+' );
    fwrite( $handle, $text );
    rewind( $handle );

    $rows = array();
    while ( false !== ( $row = fgetcsv( $handle, 0, ',', '"', '' ) ) ) {
        if ( array( null ) === $row ) {
            continue; // A blank line.
        }
        $rows[] = array_map( 'strval', $row );
    }
    fclose( $handle );
    return $rows;
}

/**
 * Pick the expression and translation columns and drop a header row.
 *
 * The first two columns with any non-empty cell are used. The first row is
 * a header (and dropped) when its first used cell starts with a word such
 * as Item, Expression or English, or its second used cell names a
 * translation. Manual tables have no header row: pass false.
 *
 * @param array $rows          Rows of cells.
 * @param bool  $detect_header Look for a header row.
 * @return array { rows: array[], header_dropped: string[]|null, columns_used: int[] (0-based) }
 */
function tbt_fc_wdt_two_columns( array $rows, $detect_header = true ) {
    $rows  = array_values( array_filter( $rows, 'is_array' ) );
    $width = 0;
    foreach ( $rows as $i => $row ) {
        $rows[ $i ] = array_values( array_map( function ( $c ) {
            return is_scalar( $c ) ? (string) $c : '';
        }, $row ) );
        $width = max( $width, count( $rows[ $i ] ) );
    }

    $cols = array();
    for ( $c = 0; $c < $width && count( $cols ) < 2; $c++ ) {
        foreach ( $rows as $row ) {
            if ( isset( $row[ $c ] ) && '' !== trim( $row[ $c ] ) ) {
                $cols[] = $c;
                break;
            }
        }
    }

    $pick = function ( $row ) use ( $cols ) {
        $a = isset( $cols[0], $row[ $cols[0] ] ) ? $row[ $cols[0] ] : '';
        $b = isset( $cols[1], $row[ $cols[1] ] ) ? $row[ $cols[1] ] : '';
        return array( $a, $b );
    };
    $picked = array_map( $pick, $rows );

    $header = null;
    if ( $detect_header && $picked ) {
        $first = $picked[0];
        if ( preg_match( '/^(item|items|word|words|expression|expressions|english|phrase|phrases|term|terms|vocabulary|glossary)\b/iu', trim( $first[0] ) )
            || preg_match( '/(translation|polish|polski|tłumaczenie)/iu', $first[1] ) ) {
            $header = array( trim( $first[0] ), trim( $first[1] ) );
            array_shift( $picked );
        }
    }

    return array(
        'rows'           => $picked,
        'header_dropped' => $header,
        'columns_used'   => $cols,
    );
}

/**
 * Trim, collapse whitespace, decode entities and drop HTML tags; drop rows
 * without an expression and repeated expressions (case-insensitive, the
 * first one wins).
 *
 * @param array $rows Rows of array( expression, translation ).
 * @return array[]
 */
function tbt_fc_wdt_clean_rows( array $rows ) {
    $clean = function ( $value ) {
        $value = is_scalar( $value ) ? (string) $value : '';
        // Real tags only (a manual table's HTML column); "<" in text stays.
        $value = preg_replace( '#</?[a-z][a-z0-9]*(?:\s[^<>]*)?/?>#i', ' ', $value );
        $value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = preg_replace( '/[\s\x{00A0}\x{2007}\x{202F}\x{FEFF}]+/u', ' ', $value );
        return trim( (string) $value );
    };

    $out  = array();
    $seen = array();
    foreach ( $rows as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }
        $row  = array_values( $row );
        $word = $clean( isset( $row[0] ) ? $row[0] : '' );
        if ( '' === $word ) {
            continue;
        }
        $key = mb_strtolower( $word, 'UTF-8' );
        if ( isset( $seen[ $key ] ) ) {
            continue;
        }
        $seen[ $key ] = true;
        $out[]        = array( $word, $clean( isset( $row[1] ) ? $row[1] : '' ) );
    }
    return $out;
}

/**
 * Column index (0-based) of a cell reference such as "B7" or "AA12".
 *
 * @param string $ref Cell reference.
 * @return int -1 when there is no column part.
 */
function tbt_fc_xlsx_col( $ref ) {
    if ( ! preg_match( '/^([A-Z]+)/i', (string) $ref, $m ) ) {
        return -1;
    }
    $n = 0;
    foreach ( str_split( strtoupper( $m[1] ) ) as $ch ) {
        $n = $n * 26 + ( ord( $ch ) - 64 );
    }
    return $n - 1;
}

/**
 * Load XML safely (no network), or null.
 *
 * @param string $xml XML text.
 * @return SimpleXMLElement|null
 */
function tbt_fc_xlsx_load( $xml ) {
    if ( ! is_string( $xml ) || '' === $xml || ! function_exists( 'simplexml_load_string' ) ) {
        return null;
    }
    $prev = libxml_use_internal_errors( true );
    $doc  = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET );
    libxml_clear_errors();
    libxml_use_internal_errors( $prev );
    return false === $doc ? null : $doc;
}

/**
 * The element's own namespace URI ('' when none).
 *
 * @param SimpleXMLElement $el Element.
 * @return string
 */
function tbt_fc_xlsx_ns( SimpleXMLElement $el ) {
    $ns = $el->getNamespaces( false );
    return $ns ? (string) reset( $ns ) : '';
}

/**
 * An un-namespaced attribute. (An element reached through children( $ns )
 * looks up $el['x'] in that namespace, which misses plain attributes.)
 *
 * @param SimpleXMLElement $el   Element.
 * @param string           $name Attribute name.
 * @return string|null
 */
function tbt_fc_xlsx_attr( SimpleXMLElement $el, $name ) {
    $atts = $el->attributes();
    return isset( $atts[ $name ] ) ? (string) $atts[ $name ] : null;
}

/**
 * The text of a shared-string or inline-string item: its <t>, or its rich
 * text runs' <t> joined.
 *
 * @param SimpleXMLElement $item <si> or <is>.
 * @param string           $ns   Namespace.
 * @return string
 */
function tbt_fc_xlsx_text( SimpleXMLElement $item, $ns ) {
    $kids = $item->children( $ns );
    if ( isset( $kids->t ) ) {
        return (string) $kids->t;
    }
    $text = '';
    foreach ( $kids->r as $run ) {
        $text .= (string) $run->children( $ns )->t;
    }
    return $text;
}

/**
 * The first worksheet's path inside the .xlsx, from workbook.xml and its rels.
 *
 * @param string $workbook_xml xl/workbook.xml.
 * @param string $rels_xml     xl/_rels/workbook.xml.rels.
 * @return string Path in the zip, e.g. "xl/worksheets/sheet1.xml".
 */
function tbt_fc_xlsx_first_sheet( $workbook_xml, $rels_xml ) {
    $fallback = 'xl/worksheets/sheet1.xml';
    $book     = tbt_fc_xlsx_load( $workbook_xml );
    $rels     = tbt_fc_xlsx_load( $rels_xml );
    if ( null === $book || null === $rels ) {
        return $fallback;
    }
    $ns    = tbt_fc_xlsx_ns( $book );
    $sheet = null;
    foreach ( $book->children( $ns )->sheets->children( $ns )->sheet as $s ) {
        $sheet = $s;
        break;
    }
    if ( null === $sheet ) {
        return $fallback;
    }
    $rid = '';
    foreach ( $sheet->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' ) as $name => $value ) {
        if ( 'id' === $name ) {
            $rid = (string) $value;
        }
    }
    if ( '' === $rid ) {
        foreach ( $sheet->attributes( 'http://purl.oclc.org/ooxml/officeDocument/relationships' ) as $name => $value ) {
            if ( 'id' === $name ) {
                $rid = (string) $value;
            }
        }
    }
    $rns = tbt_fc_xlsx_ns( $rels );
    foreach ( $rels->children( $rns )->Relationship as $rel ) {
        if ( tbt_fc_xlsx_attr( $rel, 'Id' ) === $rid ) {
            $target = str_replace( '\\', '/', (string) tbt_fc_xlsx_attr( $rel, 'Target' ) );
            if ( '/' === substr( $target, 0, 1 ) ) {
                return ltrim( $target, '/' );
            }
            return 'xl/' . preg_replace( '#^\./#', '', $target );
        }
    }
    return $fallback;
}

/**
 * Rows of strings from a worksheet. Each value lands in the column its
 * cell reference names, so empty cells do not shift values left. Shared
 * strings, inline strings, formula strings, booleans and numbers are read.
 *
 * @param string $shared_xml xl/sharedStrings.xml ('' when the file has none).
 * @param string $sheet_xml  The worksheet XML.
 * @param int    $max_rows   Row limit.
 * @return array[]|null Null when the sheet cannot be parsed.
 */
function tbt_fc_xlsx_rows( $shared_xml, $sheet_xml, $max_rows = 2000 ) {
    $sheet = tbt_fc_xlsx_load( $sheet_xml );
    if ( null === $sheet ) {
        return null;
    }

    $strings = array();
    $shared  = tbt_fc_xlsx_load( $shared_xml );
    if ( null !== $shared ) {
        $sns = tbt_fc_xlsx_ns( $shared );
        foreach ( $shared->children( $sns )->si as $si ) {
            $strings[] = tbt_fc_xlsx_text( $si, $sns );
        }
    }

    $ns   = tbt_fc_xlsx_ns( $sheet );
    $data = $sheet->children( $ns )->sheetData;
    if ( ! $data ) {
        return array();
    }

    $rows = array();
    $next = 0;
    foreach ( $data->children( $ns )->row as $row ) {
        $rn    = tbt_fc_xlsx_attr( $row, 'r' );
        $r     = null !== $rn ? (int) $rn - 1 : $next;
        $next  = $r + 1;
        $cells = array();
        $col   = 0;
        foreach ( $row->children( $ns )->c as $c ) {
            $ref  = tbt_fc_xlsx_attr( $c, 'r' );
            $at   = null !== $ref ? tbt_fc_xlsx_col( $ref ) : -1;
            $col  = $at >= 0 ? $at : $col;
            $type = (string) tbt_fc_xlsx_attr( $c, 't' );
            $kids = $c->children( $ns );
            $v    = isset( $kids->v ) ? (string) $kids->v : '';
            if ( 's' === $type ) {
                $v = isset( $strings[ (int) $v ] ) ? $strings[ (int) $v ] : '';
            } elseif ( 'inlineStr' === $type ) {
                $v = isset( $kids->is ) ? tbt_fc_xlsx_text( $kids->is, $ns ) : '';
            } elseif ( 'b' === $type ) {
                $v = '1' === $v ? 'TRUE' : 'FALSE';
            }
            $cells[ $col ] = $v;
            $col++;
        }
        if ( $cells ) {
            $line = array_fill( 0, max( array_keys( $cells ) ) + 1, '' );
            foreach ( $cells as $i => $v ) {
                $line[ $i ] = $v;
            }
            $rows[ $r ] = $line;
        }
        if ( count( $rows ) >= $max_rows ) {
            break;
        }
    }
    ksort( $rows );
    return array_values( $rows );
}

/**
 * Domain colour key for a top-level category: le, gi, pd or ''.
 *
 * Personal Development is term 498 on the site; Learn English and General
 * Interest are matched by name or slug (Phase 0 did not find them under
 * those exact names).
 *
 * @param int    $term_id Term ID.
 * @param string $name    Category name.
 * @param string $slug    Category slug.
 * @return string
 */
function tbt_fc_domain_for_category( $term_id, $name, $slug ) {
    if ( 498 === (int) $term_id ) {
        return 'pd';
    }
    $text = strtolower( $name . ' ' . str_replace( '-', ' ', $slug ) );
    if ( false !== strpos( $text, 'english' ) ) {
        return 'le';
    }
    if ( false !== strpos( $text, 'general' ) || false !== strpos( $text, 'interest' ) ) {
        return 'gi';
    }
    if ( false !== strpos( $text, 'development' ) ) {
        return 'pd';
    }
    return '';
}
