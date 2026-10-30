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
        if (TableName::existsAndIsOwned('redirect_health_snapshots', basename(__FILE__, '.php'))) {
            return;
        }

        Schema::create(TableName::resolve('redirect_health_snapshots'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('page_url_id')->constrained(TableName::resolve('page_urls'), indexName: TableName::index('redirect_health_snapshots', 'page_url_id', 'foreign'))->cascadeOnDelete();
            $table->text('source_url');
            $table->text('target_url')->nullable();
            $table->boolean('has_chain')->default(false);
            $table->boolean('has_loop')->default(false);
            $table->unsignedSmallInteger('warning_count')->default(0);
            $table->unsignedSmallInteger('error_count')->default(0);
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique('page_url_id', TableName::index('redirect_health_snapshots', 'page_url_id', 'unique'));
            $table->index(['has_chain', 'has_loop'], TableName::index('redirect_health_snapshots', ['has_chain', 'has_loop'], 'index'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('redirect_health_snapshots'));
    }
};
