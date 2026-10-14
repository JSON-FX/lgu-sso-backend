<?php

use App\Enums\AppRole;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\OAuthToken;
use App\Models\Office;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->office = Office::factory()->create(['name' => 'Original Office', 'abbreviation' => 'ORIG']);
    $this->administrator = Employee::factory()->forOffice($this->office)->create();
    $this->adminApplication = Application::factory()->create(['name' => 'Admin App Management System']);
    $this->administrator->applications()->attach($this->adminApplication, ['role' => AppRole::SuperAdministrator->value]);
    $this->centralToken = $this->issueSsoToken($this->administrator);
});

it('creates a trimmed active office and audits only permitted fields', function () {
    $response = $this->withToken($this->centralToken)->postJson('/api/v1/offices', [
        'name' => '  New Services Office  ', 'abbreviation' => '  NSO  ', 'type' => 'ignored', 'id' => 99999,
    ])->assertCreated()->assertJsonPath('data.name', 'New Services Office')
        ->assertJsonPath('data.abbreviation', 'NSO')->assertJsonPath('data.is_active', true);
    $office = Office::findOrFail($response->json('data.id'));
    expect($office->type)->toBeNull()->and($office->id)->not->toBe(99999);
    $audit = AuditLog::sole();
    expect($audit->action)->toBe('office_created')
        ->and($audit->employee_id)->toBe($this->administrator->id)
        ->and($audit->metadata)->toBe([
            'office_id' => $office->id,
            'changes' => ['name' => 'New Services Office', 'abbreviation' => 'NSO', 'is_active' => true],
        ]);
});

it('rejects invalid new office data without a write or audit', function (array $payload, array $fields) {
    $this->withToken($this->centralToken)->postJson('/api/v1/offices', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($fields);
    expect(Office::count())->toBe(1)->and(AuditLog::count())->toBe(0);
})->with([
    'missing' => [[], ['name', 'abbreviation']],
    'blank' => [['name' => '  ', 'abbreviation' => '  '], ['name', 'abbreviation']],
    'invalid types' => [['name' => [], 'abbreviation' => 42], ['name', 'abbreviation']],
    'too long' => [['name' => str_repeat('a', 256), 'abbreviation' => str_repeat('b', 21)], ['name', 'abbreviation']],
    'status on create' => [['name' => 'New Office', 'abbreviation' => 'NEW', 'is_active' => false], ['is_active']],
]);

it('rejects case-insensitive duplicates including inactive offices for create and update', function (bool $active) {
    $other = Office::factory()->create(['name' => 'Reserved Office', 'abbreviation' => 'RES', 'is_active' => $active]);
    $payload = ['name' => ' reserved OFFICE ', 'abbreviation' => ' res '];
    $this->withToken($this->centralToken)->postJson('/api/v1/offices', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['name', 'abbreviation']);
    $this->putJson('/api/v1/offices/'.$this->office->id, [...$payload, 'id' => $other->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['name', 'abbreviation']);
    expect($this->office->fresh()->name)->toBe('Original Office')->and(AuditLog::count())->toBe(0);
})->with([true, false]);

it('accepts unchanged values for the bound office and preserves omitted status', function () {
    $this->office->update(['is_active' => false]);
    $this->withToken($this->centralToken)->putJson('/api/v1/offices/'.$this->office->id, [
        'name' => ' Original Office ', 'abbreviation' => 'ORIG',
    ])->assertOk()->assertJsonPath('data.id', $this->office->id)->assertJsonPath('data.is_active', false);
    expect(AuditLog::sole()->metadata['changes'])->toBe([]);
});

it('rejects malformed supplied update values without altering the office', function (array $payload, string $field) {
    $this->withToken($this->centralToken)->putJson('/api/v1/offices/'.$this->office->id, $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($this->office->fresh()->name)->toBe('Original Office')->and($this->office->fresh()->is_active)->toBeTrue()
        ->and(AuditLog::count())->toBe(0);
})->with([
    [['name' => ' '], 'name'], [['abbreviation' => null], 'abbreviation'],
    [['name' => str_repeat('x', 256)], 'name'], [['abbreviation' => str_repeat('x', 21)], 'abbreviation'],
    [['is_active' => null], 'is_active'], [['is_active' => 'false'], 'is_active'], [['is_active' => []], 'is_active'],
]);

it('keeps self-service lists active-only while administrators can include inactive offices', function () {
    $inactive = Office::factory()->create(['is_active' => false]);
    $this->withToken($this->centralToken)->getJson('/api/v1/offices')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/offices?include_inactive=1')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonFragment(['id' => $inactive->id, 'is_active' => false]);
    $this->getJson('/api/v1/offices/'.$inactive->id)->assertOk()->assertJsonPath('data.is_active', false);
    $this->administrator->applications()->detach($this->adminApplication);
    $this->getJson('/api/v1/portal/offices?include_inactive=1')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->office->id);
    $this->getJson('/api/v1/offices?include_inactive=1')->assertForbidden();
});

it('rejects malformed inactive-office list filters', function (mixed $value) {
    $this->withToken($this->centralToken)
        ->getJson('/api/v1/offices?'.http_build_query(['include_inactive' => $value]))
        ->assertUnprocessable()->assertJsonValidationErrors('include_inactive');
})->with([
    'array' => [['1']],
    'invalid string' => ['invalid'],
]);

it('propagates names abbreviations and status to consumer reads while preserving employee relationships', function () {
    $consumer = Application::factory()->create(['client_secret' => Hash::make('office-consumer-secret')]);
    $consumer->employees()->attach($this->administrator, ['role' => AppRole::Standard->value]);
    $headers = ['X-Client-ID' => $consumer->client_id, 'X-Client-Secret' => 'office-consumer-secret'];
    $url = '/api/v1/offices/'.$this->office->id;
    $this->withToken($this->centralToken)->putJson($url, ['name' => 'Updated Office', 'abbreviation' => 'UPD'])
        ->assertOk()->assertJsonPath('data.id', $this->office->id);
    $this->getJson('/api/v1/sso/offices?search=UPD', $headers)->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Updated Office')->assertJsonPath('data.0.abbreviation', 'UPD');
    $directory = '/api/v1/sso/directory/'.$this->administrator->uuid.'?roles[]=standard';
    $this->getJson($directory, $headers)->assertOk()->assertJsonPath('data.office_name', 'Updated Office')
        ->assertJsonPath('data.office_abbreviation', 'UPD');
    $consumerToken = $this->issueSsoToken($this->administrator, $consumer);
    $this->postJson('/api/v1/sso/validate', ['token' => $consumerToken], $headers)->assertOk()
        ->assertJsonPath('data.office.name', 'Updated Office')->assertJsonPath('data.office.abbreviation', 'UPD');

    foreach ([false, true] as $active) {
        $this->putJson($url, ['is_active' => $active])->assertOk()->assertJsonPath('data.is_active', $active)
            ->assertJsonPath('data.name', 'Updated Office')->assertJsonPath('data.abbreviation', 'UPD');
        $this->getJson('/api/v1/sso/offices?search=UPD', $headers)->assertOk()->assertJsonCount($active ? 1 : 0, 'data');
        $this->getJson('/api/v1/sso/offices/'.$this->office->id, $headers)->assertOk()
            ->assertJsonPath('data.is_active', $active)->assertJsonPath('data.name', 'Updated Office');
        $this->getJson($directory, $headers)->assertOk()->assertJsonPath('data.office_id', $this->office->id)
            ->assertJsonPath('data.office_abbreviation', 'UPD');
        expect($this->administrator->fresh()->office_id)->toBe($this->office->id);
    }
    $audits = AuditLog::where('action', 'office_updated')->orderBy('id')->get();
    expect($audits)->toHaveCount(3)
        ->and($audits[0]->metadata['changes'])->toBe(['name' => 'Updated Office', 'abbreviation' => 'UPD'])
        ->and($audits[1]->metadata['changes'])->toBe(['is_active' => false])
        ->and($audits[2]->metadata['changes'])->toBe(['is_active' => true]);
});

it('denies office writes when the central administrator boundary is not satisfied', function (string $scenario, int $status) {
    $token = $this->centralToken;
    switch ($scenario) {
        case 'no token': $token = '';
            break;
        case 'invalid token': $token = 'invalid';
            break;
        case 'unregistered token': $token = JWTAuth::fromUser($this->administrator);
            break;
        case 'revoked token': OAuthToken::query()->update(['revoked_at' => now()]);
            break;
        case 'consumer token': $token = $this->issueSsoToken($this->administrator, $this->adminApplication);
            break;
        case 'setup required':
            $this->administrator->update(['must_change_password' => true]);
            $token = $this->issueSsoToken($this->administrator);
            break;
        case 'inactive employee': $this->administrator->update(['is_active' => false]);
            break;
        case 'inactive application': $this->adminApplication->update(['is_active' => false]);
            break;
        case 'deleted application': $this->adminApplication->delete();
            break;
        case 'revoked grant': $this->administrator->applications()->detach($this->adminApplication);
            break;
        case 'lesser role': $this->administrator->applications()->updateExistingPivot($this->adminApplication->id, ['role' => AppRole::Administrator->value]);
            break;
        case 'other app admin':
            $this->administrator->applications()->detach($this->adminApplication);
            $other = Application::factory()->create();
            $this->administrator->applications()->attach($other, ['role' => AppRole::SuperAdministrator->value]);
            break;
    }
    app('auth')->forgetGuards();
    foreach ([['POST', '/api/v1/offices'], ['PUT', '/api/v1/offices/'.$this->office->id]] as [$method, $url]) {
        $this->withToken($token)->json($method, $url, ['name' => 'Unauthorized Office', 'abbreviation' => 'BAD'])->assertStatus($status);
    }
    expect(Office::count())->toBe(1)->and($this->office->fresh()->name)->toBe('Original Office')->and(AuditLog::count())->toBe(0);
})->with([
    ['no token', 401], ['invalid token', 401], ['unregistered token', 401], ['revoked token', 401],
    ['consumer token', 403], ['setup required', 403], ['inactive employee', 401],
    ['inactive application', 403], ['deleted application', 403], ['revoked grant', 403],
    ['lesser role', 403], ['other app admin', 403],
]);

it('does not accept application credentials alone as administration credentials', function () {
    $consumer = Application::factory()->create(['client_secret' => Hash::make('office-consumer-secret')]);
    $headers = ['X-Client-ID' => $consumer->client_id, 'X-Client-Secret' => 'office-consumer-secret'];
    $this->postJson('/api/v1/offices', ['name' => 'Forbidden', 'abbreviation' => 'BAD'], $headers)->assertUnauthorized();
    $this->putJson('/api/v1/offices/'.$this->office->id, ['is_active' => false], $headers)->assertUnauthorized();
});

it('returns not found for absent office IDs', function () {
    $this->withToken($this->centralToken)->putJson('/api/v1/offices/999999', ['is_active' => false])->assertNotFound();
    $this->getJson('/api/v1/offices/999999')->assertNotFound();
    expect(AuditLog::count())->toBe(0);
});

it('rolls back office mutations when audit persistence fails', function (string $method) {
    Event::listen('eloquent.creating: '.AuditLog::class, function (): void {
        throw new RuntimeException('Audit storage unavailable');
    });
    $this->withoutExceptionHandling();
    $url = '/api/v1/offices'.($method === 'PUT' ? '/'.$this->office->id : '');
    try {
        $this->withToken($this->centralToken)->json($method, $url, ['name' => 'Should Roll Back', 'abbreviation' => 'ROLL']);
        $this->fail('The audit failure should reject the request.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Audit storage unavailable');
    }
    expect(Office::count())->toBe(1)->and($this->office->fresh()->name)->toBe('Original Office')->and(AuditLog::count())->toBe(0);
})->with(['POST', 'PUT']);
