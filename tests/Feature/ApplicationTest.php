<?php

use App\Models\Application;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Employee::factory()->create();
});

it('can list applications', function () {
    Application::factory()->count(3)->create();

    $response = $this->asSsoAdmin($this->admin)
        ->getJson('/api/v1/applications');

    $response->assertSuccessful()
        ->assertJsonCount(4, 'data');
});

it('can create an application', function () {
    $response = $this->asSsoAdmin($this->admin)
        ->postJson('/api/v1/applications', [
            'name' => 'Test Application',
            'description' => 'A test application',
            'redirect_uris' => ['http://test.com/callback'],
            'rate_limit_per_minute' => 60,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Test Application')
        ->assertJsonStructure(['client_secret']);

    $application = Application::where('uuid', $response->json('data.uuid'))->firstOrFail();
    expect($application->client_secret)->not->toBe($response->json('client_secret'))
        ->and(Hash::check($response->json('client_secret'), $application->client_secret))->toBeTrue();
});

it('can show an application', function () {
    $application = Application::factory()->create();

    $response = $this->asSsoAdmin($this->admin)
        ->getJson("/api/v1/applications/{$application->uuid}");

    $response->assertSuccessful()
        ->assertJsonPath('data.uuid', $application->uuid);
});

it('can update an application', function () {
    $application = Application::factory()->create();

    $response = $this->asSsoAdmin($this->admin)
        ->putJson("/api/v1/applications/{$application->uuid}", [
            'name' => 'Updated Name',
            'rate_limit_per_minute' => 100,
        ]);

    $response->assertSuccessful()
        ->assertJsonPath('data.name', 'Updated Name')
        ->assertJsonPath('data.rate_limit_per_minute', 100);
});

it('can delete an application', function () {
    $application = Application::factory()->create();

    $response = $this->asSsoAdmin($this->admin)
        ->deleteJson("/api/v1/applications/{$application->uuid}");

    $response->assertSuccessful();

    $this->assertSoftDeleted('applications', ['id' => $application->id]);
});

it('can regenerate client secret', function () {
    $application = Application::factory()->create();

    $response = $this->asSsoAdmin($this->admin)
        ->postJson("/api/v1/applications/{$application->uuid}/regenerate-secret");

    $response->assertSuccessful()
        ->assertJsonStructure(['client_secret']);
});
