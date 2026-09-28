<?php

namespace App\Models;

use App\Enums\AppRole;
use App\Enums\CivilStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Contracts\JWTSubject;

class Employee extends Authenticatable implements JWTSubject
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'birthday',
        'civil_status',
        'region',
        'province',
        'city',
        'barangay',
        'residence',
        'block_number',
        'building_floor',
        'house_number',
        'nationality',
        'email',
        'username',
        'password',
        'must_change_password',
        'is_active',
        'office_id',
        'position_id',
        'date_employed',
        'date_terminated',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'civil_status' => CivilStatus::class,
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'password' => 'hashed',
            'date_employed' => 'date',
            'date_terminated' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Employee $employee) {
            if (empty($employee->uuid)) {
                $employee->uuid = (string) Str::uuid();
            }
        });

        static::updated(function (Employee $employee) {
            if (($employee->wasChanged('is_active') && ! $employee->is_active)
                || ($employee->wasChanged('must_change_password') && $employee->must_change_password)) {
                $employee->revokeSessions();
            }
        });

        static::deleting(fn (Employee $employee) => $employee->revokeSessions());
    }

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [];
    }

    protected function initials(): Attribute
    {
        return Attribute::get(function () {
            $parts = array_filter([
                $this->first_name,
                $this->middle_name,
                $this->last_name,
            ]);

            return implode('.', array_map(fn ($part) => strtoupper(substr($part, 0, 1)), $parts));
        });
    }

    protected function age(): Attribute
    {
        return Attribute::get(function () {
            return $this->birthday ? Carbon::parse($this->birthday)->age : null;
        });
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(function () {
            $parts = array_filter([
                $this->first_name,
                $this->middle_name,
                $this->last_name,
                $this->suffix,
            ]);

            return implode(' ', $parts);
        });
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function applications(): BelongsToMany
    {
        return $this->belongsToMany(Application::class, 'employee_application')
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

    public function revokeSessions(?string $exceptTokenHash = null): void
    {
        $this->tokens()->whereNull('revoked_at')
            ->when($exceptTokenHash, fn ($query) => $query->where('access_token', '!=', $exceptTokenHash))
            ->update(['revoked_at' => now()]);
        DB::table('sso_authorization_codes')->where('employee_id', $this->id)->delete();
    }

    public function revokeApplicationAccess(Application $application): void
    {
        DB::transaction(function () use ($application): void {
            self::whereKey($this->id)->lockForUpdate()->firstOrFail();
            $this->applications()->detach($application->id);
            $this->tokens()->where('application_id', $application->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            DB::table('sso_authorization_codes')->where('employee_id', $this->id)->where('application_id', $application->id)->delete();
        });
    }

    public function hasAccessTo(Application $application): bool
    {
        return $this->applications()->where('application_id', $application->id)->exists();
    }

    public function getRoleFor(Application $application): ?AppRole
    {
        $pivot = $this->applications()->where('application_id', $application->id)->first();

        return $pivot ? AppRole::from($pivot->pivot->role) : null;
    }

    public static function generateUsername(string $firstName, string $lastName): string
    {
        $firstInitial = strtolower(substr($firstName, 0, 1));
        $normalizedLastName = strtolower(str_replace(' ', '', $lastName));
        $baseUsername = "{$firstInitial}.{$normalizedLastName}";
        $username = $baseUsername;
        $counter = 1;

        while (static::where('username', $username)->exists()) {
            $counter++;
            $username = "{$baseUsername}{$counter}";
        }

        return $username;
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
