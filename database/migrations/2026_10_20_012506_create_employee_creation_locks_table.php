<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employee_creation_locks', function (Blueprint $table) {
            $table->char('identity_hash', 64)->primary();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->index('birthday');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_creation_locks');

        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['birthday']);
        });
    }
};
