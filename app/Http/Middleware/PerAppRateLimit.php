<?php

namespace App\Http\Middleware;

use App\Models\Application;
use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PerAppRateLimit
{
    public function __construct(protected RateLimiter $limiter, CacheManager $cache)
    {
        $store = config('sso.app_rate_limit_store');
        if (is_string($store) && $store !== '') {
            $this->limiter = new RateLimiter($cache->store($store));
        }
    }

    public function handle(Request $request, Closure $next): Response
    {
        $application = $request->attributes->get('application');

        if (! $application instanceof Application) {
            return $next($request);
        }

        $key = 'app_rate_limit:'.$application->id;
        $maxAttempts = $application->rate_limit_per_minute;

        if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = $this->limiter->availableIn($key);

            return response()->json([
                'message' => 'Too many requests.',
                'retry_after' => $retryAfter,
            ], Response::HTTP_TOO_MANY_REQUESTS)
                ->header('Retry-After', $retryAfter)
                ->header('X-RateLimit-Limit', $maxAttempts)
                ->header('X-RateLimit-Remaining', 0);
        }

        $this->limiter->hit($key, 60);

        $response = $next($request);

        return $response
            ->header('X-RateLimit-Limit', $maxAttempts)
            ->header('X-RateLimit-Remaining', $this->limiter->remaining($key, $maxAttempts));
    }
}
