<?php

declare(strict_types=1);

use Capell\Core\Enums\Database\DatabaseFamily;
use Capell\Core\Facades\CapellDatabase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of `page_property_values`, owned by a term instead of a page. Terms
 * are not drafted in v1 (see plan), so there is no translation/state
 * dimension at all here — a term's structured data is always live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('term_property_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_definition_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 20, 6)->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->dateTime('value_datetime')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('unit')->nullable();
            $table->foreignId('referenced_term_id')->nullable()->constrained('terms')->nullOnDelete();
            $table->foreignId('referenced_page_id')->nullable()->constrained('pages')->nullOnDelete();
            $table->unsignedBigInteger('media_id')->nullable();
            $table->timestamps();

            $table->unique(
                ['term_id', 'property_definition_id', 'position'],
                'term_property_values_identity_unique',
            );
            $table->index(
                ['property_definition_id', 'value_number'],
                'term_property_values_numeric_lookup',
            );
        });

        if (in_array(CapellDatabase::for(DB::connection())->family(), [DatabaseFamily::MySql, DatabaseFamily::MariaDb], true)) {
            DB::statement(
                'ALTER TABLE term_property_values '
                . 'ADD INDEX term_property_values_text_lookup (property_definition_id, value_text(64))',
            );
        } else {
            Schema::table('term_property_values', function (Blueprint $table): void {
                $table->index(
                    ['property_definition_id', 'value_text'],
                    'term_property_values_text_lookup',
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('term_property_values');
    }
};
