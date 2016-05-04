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

class Ticc {
    public function __construct($argv) {
        /*
         * Initialize application
         */

        $this->load_parameters($argv);

        $this->load_config();

        // var_export(['called_as' => $this->called_as,
        //             'command' => $this->command,
        //             'params' => $this->params,
        //             'args' => $this->args,
        //             'config' => $this->config]);
        // echo PHP_EOL;

        $this->connect_db();

        $this->find_change_dir();

        $this->load_plans();}


    private function load_parameters($argv) {
        /*
         * Parse options
         */

        $this->called_as = array_shift($argv);
        $this->command = array_shift($argv);

        list($errors, $this->params, $this->args) = getopts(
            ['BAD config file' => ['Vs', 'c', 'conf']],
            $argv);

        if ($errors) {
            // Handle errors?
            var_dump($errors); echo PHP_EOL;
            exit;}}


    private function load_config() {
        /*
         * Load the configuration file
         */
        if (!file_exists(F\pick($this->params, 'config file', 'ticc.json'))) {
            throw new \Exception(
                'Please copy ticc.sample.json to ticc.json and customize as directed in the README.',
                0x0f
            );
        }

        $this->config = json_decode(
            file_get_contents(
                F\pick($this->params, 'config file', 'ticc.json')), true);}


    private function connect_db() {
        /*
         * Connect to the database
         */

        if (empty($this->config['database']))
            throw new exception\NoDatabaseException();

        $this->database = new Database($this->config['database']);}


    private function find_change_dir() {
        /*
         * Configure the master plan change directory
         */
        $this->change_directory = new ChangeSource(
            F\pick($this->config, 'plan_directory', ''));}


    public function run() {
        /*
         * Main entry point - switches control based on command
         */

        // var_export($this->masterplan->minus($this->deployedplan)); die(PHP_EOL);

        switch ($this->command) {
            case 'deploy': $this->run_deploy(); break;
            case 'revert': $this->run_revert(); break;
            case 'sync': $this->run_sync(); break;
            case 'redeploy': $this->run_redeploy(); break;
            case 'move': case 'mv': $this->run_move(); break;
            default: throw new exception\BadCommandException(
                'Must give valid command: [deploy|revert|sync|redeploy]', 0x0c);}}


    private function run_deploy() {
        /*
         * Deploy a set of changes - a target change and all
         * dependencies of it, or all un-deployed changes.
         */
        $this->database->with_protection(function() {
            $this->deploy_plan(
                $this->masterplan->minus(
                    $this->deployedplan));});}


    private function run_revert() {
        /*
         * Revert all changes made back to and including the one
         * specified in the command
         */
        // var_export($this->deployedplan->reverse()); die(PHP_EOL);
        $this->database->with_protection(function() {
            $this->revert_plan(
                $this->deployedplan->reverse());});}


    private function run_sync() {
        /*
         * Revert changes which are different or removed in the
         * current deployed plan; then deploy all undeployed changes
         * from the master plan.
         */
        $this->database->with_protection(function() {

            $stale_plan = $this->deployedplan
                ->different_from($this->masterplan);

            $this->revert_plan($stale_plan->reverse());

            echo PHP_EOL;

            $this->deploy_plan(
                $this->masterplan
                ->minus($this->deployedplan
                        ->minus($stale_plan)));});}


    private function run_move() {
        /*
         * Move a change in the source, updating dependencies and
         * deployed plan
         */
        if (count($this->args) < 2) {
            throw new exception\BadCommandException(
                'Please provide an origin change to move, and a destination.');}

        $old = array_shift($this->args);
        $new = array_shift($this->args);

        $this->change_directory->remove_change_named($old);

        $this->masterplan
            ->explicit_dependencies($old)
            ->move_change($old, $new)
            ->inject_changes_to(
                [$this->change_directory, 'write_change']);

        $this->database->rename_change($old, $new);
    }


    private function deploy_plan($plan) {
        /*
         * Revert all changes in $plan
         */
        $plan->inject_changes_to(
            function (Change $change) {
                echo "Deploying: {$change->name}... ";
                if (is_null($change->deploy_script))
                    echo "Nothing to deploy.\n";
                else {
                    $this->database->deploy_change($change);
                    echo " Done.\n";}
                $this->database->mark_deployed($change);});}


    private function revert_plan($plan) {
        /*
         * Revert all changes in $plan
         */
        $plan->inject_changes_to(
            function (Change $change) {
                echo "Reverting: {$change->name}...";
                if (is_null($change->revert_script))
                    echo "Nothing to revert.\n";
                else {
                    $this->database->revert_change($change);
                    echo " Done.\n";}
                $this->database->unmark_deployed($change);});}


    private function load_plans() {
        /*
         * Loads a plan of changes from the given $plan_dir
         */

        $this->masterplan = new Plan($this->change_directory->changes());
        $this->deployedplan = new Plan($this->database->deployed_changes());}


    private $called_as;
    private $command;
    private $options;
    private $params;
    private $args;
    private $config;
    private $database;
    private $masterplan;
    private $deployedplan;
    private $change_directory;
}
