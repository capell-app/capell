<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editor_scratch_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('locale', 40);
            $table->string('record_type', 120);
            $table->unsignedBigInteger('record_id');
            $table->string('context', 80);
            $table->json('payload');
            $table->char('content_hash', 64);
            $table->timestamp('saved_at');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(
                ['user_id', 'site_id', 'locale', 'record_type', 'record_id', 'context'],
                'editor_scratch_drafts_identity_unique',
            );
            $table->index(['user_id', 'expires_at'], 'editor_scratch_drafts_user_expiry_index');
            $table->index(['record_type', 'record_id'], 'editor_scratch_drafts_record_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editor_scratch_drafts');
    }
};
