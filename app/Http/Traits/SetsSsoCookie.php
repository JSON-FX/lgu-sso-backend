<?php

namespace App\Http\Traits;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Cookie;

trait SetsSsoCookie
{
    protected function attachSsoCookie(JsonResponse $response, string $token): JsonResponse
    {
        $cookie = new Cookie(
            name: config('sso.cookie_name'),
            value: $token,
            expire: now()->addMinutes((int) config('sso.cookie_lifetime'))->getTimestamp(),
            path: '/',
            domain: null,
            secure: config('sso.cookie_secure'),
            httpOnly: true,
            sameSite: config('sso.cookie_same_site'),
        );

        $response->headers->setCookie($cookie);

        return $response;
    }

    protected function clearSsoCookie(JsonResponse $response): JsonResponse
    {
        $cookie = new Cookie(
            name: config('sso.cookie_name'),
            value: '',
            expire: now()->subMinute()->getTimestamp(),
            path: '/',
            domain: null,
            secure: config('sso.cookie_secure'),
            httpOnly: true,
            sameSite: config('sso.cookie_same_site'),
        );

        $response->headers->setCookie($cookie);

        return $response;
    }
}
