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

class Change {
    public function __construct($change_plan) {
        /*
         * Parse and load the $change_file
         */

        if (!isset($change_plan['change_name']))
            throw new exception\BadChangeException('Change missing name');

        $this->name = $change_plan['change_name'];
        $this->dependencies = F\pick($change_plan, 'dependencies', []);
        $this->explicit_dependencies = F\pick($change_plan, 'explicit_dependencies', []);

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
            && $this->revert_script === $other->revert_script;}


    public $name;
    public $dependencies;
    public $explicit_dependencies;
    public $deploy_script = null;
    public $revert_script = null;
    public $verify_script = null;
}
