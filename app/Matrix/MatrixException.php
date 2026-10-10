<?php

namespace App\Matrix;

class MatrixException extends \RuntimeException
{
    public $retry_after;
    public $user_message;

    public function __construct($message, $code = 0, $retry_after = 0, $user_message = null)
    {
        parent::__construct($message, $code);
        $this->retry_after = $retry_after;
        $this->user_message = $user_message;
    }
}
