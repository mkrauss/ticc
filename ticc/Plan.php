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

namespace ticc;

use Functional as F;

class Plan {
    public function __construct($changes) {
        /*
         * Configure the plan
         */
        $this->plan = \functions\topological_sort(
            $changes,
            function ($change) { return $change->name(); },
            function ($change) { return $change->dependencies(); });}


    public function minus(Plan $other) {
        /*
         * Return a new plan representing the Changes in this plan
         * which do not appear, by name, in $other.
         */
        $result = clone($this);

        $result->plan = F\reject(
            $this->plan,
            function (Change $change) use ($other) {
                return F\some(
                    $other->plan,
                    function (Change $otherchange) use ($change) {
                        return $change->name() === $otherchange->name();});});

        return $result;}


    public function reverse() {
        /*
         * Return a new plan representing the reverse of this one.
         */
        $result = clone($this);
        $result->plan = array_reverse($result->plan);
        return $result;}


    public function different_from(Plan $other) {
        /*
         * Return a new plan representing the Changes in this plan
         * which are either missing (by name) or changed (by scripts)
         * in $other, as well as any changes in this plan which depend
         * on those.
         *
         * Two Hard Things. This function name is unclear.
         */
        $result = clone($this);
        $included_names = [];

        $result->plan = F\select(
            $this->plan,
            function (Change $change) use ($other, &$included_names) {
                $include
                    = F\some(
                        $included_names,
                        function (string $included_name) use ($change) {
                            return $change->depends_on($included_name);})
                    || F\none(
                        $other->plan,
                        function (Change $otherchange) use ($change) {
                            return $change->equivalent_to($otherchange);});

                if ($include) array_push($included_names, $change->name());

                return $include;});

        return $result;}


    public function move_change(string $source_name, string $dest_name) {
        /*
         * Return a new Plan with the Change indicated by $source_name
         * changed to $dest_name, and all dependencies that point to
         * it updated.
         */
        $result = clone($this);

        $result_name = function ($name) use ($source_name, $dest_name) {
            return $name === $source_name
                ? $dest_name
                : $name;};

        $map_names = F\partial_left('array_map', $result_name);

        $result->plan = array_map(
            function (Change $change) use ($result_name, $map_names) {
                return new Change([
                    'change_name' => $result_name($change->name),
                    'dependencies' => $map_names(
                        $change->dependencies),
                    'explicit_dependencies' => $map_names(
                        $change->explicit_dependencies),
                    'deploy_script' => $change->deploy_script,
                    'revert_script' => $change->revert_script,
                    'verify_script' => $change->verify_script]);},
            $this->plan);

        return $result;}


    public function explicit_dependencies($change_name) {
        /*
         * Return a Plan containing only the Change $change_name and
         * those directly explicitly depending on it.
         */
        $result = clone($this);

        $result->plan = array_filter(
            $this->plan,
            function (Change $change) use ($change_name) {
                return $change->name === $change_name
                    || in_array($change_name, $change->explicit_dependencies); });

        return $result;}


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


    public function dependency_exists(string $dependant_name, string $dependency_name) {
        /*
         * Does Change $dependant depends directly or indirectly on
         * Change $dependency?
         */
        return $dependant_name === $dependency_name
            || F\some(
                $this->find_change_by_name($dependant_name)->dependencies(),
                function ($dependant_name) use ($dependency_name) {
                    return $this->dependency_exists($dependant_name,
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
         * Call $fn for each change in the plan
         */
        foreach ($this->plan as $change) $fn($change);}


    private $plan;
}
