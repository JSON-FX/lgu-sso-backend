<?php

use App\Enums\AppRole;
use App\Models\Application;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->employee = Employee::factory()->create();
    $this->application = Application::factory()->create([
        'client_id' => 'test-client-id',
        'client_secret' => Hash::make('test-client-secret'),
    ]);
});

it('can validate a token with valid credentials', function () {
    $this->employee->applications()->attach($this->application, ['role' => AppRole::Standard->value]);
    $token = $this->issueSsoToken($this->employee, $this->application);

    $response = $this->postJson('/api/v1/sso/validate', [
        'token' => $token,
    ], [
        'X-Client-ID' => 'test-client-id',
        'X-Client-Secret' => 'test-client-secret',
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('valid', true)
        ->assertJsonStructure(['data' => ['uuid', 'email']]);
});

it('returns invalid for expired or bad token', function () {
    $response = $this->postJson('/api/v1/sso/validate', [
        'token' => 'invalid-token',
    ], [
        'X-Client-ID' => 'test-client-id',
        'X-Client-Secret' => 'test-client-secret',
    ]);

    $response->assertUnauthorized()
        ->assertJsonPath('valid', false);
});

it('rejects requests without client credentials', function () {
    $response = $this->postJson('/api/v1/sso/validate', [
        'token' => 'some-token',
    ]);

    $response->assertUnauthorized();
});

it('can authorize employee for an application', function () {
    $this->employee->applications()->attach($this->application->id, [
        'role' => AppRole::Standard->value,
    ]);

    $token = $this->issueSsoToken($this->employee, $this->application);

    $response = $this->postJson('/api/v1/sso/authorize', [
        'token' => $token,
    ], [
        'X-Client-ID' => 'test-client-id',
        'X-Client-Secret' => 'test-client-secret',
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('authorized', true)
        ->assertJsonPath('role', AppRole::Standard->value);
});

it('denies authorization for employee without access', function () {
    $token = $this->issueSsoToken($this->employee, $this->application);

    $response = $this->postJson('/api/v1/sso/authorize', [
        'token' => $token,
    ], [
        'X-Client-ID' => 'test-client-id',
        'X-Client-Secret' => 'test-client-secret',
    ]);

    $response->assertForbidden()
        ->assertJsonPath('authorized', false);
});
