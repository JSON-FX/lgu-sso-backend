<?php

use Symfony\Component\Process\Process;

it('preserves employee avatar authorization and binding with production route caching', function () {
    $directory = sys_get_temp_dir().'/sso-avatar-routes-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $environment = [
        'APP_ENV' => 'testing', 'APP_ROUTES_CACHE' => $directory.'/routes.php',
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
    ];
    try {
        $cache = new Process([PHP_BINARY, 'artisan', 'route:cache'], base_path(), $environment);
        $cache->mustRun();
        expect(is_file($environment['APP_ROUTES_CACHE']))->toBeTrue();
        $tests = new Process([PHP_BINARY, 'vendor/bin/pest', 'tests/Feature/EmployeeAvatarTest.php', '--colors=never'], base_path(), $environment, timeout: 90);
        $tests->run();
        expect($tests->isSuccessful())->toBeTrue($tests->getOutput().$tests->getErrorOutput());
    } finally {
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});
