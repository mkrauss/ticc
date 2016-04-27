#!/usr/bin/env php
<?php
/**
 * Transactional Iterative Changes - Manage database changes
 *
 * Copyright (C) 2016 Matthew Krauss
 * Copyright (C) 2016 Matthew Carter
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Report issues at: https://github.com/mkrauss/ticc
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/vendor/alexkazik/getopts/getopts.php';

try {
    echo <<<EOT
Transactional Iterative Changes - Copyright (C) 2016 Matthew Krauss

This program comes with ABSOLUTELY NO WARRANTY; for details type `$argv[0] license -w'.
This is free software, and you are welcome to redistribute it
under certain conditions; type `$argv[0] license -c' for details.





EOT;
    (new \ticc\Ticc ($argv))->run();
} catch (\Exception $e) {
    // On exception, print to STDERR and leave with the thrown error code
    $stderr = fopen('php://stderr', 'rw');
    fputs($stderr, 'FATAL ERROR:' . PHP_EOL);
    fputs($stderr, substr($e->getMessage(), 0, 1022) . PHP_EOL . PHP_EOL, 1024);
    fclose($stderr);

    exit($e->getCode());
}
