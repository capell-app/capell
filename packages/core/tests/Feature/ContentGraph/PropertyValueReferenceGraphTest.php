<?php

declare(strict_types=1);

use Capell\Core\Actions\ContentGraph\BuildContentGraphForModelAction;
use Capell\Core\Actions\ContentGraph\BuildContentImpactPreviewAction;
use Capell\Core\Actions\ContentGraph\SyncPropertyReferenceGraphEdgesAction;
use Capell\Core\Actions\HasRetainedDeletionDependenciesAction;
use Capell\Core\Actions\Properties\BuildPageSchemaGraphAction;
use Capell\Core\Actions\Properties\SetPagePropertyValuesAction;
use Capell\Core\Data\ContentGraph\ContentGraphEdgeData;
use Capell\Core\Data\Properties\PropertyValueData;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeKind;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeStrength;
use Capell\Core\Enums\PropertyRequirement;
use Capell\Core\Enums\PropertyType;
use Capell\Core\Models\BlueprintPropertySet;
use Capell\Core\Models\ContentGraphEdge;
use Capell\Core\Models\Language;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;
use Capell\Core\Models\Taxonomy;
use Capell\Core\Models\Term;
use Capell\Core\Models\TermPropertyValue;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * @param  array<string, array{0: PropertyType, 1: PropertyRequirement}>  $definitions
 * @return array<string, PropertyDefinition>
 */
function attachReferenceProperties(Page $page, array $definitions, array $overrides = []): array
{
    $set = PropertySet::factory()->create(['key' => 'test.references']);
    $created = [];

    foreach ($definitions as $key => [$type, $requirement]) {
        $created[$key] = PropertyDefinition::factory()->create([
            'property_set_id' => $set->id,
            'key' => $key,
            'type' => $type,
            'requirement' => $requirement,
            'agent_visible' => true,
            'semantic' => $type === PropertyType::Media ? 'schema:image' : null,
        ]);
    }

    BlueprintPropertySet::factory()->create([
        'blueprint_id' => $page->blueprint_id,
        'property_set_id' => $set->id,
        'overrides' => $overrides,
    ]);

    return $created;
}

function pagePropertyValue(Page $page, PropertyDefinition $definition, array $attributes, int $position = 0): PagePropertyValue
{
    return PagePropertyValue::factory()->create([
        'site_id' => $page->site_id,
        'page_id' => $page->id,
        'property_definition_id' => $definition->id,
        'translation_id' => null,
        'position' => $position,
        'value_text' => null,
        ...$attributes,
    ]);
}

/** @param array<int, ContentGraphEdgeData> $edges */
function propertyReferenceEdge(array $edges, string $targetType, int $targetId): ?ContentGraphEdgeData
{
    foreach ($edges as $edge) {
        if ($edge->kind === ContentGraphEdgeKind::ReferencedByProperty
            && $edge->target->modelType === $targetType
            && $edge->target->modelId === $targetId) {
            return $edge;
        }
    }

    return null;
}

/** @param class-string<Model> $targetType */
function storedPropertyReference(Model $source, string $targetType, int $targetId): ?ContentGraphEdge
{
    return ContentGraphEdge::query()
        ->where('source_type', $source::class)
        ->where('source_id', $source->getKey())
        ->where('target_type', $targetType)
        ->where('target_id', $targetId)
        ->where('kind', ContentGraphEdgeKind::ReferencedByProperty->value)
        ->first();
}

it('indexes page property references with publish-required properties as strong edges', function (): void {
    $page = Page::factory()->create();
    $referencedPage = Page::factory()->site($page->site)->create();
    $term = Term::factory()->create();
    $media = Media::factory()->model($referencedPage)->create();
    $definitions = attachReferenceProperties($page, [
        'hero' => [PropertyType::Media, PropertyRequirement::Publish],
        'gallery' => [PropertyType::Media, PropertyRequirement::None],
        'related' => [PropertyType::EntryReference, PropertyRequirement::Contract],
        'colour' => [PropertyType::TermReference, PropertyRequirement::None],
        'itself' => [PropertyType::EntryReference, PropertyRequirement::Publish],
    ]);
    pagePropertyValue($page, $definitions['hero'], ['media_id' => $media->id]);
    pagePropertyValue($page, $definitions['gallery'], ['media_id' => $media->id]);
    pagePropertyValue($page, $definitions['related'], ['referenced_page_id' => $referencedPage->id]);
    pagePropertyValue($page, $definitions['colour'], ['term_id' => $term->id]);
    pagePropertyValue($page, $definitions['itself'], ['referenced_page_id' => $page->id]);

    $edges = BuildContentGraphForModelAction::run($page)->edges;
    $mediaEdge = propertyReferenceEdge($edges, Media::class, $media->id);

    expect($mediaEdge?->strength)->toBe(ContentGraphEdgeStrength::Strong)
        ->and($mediaEdge?->siteId)->toBe($page->site_id)
        ->and($mediaEdge?->metadata)->toBe(['properties' => ['test.references.gallery', 'test.references.hero']])
        ->and(propertyReferenceEdge($edges, Page::class, $referencedPage->id)?->strength)->toBe(ContentGraphEdgeStrength::Weak)
        ->and(propertyReferenceEdge($edges, Term::class, $term->id)?->strength)->toBe(ContentGraphEdgeStrength::Weak)
        ->and(propertyReferenceEdge($edges, Page::class, $page->id))->toBeNull();
});

it('uses the blueprint requirement override when deciding edge strength', function (): void {
    $page = Page::factory()->create();
    $media = Media::factory()->model($page)->create();
    $definitions = attachReferenceProperties(
        $page,
        ['hero' => [PropertyType::Media, PropertyRequirement::None]],
        ['hero' => ['requirement' => PropertyRequirement::Publish->value]],
    );
    pagePropertyValue($page, $definitions['hero'], ['media_id' => $media->id]);

    $edge = propertyReferenceEdge(BuildContentGraphForModelAction::run($page)->edges, Media::class, $media->id);

    expect($edge?->strength)->toBe(ContentGraphEdgeStrength::Strong);
});

it('indexes term property references with the taxonomy site', function (): void {
    $taxonomy = Taxonomy::factory()->create();
    $term = Term::factory()->create(['taxonomy_id' => $taxonomy->id]);
    $broader = Term::factory()->create(['taxonomy_id' => $taxonomy->id]);
    $page = Page::factory()->create();
    $media = Media::factory()->model($page)->create();
    $definition = PropertyDefinition::factory()->create(['type' => PropertyType::Media, 'requirement' => PropertyRequirement::Publish]);
    $optional = PropertyDefinition::factory()->create(['type' => PropertyType::EntryReference, 'requirement' => PropertyRequirement::None]);
    TermPropertyValue::factory()->create(['term_id' => $term->id, 'property_definition_id' => $definition->id, 'value_text' => null, 'media_id' => $media->id]);
    TermPropertyValue::factory()->create(['term_id' => $term->id, 'property_definition_id' => $optional->id, 'value_text' => null, 'referenced_page_id' => $page->id, 'referenced_term_id' => $broader->id]);

    $edges = BuildContentGraphForModelAction::run($term)->edges;

    expect(propertyReferenceEdge($edges, Media::class, $media->id)?->strength)->toBe(ContentGraphEdgeStrength::Strong)
        ->and(propertyReferenceEdge($edges, Media::class, $media->id)?->siteId)->toBe($taxonomy->site_id)
        ->and(propertyReferenceEdge($edges, Page::class, $page->id)?->strength)->toBe(ContentGraphEdgeStrength::Weak)
        ->and(propertyReferenceEdge($edges, Term::class, $broader->id)?->strength)->toBe(ContentGraphEdgeStrength::Weak);
});

it('keeps the graph current as property values are written and removed', function (): void {
    $this->withoutDefer();
    $page = Page::factory()->create();
    $media = Media::factory()->model($page)->create();
    attachReferenceProperties($page, ['hero' => [PropertyType::Media, PropertyRequirement::None]]);

    SetPagePropertyValuesAction::run($page, [new PropertyValueData('hero', PropertyType::Media, $media->id)]);

    expect(storedPropertyReference($page, Media::class, $media->id)?->strength)->toBe(ContentGraphEdgeStrength::Weak);

    PagePropertyValue::query()->where('page_id', $page->id)->firstOrFail()->delete();

    expect(storedPropertyReference($page, Media::class, $media->id))->toBeNull();
});

it('keeps term graph edges current as term values are written', function (): void {
    $this->withoutDefer();
    $term = Term::factory()->create();
    $page = Page::factory()->create();

    TermPropertyValue::factory()->create(['term_id' => $term->id, 'value_text' => null, 'referenced_page_id' => $page->id]);

    expect(storedPropertyReference($term, Page::class, $page->id))->not->toBeNull();
});

it('lists a media item referenced only by a property value in its delete impact', function (): void {
    $this->withoutDefer();
    $page = Page::factory()->create(['name' => 'Spring catalogue']);
    $media = Media::factory()->model(Page::factory()->create())->create();
    $definitions = attachReferenceProperties($page, ['hero' => [PropertyType::Media, PropertyRequirement::Publish]]);
    pagePropertyValue($page, $definitions['hero'], ['media_id' => $media->id]);

    $preview = BuildContentImpactPreviewAction::run($media);

    expect($preview->blocked)->toBeTrue()
        ->and($preview->strongCount)->toBe(1)
        ->and($preview->groups[0]->dependencies[0]->name)->toBe('Spring catalogue');
});

it('lists a page referenced only by a property value in its delete impact and retains it', function (): void {
    $this->withoutDefer();
    $owner = Page::factory()->create(['name' => 'Buying guide']);
    $target = Page::factory()->site($owner->site)->create();
    $definitions = attachReferenceProperties($owner, ['related' => [PropertyType::EntryReference, PropertyRequirement::None]]);
    pagePropertyValue($owner, $definitions['related'], ['referenced_page_id' => $target->id]);

    $preview = BuildContentImpactPreviewAction::run($target);

    expect($preview->blocked)->toBeFalse()
        ->and($preview->weakCount)->toBe(1)
        ->and($preview->groups[0]->dependencies[0]->name)->toBe('Buying guide')
        ->and(HasRetainedDeletionDependenciesAction::run($target))->toBeTrue();
});

it('keeps a trashed media reference visible and clears it only on permanent deletion', function (): void {
    $this->withoutDefer();
    $page = Page::factory()->create();
    $media = Media::factory()->model(Page::factory()->create())->create();
    $definitions = attachReferenceProperties($page, ['hero' => [PropertyType::Media, PropertyRequirement::None]]);
    $value = pagePropertyValue($page, $definitions['hero'], ['media_id' => $media->id]);

    $media->delete();

    expect($value->fresh()?->media_id)->toBe($media->id)
        ->and(storedPropertyReference($page, Media::class, $media->id))->not->toBeNull()
        ->and(BuildContentImpactPreviewAction::run($media)->weakCount)->toBe(1);

    $media->forceDelete();

    expect($value->fresh()?->media_id)->toBeNull()
        ->and(storedPropertyReference($page, Media::class, $media->id))->toBeNull();
});

it('releases term property references when media is permanently deleted', function (): void {
    $this->withoutDefer();
    $term = Term::factory()->create();
    $media = Media::factory()->model(Page::factory()->create())->create();
    $value = TermPropertyValue::factory()->create(['term_id' => $term->id, 'value_text' => null, 'media_id' => $media->id]);

    $media->forceDelete();

    expect($value->fresh()?->media_id)->toBeNull()
        ->and(storedPropertyReference($term, Media::class, $media->id))->toBeNull();
});

it('nulls the media reference in the database when a media row is deleted directly', function (): void {
    $page = Page::factory()->create();
    $media = Media::factory()->model($page)->create();
    $definitions = attachReferenceProperties($page, ['hero' => [PropertyType::Media, PropertyRequirement::None]]);
    $value = pagePropertyValue($page, $definitions['hero'], ['media_id' => $media->id]);
    $termValue = TermPropertyValue::factory()->create(['value_text' => null, 'media_id' => $media->id]);

    DB::table($media->getTable())->where('id', $media->id)->delete();

    expect($value->fresh()?->media_id)->toBeNull()
        ->and($termValue->fresh()?->media_id)->toBeNull();
});

it('rejects a property value pointing at a media row that does not exist', function (): void {
    $page = Page::factory()->create();
    $definitions = attachReferenceProperties($page, ['hero' => [PropertyType::Media, PropertyRequirement::None]]);

    expect(fn (): PagePropertyValue => pagePropertyValue($page, $definitions['hero'], ['media_id' => 999_999]))
        ->toThrow(QueryException::class);
});

it('counts each page or term using a media item through property values once', function (): void {
    $page = Page::factory()->create();
    $media = Media::factory()->model($page)->create();
    $definitions = attachReferenceProperties($page, ['gallery' => [PropertyType::Media, PropertyRequirement::None]]);
    pagePropertyValue($page, $definitions['gallery'], ['media_id' => $media->id]);
    pagePropertyValue($page, $definitions['gallery'], ['media_id' => $media->id], position: 1);
    TermPropertyValue::factory()->create(['value_text' => null, 'media_id' => $media->id]);

    expect($media->fresh()?->usage_count)->toBe(2)
        ->and(SiteAccess::current()->trackedUsageCount($media))->toBe(0);

    $this->actingAs(new class(['id' => 1]) extends GenericUser
    {
        public function isGlobalAdmin(): bool
        {
            return true;
        }
    });

    expect(SiteAccess::current()->trackedUsageCount($media))->toBe(2);
});

it('re-indexes only property reference edges for an owner', function (): void {
    $page = Page::factory()->create();
    $media = Media::factory()->model($page)->create();
    $definitions = attachReferenceProperties($page, ['hero' => [PropertyType::Media, PropertyRequirement::None]]);
    PagePropertyValue::withoutEvents(fn (): PagePropertyValue => pagePropertyValue($page, $definitions['hero'], ['media_id' => $media->id]));
    ContentGraphEdge::query()->create([
        'source_type' => Page::class, 'source_id' => $page->id, 'target_type' => Page::class, 'target_id' => 999,
        'kind' => ContentGraphEdgeKind::RelatesToPage->value, 'strength' => ContentGraphEdgeStrength::Weak, 'source_package' => 'capell-app/core',
    ]);

    SyncPropertyReferenceGraphEdgesAction::run($page);

    expect(storedPropertyReference($page, Media::class, $media->id))->not->toBeNull()
        ->and(ContentGraphEdge::query()->where('source_id', $page->id)->where('kind', ContentGraphEdgeKind::RelatesToPage->value)->exists())->toBeTrue();
});

it('keeps graph internals out of the public schema and drops trashed media from it', function (): void {
    $this->withoutDefer();
    $page = Page::factory()->withTranslations()->create(['visible_from' => now()->subDay()]);
    $media = Media::factory()->model($page)->create(['disk' => 'public']);
    $definitions = attachReferenceProperties($page, ['hero' => [PropertyType::Media, PropertyRequirement::Publish]]);
    pagePropertyValue($page, $definitions['hero'], ['media_id' => $media->id]);

    $script = (string) BuildPageSchemaGraphAction::run($page)?->toJsonLdScript();

    expect(storedPropertyReference($page, Media::class, $media->id))->not->toBeNull()
        ->and($script)->toContain($media->getFullUrl())
        ->and($script)->not->toContain(ContentGraphEdgeKind::ReferencedByProperty->value)
        ->and($script)->not->toContain('test.references')
        ->and($script)->not->toContain('strength')
        ->and($script)->not->toContain('media_id');

    $media->delete();

    expect((string) BuildPageSchemaGraphAction::run($page->fresh())?->toJsonLdScript())->not->toContain($media->getFullUrl());
});

it('treats a value whose definition the blueprint no longer attaches as a weak reference', function (): void {
    $page = Page::factory()->create();
    $media = Media::factory()->model($page)->create();
    $detached = PropertyDefinition::factory()->create(['type' => PropertyType::Media, 'requirement' => PropertyRequirement::Publish]);
    pagePropertyValue($page, $detached, ['media_id' => $media->id]);

    expect(propertyReferenceEdge(BuildContentGraphForModelAction::run($page)->edges, Media::class, $media->id)?->strength)
        ->toBe(ContentGraphEdgeStrength::Weak);
});

it('re-indexes owners when a property definition is deleted with its values', function (): void {
    $this->withoutDefer();
    $page = Page::factory()->create();
    $media = Media::factory()->model(Page::factory()->create())->create();
    $definitions = attachReferenceProperties($page, ['hero' => [PropertyType::Media, PropertyRequirement::Publish]]);
    pagePropertyValue($page, $definitions['hero'], ['media_id' => $media->id]);
    $term = Term::factory()->create();
    TermPropertyValue::factory()->create(['term_id' => $term->id, 'property_definition_id' => $definitions['hero']->id, 'value_text' => null, 'media_id' => $media->id]);

    expect(storedPropertyReference($page, Media::class, $media->id))->not->toBeNull()
        ->and(storedPropertyReference($term, Media::class, $media->id))->not->toBeNull();

    $definitions['hero']->delete();

    expect(storedPropertyReference($page, Media::class, $media->id))->toBeNull()
        ->and(storedPropertyReference($term, Media::class, $media->id))->toBeNull()
        ->and(HasRetainedDeletionDependenciesAction::run($media))->toBeFalse();
});

it('re-indexes the page when a translation and its localised values are deleted', function (): void {
    $this->withoutDefer();
    $page = Page::factory()->create();
    $translation = Translation::factory()->translatable($page)->language(Language::factory()->create())->create();
    $media = Media::factory()->model(Page::factory()->create())->create();
    $definitions = attachReferenceProperties($page, ['hero' => [PropertyType::Media, PropertyRequirement::None]]);
    pagePropertyValue($page, $definitions['hero'], ['media_id' => $media->id, 'translation_id' => $translation->id]);

    expect(storedPropertyReference($page, Media::class, $media->id))->not->toBeNull();

    $translation->forceDelete();

    expect(storedPropertyReference($page, Media::class, $media->id))->toBeNull();
});

it('ignores a stored property edge whose value was removed without model events', function (): void {
    $this->withoutDefer();
    $page = Page::factory()->create();
    $media = Media::factory()->model(Page::factory()->create())->create();
    $definitions = attachReferenceProperties($page, ['hero' => [PropertyType::Media, PropertyRequirement::Publish]]);
    pagePropertyValue($page, $definitions['hero'], ['media_id' => $media->id]);

    expect(HasRetainedDeletionDependenciesAction::run($media))->toBeTrue()
        ->and(BuildContentImpactPreviewAction::run($media)->strongCount)->toBe(1);

    PagePropertyValue::query()->where('page_id', $page->id)->delete();

    expect(storedPropertyReference($page, Media::class, $media->id))->not->toBeNull()
        ->and(HasRetainedDeletionDependenciesAction::run($media))->toBeFalse()
        ->and(BuildContentImpactPreviewAction::run($media)->strongCount)->toBe(0);
});

it('keeps property media references when the permanent delete does not go through', function (): void {
    $this->withoutDefer();
    $page = Page::factory()->create();
    $media = Media::factory()->model(Page::factory()->create())->create();
    $definitions = attachReferenceProperties($page, ['hero' => [PropertyType::Media, PropertyRequirement::None]]);
    $value = pagePropertyValue($page, $definitions['hero'], ['media_id' => $media->id]);
    Event::listen('eloquent.deleting: ' . Media::class, static fn (): bool => false);

    expect($media->forceDelete())->toBeFalse()
        ->and($value->fresh()?->media_id)->toBe($media->id)
        ->and(storedPropertyReference($page, Media::class, $media->id))->not->toBeNull();
});

it('releases property media references itself where the media table carries no foreign key', function (): void {
    $this->withoutDefer();
    (require dirname(__DIR__, 3) . '/database/migrations/2026_11_11_000010_add_media_foreign_key_to_property_values_tables.php')->down();
    $page = Page::factory()->create();
    $media = Media::factory()->model(Page::factory()->create())->create();
    $definitions = attachReferenceProperties($page, ['hero' => [PropertyType::Media, PropertyRequirement::None]]);
    $value = pagePropertyValue($page, $definitions['hero'], ['media_id' => $media->id]);

    $media->forceDelete();

    expect($value->fresh()?->media_id)->toBeNull()
        ->and(storedPropertyReference($page, Media::class, $media->id))->toBeNull();
});

it('shows a trashed page whose required property still blocks a page delete in the preview', function (): void {
    $this->withoutDefer();
    $owner = Page::factory()->create();
    $target = Page::factory()->create();
    $definitions = attachReferenceProperties($owner, ['related' => [PropertyType::EntryReference, PropertyRequirement::Publish]]);
    pagePropertyValue($owner, $definitions['related'], ['referenced_page_id' => $target->id]);
    $owner->delete();

    $preview = BuildContentImpactPreviewAction::run($target);

    expect(HasRetainedDeletionDependenciesAction::run($target))->toBeTrue()
        ->and($preview->blocked)->toBeTrue()
        ->and($preview->strongCount)->toBe(1);
});
