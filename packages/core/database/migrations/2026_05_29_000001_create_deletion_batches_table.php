<?php

declare(strict_types=1);

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
        Schema::create('deletion_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('root_type');
            $table->unsignedBigInteger('root_id');
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
            $table->index(['root_type', 'root_id', 'restored_at']);
        });

        Schema::create('deletion_batch_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deletion_batch_id')->constrained()->cascadeOnDelete();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->timestamps();
            $table->index(['model_type', 'model_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deletion_batch_records');
        Schema::dropIfExists('deletion_batches');
    }
};
