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

        if (Schema::hasColumn('users', 'bio')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->text('bio')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('users', 'bio')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('bio');
        });
    }
};
