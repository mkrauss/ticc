<?php

namespace tic;

use Functional as F;

class Tic {
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

        $this->load_plans(F\pick($this->config, 'plan_directory', ''));}


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
        
        $this->config = json_decode(
            file_get_contents(
                F\pick($this->params, 'config file', 'tic.json')), true);}


    private function connect_db() {
        /*
         * Connect to the database
         */

        if (empty($this->config['database']))
            throw new exception\NoDatabaseException();

        $this->database = new Database($this->config['database']);}


    public function run() {
        /*
         * Main entry point - switches control based on command
         */

        // var_export($this->masterplan->minus($this->deployedplan)); die(PHP_EOL);

        switch ($this->command) {
            case 'overview': $this->run_overview(); break;
            case 'deploy': $this->run_deploy(); break;
            case 'revert': $this->run_revert(); break;
            case 'sync': $this->run_sync(); break;
            case 'redeploy': $this->run_redeploy(); break;
            default: throw new exception\BadCommandException(
                'Must give valid command');}}


    private function run_overview() {
        /*
         * Display an overview of of the plan that would be executed
         * with DEPLOY. Takes the same arguments ad DEPLOY.
         */
        $this->intended_plan()->inject_changes_to(
            function ($name, $dependencies, $deploy, $verify, $revert) {
                echo "{$name}\n";});}


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


    private function deploy_plan($plan) {
        /*
         * Revert all changes in $plan
         */
        $plan->inject_changes_to(
            function (string $change_name,
                      array $dependencies,
                      string $deploy=null,
                      string $verify=null,
                      string $revert=null) {
                echo "Deploying: {$change_name}... ";
                if (is_null($deploy))
                    echo "Nothing to deploy.\n";
                else {
                    $this->database->deploy_change(
                        $change_name,
                        $dependencies,
                        $deploy,
                        $verify,
                        $revert);
                    echo " Done.\n";}
                $this->database->mark_deployed($change_name,
                                               $dependencies,
                                               $deploy,
                                               $verify,
                                               $revert);});}


    private function revert_plan($plan) {
        /*
         * Revert all changes in $plan
         */
        $plan->inject_changes_to(
            function (string $change_name,
                      array $dependencies,
                      string $deploy=null,
                      string $verify=null,
                      string $revert=null) {
                echo "Reverting: {$change_name}...";
                if (is_null($revert))
                    echo "Nothing to revert.\n";
                else {
                    $this->database->revert_change($revert);
                    echo " Done.\n";}
                $this->database->unmark_deployed($change_name);});}


    private function load_plans($plan_dir) {
        /*
         * Loads a plan of changes from the given $plan_dir
         */

        $this->masterplan = new Plan(Plan::changes($plan_dir));
        $this->deployedplan = new Plan($this->database->deployed_changes());}


    private function intended_plan() {
        /*
         * Returns a potential plan based on the command line
         * parameters
         */
        static $plan = null;

        if (is_null($plan))
            $plan = $this->plan->subplan(
                $this->database->deployed_changes(),
                empty($this->args) ? null : array_shift($this->args));

        return $plan;}


    private $called_as;
    private $command;
    private $options;
    private $params;
    private $args;
    private $config;
    private $database;
    private $masterplan;
    private $deployedplan;
}
