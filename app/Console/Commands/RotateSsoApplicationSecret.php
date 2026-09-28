<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsCredentialFile;
use App\Models\Application;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

class RotateSsoApplicationSecret extends Command
{
    use ReadsCredentialFile;

    protected $signature = 'sso:rotate-app-secret
        {client-id : Existing application client ID}
        {--secret-file= : File containing the replacement client secret}';

    protected $description = 'Replace one application client secret without printing it';

    public function handle(): int
    {
        try {
            $secret = $this->credentialFromFile((string) $this->option('secret-file'), 'Application client secret', 32);
            DB::transaction(function () use ($secret): void {
                $application = Application::query()->where('client_id', $this->argument('client-id'))->lockForUpdate()->first();
                if (! $application) {
                    throw new \RuntimeException('An active application with that client ID was not found.');
                }

                if (Hash::check($secret, $application->client_secret)) {
                    throw new \RuntimeException('The replacement client secret must differ from the current secret.');
                }

                $application->update(['client_secret' => Hash::make($secret)]);
            });
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Application client secret rotated. Update the consumer with the matching value.');

        return self::SUCCESS;
    }
}
