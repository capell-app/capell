<?php

declare(strict_types=1);

use Capell\Core\Enums\Database\DatabaseFamily;
use Capell\Core\Facades\CapellDatabase;
use Capell\Core\Models\Concerns\HasPublishDates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Typed, indexed storage for a page's own property values.
 *
 * Deliberately single-copy per (page, property_definition, translation,
 * position) rather than carrying a draft/published `state` dimension: Core's
 * own page content (title/content/meta on `translations`) has no such
 * duality — visibility is a read-time date gate ({@see HasPublishDates})
 * evaluated against `visible_from`/`visible_until`, not a stored projection
 * swapped at publish time. Property values follow the exact same rule: a
 * value is "published" precisely when its owning page currently satisfies
 * `Page::published()`, checked at read time by the agent-layer resolver
 * (Phase 2), not by a duplicated row here. See the CAP-0460 Task 0
 * assumption-check note for the full reasoning — this replaces the original
 * plan's draft/published `state` column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_property_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('translation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('property_definition_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 20, 6)->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->dateTime('value_datetime')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('unit')->nullable();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('referenced_page_id')->nullable()->constrained('pages')->nullOnDelete();
            $table->unsignedBigInteger('media_id')->nullable();
            $table->timestamps();

            // Note: because translation_id is nullable, this index only
            // actually enforces uniqueness for localised rows (translation_id
            // NOT NULL) — SQL treats NULL as distinct from NULL in a unique
            // key on every supported driver. The non-localised case is
            // deduplicated at the application layer by
            // SetPagePropertyValuesAction's update-or-create identity
            // resolution, not by this index.
            $table->unique(
                ['page_id', 'property_definition_id', 'translation_id', 'position'],
                'page_property_values_identity_unique',
            );
            $table->index(
                ['site_id', 'property_definition_id', 'value_number'],
                'page_property_values_numeric_lookup',
            );
        });

        // TEXT columns cannot be indexed without a prefix length under
        // MySQL/MariaDB; sqlite (the test-suite driver) and Postgres have no
        // such restriction and reject the `(64)` prefix syntax, so the
        // families need different DDL for the same logical index.
        if (in_array(CapellDatabase::for(DB::connection())->family(), [DatabaseFamily::MySql, DatabaseFamily::MariaDb], true)) {
            DB::statement(
                'ALTER TABLE page_property_values '
                . 'ADD INDEX page_property_values_text_lookup (site_id, property_definition_id, value_text(64))',
            );
        } else {
            Schema::table('page_property_values', function (Blueprint $table): void {
                $table->index(
                    ['site_id', 'property_definition_id', 'value_text'],
                    'page_property_values_text_lookup',
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('page_property_values');
    }
};
