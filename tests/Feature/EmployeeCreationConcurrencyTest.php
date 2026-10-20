<?php

use App\Models\AuditLog;
use App\Models\Employee;
use App\Services\EmployeeCreation;
use Symfony\Component\Process\Process;

it('serializes simultaneous public and administrator creation of the same person', function () {
    if (getenv('SSO_CONCURRENCY_TESTS') !== '1') {
        $this->markTestSkipped('Opt in with a disposable sso_duplicate_test_* MySQL database.');
    }
    expect(config('database.default'))->toBe('mysql');
    expect(config('database.connections.mysql.database'))->toStartWith('sso_duplicate_test_');
    expect(config('database.connections.mysql.host'))->toBe('127.0.0.1');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();

    $directory = sys_get_temp_dir().'/sso-identity-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $payload = [
        'first_name' => 'Concurrent', 'last_name' => 'Registrant', 'birthday' => '1990-01-01',
        'civil_status' => 'single', 'nationality' => 'Filipino', 'region' => 'Test region',
        'province' => 'Test province', 'city' => 'Test city', 'barangay' => 'Test barangay',
        'password' => 'Isolated-test123', 'password_confirmation' => 'Isolated-test123',
        'applications' => [],
    ];
    $worker = <<<'WORKER'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
touch($input['directory'].'/ready-'.$input['number']);
$deadline = microtime(true) + 10;
while (!file_exists($input['directory'].'/go')) {
    if (microtime(true) > $deadline) { exit(2); }
    usleep(10000);
}
if ($input['number'] % 2 === 0) {
    $request = Illuminate\Http\Request::create('/api/v1/auth/register', 'POST', server: [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    ], content: json_encode($input['payload']));
    $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
    echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)]);
} else {
    try {
        app(App\Services\EmployeeCreation::class)->create(Illuminate\Support\Arr::except($input['payload'], ['applications', 'password_confirmation']));
        echo json_encode(['status' => 201]);
    } catch (Illuminate\Validation\ValidationException $error) {
        echo json_encode(['status' => 422, 'body' => ['message' => $error->getMessage()]]);
    }
}
WORKER;
    $processes = [];
    try {
        for ($number = 0; $number < 8; $number++) {
            $process = new Process([PHP_BINARY, '-r', $worker], base_path(), timeout: 20);
            $process->setInput(json_encode([
                'directory' => $directory, 'number' => $number,
                'payload' => [...$payload, 'email' => "concurrent-{$number}@example.test"],
            ], JSON_THROW_ON_ERROR));
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 10;
        while (count(glob($directory.'/ready-*')) !== 8 && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(glob($directory.'/ready-*'))->toHaveCount(8);
        touch($directory.'/go');
        $statuses = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $statuses[] = $result['status'];
            if ($result['status'] !== 201) {
                expect($result['status'])->toBe(422)
                    ->and($result['body']['message'])->toBe(EmployeeCreation::ACCOUNT_EXISTS);
            }
        }
        expect(array_filter($statuses, fn (int $status) => $status === 201))->toHaveCount(1)
            ->and(Employee::count())->toBe(1)
            ->and(AuditLog::where('action', 'self_registered')->count())->toBeLessThanOrEqual(1);
    } finally {
        foreach ($processes as $process) {
            $process->stop();
        }
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});
