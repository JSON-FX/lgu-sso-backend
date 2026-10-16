<?php

use App\Models\Application;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->application = Application::factory()->create(['client_secret' => Hash::make('directory-test-secret')]);
    $this->headers = ['X-Client-ID' => $this->application->client_id, 'X-Client-Secret' => 'directory-test-secret'];
    $this->roles = ['standard', 'administrator', 'super_administrator'];
    $this->url = fn (array $query = [], string $suffix = '') => '/api/v1/sso/directory'.$suffix.'?'.http_build_query(['roles' => $this->roles, ...$query]);
    $this->person = function (string $role = 'standard', array $attributes = []) {
        $employee = Employee::factory()->create($attributes);
        $this->application->employees()->attach($employee, ['role' => $role]);

        return $employee;
    };
});

it('projects only permitted display fields and current application roles across offices', function () {
    $other = Application::factory()->create();
    $expected = [];
    foreach ($this->roles as $role) {
        $employee = ($this->person)($role);
        $other->employees()->attach($employee, ['role' => 'guest']);
        $expected[$employee->uuid] = $role;
    }
    ($this->person)('guest');
    ($this->person)('unknown');
    ($this->person)('standard', ['is_active' => false]);
    ($this->person)('standard', ['must_change_password' => true]);
    ($this->person)()->delete();
    $other->employees()->attach(Employee::factory()->create(), ['role' => 'standard']);
    Employee::factory()->create();

    $response = $this->getJson(($this->url)(), $this->headers)->assertOk()->assertJsonCount(3, 'data');
    expect($response->json('meta'))->toBe(['page' => 1, 'per_page' => 20, 'has_more' => false]);
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
    foreach ($response->json('data') as $person) {
        expect(array_keys($person))->toBe(['uuid', 'full_name', 'initials', 'avatar_version', 'avatar_endpoint', 'role', 'office_id', 'office_name', 'office_abbreviation', 'position_id', 'position_title']);
        expect($person['role'])->toBe($expected[$person['uuid']]);
    }
    // A caller-supplied application identifier cannot override the credentials' scope.
    $this->getJson(($this->url)(['application_id' => $other->id]), $this->headers)->assertJsonCount(3, 'data');
});

it('leaves the guest policy to the consumer and supports missing office metadata', function () {
    $guest = ($this->person)('guest', ['office_id' => null]);
    $this->getJson(($this->url)(['roles' => ['guest']]), $this->headers)->assertOk()
        ->assertJsonPath('data.0.uuid', $guest->uuid)->assertJsonPath('data.0.role', 'guest')
        ->assertJsonPath('data.0.office_name', null)->assertJsonPath('data.0.office_abbreviation', null);
    $this->getJson(($this->url)(), $this->headers)->assertJsonCount(0, 'data');
});

it('searches name parts with literal wildcards and filters before stable pagination', function () {
    $first = ($this->person)('standard', ['first_name' => 'Maria', 'middle_name' => 'Clara', 'last_name' => 'Reyes', 'suffix' => null]);
    $second = ($this->person)('administrator', ['first_name' => 'Maria', 'middle_name' => 'Clara', 'last_name' => 'Reyes', 'suffix' => null]);
    ($this->person)('guest', ['first_name' => 'Maria', 'last_name' => 'Reyes']);
    ($this->person)('standard', ['first_name' => 'Other', 'last_name' => 'Person']);
    $ids = [$first->uuid, $second->uuid];
    sort($ids);
    foreach ([1, 2, 3] as $page) {
        $response = $this->getJson(($this->url)(['search' => '  REYES   maria clara ', 'page' => $page, 'per_page' => 1]), $this->headers)->assertOk();
        expect($response->json('data'))->toHaveCount($page < 3 ? 1 : 0);
        expect($response->json('meta'))->toBe(['page' => $page, 'per_page' => 1, 'has_more' => $page === 1]);
        if ($page < 3) {
            $response->assertJsonPath('data.0.uuid', $ids[$page - 1]);
        }
    }
    foreach (['%', '_', '!', "' OR 1=1 --"] as $search) {
        $this->getJson(($this->url)(['search' => $search]), $this->headers)->assertOk()->assertJsonCount(0, 'data');
    }
    $literal = ($this->person)('standard', ['first_name' => 'A%_!B']);
    $this->getJson(($this->url)(['search' => '%_!']), $this->headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $literal->uuid);
});

it('rejects invalid query bounds and roles', function (array $query) {
    $response = $this->getJson(($this->url)($query), $this->headers)->assertUnprocessable();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
})->with([
    [['roles' => []]], [['roles' => 'standard']], [['roles' => ['unknown']]], [['roles' => ['standard', 'standard']]],
    [['roles' => ['guest', 'standard', 'administrator', 'super_administrator', 'other']]],
    [['search' => str_repeat('a', 101)]], [['search' => ['name']]],
    [['page' => 0]], [['page' => 10001]], [['page' => '1.5']],
    [['per_page' => 0]], [['per_page' => 51]],
    [['office_id' => '']], [['office_id' => 0]], [['office_id' => -1]],
    [['office_id' => '1.5']], [['office_id' => 'invalid']], [['office_id' => [1]]],
    [['position_id' => '']], [['position_id' => 0]], [['position_id' => -1]],
    [['position_id' => '1.5']], [['position_id' => 'invalid']], [['position_id' => [1]]],
]);

it('filters office and position independently and together before pagination', function () {
    $office = Office::factory()->create(['is_active' => false]);
    $otherOffice = Office::factory()->create();
    $position = Position::create(['title' => 'Engineer', 'is_active' => false]);
    $otherPosition = Position::create(['title' => 'Clerk']);
    $first = ($this->person)('standard', ['last_name' => 'Bravo', 'office_id' => $office->id, 'position_id' => $position->id]);
    $second = ($this->person)('administrator', ['last_name' => 'Delta', 'office_id' => $office->id, 'position_id' => $position->id]);
    $officeOnly = ($this->person)('standard', ['last_name' => 'Charlie', 'office_id' => $office->id, 'position_id' => $otherPosition->id]);
    $positionOnly = ($this->person)('standard', ['last_name' => 'Alpha', 'office_id' => $otherOffice->id, 'position_id' => $position->id]);
    ($this->person)('standard', ['last_name' => 'Aardvark', 'office_id' => $otherOffice->id, 'position_id' => $otherPosition->id]);

    foreach ([
        [['office_id' => $office->id], [$first->uuid, $officeOnly->uuid, $second->uuid]],
        [['position_id' => $position->id], [$positionOnly->uuid, $first->uuid, $second->uuid]],
        [['office_id' => $office->id, 'position_id' => $position->id], [$first->uuid, $second->uuid]],
        [['office_id' => $office->id, 'position_id' => $position->id, 'search' => 'Delta Engineer'], [$second->uuid]],
        [['office_id' => 999999], []],
        [['position_id' => 999999], []],
    ] as [$filters, $expected]) {
        for ($page = 1; $page <= count($expected) + 1; $page++) {
            $response = $this->getJson(($this->url)([...$filters, 'page' => $page, 'per_page' => 1]), $this->headers)->assertOk();
            expect(array_column($response->json('data'), 'uuid'))->toBe(array_slice($expected, $page - 1, 1));
            expect($response->json('meta'))->toBe([
                'page' => $page, 'per_page' => 1, 'has_more' => $page < count($expected), 'filters_applied' => true,
            ]);
            expect($response->headers->get('Cache-Control'))->toContain('no-store');
        }
    }
});

it('keeps related search and filters within current application eligibility', function () {
    $office = Office::factory()->create(['name' => 'Needle Office', 'abbreviation' => 'NDL']);
    $position = Position::create(['title' => 'Sentinel']);
    $attributes = ['office_id' => $office->id, 'position_id' => $position->id];
    $allowed = ($this->person)('standard', $attributes);
    ($this->person)('guest', $attributes);
    ($this->person)('unknown', $attributes);
    ($this->person)('standard', [...$attributes, 'is_active' => false]);
    ($this->person)('standard', [...$attributes, 'must_change_password' => true]);
    ($this->person)('standard', $attributes)->delete();
    $other = Application::factory()->create();
    $other->employees()->attach(Employee::factory()->create($attributes), ['role' => 'standard']);
    Employee::factory()->create($attributes);

    foreach (['Needle', 'NDL', 'Sentinel', 'Needle Sentinel'] as $search) {
        foreach ([[], $attributes] as $filters) {
            $this->getJson(($this->url)([...$filters, 'search' => $search]), $this->headers)->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $allowed->uuid);
        }
    }
    $this->application->employees()->detach($allowed);
    $this->getJson(($this->url)([...$attributes, 'search' => 'Needle Sentinel']), $this->headers)->assertOk()
        ->assertJsonCount(0, 'data')->assertJsonPath('meta.filters_applied', true);
});

it('searches related fields with literal wildcards and current reference values', function (string $field) {
    $office = Office::factory()->create(['name' => 'Ordinary Office', 'abbreviation' => 'ORD']);
    $position = Position::create(['title' => 'Ordinary Position']);
    $reference = $field === 'title' ? $position : $office;
    $reference->update([$field => 'Signal%_!Value', 'is_active' => false]);
    $employee = ($this->person)('standard', ['office_id' => $office->id, 'position_id' => $position->id]);
    ($this->person)('standard', ['office_id' => Office::factory()->create(['name' => 'SignalXXXValue'])->id, 'position_id' => null]);

    foreach (['SIGNAL%_!VALUE', '%_!'] as $search) {
        $this->getJson(($this->url)(['search' => $search]), $this->headers)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $employee->uuid);
    }
    $reference->update([$field => 'Renamed']);
    $this->getJson(($this->url)(['search' => 'Signal%_!Value']), $this->headers)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(($this->url)(['search' => 'Renamed']), $this->headers)->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $employee->uuid);
})->with(['name', 'abbreviation', 'title']);

it('combines search terms across names and relations without losing employees with null relations', function () {
    $office = Office::factory()->create(['name' => 'Municipal Treasury', 'abbreviation' => 'MTO']);
    $position = Position::create(['title' => 'Engineer']);
    $employee = ($this->person)('standard', ['first_name' => 'Maria', 'middle_name' => null, 'last_name' => 'Reyes', 'suffix' => null, 'office_id' => $office->id, 'position_id' => $position->id]);
    $unassigned = ($this->person)('standard', ['first_name' => 'Nora', 'middle_name' => null, 'last_name' => 'Santos', 'suffix' => null, 'office_id' => null, 'position_id' => null]);
    $this->getJson(($this->url)(['search' => '  MARIA  engineer MTO treasury ']), $this->headers)->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $employee->uuid);
    $this->getJson(($this->url)(['search' => 'Maria engineer absent']), $this->headers)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(($this->url)(['search' => 'Nora']), $this->headers)->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $unassigned->uuid);
});

it('requires an explicit role selection on both routes', function () {
    foreach (['', '/'.Str::uuid()] as $suffix) {
        $this->getJson('/api/v1/sso/directory'.$suffix, $this->headers)->assertUnprocessable();
    }
});

it('rechecks lookup and discovery after eligibility changes', function (string $change) {
    $employee = ($this->person)();
    $lookup = ($this->url)([], '/'.$employee->uuid);
    $this->getJson($lookup, $this->headers)->assertOk()->assertJsonPath('data.uuid', $employee->uuid);
    match ($change) {
        'removed' => $this->application->employees()->detach($employee),
        'guest' => $this->application->employees()->updateExistingPivot($employee->id, ['role' => 'guest']),
        'unknown' => $this->application->employees()->updateExistingPivot($employee->id, ['role' => 'unknown']),
        'inactive' => $employee->update(['is_active' => false]),
        'password' => $employee->update(['must_change_password' => true]),
        'deleted' => $employee->delete(),
    };
    $response = $this->getJson($lookup, $this->headers)->assertNotFound()->assertExactJson(['message' => 'Employee not found.']);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $this->getJson(($this->url)(), $this->headers)->assertJsonCount(0, 'data');
})->with(['removed', 'guest', 'unknown', 'inactive', 'password', 'deleted']);

it('does not reveal whether an excluded lookup UUID exists', function () {
    $unassigned = Employee::factory()->create();
    $other = Application::factory()->create();
    $other->employees()->attach($unassigned, ['role' => 'standard']);
    foreach ([$unassigned->uuid, (string) Str::uuid(), 'invalid-uuid'] as $uuid) {
        $this->getJson(($this->url)([], '/'.$uuid), $this->headers)->assertNotFound()->assertExactJson(['message' => 'Employee not found.']);
    }
});

it('returns a changed allowed role on the next lookup', function () {
    $employee = ($this->person)();
    $lookup = ($this->url)([], '/'.$employee->uuid);
    $this->getJson($lookup, $this->headers)->assertJsonPath('data.role', 'standard');
    $this->application->employees()->updateExistingPivot($employee->id, ['role' => 'administrator']);
    $this->getJson($lookup, $this->headers)->assertJsonPath('data.role', 'administrator');
});

it('enforces active client credentials on directory and lookup', function (string $suffix) {
    $url = ($this->url)([], $suffix);
    $response = $this->getJson($url)->assertUnauthorized();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $this->getJson($url, [...$this->headers, 'X-Client-Secret' => 'wrong'])->assertUnauthorized();
    $this->application->update(['is_active' => false]);
    $this->getJson($url, $this->headers)->assertUnauthorized();
})->with(['', '/00000000-0000-4000-8000-000000000001']);

it('shares the existing application rate limit and preserves Retry-After', function () {
    $this->application->update(['rate_limit_per_minute' => 1]);
    $this->getJson(($this->url)(['office_id' => 999999]), $this->headers)->assertOk();
    $response = $this->getJson(($this->url)([], '/'.Str::uuid()), $this->headers)->assertStatus(429)->assertHeader('Retry-After');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('preserves the legacy employee directory array shape', function () {
    $employee = ($this->person)('guest');
    $this->getJson('/api/v1/sso/employees', $this->headers)->assertOk()->assertJsonCount(1)
        ->assertJsonPath('0.uuid', $employee->uuid)->assertJsonMissingPath('0.role')->assertJsonMissingPath('data');
});

it('returns stable office and position references without private employee details', function () {
    $office = \App\Models\Office::factory()->create();
    $position = \App\Models\Position::create(['title' => 'Engineer I']);
    $employee = ($this->person)('standard', ['office_id' => $office->id, 'position_id' => $position->id]);
    foreach ([['', 'data.0'], ['/'.$employee->uuid, 'data']] as [$suffix, $path]) {
        $this->getJson(($this->url)([], $suffix), $this->headers)->assertOk()
            ->assertJsonPath($path.'.office_id', $office->id)
            ->assertJsonPath($path.'.position_id', $position->id)
            ->assertJsonPath($path.'.position_title', 'Engineer I')
            ->assertJsonMissingPath($path.'.birthday')->assertJsonMissingPath($path.'.email')
            ->assertJsonMissingPath($path.'.residence')->assertJsonMissingPath($path.'.id');
    }
    $position->update(['title' => 'Engineer II', 'is_active' => false]);
    $office->update(['is_active' => false]);
    $this->getJson(($this->url)([], '/'.$employee->uuid), $this->headers)->assertOk()
        ->assertJsonPath('data.position_id', $position->id)->assertJsonPath('data.position_title', 'Engineer II')
        ->assertJsonPath('data.office_id', $office->id);
    $employee->update(['office_id' => null, 'position_id' => null]);
    $this->getJson(($this->url)([], '/'.$employee->uuid), $this->headers)->assertOk()
        ->assertJsonPath('data.office_id', null)->assertJsonPath('data.position_id', null)
        ->assertJsonPath('data.position_title', null);
});
