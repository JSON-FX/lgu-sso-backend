<?php

namespace App\Console\Commands\Concerns;

use RuntimeException;

trait ReadsCredentialFile
{
    private function credentialFromFile(string $path, string $label, int $minimumLength): string
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("{$label} file must exist and be readable.");
        }

        $contents = file_get_contents($path);
        if ($contents === false || strlen($contents) > 4096) {
            throw new RuntimeException("{$label} file could not be read or is too large.");
        }

        $credential = rtrim($contents, "\r\n");
        if (strlen($credential) < $minimumLength || strlen($credential) > 72 || preg_match('/[\x00-\x1F\x7F]/', $credential)) {
            throw new RuntimeException("{$label} must contain {$minimumLength} to 72 bytes and no control characters.");
        }

        return $credential;
    }
}
