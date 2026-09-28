<?php

use App\Models\Application;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('returns JSON unauthorized without an Accept header', function () {
    $this->get('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertHeader('content-type', 'application/json');
});

it('rejects every older session after logout-all', function () {
    $employee = Employee::factory()->create();
    $first = $this->issueSsoToken($employee);
    $second = $this->issueSsoToken($employee);
    $application = Application::factory()->create([
        'client_id' => 'revocation-test-client',
        'client_secret' => Hash::make('revocation-test-secret'),
    ]);

    $this->withToken($first)->postJson('/api/v1/auth/logout-all')->assertSuccessful();
    $this->withToken($second)->getJson('/api/v1/auth/me')->assertUnauthorized();
    $this->withUnencryptedCookies([config('sso.cookie_name') => $second])
        ->getJson('/api/v1/sso/session-check')
        ->assertJsonPath('authenticated', false);
    $this->postJson('/api/v1/sso/validate', ['token' => $second], [
        'X-Client-ID' => $application->client_id,
        'X-Client-Secret' => 'revocation-test-secret',
    ])->assertUnauthorized();
});

it('keeps administration endpoints unavailable to ordinary employees', function () {
    $employee = Employee::factory()->create();
    $this->asSsoEmployee($employee)->getJson('/api/v1/employees')->assertForbidden();
    $this->getJson('/api/v1/portal/profile')->assertSuccessful();
});
