<?php

use App\Enums\AppRole;
use App\Models\Application;
use App\Models\Employee;
use App\Models\OAuthToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->employee = Employee::factory()->create();
    $this->application = Application::factory()->create([
        'client_secret' => Hash::make('client-secret'),
        'redirect_uris' => ['https://consumer.example/callback'],
    ]);
    $this->employee->applications()->attach($this->application, ['role' => AppRole::Standard->value]);
    $this->headers = ['X-Client-ID' => $this->application->client_id, 'X-Client-Secret' => 'client-secret'];
    $this->applicationToken = $this->issueSsoToken($this->employee, $this->application);
});

it('denies application tokens on every central route even for administrators', function (string $method, string $route, array $body) {
    $admin = Application::factory()->create(['name' => 'Admin App Management System']);
    $this->employee->applications()->attach($admin, ['role' => AppRole::SuperAdministrator->value]);
    $this->withToken($this->applicationToken)->json($method, $route, $body)->assertForbidden();
})->with([
    ['GET', '/api/v1/stats/dashboard', []],
    ['GET', '/api/v1/auth/me', []],
    ['GET', '/api/v1/portal/profile', []],
    ['GET', '/api/v1/portal/offices', []],
    ['GET', '/api/v1/portal/positions', []],
    ['POST', '/api/v1/auth/refresh', []],
    ['POST', '/api/v1/auth/logout-all', []],
    ['POST', '/api/v1/auth/change-password', []],
    ['POST', '/api/v1/sso/code', []],
]);

it('lets central employee sessions read self-service office and active position options', function () {
    \App\Models\Office::factory()->create(['name' => 'Municipal Health Office']);
    \App\Models\Position::create(['title' => 'Health Officer', 'is_active' => true]);
    \App\Models\Position::create(['title' => 'Retired Position', 'is_active' => false]);

    $this->withToken($this->issueSsoToken($this->employee))
        ->getJson('/api/v1/portal/offices')
        ->assertOk()->assertJsonFragment(['name' => 'Municipal Health Office']);
    $this->getJson('/api/v1/portal/positions')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Health Officer');
});

it('rejects another application token and central tokens at consumer endpoints', function (string $method, string $route) {
    $other = Application::factory()->create();
    $this->employee->applications()->attach($other, ['role' => AppRole::Standard->value]);
    foreach ([$this->issueSsoToken($this->employee, $other), $this->issueSsoToken($this->employee)] as $token) {
        $this->withToken($token)->json($method, $route, ['token' => $token], $this->headers)->assertForbidden();
    }
})->with([
    ['POST', '/api/v1/sso/validate'],
    ['POST', '/api/v1/sso/authorize'],
    ['GET', '/api/v1/sso/employee'],
]);

it('validates its own application and returns the current role and only that application', function () {
    $other = Application::factory()->create();
    $this->employee->applications()->attach($other, ['role' => AppRole::SuperAdministrator->value]);
    $this->postJson('/api/v1/sso/validate', ['token' => $this->applicationToken], $this->headers)
        ->assertOk()->assertJsonCount(1, 'data.applications')->assertJsonPath('data.applications.0.uuid', $this->application->uuid);
    $this->employee->applications()->updateExistingPivot($this->application->id, ['role' => AppRole::Guest->value]);
    $this->postJson('/api/v1/sso/authorize', ['token' => $this->applicationToken], $this->headers)
        ->assertOk()->assertJsonPath('role', AppRole::Guest->value);
    $this->withToken($this->applicationToken)->getJson('/api/v1/sso/employee', $this->headers)->assertOk();
});

it('denies a removed grant even if its token row remains active', function () {
    $this->employee->applications()->detach($this->application);
    $this->postJson('/api/v1/sso/validate', ['token' => $this->applicationToken], $this->headers)->assertForbidden();
});

it('revokes tokens and outstanding codes through both grant removal routes', function (string $route) {
    $central = $this->issueSsoToken($this->employee);
    $code = $this->withToken($central)->postJson('/api/v1/sso/code', [
        'client_id' => $this->application->client_id, 'redirect_uri' => 'https://consumer.example/callback',
    ])->assertOk()->json('code');
    $admin = $this->employee;
    $url = str_replace(['{employee}', '{application}'], [$this->employee->uuid, $this->application->uuid], $route);
    $this->asSsoAdmin($admin)->deleteJson($url)->assertOk();
    $this->employee->applications()->attach($this->application, ['role' => AppRole::Standard->value]);
    $this->postJson('/api/v1/sso/validate', ['token' => $this->applicationToken], $this->headers)->assertUnauthorized();
    $this->postJson('/api/v1/sso/exchange', ['code' => $code, 'redirect_uri' => 'https://consumer.example/callback'], $this->headers)->assertUnauthorized();
})->with([
    '/api/v1/employees/{employee}/applications/{application}',
    '/api/v1/applications/{application}/employees/{employee}',
]);

it('allows password setup and blocks administration and application work until complete', function () {
    $admin = Application::factory()->create(['name' => 'Admin App Management System']);
    $this->employee->applications()->attach($admin, ['role' => AppRole::SuperAdministrator->value]);
    $this->employee->update(['must_change_password' => true]);
    $central = $this->issueSsoToken($this->employee);
    $this->withToken($central)->getJson('/api/v1/auth/me')->assertOk();
    $this->getJson('/api/v1/stats/dashboard')->assertForbidden()->assertJsonPath('must_change_password', true);
    $this->postJson('/api/v1/sso/code', ['client_id' => $this->application->client_id, 'redirect_uri' => 'https://consumer.example/callback'])->assertForbidden();
    $freshApplicationToken = $this->issueSsoToken($this->employee, $this->application);
    $this->postJson('/api/v1/sso/authorize', ['token' => $freshApplicationToken], $this->headers)->assertForbidden();
    $this->withToken($central)->postJson('/api/v1/auth/change-password', ['current_password' => 'password', 'new_password' => 'Changed-Password-123!'])->assertOk();
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.must_change_password', false);
    $this->postJson('/api/v1/sso/validate', ['token' => $freshApplicationToken], $this->headers)->assertUnauthorized();
});

it('application logout preserves central and other application sessions and cookies', function () {
    $central = $this->issueSsoToken($this->employee);
    $other = Application::factory()->create();
    $otherToken = $this->issueSsoToken($this->employee, $other);
    $this->withToken($this->applicationToken)->postJson('/api/v1/auth/logout')->assertOk()->assertCookieMissing(config('sso.cookie_name'));
    expect(OAuthToken::where('access_token', hash('sha256', $this->applicationToken))->first()->revoked_at)->not->toBeNull();
    foreach ([$central, $otherToken] as $token) {
        expect(OAuthToken::where('access_token', hash('sha256', $token))->first()->revoked_at)->toBeNull();
    }
});

it('central logout preserves already issued application sessions', function () {
    $central = $this->issueSsoToken($this->employee);
    $this->withToken($central)->postJson('/api/v1/auth/logout')->assertOk();
    $this->postJson('/api/v1/sso/authorize', ['token' => $this->applicationToken], $this->headers)->assertOk();
});

it('logout everywhere revokes application tokens and outstanding codes', function () {
    $central = $this->issueSsoToken($this->employee);
    $code = $this->withToken($central)->postJson('/api/v1/sso/code', ['client_id' => $this->application->client_id, 'redirect_uri' => 'https://consumer.example/callback'])->assertOk()->json('code');
    $this->postJson('/api/v1/auth/logout-all')->assertOk();
    $this->postJson('/api/v1/sso/authorize', ['token' => $this->applicationToken], $this->headers)->assertUnauthorized();
    $this->postJson('/api/v1/sso/exchange', ['code' => $code, 'redirect_uri' => 'https://consumer.example/callback'], $this->headers)->assertUnauthorized();
});

it('does not revive sessions after an employee or application is reactivated', function (string $target) {
    $model = $target === 'employee' ? $this->employee : $this->application;
    $model->update(['is_active' => false]);
    $model->update(['is_active' => true]);
    $this->postJson('/api/v1/sso/validate', ['token' => $this->applicationToken], $this->headers)->assertUnauthorized();
})->with(['employee', 'application']);

it('does not exchange a code whose source token has application scope', function () {
    DB::table('sso_authorization_codes')->insert([
        'code_hash' => hash('sha256', 'invalid-source'), 'employee_id' => $this->employee->id,
        'application_id' => $this->application->id, 'source_token_hash' => hash('sha256', $this->applicationToken),
        'redirect_uri' => 'https://consumer.example/callback', 'expires_at' => now()->addMinute(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->postJson('/api/v1/sso/exchange', ['code' => 'invalid-source', 'redirect_uri' => 'https://consumer.example/callback'], $this->headers)->assertForbidden();
});

it('does not treat an application bearer as a central cookie session', function () {
    $this->withUnencryptedCookies([config('sso.cookie_name') => $this->applicationToken])->getJson('/api/v1/sso/session-check')
        ->assertOk()->assertJsonPath('authenticated', false)->assertJsonMissingPath('access_token');
});

it('only lists active employees granted access to the requesting application', function () {
    Employee::factory()->create();
    $inactive = Employee::factory()->inactive()->create();
    $this->application->employees()->attach($inactive, ['role' => AppRole::Standard->value]);
    $this->getJson('/api/v1/sso/employees', $this->headers)->assertOk()->assertJsonCount(1)->assertJsonPath('0.uuid', $this->employee->uuid);
});

it('revokes application tokens before deletion can clear their application scope', function () {
    $this->application->forceDelete();
    $this->withToken($this->applicationToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('invalidates issued tokens and pending codes when client secrets rotate', function () {
    $central = $this->issueSsoToken($this->employee);
    $code = $this->withToken($central)->postJson('/api/v1/sso/code', [
        'client_id' => $this->application->client_id, 'redirect_uri' => 'https://consumer.example/callback',
    ])->assertOk()->json('code');
    $newSecret = $this->application->generateNewSecret();
    $headers = ['X-Client-ID' => $this->application->client_id, 'X-Client-Secret' => $newSecret];
    $this->postJson('/api/v1/sso/validate', ['token' => $this->applicationToken], $headers)->assertUnauthorized();
    $this->postJson('/api/v1/sso/exchange', ['code' => $code, 'redirect_uri' => 'https://consumer.example/callback'], $headers)->assertUnauthorized();
});

it('rotates a central session without acquiring application scope or leaving the old session active', function () {
    $central = $this->issueSsoToken($this->employee);
    $newToken = $this->withToken($central)->postJson('/api/v1/auth/refresh')->assertOk()->json('access_token');
    expect(OAuthToken::where('access_token', hash('sha256', $central))->first()->revoked_at)->not->toBeNull();
    $session = OAuthToken::where('access_token', hash('sha256', $newToken))->firstOrFail();
    expect($session->application_id)->toBeNull()->and($session->revoked_at)->toBeNull();
});
