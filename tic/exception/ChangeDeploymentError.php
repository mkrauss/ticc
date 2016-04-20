<?php

namespace tic\exception;

class ChangeDeploymentError extends \Exception{
    public function add_reason($reason) {
        /*
         * Attach a reason description
         */
        $this->reason = $reason;}

    public function __toString() {
        return __CLASS__ . ": {$this->message} ({$this->reason})\n";}

    private $reason;
};
