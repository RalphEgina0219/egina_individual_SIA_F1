<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a request is valid but violates a business rule (e.g. not
 * enough points, account not eligible). Rendered as a JSON error response.
 */
class BusinessRuleException extends RuntimeException
{
    public function __construct(string $message, protected int $status = 422, protected array $data = [])
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function data(): array
    {
        return $this->data;
    }
}
