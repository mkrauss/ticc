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

        switch ($this->command) {
            case 'overview': $this->run_overview(); break;
            case 'deploy': $this->run_deploy(); break;
            case 'revert': $this->run_revert(); break;
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
            $this->intended_plan()->inject_changes_to(
                function ($change_name, $plan, $deploy, $verify, $revert) {
                    echo "Deploying: {$change_name}... ";
                    if (is_null($deploy))
                        echo "Nothing to deploy.\n";
                    else {
                        $this->database->deploy_change($change_name, $plan, $deploy, $verify, $revert);
                        echo " Done.\n";}});});}


    private function run_revert() {
        /*
         * Revert all changes made back to and including the one
         * specified in the command
         */
        if (empty($this->args))
            throw new exception\BadCommandException(
                'Must give change to revert');
        $this->database->with_protection(function() {
            $this->database->revert_through(
                array_shift($this->args));});}


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
