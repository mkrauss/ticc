<?php

/*
 * Transactional Iterative Changes
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/vendor/alexkazik/getopts/getopts.php';

(new \tic\Tic ($argv))->run();

// var_export((new \tic\Plan(''))->plan_files('.')); echo PHP_EOL;

// var_export(functions\topological_sort(
//     [['key' => 'bake cookies',
//       'depends' => ['prepare cookie sheet',
//                     'preheat oven',
//                     'mix ingredients']],
//      ['key' => 'buy ingredients', 'depends'
//       => []],
//      ['key' => 'prepare cookie sheet', 'depends'
//       => ['prepare utensils']],
//      ['key' => 'mix ingredients', 'depends'
//       => ['prepare utensils',
//           'buy ingredients']],
//      ['key' => 'preheat oven', 'depends'
//       => ['prepare utensils']],
//      ['key' => 'prepare utensils', 'depends'
//       => []]],
//     function ($el) { return $el['key']; },
//     function ($el) { return $el['depends']; }));
// echo PHP_EOL;
