<?php

namespace App\Exceptions;

use Exception;

class NotFoundException extends Exception
{
    protected int $status;
    protected ?array $messageData;

    public function __construct(
        string $message = 'Resource not found',
        ?array $messageData = null,
        int $status = 404
    ) {
        parent::__construct($message);
        $this->status = $status;
        $this->messageData = $messageData;
    }

    public function getStatus(): int
    {
        return $this->status;
    }


    public function getMessageData(): ?array
    {
        return $this->messageData;
    }
}
