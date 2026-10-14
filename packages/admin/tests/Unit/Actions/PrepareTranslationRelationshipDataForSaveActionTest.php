<?php

declare(strict_types=1);

use Capell\Admin\Actions\PrepareTranslationRelationshipDataForSaveAction;
use Capell\Core\Enums\ContentStructure;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Models\Translation;
use Illuminate\Support\Facades\DB;

it('persists JSON-looking HTML content verbatim for a real page', function (): void {
    $blueprint = Blueprint::factory()->contentStructure(ContentStructure::Blocks)->createOne();
    $page = Page::factory()->type($blueprint)->createOne([
        'content_structure_override' => ContentStructure::Html->value,
    ]);
    $translation = Translation::factory()->translatable($page)->createOne(['content' => '{"b":2,"a":1}'])->fresh();
    $data = ['content' => '{"a":1,"b":2}'];

    $prepared = PrepareTranslationRelationshipDataForSaveAction::run($translation, $data);
    $translation->fill($prepared)->save();

    expect($translation->refresh()->getRawOriginal('content'))->toBe($data['content'])
        ->and($translation->content)->toBe($data['content'])
        ->and($prepared)->toBe($data);
});

it('persists lossy float collisions in Blocks content', function (string $stored, string $submitted): void {
    $blueprint = Blueprint::factory()->contentStructure(ContentStructure::Blocks)->createOne();
    $page = Page::factory()->type($blueprint)->createOne();
    $translation = Translation::factory()->translatable($page)->createOne(['content' => $stored])->fresh();
    $data = ['content' => $submitted];

    $prepared = PrepareTranslationRelationshipDataForSaveAction::run($translation, $data);
    $translation->fill($prepared)->save();

    expect($translation->refresh()->getRawOriginal('content'))->toBe($submitted)
        ->and($prepared)->toBe($data);
})->with([
    'rounding collision' => ['{"value":0.1}', '{"value":0.10000000000000001}'],
    'underflow' => ['{"value":0.0}', '{"value":1e-400}'],
]);

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

    expect(PrepareTranslationRelationshipDataForSaveAction::run($translation, $data))->toBe([])
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

it('persists JSON edits without losing object identity or integer precision', function (string $attribute, string $stored, string $submitted): void {
    $blueprint = Blueprint::factory()->contentStructure(ContentStructure::Blocks)->createOne();
    $page = Page::factory()->type($blueprint)->createOne();
    $translation = Translation::factory()->translatable($page)->createOne();
    DB::table($translation->getTable())->where('id', $translation->id)->update([$attribute => $stored]);
    $translation->refresh();

    $data = [$attribute => $attribute === 'content' ? $submitted : get_object_vars(json_decode($submitted, flags: JSON_THROW_ON_ERROR))];
    $expectedRaw = $attribute === 'content' ? $submitted : json_encode($data[$attribute], JSON_THROW_ON_ERROR);
    $prepared = PrepareTranslationRelationshipDataForSaveAction::run($translation, $data);

    $translation->fill($prepared ?? [])->save();

    expect($translation->refresh()->getRawOriginal($attribute))->toBe($expectedRaw)
        ->and(json_decode((string) $translation->getRawOriginal($attribute), flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING))
        ->toEqual(json_decode($expectedRaw, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING))
        ->and($prepared)->toBe($data);
})->with(['meta', 'content'])->with([
    'empty object to list' => ['{"settings":{}}', '{"settings":[]}'],
    'empty list to object' => ['{"settings":[]}', '{"settings":{}}'],
    'numeric object to list' => ['{"settings":{"0":"a","1":"b"}}', '{"settings":["a","b"]}'],
    'list to numeric object' => ['{"settings":["a","b"]}', '{"settings":{"0":"a","1":"b"}}'],
    'large integer change' => ['{"value":9223372036854775808}', '{"value":9223372036854775809}'],
    'large integer to string' => ['{"value":9223372036854775808}', '{"value":"9223372036854775808"}'],
]);

it('retains raw JSON when numeric object keys are reordered', function (string $attribute): void {
    $page = new Page(['content_structure_override' => ContentStructure::Blocks->value]);
    $page->setRelation('blueprint', new Blueprint);

    $translation = new Translation;
    $translation->setRawAttributes([
        'translatable_id' => 1,
        $attribute => '{"settings":{"1":"b","0":"a"}}',
    ], sync: true);
    $translation->setRelation('translatable', $page);

    $data = [$attribute => ['settings' => (object) ['0' => 'a', '1' => 'b']]];

    expect(PrepareTranslationRelationshipDataForSaveAction::run($translation, $data))->toBe([])
        ->and(PrepareTranslationRelationshipDataForSaveAction::run($translation, [...$data, 'title' => 'Edited']))->toBe(['title' => 'Edited'])
        ->and($translation->getRawOriginal($attribute))->toBe('{"settings":{"1":"b","0":"a"}}');
})->with(['meta', 'content']);

it('persists JSON when decoding fails or scalar types differ', function (string $stored, string $submitted): void {
    $page = new Page(['content_structure_override' => ContentStructure::Blocks->value]);
    $page->setRelation('blueprint', new Blueprint);

    $translation = new Translation;
    $translation->setRawAttributes(['translatable_id' => 1, 'content' => $stored], sync: true);
    $translation->setRelation('translatable', $page);

    $data = ['content' => $submitted];

    expect(PrepareTranslationRelationshipDataForSaveAction::run($translation, $data))->toBe($data);
})->with([
    'integer to float' => ['{"value":1}', '{"value":1.0}'],
    'invalid stored JSON' => ['{"value":', '{"value":1}'],
    'invalid candidate JSON' => ['{"value":1}', '{"value":'],
    'both invalid' => ['not JSON', 'different invalid JSON'],
]);

it('prepares a title-only save without resolving the content structure', function (): void {
    $blueprint = Blueprint::factory()->contentStructure(ContentStructure::Blocks)->createOne();
    $page = Page::factory()->type($blueprint)->createOne();
    $translation = Translation::factory()->translatable($page)->createOne([
        'content' => '{"blocks":[]}',
        'meta' => ['label' => 'Original'],
    ])->fresh();
    $data = ['title' => 'Edited title'];

    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $prepared = PrepareTranslationRelationshipDataForSaveAction::run($translation, $data);
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
    }

    expect($prepared)->toBe($data)
        ->and(array_column($queries, 'query'))->toBe([])
        ->and($translation->relationLoaded('translatable'))->toBeFalse();
});
