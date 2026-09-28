<?php

namespace Database\Seeders;

use App\Enums\AppRole;
use App\Enums\CivilStatus;
use App\Models\Application;
use App\Models\Employee;
use App\Models\Position;
use Illuminate\Database\Seeder;

class EmployeeSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new \RuntimeException('Demo employees are only available in local and test environments.');
        }

        $admin = Employee::firstOrCreate(
            ['email' => 'admin@lgu-sso.test'],
            [
                'first_name' => 'Admin',
                'middle_name' => null,
                'last_name' => 'User',
                'username' => Employee::generateUsername('Admin', 'User'),
                'birthday' => '1990-01-15',
                'civil_status' => CivilStatus::Single,
                'province' => 'Metro Manila',
                'city' => 'Quezon City',
                'barangay' => 'Barangay Holy Spirit',
                'residence' => '123 Admin Street',
                'nationality' => 'Filipino',
                'password' => 'password',
                'must_change_password' => false,
                'position_id' => Position::firstOrCreate(['title' => 'Database Manager'])->id,
            ]
        );

        $applications = Application::all();

        foreach ($applications as $app) {
            $admin->applications()->syncWithoutDetaching([
                $app->id => ['role' => AppRole::SuperAdministrator->value],
            ]);
        }
    }
}
