<?php

declare(strict_types=1);

use Capell\Admin\Actions\PrepareTranslationRelationshipDataForSaveAction;
use Capell\Core\Enums\ContentStructure;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Models\Translation;

it('retains stored JSON when only object key order or formatting changes', function (string $attribute): void {
    $page = new Page(['content_structure_override' => ContentStructure::Blocks->value]);
    $page->setRelation('blueprint', new Blueprint);

    $translation = new Translation;
    $translation->setRawAttributes([
        'translatable_id' => 1,
        'title' => 'Original title',
        $attribute => '{"second": null, "first": {"label": "Original", "items": [1, "2"]}}',
    ], sync: true);
    $translation->setRelation('translatable', $page);

    $attributes = $translation->getAttributes();
    $data = [$attribute => ['first' => ['items' => [1, '2'], 'label' => 'Original'], 'second' => null]];

    expect(PrepareTranslationRelationshipDataForSaveAction::run($translation, $data))->toBeNull()
        ->and(PrepareTranslationRelationshipDataForSaveAction::run($translation, [...$data, 'title' => 'Edited title']))->toBe(['title' => 'Edited title'])
        ->and($translation->getAttributes())->toBe($attributes);
})->with(['meta', 'content']);

it('persists meaningful JSON changes without treating scalar types or list order as equivalent', function (array $submitted): void {
    $translation = new Translation;
    $translation->setRawAttributes([
        'meta' => '{"label": "Original", "items": [1, "2"], "optional": null}',
    ], sync: true);
    $attributes = $translation->getAttributes();
    $data = ['meta' => $submitted];

    expect(PrepareTranslationRelationshipDataForSaveAction::run($translation, $data))->toBe($data)
        ->and($translation->getAttributes())->toBe($attributes);
})->with([
    'changed value' => [['label' => 'Edited', 'items' => [1, '2'], 'optional' => null]],
    'changed scalar type' => [['label' => 'Original', 'items' => ['1', '2'], 'optional' => null]],
    'reordered list' => [['label' => 'Original', 'items' => ['2', 1], 'optional' => null]],
    'removed null key' => [['label' => 'Original', 'items' => [1, '2']]],
    'added null key' => [['label' => 'Original', 'items' => [1, '2'], 'optional' => null, 'extra' => null]],
    'replaced array with scalar' => [['label' => 'Original', 'items' => '1,2', 'optional' => null]],
    'replaced list with object' => [['label' => 'Original', 'items' => ['first' => 1, 'second' => '2'], 'optional' => null]],
    'different object keys' => [['label' => 'Original', 'items' => [1, '2'], 'replacement' => null]],
    'nested change' => [['label' => 'Original', 'items' => [1, ['label' => 'Edited']], 'optional' => null]],
]);
