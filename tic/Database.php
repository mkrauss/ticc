<?php

namespace tic;

use Functional as F;

class Database {
    public function __construct($config) {
        /*
         * Connect to the database and ensure it is set up for Tic
         */

        $this->in_transaction = false;
        $this->savepoint_count = 0;

        $this->schema = $config['tic schema'] ?? 'tic';

        $this->database = new \PDO (
            (isset($config['engine']) ? "{$config['engine']}:" : 'pgsql:')
            . (isset($config['host']) ? "host={$config['host']};" : '')
            . (isset($config['port']) ? "port={$config['port']};" : '')
            . (isset($config['name']) ? "dbname={$config['name']}" : ''));

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
                select change, dependencies, deploy, verify, revert
                from \"{$this->schema}\".deployed;")
            ->fetchAll(\PDO::FETCH_COLUMN, 0));}

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



    public function deploy_change(string $change_name,
                                  array $dependencies,
                                  string $deploy=null,
                                  string $verify=null,
                                  string $revert=null) {
        /*
         * Deploy a change, making sure it is complete, and mark it deployed
         */
        $this->with_protection(
            function() use ($change_name, $dependencies, $deploy, $verify, $revert) {

                $this->exec_to_fail(
                    $verify, new exception\ChangeDeploymentError(
                        "Change {$change_name} verifies before deploy"));

                $this->exec(
                    $deploy, new exception\ChangeDeploymentError(
                        "Change {$change_name} failed to deploy"));

                $this->exec_to_rollback(
                    $verify, new exception\ChangeDeploymentError(
                        "Change {$change_name} failed to verify"));

                $this->exec(
                    $revert, new exception\ChangeDeploymentError(
                        "Change {$change_name} failed to revert"));

                $this->exec_to_fail(
                    $verify, new exception\ChangeDeploymentError(
                        "Change {$change_name} verifies after revert"));

                $this->exec(
                    $deploy, new exception\ChangeDeploymentError(
                        "Change {$change_name} failed to re-deploy"));

                $this->exec_to_rollback(
                    $verify, new exception\ChangeDeploymentError(
                        "Change {$change_name} failed to re-verify"));

                $this->mark_deployed($change_name, $dependencies,
                                     $deploy, $verify, $revert);});}


    public function revert_through($change_name) {
        /*
         * Revert all deployed changes back to and including the one
         * named $change_name
         */
        $this->with_protection(
            function() use ($change_name) {
                $changes_to_revert = $this->database->query("
                    select change, revert
                    from \"{$this->schema}\".deployed
                    natural join (
                        select change
                             , after
                             , count(after) over (partition by change) as num
                        from \"{$this->schema}\".deployed_after) change_w_num
                    where after = {$this->database->quote($change_name)}
                    order by num desc;")
                    ->fetchAll();

                foreach($changes_to_revert as $to_revert)
                    $this->revert_change($to_revert['change'],
                                         $to_revert['revert']);});}


    private function revert_change($change_name, $revert) {
        /*
         * Revert a single change $change_name with script $revert
         */
        $this->database->exec($revert);
        $this->unmark_deployed($change_name);
    }


    private function unmark_deployed($change_name) {
        /*
         * Remove the deployment info for the given chnage 
         */
        try {
            $this->database->exec("
                delete from \"{$this->schema}\".deployed
                where change = {$this->database->quote($change_name)};");}
        catch (\PDOException $error) {
            throw new exception\FailureToMarkChange(
                "Could not clear change record {$change_name}",
                $error->getCode(), $error);}}


    public function mark_deployed(string $change_name,
                                  array $dependencies,
                                  string $deploy=null,
                                  string $verify=null,
                                  string $revert=null) {
        /*
         * Mark the given change deployed in the database
         */
        $dependencies = 'array['
            . implode(
                ',',
                array_map(
                    // function ($dependency) {
                    //     return $this->database->quote($dependency); }
                    [$this->database, 'quote'],
                    $dependencies))
            . ']::text[]';

        try {
            $this->database->exec("
                insert into \"{$this->schema}\".deployed values (
                       {$this->database->quote($change_name)}
                     , current_timestamp
                     , {$dependencies}
                     , {$this->database->quote($deploy)}
                     , {$this->database->quote($verify)}
                     , {$this->database->quote($revert)});");}
        catch (\PDOException $error) {
            throw new exception\FailureToMarkChange(
                "Could not track change {$change_name}",
                $error->getCode(), $error);}}


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
            if (isset($exception)) {
                $exception->add_reason($error->getMessage());
                throw $exception; }
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
         * Makes sure there is a proper tic schema and initializes it
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
                    change text
                  , deployed_at timestamptz
                  , dependencies text[]
                  , deploy text
                  , verify text
                  , revert text);");});}

    
    private $database;
    private $schema;
    private $savepoint_count;
    private $in_transaction;
}
