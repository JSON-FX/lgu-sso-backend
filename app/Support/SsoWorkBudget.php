<?php

namespace App\Support;

use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SsoWorkBudget
{
    public function store(): Repository
    {
        $name = config('sso.capacity_store', 'database');
        if (! is_string($name) || config('cache.stores.'.$name.'.driver') !== 'database') {
            throw new SsoCapacityException;
        }
        foreach (['connection', 'lock_connection'] as $setting) {
            $connection = config('cache.stores.'.$name.'.'.$setting);
            if ($connection !== null && $connection !== config('database.default')) {
                throw new SsoCapacityException;
            }
        }

        return Cache::store($name);
    }

    public function charge(string $scope, int $amount, int $limit, int $seconds = 60): void
    {
        if ($limit < 1 || $amount < 1 || $amount > 10 || $seconds < 1) {
            throw new SsoCapacityException;
        }

        // A reservation spanning a boundary is retained, then charged once in the fresh window.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $window = intdiv(now()->timestamp, $seconds);
            $end = ($window + 1) * $seconds;
            $key = 'sso-work:v1:'.$scope.':'.$window;

            try {
                $store = $this->store();
                // Keep counter generations beyond their window. Rejected work stays charged.
                $store->add($key, 0, $end - now()->timestamp + 120);
                $used = $store->increment($key, $amount);
            } catch (Throwable) {
                throw new SsoCapacityException;
            }

            if (! is_int($used) || $used < 1) {
                throw new SsoCapacityException;
            }
            if (intdiv(now()->timestamp, $seconds) !== $window) {
                continue;
            }
            if ($used > $limit) {
                throw new SsoCapacityException(429, max(1, $end - now()->timestamp));
            }

            return;
        }

        // Repeated clock movement cannot establish current admission within bounded work.
        throw new SsoCapacityException;
    }
}
