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
        if (TableName::existsAndIsOwned('layouts', basename(__FILE__, '.php'))) {
            return;
        }

        Schema::create(TableName::resolve('layouts'), function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('theme_id')->nullable()->constrained(TableName::resolve('themes'), indexName: TableName::index('layouts', 'theme_id', 'foreign'))->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained(TableName::resolve('sites'), indexName: TableName::index('layouts', 'site_id', 'foreign'))->nullOnDelete();
            $table->string('key', 128);
            $table->string('group', 64)->nullable();
            $table->json('meta')->nullable();
            $table->json('admin')->nullable();
            $table->json('containers')->nullable();
            $table->json('elements')->nullable();
            $table->unsignedInteger('order')->default(0)->index(TableName::index('layouts', 'order', 'index'));
            $table->boolean('default')->index(TableName::index('layouts', 'default', 'index'))->default(0);
            $table->boolean('status')->index(TableName::index('layouts', 'status', 'index'))->default(1);
            $table->userstamps();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['key', 'deleted_at'], TableName::index('layouts', ['key', 'deleted_at'], 'unique'));
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('layouts'));
    }
};
