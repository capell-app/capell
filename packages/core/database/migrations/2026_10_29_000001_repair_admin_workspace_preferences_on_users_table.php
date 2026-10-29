<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'admin_workspace_preferences')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->json('admin_workspace_preferences')->nullable();
        });
    }

    public function down(): void
    {
        // The column may predate this repair and can contain user preferences.
    }
};
