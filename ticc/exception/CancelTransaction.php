<?php

namespace ticc\exception;

/*
 *This is an atypical exception intended to be thrown and caught
 *within Database::exec_to_rollback (or elsewhere if needed) to
 *indicate that everything went fine but to break the transaction and
 *cause a rollback.
 */

class CancelTransaction extends \Exception{};
