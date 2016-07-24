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

        $this->connect_db();

        $this->find_change_dir();

        $this->load_plans();

        $this->build_plan_runner();}


    private function load_parameters($argv) {
        /*
         * Parse options
         */
        list($errors, $this->params, $this->args) = getopts(
            [
              'BAD config file' => ['Vs', 'c', 'conf'],
              'verbose' => ['Ss', 'v', 'verbose']
            ],
            $argv);

        // If flag options come in before the command, it will think they are
        // the command, therefore they need to be cleaned out.
        $argv = F\select($argv, function ($arg) {
          return strpos($arg, '-') === false;});

        $this->called_as = array_shift($argv);
        $this->command = array_shift($argv);

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


    public function build_plan_runner() {
        /*
         * Instantiate our PlanRunner object
         */
        $this->plan_runner = new PlanRunner($this->database);
    }


    public function run() {
        /*
         * Main entry point - switches control based on command
         */

        // var_export($this->masterplan->minus($this->deployedplan)); die(PHP_EOL);

        switch ($this->command) {
            case 'deploy': $this->run_deploy(); break;
            case 'revert': $this->run_revert(); break;
            case 'sync': $this->run_sync(); break;
            case 'move': case 'mv': $this->run_move(); break;
            case 'verify': $this->run_verify(); break;
            default: throw new exception\BadCommandException(
                'Must give valid command: [deploy|revert|sync|move|verify]', 0x0c);}}


    private function run_deploy() {
        /*
         * Deploy a set of changes - a target change and all
         * dependencies of it, or all un-deployed changes.
         */
        if($this->masterplan->minus($this->deployedplan)->plan_empty_p()) {
            // @todo Not sure if this should be an exception, but it should be
            // clearer when user is running in a non-working directory (such
            // as the project top level with no direct sub-directories that
            // have the appropriate plan.json files)
            throw new exception\EmptyPlanException(
                'No plan items to deploy (are you in a directory that has directories with a plan.json file in them?)'
            );}

        $this->database->with_protection(function() {
            $this->plan_runner->deploy_plan(
                $this->plan_to_deploy()->minus($this->deployedplan));});}


    private function run_revert() {
        /*
         * Revert all changes made back to and including the one
         * specified in the command
         */
        // var_export($this->deployedplan->reverse()); die(PHP_EOL);
        $this->database->with_protection(function() {
            $this->plan_runner->revert_plan(
                $this->plan_to_revert()->reverse());});}


    private function run_sync() {
        /*
         * Revert changes which are different or removed in the
         * current deployed plan; then deploy all undeployed changes
         * from the master plan.
         */
        $this->database->with_protection(function() {

            $stale_plan = $this->deployedplan
                ->different_from($this->masterplan);

            $this->plan_runner->revert_plan($stale_plan->reverse());

            echo PHP_EOL;

            $this->plan_runner->deploy_plan(
                $this->plan_to_deploy()->minus($this->deployedplan
                                               ->minus($stale_plan)));});}


    public function run_verify() {
        /*
         * Find the minimum subplan to deploy <change> and, for each
         * Change, run the verify script to confirm it is legitimately
         * deployed and mark it so.
         */
        $this->database->with_protection(function() {
            $this->plan_runner->verify_plan($this->plan_to_deploy());});}


    private function run_move() {
        /*
         * Move a change in the source, updating dependencies and
         * deployed plan
         */
        if (count($this->args) < 2) {
            throw new exception\BadCommandException(
                'Please provide an origin change to move, and a destination.');}

        $old = array_shift($this->args);

        if ($old === "/") {
            throw new exception\BadCommandException(
                "Cannot move top level change");}

        $new = array_shift($this->args);

        if ($new === $old) {
            throw new exception\BadCommandException(
                "Cannot move a change to itself");}

        $this->change_directory->remove_change_named($old);

        $this->masterplan
            ->explicit_dependencies($old)
            ->move_change($old, $new)
            ->inject_changes_to(
                [$this->change_directory, 'write_change']);

        $this->database->rename_change($old, $new);
    }


    private function plan_to_deploy() {
        /*
         * Takes the next argument as a Change name and returns a
         * minimum subset of the master plan to deploy that Change; if
         * there is no next argument, returns the complete master
         * plan.
         */
        return (count($this->args) >= 1
                ? $this->masterplan->subplan(array_shift($this->args))
                : $this->masterplan);}


    private function plan_to_revert() {
        /*
         * Takes the next argument as a Change name and returns a
         * minimum subset of the deployed plan to revert that Change;
         * if there is no next argument, returns the complete deployed
         * plan.
         */
        return (count($this->args) >= 1
                ? $this->deployedplan->superplan(array_shift($this->args))
                : $this->deployedplan);}


    private function load_plans() {
        /*
         * Loads a plan of changes from the given $plan_dir
         */

        $this->masterplan = new Plan($this->change_directory->changes());
        $this->deployedplan = new Plan($this->database->deployed_changes());}


    public function get_parameter(string $key) {
        /*
        * Get a setting from the params array, or null if not set
        */
        return F\pick($this->params, $key, null);}


    private $called_as;
    private $command;
    private $options;
    private $params;
    private $args;
    private $config;
    private $database;
    private $plan_runner;
    private $masterplan;
    private $deployedplan;
    private $change_directory;
}
