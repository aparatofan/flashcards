<?php
/**
 * Standalone glossary-import tests: php tbt-flashcards/tests/test-glossary-import.php
 *
 * No WordPress needed: glossary-import-format.php is pure.
 * Exits non-zero on any failure.
 */

if ( 'cli' !== PHP_SAPI ) {
    exit;
}

define( 'ABSPATH', __DIR__ . '/' );

require __DIR__ . '/../includes/glossary-import-format.php';

$failures = 0;
function check( $name, $condition, $detail = '' ) {
    global $failures;
    if ( $condition ) {
        echo "ok   - $name\n";
        return;
    }
    $failures++;
    echo "FAIL - $name" . ( '' !== $detail ? "\n       $detail" : '' ) . "\n";
}

// 1. wdt_ids.
check( 'id=139', array( 139 ) === tbt_fc_wdt_ids( 'a [wpdatatable id=139] b' ) );
check( 'id="139"', array( 139 ) === tbt_fc_wdt_ids( '[wpdatatable id="139" table_view=regular]' ) );
check( "id='139'", array( 139 ) === tbt_fc_wdt_ids( "[wpdatatable id='139']" ) );
check( 'id=&quot;139&quot;', array( 139 ) === tbt_fc_wdt_ids( '[wpdatatable id=&quot;139&quot;]' ) );
check( 'curly quotes', array( 139 ) === tbt_fc_wdt_ids( '[wpdatatable id=”139″]' ) );
check( 'curly quote entities', array( 139 ) === tbt_fc_wdt_ids( '[wpdatatable id=&#8221;139&#8243;]' ) );
check( 'two tables, duplicates removed', array( 57, 12 ) === tbt_fc_wdt_ids( '[wpdatatable id=57] [wpdatatable id="12"] [wpdatatable id=57]' ) );
check( 'none', array() === tbt_fc_wdt_ids( 'Just text [et_pb_text]x[/et_pb_text]' ) );
check( 'table_id or other shortcodes ignored', array() === tbt_fc_wdt_ids( '[wpdatatable_sum table_id=5] [wpdatatable table_id=9]' ) );

// 2. replace_wdt.
$content = 'A [wpdatatable id=5] B [wpdatatable id="6"] C [wpdatatable id=&quot;5&quot;] D';
check(
    'only the given ID changes, every occurrence',
    'A X B [wpdatatable id="6"] C X D' === tbt_fc_replace_wdt( $content, 5, 'X' ),
    tbt_fc_replace_wdt( $content, 5, 'X' )
);
check( 'missing ID leaves the content', $content === tbt_fc_replace_wdt( $content, 99, 'X' ) );

// 3. global module.
$divi = '[et_pb_section global_module="16766"][et_pb_text][wpdatatable id=5][/et_pb_text][/et_pb_section][et_pb_section][et_pb_text][wpdatatable id=6][/et_pb_text][/et_pb_section]';
check( 'table in a global module found', 16766 === tbt_fc_wdt_global_module( $divi, 5 ) );
check( 'table outside it is not', 0 === tbt_fc_wdt_global_module( $divi, 6 ) );

// 4. google_csv_url.
$id = '1AbC-dEf_123';
check( 'edit#gid=0', "https://docs.google.com/spreadsheets/d/$id/export?format=csv&gid=0" === tbt_fc_google_csv_url( "https://docs.google.com/spreadsheets/d/$id/edit#gid=0" ) );
check( 'edit?gid=…#gid=…', "https://docs.google.com/spreadsheets/d/$id/export?format=csv&gid=625851131" === tbt_fc_google_csv_url( "https://docs.google.com/spreadsheets/d/$id/edit?gid=625851131#gid=625851131" ) );
check( 'no gid', "https://docs.google.com/spreadsheets/d/$id/export?format=csv&gid=0" === tbt_fc_google_csv_url( "https://docs.google.com/spreadsheets/d/$id/edit?usp=sharing" ) );
check( 'pubhtml form', 'https://docs.google.com/spreadsheets/d/e/2PACX-1vX/pub?gid=0&single=true&output=csv' === tbt_fc_google_csv_url( 'https://docs.google.com/spreadsheets/d/e/2PACX-1vX/pubhtml?gid=0&single=t' ) );
check( 'non-Google URL', '' === tbt_fc_google_csv_url( 'https://example.com/spreadsheets/d/abc/edit' ) );

// 5. upload_path.
$basedir = '/home/platne/serwer135893/public_html/wp-content/uploads';
$baseurl = 'https://thebluetree.pl/wp-content/uploads';
check( 'site URL', "$basedir/2020/07/x.xlsx" === tbt_fc_upload_path( 'http://thebluetree.pl/wp-content/uploads/2020/07/x.xlsx', $basedir, $baseurl ) );
check( 'old host path', "$basedir/2020/07/x.xlsx" === tbt_fc_upload_path( '/home/5f2d1904c1ac191bfcc55f78/public_html/wp-content/uploads/2020/07/x.xlsx', $basedir, $baseurl ) );
check( 'current host path', "$basedir/2021/03/y.xlsx" === tbt_fc_upload_path( "$basedir/2021/03/y.xlsx", $basedir, $baseurl ) );
check( 'encoded name', "$basedir/2021/03/my file.xlsx" === tbt_fc_upload_path( "$baseurl/2021/03/my%20file.xlsx", $basedir, $baseurl ) );
check( 'unrelated', '' === tbt_fc_upload_path( 'https://example.com/files/x.xlsx', $basedir, $baseurl ) );
check( 'climbing out', '' === tbt_fc_upload_path( "$baseurl/../wp-config.php", $basedir, $baseurl ) );

// 6. csv_rows.
$rows = tbt_fc_wdt_csv_rows( "\xEF\xBB\xBFItem,Translation\n\"slouch, badly\",garbić się\n\"two\nlines\",\"a \"\"q\"\"\"\n\n" );
check( 'BOM stripped', 'Item' === $rows[0][0], var_export( $rows, true ) );
check( 'quoted comma', 'slouch, badly' === $rows[1][0] );
check( 'quoted newline', "two\nlines" === $rows[2][0] && 'a "q"' === $rows[2][1], var_export( $rows, true ) );
check( 'blank lines skipped', 3 === count( $rows ) );

// 7. two_columns.
$r = tbt_fc_wdt_two_columns( array( array( 'Item', 'Translation' ), array( 'reach', 'sięgać' ) ) );
check( 'header dropped', array( array( 'reach', 'sięgać' ) ) === $r['rows'] && array( 'Item', 'Translation' ) === $r['header_dropped'] && array( 0, 1 ) === $r['columns_used'] );
$r = tbt_fc_wdt_two_columns( array( array( 'reach', 'sięgać' ), array( 'crane', 'wyciągać szyję' ) ) );
check( 'no header', 2 === count( $r['rows'] ) && null === $r['header_dropped'] );
$r = tbt_fc_wdt_two_columns( array( array( 'Wordsmith', 'kowal słowa' ) ) );
check( '"Wordsmith" is not a header', null === $r['header_dropped'] );
$r = tbt_fc_wdt_two_columns( array( array( 'english', 'polski' ) ), false );
check( 'no header detection for manual tables', null === $r['header_dropped'] && 1 === count( $r['rows'] ) );
$r = tbt_fc_wdt_two_columns( array( array( '', 'Phrase', 'Polish' ), array( '', 'reach', 'sięgać' ), array( '', 'bend', 'zginać' ) ) );
check( 'empty first column', array( 1, 2 ) === $r['columns_used'] && array( array( 'reach', 'sięgać' ), array( 'bend', 'zginać' ) ) === $r['rows'], var_export( $r, true ) );
$r = tbt_fc_wdt_two_columns( array( array( 'reach', '', 'sięgać', 'x' ), array( 'bend', '', 'zginać', 'y' ) ) );
check( 'three columns: empty second skipped', array( 0, 2 ) === $r['columns_used'] && array( 'bend', 'zginać' ) === $r['rows'][1] );
$r = tbt_fc_wdt_two_columns( array( array( 'a', 'b', 'c' ) ) );
check( 'three columns: first two used', array( 0, 1 ) === $r['columns_used'] && array( array( 'a', 'b' ) ) === $r['rows'] );

// 8. clean_rows.
$r = tbt_fc_wdt_clean_rows( array(
    array( '  at arm&#039;s&nbsp;length ', ' na  wyciągnięcie   ręki ' ),
    array( 'Rock &amp; roll', '<b>rock</b> and roll' ),
    array( 'AT ARM\'S LENGTH', 'duplicate' ),
    array( '', 'no expression' ),
    array( '   ', 'blank' ),
    array( 'a < b', '' ),
    array( 'solo' ),
) );
check(
    'clean_rows: entities, whitespace, tags, duplicates, empties',
    array(
        array( "at arm's length", 'na wyciągnięcie ręki' ),
        array( 'Rock & roll', 'rock and roll' ),
        array( 'a < b', '' ),
        array( 'solo', '' ),
    ) === $r,
    var_export( $r, true )
);

// 9. xlsx: shared strings, inline strings, numbers, gaps keep their column.
$shared = '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Item</t></si><si><t>Translation</t></si><si><r><t>re</t></r><r><t>ach</t></r></si><si><t>sięgać</t></si></sst>';
$sheet  = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
    . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
    . '<row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2" t="s"><v>3</v></c></row>'
    . '<row r="4"><c r="A4" t="inlineStr"><is><t>ten</t></is></c><c r="C4"><v>10</v></c></row>'
    . '</sheetData></worksheet>';
$x = tbt_fc_xlsx_rows( $shared, $sheet );
check(
    'xlsx rows',
    array( array( 'Item', 'Translation' ), array( 'reach', 'sięgać' ), array( 'ten', '', '10' ) ) === $x,
    var_export( $x, true )
);
check( 'xlsx col', 0 === tbt_fc_xlsx_col( 'A1' ) && 26 === tbt_fc_xlsx_col( 'AA3' ) );
$book = '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Glossary" sheetId="3" r:id="rId7"/><sheet name="B" sheetId="1" r:id="rId1"/></sheets></workbook>';
$rels = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/><Relationship Id="rId7" Target="worksheets/sheet3.xml"/></Relationships>';
check( 'first worksheet path', 'xl/worksheets/sheet3.xml' === tbt_fc_xlsx_first_sheet( $book, $rels ), tbt_fc_xlsx_first_sheet( $book, $rels ) );

// 10. Domain.
check( 'domain pd by ID', 'pd' === tbt_fc_domain_for_category( 498, 'PERSONAL DEVELOPMENT', 'development' ) );
check( 'domain le', 'le' === tbt_fc_domain_for_category( 3, 'LEARN ENGLISH', 'learn-english' ) );
check( 'domain gi', 'gi' === tbt_fc_domain_for_category( 4, 'GENERAL INTEREST', 'general-interest' ) );
check( 'domain none', '' === tbt_fc_domain_for_category( 5, 'News', 'news' ) );

echo $failures ? "\n$failures failure(s)\n" : "\nAll tests passed\n";
exit( $failures ? 1 : 0 );
