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

// glossary-translate.php: only its pure helpers are called here.
define( 'TBT_FC_MAX_GENERATE_ITEMS', 40 );
function add_action() {}
require __DIR__ . '/../includes/glossary-translate.php';

// 2.4.1's tests read pairs; parse_pasted() returns cards since 2.4.2.
function pp( $text ) {
    return tbt_fc_paste_pairs( tbt_fc_parse_pasted( $text ) );
}

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

// 11. Quizlet set IDs (2.4.1).
check(
    'quizlet: embed iframe',
    array( '868199351' ) === tbt_fc_quizlet_ids( '<iframe src="https://quizlet.com/868199351/flashcards/embed?i=ki5an&x=1jj1" height="500"></iframe>' )
);
check( 'quizlet: /pl/…/slug/ link', array( '123456789' ) === tbt_fc_quizlet_ids( '<a href="https://quizlet.com/pl/123456789/why-we-sleep-flash-cards/">set</a>' ) );
check(
    'quizlet: two sets, repeats dropped, in order',
    array( '868199351', '868199852' ) === tbt_fc_quizlet_ids( 'quizlet.com/868199351/flashcards/embed … quizlet.com/868199852/flashcards/embed … https://quizlet.com/868199351/' )
);
check( 'quizlet: none', array() === tbt_fc_quizlet_ids( 'No set here, quizlet.com/live and quizlet.com/1234 only' ) );

// 12. Multidecker lists (2.4.1).
check(
    'multidecker: four lists from embeds',
    array( 'why-we-sleep-part-1-5e140c72974472086c49cf21', 'why-we-sleep-part-2-5e140f7d974472086c49cf2a' ) === tbt_fc_multidecker_slugs(
        '<iframe src="https://multidecker.com/list/why-we-sleep-part-1-5e140c72974472086c49cf21/play"></iframe>'
        . '<iframe src="https://multidecker.com/list/why-we-sleep-part-2-5e140f7d974472086c49cf2a/play"></iframe>'
        . '<a href="https://multidecker.com/list/why-we-sleep-part-1-5e140c72974472086c49cf21/">again</a>'
    )
);
check( 'multidecker: none', array() === tbt_fc_multidecker_slugs( 'https://multidecker.com/ and quizlet.com/868199351/' ) );

// 13. parse_pasted (2.4.1).
$sample = "motionless\tnieruchomy\ntransition\tprzejście, przemiana\npull an all-nighter\tzarwać nockę\n";
check(
    'paste: tab lines (Why we sleep sample)',
    array( array( 'motionless', 'nieruchomy' ), array( 'transition', 'przejście, przemiana' ), array( 'pull an all-nighter', 'zarwać nockę' ) ) === pp( $sample ),
    var_export( pp( $sample ), true )
);
$p = pp( "motionless - nieruchomy\npull an all-nighter – zarwać nockę - nie spać\r\nwell-being - dobrostan" );
check(
    'paste: " - " and " – " lines, first occurrence, hyphenated words kept',
    array( array( 'motionless', 'nieruchomy' ), array( 'pull an all-nighter', 'zarwać nockę - nie spać' ), array( 'well-being', 'dobrostan' ) ) === $p,
    var_export( $p, true )
);
$p = pp( "motionless; nieruchomy\ntransition; przejście, przemiana\nsleep; spać" );
check(
    'paste: ";" lines beat a "," in one line',
    array( array( 'motionless', ' nieruchomy' ), array( 'transition', ' przejście, przemiana' ), array( 'sleep', ' spać' ) ) === $p,
    var_export( $p, true )
);
$p = pp( "\n\nmotionless\tnieruchomy\n   \n\ntransition\tprzejście\n\n" );
check( 'paste: blank lines skipped', array( array( 'motionless', 'nieruchomy' ), array( 'transition', 'przejście' ) ) === $p, var_export( $p, true ) );
$p = pp( "motionless - nieruchomy\nserendipity\ntransition - przejście" );
check( 'paste: a line without the separator has no translation (" - " mode)', array( 'serendipity', '' ) === $p[1], var_export( $p, true ) );
$p = pp( "motionless\tnieruchomy\nserendipity\ntransition\tprzejście" );
check( 'paste: tab mode, a line without a tab joins the card above (2.4.2)', array( array( 'motionless', 'nieruchomy serendipity' ), array( 'transition', 'przejście' ) ) === $p, var_export( $p, true ) );
check( 'paste: no separator anywhere', array( array( 'one', '' ), array( 'two words', '' ) ) === pp( "one\ntwo words" ) );
$two = $sample . "\nMotionless\tbez ruchu\nsnore\tchrapać\n";
$r   = tbt_fc_wdt_clean_rows( tbt_fc_wdt_two_columns( pp( $two ) )['rows'] );
check(
    'paste: two exports together, the repeated word kept once (first wins)',
    array( array( 'motionless', 'nieruchomy' ), array( 'transition', 'przejście, przemiana' ), array( 'pull an all-nighter', 'zarwać nockę' ), array( 'snore', 'chrapać' ) ) === $r,
    var_export( $r, true )
);
$r = tbt_fc_wdt_clean_rows( tbt_fc_wdt_two_columns( pp( "Term\tTranslation\n" . $sample ) )['rows'] );
check( 'paste: a header line is dropped', 3 === count( $r ) && 'motionless' === $r[0][0], var_export( $r, true ) );
check( 'paste: blank text gives no rows', array() === pp( " \n\n\t\n" ) && array() === pp( '' ) );
$long = tbt_fc_paste_limit( array( array( str_repeat( 'ą', 130 ), str_repeat( 'b', 200 ) ) ) );
check( 'paste: limits cut to 120 / 190 characters', 120 === mb_strlen( $long[0][0], 'UTF-8' ) && 190 === strlen( $long[0][1] ) );

// 14. Multi-line Quizlet cards (2.4.2).
$cozy = "Coziness\tDefinition: A feeling of warmth, comfort, and safety, often associated with a pleasant atmosphere.\n"
    . "Example: The candlelight and soft blankets created a sense of coziness in the room.\n"
    . "Content\tDefinition: Feeling satisfied and happy with what you have.\n"
    . "Example: After dinner with her family, she felt calm and content.\n";
$c = tbt_fc_parse_pasted( $cozy );
check( 'cards: the Coziness/Content sample gives 2 cards', 2 === count( $c ), var_export( $c, true ) );
check(
    'cards: definition without its label, example split off',
    'Coziness' === $c[0]['term']
        && 'A feeling of warmth, comfort, and safety, often associated with a pleasant atmosphere.' === $c[0]['definition']
        && 'Example: The candlelight and soft blankets created a sense of coziness in the room.' === $c[0]['example']
        && 'Content' === $c[1]['term']
        && 'Feeling satisfied and happy with what you have.' === $c[1]['definition']
        && 'Example: After dinner with her family, she felt calm and content.' === $c[1]['example'],
    var_export( $c, true )
);
check(
    'cards: a plain Save stores the definition without the example',
    array( array( 'Coziness', 'A feeling of warmth, comfort, and safety, often associated with a pleasant atmosphere.' ), array( 'Content', 'Feeling satisfied and happy with what you have.' ) ) === tbt_fc_paste_pairs( $c )
);
check(
    'cards: a plain two-column paste has no definitions to strip',
    array( array( 'term' => 'motionless', 'definition' => 'nieruchomy', 'example' => '', 'labelled' => false ) ) === tbt_fc_parse_pasted( "motionless\tnieruchomy" )
);
$c = tbt_fc_parse_pasted( "Quizlet export, part 2\nmotionless\tnieruchomy\nsnore\tchrapać" );
check( 'cards: a stray line before the first card is ignored', array( array( 'motionless', 'nieruchomy' ), array( 'snore', 'chrapać' ) ) === tbt_fc_paste_pairs( $c ), var_export( $c, true ) );
$c = tbt_fc_parse_pasted( "przytulność\tdefinicja: uczucie ciepła i bezpieczeństwa\nPRZYKŁAD: W pokoju było przytulnie.\nzadowolony\tDefinicja:czujący satysfakcję Przykład: Była zadowolona." );
check(
    'cards: Definicja: / Przykład: labels, any case',
    'uczucie ciepła i bezpieczeństwa' === $c[0]['definition'] && 'PRZYKŁAD: W pokoju było przytulnie.' === $c[0]['example']
        && 'czujący satysfakcję' === $c[1]['definition'] && 'Przykład: Była zadowolona.' === $c[1]['example'],
    var_export( $c, true )
);
check( 'cards: an example with no definition', array( 'term' => 'bask', 'definition' => '', 'example' => 'Example: Cats bask in the sun.', 'labelled' => false ) === tbt_fc_parse_pasted( "bask\tExample: Cats bask in the sun." )[0] );

// 15. English definitions heuristic (2.4.2).
check( 'english: the Coziness/Content sample', true === tbt_fc_paste_english( tbt_fc_parse_pasted( $cozy ) ) );
check( 'english: Polish translations', false === tbt_fc_paste_english( tbt_fc_parse_pasted( $sample ) ) );
check( 'english: long unlabelled English definitions', true === tbt_fc_paste_english( tbt_fc_parse_pasted( "content\tfeeling satisfied and happy with what you have\nsnore\tto breathe noisily through your nose while sleeping" ) ) );
check( 'english: short English glosses are not flagged', false === tbt_fc_paste_english( tbt_fc_parse_pasted( "content\thappy\nsnore\tbreathe noisily" ) ) );
check( 'english: no definitions', false === tbt_fc_paste_english( tbt_fc_parse_pasted( "one\ntwo" ) ) );

// 16. Cards to translate (2.4.2).
$t = tbt_fc_paste_translatable( tbt_fc_parse_pasted( "Term\tDefinition\n" . $cozy . "\tno term\n" ) );
check( 'translatable: header and termless cards dropped', array( 'Coziness', 'Content' ) === array_column( $t, 'term' ), var_export( $t, true ) );
check( 'translatable: plain words', array( 'happy', 'take for granted' ) === array_column( tbt_fc_paste_translatable( tbt_fc_parse_pasted( "happy\n take  for granted \n" ) ), 'term' ) );

// 17. Translation prompt and alignment (2.4.2).
$prompt = tbt_fc_translate_user_prompt(
    array(
        array( 'term' => 'Content', 'definition' => 'Feeling satisfied.', 'example' => 'Example: She felt content.' ),
        array( 'term' => 'bask in something', 'definition' => '', 'example' => 'Example: Cats bask in the sun.' ),
        array( 'term' => 'snore', 'definition' => '', 'example' => '' ),
    ),
    '10 Happy Words Around the World'
);
check(
    'prompt: title, numbered items, definition first, example only without one',
    "Lesson: 10 Happy Words Around the World\n\nItems:\n1. Content — Feeling satisfied.\n2. bask in something — Example: Cats bask in the sun.\n3. snore" === $prompt,
    $prompt
);
check( 'prompt: no title', 0 === strpos( tbt_fc_translate_user_prompt( array(), '' ), 'Lesson: no title given.' ) );
$schema = tbt_fc_translate_schema( 3 );
check( 'schema: translations index + translation, strict', array( 'index', 'translation' ) === $schema['properties']['translations']['items']['required'] && false === $schema['additionalProperties'] && 3 === $schema['properties']['translations']['maxItems'] );
$a = tbt_fc_align_translations( 4, array(
    array( 'index' => 3, 'translation' => 'chrapać' ),
    array( 'index' => 1, 'translation' => ' „zadowolony” ' ),
    array( 'index' => 1, 'translation' => 'treść' ),
    array( 'index' => 9, 'translation' => 'extra' ),
    'junk',
) );
check( 'align: by index, dropped items empty, first wins, quotes trimmed', array( 'zadowolony', '', 'chrapać', '' ) === $a, var_export( $a, true ) );
check( 'align: plain text on one line, 190 characters', 'a b' === tbt_fc_align_translations( 1, array( array( 'index' => 1, 'translation' => "<b>a</b>\nb" ) ) )[0] && 190 === mb_strlen( tbt_fc_align_translations( 1, array( array( 'index' => 1, 'translation' => str_repeat( 'ż', 300 ) ) ) )[0], 'UTF-8' ) );
check( 'align: nothing back', array( '', '' ) === tbt_fc_align_translations( 2, array() ) );

echo $failures ? "\n$failures failure(s)\n" : "\nAll tests passed\n";
exit( $failures ? 1 : 0 );
