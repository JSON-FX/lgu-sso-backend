<?php

namespace App\Support;

use App\Models\OAuthToken;

class ActiveSsoToken
{
    public static function find(string $token, int $employeeId): ?OAuthToken
    {
        return OAuthToken::query()
            ->where('access_token', hash('sha256', $token))
            ->where('employee_id', $employeeId)
            ->whereNull('revoked_at')
            ->first();
    }

    public static function exists(string $token, int $employeeId): bool
    {
        return self::find($token, $employeeId) !== null;
    }
}
