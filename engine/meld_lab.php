<?php
declare(strict_types=1);

require_once __DIR__ . '/Cards.php';
require_once __DIR__ . '/Meld.php';

// -----------------------------------------------------------------------------
// meld_lab — ad hoc / matrix exploration of Meld::detect(), for checking how
// partial and doubled combos (two kings but one queen, double pinochle, etc.)
// add up. Not part of the automated test suite (see harness.php for that) —
// this is a manual tool, driven by whoever is testing it, not a set of
// pre-judged assertions.
//
// Two ways to drive it:
//   CLI:     php engine/meld_lab.php S KS KS QS AH AD
//            (first arg is trump: S/H/D/C/NT; the rest are card codes,
//             duplicates allowed, doesn't need to be a real 5-card hand)
//   Browser: /poker-pinochle/dev.php?meld=1  (a form — see dev.php)
//
// meld_lab_render() / meld_lab_scenarios() below are reusable by both.
// -----------------------------------------------------------------------------

/** Render one hand's meld breakdown as plain text (no trailing exit/echo). */
function meld_lab_render(array $hand, string $trump): string
{
    $labels = implode(' ', array_map(['Cards', 'label'], $hand));
    $det = Meld::detect($hand, $trump);

    $out = "hand: {$labels}   trump: " . Cards::SUIT_GLYPH[$trump] . "\n";
    if (empty($det['items'])) {
        $out .= "  (no meld)\n";
    } else {
        foreach ($det['items'] as $it) {
            $cards = implode('+', array_map(['Cards', 'label'], $it['cards']));
            $out .= sprintf("  %-18s %-10s +%d\n", $it['label'], "[{$cards}]", $it['points']);
        }
    }
    $out .= "  total: {$det['total']}\n";
    return $out;
}

/**
 * A reference set of doubled/partial-combo hands, covering the cases that
 * exercise Meld::detect()'s min-count logic. Purely illustrative — not
 * assertions; read the output and judge for yourself.
 *
 * @return array<int, array{0:string,1:string[],2:string}>  [label, hand, trump]
 */
function meld_lab_scenarios(): array
{
    return [
        ['1 king + 1 queen (trump) = single royal marriage',   ['KS', 'QS'],                     'S'],
        ['2 kings + 2 queens (trump) = double royal marriage', ['KS', 'KS', 'QS', 'QS'],         'S'],
        ['2 kings + 1 queen (trump) = only 1 marriage',        ['KS', 'KS', 'QS'],                'S'],
        ['1 king + 2 queens (trump) = only 1 marriage',        ['KS', 'QS', 'QS'],                'S'],
        ['2 kings only, no queen = no marriage',               ['KS', 'KS'],                      'S'],

        ['1 king + 1 queen (off-trump) = single marriage',     ['KH', 'QH'],                      'S'],
        ['2 kings + 2 queens (off-trump) = double marriage',   ['KH', 'KH', 'QH', 'QH'],          'S'],

        ['2 kings + 2 queens under NT = double, off value',    ['KS', 'KS', 'QS', 'QS'],          'NT'],

        ['1 nine of trump',                                    ['9S'],                            'S'],
        ['2 nines of trump',                                   ['9S', '9S'],                      'S'],
        ['2 nines, but NT declared = no meld',                 ['9S', '9S'],                      'NT'],
        ['nine of an off-suit does not meld',                  ['9H'],                             'S'],

        ['1 pinochle (QS + JD)',                               ['QS', 'JD'],                      'S'],
        ['double pinochle (2x QS + 2x JD)',                    ['QS', 'QS', 'JD', 'JD'],          'S'],
        ['2 QS + 1 JD = only 1 pinochle',                      ['QS', 'QS', 'JD'],                'S'],
        ['1 QS + 2 JD = only 1 pinochle',                      ['QS', 'JD', 'JD'],                'S'],

        ['reuse: 1 QS shared by marriage + pinochle',          ['KS', 'QS', 'JD'],                'S'],
        ['reuse: both doubled at once (2x KS/QS/JD)',          ['KS', 'KS', 'QS', 'QS', 'JD', 'JD'], 'S'],

        ['four aces (one per suit)',                           ['AS', 'AH', 'AD', 'AC'],          'S'],
        ['four aces + a duplicate ace = still just one meld',  ['AS', 'AS', 'AH', 'AD', 'AC'],    'S'],
    ];
}

// ------------------------------------------------------------- CLI entry point
// Only runs when this file is the script php was invoked with directly (not
// when dev.php requires it for the functions above).
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $args = array_slice($argv, 1);

    if (empty($args)) {
        foreach (meld_lab_scenarios() as [$label, $hand, $trump]) {
            echo "-- {$label} --\n" . meld_lab_render($hand, $trump) . "\n";
        }
        exit(0);
    }

    $trump = strtoupper(array_shift($args));
    if (!in_array($trump, ['S', 'H', 'D', 'C', 'NT'], true)) {
        fwrite(STDERR, "First arg must be trump: S, H, D, C or NT.\n");
        exit(1);
    }
    $hand = array_map('strtoupper', $args);
    foreach ($hand as $c) {
        if (!Cards::isValid($c)) {
            fwrite(STDERR, "Bad card code '{$c}'.\n");
            exit(1);
        }
    }
    echo meld_lab_render($hand, $trump);
}
