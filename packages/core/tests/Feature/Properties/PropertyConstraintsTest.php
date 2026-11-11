<?php

declare(strict_types=1);

use Capell\Core\Actions\CaptureBlueprintSchemaSnapshotAction;
use Capell\Core\Actions\Properties\BuildPropertyValidationRulesAction;
use Capell\Core\Actions\Properties\PromoteBlueprintFieldAction;
use Capell\Core\Actions\Properties\ResolveEffectiveDefinitionsAction;
use Capell\Core\Actions\Properties\SetPagePropertyValuesAction;
use Capell\Core\Actions\Properties\UpdatePropertyDefinitionConstraintsAction;
use Capell\Core\Actions\Properties\ValidatePromotedPropertyValuesAction;
use Capell\Core\Data\Properties\PropertyConstraintsData;
use Capell\Core\Data\Properties\PropertyValueData;
use Capell\Core\Enums\PropertyType;
use Capell\Core\EventSourcing\Serializers\PageStateSerializer;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\BlueprintPropertySet;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;
use Capell\Core\Models\Taxonomy;
use Capell\Core\Models\Term;
use Capell\Core\Support\TableName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * @param  array<string, mixed>  $constraints
 */
function constrainedProperty(Page $page, PropertyType $type, array $constraints, string $key = 'field'): PropertyDefinition
{
    $set = PropertySet::factory()->create();
    $definition = PropertyDefinition::factory()
        ->withConstraints($constraints)
        ->create(['property_set_id' => $set->id, 'key' => $key, 'type' => $type]);
    BlueprintPropertySet::factory()->create(['blueprint_id' => $page->blueprint_id, 'property_set_id' => $set->id]);

    return $definition;
}

/**
 * The validation messages a write is rejected with, or [] when it is stored.
 *
 * @return array<string, list<string>>
 */
function propertyWriteErrors(Page $page, string $key, PropertyType $type, mixed $value, ?string $currency = null): array
{
    try {
        SetPagePropertyValuesAction::run($page, [new PropertyValueData($key, $type, $value, currency: $currency)]);
    } catch (ValidationException $validationException) {
        return $validationException->errors();
    }

    return [];
}

it('stores constraints as versioned snake_case JSON and an empty rule set as null', function (): void {
    $definition = PropertyDefinition::factory()->withConstraints(['max_length' => 20, 'unique_per_site' => true])->create();

    $raw = DB::table(TableName::resolve('property_definitions'))->where('id', $definition->id)->value('constraints');

    expect(json_decode((string) $raw, true))->toBe(['version' => 1, 'max_length' => 20, 'unique_per_site' => true])
        ->and($definition->refresh()->constraints)->toBeInstanceOf(PropertyConstraintsData::class)
        ->and($definition->constraints?->maxLength)->toBe(20);

    $definition->constraints = new PropertyConstraintsData;
    $definition->save();

    expect(DB::table(TableName::resolve('property_definitions'))->where('id', $definition->id)->value('constraints'))->toBeNull()
        ->and($definition->refresh()->constraints)->toBeNull();
});

it('reads stored constraints tolerantly, dropping malformed values and unknown keys', function (): void {
    $constraints = PropertyConstraintsData::fromStored([
        'version' => 2,
        'min' => 'not a number',
        'max_length' => -5,
        'allowed_blueprints' => ['article', '', 7, 'article'],
        'future_rule' => true,
    ]);

    expect($constraints->version)->toBe(2)
        ->and($constraints->min)->toBeNull()
        ->and($constraints->maxLength)->toBeNull()
        ->and($constraints->allowedBlueprints)->toBe(['article'])
        ->and($constraints->toStored())->toBe(['version' => 2, 'allowed_blueprints' => ['article']]);
});

it('carries constraints into the effective definition every write path resolves', function (): void {
    $page = Page::factory()->create();
    constrainedProperty($page, PropertyType::Number, ['min' => 1, 'max' => 5]);

    $effective = ResolveEffectiveDefinitionsAction::run($page)->firstWhere('key', 'field');

    expect($effective?->constraints?->min)->toBe(1)
        ->and($effective?->constraints?->max)->toBe(5);
});

it('builds no rules for a definition without constraints', function (): void {
    $page = Page::factory()->create();
    $set = PropertySet::factory()->create();
    PropertyDefinition::factory()->create(['property_set_id' => $set->id, 'key' => 'free', 'type' => PropertyType::Text]);
    BlueprintPropertySet::factory()->create(['blueprint_id' => $page->blueprint_id, 'property_set_id' => $set->id]);

    $definition = ResolveEffectiveDefinitionsAction::run($page)->firstWhere('key', 'free');

    expect(BuildPropertyValidationRulesAction::run($definition, $page->site_id))->toBe([])
        ->and(propertyWriteErrors($page, 'free', PropertyType::Text, str_repeat('x', 5000)))->toBe([]);
});

it('enforces numeric ranges', function (mixed $value, bool $accepted): void {
    $page = Page::factory()->create();
    constrainedProperty($page, PropertyType::Money, ['min' => 0, 'max' => 1000], 'price');

    $errors = propertyWriteErrors($page, 'price', PropertyType::Money, $value, 'GBP');

    expect($errors === [])->toBe($accepted);

    if (! $accepted) {
        expect($errors['price'][0])->toContain('Price must be');
    }
})->with([
    'inside the range' => [49.99, true],
    'at the lower bound' => [0, true],
    'below the minimum' => [-1, false],
    'above the maximum' => [1000.01, false],
]);

it('enforces text length and format against the whole value', function (string $value, ?string $expected): void {
    $page = Page::factory()->create();
    constrainedProperty($page, PropertyType::Text, ['min_length' => 8, 'max_length' => 8, 'pattern' => '[A-Z]{3}-[0-9]{4}'], 'sku');

    $errors = propertyWriteErrors($page, 'sku', PropertyType::Text, $value);

    if ($expected === null) {
        expect($errors)->toBe([]);

        return;
    }

    expect($errors['sku'])->toContain($expected);
})->with([
    'valid' => ['ABC-1234', null],
    'too short' => ['AB-1234', 'Sku must be at least 8 characters long. Add more text.'],
    'wrong format' => ['abc-1234', 'Sku is not in the format this field expects. Check the field help for an example and try again.'],
    'format matched only in part' => ['XABC-123', 'Sku is not in the format this field expects. Check the field help for an example and try again.'],
]);

it('rejects a value another page on the same site already uses, but not one on another site', function (): void {
    $page = Page::factory()->create();
    constrainedProperty($page, PropertyType::Text, ['unique_per_site' => true], 'code');
    $sibling = Page::factory()->create(['site_id' => $page->site_id, 'blueprint_id' => $page->blueprint_id]);
    $elsewhere = Page::factory()->create(['blueprint_id' => $page->blueprint_id]);

    expect(propertyWriteErrors($page, 'code', PropertyType::Text, 'SPRING'))->toBe([])
        ->and(propertyWriteErrors($page->fresh(), 'code', PropertyType::Text, 'SPRING'))->toBe([])
        ->and(propertyWriteErrors($sibling, 'code', PropertyType::Text, 'SPRING'))
        ->toBe(['code' => ['Another page on this site already uses this Code. Enter a different value.']])
        ->and(propertyWriteErrors($elsewhere, 'code', PropertyType::Text, 'SPRING'))->toBe([])
        ->and(PagePropertyValue::query()->where('value_text', 'SPRING')->count())->toBe(2);
});

it('restricts entry references to the allowed page types', function (): void {
    $page = Page::factory()->create();
    constrainedProperty($page, PropertyType::EntryReference, ['allowed_blueprints' => ['article']], 'related');
    $article = Page::factory()->create([
        'site_id' => $page->site_id,
        'blueprint_id' => Blueprint::factory()->page()->create(['key' => 'article'])->id,
    ]);
    $landing = Page::factory()->create([
        'site_id' => $page->site_id,
        'blueprint_id' => Blueprint::factory()->page()->create(['key' => 'landing'])->id,
    ]);

    expect(propertyWriteErrors($page, 'related', PropertyType::EntryReference, $article->id))->toBe([])
        ->and(propertyWriteErrors($page, 'related', PropertyType::EntryReference, $landing->id))
        ->toBe(['related' => ['Related can only link to these page types: article. Choose a page of one of those types.']]);
});

it('restricts term references to the allowed taxonomies', function (): void {
    $page = Page::factory()->create();
    constrainedProperty($page, PropertyType::TermReference, ['allowed_taxonomies' => ['topics']], 'topic');
    $topic = Term::factory()->for(Taxonomy::factory()->create(['site_id' => $page->site_id, 'key' => 'topics']))->create();
    $colour = Term::factory()->for(Taxonomy::factory()->create(['site_id' => $page->site_id, 'key' => 'colours']))->create();

    expect(propertyWriteErrors($page, 'topic', PropertyType::TermReference, $topic->id))->toBe([])
        ->and(propertyWriteErrors($page, 'topic', PropertyType::TermReference, $colour->id))
        ->toBe(['topic' => ['Topic can only use terms from: topics. Choose a term from one of those.']]);
});

it('restricts media to the allowed file types and image sizes', function (string $mimeType, array $size, ?string $expected): void {
    $page = Page::factory()->create();
    constrainedProperty($page, PropertyType::Media, [
        'allowed_mime_types' => ['image/*'],
        'min_width' => 800,
        'max_height' => 1200,
    ], 'hero');
    $media = Media::factory()->model($page)->create(['mime_type' => $mimeType, 'custom_properties' => $size]);

    $errors = propertyWriteErrors($page, 'hero', PropertyType::Media, $media->id);

    if ($expected === null) {
        expect($errors)->toBe([]);

        return;
    }

    expect($errors['hero'])->toContain($expected);
})->with([
    'a large enough image' => ['image/png', ['width' => 1600, 'height' => 900], null],
    'a document' => ['application/pdf', ['width' => 1600, 'height' => 900], 'Hero only accepts these file types: image/*. Choose a file of one of those types.'],
    'too narrow' => ['image/jpeg', ['width' => 400, 'height' => 300], 'Hero needs an image at least 800 pixels wide. Choose a wider image.'],
    'too tall' => ['image/jpeg', ['width' => 1600, 'height' => 2400], 'Hero needs an image no more than 1200 pixels tall. Choose a shorter image.'],
    'of unknown size' => ['image/jpeg', [], 'Hero needs an image whose size is known. Upload the image again so its size can be checked.'],
]);

it('saves constraints through the update action and clears them when every rule is empty', function (): void {
    $definition = PropertyDefinition::factory()->create(['type' => PropertyType::Number]);

    UpdatePropertyDefinitionConstraintsAction::run($definition, ['min' => '1.5', 'max' => '10', 'unique_per_site' => '1']);

    expect($definition->refresh()->constraints?->toStored())->toBe(['version' => 1, 'min' => 1.5, 'max' => 10, 'unique_per_site' => true]);

    UpdatePropertyDefinitionConstraintsAction::run($definition, ['min' => '', 'max' => null, 'unique_per_site' => false]);

    expect($definition->refresh()->constraints)->toBeNull();
});

it('refuses rule sets that cannot work', function (PropertyType $type, array $input, string $field, string $message): void {
    $definition = PropertyDefinition::factory()->create(['type' => $type]);

    try {
        UpdatePropertyDefinitionConstraintsAction::run($definition, $input);
        $this->fail('Expected the rule set to be refused.');
    } catch (ValidationException $validationException) {
        expect($validationException->errors()[$field] ?? [])->toContain($message);
    }

    expect($definition->refresh()->constraints)->toBeNull();
})->with([
    'a range that is upside down' => [PropertyType::Number, ['min' => 10, 'max' => 1], 'max', 'The largest allowed value is smaller than the smallest. Swap them or change one.'],
    'a length on a number' => [PropertyType::Number, ['max_length' => 5], 'max_length', 'This rule does not apply to this kind of field. Clear it before saving.'],
    'a format that does not compile' => [PropertyType::Text, ['pattern' => '[A-Z'], 'pattern', 'This format cannot be used. Check it for unmatched brackets, stray symbols or (*...) instructions, or ask a developer to write it.'],
    'a format that closes the whole-value wrapper' => [PropertyType::Text, ['pattern' => 'x)|(y'], 'pattern', 'This format cannot be used. Check it for unmatched brackets, stray symbols or (*...) instructions, or ask a developer to write it.'],
    'a malformed file type' => [PropertyType::Media, ['allowed_mime_types' => ['png']], 'allowed_mime_types.0', 'Enter a file type such as image/png, or image/* for any image.'],
]);

it('leaves package-owned property rules to their package', function (): void {
    $set = PropertySet::factory()->create(['owner_package' => 'capell-app/commerce']);
    $definition = PropertyDefinition::factory()->create(['property_set_id' => $set->id, 'type' => PropertyType::Number]);

    expect(fn (): mixed => UpdatePropertyDefinitionConstraintsAction::run($definition, ['min' => 1]))
        ->toThrow(ValidationException::class);

    expect($definition->refresh()->constraints)->toBeNull();
});

it('records the property rules a blueprint carried in its schema snapshot', function (): void {
    $page = Page::factory()->create();
    $set = PropertySet::factory()->create(['key' => 'test.snapshot']);
    PropertyDefinition::factory()->withConstraints(['max_length' => 12])->create([
        'property_set_id' => $set->id,
        'key' => 'sku',
        'type' => PropertyType::Text,
    ]);
    BlueprintPropertySet::factory()->create(['blueprint_id' => $page->blueprint_id, 'property_set_id' => $set->id]);
    $blueprint = Blueprint::query()->findOrFail($page->blueprint_id);

    $snapshot = CaptureBlueprintSchemaSnapshotAction::run($blueprint, 'test');

    expect($snapshot->metadata)->toBe(['property_definitions' => [[
        'property_set' => 'test.snapshot',
        'key' => 'sku',
        'type' => 'text',
        'requirement' => 'none',
        'constraints' => ['version' => 1, 'max_length' => 12],
    ]]]);
});

it('compares numeric values when enforcing uniqueness', function (): void {
    $page = Page::factory()->create();
    constrainedProperty($page, PropertyType::Number, ['unique_per_site' => true], 'position');
    $sibling = Page::factory()->create(['site_id' => $page->site_id, 'blueprint_id' => $page->blueprint_id]);

    expect(propertyWriteErrors($page, 'position', PropertyType::Number, 10))->toBe([])
        ->and(propertyWriteErrors($sibling, 'position', PropertyType::Number, '10.0'))
        ->toBe(['position' => ['Another page on this site already uses this Position. Enter a different value.']])
        ->and(propertyWriteErrors($sibling, 'position', PropertyType::Number, 11))->toBe([]);
});

it('lets a value held only by a deleted page be reused', function (): void {
    $page = Page::factory()->create();
    constrainedProperty($page, PropertyType::Text, ['unique_per_site' => true], 'code');
    $sibling = Page::factory()->create(['site_id' => $page->site_id, 'blueprint_id' => $page->blueprint_id]);

    expect(propertyWriteErrors($page, 'code', PropertyType::Text, 'SUMMER'))->toBe([]);

    $page->delete();

    expect(propertyWriteErrors($sibling, 'code', PropertyType::Text, 'SUMMER'))->toBe([]);
});

it('blocks an unrelated save over an older over-limit promoted value where developers work, and only reports it in production', function (): void {
    $page = Page::factory()->create(['name' => 'An established page name']);
    $set = PropertySet::factory()->create();
    $definition = PropertyDefinition::factory()->create(['property_set_id' => $set->id, 'key' => 'display_name', 'type' => PropertyType::Text]);
    BlueprintPropertySet::factory()->create(['blueprint_id' => $page->blueprint_id, 'property_set_id' => $set->id]);
    PromoteBlueprintFieldAction::run(Blueprint::query()->findOrFail($page->blueprint_id), $definition, 'name');
    $page->save();

    UpdatePropertyDefinitionConstraintsAction::run($definition, ['max_length' => 10]);

    $page->refresh()->order = 98;

    // Refused before the row is written, so nothing is half-saved.
    expect(fn (): bool => $page->save())->toThrow(ValidationException::class)
        ->and($page->refresh()->order)->not->toBe(98);

    app()->detectEnvironment(fn (): string => 'production');
    $page->refresh()->order = 99;
    $page->save();

    expect($page->refresh()->order)->toBe(99);

    $page->name = 'Another name that is too long';

    expect(fn (): bool => $page->save())->toThrow(ValidationException::class);

    // Rejected before the row is written: the field and its property stay in step.
    expect($page->refresh()->name)->toBe('An established page name')
        ->and(PagePropertyValue::query()->where('property_definition_id', $definition->id)->value('value_text'))->toBe('An established page name');
});

/**
 * A page whose `name` field is promoted to a `display_name` property. Nothing
 * is synced yet, as after a promotion added to existing content.
 *
 * @param  array<string, mixed>  $constraints
 */
function promotedNameProperty(Page $page, array $constraints = [], ?int $blueprintId = null): PropertyDefinition
{
    $set = PropertySet::factory()->create();
    $definition = PropertyDefinition::factory()->withConstraints($constraints)->create([
        'property_set_id' => $set->id,
        'key' => 'display_name',
        'type' => PropertyType::Text,
    ]);
    $blueprint = Blueprint::query()->findOrFail($blueprintId ?? $page->blueprint_id);
    BlueprintPropertySet::factory()->create(['blueprint_id' => $blueprint->id, 'property_set_id' => $set->id]);
    PromoteBlueprintFieldAction::run($blueprint, $definition, 'name');

    return $definition;
}

it('lets an unrelated production save through, reporting an older promoted value with no stored property that breaks a later rule', function (): void {
    $page = Page::factory()->create(['name' => 'An established page name']);
    $definition = promotedNameProperty($page, ['max_length' => 10]);

    expect(PagePropertyValue::query()->where('property_definition_id', $definition->id)->exists())->toBeFalse();

    app()->detectEnvironment(fn (): string => 'production');
    $log = Log::spy();
    $page->refresh()->order = 42;
    $page->save();

    $log->shouldHaveReceived('warning')->atLeast()->once();

    expect($page->refresh()->order)->toBe(42)
        ->and(PagePropertyValue::query()->where('property_definition_id', $definition->id)->exists())->toBeFalse();

    $page->name = 'Short';
    $page->save();

    expect(PagePropertyValue::query()->where('property_definition_id', $definition->id)->value('value_text'))->toBe('Short');
});

it('checks promoted fields against the new blueprint before a page moves to it', function (): void {
    $page = Page::factory()->create(['name' => 'An established page name']);
    $strict = Blueprint::factory()->page()->create();
    promotedNameProperty($page, ['max_length' => 10], $strict->id);
    $originalBlueprintId = $page->blueprint_id;

    $page->refresh()->blueprint_id = $strict->id;

    expect(fn (): bool => $page->save())->toThrow(ValidationException::class)
        ->and($page->refresh()->blueprint_id)->toBe($originalBlueprintId);
});

it('enforces an unchanged over-limit stored value where developers work and only reports it in production', function (): void {
    $page = Page::factory()->create();
    $definition = constrainedProperty($page, PropertyType::Text, [], 'code');
    SetPagePropertyValuesAction::run($page, [new PropertyValueData('code', PropertyType::Text, 'far too long for the later rule')]);
    UpdatePropertyDefinitionConstraintsAction::run($definition, ['max_length' => 5]);
    $same = [new PropertyValueData('code', PropertyType::Text, 'far too long for the later rule')];

    expect(fn (): mixed => SetPagePropertyValuesAction::run($page, $same))->toThrow(ValidationException::class);

    app()->detectEnvironment(fn (): string => 'production');
    $log = Log::spy();
    SetPagePropertyValuesAction::run($page, $same);

    $log->shouldHaveReceived('warning')->once();

    expect(fn (): mixed => SetPagePropertyValuesAction::run($page, [new PropertyValueData('code', PropertyType::Text, 'a different over-long value')]))
        ->toThrow(ValidationException::class);
});

it('refuses to restore a page whose unique value another page took while it was in the bin', function (): void {
    $page = Page::factory()->create();
    constrainedProperty($page, PropertyType::Text, ['unique_per_site' => true], 'code');
    $sibling = Page::factory()->create(['site_id' => $page->site_id, 'blueprint_id' => $page->blueprint_id]);
    propertyWriteErrors($page, 'code', PropertyType::Text, 'AUTUMN');
    $page->delete();
    propertyWriteErrors($sibling, 'code', PropertyType::Text, 'AUTUMN');

    expect(fn (): mixed => $page->restore())->toThrow(ValidationException::class)
        ->and(Page::withTrashed()->whereKey($page->id)->sole()->trashed())->toBeTrue();

    propertyWriteErrors($sibling, 'code', PropertyType::Text, 'WINTER');

    expect($page->restore())->toBeTrue();
});

it('will not make a property unique while pages on one site share a value', function (): void {
    $page = Page::factory()->create();
    $definition = constrainedProperty($page, PropertyType::Text, [], 'code');
    $sibling = Page::factory()->create(['site_id' => $page->site_id, 'blueprint_id' => $page->blueprint_id]);
    propertyWriteErrors($page, 'code', PropertyType::Text, 'SHARED');
    propertyWriteErrors($sibling, 'code', PropertyType::Text, 'SHARED');

    try {
        UpdatePropertyDefinitionConstraintsAction::run($definition, ['unique_per_site' => true]);
        $this->fail('Expected the unique rule to be refused.');
    } catch (ValidationException $validationException) {
        expect($validationException->errors()['unique_per_site'][0])->toStartWith('Some pages on the same site already share a value');
    }

    propertyWriteErrors($sibling, 'code', PropertyType::Text, 'OTHER');
    UpdatePropertyDefinitionConstraintsAction::run($definition, ['unique_per_site' => true]);

    expect($definition->refresh()->constraints?->uniquePerSite)->toBeTrue();
});

it("rejects a unique value repeated across one page's entries in a single write", function (): void {
    $page = Page::factory()->create();
    $set = PropertySet::factory()->create();
    PropertyDefinition::factory()->withConstraints(['unique_per_site' => true])->create([
        'property_set_id' => $set->id,
        'key' => 'codes',
        'type' => PropertyType::Text,
        'multiple' => true,
    ]);
    BlueprintPropertySet::factory()->create(['blueprint_id' => $page->blueprint_id, 'property_set_id' => $set->id]);

    expect(fn (): mixed => SetPagePropertyValuesAction::run($page, [
        new PropertyValueData('codes', PropertyType::Text, 'SAME', position: 0),
        new PropertyValueData('codes', PropertyType::Text, 'SAME', position: 1),
    ]))->toThrow(ValidationException::class, 'Codes must be different in each entry. Remove the repeated value.');

    SetPagePropertyValuesAction::run($page, [
        new PropertyValueData('codes', PropertyType::Text, 'ONE', position: 0),
        new PropertyValueData('codes', PropertyType::Text, 'TWO', position: 1),
    ]);

    expect(PagePropertyValue::query()->where('page_id', $page->id)->count())->toBe(2);
});

it('refuses a format that backtracks catastrophically when it is saved', function (): void {
    $definition = PropertyDefinition::factory()->create(['type' => PropertyType::Text]);

    try {
        UpdatePropertyDefinitionConstraintsAction::run($definition, ['pattern' => '(a+)+$']);
        $this->fail('Expected the pattern to be refused.');
    } catch (ValidationException $validationException) {
        expect($validationException->errors()['pattern'][0])->toStartWith('This format could take too long to check');
    }

    expect($definition->refresh()->constraints)->toBeNull();
});

it('treats a stored format that cannot finish checking as a failed check, not a pass', function (): void {
    $page = Page::factory()->create();
    // Written directly, as a package or older release could have stored it.
    constrainedProperty($page, PropertyType::Text, ['pattern' => '(a+)+$'], 'code');

    $started = microtime(true);
    $errors = propertyWriteErrors($page, 'code', PropertyType::Text, str_repeat('a', 40) . '!');

    expect($errors['code'][0] ?? null)->toStartWith('Code could not be checked against its required format')
        ->and(microtime(true) - $started)->toBeLessThan(5.0)
        ->and(propertyWriteErrors($page, 'code', PropertyType::Text, 'aaaa'))->toBe([]);
});

it('refuses a format that would escape the whole-value anchors when it is saved', function (string $pattern): void {
    $definition = PropertyDefinition::factory()->create(['type' => PropertyType::Text]);

    try {
        UpdatePropertyDefinitionConstraintsAction::run($definition, ['pattern' => $pattern]);
        $this->fail('Expected the pattern to be refused.');
    } catch (ValidationException $validationException) {
        expect($validationException->errors()['pattern'][0])->toStartWith('This format cannot be used.');
    }

    expect($definition->refresh()->constraints)->toBeNull();
})->with([
    'a group closed early into an alternation' => ['x)|(y'],
    'a control verb that ends the match early' => ['abc(*ACCEPT)'],
]);

it('fails a check against a stored format that would escape the whole-value anchors, instead of passing it', function (string $pattern, string $value): void {
    $page = Page::factory()->create();
    // Written directly, bypassing the update action's checks.
    constrainedProperty($page, PropertyType::Text, ['pattern' => $pattern], 'code');

    expect(propertyWriteErrors($page, 'code', PropertyType::Text, $value)['code'][0] ?? null)
        ->toStartWith('Code could not be checked against its required format');
})->with([
    'a group closed early into an alternation' => ['x)|(y', 'x and anything after it'],
    'a control verb that ends the match early' => ['abc(*ACCEPT)', 'abc and anything after it'],
]);

it('keeps escaped brackets and ordinary alternation working inside a format', function (): void {
    $page = Page::factory()->create();
    constrainedProperty($page, PropertyType::Text, ['pattern' => 'red|blue|\(\*green\)'], 'colour');

    expect(propertyWriteErrors($page, 'colour', PropertyType::Text, 'blue'))->toBe([])
        ->and(propertyWriteErrors($page, 'colour', PropertyType::Text, '(*green)'))->toBe([])
        ->and(propertyWriteErrors($page, 'colour', PropertyType::Text, 'blue sky')['colour'][0] ?? null)
        ->toStartWith('Colour is not in the format this field expects.');
});

it('skips resolving property definitions on a page save when no promoted field carries a rule', function (): void {
    $onOwnBlueprint = static fn (): Page => Page::factory()->create([
        'name' => 'Short',
        'blueprint_id' => Blueprint::factory()->page()->create()->id,
    ]);
    $plain = $onOwnBlueprint();
    $unconstrained = $onOwnBlueprint();
    promotedNameProperty($unconstrained);
    $constrained = $onOwnBlueprint();
    promotedNameProperty($constrained, ['max_length' => 10]);
    $definitionQueries = static function (Page $page): int {
        $page->refresh()->order = 7;
        DB::flushQueryLog();
        DB::enableQueryLog();
        ValidatePromotedPropertyValuesAction::run($page);
        DB::disableQueryLog();

        return collect(DB::getQueryLog())
            ->filter(static fn (array $query): bool => str_contains((string) $query['query'], (new PropertyDefinition)->getTable()))
            ->count();
    };

    // No promotion: no definition query at all. A promotion without rules:
    // one existence check, no resolution. A promotion with rules: resolved.
    expect($definitionQueries($plain))->toBe(0)
        ->and($definitionQueries($unconstrained))->toBe(1)
        ->and($definitionQueries($constrained))->toBeGreaterThan(1);
});

it("rejects a unique value already stored at another of the page's entries that the write leaves in place", function (): void {
    $page = Page::factory()->create();
    $set = PropertySet::factory()->create();
    PropertyDefinition::factory()->withConstraints(['unique_per_site' => true])->create([
        'property_set_id' => $set->id,
        'key' => 'codes',
        'type' => PropertyType::Text,
        'multiple' => true,
    ]);
    BlueprintPropertySet::factory()->create(['blueprint_id' => $page->blueprint_id, 'property_set_id' => $set->id]);
    SetPagePropertyValuesAction::run($page, [
        new PropertyValueData('codes', PropertyType::Text, 'ONE', position: 0),
        new PropertyValueData('codes', PropertyType::Text, 'TWO', position: 1),
    ]);

    expect(fn (): mixed => SetPagePropertyValuesAction::run($page, [
        new PropertyValueData('codes', PropertyType::Text, 'ONE', position: 1),
    ]))->toThrow(ValidationException::class, 'Codes must be different in each entry. Remove the repeated value.');

    // Rewriting both entries in one write may swap the values.
    SetPagePropertyValuesAction::run($page, [
        new PropertyValueData('codes', PropertyType::Text, 'TWO', position: 0),
        new PropertyValueData('codes', PropertyType::Text, 'ONE', position: 1),
    ]);

    expect(PagePropertyValue::query()->where('page_id', $page->id)->orderBy('position')->pluck('value_text')->all())->toBe(['TWO', 'ONE']);
});

it('checks restored property values when a page is rolled back, enforcing outside production and reporting in production', function (): void {
    $page = Page::factory()->create();
    constrainedProperty($page, PropertyType::Text, ['unique_per_site' => true], 'code');
    $sibling = Page::factory()->create(['site_id' => $page->site_id, 'blueprint_id' => $page->blueprint_id]);
    propertyWriteErrors($page, 'code', PropertyType::Text, 'AUTUMN');
    $serializer = resolve(PageStateSerializer::class);
    $snapshot = $serializer->capture($page->refresh());
    propertyWriteErrors($page, 'code', PropertyType::Text, 'WINTER');
    propertyWriteErrors($sibling, 'code', PropertyType::Text, 'AUTUMN');
    $code = static fn (): ?string => PagePropertyValue::query()->where('page_id', $page->id)->value('value_text');

    expect(fn () => $serializer->restore($page->refresh(), $snapshot))
        ->toThrow(ValidationException::class, 'Another page on this site already uses this Code. Enter a different value.')
        ->and($code())->toBe('WINTER');

    app()->detectEnvironment(static fn (): string => 'production');
    $log = Log::spy();
    $serializer->restore($page->refresh(), $snapshot);

    $log->shouldHaveReceived('warning')->once();
    expect($code())->toBe('AUTUMN');
});

it('treats a promoted number entered as 5.0 over a saved 5 as unchanged, on the page save and in the editor alike', function (): void {
    $page = Page::factory()->create(['name' => '5']);
    $set = PropertySet::factory()->create();
    $definition = PropertyDefinition::factory()->withConstraints(['max' => 3])->create([
        'property_set_id' => $set->id,
        'key' => 'rating',
        'type' => PropertyType::Number,
    ]);
    BlueprintPropertySet::factory()->create(['blueprint_id' => $page->blueprint_id, 'property_set_id' => $set->id]);
    PromoteBlueprintFieldAction::run(Blueprint::query()->findOrFail($page->blueprint_id), $definition, 'name');
    app()->detectEnvironment(static fn (): string => 'production');
    $log = Log::spy();

    $page->refresh()->name = '5.0';
    $page->save();

    // Reported once, as an unchanged value, instead of refused as a change.
    $log->shouldHaveReceived('warning')->once();
    expect($page->refresh()->name)->toBe('5.0');

    $page->name = '6';

    expect(fn (): bool => $page->save())->toThrow(ValidationException::class);
});

it('enforces every promoted field when a page moves to another blueprint, in production too', function (): void {
    app()->detectEnvironment(static fn (): string => 'production');
    $page = Page::factory()->create(['name' => 'An established page name']);
    $strict = Blueprint::factory()->page()->create();
    promotedNameProperty($page, ['max_length' => 10], $strict->id);
    $originalBlueprintId = $page->blueprint_id;

    $page->refresh()->blueprint_id = $strict->id;

    expect(fn (): bool => $page->save())->toThrow(ValidationException::class)
        ->and($page->refresh()->blueprint_id)->toBe($originalBlueprintId);
});

it('reports rather than refuses a repeat already stored on the page in production, but enforces it elsewhere', function (): void {
    $page = Page::factory()->create();
    $set = PropertySet::factory()->create();
    PropertyDefinition::factory()->create([
        'property_set_id' => $set->id,
        'key' => 'codes',
        'type' => PropertyType::Text,
        'multiple' => true,
    ]);
    BlueprintPropertySet::factory()->create(['blueprint_id' => $page->blueprint_id, 'property_set_id' => $set->id]);
    SetPagePropertyValuesAction::run($page, [
        new PropertyValueData('codes', PropertyType::Text, 'SAME', position: 0),
        new PropertyValueData('codes', PropertyType::Text, 'SAME', position: 1),
    ]);
    PropertyDefinition::query()->where('key', 'codes')->firstOrFail()
        ->forceFill(['constraints' => PropertyConstraintsData::fromStored(['unique_per_site' => true])])->save();
    $unchanged = [new PropertyValueData('codes', PropertyType::Text, 'SAME', position: 1)];

    expect(fn (): mixed => SetPagePropertyValuesAction::run($page, $unchanged))
        ->toThrow(ValidationException::class, 'Codes must be different in each entry. Remove the repeated value.');

    app()->detectEnvironment(static fn (): string => 'production');
    $log = Log::spy();
    SetPagePropertyValuesAction::run($page, $unchanged);

    $log->shouldHaveReceived('warning')->once();

    expect(fn (): mixed => SetPagePropertyValuesAction::run($page, [new PropertyValueData('codes', PropertyType::Text, 'SAME', position: 2)]))
        ->toThrow(ValidationException::class);
});
