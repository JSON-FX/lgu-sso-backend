<?php

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('avatars');
    $this->employee = Employee::factory()->create();
    $this->token = $this->issueSsoToken($this->employee);
    $this->url = '/api/v1/portal/profile/avatar';
});

it('rewrites private photos, exposes metadata, replaces safely and removes them', function () {
    $first = $this->withToken($this->token)->postJson($this->url, ['photo' => UploadedFile::fake()->image('photo.png', 600, 300)])
        ->assertOk()->json('data.avatar_version');
    expect($first)->toBeString();
    $oldPath = $this->employee->fresh()->avatar_path;
    $image = $this->get($this->url)->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($image->headers->get('Cache-Control'))->toContain('private', 'no-store');
    expect(getimagesizefromstring($image->getContent()))->toMatchArray([0 => 512, 1 => 512, 2 => IMAGETYPE_WEBP]);
    $this->postJson($this->url, ['photo' => UploadedFile::fake()->image('new.jpg', 256, 256)])
        ->assertOk()->assertJsonMissing(['avatar_version' => $first]);
    Storage::disk('avatars')->assertMissing($oldPath);
    expect($this->employee->fresh()->toArray())->not->toHaveKey('avatar_path');
    $this->deleteJson($this->url)->assertOk()->assertJsonPath('data.avatar_version', null)->assertJsonPath('data.avatar_endpoint', null);
    $this->getJson($this->url)->assertNotFound();
    expect(Storage::disk('avatars')->allFiles())->toBe([])->and(AuditLog::count())->toBe(3);
});

it('rejects invalid uploads without changing the existing photo', function (string $kind) {
    $this->withToken($this->token)->postJson($this->url, ['photo' => UploadedFile::fake()->image('valid.png', 256, 256)])->assertOk();
    $version = $this->employee->fresh()->avatar_version;
    $file = match ($kind) {
        'small' => UploadedFile::fake()->image('small.png', 255, 256),
        'large dimensions' => UploadedFile::fake()->image('wide.png', 4097, 256),
        'oversize' => UploadedFile::fake()->image('large.png', 256, 256)->size(5121),
        'svg' => UploadedFile::fake()->createWithContent('photo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
        'corrupt' => UploadedFile::fake()->createWithContent('photo.jpg', 'not an image'),
    };
    $this->postJson($this->url, ['photo' => $file])->assertUnprocessable()->assertJsonValidationErrors('photo');
    expect($this->employee->fresh()->avatar_version)->toBe($version)->and(Storage::disk('avatars')->allFiles())->toHaveCount(1);
})->with(['small', 'large dimensions', 'oversize', 'svg', 'corrupt']);

it('allows only current central super administrators to manage another employee photo', function () {
    $subject = Employee::factory()->create();
    $url = '/api/v1/employees/'.$subject->uuid.'/avatar';
    $this->withToken($this->token)->postJson($url, ['photo' => UploadedFile::fake()->image('photo.png', 256, 256)])->assertForbidden();
    $admin = Application::factory()->create(['name' => 'Admin App Management System']);
    $this->employee->applications()->attach($admin, ['role' => 'super_administrator']);
    $this->postJson($url, ['photo' => UploadedFile::fake()->image('photo.png', 256, 256)])->assertOk();
    expect($subject->fresh()->avatar_version)->not->toBeNull()->and($this->employee->fresh()->avatar_version)->toBeNull();
    $this->get($url)->assertOk();
    $this->deleteJson($url)->assertOk()->assertJsonPath('data.avatar_version', null);
    $this->getJson($url)->assertNotFound();
    $this->postJson($url, ['photo' => UploadedFile::fake()->image('replacement.png', 256, 256)])->assertOk();
    $this->employee->applications()->detach($admin);
    $this->deleteJson($url)->assertForbidden();
    expect($subject->fresh()->avatar_version)->not->toBeNull();
});

it('returns not found for an unknown employee photo instead of a server error', function () {
    $url = '/api/v1/employees/'.\Illuminate\Support\Str::uuid().'/avatar';
    $this->asSsoAdmin($this->employee)->getJson($url)->assertNotFound();
    $this->postJson($url, ['photo' => UploadedFile::fake()->image('photo.png', 256, 256)])->assertNotFound();
    $this->deleteJson($url)->assertNotFound();
    expect(Storage::disk('avatars')->allFiles())->toBe([]);
});

it('rejects consumer tokens and revoked or setup-required central tokens', function (string $kind) {
    $app = Application::factory()->create();
    $this->employee->applications()->attach($app, ['role' => 'super_administrator']);
    $token = $kind === 'consumer' ? $this->issueSsoToken($this->employee, $app) : $this->token;
    if ($kind === 'revoked') {
        $this->employee->revokeSessions();
    }
    if ($kind === 'setup') {
        $this->employee->update(['must_change_password' => true]);
        $token = $this->issueSsoToken($this->employee);
    }
    $this->withToken($token)->postJson($this->url, ['photo' => UploadedFile::fake()->image('photo.png', 256, 256)])->assertStatus($kind === 'revoked' ? 401 : 403);
    expect(Storage::disk('avatars')->allFiles())->toBe([]);
})->with(['consumer', 'revoked', 'setup']);

it('shares the same bytes only with eligible credential-scoped directory consumers', function () {
    $this->withToken($this->token)->postJson($this->url, ['photo' => UploadedFile::fake()->image('photo.png', 256, 256)])->assertOk();
    $bytes = $this->get($this->url)->getContent();
    $app = Application::factory()->create(['client_secret' => Hash::make('test-secret')]);
    $headers = ['X-Client-ID' => $app->client_id, 'X-Client-Secret' => 'test-secret'];
    $url = '/api/v1/sso/directory/'.$this->employee->uuid.'/avatar?roles[]=standard';
    $this->getJson($url)->assertUnauthorized();
    $this->getJson($url, $headers)->assertNotFound();
    $this->employee->applications()->attach($app, ['role' => 'standard']);
    expect($this->get($url, $headers)->assertOk()->getContent())->toBe($bytes);
    $this->getJson('/api/v1/sso/directory/'.$this->employee->uuid.'?roles[]=standard', $headers)->assertOk()
        ->assertJsonPath('data.avatar_version', $this->employee->fresh()->avatar_version);
    $this->employee->applications()->detach($app);
    $this->getJson($url, $headers)->assertNotFound();
    $this->employee->applications()->attach($app, ['role' => 'guest']);
    $this->getJson($url, $headers)->assertNotFound();
    $app->update(['is_active' => false]);
    $this->getJson($url, $headers)->assertUnauthorized();
});

it('retains the old photo and removes the new file if the database transaction fails', function () {
    $this->withToken($this->token)->postJson($this->url, ['photo' => UploadedFile::fake()->image('first.png', 256, 256)])->assertOk();
    $old = $this->employee->fresh();
    \Illuminate\Support\Facades\Event::listen('eloquent.updating: '.Employee::class, fn () => throw new RuntimeException('Simulated database failure'));
    $this->postJson($this->url, ['photo' => UploadedFile::fake()->image('replacement.png', 256, 256)])->assertStatus(503);
    expect($this->employee->fresh()->avatar_version)->toBe($old->avatar_version)
        ->and(Storage::disk('avatars')->allFiles())->toBe([$old->avatar_path]);
});

it('denies private photo delivery after employee eligibility changes', function (string $change) {
    $this->withToken($this->token)->postJson($this->url, ['photo' => UploadedFile::fake()->image('first.png', 256, 256)])->assertOk();
    $app = Application::factory()->create(['client_secret' => Hash::make('test-secret')]);
    $this->employee->applications()->attach($app, ['role' => 'standard']);
    match ($change) {
        'inactive' => $this->employee->update(['is_active' => false]),
        'setup' => $this->employee->update(['must_change_password' => true]),
        'deleted' => $this->employee->delete(),
    };
    $this->getJson('/api/v1/sso/directory/'.$this->employee->uuid.'/avatar?roles[]=standard', [
        'X-Client-ID' => $app->client_id, 'X-Client-Secret' => 'test-secret',
    ])->assertNotFound();
})->with(['inactive', 'setup', 'deleted']);
