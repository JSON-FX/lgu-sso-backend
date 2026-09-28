<?php

use App\Enums\AppRole;
use App\Models\Application;
use App\Models\Employee;
use App\Models\OAuthToken;
use Database\Seeders\ApplicationSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\EmployeeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $GLOBALS['ssoCredentialFiles'] = [];
});

afterEach(function () {
    foreach ($GLOBALS['ssoCredentialFiles'] as $path) {
        unlink($path);
    }
});

function credentialFile(string $value): string
{
    $path = tempnam(sys_get_temp_dir(), 'sso-credential-');
    file_put_contents($path, $value."\n");
    chmod($path, 0600);
    $GLOBALS['ssoCredentialFiles'][] = $path;

    return $path;
}

function bootstrapOptions(string $passwordFile, string $clientSecretFile): array
{
    return [
        '--first-name' => 'First',
        '--last-name' => 'Administrator',
        '--email' => 'first.admin@example.org',
        '--birthday' => '1980-01-01',
        '--civil-status' => 'single',
        '--residence' => 'Quezon City',
        '--nationality' => 'Filipino',
        '--portal-redirect-uri' => 'https://sso.example.org/callback',
        '--password-file' => $passwordFile,
        '--client-secret-file' => $clientSecretFile,
    ];
}

it('bootstraps the first administrator with file-provided credentials and no secret output', function () {
    $password = 'first-admin-secure-passphrase-2026';
    $secret = 'portal-client-secure-secret-2026-abc';

    expect(Artisan::call('sso:bootstrap-admin', bootstrapOptions(
        credentialFile($password), credentialFile($secret)
    )))->toBe(0);

    $output = Artisan::output();
    expect($output)->not->toContain($password, $secret);

    $employee = Employee::firstOrFail();
    $application = Application::firstOrFail();

    expect($employee->username)->toBe('f.administrator')
        ->and($employee->must_change_password)->toBeTrue()
        ->and(Hash::check($password, $employee->password))->toBeTrue()
        ->and($application->validateSecret($secret))->toBeTrue()
        ->and($employee->getRoleFor($application))->toBe(AppRole::SuperAdministrator)
        ->and($application->redirect_uris)->toBe(['https://sso.example.org/callback']);

    expect(Artisan::call('sso:bootstrap-admin', bootstrapOptions(
        credentialFile($password), credentialFile($secret)
    )))->toBe(1);
    expect(Employee::count())->toBe(1);
});

it('rejects weak or missing credential files without partial bootstrap', function () {
    $options = bootstrapOptions(credentialFile('short'), credentialFile('portal-client-secure-secret-2026-abc'));

    expect(Artisan::call('sso:bootstrap-admin', $options))->toBe(1)
        ->and(Employee::count())->toBe(0)
        ->and(Application::count())->toBe(0);
});

it('rotates an administrator password and revokes every active session', function () {
    $oldPassword = 'first-admin-secure-passphrase-2026';
    Artisan::call('sso:bootstrap-admin', bootstrapOptions(
        credentialFile($oldPassword), credentialFile('portal-client-secure-secret-2026-abc')
    ));
    $employee = Employee::firstOrFail();
    OAuthToken::create(['employee_id' => $employee->id, 'access_token' => hash('sha256', 'test-token')]);

    expect(Artisan::call('sso:rotate-admin-password', [
        'username' => $employee->username,
        '--password-file' => credentialFile($oldPassword),
    ]))->toBe(1);
    expect($employee->tokens()->whereNull('revoked_at')->count())->toBe(1);

    $newPassword = 'replacement-admin-passphrase-2026';
    expect(Artisan::call('sso:rotate-admin-password', [
        'username' => $employee->username,
        '--password-file' => credentialFile($newPassword),
    ]))->toBe(0);

    $employee->refresh();
    expect(Hash::check($newPassword, $employee->password))->toBeTrue()
        ->and(Hash::check($oldPassword, $employee->password))->toBeFalse()
        ->and($employee->must_change_password)->toBeTrue()
        ->and($employee->tokens()->whereNull('revoked_at')->count())->toBe(0)
        ->and(Artisan::output())->not->toContain($newPassword);
});

it('rotates one application secret and leaves other applications intact', function () {
    $first = Application::factory()->create(['client_secret' => Hash::make('old-first-secret')]);
    $second = Application::factory()->create(['client_secret' => Hash::make('old-second-secret')]);
    $newSecret = 'replacement-application-client-secret-2026';

    expect(Artisan::call('sso:rotate-app-secret', [
        'client-id' => $first->client_id,
        '--secret-file' => credentialFile('old-first-secret'),
    ]))->toBe(1);

    expect(Artisan::call('sso:rotate-app-secret', [
        'client-id' => $first->client_id,
        '--secret-file' => credentialFile($newSecret),
    ]))->toBe(0);

    expect($first->fresh()->validateSecret($newSecret))->toBeTrue()
        ->and($first->fresh()->validateSecret('old-first-secret'))->toBeFalse()
        ->and($second->fresh()->validateSecret('old-second-secret'))->toBeTrue()
        ->and(Artisan::output())->not->toContain($newSecret);
});

it('refuses to rotate passwords for non-administrators', function () {
    $employee = Employee::factory()->create(['password' => 'existing-password']);

    expect(Artisan::call('sso:rotate-admin-password', [
        'username' => $employee->username,
        '--password-file' => credentialFile('replacement-admin-passphrase-2026'),
    ]))->toBe(1);

    expect(Hash::check('existing-password', $employee->fresh()->password))->toBeTrue();
});

it('rejects demo seeders in production', function () {
    app()->instance('env', 'production');

    try {
        foreach ([DatabaseSeeder::class, EmployeeSeeder::class, ApplicationSeeder::class] as $seeder) {
            expect(fn () => app($seeder)->run())->toThrow(RuntimeException::class);
        }
    } finally {
        app()->instance('env', 'testing');
    }
});
