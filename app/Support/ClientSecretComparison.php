<?php

namespace App\Support;

use App\Models\Application;
use Throwable;

class ClientSecretComparison
{
    public function __construct(private SsoWorkBudget $budget) {}

    public function enabled(Application $application): bool
    {
        return config('sso.client_comparison.enabled') === true
            && $application->uuid === config('sso.client_comparison.application_uuid');
    }

    public function check(Application $application, string $secret): bool
    {
        if (! $this->enabled($application)) {
            return $application->validateSecret($secret);
        }
        if (strlen($secret) > 72 || preg_match('/[\x00-\x1f\x7f]/', $secret)) {
            return false;
        }

        try {
            return app(SsoDatabaseBounds::class)->run(fn () => $this->cached($application, $secret));
        } catch (SsoCapacityException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new SsoCapacityException;
        }
    }

    private function cached(Application $application, string $secret): bool
    {
        try {
            $key = config('app.key');
            if (is_string($key) && str_starts_with($key, 'base64:')) {
                $key = base64_decode(substr($key, 7), true);
            }
            if (! is_string($key) || strlen($key) < 32) {
                throw new SsoCapacityException;
            }
            $material = 'sso-client-comparison:v1';
            foreach ([$application->uuid, $application->getRawOriginal('client_secret'), $secret] as $part) {
                $material .= strlen($part).':'.$part;
            }
            $cacheKey = 'sso-client:v1:'.hash_hmac('sha256', $material, $key);
            $store = $this->budget->store();
            $ttl = (int) config('sso.client_comparison.ttl', 60);
            if ($ttl < 1 || $ttl > 60) {
                throw new SsoCapacityException;
            }
            $lock = $store->lock($cacheKey.':comparison', 15);
            $deadline = hrtime(true) + 1_000_000_000;
            do {
                $cached = $store->get($cacheKey);
                if (hrtime(true) >= $deadline) {
                    throw new SsoCapacityException;
                }
                if ($cached === true) {
                    return true;
                }
                if ($lock->get()) {
                    try {
                        if (hrtime(true) >= $deadline) {
                            throw new SsoCapacityException;
                        }
                        if ($store->get($cacheKey) === true) {
                            return true;
                        }
                        $algorithm = password_get_info($application->getRawOriginal('client_secret'));
                        if ($algorithm['algoName'] !== 'bcrypt' || ($algorithm['options']['cost'] ?? 99) > 12) {
                            throw new SsoCapacityException;
                        }
                        // The ownership lock joins normal cold misses; every actual comparison is still charged.
                        $this->budget->charge('client-global', 1, (int) config('sso.client_comparison.global_cold_per_minute'));
                        $this->budget->charge('client-'.$application->id, 1, (int) config('sso.client_comparison.cold_per_minute'));
                        if (! $application->validateSecret($secret)) {
                            return false;
                        }
                        if (! $store->put($cacheKey, true, $ttl)) {
                            throw new SsoCapacityException;
                        }

                        return true;
                    } finally {
                        $lock->release();
                    }
                }
                usleep(25_000);
            } while (hrtime(true) < $deadline);

            throw new SsoCapacityException;
        } catch (SsoCapacityException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Never chain a credential/cache exception with arguments or database bindings.
            throw new SsoCapacityException;
        }
    }
}
