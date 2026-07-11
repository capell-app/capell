<?php

declare(strict_types=1);

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
        if (Schema::hasTable('blueprints')) {
            return;
        }

        Schema::create('blueprints', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type', 64);
            $table->string('key', 128);
            $table->string('group')->nullable();
            $table->json('meta')->nullable();
            $table->json('admin')->nullable();
            $table->string('component')->nullable()->index();
            $table->string('component_item')->nullable()->index();
            $table->boolean('is_livewire')->nullable();
            $table->string('view_file')->nullable();
            $table->unsignedInteger('order')->default(0)->index();
            $table->boolean('default')->default(0);
            $table->boolean('status')->default(1);
            $table->userstamps();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['type', 'key']);
            $table->index(['type', 'default']);
            $table->index(['type', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blueprints');
    }
};
