<?php

namespace ticc;

use Functional as F;

class Change {
    public function __construct($change_plan) {
        /*
         * Parse and load the $change_file
         */

        if (!isset($change_plan['change_name']))
            throw new exception\BadChangeException('Change missing name');

        $this->name = $change_plan['change_name'];
        $this->dependencies = F\pick($change_plan, 'dependencies', []);

        if (isset($change_plan['deploy_script'])
            && isset($change_plan['revert_script'])
            && isset($change_plan['verify_script'])) {

            $this->deploy_script = F\pick($change_plan, 'deploy_script');
            $this->revert_script = F\pick($change_plan, 'revert_script');
            $this->verify_script = F\pick($change_plan, 'verify_script');}

        elseif (isset($change_plan['deploy_script'])
                || isset($change_plan['revert_script'])
                || isset($change_plan['verify_script'])) {
            throw new exception\BadChangeException("Incomplete change {$this->name}");}}


    public function name() {
        /*
         * Return the name of this change
         */

        return $this->name;}


    public function dependencies() {
        /*
         * Return the list of changes this one immediately depends on
         */

        return $this->dependencies;}


    public function depends_on($change_name) {
        /*
         * Return true if this change directly depends on a change
         * called $change_name, otherwise false
         */
        return F\some(
            $this->dependencies,
            function ($dependency) use ($change_name) {
                return $dependency === $change_name;});}


    public function equivalent_to(Change $other) {
        /*
         * True if this change and $other are exactly equivalent -
         * same name and all scripts unchanged. Ignores changes in
         * dependencies as those represent "where the change fits"
         * rather than "what the change is"; or more materially,
         * because this is used to determine when a change needs to be
         * reverted for syncing, and if it is already deployed
         * successfully, a different dependencies do not indicate
         * re-deploying.
         */
        return $this->name === $other->name
            && $this->deploy_script === $other->deploy_script
            && $this->verify_script === $other->verify_script
            && $this->revert_script === $other->revert_script;
    }


    public function inject_to($fn) {
        /*
         * Call $fn passing it: $change_name, $change_plan, $deploy,
         * $verify, $revert
         */
        $fn($this->name,
            $this->dependencies,
            $this->deploy_script,
            $this->verify_script,
            $this->revert_script);}


    private $name;
    private $dependencies;
    private $deploy_script = null;
    private $revert_script = null;
    private $verify_script = null;
}
