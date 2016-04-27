#!/usr/bin/env php
<?php

/*
 * Transactional Iterative Changes
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/vendor/alexkazik/getopts/getopts.php';

try {
    (new \ticc\Ticc ($argv))->run();
} catch (\Exception $e) {
    // On exception, print to STDERR and leave with the thrown error code
    $stderr = fopen('php://stderr', 'rw');
    fputs($stderr, substr($e->getMessage(), 0, 1022) . PHP_EOL . PHP_EOL, 1024);
    fclose($stderr);

    exit($e->getCode());
}
