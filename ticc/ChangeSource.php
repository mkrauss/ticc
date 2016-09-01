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

class ChangeSource {
    /*
     * Directory tree of Changes.
     */

    public function __construct($directory) {
        /*
         * 
         */
        $this->directory = $directory;}


    public function changes(string $change_dirname = null,
                            array $implicit_dependencies = [])
        : array {
        /*
         * Get all plan files under our $directory
         */

        if (is_null($change_dirname)) $change_dirname = $this->directory;

        $change_plan = [
            'change_name' => trim($change_dirname, '/'),
            'dependencies' => $implicit_dependencies];

        $subchanges = [];

        if (is_readable("{$change_dirname}plan.json")) {
            $change_plan_file = json_decode(file_get_contents(
                "{$change_dirname}plan.json"), true);

            if (isset($change_plan_file['change_name']))
                $change_plan['change_name'] = $change_plan_file['change_name'];

            if (isset($change_plan_file['dependencies'])) {
                $change_plan['dependencies'] = array_unique(array_merge(
                    $change_plan['dependencies'],
                    $change_plan_file['dependencies']));
                $change_plan['explicit_dependencies']
                    = $change_plan_file['dependencies'];}}

        if (is_readable("{$change_dirname}deploy.sql"))
            $change_plan['deploy_script'] = file_get_contents(
                "{$change_dirname}deploy.sql");

        if (is_readable("{$change_dirname}revert.sql"))
            $change_plan['revert_script'] = file_get_contents(
                "{$change_dirname}revert.sql");

        if (is_readable("{$change_dirname}verify.sql"))
            $change_plan['verify_script'] = file_get_contents(
                "{$change_dirname}verify.sql");

        foreach(scandir(empty($change_dirname) ? '.' : $change_dirname)
                as $filename) {

            if ($filename !== '.' && $filename !== '..'
                && is_dir("{$change_dirname}{$filename}")) {

                $subchanges = array_merge(
                    $subchanges,
                    $this->changes("{$change_dirname}{$filename}/",
                                   $change_plan['dependencies']));}}

        $change_plan['dependencies'] = array_unique(array_merge(
            $change_plan['dependencies'],
            array_map(
                function ($subchange) { return $subchange->name(); },
                $subchanges)));

        return array_merge(
            [new Change ($change_plan)],
            $subchanges);}


    public function write_change(Change $change) {
        /*
         * Write a change back to directory tree form.
         */
        if (!is_dir($change->name())) mkdir($change->name(), 0777, true);

        if (!is_null($change->deploy_script())) {
            file_put_contents("./{$change->name()}/deploy.sql",
                              $change->deploy_script());}

        if (!is_null($change->verify_script())) {
            file_put_contents("./{$change->name()}/verify.sql",
                              $change->verify_script());}

        if (!is_null($change->revert_script())) {
            file_put_contents("./{$change->name()}/revert.sql",
                              $change->revert_script());}

        file_put_contents(
            "./{$change->name()}/plan.json",
            json_encode(['dependencies' => $change->explicit_dependencies()],
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));}


    public function remove_change_named($change_name) {
        /*
         * Remove the change named $change_name from the source
         */
        if (!is_dir($change_name)) {
            throw new exception\BadChange(
                "Change {$change_name} does not exist");}

        $this->remove_change_file($change_name, 'deploy.sql');
        $this->remove_change_file($change_name, 'verify.sql');
        $this->remove_change_file($change_name, 'revert.sql');
        $this->remove_change_file($change_name, 'plan.json');

        if (count(glob("./{$change_name}/*")) === 0 ) {
            if (!rmdir("./{$change_name}")) {
                throw new \Exception(
                    "Can't remove change directory {$change_name}");};}}


    private function remove_change_file($change_name, $file) {
        /*
         * 
         */
        if (file_exists("./{$change_name}/{$file}")) {
            if (!unlink("./{$change_name}/{$file}")) {
                throw new \Exception("Can't remove {$change_name}/{$file}");}}}


    private $directory;
}
