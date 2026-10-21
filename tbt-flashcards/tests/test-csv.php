<?php
/**
 * Standalone CSV tests: php tbt-flashcards/tests/test-csv.php
 *
 * No WordPress needed; the few WP functions cards.php touches are stubbed.
 * Exits non-zero on the first failure.
 */

if ( 'cli' !== PHP_SAPI ) {
    exit;
}

define( 'ABSPATH', __DIR__ . '/' );

if ( ! function_exists( '__' ) ) {
    function __( $text ) {
        return $text;
    }
}

/**
 * Close to WordPress's sanitize_text_field(): strip tags, collapse line
 * breaks, tabs and runs of spaces, trim, drop percent-encoded octets.
 */
function sanitize_text_field( $str ) {
    $filtered = (string) $str;
    if ( false !== strpos( $filtered, '<' ) ) {
        $filtered = strip_tags( $filtered );
    }
    $filtered = preg_replace( '/[\r\n\t ]+/', ' ', $filtered );
    $filtered = trim( $filtered );
    while ( preg_match( '/%[a-f0-9]{2}/i', $filtered, $match ) ) {
        $filtered = str_replace( $match[0], '', $filtered );
    }
    return trim( preg_replace( '/ +/', ' ', $filtered ) );
}

require __DIR__ . '/../includes/cards.php';

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

// 1. Parse the fixture: 26 cards.
$fixture = file_get_contents( __DIR__ . '/fixtures/yalom-and-the-secret-ingredient.csv' );
$cards   = tbt_fc_parse_csv( $fixture );
check( 'fixture parses to 26 cards', 26 === count( $cards ), 'got ' . count( $cards ) );
check( 'first card word', 'Delineated stages' === $cards[0]['word'] );
check( 'quoted translation keeps its comma', 'współczucie, troska o cierpienie drugiej osoby' === $cards[2]['translation'] );
check( 'last card example', 'Therapy helped her untangle conflicting feelings about her family.' === $cards[25]['example'] );

// 2. Round trip is byte-identical, raw and after the editor's sanitiser.
$csv = tbt_fc_to_csv( $cards );
check( 'to_csv( parse( fixture ) ) === fixture', $csv === $fixture, 'lengths ' . strlen( $csv ) . ' vs ' . strlen( $fixture ) );
check( 'no BOM', "\xEF\xBB\xBF" !== substr( $csv, 0, 3 ) );
check( 'LF only, final newline', false === strpos( $csv, "\r" ) && "\n" === substr( $csv, -1 ) );
check( 'sanitised round trip is byte-identical', tbt_fc_to_csv( tbt_fc_sanitize_cards( $cards ) ) === $fixture );

// 3. Quoting cases.
check( 'field with comma is quoted', '"a, b"' === tbt_fc_csv_field( 'a, b' ) );
check( 'field with quote is quoted and doubled', '"say ""hi"""' === tbt_fc_csv_field( 'say "hi"' ) );
check( 'plain field with spaces and slashes is bare', '/ət ə prəˈfaʊnd ˈlevəl/' === tbt_fc_csv_field( '/ət ə prəˈfaʊnd ˈlevəl/' ) );
check( 'field with newline is quoted', "\"a\nb\"" === tbt_fc_csv_field( "a\nb" ) );
check(
    'row serialisation',
    "word,phonetic,translation,example\nx,/x/,\"a, b\",\"He said \"\"no\"\".\"\n" === tbt_fc_to_csv( array( array( 'word' => 'x', 'phonetic' => '/x/', 'translation' => 'a, b', 'example' => 'He said "no".' ) ) )
);

// 4. Semicolon- and tab-delimited inputs with alias headers parse the same.
$expected = array(
    array( 'word' => 'Diligently', 'phonetic' => '/ˈdɪlɪdʒəntli/', 'translation' => 'pilnie, starannie', 'example' => 'She worked diligently.' ),
    array( 'word' => 'Elusive', 'phonetic' => '/ɪˈluːsɪv/', 'translation' => 'nieuchwytny', 'example' => 'The cause remained elusive.' ),
);
$semicolon = "english;ipa;polish;sentence\r\nDiligently;/ˈdɪlɪdʒəntli/;pilnie, starannie;She worked diligently.\r\n;;;\r\nElusive;/ɪˈluːsɪv/;nieuchwytny;\"The cause remained elusive.\"\r\n";
$tab       = "\xEF\xBB\xBFEnglish\tPolish\tIPA\tSentence\nDiligently\tpilnie, starannie\t/ˈdɪlɪdʒəntli/\tShe worked diligently.\n\nElusive\tnieuchwytny\t/ɪˈluːsɪv/\tThe cause remained elusive.\n";
check( 'semicolon + aliases', tbt_fc_parse_csv( $semicolon ) === $expected, var_export( tbt_fc_parse_csv( $semicolon ), true ) );
check( 'tab + aliases + BOM', tbt_fc_parse_csv( $tab ) === $expected, var_export( tbt_fc_parse_csv( $tab ), true ) );

// 5. Sanitiser rules.
$long  = str_repeat( 'ą', 150 ); // 300 bytes.
$clean = tbt_fc_sanitize_cards( array(
    array( 'word' => $long, 'phonetic' => '<b>/x/</b>', 'translation' => str_repeat( 'x', 600 ), 'example' => "a\nb", 'extra' => 'dropped' ),
    array( 'word' => '   ', 'translation' => 'no word' ),
    'not a row',
) );
check( 'rows without a word are dropped', 1 === count( $clean ) );
check( 'word capped at 200 bytes on a character boundary', strlen( $clean[0]['word'] ) <= 200 && mb_check_encoding( $clean[0]['word'], 'UTF-8' ) && 100 === mb_strlen( $clean[0]['word'] ) );
check( 'other fields capped at 500 chars', 500 === mb_strlen( $clean[0]['translation'] ) );
check( 'tags and newlines stripped', '/x/' === $clean[0]['phonetic'] && 'a b' === $clean[0]['example'] );
check( 'only the four keys', array( 'word', 'phonetic', 'translation', 'example' ) === array_keys( $clean[0] ) );
$many = array_fill( 0, 350, array( 'word' => 'w' ) );
check( 'at most 300 cards', 300 === count( tbt_fc_sanitize_cards( $many ) ) );

echo $failures ? "\n$failures failure(s)\n" : "\nAll tests passed\n";
exit( $failures ? 1 : 0 );
