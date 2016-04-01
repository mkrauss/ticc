<?php

namespace tic;

use Functional as F;

class Tic {
    public function __construct($argv) {
        /*
         * Parse options
         */
        $this->called_as = array_shift($argv);
        $this->command = $argv[0];

        list($errors, $params, $args) = getopts(
            ['f' => ['Vs', 'f', 'file'],
             'g' => ['Vs', 'g']],
            $argv);

        if ($errors) {
            // Handle errors?
            var_dump($errors);
            exit;}}


    public function run() {
        /*
         * Main function
         */

        switch ($this->command) {
            case 'deploy': $this->run_deploy();
            case 'revert': $this->run_revert();
            case 'redeploy': $this->run_redeploy();
            default: echo "Bad command!\n"; exit;}}

    private function deploy() {
        
    }

    private $called_as;
    private $command;
    private $options;
}
