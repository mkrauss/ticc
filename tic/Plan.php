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


    public function subplan($deployed_change_names, $target_change_name=null) {
        /*
         * Returns a new Plan representing the necessary changes to
         * deploy $target_change assuming that the array of
         * $deployed_changes are already deployed
         */
        $subplan = clone($this);

        $subplan->plan = F\select(
            $this->plan,

            is_null($target_change_name)

            ? function ($proposed_change) use ($deployed_change_names) {
                return !F\contains($deployed_change_names,
                                   $proposed_change->name());}

            : function ($proposed_change)
                use ($deployed_change_names, $target_change_name) {
                    $proposed_change_name = $proposed_change->name();
                    return !F\contains($deployed_change_names,
                                       $proposed_change_name)
                        && $this->dependency_exists($target_change_name,
                                                    $proposed_change_name);});

        return $subplan;}


    public function dependency_exists($dependant_name, $dependency_name) {
        /*
         * Does Change $dependant depends directly or indirectly on
         * Change $dependency?
         */
        return $dependant_name === $dependency_name
            || F\some(
                $this->find_change_by_name($dependant_name)->dependencies(),
                function ($dependant_name) use ($dependency_name) {
                    $this->dependency_exists($dependant_name,
                                             $dependency_name);});}

    // public function dependency_exists($dependant, $dependency_name) {
    //     /*
    //      * Does Change $dependant depends directly or indirectly on
    //      * Change $dependency?
    //      */
    //     return $dependant->name() === $dependency_name
    //         || F\some(
    //             $dependant->dependencies(),
    //             function ($dependant_name) use ($dependency_name) {
    //                 $this->dependency_exists(
    //                     $this->find_change_by_name($dependant_name),
    //                     $dependency_name);});}


    public function find_change_by_name($change_name) {
        /*
         * Find the change named $change_name in the plan
         */
        $change = F\first(
            $this->plan,
            function ($change) use ($change_name) {
                return $change_name === $change->name(); });

        if (is_null($change))
            throw new exception\BadChangeException(
                "Cannot find change named {$change_name}");

        return $change;}


    public function inject_changes_to($fn) {
        /*
         * Ask each change in the plan to inject_to $fn
         */
        foreach ($this->plan as $change)
            $change->inject_to($fn);}


    private function changes($change_dirname, $implicit_dependencies = []) {
        /*
         * Get all plan files under $change_dirname
         */
        $change_plan = [
            'change_name' => trim($change_dirname, '/'),
            'dependencies' => $implicit_dependencies];

        $subchanges = [];

        if (is_readable("{$change_dirname}plan.json")) {
            $change_plan_file = json_decode(file_get_contents(
                "{$change_dirname}plan.json"), true);

            if (isset($change_plan_file['change_name']))
                $change_plan['change_name'] = $change_plan_file['change_name'];

            if (isset($change_plan_file['dependencies']))
                $change_plan['dependencies'] = array_unique(array_merge(
                    $change_plan['dependencies'],
                    $change_plan_file['dependencies']));
        }

        if (is_readable("{$change_dirname}deploy.sql"))
            $change_plan['deploy_script'] = file_get_contents(
                "{$change_dirname}deploy.sql");

        if (is_readable("{$change_dirname}revert.sql"))
            $change_plan['revert_script'] = file_get_contents(
                "{$change_dirname}revert.sql");

        if (is_readable("{$change_dirname}verify.sql"))
            $change_plan['verify_script'] = file_get_contents(
                "{$change_dirname}verify.sql");

        foreach(scandir(empty($change_dirname) ? '.' : $change_dirname)
                as $filename)

            if ($filename !== '.' && $filename !== '..'
                && is_dir("{$change_dirname}{$filename}"))

                $subchanges = array_merge(
                    $subchanges,
                    $this->changes("{$change_dirname}{$filename}/",
                                   $change_plan['dependencies']));

        $change_plan['dependencies'] = array_unique(array_merge(
            $change_plan['dependencies'],
            array_map(
                function ($subchange) { return $subchange->name(); },
                $subchanges)));

        return array_merge(
            [new Change ($change_plan)],
            $subchanges);}


    private $plan;
}
