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

namespace ticc\PlanRunner;

use Functional as F;
use ticc\Plan;
use ticc\Change;

class ExportSqlPlanRunner implements \ticc\PlanRunner {
    /*
     * Plan runner that exports all the SQL it would run to the output
     */

    private function export_change($action, $name, $script) {
        /*
         * Prints out a change with some niceties
         */
        print("---\n");
        print("--- {$action} {$name}\n");
        print("---\n");
        print("\n");
        print($script);
        print("\n");}

    public function deploy_plan(Plan $plan) {
        /*
         * Deploy all changes in $plan
         */
        $plan->inject_changes_to(
            function (Change $change) {
                if (!is_null($change->deploy_script())) {
                    $this->export_Change('Deploy', $change->name(), $change->deploy_script());}});}


    public function revert_plan(Plan $plan) {
        /*
         * Revert all changes in $plan
         */
        $plan->inject_changes_to(
            function (Change $change) {
                if (!is_null($change->revert_script())) {
                    $this->export_change('Revert', $change->name(), $change->revert_script());}});}


    public function verify_plan(Plan $plan) {
        /*
         * Verify all changes in $plan
         */
        $plan->inject_changes_to(
            function (Change $change) {
                if (!is_null($change->verify_script())) {
                    $this->export_change('Verify', $change->name(), $change->verify_script());}});}}
