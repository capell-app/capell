<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('blueprint_schema_snapshots')) {
            return;
        }

        Schema::create('blueprint_schema_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('blueprint_id')->constrained('blueprints')->cascadeOnDelete();
            $table->string('blueprint_key');
            $table->string('blueprint_type');
            $table->timestamp('taken_at')->index();
            $table->string('reason');
            $table->longText('admin_before')->nullable();
            $table->longText('meta_before')->nullable();
            $table->string('type_before')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['blueprint_id', 'taken_at'], 'blueprint_schema_snapshots_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blueprint_schema_snapshots');
    }
};
