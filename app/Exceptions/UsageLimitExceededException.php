<?php

namespace App\Exceptions;

use RuntimeException;

class UsageLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $feature,
        public readonly int $limit,
        public readonly int $used,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : 'Daily usage limit exceeded.');
    }
}
