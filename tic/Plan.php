<?php

namespace tic;

use Functional as F;

class Plan {
    public function __construct($change_dirname) {
        /*
         * Configure the plan
         */
        $this->plan = functions\topological_sort(
            array_map(
                function ($change_file) { return new Change($change_file); },
                $this->change_files($change_dirname)),
            function ($change) { return $change->name(); },
            function ($change) { return $change->dependencies(); });}


    public function change_files($change_dirname) {
        /*
         * Get all plan files under $change_dirname
         */
        $recurser = function($change_dirname) use (&$recurser) {
            return array_map(
                function($filename) use ($change_dirname, $recurser) {
                    $path = "{$change_dirname}/{$filename}";

                    if ($filename === '.' || $filename === '..')
                        return [];

                    if (is_dir($path))
                        return $recurser($path);

                    if (!fnmatch('*.change', $filename))
                        return [];

                    return $path;},
                scandir($change_dirname));};

        return F\flatten($recurser($change_dirname));}


    private $plan;
}
