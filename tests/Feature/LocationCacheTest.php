<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

it('serves cached locations without calling the provider again while fresh', function () {
    Http::fake([
        '*psgc.cloud/api/regions' => Http::response([['code' => '010000000', 'name' => 'Ilocos Region']]),
    ]);

    $this->getJson('/api/v1/locations/regions')
        ->assertOk()
        ->assertJsonPath('data.0.code', '010000000');

    $this->getJson('/api/v1/locations/regions')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Ilocos Region');

    Http::assertSentCount(1);
});

it('serves the last known location data when a refresh fails', function () {
    Http::fake([
        '*psgc.cloud/api/regions' => Http::sequence()
            ->push([['code' => '010000000', 'name' => 'Ilocos Region']])
            ->push(['message' => 'Unavailable'], 503),
    ]);

    $this->getJson('/api/v1/locations/regions')->assertOk();
    $this->travel(25)->hours();

    $this->getJson('/api/v1/locations/regions')
        ->assertOk()
        ->assertJsonPath('data.0.code', '010000000');

    Http::assertSentCount(2);
});

it('returns 503 when the provider fails before locations are cached', function () {
    Http::fake([
        '*psgc.cloud/api/regions' => Http::response(['message' => 'Unavailable'], 503),
    ]);

    $this->getJson('/api/v1/locations/regions')
        ->assertStatus(503)
        ->assertJsonPath('message', 'Location service is unavailable.');
});

it('serves cached locations when the provider connection fails', function () {
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        if (++$attempts === 1) {
            return Http::response([['code' => '010000000', 'name' => 'Ilocos Region']]);
        }

        throw new ConnectionException('Provider connection failed');
    });

    $this->getJson('/api/v1/locations/regions')->assertOk();
    $this->travel(25)->hours();

    $this->getJson('/api/v1/locations/regions')
        ->assertOk()
        ->assertJsonPath('data.0.code', '010000000');
});

it('maps each public location route to the provider hierarchy', function (string $route, string $upstream) {
    Http::fake([
        "*psgc.cloud/api{$upstream}" => Http::response([['code' => 'sample', 'name' => 'Sample']]),
    ]);

    $this->getJson("/api/v1/locations{$route}")
        ->assertOk()
        ->assertJsonPath('data.0.code', 'sample');

    Http::assertSent(fn ($request) => $request->url() === "https://psgc.cloud/api{$upstream}");
})->with([
    ['/regions/010000000/provinces', '/regions/010000000/provinces'],
    ['/provinces', '/provinces'],
    ['/provinces/012800000/cities', '/provinces/012800000/cities-municipalities'],
    ['/cities/012801000/barangays', '/cities-municipalities/012801000/barangays'],
]);
