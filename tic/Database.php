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
        catch (\PDOException $exception) {
            $this->in_transaction = false;
            $this->database->rollBack();
            throw $exception;}

        $this->in_transaction = false;
        $this->database->commit();
        return $result;}


    public function with_savepoint($fn, $savepoint = null) {
        /*
         * Effectively emulate a sub-transaction running $fn with a
         * savepoint. If specified, name it $savepoint.
         */
        $savepoint = $savepoint ?? "tic_savepoint_{$this->savepoint_count}";

        if (!$this->in_transaction)
            throw new exception\TransactionError(
                "Tried to invoke savepoint {$savepoint}"
                . " while not in a transaction");

        $this->exec("savepoint {$savepoint_name};");
        ++ $savepoint_count;

        try {
            $result = $fn();}
        catch (\PDOException $exception) {
            -- $savepoint_count;
            $this->exec("rollback to savepoint {$savepoint_name};");
            throw $exception;}

        -- $savepoint_count;
        $this->exec("release savepoint {$savepoint_name};");
        return $result;}


    private function deploy_change($change_name, $plan, $deploy, $verify, $revert) {
        /*
         * Deploy a change, making sure it is complete, and mark it deployed
         */
        $this->with_protection(function() {
            try {
                $this->exec($deploy);}
            catch (\PDOException $error) {
                throw new exception\ChangeDeploymentError(
                    "Change {$change_name} failed to deploy");}

            try {
                $this->exec($verify);}
            catch (\PDOException $error) {
                throw new exception\ChangeDeploymentError(
                    "Change {$change_name} failed to verify");}

            try {
                $this->exec($revert);}
            catch (\PDOException $error) {
                throw new exception\ChangeDeploymentError(
                    "Change {$change_name} failed to revert");}

            try {
                $this->exec($verify);
                throw new exception\ChangeDeploymentError(
                    "Change {$change_name} verifies after revert");}
            catch (\PDOException $ignore) {}

            try {
                $this->exec($deploy);}
            catch (\PDOException $error) {
                throw new exception\ChangeDeploymentError(
                    "Change {$change_name} failed to re-deploy");}

            try {
                $this->exec($verify);}
            catch (\PDOException $error) {
                throw new exception\ChangeDeploymentError(
                    "Change {$change_name} failed to re-verify");}});}


    public function exec($query) {
        /*
         * Just a pass-thru to PDO::exec
         */
        return $this->database->exec($query);
    }


    private function ensure_tic() {
        /*
         * Makes sure there is a proper tic schema and initializes it
         * if not
         */
        $this->with_transaction(function () {
            if ($this->database->query("
                select '{$this->schema}' in (
                    select schema_name from information_schema.schemata);")
                ->fetchColumn())
                return true;

            $this->exec("create schema \"{$this->schema}\";");
            $this->exec("
                create table \"{$this->schema}\".deployed (
                    change text
                  , deployed_at timestamptz
                  , plan jsonb
                  , deploy text
                  , verify text
                  , revert text);");});}

    
    private $database;
    private $savepoint_count;
    private $in_transaction;
}
