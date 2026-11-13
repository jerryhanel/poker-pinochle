<?php

require_once __DIR__ . '/../engine/autoload.php';
require_once __DIR__ . '/Assert.php';

$skip = ['run.php', 'Assert.php'];

foreach (['Engine', 'Lib'] as $dir) {
    $files = glob(__DIR__ . '/' . $dir . '/*Test.php') ?: [];
    sort($files);
    foreach ($files as $file) {
        if (in_array(basename($file), $skip, true)) {
            continue;
        }
        require_once $file;
    }
}

Assert::summary();
