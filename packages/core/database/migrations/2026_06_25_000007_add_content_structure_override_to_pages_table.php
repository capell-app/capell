<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the per-page content-structure override column referenced by the Page
 * model accessor (getContentStructureAttribute) and the EditPage mode-switch.
 * Stores a ContentStructure value ('blocks' | 'html'); null falls back to the
 * blueprint default.
 */
return new class extends Migration
{
    public function up(): void
    {
        TableName::existsAndIsOwned('pages', '2026_05_10_190832_12_create_pages_table');

        Schema::table(TableName::resolve('pages'), function (Blueprint $table): void {
            $table->string('content_structure_override')->nullable()->after('admin');
        });
    }

    public function down(): void
    {
        TableName::existsAndIsOwned('pages', '2026_05_10_190832_12_create_pages_table');

        Schema::table(TableName::resolve('pages'), function (Blueprint $table): void {
            $table->dropColumn('content_structure_override');
        });
    }
};
