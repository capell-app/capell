<?php

declare(strict_types=1);

use Capell\Core\Actions\Properties\ProjectAgentSchemaValueAction;
use Capell\Core\Data\Properties\AgentPropertyEntryData;
use Capell\Core\Enums\PropertyType;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Taxonomy;
use Capell\Core\Models\Term;

it('projects supported durations to ISO 8601 and omits unsafe units', function (): void {
    $projector = new ProjectAgentSchemaValueAction;

    expect($projector->handle(new AgentPropertyEntryData('x', null, PropertyType::Duration, 1500, unit: 'ms')))
        ->toBe('PT1.5S')
        ->and($projector->handle(new AgentPropertyEntryData('x', null, PropertyType::Duration, 2, unit: 'hours')))
        ->toBe('PT2H')
        ->and($projector->handle(new AgentPropertyEntryData('x', null, PropertyType::Duration, -1, unit: 's')))
        ->toBeNull()
        ->and($projector->handle(new AgentPropertyEntryData('x', null, PropertyType::Duration, 2, unit: 'fortnights')))
        ->toBeNull();
});

it('projects a same-site published entry reference with its public URL and title', function (): void {
    $source = Page::factory()->withTranslations()->published()->create();
    $language = $source->site->language;
    $target = Page::factory()->site($source->site)->withTranslations($language, data: ['title' => 'Public target'])->published()->create([
        'visible_from' => now()->subDay(),
    ]);
    PageUrl::factory()->page($target)->site($source->site)->language($language)->create(['url' => '/target']);

    $value = ProjectAgentSchemaValueAction::run(
        new AgentPropertyEntryData('x', 'schema:relatedLink', PropertyType::EntryReference, null, referenceId: $target->id),
        $source->site_id,
        $language,
    );

    expect($value)->toMatchArray(['@id' => $target->pageUrls()->first()->fullUrl(), 'url' => $target->pageUrls()->first()->fullUrl(), 'name' => 'Public target'])
        ->and($value)->not->toHaveKey('id');
});

it('omits entry references that are draft, foreign-site, or have no public URL', function (): void {
    $source = Page::factory()->published()->create();
    $draft = Page::factory()->site($source->site)->create();
    $foreign = Page::factory()->published()->create();
    $withoutUrl = Page::factory()->site($source->site)->published()->create();
    $projector = new ProjectAgentSchemaValueAction;

    foreach ([$draft, $foreign, $withoutUrl] as $page) {
        expect($projector->handle(
            new AgentPropertyEntryData('x', null, PropertyType::EntryReference, null, referenceId: $page->id),
            $source->site_id,
        ))->toBeNull();
    }
});

it('projects same-site term semantics and omits terms from another site', function (): void {
    $source = Page::factory()->published()->create();
    $taxonomy = Taxonomy::factory()->create(['site_id' => $source->site_id]);
    $term = Term::factory()->for($taxonomy)->create(['name' => 'Outdoor', 'semantic' => 'schema:Product']);
    $foreignTaxonomy = Taxonomy::factory()->create();
    $foreignTerm = Term::factory()->for($foreignTaxonomy)->create(['name' => 'Private']);
    $projector = new ProjectAgentSchemaValueAction;

    expect($projector->handle(new AgentPropertyEntryData('x', null, PropertyType::TermReference, null, referenceId: $term->id), $source->site_id))
        ->toBe(['name' => 'Outdoor', '@type' => 'Product'])
        ->and($projector->handle(new AgentPropertyEntryData('x', null, PropertyType::TermReference, null, referenceId: $foreignTerm->id), $source->site_id))
        ->toBeNull();
});

it('projects public media metadata without exposing private media URLs', function (): void {
    config([
        'filesystems.disks.public.visibility' => 'public',
        'filesystems.disks.private.visibility' => 'private',
    ]);
    $owner = Page::factory()->published()->create();
    $public = Media::factory()->model($owner)->create(['disk' => 'public', 'custom_properties' => ['width' => 640, 'height' => 480]]);
    $private = Media::factory()->model($owner)->create(['disk' => 'private']);
    $foreignOwner = Page::factory()->published()->create();
    $foreign = Media::factory()->model($foreignOwner)->create(['disk' => 'public']);
    $projector = new ProjectAgentSchemaValueAction;

    expect($projector->handle(new AgentPropertyEntryData('x', null, PropertyType::Media, null, referenceId: $public->id), $owner->site_id))
        ->toMatchArray(['@type' => 'ImageObject', 'name' => $public->name, 'width' => 640, 'height' => 480])
        ->and($projector->handle(new AgentPropertyEntryData('x', null, PropertyType::Media, null, referenceId: $private->id), $owner->site_id))
        ->toBeNull()
        ->and($projector->handle(new AgentPropertyEntryData('x', null, PropertyType::Media, null, referenceId: $foreign->id), $owner->site_id))
        ->toBeNull();
});

it('omits entry references that cannot be rendered anonymously', function (string $reason): void {
    $source = Page::factory()->withTranslations()->published()->create();
    $language = $source->site->language;
    $target = Page::factory()->site($source->site)->withTranslations($language)->published()->create();
    PageUrl::factory()->page($target)->site($source->site)->language($language)->create();

    match ($reason) {
        'private blueprint' => $target->blueprint->update(['meta' => ['accessible' => false]]),
        'disabled blueprint' => $target->blueprint->update(['status' => false]),
        'redirect' => $target->pageUrls()->update(['type' => UrlTypeEnum::Redirect]),
        'missing translation' => $target->translations()->delete(),
        default => throw new InvalidArgumentException($reason),
    };

    expect(ProjectAgentSchemaValueAction::run(
        new AgentPropertyEntryData('x', 'schema:relatedLink', PropertyType::EntryReference, null, referenceId: $target->id),
        $source->site_id,
        $language,
    ))->toBeNull();
})->with(['private blueprint', 'disabled blueprint', 'redirect', 'missing translation']);
