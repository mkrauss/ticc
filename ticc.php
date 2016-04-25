<?php

/*
 * Transactional Iterative Changes
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/vendor/alexkazik/getopts/getopts.php';

(new \ticc\Ticc ($argv))->run();
