<?php

declare(strict_types=1);

use Capell\Admin\Actions\ContentGraph\ValidateContentDeleteImpactAction;
use Capell\Admin\Actions\Media\BuildMediaUsageItemsAction;
use Capell\Admin\Actions\Media\CountOmittedMediaUsageAction;
use Capell\Admin\Filament\Resources\Media\Pages\ListMedia;
use Capell\Core\Enums\PropertyRequirement;
use Capell\Core\Enums\PropertyType;
use Capell\Core\Models\Media as CapellMedia;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\Term;
use Capell\Core\Models\TermPropertyValue;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;

uses(CreatesAdminUser::class)
    ->group('media');

beforeEach(function (): void {
    test()->actingAsAdmin();
    config()->set('capell.media.model', CapellMedia::class);
    config()->set('media-library.media_model', CapellMedia::class);
});

function mediaUsedOnlyByProperty(Page $page, PropertyRequirement $requirement = PropertyRequirement::None, string $name = 'Property image'): CapellMedia
{
    $media = CapellMedia::factory()->model(Page::factory()->create())->createOne(['name' => $name, 'file_name' => 'property.jpg']);
    $definition = PropertyDefinition::factory()->create(['type' => PropertyType::Media, 'requirement' => $requirement]);

    PagePropertyValue::factory()->create([
        'site_id' => $page->site_id,
        'page_id' => $page->id,
        'property_definition_id' => $definition->id,
        'translation_id' => null,
        'value_text' => null,
        'media_id' => $media->id,
    ]);

    return $media;
}

it('counts and filters media used only through property values', function (): void {
    $page = Page::factory()->create(['name' => 'Spring catalogue']);
    $used = mediaUsedOnlyByProperty($page);
    TermPropertyValue::factory()->create(['value_text' => null, 'media_id' => $used->id]);
    $unused = CapellMedia::factory()->model($page)->createOne(['name' => 'Spare image', 'file_name' => 'spare.jpg']);

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->assertTableColumnStateSet('usage_count', 2, $used)
        ->assertTableColumnStateSet('usage_count', 0, $unused)
        ->filterTable('tracked_use', 'used')
        ->assertCanSeeTableRecords([$used])
        ->assertCanNotSeeTableRecords([$unused]);

    Livewire::test(ListMedia::class)
        ->filterTable('tracked_use', 'unused')
        ->assertCanSeeTableRecords([$unused])
        ->assertCanNotSeeTableRecords([$used]);
});

it('lists the pages and terms whose properties use a media item', function (): void {
    $page = Page::factory()->create(['name' => 'Spring catalogue']);
    $media = mediaUsedOnlyByProperty($page);
    $term = Term::factory()->create(['name' => 'Linen']);
    TermPropertyValue::factory()->create(['term_id' => $term->id, 'value_text' => null, 'media_id' => $media->id]);

    $items = collect(BuildMediaUsageItemsAction::run($media))->where('label', __('capell-admin::media.used_in_property'));

    expect($items->pluck('title')->all())->toBe(['Spring catalogue', 'Linen'])
        ->and(CountOmittedMediaUsageAction::run($media))->toBe(0);
});

it('shares one usage limit between attachments and property owners', function (): void {
    $definition = PropertyDefinition::factory()->create(['type' => PropertyType::Media]);
    $media = CapellMedia::factory()->model(Page::factory()->create())->createOne();

    foreach (range(1, BuildMediaUsageItemsAction::DEFAULT_LIMIT + 2) as $position) {
        $page = Page::factory()->create(['name' => 'Property page ' . $position]);
        PagePropertyValue::factory()->create([
            'site_id' => $page->site_id, 'page_id' => $page->id, 'property_definition_id' => $definition->id,
            'translation_id' => null, 'value_text' => null, 'media_id' => $media->id,
        ]);
    }

    $term = Term::factory()->create();
    TermPropertyValue::factory()->create(['term_id' => $term->id, 'value_text' => null, 'media_id' => $media->id]);

    $limited = collect(BuildMediaUsageItemsAction::run($media))->where('label', __('capell-admin::media.used_in_property'));
    $all = collect(BuildMediaUsageItemsAction::run($media, showAll: true))->where('label', __('capell-admin::media.used_in_property'));

    expect($limited)->toHaveCount(BuildMediaUsageItemsAction::DEFAULT_LIMIT)
        ->and($all)->toHaveCount(BuildMediaUsageItemsAction::DEFAULT_LIMIT + 3)
        ->and(CountOmittedMediaUsageAction::run($media))->toBe(3);
});

it('blocks deleting media that a publish-required property relies on', function (): void {
    $this->withoutDefer();
    $page = Page::factory()->create();
    $required = mediaUsedOnlyByProperty($page, PropertyRequirement::Publish);
    $optional = mediaUsedOnlyByProperty($page, PropertyRequirement::None, 'Optional image');

    $blocked = ValidateContentDeleteImpactAction::run($required);
    $warned = ValidateContentDeleteImpactAction::run($optional);

    expect($blocked->allowed)->toBeFalse()
        ->and($blocked->blockingCount)->toBe(1)
        ->and($warned->allowed)->toBeTrue()
        ->and($warned->warningCount)->toBe(1);
});
