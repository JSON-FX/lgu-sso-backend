<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Employee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class EmployeeAvatars
{
    public function replace(Employee $employee, Employee $actor, UploadedFile $photo): Employee
    {
        $dimensions = @getimagesize($photo->getPathname());
        if (! $dimensions || ! in_array($dimensions[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
            || min($dimensions[0], $dimensions[1]) < 256 || max($dimensions[0], $dimensions[1]) > 4096
            || $dimensions[0] * $dimensions[1] > 16000000) {
            throw ValidationException::withMessages(['photo' => 'Choose a JPG, PNG, or WebP photo between 256 and 4096 pixels per side, up to 16 million pixels.']);
        }
        $source = @imagecreatefromstring($photo->getContent());
        if (! $source) {
            throw ValidationException::withMessages(['photo' => 'This image could not be opened. Choose another photo.']);
        }
        $canvas = imagecreatetruecolor(512, 512);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        $side = min(imagesx($source), imagesy($source));
        imagecopyresampled($canvas, $source, 0, 0, (int) ((imagesx($source) - $side) / 2), (int) ((imagesy($source) - $side) / 2), 512, 512, $side, $side);
        ob_start();
        try {
            $encoded = imagewebp($canvas, null, 85);
            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($source);
            imagedestroy($canvas);
        }
        abort_unless($encoded && is_string($bytes) && strlen($bytes) > 0 && strlen($bytes) <= 512 * 1024, 503, 'Photo processing is temporarily unavailable.');
        $version = (string) Str::uuid();
        $path = $employee->uuid.'/'.$version.'.webp';
        try {
            Storage::disk('avatars')->put($path, $bytes);
            $oldPath = DB::transaction(function () use ($employee, $actor, $path, $version) {
                $locked = Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
                $oldPath = $locked->avatar_path;
                $locked->forceFill(['avatar_path' => $path, 'avatar_version' => $version])->save();
                AuditLog::log('profile_photo_updated', $actor, metadata: ['employee_uuid' => $employee->uuid, 'avatar_version' => $version]);

                return $oldPath;
            });
        } catch (Throwable $error) {
            $this->cleanup($path);
            report($error);
            abort(503, 'Could not save the photo. Please try again.');
        }
        $this->cleanup($oldPath);

        return $employee->fresh(['office', 'position', 'applications']);
    }

    public function remove(Employee $employee, Employee $actor): Employee
    {
        $oldPath = DB::transaction(function () use ($employee, $actor) {
            $locked = Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $oldPath = $locked->avatar_path;
            $locked->forceFill(['avatar_path' => null, 'avatar_version' => null])->save();
            if ($oldPath) {
                AuditLog::log('profile_photo_removed', $actor, metadata: ['employee_uuid' => $employee->uuid]);
            }

            return $oldPath;
        });
        $this->cleanup($oldPath);

        return $employee->fresh(['office', 'position', 'applications']);
    }

    public function response(Employee $employee)
    {
        $employee = $employee->fresh();
        abort_unless($employee, 404, 'Photo not found.');
        $disk = Storage::disk('avatars');
        abort_unless($employee->avatar_path && $disk->exists($employee->avatar_path), 404, 'Photo not found.');

        return response($disk->get($employee->avatar_path), 200, [
            'Content-Type' => 'image/webp', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline; filename="profile.webp"',
        ]);
    }

    private function cleanup(?string $path): void
    {
        if (! $path) {
            return;
        }
        try {
            Storage::disk('avatars')->delete($path);
        } catch (Throwable) {
            Log::warning('An unreferenced profile photo could not be removed.');
        }
    }
}
