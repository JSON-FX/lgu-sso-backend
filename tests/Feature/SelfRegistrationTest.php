<?php

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Position;
use App\Services\EmployeeCreation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->connect = Application::factory()->create(['name' => 'LGU Connect']);
    $this->opts = Application::factory()->create(['name' => 'OPTS']);
    $this->adminApp = Application::factory()->create(['name' => 'Admin App Management System']);
    $this->office = Office::factory()->create(['is_active' => true]);
    $this->position = Position::create(['title' => 'Accountant', 'is_active' => true]);
    $this->payload = [
        'first_name' => 'Maria', 'last_name' => 'Santos', 'email' => 'maria@example.test',
        'birthday' => '1995-11-04', 'civil_status' => 'single', 'nationality' => 'Filipino',
        'region' => 'Region X', 'province' => 'Bukidnon', 'city' => 'Quezon', 'barangay' => 'Poblacion',
        'password' => 'Personal-pass123', 'password_confirmation' => 'Personal-pass123',
        'office_id' => $this->office->id, 'position_id' => $this->position->id,
        'applications' => [$this->connect->uuid, $this->opts->uuid],
    ];
});

it('publishes only public active application and reference fields', function () {
    Application::factory()->create(['name' => 'Inactive', 'is_active' => false]);
    $deleted = Application::factory()->create(['name' => 'Deleted']);
    $deleted->delete();
    Office::factory()->create(['is_active' => false]);
    Position::create(['title' => 'Retired', 'is_active' => false]);
    $response = $this->getJson('/api/v1/auth/registration-options')->assertOk()
        ->assertJsonCount(2, 'data.applications')->assertJsonCount(1, 'data.offices')->assertJsonCount(1, 'data.positions')
        ->assertJsonPath('data.applications.0.default_role', 'standard')->assertJsonPath('data.applications.1.default_role', 'guest');
    expect(array_keys($response->json('data.applications.0')))->toBe(['uuid', 'name', 'description', 'default_role']);
    expect($response->getContent())->not->toContain($this->connect->client_id, 'client_secret', 'redirect_uris', 'Admin App Management System');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('creates an account with default grants and accepts its personal password at login', function () {
    $response = $this->postJson('/api/v1/auth/register', $this->payload)->assertCreated()
        ->assertJsonPath('username', 'm.santos')->assertJsonCount(2, 'applications');
    $employee = Employee::where('username', 'm.santos')->firstOrFail();
    expect($employee->getRoleFor($this->connect)->value)->toBe('standard');
    expect($employee->getRoleFor($this->opts)->value)->toBe('guest');
    expect($employee->hasAccessTo($this->adminApp))->toBeFalse();
    expect($employee->must_change_password)->toBeFalse();
    expect($employee->is_active)->toBeTrue();
    expect(Hash::check($this->payload['password'], $employee->password))->toBeTrue();
    expect($employee->tokens()->count())->toBe(0);
    expect($response->getContent())->not->toContain($this->payload['password'], 'access_token', 'client_secret', 'client_id');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $audit = AuditLog::where('action', 'self_registered')->firstOrFail();
    expect(json_encode($audit->metadata))->not->toContain('password', $this->payload['password']);
    $login = $this->postJson('/api/v1/auth/login', ['username' => 'm.santos', 'password' => $this->payload['password']])->assertOk()->assertJsonPath('employee.must_change_password', false);
    app('auth')->forgetGuards();
    app('tymon.jwt')->unsetToken();
    \Tymon\JWTAuth\Facades\JWTAuth::unsetToken();
    $this->withToken($login->json('access_token'))->getJson('/api/v1/employees')->assertForbidden();
});

it('allows registration without an application and reserves deleted usernames', function () {
    $existing = Employee::factory()->create(['username' => 'm.santos']);
    $existing->delete();
    $this->payload['applications'] = [];
    $response = $this->postJson('/api/v1/auth/register', $this->payload)->assertCreated()->assertJsonPath('username', 'm.santos2');
    expect(Employee::where('username', $response->json('username'))->firstOrFail()->applications()->count())->toBe(0);
});

it('rejects privileged fields and malformed or duplicate application selections', function (string $field, mixed $value) {
    $this->payload[$field] = $value;
    $this->postJson('/api/v1/auth/register', $this->payload)->assertUnprocessable();
    expect(Employee::count())->toBe(0);
    expect(DB::table('employee_application')->count())->toBe(0);
})->with([
    ['role', 'super_administrator'], ['roles', ['administrator']], ['username', 'j.alanano'],
    ['is_active', false], ['must_change_password', false], ['uuid', '123'],
    ['date_employed', '2026-01-01'], ['applications', [['uuid' => '123', 'role' => 'administrator']]],
    ['applications', ['not-a-uuid']],
]);

it('rejects unavailable or internal applications atomically', function (string $state) {
    $app = match ($state) {
        'internal' => $this->adminApp,
        default => Application::factory()->create(),
    };
    if ($state === 'inactive') {
        $app->update(['is_active' => false]);
    }
    if ($state === 'deleted') {
        $app->delete();
    }
    $this->payload['applications'] = [$this->connect->uuid, $app->uuid];
    $this->postJson('/api/v1/auth/register', $this->payload)->assertUnprocessable()->assertJsonValidationErrors('applications');
    expect(Employee::count())->toBe(0);
})->with(['internal', 'inactive', 'deleted']);

it('rejects duplicate selected applications and unavailable reference records', function () {
    $this->payload['applications'] = [$this->connect->uuid, $this->connect->uuid];
    $this->postJson('/api/v1/auth/register', $this->payload)->assertUnprocessable();
    $this->payload['applications'] = [];
    $this->office->update(['is_active' => false]);
    $this->position->update(['is_active' => false]);
    $this->postJson('/api/v1/auth/register', $this->payload)->assertUnprocessable()->assertJsonValidationErrors(['office_id', 'position_id']);
});

it('does not claim or change an existing account with the same email', function () {
    $existing = Employee::factory()->create(['email' => $this->payload['email'], 'password' => 'existing-password']);
    $this->postJson('/api/v1/auth/register', $this->payload)->assertUnprocessable()->assertJsonValidationErrors('email')
        ->assertJsonPath('message', EmployeeCreation::ACCOUNT_EXISTS);
    expect(Employee::count())->toBe(1);
    expect(Hash::check('existing-password', $existing->fresh()->password))->toBeTrue();
    expect($existing->applications()->count())->toBe(0);
});

it('formats personal names and nationality while preserving credentials and other inputs', function () {
    $this->payload = [...$this->payload,
        'first_name' => '  MARÍA   ANA-MAY ', 'middle_name' => 'DELA CRUZ',
        'last_name' => 'O’NEILL', 'nationality' => 'fILIPINO', 'suffix' => 'III',
        'email' => 'PERSONAL@example.test', 'password' => 'KeEp-CASE123',
        'password_confirmation' => 'KeEp-CASE123', 'residence' => 'BLK ABC ROAD',
    ];
    $this->postJson('/api/v1/auth/register', $this->payload)->assertCreated();
    $employee = Employee::where('email', 'PERSONAL@example.test')->firstOrFail();
    expect($employee->first_name)->toBe('María Ana-May')
        ->and($employee->middle_name)->toBe('Dela Cruz')->and($employee->last_name)->toBe('O’Neill')
        ->and($employee->nationality)->toBe('Filipino')->and($employee->suffix)->toBe('III')
        ->and($employee->residence)->toBe('BLK ABC ROAD')
        ->and(Hash::check('KeEp-CASE123', $employee->password))->toBeTrue();
});

it('preserves validation for invalid personal detail types', function (string $field) {
    $this->payload[$field] = ['INVALID'];
    $this->postJson('/api/v1/auth/register', $this->payload)->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(Employee::count())->toBe(0);
})->with(['first_name', 'middle_name', 'last_name', 'nationality']);

it('rejects an existing person using another email without changing their account', function (string $state) {
    $existing = Employee::factory()->create([
        'first_name' => 'Maria', 'middle_name' => 'Existing', 'last_name' => 'Santos',
        'birthday' => $this->payload['birthday'], 'email' => 'm.santos@lgu.gov.ph',
        'username' => 'm.santos', 'password' => 'Existing-secret123',
        'is_active' => $state !== 'inactive',
    ]);
    $existing->applications()->attach($this->opts, ['role' => 'administrator']);
    if ($state === 'deleted') {
        $existing->delete();
    }
    $before = $existing->fresh()->getAttributes();
    $auditCount = AuditLog::count();

    $this->postJson('/api/v1/auth/register', $this->payload)->assertUnprocessable()
        ->assertJsonValidationErrors('account')->assertJsonPath('message', EmployeeCreation::ACCOUNT_EXISTS);

    expect(Employee::withTrashed()->count())->toBe(1)
        ->and(Employee::withTrashed()->findOrFail($existing->id)->getAttributes())->toBe($before)
        ->and($existing->applications()->count())->toBe(1)
        ->and($existing->getRoleFor($this->opts)->value)->toBe('administrator')
        ->and(AuditLog::count())->toBe($auditCount);
})->with(['active', 'inactive', 'deleted']);

it('matches normalized names and birthday even if optional names are omitted', function () {
    Employee::factory()->create([
        'first_name' => "  MARIA\t TERESA  ", 'last_name' => " DEL\u{00A0} ROSARIO ",
        'middle_name' => 'Cruz', 'suffix' => 'Jr.', 'birthday' => $this->payload['birthday'],
    ]);
    $this->payload['first_name'] = 'maria teresa';
    $this->payload['last_name'] = 'del rosario';
    $this->postJson('/api/v1/auth/register', $this->payload)->assertUnprocessable()
        ->assertJsonPath('message', EmployeeCreation::ACCOUNT_EXISTS);
    expect(Employee::count())->toBe(1);
});

it('allows distinct people who share a generated username pattern', function (string $firstName, string $birthday) {
    Employee::factory()->create([
        'first_name' => $firstName, 'last_name' => 'Santos', 'birthday' => $birthday, 'username' => 'm.santos',
    ]);
    $this->postJson('/api/v1/auth/register', $this->payload)->assertCreated()->assertJsonPath('username', 'm.santos2');
    expect(Employee::count())->toBe(2);
})->with([['Miguel', '1995-11-04'], ['Maria', '1994-11-04']]);

it('rejects repeat registration with another email after the first registration succeeds', function () {
    $this->payload['applications'] = [];
    $this->postJson('/api/v1/auth/register', $this->payload)->assertCreated();
    $this->payload['email'] = 'another@example.test';
    $this->postJson('/api/v1/auth/register', $this->payload)->assertUnprocessable()
        ->assertJsonPath('message', EmployeeCreation::ACCOUNT_EXISTS);
    expect(Employee::count())->toBe(1)
        ->and(AuditLog::where('action', 'self_registered')->count())->toBe(1);
});

it('also prevents a super administrator from creating an existing identity', function () {
    $admin = Employee::factory()->create();
    Employee::factory()->create([
        'first_name' => 'Maria', 'last_name' => 'Santos', 'birthday' => $this->payload['birthday'],
    ]);
    $payload = \Illuminate\Support\Arr::except($this->payload, ['password', 'password_confirmation', 'applications']);
    $this->asSsoAdmin($admin)->postJson('/api/v1/employees', $payload)->assertUnprocessable()
        ->assertJsonPath('message', EmployeeCreation::ACCOUNT_EXISTS);
    expect(Employee::count())->toBe(2);
});

it('validates required details and password confirmation', function () {
    $this->payload['password_confirmation'] = 'different';
    unset($this->payload['barangay'], $this->payload['email']);
    $this->postJson('/api/v1/auth/register', $this->payload)->assertUnprocessable()->assertJsonValidationErrors(['password', 'barangay', 'email']);
});

it('rolls back employee and grants when audit fails', function () {
    AuditLog::creating(fn () => throw new RuntimeException('Audit unavailable'));
    try {
        $this->postJson('/api/v1/auth/register', $this->payload)->assertStatus(500);
        expect(Employee::count())->toBe(0);
        expect(DB::table('employee_application')->count())->toBe(0);
    } finally {
        AuditLog::flushEventListeners();
        AuditLog::clearBootedModels();
    }
});

it('limits repeated public signup attempts', function () {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/v1/auth/register', [])->assertUnprocessable();
    }
    $this->postJson('/api/v1/auth/register', $this->payload)->assertStatus(429);
    expect(Employee::count())->toBe(0);
});

it('handles long and accented names without exceeding username storage', function () {
    $this->payload['first_name'] = 'Élise';
    $this->payload['last_name'] = str_repeat('ñ', 255);
    $username = $this->postJson('/api/v1/auth/register', $this->payload)->assertCreated()->json('username');
    expect(mb_strlen($username))->toBeLessThanOrEqual(255);
    expect(mb_check_encoding($username, 'UTF-8'))->toBeTrue();
});

it('rejects passwords beyond the bcrypt byte limit', function () {
    $this->payload['password'] = str_repeat('é', 40).'123';
    $this->payload['password_confirmation'] = $this->payload['password'];
    $this->postJson('/api/v1/auth/register', $this->payload)->assertUnprocessable()->assertJsonValidationErrors('password');
});

it('treats hash-shaped passwords as plaintext chosen by the registrant', function () {
    $chosen = Hash::make('1');
    $this->payload['password'] = $chosen;
    $this->payload['password_confirmation'] = $chosen;
    $username = $this->postJson('/api/v1/auth/register', $this->payload)->assertCreated()->json('username');
    $employee = Employee::where('username', $username)->firstOrFail();
    expect($employee->password)->not->toBe($chosen);
    expect(Hash::check($chosen, $employee->password))->toBeTrue();
    $this->postJson('/api/v1/auth/login', ['username' => $username, 'password' => '1'])->assertUnauthorized();
    $this->postJson('/api/v1/auth/login', ['username' => $username, 'password' => $chosen])->assertOk();
});
