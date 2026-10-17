<?php

namespace App\Http\Middleware;

use App\Support\SsoDatabaseBounds;
use Closure;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response;

class GuardSsoBatchRequest
{
    public const PATH = 'api/v1/sso/authorize-batch';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is(self::PATH)) {
            return $next($request);
        }
        if (config('sso.batch.enabled') !== true) {
            return $this->reject(404);
        }
        // This FastCGI parameter is set only by the exact Nginx route to the one-child batch pool.
        if ($request->server('SSO_BATCH_POOL') !== '1') {
            return $this->reject(503)->header('Retry-After', '1');
        }
        if ((int) $request->server('CONTENT_LENGTH', 0) > 196608) {
            return $this->reject(413);
        }
        $raw = $request->getContent();
        if (strlen($raw) > 196608) {
            return $this->reject(413);
        }
        if (! $request->isJson()) {
            return $this->reject(400);
        }
        try {
            $decoded = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->reject(400);
        }
        if (! $decoded instanceof \stdClass || ! isset($decoded->items) || ! is_array($decoded->items)
            || count($decoded->items) < 1 || count($decoded->items) > 10) {
            return $this->reject(422);
        }
        $data = get_object_vars($decoded);
        $data['items'] = [];
        foreach ($decoded->items as $item) {
            if (! $item instanceof \stdClass) {
                return $this->reject(422);
            }
            $data['items'][] = get_object_vars($item);
        }
        $request->setJson(new InputBag($data));
        if (! $request->headers->has('X-Client-ID') || ! $request->headers->has('X-Client-Secret')) {
            return $this->reject(401);
        }

        return app(SsoDatabaseBounds::class)->run(function () use ($request, $next) {
            $response = $next($request);
            $response->headers->set('Cache-Control', 'private, no-store');

            return $response;
        });
    }

    private function reject(int $status): \Illuminate\Http\JsonResponse
    {
        return response()->json(['message' => 'Authorization request unavailable or invalid.'], $status)
            ->header('Cache-Control', 'private, no-store');
    }
}
