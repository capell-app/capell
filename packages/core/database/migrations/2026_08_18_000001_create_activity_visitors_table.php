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
        Schema::create(TableName::resolve('activity_visitors'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained(TableName::resolve('sites'), indexName: TableName::index('activity_visitors', 'site_id', 'foreign'))->cascadeOnDelete();
            $table->string('language', 32);
            $table->date('day');
            $table->string('visitor_hash', 32);
            $table->timestamp('first_seen_at');

            $table->unique(
                ['site_id', 'language', 'day', 'visitor_hash'],
                TableName::namedIndex('activity_visitors', 'activity_visitors_identity_unique'),
            );
            $table->index(['site_id', 'first_seen_at'], TableName::namedIndex('activity_visitors', 'activity_visitors_site_time_index'));
            $table->index('day', TableName::namedIndex('activity_visitors', 'activity_visitors_day_index'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('activity_visitors'));
    }
};
