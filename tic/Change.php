<?php

namespace tic;

use Functional as F;

class Change {
    public function __construct($change_file) {
        /*
         * Parse and load the $change_file
         */
        $matches = [];
        if (!preg_match('/(?:^depends: (\w+)$\n)?\n(.+)/sm',
                        file_get_contents($change_file)))
            throw new BadChangeException();

        $this->dependencies = explode(' ', $matches[1]);
        $this->body = $matches(2);
        $this->name = basename($change_file, '.change');}


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
    private $body;
}
