<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class LocationController extends Controller
{
    private const REFRESH_AFTER_SECONDS = 86400;

    public function regions(): JsonResponse
    {
        return $this->fetchLocations('/regions');
    }

    public function provincesByRegion(string $regionCode): JsonResponse
    {
        return $this->fetchLocations('/regions/'.rawurlencode($regionCode).'/provinces');
    }

    public function provinces(): JsonResponse
    {
        return $this->fetchLocations('/provinces');
    }

    public function cities(string $provinceCode): JsonResponse
    {
        return $this->fetchLocations('/provinces/'.rawurlencode($provinceCode).'/cities-municipalities');
    }

    public function barangays(string $cityCode): JsonResponse
    {
        return $this->fetchLocations('/cities-municipalities/'.rawurlencode($cityCode).'/barangays');
    }

    private function fetchLocations(string $path): JsonResponse
    {
        $cacheKey = 'psgc:locations:'.hash('sha256', $path);
        $cached = Cache::get($cacheKey);

        if (is_array($cached) && isset($cached['fetched_at'], $cached['data'])
            && is_array($cached['data'])
            && now()->timestamp - $cached['fetched_at'] < self::REFRESH_AFTER_SECONDS) {
            return response()->json(['data' => $cached['data']]);
        }

        try {
            $response = Http::acceptJson()
                ->timeout(10)
                ->get(rtrim(config('services.psgc.url'), '/').$path);
        } catch (ConnectionException $e) {
            return $this->cachedOrUnavailable($cached);
        }

        $locations = $response->json();

        if (! $response->successful() || ! is_array($locations) || ! array_is_list($locations)) {
            return $this->cachedOrUnavailable($cached);
        }

        Cache::forever($cacheKey, [
            'data' => $locations,
            'fetched_at' => now()->timestamp,
        ]);

        return response()->json(['data' => $locations]);
    }

    private function cachedOrUnavailable(mixed $cached): JsonResponse
    {
        if (is_array($cached) && isset($cached['data']) && is_array($cached['data'])) {
            return response()->json(['data' => $cached['data']]);
        }

        return response()->json(['message' => 'Location service is unavailable.'], 503);
    }
}
