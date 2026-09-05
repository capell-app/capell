<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blueprint_property_sets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('blueprint_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_set_id')->constrained()->cascadeOnDelete();
            $table->json('overrides')->nullable();
            $table->timestamps();

            $table->unique(['blueprint_id', 'property_set_id'], 'blueprint_property_sets_pair_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blueprint_property_sets');
    }
};
