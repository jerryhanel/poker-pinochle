<?php
declare(strict_types=1);

require_once __DIR__ . '/../../engine/autoload.php';
require_once __DIR__ . '/../Assert.php';

use PokerPinochle\Lib\BotStrategy;

$expectedDefaults = [
    'ceil_set'    => 2,
    'ev_bid'      => 1,
    'team_loosen' => 3,
    'meld_cap'    => 9,
    'near'        => 8,
    'starve'      => 1,
    'assume_low'  => 1,
    'lead_extra'  => 1.2,
    'feed'        => 1,
    'banked'      => 1,
];

test('defaults applied with no overrides', function () use ($expectedDefaults) {
    Assert::equal($expectedDefaults, (new BotStrategy())->cfg, 'BotStrategy/cfg: no overrides -> exactly the defaults');
    Assert::equal($expectedDefaults, (new BotStrategy([]))->cfg, 'BotStrategy/cfg: empty override array -> exactly the defaults');
});

test('overrides merge over defaults leaving rest intact', function () use ($expectedDefaults) {
    $s = new BotStrategy(['ceil_set' => 0]);
    Assert::equal(0, $s->cfg['ceil_set'], 'BotStrategy/cfg: ceil_set override applied');
    $rest = $s->cfg;
    unset($rest['ceil_set']);
    $restDefaults = $expectedDefaults;
    unset($restDefaults['ceil_set']);
    Assert::equal($restDefaults, $rest, 'BotStrategy/cfg: other keys untouched by a single override');

    $s = new BotStrategy(['starve' => 0, 'lead_extra' => 2.5]);
    Assert::equal(0, $s->cfg['starve'], 'BotStrategy/cfg: starve override applied');
    Assert::equal(2.5, $s->cfg['lead_extra'], 'BotStrategy/cfg: lead_extra override applied');
    Assert::equal(2, $s->cfg['ceil_set'], 'BotStrategy/cfg: ceil_set keeps default alongside other overrides');

    $s = new BotStrategy(['extra_knob' => 7]);
    Assert::equal(7, $s->cfg['extra_knob'], 'BotStrategy/cfg: unknown key is carried through');
    Assert::equal(count($expectedDefaults) + 1, count($s->cfg), 'BotStrategy/cfg: unknown key adds, does not replace');
});

test('all expected default keys present', function () use ($expectedDefaults) {
    $s = new BotStrategy();
    foreach (array_keys($expectedDefaults) as $key) {
        Assert::true(array_key_exists($key, $s->cfg), "BotStrategy/cfg: default key '{$key}' present");
    }
    Assert::equal(count($expectedDefaults), count($s->cfg), 'BotStrategy/cfg: no unexpected default keys');
});
