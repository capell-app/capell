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
        Schema::create(TableName::resolve('taxonomies'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained(TableName::resolve('sites'), indexName: TableName::index('taxonomies', 'site_id', 'foreign'))->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->boolean('hierarchical')->default(false);
            $table->foreignId('property_set_id')->nullable()->constrained(TableName::resolve('property_sets'), indexName: TableName::index('taxonomies', 'property_set_id', 'foreign'))->nullOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['site_id', 'key'], TableName::namedIndex('taxonomies', 'taxonomies_site_key_unique'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('taxonomies'));
    }
};
