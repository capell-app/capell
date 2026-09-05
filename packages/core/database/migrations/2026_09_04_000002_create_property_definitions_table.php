<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('property_set_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('type');
            $table->string('semantic')->nullable();
            $table->string('requirement')->default('none');
            $table->boolean('agent_visible')->default(true);
            $table->boolean('localised')->default(false);
            $table->boolean('multiple')->default(false);
            $table->boolean('locked')->default(false);
            $table->text('description')->nullable();
            $table->json('unit_config')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['property_set_id', 'key'], 'property_definitions_set_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_definitions');
    }
};
