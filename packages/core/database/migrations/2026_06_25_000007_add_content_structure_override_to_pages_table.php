<?php

declare(strict_types=1);

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
        Schema::table('pages', function (Blueprint $table): void {
            $table->string('content_structure_override')->nullable()->after('admin');
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table): void {
            $table->dropColumn('content_structure_override');
        });
    }
};
