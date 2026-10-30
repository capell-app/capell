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
        Schema::create(TableName::resolve('themes'), function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('blueprint_id')->constrained(TableName::resolve('blueprints'), indexName: TableName::index('themes', 'blueprint_id', 'foreign'));
            $table->string('key', 128);
            $table->string('active_key', 128)->nullable()->unique(TableName::index('themes', 'active_key', 'unique'));
            $table->longText('custom_css')->nullable();
            $table->json('meta')->nullable();
            $table->json('meta_extra')->nullable();
            $table->json('admin')->nullable();
            $table->unsignedInteger('order')->default(0)->index(TableName::index('themes', 'order', 'index'));
            $table->boolean('default')->index(TableName::index('themes', 'default', 'index'))->default(0);
            $table->boolean('status')->index(TableName::index('themes', 'status', 'index'))->default(1);
            $table->userstamps();
            $table->timestamps();
            $table->unique(['key', 'deleted_at'], TableName::index('themes', ['key', 'deleted_at'], 'unique'));
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('themes'));
    }
};
