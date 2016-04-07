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
                    'args' => $this->args]);
        echo PHP_EOL;

        $this->connect_db();}


    private function load_parameters($argv) {
        /*
         * Parse options
         */

        $this->called_as = array_shift($argv);
        $this->command = array_shift($argv);

        list($errors, $this->params, $this->args) = getopts(
            ['config file' => ['Vs', 'c', 'conf']],
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
                F\pick($this->params, 'config file', 'tic.conf')), true);}


    public function run() {
        /*
         * Main entry point - switches control based on command
         */

        switch ($this->command) {
            case 'deploy': $this->run_deploy(); break;
            case 'revert': $this->run_revert(); break;
            case 'redeploy': $this->run_redeploy(); break;
            default: throw new \tic\exception\BadCommandException(
                'Must give valid command');}}


    private function run_deploy() {
        /*
         * Deploy a set of changes - a target change and all
         * dependencies of it, or all un-deployed changes.
         */

        $this->db()->transaction(function($db) {
            $this->deploy_changes(
                empty($this->args)
                ? $this->plan
                : $this->plan->subplan(
                    $this->deployed_changes(),
                    array_shift($this->args)));});}


    private function load_plan($plan_dir) {
        /*
         * Loads a plan of changes from the given $plan_dir
         */

        $this->plan = new Plan ($plan_dir);}


    private $called_as;
    private $command;
    private $options;
    private $params;
    private $args;
    private $config;
}
