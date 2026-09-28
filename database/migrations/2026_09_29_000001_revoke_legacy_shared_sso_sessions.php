<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('oauth_tokens')->whereNull('revoked_at')->update(['revoked_at' => now()]);
        DB::table('sso_authorization_codes')->delete();
    }

    public function down(): void
    {
        // Revoked credentials must never be restored by a rollback.
    }
};
