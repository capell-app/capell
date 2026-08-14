<?php

declare(strict_types=1);

use Capell\Admin\Actions\Media\BuildMediaHealthIndexAction;
use Capell\Admin\Actions\Media\BuildMediaHealthStateAction;
use Capell\Admin\Actions\Media\RepairMediaHealthAction;
use Capell\Admin\Enums\MediaHealthRepairEnum;
use Capell\Core\Models\AssetAttachment;
use Capell\Core\Models\Language;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\Translation;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;

uses(CreatesAdminUser::class)->group('media');

beforeEach(function (): void {
    test()->actingAsAdmin();

    Storage::fake('public');
    Storage::fake('local');
    config()->set('capell.media.model', Media::class);
    config()->set('media-library.media_model', Media::class);
});

it('builds combined alt rights duplicate and usage health state', function (): void {
    $owner = Page::factory()->createOne();
    $language = Language::factory()->english()->createOne();

    $missingMetadata = mediaHealthTestMedia($owner, $language, [
        'alt' => '',
        'credit' => '',
    ]);
    $usedMedia = mediaHealthTestMedia($owner, $language, [
        'alt' => 'A useful description',
        'credit' => 'Capell Studio',
    ]);

    AssetAttachment::query()->create([
        'related_type' => $owner->getMorphClass(),
        'related_id' => $owner->getKey(),
        'asset_type' => $usedMedia->getMorphClass(),
        'asset_id' => $usedMedia->getKey(),
        'order' => 1,
    ]);

    $duplicateA = mediaHealthTestMedia($owner, $language, [
        'alt' => 'Duplicate A',
        'credit' => 'Capell Studio',
    ], size: 11);
    $duplicateB = mediaHealthTestMedia($owner, $language, [
        'alt' => 'Duplicate B',
        'credit' => 'Capell Studio',
    ], size: 11);

    Storage::disk('public')->put($duplicateA->getPathRelativeToRoot(), 'same bytes');
    Storage::disk('public')->put($duplicateB->getPathRelativeToRoot(), 'same bytes');

    $missingState = BuildMediaHealthStateAction::run($missingMetadata);
    $usedState = BuildMediaHealthStateAction::run($usedMedia);
    $duplicateState = BuildMediaHealthStateAction::run($duplicateA);

    expect($missingState->issues())
        ->toContain('missing_alt', 'missing_rights', 'unused')
        ->and($missingState->usageCount)->toBe(0)
        ->and($usedState->issues())->toBe(['healthy'])
        ->and($usedState->usageCount)->toBe(1)
        ->and($duplicateState->issues())->toContain('duplicate')
        ->and(BuildMediaHealthIndexAction::run())
        ->toHaveKey($duplicateB->getKey(), 'duplicate');
});

it('repairs only safe selected records and rechecks unused media before trashing', function (): void {
    $owner = Page::factory()->createOne();
    $language = Language::factory()->english()->createOne();
    $missingAlt = mediaHealthTestMedia($owner, $language, ['alt' => '', 'credit' => 'Credit']);
    $alreadyDescribed = mediaHealthTestMedia($owner, $language, ['alt' => 'Description', 'credit' => 'Credit']);
    $unused = mediaHealthTestMedia($owner, $language, ['alt' => 'Unused', 'credit' => 'Credit']);
    $used = mediaHealthTestMedia($owner, $language, ['alt' => 'Used', 'credit' => 'Credit']);

    AssetAttachment::query()->create([
        'related_type' => $owner->getMorphClass(),
        'related_id' => $owner->getKey(),
        'asset_type' => $used->getMorphClass(),
        'asset_id' => $used->getKey(),
        'order' => 1,
    ]);

    $markResult = RepairMediaHealthAction::run(
        selectedMedia: new Collection([$missingAlt, $alreadyDescribed]),
        actor: test()->authenticatedUser(),
        repair: MediaHealthRepairEnum::MarkDecorative,
    );

    $missingAlt->load('translations');
    $markedMeta = $missingAlt->translations->firstOrFail()->meta;

    expect($markResult->repaired)->toBe(1)
        ->and($markResult->skipped)->toContain(['id' => $alreadyDescribed->getKey(), 'reason' => 'not_missing_alt'])
        ->and($markedMeta)->toMatchArray(['decorative' => true])
        ->and($markedMeta)->not->toHaveKey('alt');

    $deleteResult = RepairMediaHealthAction::run(
        selectedMedia: new Collection([$unused, $used]),
        actor: test()->authenticatedUser(),
        repair: MediaHealthRepairEnum::DeleteUnused,
    );

    expect($deleteResult->repaired)->toBe(1)
        ->and($deleteResult->skipped)->toContain(['id' => $used->getKey(), 'reason' => 'in_use'])
        ->and($unused->fresh()->trashed())->toBeTrue()
        ->and($used->fresh()->trashed())->toBeFalse();
});

it('rechecks per-record permissions for bulk health repairs', function (): void {
    $owner = Page::factory()->createOne();
    $language = Language::factory()->english()->createOne();
    $media = mediaHealthTestMedia($owner, $language, ['alt' => 'Unused', 'credit' => 'Credit']);
    $actor = test()->createUser();

    $result = RepairMediaHealthAction::run(
        selectedMedia: new Collection([$media]),
        actor: $actor,
        repair: MediaHealthRepairEnum::DeleteUnused,
    );

    expect($result->repaired)->toBe(0)
        ->and($result->skipped)->toContain(['id' => $media->getKey(), 'reason' => 'unauthorized'])
        ->and($media->fresh()->trashed())->toBeFalse();
});

/** @param array<string, mixed> $meta */
function mediaHealthTestMedia(
    Page $owner,
    Language $language,
    array $meta,
    int $size = 100,
): Media {
    $media = Media::factory()->model($owner)->createOne([
        'size' => $size,
    ]);

    Translation::query()->create([
        'language_id' => $language->getKey(),
        'translatable_type' => $media->getMorphClass(),
        'translatable_id' => $media->getKey(),
        'title' => $media->name,
        'meta' => $meta,
    ]);

    return $media->load(['translations.language']);
}
