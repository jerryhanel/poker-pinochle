<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $map = [
        'PokerPinochle\\Engine\\' => __DIR__ . '/',
        'PokerPinochle\\Lib\\'    => __DIR__ . '/../lib/',
    ];
    foreach ($map as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $file = $dir . substr($class, strlen($prefix)) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});
