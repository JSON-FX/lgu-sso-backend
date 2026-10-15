<?php

use App\Models\Application;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->application = Application::factory()->create([
        'client_secret' => Hash::make('limit-client-secret'),
        'rate_limit_per_minute' => 2,
    ]);
    $this->headers = [
        'X-Client-ID' => $this->application->client_id,
        'X-Client-Secret' => 'limit-client-secret',
    ];
    config(['cache.stores.application_counters' => ['driver' => 'array', 'serialize' => false]]);
});

it('preserves the existing application budget by default and shares it across endpoints', function () {
    $key = 'app_rate_limit:'.$this->application->id;
    app(RateLimiter::class)->hit($key, 60);
    $this->getJson('/api/v1/sso/offices', $this->headers)->assertOk()->assertHeader('X-RateLimit-Remaining', '0');
    $limited = $this->getJson('/api/v1/sso/positions', $this->headers)->assertStatus(429);
    expect((int) $limited->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
    expect($limited->json('retry_after'))->toBe((int) $limited->headers->get('Retry-After'));
    $this->travel(61)->seconds();
    $this->getJson('/api/v1/sso/positions', $this->headers)->assertOk();
});

it('isolates optional application counters from login and registration limits', function () {
    config(['sso.app_rate_limit_store' => 'application_counters']);
    $key = 'app_rate_limit:'.$this->application->id;
    $global = app(RateLimiter::class);
    $global->hit($key, 60);
    $global->hit('sso_login:account:'.hash('sha256', 'test.account'), 900);

    $this->getJson('/api/v1/sso/offices', $this->headers)->assertOk();
    $this->getJson('/api/v1/sso/positions', $this->headers)->assertOk();
    $this->getJson('/api/v1/sso/offices', $this->headers)->assertStatus(429);

    expect($global->attempts($key))->toBe(1)
        ->and($global->attempts('sso_login:account:'.hash('sha256', 'test.account')))->toBe(1);
    $dedicated = new RateLimiter(Cache::store('application_counters'));
    expect($dedicated->attempts($key))->toBe(2);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/login', ['username' => 'blocked.account', 'password' => 'wrong'])->assertUnauthorized();
    }
    $this->postJson('/api/v1/auth/login', ['username' => 'blocked.account', 'password' => 'wrong'])->assertStatus(429);
    expect($global->attempts('sso_login:account:'.hash('sha256', 'blocked.account')))->toBe(5)
        ->and($dedicated->attempts('sso_login:account:'.hash('sha256', 'blocked.account')))->toBe(0);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/register', [])->assertUnprocessable();
    }
    $this->postJson('/api/v1/auth/register', [])->assertStatus(429);
});

it('does not share an application budget with another client', function () {
    config(['sso.app_rate_limit_store' => 'application_counters']);
    for ($i = 0; $i < 2; $i++) {
        $this->getJson('/api/v1/sso/offices', $this->headers)->assertOk();
    }
    $other = Application::factory()->create(['client_secret' => Hash::make('other-secret')]);
    $this->getJson('/api/v1/sso/offices', [
        'X-Client-ID' => $other->client_id,
        'X-Client-Secret' => 'other-secret',
    ])->assertOk();
    $this->getJson('/api/v1/sso/offices', $this->headers)->assertStatus(429);
});

it('fails closed instead of falling back when the selected store is unavailable', function () {
    config(['sso.app_rate_limit_store' => 'application_counters']);
    Cache::store('application_counters')->put('app_rate_limit:'.$this->application->id, 2, 60);
    $store = Mockery::mock(Illuminate\Contracts\Cache\Store::class);
    $store->shouldReceive('get')->andThrow(new RuntimeException('Counter store unavailable'));
    Cache::extend('unavailable', fn () => Cache::repository($store));
    config(['cache.stores.broken_counters' => ['driver' => 'unavailable'], 'sso.app_rate_limit_store' => 'broken_counters']);
    $this->withoutExceptionHandling();
    expect(fn () => $this->getJson('/api/v1/sso/offices', $this->headers))->toThrow(RuntimeException::class, 'Counter store unavailable');
});

it('does not spend registration submission attempts on loading choices', function () {
    for ($i = 0; $i < 60; $i++) {
        $this->getJson('/api/v1/auth/registration-options')->assertOk();
    }
    $this->getJson('/api/v1/auth/registration-options')->assertStatus(429);
    $this->postJson('/api/v1/auth/register', [])->assertUnprocessable();
    $this->travel(61)->seconds();
    $this->getJson('/api/v1/auth/registration-options')->assertOk();
});

it('keeps submission limits per IP without blocking choices or another client', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/register', [])->assertUnprocessable();
    }
    $this->postJson('/api/v1/auth/register', [])->assertStatus(429);
    $this->getJson('/api/v1/auth/registration-options')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.25'])
        ->postJson('/api/v1/auth/register', [])->assertUnprocessable();
    $this->travel(61)->seconds();
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->postJson('/api/v1/auth/register', [])->assertUnprocessable();
});
