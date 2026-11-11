<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the declarative validation rules a property definition carries (ranges,
 * lengths, patterns, uniqueness, allowed reference targets and media limits).
 * The column is nullable: definitions written before this migration have no
 * rules and keep accepting every value their type allows.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = TableName::resolve('property_definitions');

        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            if (! Schema::hasColumn($table, 'constraints')) {
                $blueprint->json('constraints')->nullable()->after('unit_config');
            }
        });
    }

    public function down(): void
    {
        $table = TableName::resolve('property_definitions');

        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            if (Schema::hasColumn($table, 'constraints')) {
                $blueprint->dropColumn('constraints');
            }
        });
    }
};
