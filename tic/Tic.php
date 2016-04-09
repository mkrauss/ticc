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

        var_export(['called_as' => $this->called_as,
                    'command' => $this->command,
                    'params' => $this->params,
                    'args' => $this->args,
                    'config' => $this->config]);
        echo PHP_EOL;

        $this->load_plan(F\pick($this->config, 'plan_directory', '.'));

        $this->connect_db();}


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

        $database_engine = 'pgsql';

        extract($this->config['database'], EXTR_PREFIX_ALL, 'database');

        $this->database = new \PDO(
            "{$database_engine}:"
            . (isset($database_host) ? "host={$database_host};" : '')
            . (isset($database_port) ? "port={$database_port};" : '')
            . (isset($database_name) ? "dbname={$database_name}" : ''));}


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
        echo $this->intended_plan()->overview();
    }


    private function run_deploy() {
        /*
         * Deploy a set of changes - a target change and all
         * dependencies of it, or all un-deployed changes.
         */

        $this->db()->transaction(function($db) {
            $this->intended_plan()->deploy($db);});}


    private function load_plan($plan_dir) {
        /*
         * Loads a plan of changes from the given $plan_dir
         */

        $this->plan = new Plan ($plan_dir);}


    private function intended_plan() {
        /*
         * Returns a potential plan based on the command line
         * parameters
         */
        return empty($this->args)
            ? $this->plan
            : $this->plan->subplan(
                $this->deployed_changes(),
                array_shift($this->args));}


    private $called_as;
    private $command;
    private $options;
    private $params;
    private $args;
    private $config;
    private $database;
    private $plan;
}
