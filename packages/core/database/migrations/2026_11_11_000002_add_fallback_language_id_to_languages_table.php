<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives each language an optional fallback language. When content has no
 * translation in the requested language, ResolveTranslationAction walks this
 * chain instead of returning an arbitrary translation row. The column lives on
 * languages because sites have no site-language pivot: a site's languages are
 * derived from its domains, so a per-site chain would have nowhere to live.
 * Nullable, so existing languages keep today's behaviour (no fallback).
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = TableName::resolve('languages');

        if (Schema::hasColumn($table, 'fallback_language_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            $blueprint->foreignId('fallback_language_id')
                ->nullable()
                ->after('locale')
                ->constrained($table, indexName: TableName::index('languages', 'fallback_language_id', 'foreign'))
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        $table = TableName::resolve('languages');

        if (! Schema::hasColumn($table, 'fallback_language_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            // SQLite can only drop a foreign key by its column list; other drivers
            // take the (possibly hashed) constraint name the up() migration used.
            $blueprint->dropForeign(Schema::getConnection()->getDriverName() === 'sqlite'
                ? ['fallback_language_id']
                : TableName::index('languages', 'fallback_language_id', 'foreign'));
            $blueprint->dropColumn('fallback_language_id');
        });
    }
};
