<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class Application extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'description',
        'client_id',
        'client_secret',
        'redirect_uris',
        'rate_limit_per_minute',
        'is_active',
    ];

    protected $hidden = [
        'client_secret',
    ];

    protected function casts(): array
    {
        return [
            'redirect_uris' => 'array',
            'rate_limit_per_minute' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Application $application) {
            if (empty($application->uuid)) {
                $application->uuid = (string) Str::uuid();
            }
            if (empty($application->client_id)) {
                $application->client_id = Str::random(40);
            }
            if (empty($application->client_secret)) {
                $application->client_secret = Hash::make(Str::random(40));
            }
        });

        static::updated(function (Application $application) {
            if (($application->wasChanged('is_active') && ! $application->is_active)
                || $application->wasChanged('client_secret')) {
                $application->revokeSessions();
            }
        });

        static::deleting(fn (Application $application) => $application->revokeSessions());
    }

    public function revokeSessions(): void
    {
        $this->tokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        DB::table('sso_authorization_codes')->where('application_id', $this->id)->delete();
    }

    public function generateNewSecret(): string
    {
        $plainSecret = Str::random(40);
        $this->client_secret = Hash::make($plainSecret);
        $this->save();

        return $plainSecret;
    }

    public function validateSecret(string $secret): bool
    {
        return Hash::check($secret, $this->client_secret);
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employee_application')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(OAuthToken::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
