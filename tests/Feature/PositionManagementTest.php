<?php

use App\Enums\AppRole;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->position = Position::create(['title' => 'Original Position', 'is_active' => true]);
    $this->administrator = Employee::factory()->create(['position_id' => $this->position->id]);
    $this->adminApplication = Application::factory()->create(['name' => 'Admin App Management System']);
    $this->administrator->applications()->attach($this->adminApplication, ['role' => AppRole::SuperAdministrator->value]);
    $this->centralToken = $this->issueSsoToken($this->administrator);
});

it('creates a trimmed active position and audits permitted fields', function () {
    $response = $this->withToken($this->centralToken)->postJson('/api/v1/positions', [
        'title' => '  Planning Officer I  ', 'id' => 9999,
    ])->assertCreated()->assertJsonPath('data.title', 'Planning Officer I')->assertJsonPath('data.is_active', true);
    $position = Position::findOrFail($response->json('data.id'));
    expect($position->id)->not->toBe(9999);
    expect(AuditLog::sole()->metadata)->toBe([
        'position_id' => $position->id,
        'changes' => ['title' => 'Planning Officer I', 'is_active' => true],
    ]);
});

it('rejects invalid and duplicate titles including inactive records', function () {
    $inactive = Position::create(['title' => 'Reserved Position', 'is_active' => false]);
    foreach ([[], ['title' => ' '], ['title' => []], ['title' => str_repeat('x', 256)],
        ['title' => ' reserved POSITION '], ['title' => 'Valid Position', 'is_active' => false]] as $payload) {
        $field = array_key_exists('is_active', $payload) ? 'is_active' : 'title';
        $this->withToken($this->centralToken)->postJson('/api/v1/positions', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }
    $this->putJson('/api/v1/positions/'.$this->position->id, ['title' => $inactive->title])
        ->assertUnprocessable()->assertJsonValidationErrors('title');
    expect(Position::count())->toBe(2)->and(AuditLog::count())->toBe(0);
});

it('keeps self-service active-only while admins can inspect and update inactive positions', function () {
    $inactive = Position::create(['title' => 'Inactive Position', 'is_active' => false]);
    $this->withToken($this->centralToken)->getJson('/api/v1/positions')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/positions?include_inactive=1')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/positions/'.$inactive->id)->assertOk()->assertJsonPath('data.is_active', false);
    $this->putJson('/api/v1/positions/'.$inactive->id, ['title' => 'Updated Position'])
        ->assertOk()->assertJsonPath('data.is_active', false);
    $this->administrator->applications()->detach($this->adminApplication);
    $this->getJson('/api/v1/portal/positions?include_inactive=1')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/positions?include_inactive=1')->assertForbidden();
});

it('propagates title and active status to consumer endpoints while preserving employees', function () {
    $consumer = Application::factory()->create(['client_secret' => Hash::make('position-consumer-secret')]);
    $consumer->employees()->attach($this->administrator, ['role' => AppRole::Standard->value]);
    $headers = ['X-Client-ID' => $consumer->client_id, 'X-Client-Secret' => 'position-consumer-secret'];
    $url = '/api/v1/positions/'.$this->position->id;
    $this->withToken($this->centralToken)->putJson($url, ['title' => 'Updated Position'])
        ->assertOk()->assertJsonPath('data.id', $this->position->id);
    $directory = '/api/v1/sso/directory/'.$this->administrator->uuid.'?roles[]=standard';
    $this->getJson($directory, $headers)->assertOk()->assertJsonPath('data.position_id', $this->position->id)
        ->assertJsonPath('data.position_title', 'Updated Position');
    $consumerToken = $this->issueSsoToken($this->administrator, $consumer);
    $this->postJson('/api/v1/sso/validate', ['token' => $consumerToken], $headers)->assertOk()
        ->assertJsonPath('data.position.id', $this->position->id)
        ->assertJsonPath('data.position.title', 'Updated Position');
    foreach ([false, true] as $active) {
        $this->putJson($url, ['is_active' => $active])->assertOk()->assertJsonPath('data.is_active', $active)
            ->assertJsonPath('data.title', 'Updated Position');
        $this->getJson('/api/v1/sso/positions?search=Updated', $headers)->assertOk()->assertJsonCount($active ? 1 : 0, 'data');
        $this->getJson('/api/v1/sso/positions/'.$this->position->id, $headers)->assertOk()
            ->assertJsonPath('data.title', 'Updated Position')->assertJsonPath('data.is_active', $active);
        $this->getJson($directory, $headers)->assertOk()->assertJsonPath('data.position_id', $this->position->id)
            ->assertJsonPath('data.position_title', 'Updated Position');
        expect($this->administrator->fresh()->position_id)->toBe($this->position->id);
    }
    expect(AuditLog::where('action', 'position_updated')->orderBy('id')->get()
        ->pluck('metadata')->map(fn ($metadata) => $metadata['changes'])->all())
        ->toBe([['title' => 'Updated Position'], ['is_active' => false], ['is_active' => true]]);
});

it('denies consumer and lesser-role writes', function () {
    $consumer = Application::factory()->create();
    $consumerToken = $this->issueSsoToken($this->administrator, $consumer);
    foreach (['', $consumerToken] as $token) {
        $status = $token === '' ? 401 : 403;
        $this->withToken($token)->postJson('/api/v1/positions', ['title' => 'Denied'])->assertStatus($status);
        $this->putJson('/api/v1/positions/'.$this->position->id, ['is_active' => false])->assertStatus($status);
    }
    $this->administrator->applications()->updateExistingPivot($this->adminApplication->id, ['role' => AppRole::Administrator->value]);
    $this->withToken($this->centralToken)->postJson('/api/v1/positions', ['title' => 'Denied'])->assertForbidden();
    expect(Position::count())->toBe(1)->and(AuditLog::count())->toBe(0);
});

it('rejects malformed filters and status while preserving existing data', function () {
    $this->withToken($this->centralToken)->getJson('/api/v1/positions?include_inactive=invalid')
        ->assertUnprocessable()->assertJsonValidationErrors('include_inactive');
    $this->putJson('/api/v1/positions/'.$this->position->id, ['is_active' => 'false'])
        ->assertUnprocessable()->assertJsonValidationErrors('is_active');
    $this->putJson('/api/v1/positions/'.$this->position->id, ['title' => ' '])
        ->assertUnprocessable()->assertJsonValidationErrors('title');
    expect($this->position->fresh()->title)->toBe('Original Position')
        ->and($this->position->fresh()->is_active)->toBeTrue();
});

it('rolls back a position mutation when its audit cannot be recorded', function () {
    Event::listen('eloquent.creating: '.AuditLog::class, function (): void {
        throw new RuntimeException('Audit storage unavailable');
    });
    $this->withoutExceptionHandling();
    try {
        $this->withToken($this->centralToken)->putJson('/api/v1/positions/'.$this->position->id, ['title' => 'Should Roll Back']);
        $this->fail('The audit failure should reject the request.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Audit storage unavailable');
    }
    expect($this->position->fresh()->title)->toBe('Original Position')->and(AuditLog::count())->toBe(0);
});
