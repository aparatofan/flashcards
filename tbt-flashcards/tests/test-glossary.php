<?php
/**
 * Standalone glossary tests: php tbt-flashcards/tests/test-glossary.php
 *
 * No WordPress needed; the hooks glossary.php registers are stubbed.
 * Exits non-zero on any failure.
 */

if ( 'cli' !== PHP_SAPI ) {
    exit;
}

define( 'ABSPATH', __DIR__ . '/' );

function add_action() {}
function add_shortcode() {}

class WP_Error {}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function __( $text ) { return $text; }
function esc_url_raw( $url ) { return preg_match( '#^https?://#i', $url ) ? $url : ''; }
function wp_remote_get() { throw new Exception( 'The glossary must never read over HTTP.' ); }

// A temporary uploads folder, removed at the end.
$uploads_dir = sys_get_temp_dir() . '/tbt-fc-gl-' . getmypid();
mkdir( $uploads_dir . '/2026/11', 0777, true );
function wp_upload_dir() {
    global $uploads_dir;
    return array( 'error' => false, 'baseurl' => 'https://thebluetree.pl/wp-content/uploads', 'basedir' => $uploads_dir );
}
function untrailingslashit( $value ) { return rtrim( $value, '/\\' ); }

require __DIR__ . '/../includes/cards.php';
require __DIR__ . '/../includes/glossary.php';

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

function card( $word, $translation = null ) {
    $card = array( 'word' => $word, 'phonetic' => '/x/', 'example' => 'An example.' );
    if ( null !== $translation ) {
        $card['translation'] = $translation;
    }
    return $card;
}

// 1. Lesson order: sets in order, cards in set order.
$rows = tbt_fc_glossary_rows( array(
    array( card( 'Make a confession', 'wyznać coś' ), card( 'Experience stress', 'doświadczać stresu' ) ),
    array( card( 'Anxiety', 'niepokój' ) ),
) );
check( 'order kept across sets', array(
    array( 'Make a confession', 'wyznać coś' ),
    array( 'Experience stress', 'doświadczać stresu' ),
    array( 'Anxiety', 'niepokój' ),
) === $rows, var_export( $rows, true ) );

// 2. Duplicates dropped case-insensitively and trimmed, at first place.
$rows = tbt_fc_glossary_rows( array(
    array( card( 'Chronic stress', 'przewlekły stres' ), card( 'Courage', 'odwaga' ) ),
    array( card( '  chronic STRESS ', 'inny' ), card( 'Crave', 'pragnąć' ), card( 'COURAGE', 'męstwo' ) ),
) );
check( 'duplicates dropped, first kept', array(
    array( 'Chronic stress', 'przewlekły stres' ),
    array( 'Courage', 'odwaga' ),
    array( 'Crave', 'pragnąć' ),
) === $rows, var_export( $rows, true ) );

// 3. Non-ASCII case folding for duplicates.
$rows = tbt_fc_glossary_rows( array( array( card( 'Ćma', 'a' ), card( 'ćMA', 'b' ) ) ) );
check( 'multibyte duplicates dropped', 1 === count( $rows ) && 'a' === $rows[0][1] );

// 4. Empty words skipped; empty or missing translation kept as ''.
$rows = tbt_fc_glossary_rows( array( array(
    card( '', 'nic' ),
    card( '   ', 'nic' ),
    array( 'translation' => 'no word key' ),
    card( 'Help out', '' ),
    card( 'Profound' ),
    'not a card',
) ) );
check( 'empty words skipped, empty translation kept', array(
    array( 'Help out', '' ),
    array( 'Profound', '' ),
) === $rows, var_export( $rows, true ) );

// 5. A WP_Error or non-array set counts as empty.
$rows = tbt_fc_glossary_rows( array( new WP_Error(), 'x', array( card( 'Inevitable', 'nieunikniony' ) ) ) );
check( 'WP_Error set counts as empty', array( array( 'Inevitable', 'nieunikniony' ) ) === $rows );
check( 'no sets, no rows', array() === tbt_fc_glossary_rows( array() ) );

// 6. Only expression and translation leave the server.
$rows = tbt_fc_glossary_rows( array( array( card( 'Resilience', 'odporność psychiczna' ) ) ) );
check( 'rows carry two columns only', 2 === count( $rows[0] ) );

// 7. ids attribute: digits only, order kept, duplicates dropped.
check( 'ids parsed in order', array( 361, 362, 363 ) === tbt_fc_glossary_parse_ids( '361, 362,363' ) );
check( 'ids survive curly quotes and duplicates', array( 361, 362 ) === tbt_fc_glossary_parse_ids( '”361,362,361″' ) );
check( 'empty ids', array() === tbt_fc_glossary_parse_ids( '' ) );

// 8. urls attribute: commas, spaces, duplicates, empties.
$a = 'https://thebluetree.pl/wp-content/uploads/2026/11/a.csv';
$b = 'https://thebluetree.pl/wp-content/uploads/2026/11/b.csv';
check( 'urls parsed in order, trimmed', array( $a, $b ) === tbt_fc_glossary_parse_urls( " $a ,$b" ) );
check( 'urls duplicates and empties dropped', array( $a, $b ) === tbt_fc_glossary_parse_urls( "$a,, $a ,$b," ) );
check( 'empty urls', array() === tbt_fc_glossary_parse_urls( '' ) );
check( 'urls survive curly quotes', array( $a, $b ) === tbt_fc_glossary_parse_urls( "”{$a},{$b}″" ) );

// 9. Files: read from uploads only, never over HTTP.
file_put_contents( $uploads_dir . '/2026/11/a.csv', "\xEF\xBB\xBFword,phonetic,translation,example\nHabit,/ˈhæbɪt/,nawyk,A good habit.\nFree,/friː/,darmowy,It is free.\n" );
try {
    $sets = tbt_fc_glossary_url_sets( array( $a ) );
    check( 'a file in uploads is read', array( array( 'Habit', 'nawyk' ), array( 'Free', 'darmowy' ) ) === tbt_fc_glossary_rows( $sets ), var_export( $sets, true ) );
    $sets = tbt_fc_glossary_url_sets( array( 'https://example.com/wp-content/uploads/2026/11/a.csv' ) );
    check( 'a URL outside uploads is skipped', array() === $sets );
    $sets = tbt_fc_glossary_url_sets( array( $b ) );
    check( 'a missing file is skipped', array() === $sets );
    check( 'http and https uploads URLs both read', 1 === count( tbt_fc_glossary_url_sets( array( str_replace( 'https:', 'http:', $a ) ) ) ) );
} catch ( Exception $e ) {
    check( 'no HTTP read', false, $e->getMessage() );
}

// 10. A set and a file with the same word give one row: the set's.
$rows = tbt_fc_glossary_rows( array_merge( array( array( card( 'habit', 'przyzwyczajenie' ) ) ), tbt_fc_glossary_url_sets( array( $a ) ) ) );
check( 'set word wins over file word', array( array( 'habit', 'przyzwyczajenie' ), array( 'Free', 'darmowy' ) ) === $rows, var_export( $rows, true ) );

// 11. Stored rows (_tbt_fc_glossary, 2.4.0): only lists of two strings.
$stored = tbt_fc_glossary_decode_stored( '[["reach","sięgać"],["bad"],["x",1],"y",["slouch","garbić się"],["a","b","c"]]' );
check( 'stored rows validated', array( array( 'reach', 'sięgać' ), array( 'slouch', 'garbić się' ) ) === $stored, var_export( $stored, true ) );
check( 'stored rows: not JSON', array() === tbt_fc_glossary_decode_stored( 'nope' ) );
check( 'stored rows: empty', array() === tbt_fc_glossary_decode_stored( '' ) );

unlink( $uploads_dir . '/2026/11/a.csv' );
rmdir( $uploads_dir . '/2026/11' );
rmdir( $uploads_dir . '/2026' );
rmdir( $uploads_dir );

echo $failures ? "\n$failures failure(s)\n" : "\nAll tests passed\n";
exit( $failures ? 1 : 0 );
