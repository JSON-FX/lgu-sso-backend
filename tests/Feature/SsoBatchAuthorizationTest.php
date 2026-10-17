<?php

use App\Enums\AppRole;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\OAuthToken;
use App\Support\ApplicationSessionAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->employee = Employee::factory()->create();
    $this->application = Application::factory()->create(['client_secret' => Hash::make('batch-client-secret')]);
    $this->employee->applications()->attach($this->application, ['role' => AppRole::Standard->value]);
    $this->token = $this->issueSsoToken($this->employee, $this->application);
    $this->headers = ['X-Client-ID' => $this->application->client_id, 'X-Client-Secret' => 'batch-client-secret'];
    $this->body = ['items' => [['id' => '0', 'token' => $this->token]]];
    config(['sso.batch.enabled' => true, 'sso.batch.application_uuid' => $this->application->uuid,
        'sso.batch.items_per_minute' => 100, 'sso.batch.global_items_per_minute' => 200]);
    $this->withServerVariables(['SSO_BATCH_POOL' => '1']);
});

it('returns exact ordered mixed decisions with fresh singleton parity and no token material', function () {
    $other = Application::factory()->create();
    $this->employee->applications()->attach($other, ['role' => 'guest']);
    $central = $this->issueSsoToken($this->employee);
    $foreign = $this->issueSsoToken($this->employee, $other);
    $items = [['id' => 'good', 'token' => $this->token], ['id' => 'bad', 'token' => 'invalid-token'],
        ['id' => 'central', 'token' => $central], ['id' => 'foreign', 'token' => $foreign]];
    $response = $this->postJson('/api/v1/sso/authorize-batch', ['items' => $items], $this->headers)
        ->assertOk()->assertJsonCount(4, 'data')
        ->assertJsonPath('data.0.id', 'good')->assertJsonPath('data.0.role', 'standard')
        ->assertJsonPath('data.0.employee.uuid', $this->employee->uuid)
        ->assertJsonPath('data.1', ['id' => 'bad', 'authorized' => false, 'status' => 401])
        ->assertJsonPath('data.2.status', 403)->assertJsonPath('data.3.status', 403);
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    foreach (array_column($items, 'token') as $token) {
        expect($response->getContent().AuditLog::all()->toJson())->not->toContain($token, hash('sha256', $token));
    }
    expect(AuditLog::count())->toBe(4)->and(AuditLog::whereNotNull('employee_id')->count())->toBe(1);
    $this->postJson('/api/v1/sso/authorize', ['token' => $this->token], $this->headers)
        ->assertOk()->assertJsonPath('role', $response->json('data.0.role'));
});

it('checks current token grant role and password state on each new batch', function () {
    $this->postJson('/api/v1/sso/authorize-batch', $this->body, $this->headers)->assertOk()->assertJsonPath('data.0.authorized', true);
    $this->employee->applications()->updateExistingPivot($this->application->id, ['role' => 'guest']);
    $this->postJson('/api/v1/sso/authorize-batch', $this->body, $this->headers)->assertOk()->assertJsonPath('data.0.role', 'guest');
    DB::table('employees')->where('id', $this->employee->id)->update(['must_change_password' => true]);
    $this->postJson('/api/v1/sso/authorize-batch', $this->body, $this->headers)->assertOk()->assertJsonPath('data.0.status', 403);
    DB::table('employees')->where('id', $this->employee->id)->update(['must_change_password' => false]);
    $this->employee->applications()->detach($this->application);
    $this->postJson('/api/v1/sso/authorize-batch', $this->body, $this->headers)->assertOk()->assertJsonPath('data.0.status', 403);
    $this->employee->applications()->attach($this->application, ['role' => 'standard']);
    OAuthToken::where('access_token', hash('sha256', $this->token))->update(['revoked_at' => now()]);
    $this->postJson('/api/v1/sso/authorize-batch', $this->body, $this->headers)->assertOk()->assertJsonPath('data.0.status', 401);
});

it('does not leak claims between expiring nonexpiring expired and invalid JWT items', function () {
    $base = JWTAuth::setToken($this->token)->getPayload()->toArray();
    $tokens = [];
    foreach (['future', 'forever', 'expired', 'forever-again'] as $type) {
        $claims = array_merge($base, ['jti' => (string) \Illuminate\Support\Str::uuid(), 'iat' => now()->subMinutes(2)->timestamp, 'nbf' => now()->subMinutes(2)->timestamp]);
        if (str_starts_with($type, 'forever')) {
            unset($claims['exp']);
        } else {
            $claims['exp'] = $type === 'expired' ? now()->subMinute()->timestamp : now()->addHour()->timestamp;
        }
        $token = app('tymon.jwt.provider.jwt')->encode($claims);
        OAuthToken::create(['employee_id' => $this->employee->id, 'application_id' => $this->application->id, 'access_token' => hash('sha256', $token)]);
        $tokens[] = ['id' => $type, 'token' => $token];
    }
    array_splice($tokens, 2, 0, [['id' => 'invalid', 'token' => 'invalid-token']]);
    foreach ([$tokens, array_reverse($tokens)] as $items) {
        $data = $this->postJson('/api/v1/sso/authorize-batch', ['items' => $items], $this->headers)->assertOk()->json('data');
        foreach ($data as $result) {
            expect($result['authorized'])->toBe(! in_array($result['id'], ['expired', 'invalid'], true));
        }
    }
});

it('denies inactive deleted blacklisted and subject-mismatched token records', function (string $state) {
    if ($state === 'inactive') {
        $this->employee->update(['is_active' => false]);
    } elseif ($state === 'deleted') {
        $this->employee->delete();
    } elseif ($state === 'blacklisted') {
        JWTAuth::setToken($this->token)->invalidate();
    } else {
        OAuthToken::where('access_token', hash('sha256', $this->token))->update(['employee_id' => Employee::factory()->create()->id]);
    }
    $this->postJson('/api/v1/sso/authorize-batch', $this->body, $this->headers)->assertOk()->assertJsonPath('data.0.status', 401);
})->with(['inactive', 'deleted', 'blacklisted', 'wrong-subject']);

it('rejects malformed shapes atomically without returning submitted keys or tokens', function (string $case) {
    $body = $this->body;
    match ($case) {
        'empty' => $body['items'] = [],
        'too-many' => $body['items'] = array_fill(0, 11, $body['items'][0]),
        'duplicate-id' => $body['items'][] = ['id' => '0', 'token' => 'different'],
        'duplicate-token' => $body['items'][] = ['id' => 'other', 'token' => $this->token],
        'extra-top' => $body['extra'] = true,
        'extra-item' => $body['items'][0]['employee_id'] = 123,
        'missing-id' => $body['items'][0] = ['token' => $this->token],
        'numeric-id' => $body['items'][0]['id'] = 0,
        'long-id' => $body['items'][0]['id'] = str_repeat('a', 65),
        'object-list' => $body['items'] = ['sensitive-submitted-key' => $body['items'][0]],
        'leading-space' => $body['items'][0]['token'] = ' '.$this->token,
        'trailing-space' => $body['items'][0]['token'] = $this->token.' ',
        'control' => $body['items'][0]['token'] = "abc\0def",
        'too-long-token' => $body['items'][0]['token'] = str_repeat('x', 16385),
    };
    $response = $this->postJson('/api/v1/sso/authorize-batch', $body, $this->headers)->assertUnprocessable();
    expect($response->getContent())->not->toContain($this->token, 'sensitive-submitted-key');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect(AuditLog::count())->toBe(0);
})->with(['empty', 'too-many', 'duplicate-id', 'duplicate-token', 'extra-top', 'extra-item', 'missing-id', 'numeric-id', 'long-id', 'object-list', 'leading-space', 'trailing-space', 'control', 'too-long-token']);

it('enforces body and parser limits before validation and secrets stay private', function () {
    $headers = ['CONTENT_TYPE' => 'application/json', 'SSO_BATCH_POOL' => '1'];
    $this->call('POST', '/api/v1/sso/authorize-batch', [], [], [], $headers, str_repeat('x', 196609))->assertStatus(413);
    $this->call('POST', '/api/v1/sso/authorize-batch', [], [], [], $headers, '{broken')->assertStatus(400);
    $this->call('POST', '/api/v1/sso/authorize-batch', [], [], [], $headers, '[]')->assertStatus(422);
});

it('defaults disabled and requires an actual trusted pool parameter', function () {
    config(['sso.batch.enabled' => false]);
    $this->postJson('/api/v1/sso/authorize-batch', $this->body, $this->headers)->assertNotFound();
    config(['sso.batch.enabled' => true]);
    $this->withServerVariables(['SSO_BATCH_POOL' => null]);
    $this->postJson('/api/v1/sso/authorize-batch', $this->body, $this->headers + ['SSO-Batch-Pool' => '1'])->assertStatus(503)->assertHeader('Retry-After', '1');
});

it('rejects other clients and inactive applications without admitting item work', function () {
    config(['sso.batch.application_uuid' => 'not-this-client']);
    $this->postJson('/api/v1/sso/authorize-batch', $this->body, $this->headers)->assertNotFound();
    config(['sso.batch.application_uuid' => $this->application->uuid]);
    $this->application->update(['is_active' => false]);
    $this->postJson('/api/v1/sso/authorize-batch', $this->body, $this->headers)->assertUnauthorized();
});

it('charges the shared HTTP quota and separately charges every batch item', function () {
    config(['sso.batch.items_per_minute' => 1]);
    $this->postJson('/api/v1/sso/authorize-batch', $this->body, $this->headers)->assertOk();
    $this->postJson('/api/v1/sso/authorize-batch', $this->body, $this->headers)->assertStatus(429)->assertHeader('Retry-After');
    $this->getJson('/api/v1/sso/offices', $this->headers)->assertOk();
    expect(AuditLog::count())->toBe(1);
    $this->application->update(['rate_limit_per_minute' => 3]);
    $this->postJson('/api/v1/sso/authorize', ['token' => $this->token], $this->headers)->assertStatus(429);
});

it('requires positive explicit work budgets and a supported shared counter store', function () {
    config(['sso.batch.items_per_minute' => 0]);
    $this->postJson('/api/v1/sso/authorize-batch', $this->body, $this->headers)->assertStatus(503)->assertHeader('Retry-After', '1');
    config(['sso.batch.items_per_minute' => 100, 'sso.capacity_store' => 'array']);
    $this->postJson('/api/v1/sso/authorize-batch', $this->body, $this->headers)->assertStatus(503);
});

it('returns no partial positives or secret diagnostics on infrastructure failures', function () {
    $this->mock(ApplicationSessionAuthorization::class)->shouldReceive('decide')->andThrow(new RuntimeException($this->token));
    Log::spy();
    $response = $this->postJson('/api/v1/sso/authorize-batch', $this->body, $this->headers)->assertStatus(503)->assertHeader('Retry-After', '1');
    expect($response->getContent())->not->toContain($this->token, 'data');
    Log::shouldHaveReceived('warning')->with('SSO batch verification unavailable.', ['exception_type' => RuntimeException::class])->once();
});

it('rejects numeric JSON objects and array rows before authorization', function () {
    $server = ['CONTENT_TYPE' => 'application/json', 'SSO_BATCH_POOL' => '1'];
    foreach (['{"items":{"0":{"id":"0","token":"invalid"}}}', '{"items":[["0","invalid"]]}'] as $body) {
        $this->call('POST', '/api/v1/sso/authorize-batch', [], [], [], $server, $body)->assertUnprocessable();
    }
    expect(AuditLog::count())->toBe(0);
});
