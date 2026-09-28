<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsCredentialFile;
use App\Enums\AppRole;
use App\Enums\CivilStatus;
use App\Models\Application;
use App\Models\Employee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class BootstrapSsoAdministrator extends Command
{
    use ReadsCredentialFile;

    protected $signature = 'sso:bootstrap-admin
        {--first-name= : Administrator first name}
        {--last-name= : Administrator last name}
        {--email= : Administrator email address}
        {--birthday= : Administrator birth date (YYYY-MM-DD)}
        {--civil-status= : Administrator civil status}
        {--residence= : Administrator residence}
        {--nationality= : Administrator nationality}
        {--password-file= : File containing the initial administrator password}
        {--client-secret-file= : File containing the portal client secret}
        {--portal-redirect-uri= : HTTPS callback URL for the admin application}';

    protected $description = 'Create the first SSO administrator and admin application in an empty database';

    public function handle(): int
    {
        $attributes = [
            'first_name' => $this->option('first-name'),
            'last_name' => $this->option('last-name'),
            'email' => $this->option('email'),
            'birthday' => $this->option('birthday'),
            'civil_status' => $this->option('civil-status'),
            'residence' => $this->option('residence'),
            'nationality' => $this->option('nationality'),
            'portal_redirect_uri' => $this->option('portal-redirect-uri'),
            'password_file' => $this->option('password-file'),
            'client_secret_file' => $this->option('client-secret-file'),
        ];

        $validator = Validator::make($attributes, [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'birthday' => ['required', 'date', 'before:today'],
            'civil_status' => ['required', 'in:'.implode(',', CivilStatus::values())],
            'residence' => ['required', 'string', 'max:500'],
            'nationality' => ['required', 'string', 'max:100'],
            'portal_redirect_uri' => ['required', 'url', 'max:2048'],
            'password_file' => ['required', 'string'],
            'client_secret_file' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        $redirectUri = $attributes['portal_redirect_uri'];
        $parts = parse_url($redirectUri);
        if (! is_array($parts) || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || ($parts['scheme'] !== 'https' && ! (app()->environment('local', 'testing') && $parts['scheme'] === 'http'))) {
            $this->error('Portal redirect URI must use HTTPS and must not contain credentials or a fragment.');

            return self::FAILURE;
        }

        try {
            $password = $this->credentialFromFile($attributes['password_file'], 'Administrator password', 20);
            $clientSecret = $this->credentialFromFile($attributes['client_secret_file'], 'Portal client secret', 32);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (hash_equals($password, $clientSecret)) {
            $this->error('Administrator password and portal client secret must differ.');

            return self::FAILURE;
        }

        try {
            $result = DB::transaction(function () use ($attributes, $password, $clientSecret, $redirectUri): array {
                if (Employee::withTrashed()->exists() || Application::withTrashed()->exists()) {
                    throw new \RuntimeException('Bootstrap requires an empty employee and application database.');
                }

                $application = Application::create([
                    'name' => 'Admin App Management System',
                    'description' => 'The frontend admin application for managing the SSO system',
                    'client_id' => Str::random(40),
                    'client_secret' => Hash::make($clientSecret),
                    'redirect_uris' => [$redirectUri],
                    'rate_limit_per_minute' => 100,
                ]);

                $employee = Employee::create([
                    'first_name' => $attributes['first_name'],
                    'last_name' => $attributes['last_name'],
                    'email' => $attributes['email'],
                    'username' => Employee::generateUsername($attributes['first_name'], $attributes['last_name']),
                    'birthday' => $attributes['birthday'],
                    'civil_status' => $attributes['civil_status'],
                    'residence' => $attributes['residence'],
                    'nationality' => $attributes['nationality'],
                    'password' => $password,
                    'must_change_password' => true,
                    'is_active' => true,
                ]);

                $employee->applications()->attach($application->id, ['role' => AppRole::SuperAdministrator->value]);

                return [$employee->username, $application->client_id];
            });
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('First SSO administrator created. Change the initial password at first login.');
        $this->line('Username: '.$result[0]);
        $this->line('Admin application client ID: '.$result[1]);

        return self::SUCCESS;
    }
}
