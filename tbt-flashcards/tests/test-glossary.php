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

echo $failures ? "\n$failures failure(s)\n" : "\nAll tests passed\n";
exit( $failures ? 1 : 0 );
