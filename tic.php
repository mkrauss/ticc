<?php

/*
 * Transactional Iterative Changes
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/vendor/alexkazik/getopts/getopts.php';

(new \tic\Tic ($argv))->run();
