<?php

namespace App\Exceptions;

use Exception;

class NotFoundException extends Exception
{
    protected int $status;

    public function __construct(
        string $message = 'Resource not found',
        int $status = 404
    ) {
        parent::__construct($message);
        $this->status = $status;
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}
