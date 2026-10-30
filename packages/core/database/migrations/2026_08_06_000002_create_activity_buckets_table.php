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
        Schema::create(TableName::resolve('activity_buckets'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained(TableName::resolve('sites'), indexName: TableName::index('activity_buckets', 'site_id', 'foreign'))->cascadeOnDelete();
            $table->string('language', 32);
            $table->string('subject_type', 32);
            $table->string('subject_key', 191);
            $table->timestamp('bucket_started_at');
            $table->unsignedBigInteger('count')->default(0);
            $table->timestamps();

            $table->unique(
                ['site_id', 'language', 'subject_type', 'subject_key', 'bucket_started_at'],
                TableName::namedIndex('activity_buckets', 'activity_buckets_identity_unique'),
            );
            $table->index(['site_id', 'language', 'bucket_started_at'], TableName::namedIndex('activity_buckets', 'activity_buckets_site_language_time_index'));
            $table->index(['subject_type', 'bucket_started_at'], TableName::namedIndex('activity_buckets', 'activity_buckets_subject_time_index'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('activity_buckets'));
    }
};
