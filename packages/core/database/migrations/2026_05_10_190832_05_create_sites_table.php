<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
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
        Schema::create(TableName::resolve('sites'), function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('blueprint_id')->constrained(TableName::resolve('blueprints'), indexName: TableName::index('sites', 'blueprint_id', 'foreign'));
            $table->foreignId('theme_id')->constrained(TableName::resolve('themes'), indexName: TableName::index('sites', 'theme_id', 'foreign'));
            $table->foreignId('language_id')->constrained(TableName::resolve('languages'), indexName: TableName::index('sites', 'language_id', 'foreign'));
            $table->json('meta')->nullable();
            $table->json('admin')->nullable();
            $table->unsignedInteger('order')->default(0)->index(TableName::index('sites', 'order', 'index'));
            $table->boolean('default')->index(TableName::index('sites', 'default', 'index'))->default(0);
            $table->boolean('status')->index(TableName::index('sites', 'status', 'index'))->default(1);
            $table->userstamps();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('sites'));
    }
};
