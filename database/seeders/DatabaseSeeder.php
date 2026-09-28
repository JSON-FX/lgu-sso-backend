<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new \RuntimeException('Demo seeders are only available in local and test environments.');
        }

        $this->call([
            OfficeSeeder::class,
            PositionSeeder::class,
            ApplicationSeeder::class,
            EmployeeSeeder::class,
        ]);
    }
}
