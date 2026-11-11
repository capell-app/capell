<?php

declare(strict_types=1);

use Capell\Core\Data\UpgradeContext;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeKind;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeStrength;
use Capell\Core\Models\ContentGraphEdge;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\Term;
use Capell\Core\Models\TermPropertyValue;
use Capell\Core\Support\TableName;
use Capell\Core\Support\Upgrade\IndexPropertyValueReferencesUpgradeStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function hasMediaForeignKey(string $baseTable): bool
{
    foreach (Schema::getForeignKeys(TableName::resolve($baseTable)) as $foreignKey) {
        if ($foreignKey['columns'] === ['media_id']) {
            return true;
        }
    }

    return false;
}

it('nulls dangling media references before adding the foreign key and is reversible', function (): void {
    $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_11_11_000010_add_media_foreign_key_to_property_values_tables.php';
    $migration->down();

    expect(hasMediaForeignKey('page_property_values'))->toBeFalse()
        ->and(hasMediaForeignKey('term_property_values'))->toBeFalse();

    $page = Page::factory()->create();
    $trashed = Media::factory()->model($page)->create();
    $trashed->delete();

    $dangling = PagePropertyValue::withoutEvents(fn (): PagePropertyValue => PagePropertyValue::factory()->create([
        'site_id' => $page->site_id, 'page_id' => $page->id, 'value_text' => null, 'media_id' => 999_999,
    ]));
    $kept = PagePropertyValue::withoutEvents(fn (): PagePropertyValue => PagePropertyValue::factory()->create([
        'site_id' => $page->site_id, 'page_id' => $page->id, 'value_text' => null, 'media_id' => $trashed->id, 'position' => 1,
    ]));
    $danglingTerm = TermPropertyValue::withoutEvents(fn (): TermPropertyValue => TermPropertyValue::factory()->create([
        'value_text' => null, 'media_id' => 999_998,
    ]));

    $migration->up();
    $migration->up();

    expect($dangling->fresh()?->media_id)->toBeNull()
        ->and($danglingTerm->fresh()?->media_id)->toBeNull()
        ->and($kept->fresh()?->media_id)->toBe($trashed->id)
        ->and(hasMediaForeignKey('page_property_values'))->toBeTrue()
        ->and(hasMediaForeignKey('term_property_values'))->toBeTrue();
});

it('leaves a media table Capell did not record creating unconstrained instead of failing the upgrade', function (): void {
    $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_11_11_000010_add_media_foreign_key_to_property_values_tables.php';
    $migration->down();

    DB::table((string) config('database.migrations.table', 'migrations'))
        ->where('migration', 'like', '%create_media_table%')
        ->delete();

    $migration->up();

    expect(hasMediaForeignKey('page_property_values'))->toBeFalse()
        ->and(hasMediaForeignKey('term_property_values'))->toBeFalse();
});

/** @return list<string> names of the indexes whose leading column is media_id */
function mediaIdIndexes(string $baseTable): array
{
    return array_values(array_map(
        static fn (array $index): string => $index['name'],
        array_filter(
            Schema::getIndexes(TableName::resolve($baseTable)),
            static fn (array $index): bool => ($index['columns'][0] ?? null) === 'media_id',
        ),
    ));
}

it('keeps media_id indexed on every driver and never drops the index a foreign key owns', function (): void {
    $index = require dirname(__DIR__, 2) . '/database/migrations/2026_11_11_000011_add_media_id_index_to_property_values_tables.php';

    foreach (['page_property_values', 'term_property_values'] as $baseTable) {
        $ownName = TableName::index($baseTable, 'media_id', 'index');
        $before = mediaIdIndexes($baseTable);

        // MySQL and MariaDB index the foreign key column themselves, so the migration adds nothing there.
        expect($before)->not->toBeEmpty();

        $index->down();

        expect(mediaIdIndexes($baseTable))->toBe(array_values(array_diff($before, [$ownName])))
            ->and(hasMediaForeignKey($baseTable))->toBeTrue();

        $index->up();
        $index->up();

        expect(mediaIdIndexes($baseTable))->toEqualCanonicalizing($before);
    }
});

it('adds and removes its own media_id index where no foreign key indexes the column', function (): void {
    $foreignKey = require dirname(__DIR__, 2) . '/database/migrations/2026_11_11_000010_add_media_foreign_key_to_property_values_tables.php';
    $index = require dirname(__DIR__, 2) . '/database/migrations/2026_11_11_000011_add_media_id_index_to_property_values_tables.php';

    $index->down();

    $foreignKey->down();

    foreach (['page_property_values', 'term_property_values'] as $baseTable) {
        expect(mediaIdIndexes($baseTable))->toBe([])
            ->and(hasMediaForeignKey($baseTable))->toBeFalse();
    }

    $index->up();

    foreach (['page_property_values', 'term_property_values'] as $baseTable) {
        expect(mediaIdIndexes($baseTable))->toBe([TableName::index($baseTable, 'media_id', 'index')]);
    }

    $index->down();

    foreach (['page_property_values', 'term_property_values'] as $baseTable) {
        expect(mediaIdIndexes($baseTable))->toBe([]);
    }

    $foreignKey->up();
    $index->up();

    foreach (['page_property_values', 'term_property_values'] as $baseTable) {
        expect(hasMediaForeignKey($baseTable))->toBeTrue()
            ->and(mediaIdIndexes($baseTable))->not->toBeEmpty();
    }
});

it('skips the reference index upgrade when no property value holds a reference', function (): void {
    PagePropertyValue::factory()->create();

    expect((new IndexPropertyValueReferencesUpgradeStep)->shouldRun(new UpgradeContext(composerVersions: [], ledgerVersions: [], appliedStepIds: [])))->toBeFalse();
});

it('indexes references that existing property values already hold during the upgrade', function (): void {
    $page = Page::factory()->create();
    $media = Media::factory()->model($page)->create();
    $term = Term::factory()->create();
    $definition = PropertyDefinition::factory()->create();
    PagePropertyValue::withoutEvents(fn (): PagePropertyValue => PagePropertyValue::factory()->create([
        'site_id' => $page->site_id, 'page_id' => $page->id, 'property_definition_id' => $definition->id, 'value_text' => null, 'media_id' => $media->id,
    ]));
    TermPropertyValue::withoutEvents(fn (): TermPropertyValue => TermPropertyValue::factory()->create([
        'term_id' => $term->id, 'property_definition_id' => $definition->id, 'value_text' => null, 'referenced_page_id' => $page->id,
    ]));
    ContentGraphEdge::query()->where('kind', ContentGraphEdgeKind::ReferencedByProperty->value)->delete();

    $context = new UpgradeContext(composerVersions: [], ledgerVersions: [], appliedStepIds: []);
    $step = collect(app()->tagged('capell.upgrade-steps'))
        ->first(fn (object $step): bool => $step instanceof IndexPropertyValueReferencesUpgradeStep);

    expect($step)->toBeInstanceOf(IndexPropertyValueReferencesUpgradeStep::class)
        ->and($step?->label())->toBe(__('capell-core::properties.upgrade.index_references'))
        ->and($step?->shouldRun($context))->toBeTrue()
        ->and($step?->run($context))->toBeTrue();

    $edges = ContentGraphEdge::query()->where('kind', ContentGraphEdgeKind::ReferencedByProperty->value)->get();

    expect($edges)->toHaveCount(2)
        ->and($edges->firstWhere('source_type', Page::class)?->target_id)->toBe($media->id)
        ->and($edges->firstWhere('source_type', Term::class)?->target_id)->toBe($page->id)
        ->and($edges->every(fn (ContentGraphEdge $edge): bool => $edge->strength === ContentGraphEdgeStrength::Weak))->toBeTrue();
});

it('resolves the configured name of an external table', function (): void {
    config()->set('capell.database.external_tables.media', 'capell_media_files');

    expect(TableName::external('media'))->toBe('capell_media_files')
        ->and(TableName::external('stored_events'))->toBe('stored_events');

    config()->set('capell.database.external_tables.media', 'bad name;');

    expect(fn (): string => TableName::external('media'))->toThrow(RuntimeException::class);
});
