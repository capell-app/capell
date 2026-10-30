<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(TableName::resolve('blueprint_property_sets'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('blueprint_id')->constrained(TableName::resolve('blueprints'), indexName: TableName::index('blueprint_property_sets', 'blueprint_id', 'foreign'))->cascadeOnDelete();
            $table->foreignId('property_set_id')->constrained(TableName::resolve('property_sets'), indexName: TableName::index('blueprint_property_sets', 'property_set_id', 'foreign'))->cascadeOnDelete();
            $table->json('overrides')->nullable();
            $table->timestamps();

            $table->unique(['blueprint_id', 'property_set_id'], TableName::namedIndex('blueprint_property_sets', 'blueprint_property_sets_pair_unique'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('blueprint_property_sets'));
    }
};
