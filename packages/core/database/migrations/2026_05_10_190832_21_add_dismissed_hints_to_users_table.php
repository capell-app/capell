<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (Schema::hasColumn('users', 'dismissed_hints')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->json('dismissed_hints')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('users', 'dismissed_hints')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('dismissed_hints');
        });
    }
};
