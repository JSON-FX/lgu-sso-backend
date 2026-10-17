<?php

namespace App\Support;

use RuntimeException;

class SsoCapacityException extends RuntimeException
{
    public function __construct(public readonly int $status = 503, public readonly int $retryAfter = 1)
    {
        parent::__construct('SSO verification is temporarily unavailable.');
    }
}
