<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsCredentialFile;
use App\Enums\AppRole;
use App\Models\Employee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

class RotateSsoAdminPassword extends Command
{
    use ReadsCredentialFile;

    protected $signature = 'sso:rotate-admin-password
        {username : Username of an existing SSO super administrator}
        {--password-file= : File containing the new administrator password}';

    protected $description = 'Replace an SSO administrator password and revoke all existing sessions';

    public function handle(): int
    {
        try {
            $password = $this->credentialFromFile((string) $this->option('password-file'), 'Administrator password', 20);
            DB::transaction(function () use ($password): void {
                $employee = Employee::query()->where('username', $this->argument('username'))
                    ->where('is_active', true)->lockForUpdate()->first();

                if (! $employee || ! $employee->applications()
                    ->where('applications.name', 'Admin App Management System')
                    ->wherePivot('role', AppRole::SuperAdministrator->value)->exists()) {
                    throw new \RuntimeException('An active SSO super administrator with that username was not found.');
                }

                if (Hash::check($password, $employee->password)) {
                    throw new \RuntimeException('The replacement password must differ from the current password.');
                }

                $employee->update(['password' => $password, 'must_change_password' => true]);
                $employee->tokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            });
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Administrator password rotated. Existing sessions were revoked.');

        return self::SUCCESS;
    }
}
