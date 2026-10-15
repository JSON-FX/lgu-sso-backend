<?php

use App\Enums\AppRole;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\OAuthToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->employee = Employee::factory()->create();
    $this->application = Application::factory()->create([
        'client_secret' => Hash::make('audit-client-secret'),
    ]);
    $this->employee->applications()->attach($this->application, ['role' => AppRole::Standard->value]);
    $this->token = $this->issueSsoToken($this->employee, $this->application);
    $this->headers = [
        'X-Client-ID' => $this->application->client_id,
        'X-Client-Secret' => 'audit-client-secret',
        'User-Agent' => 'SSO audit regression',
    ];
});

it('records one complete verified success event per application check', function ($endpoint, $action) {
    $this->postJson('/api/v1/sso/'.$endpoint, ['token' => $this->token], $this->headers)->assertOk();

    $event = AuditLog::sole();
    expect($event->action)->toBe($action)
        ->and($event->employee_id)->toBe($this->employee->id)
        ->and($event->application_id)->toBe($this->application->id)
        ->and($event->user_agent)->toBe('SSO audit regression')
        ->and($event->ip_address)->not->toBeNull()
        ->and($event->metadata)->toBe([
            'method' => 'POST',
            'path' => 'api/v1/sso/'.$endpoint,
            'status_code' => 200,
        ]);
    expect(json_encode($event->toArray()))->not->toContain($this->token, 'audit-client-secret');
})->with([['authorize', 'app_authorize'], ['validate', 'token_validate']]);

it('keeps rejected checks auditable without trusting submitted identities', function ($endpoint) {
    $this->postJson('/api/v1/sso/'.$endpoint, [
        'token' => 'invalid-token',
        'employee_id' => $this->employee->id,
        'sso_audit_employee' => $this->employee->toArray(),
    ], $this->headers)->assertUnauthorized();

    $event = AuditLog::sole();
    expect($event->employee_id)->toBeNull()
        ->and($event->application_id)->toBe($this->application->id)
        ->and($event->metadata['status_code'])->toBe(401);
})->with(['authorize', 'validate']);

it('records denied application access once without attributing a verified actor', function ($endpoint) {
    $this->employee->applications()->detach($this->application);
    $this->postJson('/api/v1/sso/'.$endpoint, ['token' => $this->token], $this->headers)->assertForbidden();

    $event = AuditLog::sole();
    expect($event->employee_id)->toBeNull()
        ->and($event->application_id)->toBe($this->application->id)
        ->and($event->metadata['status_code'])->toBe(403);
})->with(['authorize', 'validate']);

it('does not reuse a successful check after token revocation', function () {
    $this->postJson('/api/v1/sso/authorize', ['token' => $this->token], $this->headers)->assertOk();
    OAuthToken::where('access_token', hash('sha256', $this->token))->update(['revoked_at' => now()]);

    $this->postJson('/api/v1/sso/authorize', ['token' => $this->token], $this->headers)->assertUnauthorized();

    $events = AuditLog::orderBy('id')->get();
    expect($events)->toHaveCount(2)
        ->and($events[0]->metadata['status_code'])->toBe(200)
        ->and($events[1]->metadata['status_code'])->toBe(401)
        ->and($events[1]->employee_id)->toBeNull();
});
