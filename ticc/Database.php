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

class Database {
    public function __construct($config) {
        /*
         * Connect to the database and ensure it is set up for Ticc
         */

        $this->in_transaction = false;
        $this->savepoint_count = 0;

        $this->schema = $config['ticc schema'] ?? 'ticc';

        $this->database = new \PDO (
            (isset($config['engine']) ? "{$config['engine']}:" : 'pgsql:')
            . (isset($config['host']) ? "host={$config['host']};" : '')
            . (isset($config['port']) ? "port={$config['port']};" : '')
            . (isset($config['name']) ? "dbname={$config['name']};" : ''),
            (isset($config['username']) ? $config['username'] : null),
            (isset($config['password']) ? $config['password'] : null));

        $this->database->setAttribute(\PDO::ATTR_ERRMODE,
                                      \PDO::ERRMODE_EXCEPTION);
        $this->database->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE,
                                      \PDO::FETCH_ASSOC);

        $this->ensure_tic();}


    public function with_protection($fn) {
        /*
         * Runs $fn safely inside either a transaction or simulated
         * sub-transaction using a savepoint
         */
        if (!$this->in_transaction)
            return $this->with_transaction($fn);
        else
            return $this->with_savepoint($fn);}


    public function with_transaction($fn) {
        /*
         * Safely execute the $fn inside a transaction.
         */
        $this->database->beginTransaction();
        $this->in_transaction = true;

        try {
            $result = $fn();}
        catch (\Exception $exception) {
            $this->database->rollBack();
            throw $exception;}
        finally {
            $this->in_transaction = false;}

        $this->database->commit();
        return $result;}


    public function with_savepoint($fn, $savepoint_name = null) {
        /*
         * Effectively emulate a sub-transaction running $fn with a
         * savepoint. If specified, name it $savepoint.
         */
        $savepoint_name = $savepoint_name ?? "tic_savepoint_{$this->savepoint_count}";

        if (!$this->in_transaction)
            throw new exception\TransactionError(
                "Tried to invoke savepoint {$savepoint}"
                . " while not in a transaction");

        $this->database->exec("savepoint {$savepoint_name};");
        ++ $this->savepoint_count;

        try {
            $result = $fn();}
        catch (\Exception $exception) {
            $this->database->exec("rollback to savepoint {$savepoint_name};");
            throw $exception;}
        finally {
            -- $this->savepoint_count;}

        $this->database->exec("release savepoint {$savepoint_name};");
        return $result;}


    public function deployed_changes() {
        /*
         * Fetch and return an array of currently deployed changes
         */

        return array_map(
            function (array $change) {
                $change['dependencies'] = static::translate_array_from_pg($change['dependencies']);
                if (empty($change['deploy'])) unset($change['deploy']);
                if (empty($change['verify'])) unset($change['verify']);
                if (empty($change['revert'])) unset($change['revert']);
                return new Change($change); },
            $this->database->query("
                select change as change_name
                     , dependencies as dependencies
                     , deploy as deploy_script
                     , verify as verify_script
                     , revert as revert_script
                from \"{$this->schema}\".deployed;")
            ->fetchAll());}


    static private function translate_array_from_pg(string $array_rep) {
        /*
         * Given a string (?!) result from a PostgreSQL array,
         * translate it to a PHP array. Because PDO sucks. Need to
         * consider another DB layer.
         *
         * Note: this is *not* a complete or robust method, but should
         * cover the anticipated cases for this program.
         */
        return $array_rep === '{}'
            ? []
            : array_map(
                function($element) { return $element === '""' ? '' : $element; },
                explode(
                    ',',
                    trim($array_rep, '{}')));}


    public function rename_change($old, $new) {
        /*
         * Update deployed changes renaming $old to $new in change
         * names and dependencies
         */
        $this->with_protection(
            function () use ($old, $new) {
                $this->database->exec(
                    "update \"{$this->schema}\".deployed
                     set change = {$this->database->quote($new)}
                     where change = {$this->database->quote($old)};");
                $this->database->exec(
                    "update \"{$this->schema}\".deployed
                     set dependencies = array_replace(
                         dependencies,
                         {$this->database->quote($old)},
                         {$this->database->quote($new)});");});}


    public function deploy_change(Change $change) {
        /*
         * Deploy a change, making sure it is complete, and mark it deployed
         */
        $this->with_protection(
            function() use ($change) {

                $this->exec_to_fail(
                    $change->verify_script(), new exception\ChangeDeploymentError(
                        "Change {$change->name()} verifies before deploy"));

                $this->exec(
                    $change->deploy_script(), new exception\ChangeDeploymentError(
                        "Change {$change->name()} failed to deploy"));

                $this->exec_to_rollback(
                    $change->verify_script(), new exception\ChangeDeploymentError(
                        "Change {$change->name()} failed to verify"));

                $this->exec(
                    $change->revert_script(), new exception\ChangeDeploymentError(
                        "Change {$change->name()} failed to revert"));

                $this->exec_to_fail(
                    $change->verify_script(), new exception\ChangeDeploymentError(
                        "Change {$change->name()} verifies after revert"));

                $this->exec(
                    $change->deploy_script(), new exception\ChangeDeploymentError(
                        "Change {$change->name()} failed to re-deploy"));

                $this->exec_to_rollback(
                    $change->verify_script(), new exception\ChangeDeploymentError(
                        "Change {$change->name()} failed to re-verify"));});}


    public function revert_change(Change $change) {
        /*
         * Revert a single Change $change
         */
        $this->database->exec($change->revert_script());}


    public function verify_change(Change $change) {
        /*
         * Verify a change, to confirm it was already deployed. Note
         * that this may be used when dependent Changes are also
         * already deployed, so it cannot test reverting and
         * redeploying.
         */
        $this->with_protection(
            function() use ($change) {
                $this->exec_to_rollback(
                    $change->verify_script(), new exception\ChangeDeploymentError(
                        "Change {$change->name()} failed to verify"));});}


    public function unmark_deployed(Change $change) {
        /*
         * Remove the deployment info for the given chnage
         */
        try {
            $this->database->exec("
                delete from \"{$this->schema}\".deployed
                where change = {$this->database->quote($change->name())};");}
        catch (\PDOException $error) {
            throw new exception\FailureToMarkChange(
                "Could not clear change record {$change->name()}",
                $error->getCode(), $error);}}


    public function mark_deployed(Change $change) {
        /*
         * Mark the given change deployed in the database
         */
        $dependencies = 'array['
            . implode(
                ',',
                array_map(
                    [$this->database, 'quote'],
                    $change->dependencies()))
            . ']::text[]';

        try {
            $this->database->exec("
                insert into \"{$this->schema}\".deployed
                select {$this->quote($change->name())}
                     , current_timestamp
                     , {$dependencies}
                     , {$this->quote($change->deploy_script())}
                     , {$this->quote($change->verify_script())}
                     , {$this->quote($change->revert_script())}
                where {$this->quote($change->name())} not in (
                    select change from \"{$this->schema}\".deployed);");}
        catch (\PDOException $error) {
            throw new exception\FailureToMarkChange(
                "Could not track change {$change->name()}",
                $error->getCode(), $error);}}


    private function quote(string $value=null) {
        /*
         * Quote respecing NULLs because PDO sucks.
         */
        return is_null($value) ? 'NULL' : $this->database->quote($value);
    }



    public function exec($statement, $exception = null) {
        /*
         * Execute $statement with protection and optionally throw
         * $exception if it fails. If it fails and $exception is null
         * just rethrow the original.
         */
        try {
            $this->with_protection(function () use ($statement) {
                return $this->database->exec($statement);});}
        catch (\PDOException $error) {
            if (isset($exception)) {
                $exception->add_reason($error->getMessage());
                throw $exception; }
            else
                throw $error;}}


    public function exec_to_fail($statement, $exception = null) {
        /*
         * Execute $statement with protection and optionally throw
         * $exception if *doesn't* fail. If it doesn't fail and
         * $exception is null throw a generic exception.
         */
        try {
            $this->with_protection(function () use ($statement) {
                return $this->database->exec($statement);});
            if (isset($exception))
                throw $exception;
            else
                throw new Exception(
                    'A statement intended to fail succeeded');}
        catch (\PDOException $ignore) {}}


    public function exec_to_rollback($statement, $exception = null) {
        /*
         * Execute $statement with protection and optionally throw
         * $exception if it fails. If it fails and $exception is null,
         * just rethrow the original. However, if it succeeds,
         * rollback the protection wrapper so it has no effect.
         */
        $result = null;

        try {
            $this->with_protection(function () use ($statement, &$result) {
                $result = $this->database->exec($statement);
                throw new exception\CancelTransaction();});}
        catch (\PDOException $error) {
            if (isset($exception)) {
                $exception->add_reason($error->getMessage());
                throw $exception; }
            else
                throw $error;}
        catch (exception\CancelTransaction $ignore) {}

        return $result;}


    private function ensure_tic() {
        /*
         * Makes sure there is a proper ticc schema and initializes it
         * if not
         */
        $this->with_transaction(function () {
            if ($this->database->query("
                select '{$this->schema}' in (
                    select schema_name
                    from information_schema.schemata);")->fetchColumn())
                return true;

            $this->database->exec("create schema \"{$this->schema}\";");

            $this->database->exec("
                create table \"{$this->schema}\".deployed (
                    change text primary key
                  , deployed_at timestamptz not null
                  , dependencies text[] not null
                  , deploy text
                  , verify text
                  , revert text);");});}


    private $database;
    private $schema;
    private $savepoint_count;
    private $in_transaction;
}
