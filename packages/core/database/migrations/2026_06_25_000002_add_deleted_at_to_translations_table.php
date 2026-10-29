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
        if (! TableName::existsAndIsOwned('translations', '2026_05_10_190832_07_create_translations_table') || Schema::hasColumn(TableName::resolve('translations'), 'deleted_at')) {
            return;
        }

        Schema::table(TableName::resolve('translations'), function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (! TableName::existsAndIsOwned('translations', '2026_05_10_190832_07_create_translations_table') || ! Schema::hasColumn(TableName::resolve('translations'), 'deleted_at')) {
            return;
        }

        Schema::table(TableName::resolve('translations'), function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
