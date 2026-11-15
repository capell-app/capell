<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each translation remembers a fingerprint of every default-language field as it stood when the translation was
 * last saved, so the editor can flag only the fields whose source changed since.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! TableName::existsAndIsOwned('translations', '2026_05_10_190832_07_create_translations_table') || Schema::hasColumn(TableName::resolve('translations'), 'source_fingerprints')) {
            return;
        }

        Schema::table(TableName::resolve('translations'), function (Blueprint $table): void {
            $table->json('source_fingerprints')->nullable();
        });
    }

    public function down(): void
    {
        if (! TableName::existsAndIsOwned('translations', '2026_05_10_190832_07_create_translations_table') || ! Schema::hasColumn(TableName::resolve('translations'), 'source_fingerprints')) {
            return;
        }

        Schema::table(TableName::resolve('translations'), function (Blueprint $table): void {
            $table->dropColumn('source_fingerprints');
        });
    }
};
