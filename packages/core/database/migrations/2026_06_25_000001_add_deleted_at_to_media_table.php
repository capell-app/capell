<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('media') || Schema::hasColumn('media', 'deleted_at')) {
            return;
        }

        Schema::table('media', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('media') || ! Schema::hasColumn('media', 'deleted_at')) {
            return;
        }

        Schema::table('media', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
