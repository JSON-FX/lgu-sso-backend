<?php

namespace Tests;

use App\Enums\AppRole;
use App\Models\Application;
use App\Models\Employee;
use App\Models\OAuthToken;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

abstract class TestCase extends BaseTestCase
{
    protected function issueSsoToken(Employee $employee, ?Application $application = null): string
    {
        $token = JWTAuth::fromUser($employee);
        OAuthToken::create([
            'employee_id' => $employee->id,
            'application_id' => $application?->id,
            'access_token' => hash('sha256', $token),
        ]);

        return $token;
    }

    protected function asSsoEmployee(Employee $employee): static
    {
        return $this->withToken($this->issueSsoToken($employee));
    }

    protected function asSsoAdmin(Employee $employee): static
    {
        $application = Application::firstOrCreate(
            ['name' => 'Admin App Management System'],
            ['client_secret' => 'test-secret', 'redirect_uris' => []]
        );
        $employee->applications()->syncWithoutDetaching([
            $application->id => ['role' => AppRole::SuperAdministrator->value],
        ]);

        return $this->asSsoEmployee($employee);
    }
}
