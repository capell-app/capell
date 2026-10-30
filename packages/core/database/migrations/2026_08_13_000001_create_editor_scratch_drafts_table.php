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
        Schema::create(TableName::resolve('editor_scratch_drafts'), function (Blueprint $table): void {
            $table->id();
            $table->foreignUserId('user_id')->constrained('users', indexName: TableName::index('editor_scratch_drafts', 'user_id', 'foreign'))->cascadeOnDelete();
            $table->foreignId('site_id')->constrained(TableName::resolve('sites'), indexName: TableName::index('editor_scratch_drafts', 'site_id', 'foreign'))->cascadeOnDelete();
            $table->string('locale', 40);
            $table->string('record_type', 120);
            $table->unsignedBigInteger('record_id');
            $table->string('context', 80);
            $table->json('payload');
            $table->char('content_hash', 64);
            $table->timestamp('saved_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['user_id', 'site_id', 'locale', 'record_type', 'record_id', 'context'],
                TableName::namedIndex('editor_scratch_drafts', 'editor_scratch_drafts_identity_unique'),
            );
            $table->index(['user_id', 'expires_at'], TableName::namedIndex('editor_scratch_drafts', 'editor_scratch_drafts_user_expiry_index'));
            $table->index(['record_type', 'record_id'], TableName::namedIndex('editor_scratch_drafts', 'editor_scratch_drafts_record_index'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('editor_scratch_drafts'));
    }
};
