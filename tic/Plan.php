<?php

namespace tic;

use Functional as F;

class Plan {
    public function __construct($change_dirname) {
        /*
         * Configure the plan
         */
        $this->plan = \functions\topological_sort(
            $this->changes($change_dirname),
            function ($change) { return $change->name(); },
            function ($change) { return $change->dependencies(); });}


    public function changes($change_dirname) {
        /*
         * Get all plan files under $change_dirname
         */
        $recurser = function($change_dirname) use (&$recurser) {
            $change_plan = [
                'change_name' => basename($change_dirname),
                'dependencies' => []];

            $subchanges = [];

            foreach(scandir($change_dirname) as $filename) {
                $path = "{$change_dirname}/{$filename}";

                if ($filename === '.' || $filename === '..')
                    continue;

                else if (is_dir($path))
                    $subchanges = array_merge($subchanges, $recurser($path));

                else if (fnmatch('plan.json', $filename))
                    $change_plan = array_replace_recursive(
                        $change_plan,
                        json_decode($path, true) ?? []);

                else if (fnmatch('deploy.sql', $filename))
                    $change_plan['deploy_script'] = file_get_contents($path);

                else if (fnmatch('revert.sql', $filename))
                    $change_plan['revert_script'] = file_get_contents($path);

                else if (fnmatch('verify.sql', $filename))
                    $change_plan['verify_script'] = file_get_contents($path);}

            return array_merge(
                [new Change ($change_plan)],
                $subchanges);
        };
        // @TODO Maybe don't need the recurser now...

        return $recurser($change_dirname);}


    private $plan;
}
