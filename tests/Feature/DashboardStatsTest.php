<?php

use App\Models\Application;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows accurate dashboard counts to administrators', function () {
    $admin = Employee::factory()->create();
    Employee::factory()->create(['is_active' => false]);
    Application::factory()->create(['is_active' => false]);

    $this->asSsoAdmin($admin)
        ->getJson('/api/v1/stats/dashboard')
        ->assertSuccessful()
        ->assertJsonPath('totalEmployees', 2)
        ->assertJsonPath('activeEmployees', 1)
        ->assertJsonPath('totalApplications', 2)
        ->assertJsonPath('activeApplications', 1);
});

it('denies dashboard counts to regular employees', function () {
    $employee = Employee::factory()->create();

    $this->asSsoEmployee($employee)
        ->getJson('/api/v1/stats/dashboard')
        ->assertForbidden();
});
