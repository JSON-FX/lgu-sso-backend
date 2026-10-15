<?php

use App\Enums\AppRole;
use App\Models\Application;
use App\Models\Employee;
use App\Models\OAuthToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->employee = Employee::factory()->create();
    $this->application = Application::factory()->create([
        'client_id' => 'code-client',
        'client_secret' => Hash::make('code-secret'),
        'redirect_uris' => ['https://consumer.example/sso/callback'],
    ]);
    $this->employee->applications()->attach($this->application->id, ['role' => AppRole::Standard->value]);
    $this->clientHeaders = ['X-Client-ID' => 'code-client', 'X-Client-Secret' => 'code-secret'];
});

function issueAuthorizationCode($test): string
{
    $token = JWTAuth::fromUser($test->employee);
    OAuthToken::create(['employee_id' => $test->employee->id, 'access_token' => hash('sha256', $token)]);
    $response = $test->withToken($token)->postJson('/api/v1/sso/code', [
        'client_id' => 'code-client',
        'redirect_uri' => 'https://consumer.example/sso/callback',
    ]);
    $response->assertOk()->assertJsonPath('expires_in', 60);

    return $response->json('code');
}

it('exchanges a code once for a server-side bearer token', function () {
    $code = issueAuthorizationCode($this);
    expect(DB::table('sso_authorization_codes')->first()->code_hash)->toBe(hash('sha256', $code));

    $exchange = $this->postJson('/api/v1/sso/exchange', [
        'code' => $code,
        'redirect_uri' => 'https://consumer.example/sso/callback',
    ], $this->clientHeaders);
    $exchange->assertOk()->assertJsonStructure(['access_token', 'token_type']);

    $this->postJson('/api/v1/sso/validate', ['token' => $exchange->json('access_token')], $this->clientHeaders)
        ->assertOk()->assertJsonPath('valid', true);
    $this->postJson('/api/v1/sso/exchange', [
        'code' => $code,
        'redirect_uri' => 'https://consumer.example/sso/callback',
    ], $this->clientHeaders)->assertUnauthorized();
});

it('reports the issued token lifetime without treating no expiry as zero', function (?int $ttl, ?int $seconds) {
    config(['jwt.ttl' => $ttl]);
    JWTAuth::factory()->setTTL($ttl);
    $code = issueAuthorizationCode($this);

    $exchange = $this->postJson('/api/v1/sso/exchange', [
        'code' => $code,
        'redirect_uri' => 'https://consumer.example/sso/callback',
    ], $this->clientHeaders);
    $exchange->assertOk()
        ->assertJsonStructure(['access_token', 'token_type', 'expires_in'])
        ->assertJsonPath('expires_in', $seconds);
    expect($exchange->headers->get('Cache-Control'))->toContain('no-store');
    $payload = JWTAuth::setToken($exchange->json('access_token'))->getPayload();

    if ($seconds === null) {
        expect($payload->toArray())->not->toHaveKey('exp');
    } else {
        expect($payload->get('exp') - $payload->get('iat'))->toBe($seconds)
            ->and($seconds)->toBeGreaterThan(0);
    }

    $this->postJson('/api/v1/sso/authorize', ['token' => $exchange->json('access_token')], $this->clientHeaders)
        ->assertOk()->assertJsonPath('authorized', true);
})->with([
    'nonexpiring token' => [null, null],
    'one minute token' => [1, 60],
    'one hour token' => [60, 3600],
]);

it('binds the code to the exact client and redirect uri', function () {
    $code = issueAuthorizationCode($this);
    $this->postJson('/api/v1/sso/exchange', [
        'code' => $code,
        'redirect_uri' => 'https://consumer.example/sso/callback/',
    ], $this->clientHeaders)->assertUnauthorized();

    $other = Application::factory()->create(['client_id' => 'other', 'client_secret' => Hash::make('other-secret')]);
    $this->postJson('/api/v1/sso/exchange', [
        'code' => $code,
        'redirect_uri' => 'https://consumer.example/sso/callback',
    ], ['X-Client-ID' => 'other', 'X-Client-Secret' => 'other-secret'])->assertUnauthorized();

    $this->postJson('/api/v1/sso/exchange', [
        'code' => $code,
        'redirect_uri' => 'https://consumer.example/sso/callback',
    ], $this->clientHeaders)->assertOk();
});

it('rejects expired codes and unauthorized issuance', function () {
    $code = issueAuthorizationCode($this);
    DB::table('sso_authorization_codes')->update(['expires_at' => now()->subSecond()]);
    $this->postJson('/api/v1/sso/exchange', [
        'code' => $code,
        'redirect_uri' => 'https://consumer.example/sso/callback',
    ], $this->clientHeaders)->assertUnauthorized();

    $this->employee->applications()->detach($this->application->id);
    $this->postJson('/api/v1/sso/code', [
        'client_id' => 'code-client',
        'redirect_uri' => 'https://consumer.example/sso/callback',
    ])->assertForbidden();
});

it('rejects codes after their source session is revoked', function () {
    $code = issueAuthorizationCode($this);
    OAuthToken::query()->update(['revoked_at' => now()]);
    $this->postJson('/api/v1/sso/exchange', [
        'code' => $code,
        'redirect_uri' => 'https://consumer.example/sso/callback',
    ], $this->clientHeaders)->assertForbidden();
});
