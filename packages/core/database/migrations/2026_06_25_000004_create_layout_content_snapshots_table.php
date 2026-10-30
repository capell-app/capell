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
        if (TableName::existsAndIsOwned('layout_content_snapshots', basename(__FILE__, '.php'))) {
            return;
        }

        Schema::create(TableName::resolve('layout_content_snapshots'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('layout_id')->constrained(TableName::resolve('layouts'), indexName: TableName::index('layout_content_snapshots', 'layout_id', 'foreign'))->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained(TableName::resolve('sites'), indexName: TableName::index('layout_content_snapshots', 'site_id', 'foreign'))->nullOnDelete();
            $table->foreignId('theme_id')->nullable()->constrained(TableName::resolve('themes'), indexName: TableName::index('layout_content_snapshots', 'theme_id', 'foreign'))->nullOnDelete();
            $table->timestamp('taken_at')->index(TableName::index('layout_content_snapshots', 'taken_at', 'index'));
            $table->string('reason');
            $table->longText('containers_before')->nullable();
            $table->longText('admin_before')->nullable();
            $table->longText('meta_before')->nullable();
            $table->longText('elements_before')->nullable();
            $table->foreignUserId('actor_id')->nullable()->constrained('users', indexName: TableName::index('layout_content_snapshots', 'actor_id', 'foreign'))->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['layout_id', 'taken_at'], TableName::namedIndex('layout_content_snapshots', 'layout_content_snapshots_lookup_index'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('layout_content_snapshots'));
    }
};
