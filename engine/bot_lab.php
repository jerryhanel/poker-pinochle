<?php
declare(strict_types=1);

// -----------------------------------------------------------------------------
// bot_lab — all-bot games with NO database and NO network. Safe to run anywhere.
//
//   php engine/bot_lab.php --verbose --profiles=l14,l14,l14,l14 --seed=7
//       one narrated 4-seat game (profile per seat: v1 | l14)
//
//   php engine/bot_lab.php --games=300 --a=l14 --b=v1 --seed=1
//       A vs B over many games, alternating which pair sits on which side
//
//   --set=key=value[,key=value]   override BotStrategy::$cfg for the l14 seats
// -----------------------------------------------------------------------------

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/../lib/BotPlayer.php';

use PokerPinochle\Engine\{Cards, Deck, State};
use PokerPinochle\Lib\{BotPlayer, BotStrategy};

$opt = [];
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--') === 0) {
        $kv = explode('=', substr($arg, 2), 2);
        $opt[$kv[0]] = $kv[1] ?? '1';
    }
}
if (isset($opt['set'])) {
    foreach (explode(',', $opt['set']) as $pair) {
        $kv = explode('=', $pair, 2);
        BotStrategy::$cfg[$kv[0]] = is_numeric($kv[1]) ? $kv[1] + 0 : $kv[1];
    }
}
$verbose = isset($opt['verbose']);
$seed    = (int) ($opt['seed'] ?? 1);

function lab_labels(array $cards): string
{
    return implode(' ', array_map([Cards::class, 'label'], $cards));
}

/** Play one whole game. $profiles = [seat => 'v1'|'l14']. Returns stats. */
function lab_game(array $profiles, int $seed, bool $verbose): array
{
    $g = new State(4, 'teams');
    $stats = [
        'hands' => 0, 'winner' => null, 'running' => [0, 0],
        'bid' => [0, 0], 'made' => [0, 0], 'bidScore' => [0, 0], 'defScore' => [0, 0],
    ];
    $guard = 0;
    while (!$g->isGameOver() && $guard++ < 60) {
        $g->startHand(Deck::shuffle(Deck::fresh(), $seed * 1000 + $guard));
        $hand = $g->hand;
        if ($verbose) {
            echo "\n================ HAND {$g->handNo}   dealer = seat {$g->dealerSeat}   score " . implode('-', $g->running) . " (target {$g->target}) ================\n";
            foreach ($hand['hands'] as $s => $cards) {
                printf("  seat %d [%s, side %d]: %s\n", $s, $profiles[$s], $g->seatSide($s), lab_labels($cards));
            }
        }
        while ($g->hand['phase'] === 'auction') {
            $s = $g->auctionTurnSeat();
            $d = BotPlayer::decideBid($g, $s, $profiles[$s]);
            $g->bid($s, $d['action'], $d['amount']);
        }
        if ($verbose) {
            $log = [];
            foreach ($g->hand['auction']['log'] as $e) {
                $log[] = 's' . $e['seat'] . ':' . ($e['action'] === 'bid' ? $e['amount'] : 'pass');
            }
            echo '  auction: ' . implode('  ', $log) . "  -> seat {$g->hand['bidWinnerSeat']} wins at {$g->hand['winningBid']}"
                . ($g->hand['stuck'] ? ' (stuck dealer)' : '') . "\n";
        }
        $b = $g->hand['bidWinnerSeat'];
        $g->declareInSuit($b, BotPlayer::decideDeclareSuit($g, $b, $profiles[$b]));
        if ($verbose) {
            echo '  declared: ' . Cards::SUIT_NAME[$g->hand['inSuit']] . "\n";
        }
        $pitches = [];
        foreach ($g->pendingDiscardSeats() as $s) {
            $pitch = BotPlayer::decideDraw($g, $s, $profiles[$s]);
            $pitches[$s] = $pitch;
            $g->draw($s, $pitch);
        }
        if ($verbose) {
            foreach ($pitches as $s => $p) {
                $drawn = $g->hand['drawRecord'][$s]['drawn'];
                printf("  draw seat %d: pitch [%s] drew [%s]\n", $s, lab_labels($p), lab_labels($drawn));
            }
            foreach ($g->hand['final'] as $s => $cards) {
                printf("  final seat %d: %s   meld %d\n", $s, lab_labels($cards), $g->hand['meldRecord'][$s]['total']);
            }
        }
        $trickNo = 0;
        while ($g->hand['phase'] === 'play') {
            $s = $g->playTurnSeat();
            $card = BotPlayer::decidePlay($g, $s, $profiles[$s]);
            if (count($g->hand['trick']['plays']) === 0) {
                $trickNo++;
                if ($verbose) {
                    echo "  trick {$trickNo}:";
                }
            }
            if ($verbose) {
                echo ' s' . $s . ':' . Cards::label($card);
            }
            $before = count($g->hand['tricks']);
            $g->play($s, $card);
            if ($verbose && count($g->hand['tricks']) > $before) {
                $t = $g->hand['tricks'][$before];
                echo "  -> seat {$t['winnerSeat']} ({$t['counters']})\n";
            }
        }
        $r = $g->handResult();
        $bs = $r['biddingSideNo'];
        $stats['hands']++;
        $stats['bid'][$bs]++;
        if ($r['made']) {
            $stats['made'][$bs]++;
        }
        $stats['bidScore'][$bs] += $r['handScores'][$bs];
        $stats['defScore'][1 - $bs] += $r['handScores'][1 - $bs];
        if ($verbose) {
            printf("  RESULT: side %d bid %d in %s: %s (total %d). Hand scores %s. Running %s\n",
                $bs, $r['bid'], $g->hand['inSuit'], $r['made'] ? 'MADE' : 'SET', $r['biddingTotal'],
                implode('/', $r['handScores']), implode('-', $r['runningAfter']));
        }
    }
    $stats['winner'] = $g->gameWinnerSideNo;
    $stats['running'] = $g->running;
    return $stats;
}

if ($verbose) {
    $profiles = isset($opt['profiles']) ? explode(',', $opt['profiles']) : ['l14', 'l14', 'l14', 'l14'];
    $st = lab_game($profiles, $seed, true);
    echo "\n=== GAME OVER: side {$st['winner']} wins, final " . implode('-', $st['running']) . " after {$st['hands']} hands ===\n";
    echo 'fallbacks: ' . count(BotPlayer::$fallbacks) . "\n";
    foreach (array_slice(array_unique(BotPlayer::$fallbacks), 0, 5) as $m) {
        echo "  $m\n";
    }
    exit(0);
}

$games = (int) ($opt['games'] ?? 100);
$a = $opt['a'] ?? 'l14';
$b = $opt['b'] ?? 'v1';
$aWins = 0; $aPts = 0; $bPts = 0; $hands = 0;
$aBid = 0; $aMade = 0; $bBid = 0; $bMade = 0; $aBidScore = 0; $bBidScore = 0; $aDef = 0; $bDef = 0;
for ($i = 0; $i < $games; $i++) {
    $aSide = $i % 2;                       // alternate who sits on side 0
    $profiles = $aSide === 0 ? [$a, $b, $a, $b] : [$b, $a, $b, $a];
    $st = lab_game($profiles, $seed + $i, false);
    $bSide = 1 - $aSide;
    if ($st['winner'] === $aSide) {
        $aWins++;
    }
    $aPts += $st['running'][$aSide];
    $bPts += $st['running'][$bSide];
    $hands += $st['hands'];
    $aBid += $st['bid'][$aSide];   $aMade += $st['made'][$aSide];   $aBidScore += $st['bidScore'][$aSide];
    $bBid += $st['bid'][$bSide];   $bMade += $st['made'][$bSide];   $bBidScore += $st['bidScore'][$bSide];
    $aDef += $st['defScore'][$aSide]; $bDef += $st['defScore'][$bSide];
}
printf("%s vs %s over %d games (%d hands, seeds %d..%d)\n", $a, $b, $games, $hands, $seed, $seed + $games - 1);
printf("  %s wins %d (%.1f%%)   final-score avg %s %.1f  %s %.1f\n", $a, $aWins, 100 * $aWins / $games, $a, $aPts / $games, $b, $bPts / $games);
printf("  %s: bid %d hands, made %.0f%%, avg bid-hand score %+.2f; avg defending score %.2f\n", $a, $aBid, $aBid ? 100 * $aMade / $aBid : 0, $aBid ? $aBidScore / $aBid : 0, ($hands - $aBid) ? $aDef / ($hands - $aBid) : 0);
printf("  %s: bid %d hands, made %.0f%%, avg bid-hand score %+.2f; avg defending score %.2f\n", $b, $bBid, $bBid ? 100 * $bMade / $bBid : 0, $bBid ? $bBidScore / $bBid : 0, ($hands - $bBid) ? $bDef / ($hands - $bBid) : 0);
echo 'fallbacks: ' . count(BotPlayer::$fallbacks) . "\n";
foreach (array_slice(array_unique(BotPlayer::$fallbacks), 0, 5) as $m) {
    echo "  $m\n";
}
