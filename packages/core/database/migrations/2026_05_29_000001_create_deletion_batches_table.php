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
        Schema::create(TableName::resolve('deletion_batches'), function (Blueprint $table): void {
            $table->id();
            $table->string('root_type');
            $table->unsignedBigInteger('root_id');
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
            $table->index(['root_type', 'root_id', 'restored_at'], TableName::index('deletion_batches', ['root_type', 'root_id', 'restored_at'], 'index'));
        });

        Schema::create(TableName::resolve('deletion_batch_records'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deletion_batch_id')->constrained(TableName::resolve('deletion_batches'), indexName: TableName::index('deletion_batch_records', 'deletion_batch_id', 'foreign'))->cascadeOnDelete();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->timestamps();
            $table->index(['model_type', 'model_id'], TableName::index('deletion_batch_records', ['model_type', 'model_id'], 'index'));
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('deletion_batch_records'));
        Schema::dropIfExists(TableName::resolve('deletion_batches'));
    }
};
