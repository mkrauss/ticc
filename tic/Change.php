<?php

namespace tic;

use Functional as F;

class Change {
    public function __construct($change_plan) {
        /*
         * Parse and load the $change_file
         */

        if (empty($change_plan['change_name']))
            throw new BadChangeException('Missing name');

        $this->name = $change_plan['change_name'];
        $this->dependencies = F\pick($change_plan, 'dependencies', []);
        $this->deploy_script = F\pick($change_plan, 'deploy_script', '');
        $this->revert_script = F\pick($change_plan, 'revert_script', '');
        $this->verify_script = F\pick($change_plan, 'verify_script', '');}


    public function dependencies() {
        /*
         * Return the list of changes this one depends on
         */

        return $this->dependencies;}


    public function name() {
        /*
         * Return the name of this change
         */

        return $this->name;}


    private $name;
    private $dependencies;
    private $deploy_script;
    private $revert_script;
    private $verify_script;
}
