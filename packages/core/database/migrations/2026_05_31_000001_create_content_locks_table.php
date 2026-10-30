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
        Schema::create(TableName::resolve('content_locks'), function (Blueprint $table): void {
            $table->id();
            $table->foreignUserId('user_id')->constrained('users', indexName: TableName::index('content_locks', 'user_id', 'foreign'))->cascadeOnDelete();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['model_type', 'model_id'], TableName::index('content_locks', ['model_type', 'model_id'], 'unique'));
            $table->index(['user_id', 'expires_at'], TableName::index('content_locks', ['user_id', 'expires_at'], 'index'));
            $table->index('expires_at', TableName::index('content_locks', 'expires_at', 'index'));
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('content_locks'));
    }
};
