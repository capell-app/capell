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

        if (Schema::hasColumn('users', 'preferred_admin_language_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table
                ->foreignId('preferred_admin_language_id')
                ->nullable()
                ->after('email')
                ->constrained('languages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('users', 'preferred_admin_language_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('preferred_admin_language_id');
        });
    }
};
