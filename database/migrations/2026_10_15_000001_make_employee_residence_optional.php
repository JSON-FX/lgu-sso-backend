<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('residence', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        // An image rollback retains the nullable column; reversing this needs a separate data review.
        Schema::table('employees', function (Blueprint $table) {
            $table->string('residence')->nullable(false)->change();
        });
    }
};
