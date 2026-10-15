<?php

use App\Enums\AppRole;
use App\Models\Application;
use App\Models\Employee;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Employee::factory()->create();
});

it('can list employees', function () {
    Employee::factory()->count(3)->create();

    $response = $this->asSsoAdmin($this->admin)
        ->getJson('/api/v1/employees');

    $response->assertSuccessful()
        ->assertJsonCount(4, 'data');
});

it('searches employee names and email addresses', function () {
    $employee = Employee::factory()->create([
        'first_name' => 'Ada',
        'middle_name' => null,
        'last_name' => 'Lovelace',
        'email' => 'ada.lovelace@example.com',
    ]);
    Employee::factory()->create([
        'first_name' => 'Grace',
        'last_name' => 'Hopper',
        'email' => 'grace.hopper@example.com',
    ]);

    $this->asSsoAdmin($this->admin)
        ->getJson('/api/v1/employees?search=Ada%20Lovelace')
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.uuid', $employee->uuid);

    $this->asSsoAdmin($this->admin)
        ->getJson('/api/v1/employees?search=ada.lovelace%40example.com')
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.uuid', $employee->uuid);
});

it('filters employees by active status and paginates the filtered count', function () {
    Employee::factory()->create(['first_name' => 'Taylor', 'is_active' => true]);
    $inactive = Employee::factory()->count(5)->inactive()->create(['first_name' => 'Taylor']);
    Employee::factory()->inactive()->create(['first_name' => 'Morgan']);

    $this->asSsoAdmin($this->admin)
        ->getJson('/api/v1/employees?search=Taylor&status=active')
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonCount(1, 'data');

    $response = $this->asSsoAdmin($this->admin)
        ->getJson('/api/v1/employees?search=Taylor&status=inactive&per_page=2&page=2');

    $response->assertSuccessful()
        ->assertJsonPath('meta.total', 5)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.last_page', 3)
        ->assertJsonPath('meta.from', 3)
        ->assertJsonPath('meta.to', 4)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.uuid', $inactive[2]->uuid)
        ->assertJsonPath('data.1.uuid', $inactive[3]->uuid);
});

it('rejects an unsupported employee status or page size', function () {
    $this->asSsoAdmin($this->admin)
        ->getJson('/api/v1/employees?status=archived')
        ->assertUnprocessable();

    $this->asSsoAdmin($this->admin)
        ->getJson('/api/v1/employees?per_page=0')
        ->assertUnprocessable();
});

it('includes application access in the employee list', function () {
    $application = Application::factory()->create();
    $this->admin->applications()->attach($application->id, ['role' => AppRole::Standard->value]);

    $this->asSsoAdmin($this->admin)
        ->getJson('/api/v1/employees')
        ->assertSuccessful()
        ->assertJsonFragment(['uuid' => $application->uuid, 'role' => AppRole::Standard->value]);
});

it('can create an employee', function () {
    $position = Position::create(['title' => 'Software Developer']);

    $response = $this->asSsoAdmin($this->admin)
        ->postJson('/api/v1/employees', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'birthday' => '1990-01-15',
            'civil_status' => 'single',
            'region' => 'Northern Mindanao',
            'province' => 'Bukidnon',
            'city' => 'Quezon',
            'barangay' => 'Poblacion',
            'residence' => '123 Test Street',
            'nationality' => 'Filipino',
            'email' => 'john.doe@example.com',
            'position_id' => $position->id,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.first_name', 'John')
        ->assertJsonPath('data.email', 'john.doe@example.com');
});

it('creates an employee with geographic location and no street details', function () {
    $this->asSsoAdmin($this->admin)->postJson('/api/v1/employees', [
        'first_name' => 'Jane', 'last_name' => 'Doe', 'birthday' => '1990-01-15',
        'civil_status' => 'married', 'nationality' => 'Filipino',
        'region' => 'Northern Mindanao', 'province' => 'Bukidnon',
        'city' => 'Quezon', 'barangay' => 'Poblacion',
    ])->assertCreated()->assertJsonPath('data.residence', null)
        ->assertJsonPath('data.house_number', null)->assertJsonPath('data.block_number', null)
        ->assertJsonPath('data.building_floor', null)->assertJsonPath('data.city', 'Quezon');
});

it('requires every geographic field when creating an employee', function (string $field, mixed $value) {
    $data = [
        'first_name' => 'Jane', 'last_name' => 'Doe', 'birthday' => '1990-01-15',
        'civil_status' => 'married', 'nationality' => 'Filipino',
        'region' => 'Northern Mindanao', 'province' => 'Bukidnon',
        'city' => 'Quezon', 'barangay' => 'Poblacion',
    ];
    if ($value === 'omitted') {
        unset($data[$field]);
    } else {
        $data[$field] = $value;
    }
    $this->asSsoAdmin($this->admin)->postJson('/api/v1/employees', $data)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(Employee::count())->toBe(1);
})->with(['region', 'province', 'city', 'barangay'])->with(['omitted', '', null]);

it('clears optional street details without clearing the geographic location', function () {
    $employee = Employee::factory()->create([
        'region' => 'Northern Mindanao', 'province' => 'Bukidnon', 'city' => 'Quezon', 'barangay' => 'Poblacion',
        'house_number' => '42', 'block_number' => 'A', 'building_floor' => '2',
    ]);
    $this->asSsoAdmin($this->admin)->putJson("/api/v1/employees/{$employee->uuid}", [
        'residence' => '', 'house_number' => '', 'block_number' => '', 'building_floor' => '',
    ])->assertSuccessful()->assertJsonPath('data.residence', null)
        ->assertJsonPath('data.house_number', null)->assertJsonPath('data.block_number', null)
        ->assertJsonPath('data.building_floor', null)->assertJsonPath('data.barangay', 'Poblacion');
});

it('rejects clearing supplied geographic fields on a partial employee update', function (string $field) {
    $employee = Employee::factory()->create();
    $this->asSsoAdmin($this->admin)->putJson("/api/v1/employees/{$employee->uuid}", [$field => null])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with(['region', 'province', 'city', 'barangay']);

it('can show an employee', function () {
    $employee = Employee::factory()->create();

    $response = $this->asSsoAdmin($this->admin)
        ->getJson("/api/v1/employees/{$employee->uuid}");

    $response->assertSuccessful()
        ->assertJsonPath('data.uuid', $employee->uuid);
});

it('can update an employee', function () {
    $employee = Employee::factory()->create();

    $response = $this->asSsoAdmin($this->admin)
        ->putJson("/api/v1/employees/{$employee->uuid}", [
            'first_name' => 'Updated Name',
        ]);

    $response->assertSuccessful()
        ->assertJsonPath('data.first_name', 'Updated Name');
});

it('can delete an employee', function () {
    $employee = Employee::factory()->create();

    $response = $this->asSsoAdmin($this->admin)
        ->deleteJson("/api/v1/employees/{$employee->uuid}");

    $response->assertSuccessful();

    $this->assertSoftDeleted('employees', ['id' => $employee->id]);
});

it('can grant application access to employee', function () {
    $employee = Employee::factory()->create();
    $application = Application::factory()->create();

    $response = $this->asSsoAdmin($this->admin)
        ->postJson("/api/v1/employees/{$employee->uuid}/applications", [
            'application_uuid' => $application->uuid,
            'role' => AppRole::Standard->value,
        ]);

    $response->assertSuccessful();

    $this->assertDatabaseHas('employee_application', [
        'employee_id' => $employee->id,
        'application_id' => $application->id,
        'role' => AppRole::Standard->value,
    ]);
});

it('can revoke application access from employee', function () {
    $employee = Employee::factory()->create();
    $application = Application::factory()->create();
    $employee->applications()->attach($application->id, ['role' => AppRole::Standard->value]);

    $response = $this->asSsoAdmin($this->admin)
        ->deleteJson("/api/v1/employees/{$employee->uuid}/applications/{$application->uuid}");

    $response->assertSuccessful();

    $this->assertDatabaseMissing('employee_application', [
        'employee_id' => $employee->id,
        'application_id' => $application->id,
    ]);
});
