<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Filament's database notifications need this table, and nothing else in the
 * workbench supplies it.
 *
 * `notifications:table` generates one into database_path('migrations'), which
 * under this workbench is workbench/database/migrations — a directory that
 * testbench.yaml does not register, so the generated migration never ran and
 * the table was silently absent. This path IS registered, so provide it here
 * instead of relying on generated output landing somewhere that executes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notifications')) {
            return;
        }

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
