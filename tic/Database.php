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

        $this->database->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

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
        return $this->database->query("
            select change from \"{$this->schema}\".deployed;")
            ->fetchAll(\PDO::FETCH_COLUMN, 0);}


    public function deploy_change($change_name, $plan, $deploy, $verify, $revert) {
        /*
         * Deploy a change, making sure it is complete, and mark it deployed
         */
        $this->with_protection(
            function() use ($change_name, $plan, $deploy, $verify, $revert) {

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

                $this->mark_deployed($change_name, json_encode($plan),
                                     $deploy, $verify, $revert);});}


    private function mark_deployed($change_name, $plan,
                                   $deploy, $verify, $revert) {
        /*
         * Mark the given change deployed in the database
         */
        try {
            $this->database->exec("
                insert into \"{$this->schema}\".deployed values (
                       {$this->database->quote($change_name)}
                     , current_timestamp
                     , {$this->database->quote($plan)}
                     , {$this->database->quote($deploy)}
                     , {$this->database->quote($verify)}
                     , {$this->database->quote($revert)});");}
        catch (\PDOException $error) {
            throw new exception\FailureToMarkChange(
                "Could not track change {$change_name}");}}


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
            throw $exception ?? $error;}}


    public function exec_to_fail($statement, $exception = null) {
        /*
         * Execute $statement with protection and optionally throw
         * $exception if *doesn't* fail. If it doesn't fail and
         * $exception is null throw a generic exception.
         */
        try {
            $this->with_protection(function () use ($statement) {
                return $this->database->exec($statement);});
            throw $exception ?? new Exception(
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
            throw $exception ?? $error;}
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
                  , plan jsonb
                  , deploy text
                  , verify text
                  , revert text);");});}

    
    private $database;
    private $schema;
    private $savepoint_count;
    private $in_transaction;
}
