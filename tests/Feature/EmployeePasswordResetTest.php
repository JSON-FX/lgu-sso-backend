<?php

use App\Enums\AppRole;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\OAuthToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Employee::factory()->create();
    $this->employee = Employee::factory()->create(['password' => 'Old-password-123']);
    $this->application = Application::factory()->create([
        'client_id' => 'reset-test-client', 'client_secret' => Hash::make('reset-test-secret'),
        'redirect_uris' => ['https://consumer.example/callback'],
    ]);
    $this->employee->applications()->attach($this->application->id, ['role' => AppRole::Standard->value]);
    $this->headers = ['X-Client-ID' => 'reset-test-client', 'X-Client-Secret' => 'reset-test-secret'];
    $this->endpoint = "/api/v1/employees/{$this->employee->uuid}/reset-password";
});

it('resets to a one-time displayed hashed password and revokes all sessions and codes', function () {
    $central = $this->issueSsoToken($this->employee);
    $applicationToken = $this->issueSsoToken($this->employee, $this->application);
    $code = $this->withToken($central)->postJson('/api/v1/sso/code', [
        'client_id' => $this->application->client_id, 'redirect_uri' => 'https://consumer.example/callback',
    ])->assertOk()->json('code');
    app('auth')->forgetGuards();
    app('tymon.jwt')->unsetToken();
    \Tymon\JWTAuth\Facades\JWTAuth::unsetToken();
    $response = $this->asSsoAdmin($this->admin)->postJson($this->endpoint)->assertOk()
        ->assertJsonPath('data.username', $this->employee->username)
        ->assertJsonPath('data.must_change_password', true);
    $temporary = $response->json('temporary_password');
    expect(strlen($temporary))->toBe(20);
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    expect(Hash::check($temporary, $this->employee->fresh()->password))->toBeTrue();
    expect($this->employee->fresh()->must_change_password)->toBeTrue();
    expect($this->employee->tokens()->whereNull('revoked_at')->count())->toBe(0);
    expect(DB::table('sso_authorization_codes')->where('employee_id', $this->employee->id)->count())->toBe(0);
    expect($this->employee->applications()->count())->toBe(1);
    $audit = AuditLog::where('action', 'employee_password_reset')->sole();
    expect($audit->employee_id)->toBe($this->admin->id);
    expect($audit->metadata)->toBe(['employee_uuid' => $this->employee->uuid]);
    expect($audit->toJson())->not->toContain($temporary)->not->toContain('Old-password-123');
    $this->getJson("/api/v1/employees/{$this->employee->uuid}")->assertOk()->assertJsonMissingPath('temporary_password')->assertJsonMissingPath('data.password');
    app('auth')->forgetGuards();
    app('tymon.jwt')->unsetToken();
    \Tymon\JWTAuth\Facades\JWTAuth::unsetToken();
    $this->withToken($central)->getJson('/api/v1/auth/me')->assertUnauthorized();
    $this->postJson('/api/v1/sso/authorize', ['token' => $applicationToken], $this->headers)->assertUnauthorized();
    $this->postJson('/api/v1/sso/exchange', ['code' => $code, 'redirect_uri' => 'https://consumer.example/callback'], $this->headers)->assertUnauthorized();
    $this->postJson('/api/v1/auth/login', ['username' => $this->employee->username, 'password' => 'Old-password-123'])->assertUnauthorized();
});

it('requires setup after temporary login and accepts only a different new password', function () {
    $temporary = $this->asSsoAdmin($this->admin)->postJson($this->endpoint)->assertOk()->json('temporary_password');
    $login = $this->postJson('/api/v1/auth/login', ['username' => $this->employee->username, 'password' => $temporary])
        ->assertOk()->assertJsonPath('employee.must_change_password', true);
    $token = $login->json('access_token');
    app('auth')->forgetGuards();
    app('tymon.jwt')->unsetToken();
    \Tymon\JWTAuth\Facades\JWTAuth::unsetToken();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    $this->getJson('/api/v1/portal/profile')->assertForbidden();
    $this->getJson('/api/v1/employees')->assertForbidden();
    $this->postJson('/api/v1/auth/refresh')->assertForbidden();
    $this->postJson('/api/v1/sso/code', ['client_id' => $this->application->client_id, 'redirect_uri' => 'https://consumer.example/callback'])->assertForbidden();
    $this->postJson('/api/v1/auth/change-password', ['current_password' => $temporary, 'new_password' => $temporary])
        ->assertUnprocessable()->assertJsonValidationErrors('new_password');
    $this->postJson('/api/v1/auth/change-password', ['current_password' => $temporary, 'new_password' => 'Personal-password-456'])->assertOk();
    expect($this->employee->fresh()->must_change_password)->toBeFalse();
    app('auth')->forgetGuards();
    app('tymon.jwt')->unsetToken();
    \Tymon\JWTAuth\Facades\JWTAuth::unsetToken();
    $this->getJson('/api/v1/portal/profile')->assertOk();
    $this->postJson('/api/v1/auth/login', ['username' => $this->employee->username, 'password' => $temporary])->assertUnauthorized();
    $this->postJson('/api/v1/auth/login', ['username' => $this->employee->username, 'password' => 'Personal-password-456'])->assertOk();
});

it('revokes setup sessions on repeated resets and rejects their stale password-change requests', function () {
    $first = $this->asSsoAdmin($this->admin)->postJson($this->endpoint)->assertOk()->json('temporary_password');
    $token = $this->postJson('/api/v1/auth/login', ['username' => $this->employee->username, 'password' => $first])->assertOk()->json('access_token');
    $second = $this->asSsoAdmin($this->admin)->postJson($this->endpoint)->assertOk()->json('temporary_password');
    expect($second)->not->toBe($first);
    app('auth')->forgetGuards();
    app('tymon.jwt')->unsetToken();
    \Tymon\JWTAuth\Facades\JWTAuth::unsetToken();
    $this->withToken($token)->postJson('/api/v1/auth/change-password', ['current_password' => $first, 'new_password' => 'Personal-password-456'])->assertUnauthorized();
    $this->postJson('/api/v1/auth/login', ['username' => $this->employee->username, 'password' => $first])->assertUnauthorized();
    $this->postJson('/api/v1/auth/login', ['username' => $this->employee->username, 'password' => $second])->assertOk();
});

it('denies unauthorized resets without changing the employee', function (string $case, int $status) {
    $before = $this->employee->password;
    if ($case !== 'anonymous') {
        $this->asSsoAdmin($this->admin);
        $grant = Application::where('name', 'Admin App Management System')->firstOrFail();
        match ($case) {
            'ordinary' => $this->admin->applications()->updateExistingPivot($grant->id, ['role' => AppRole::Standard->value]),
            'revoked-grant' => $this->admin->applications()->detach($grant->id),
            'inactive-grant' => $grant->update(['is_active' => false]),
            'revoked-session' => $this->admin->revokeSessions(),
            'inactive-actor' => $this->admin->update(['is_active' => false]),
            'setup-actor' => $this->withToken($this->issueSsoToken(tap($this->admin)->update(['must_change_password' => true]))),
            'application-session' => $this->withToken($this->issueSsoToken($this->admin, $grant)),
        };
    }
    $this->postJson($this->endpoint)->assertStatus($status);
    expect($this->employee->fresh()->password)->toBe($before);
    expect(AuditLog::where('action', 'employee_password_reset')->count())->toBe(0);
})->with([
    ['anonymous', 401], ['ordinary', 403], ['revoked-grant', 403], ['inactive-grant', 403],
    ['revoked-session', 401], ['inactive-actor', 401], ['setup-actor', 403], ['application-session', 403],
]);

it('rejects self-reset and direct password edits', function () {
    $this->asSsoAdmin($this->admin)->postJson("/api/v1/employees/{$this->admin->uuid}/reset-password")->assertUnprocessable();
    $before = $this->employee->password;
    $this->putJson("/api/v1/employees/{$this->employee->uuid}", ['password' => 'Bypass-password-123', 'must_change_password' => false])
        ->assertUnprocessable()->assertJsonValidationErrors(['password', 'must_change_password']);
    expect($this->employee->fresh()->password)->toBe($before);
});

it('does not reactivate an inactive employee', function () {
    $this->employee->update(['is_active' => false]);
    $password = $this->asSsoAdmin($this->admin)->postJson($this->endpoint)->assertOk()->json('temporary_password');
    expect($this->employee->fresh()->is_active)->toBeFalse();
    $this->postJson('/api/v1/auth/login', ['username' => $this->employee->username, 'password' => $password])->assertUnauthorized();
});

it('rolls back the password and revocations if the audit cannot be saved', function () {
    $token = $this->issueSsoToken($this->employee);
    $before = $this->employee->password;
    AuditLog::creating(function (AuditLog $log) {
        if ($log->action === 'employee_password_reset') {
            throw new RuntimeException('Simulated audit failure');
        }
    });
    $this->asSsoAdmin($this->admin)->postJson($this->endpoint)->assertStatus(500);
    expect($this->employee->fresh()->password)->toBe($before);
    expect($this->employee->fresh()->must_change_password)->toBeFalse();
    expect(OAuthToken::where('access_token', hash('sha256', $token))->value('revoked_at'))->toBeNull();
});

it('does not issue a token when credentials change after the initial password check', function () {
    $replacement = Hash::make('Replacement-password-123');
    Hash::partialMock()->shouldReceive('check')->once()->andReturnUsing(function () use ($replacement) {
        DB::table('employees')->where('id', $this->employee->id)->update(['password' => $replacement, 'must_change_password' => true]);

        return true;
    });
    $this->postJson('/api/v1/auth/login', ['username' => $this->employee->username, 'password' => 'Old-password-123'])->assertUnauthorized();
    expect($this->employee->tokens()->count())->toBe(0);
});
