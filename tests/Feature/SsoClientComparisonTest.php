<?php

use App\Models\Application;
use App\Models\Employee;
use App\Models\OAuthToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->secret = str_repeat('a', 40);
    $this->application = Application::factory()->create(['client_secret' => Hash::make($this->secret)]);
    $this->headers = ['X-Client-ID' => $this->application->client_id, 'X-Client-Secret' => $this->secret];
    config(['sso.client_comparison.enabled' => true, 'sso.client_comparison.application_uuid' => $this->application->uuid,
        'sso.client_comparison.cold_per_minute' => 20, 'sso.client_comparison.global_cold_per_minute' => 40]);
});

it('memoizes only the successful secret comparison without storing secret-derived plaintext', function () {
    $hasher = Hash::driver();
    Hash::shouldReceive('check')->once()->with($this->secret, $this->application->client_secret)->andReturnUsing($hasher->check(...));
    $this->getJson('/api/v1/sso/offices', $this->headers)->assertOk();
    $this->getJson('/api/v1/sso/offices', $this->headers)->assertOk();
    $rows = DB::table('cache')->get()->toJson();
    expect($rows)->not->toContain($this->secret, $this->application->client_secret, hash('sha256', $this->secret));
});

it('does not cache token authorization role or revocation decisions', function () {
    $employee = Employee::factory()->create();
    $employee->applications()->attach($this->application, ['role' => 'standard']);
    $token = $this->issueSsoToken($employee, $this->application);
    $hasher = Hash::driver();
    Hash::shouldReceive('check')->once()->andReturnUsing($hasher->check(...));
    $this->postJson('/api/v1/sso/authorize', ['token' => $token], $this->headers)->assertOk()->assertJsonPath('role', 'standard');
    $employee->applications()->updateExistingPivot($this->application->id, ['role' => 'guest']);
    $this->postJson('/api/v1/sso/authorize', ['token' => $token], $this->headers)->assertOk()->assertJsonPath('role', 'guest');
    OAuthToken::where('access_token', hash('sha256', $token))->update(['revoked_at' => now()]);
    $this->postJson('/api/v1/sso/authorize', ['token' => $token], $this->headers)->assertUnauthorized();
});

it('observes secret rotation immediately even while an old success remains cached', function () {
    $this->getJson('/api/v1/sso/offices', $this->headers)->assertOk();
    $newSecret = str_repeat('b', 40);
    $this->application->update(['client_secret' => Hash::make($newSecret)]);
    $this->getJson('/api/v1/sso/offices', $this->headers)->assertUnauthorized();
    $this->getJson('/api/v1/sso/offices', array_replace($this->headers, ['X-Client-Secret' => $newSecret]))->assertOk();
});

it('observes application deactivation and deletion despite a warm comparison', function (string $mutation) {
    $this->getJson('/api/v1/sso/offices', $this->headers)->assertOk();
    if ($mutation === 'inactive') {
        $this->application->update(['is_active' => false]);
    } else {
        $this->application->delete();
    }
    $this->getJson('/api/v1/sso/offices', $this->headers)->assertUnauthorized();
})->with(['inactive', 'deleted']);

it('isolates applications and exact supplied secrets', function () {
    $this->getJson('/api/v1/sso/offices', $this->headers)->assertOk();
    $other = Application::factory()->create(['client_secret' => Hash::make(str_repeat('b', 40))]);
    $this->getJson('/api/v1/sso/offices', array_replace($this->headers, ['X-Client-ID' => $other->client_id]))->assertUnauthorized();
    $this->getJson('/api/v1/sso/offices', array_replace($this->headers, ['X-Client-Secret' => str_repeat('b', 40)]))->assertUnauthorized();
});

it('rechecks after TTL expiry and server-key rotation', function () {
    $hasher = Hash::driver();
    Hash::shouldReceive('check')->times(3)->andReturnUsing($hasher->check(...));
    $this->getJson('/api/v1/sso/offices', $this->headers)->assertOk();
    $this->travel(61)->seconds();
    $this->getJson('/api/v1/sso/offices', $this->headers)->assertOk();
    config(['app.key' => 'base64:'.base64_encode(str_repeat('z', 32))]);
    $this->getJson('/api/v1/sso/offices', $this->headers)->assertOk();
});

it('charges wrong-secret cold comparisons but does not cache their results', function () {
    config(['sso.client_comparison.cold_per_minute' => 2]);
    $hasher = Hash::driver();
    Hash::shouldReceive('check')->twice()->andReturnUsing($hasher->check(...));
    $wrong = array_replace($this->headers, ['X-Client-Secret' => 'wrong-secret']);
    $this->getJson('/api/v1/sso/offices', $wrong)->assertUnauthorized();
    $this->getJson('/api/v1/sso/offices', $wrong)->assertUnauthorized();
    $this->getJson('/api/v1/sso/offices', $wrong)->assertStatus(429)->assertHeader('Retry-After');
    expect(DB::table('cache')->where('key', 'like', '%sso-client:%')->count())->toBe(0);
});

it('fails closed when the capacity store or HMAC key is unavailable', function () {
    config(['sso.capacity_store' => 'array']);
    $this->getJson('/api/v1/sso/offices', $this->headers)->assertStatus(503);
    config(['sso.capacity_store' => 'database', 'app.key' => '']);
    $this->getJson('/api/v1/sso/offices', $this->headers)->assertStatus(503);
});

it('rejects oversized and control-bearing secrets before comparison work', function () {
    Hash::partialMock()->shouldNotReceive('check');
    foreach ([str_repeat('a', 73), "abc\0def"] as $secret) {
        $this->getJson('/api/v1/sso/offices', array_replace($this->headers, ['X-Client-Secret' => $secret]))->assertUnauthorized();
    }
});

it('supports nonrandom CLI credentials without publishing an offline guessing oracle', function () {
    $secret = str_repeat('x', 72);
    $this->application->update(['client_secret' => Hash::make($secret)]);
    $headers = array_replace($this->headers, ['X-Client-Secret' => $secret]);
    $this->getJson('/api/v1/sso/offices', $headers)->assertOk();
    $this->getJson('/api/v1/sso/offices', $headers)->assertOk();
    expect(DB::table('cache')->get()->toJson())->not->toContain($secret, hash('sha256', $secret));
});
