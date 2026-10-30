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
        if (TableName::existsAndIsOwned('blueprint_schema_snapshots', basename(__FILE__, '.php'))) {
            return;
        }

        Schema::create(TableName::resolve('blueprint_schema_snapshots'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('blueprint_id')->constrained(TableName::resolve('blueprints'), indexName: TableName::index('blueprint_schema_snapshots', 'blueprint_id', 'foreign'))->cascadeOnDelete();
            $table->string('blueprint_key');
            $table->string('blueprint_type');
            $table->timestamp('taken_at')->index(TableName::index('blueprint_schema_snapshots', 'taken_at', 'index'));
            $table->string('reason');
            $table->longText('admin_before')->nullable();
            $table->longText('meta_before')->nullable();
            $table->string('type_before')->nullable();
            $table->foreignUserId('actor_id')->nullable()->constrained('users', indexName: TableName::index('blueprint_schema_snapshots', 'actor_id', 'foreign'))->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['blueprint_id', 'taken_at'], TableName::namedIndex('blueprint_schema_snapshots', 'blueprint_schema_snapshots_lookup_index'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('blueprint_schema_snapshots'));
    }
};
