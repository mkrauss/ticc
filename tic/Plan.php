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


    public function subplan($deployed_change_names, $target_change_name) {
        /*
         * Returns a new Plan representing the necessary changes to
         * deploy $target_change assuming that the array of
         * $deployed_changes are already deployed
         */
        $target_change = $this->find_change_by_name($target_change_name);

        $subplan = clone($this);
        $subplan->plan = F\select(
            $this->plan,
            function ($proposed_change)
                use ($deployed_change_names, $target_change) {
                    $proposed_change_name = $proposed_change->name();
                    return !F\contains($deployed_change_names, $proposed_change_name)
                        && $target_change->depends_on($proposed_change_name);});

        return $subplan;}


    public function find_change_by_name($change_name) {
        /*
         * Find the change named $change_name in the plan
         */
        $change = F\pick(
            $this->plan,
            $change_name,
            null,
            function ($change) { return $change->name(); });

        if (isnull($change))
            throw new BadChangeException(
                "Cannot find change named {$change_name}");

        return $change;}


    private function changes($change_dirname) {
        /*
         * Get all plan files under $change_dirname
         */
        $recurser = function($change_dirname)
            use (&$recurser) {
                $change_plan = [
                    'change_name' => $change_dirname,
                    'dependencies' => []];

                $subchanges = [];

                foreach(scandir(empty($change_dirname) ? '.' : $change_dirname)
                        as $filename) {
                    $path = empty($change_dirname)
                        ? $filename
                        : "{$change_dirname}/{$filename}";

                    if ($filename === '.' || $filename === '..')
                        continue;

                    else if (is_dir($path))
                        $subchanges = array_merge(
                            $subchanges,
                            $recurser($path));

                    else if (fnmatch('plan.json', $filename)) {
                        $change_plan = array_replace_recursive(
                            $change_plan,
                            json_decode(file_get_contents($path), true) ?? []);}

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
