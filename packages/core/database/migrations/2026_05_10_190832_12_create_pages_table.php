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
        Schema::create(TableName::resolve('pages'), function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->nullable();
            $table->string('name');
            $table->foreignId('blueprint_id')->constrained(TableName::resolve('blueprints'), indexName: TableName::index('pages', 'blueprint_id', 'foreign'));
            $table->foreignId('layout_id')->constrained(TableName::resolve('layouts'), indexName: TableName::index('pages', 'layout_id', 'foreign'));
            $table->foreignId('site_id')->constrained(TableName::resolve('sites'), indexName: TableName::index('pages', 'site_id', 'foreign'))->cascadeOnDelete();
            $table->json('meta')->nullable();
            $table->json('admin')->nullable();
            $table->visibleDates();
            $table->unsignedInteger('order')->nullable();
            $table->userstamps();
            $table->timestamps();
            $table->softDeletes();
            $table->nestedSet();
            $table->nestedSetDepth();
            $table->index(['parent_id', '_lft'], TableName::index('pages', ['parent_id', '_lft'], 'index'));
            $table->index(['_lft', '_rgt'], TableName::index('pages', ['_lft', '_rgt'], 'index'));
            $table->index(['_rgt'], TableName::index('pages', ['_rgt'], 'index'));
            $table->index('uuid', TableName::index('pages', 'uuid', 'index'));
            $table->index(['site_id', 'blueprint_id'], TableName::index('pages', ['site_id', 'blueprint_id'], 'index'));
            $table->index(['site_id', 'order'], TableName::index('pages', ['site_id', 'order'], 'index'));
            $table->index(['site_id', 'visible_from'], TableName::index('pages', ['site_id', 'visible_from'], 'index'));
            $table->index(['site_id', 'visible_until'], TableName::index('pages', ['site_id', 'visible_until'], 'index'));
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('pages'));
    }
};
