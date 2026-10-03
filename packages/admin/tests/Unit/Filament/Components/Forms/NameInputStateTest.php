<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\NameInput;
use Capell\Admin\Filament\Components\Forms\Page\NameInput as PageNameInput;
use Capell\Admin\Filament\Components\Forms\Page\SettingsSchema;
use Capell\Admin\Tests\Fixtures\Livewire;
use Filament\Schemas\Schema;
use Sinnbeck\DomAssertions\Asserts\AssertElement;
use Symfony\Component\Process\Process;

/** @param array<string, mixed> $data */
function mountedNameInputForTest(NameInput $field, array $data, string $operation): NameInput
{
    Schema::make(Livewire::make()->data($data))
        ->operation($operation)
        ->statePath('data')
        ->components([$field])
        ->getComponents();

    return $field;
}

/**
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function runNameUpdaterJsForTest(NameInput $field, array $data, string $operation, bool $typing = false): array
{
    $field = mountedNameInputForTest($field, $data, $operation);
    $process = new Process(['node', __DIR__ . '/Fixtures/run-name-updater.mjs']);
    $process->setInput(json_encode([
        'scripts' => $field->getAfterStateUpdatedJs(),
        'changeScript' => $field->getExtraInputAttributes()['x-on:change'] ?? '',
        'typing' => $typing,
        'state' => $data['name'],
        'data' => $data,
    ], JSON_THROW_ON_ERROR));
    $process->mustRun();

    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    assert(is_array($result));

    return $result;
}

it('seeds only blank page titles and preserves existing and manual slugs on the server', function (string $operation): void {
    $data = [
        'name' => 'Internal reference',
        'translations' => [
            'english' => ['title' => 'Public English title', 'meta' => ['slug' => 'english-url']],
            'french' => ['title' => 'Titre français', 'meta' => ['slug' => 'french-url']],
            'blank' => ['title' => '', 'meta' => []],
            'manual' => ['title' => null, 'meta' => ['slug' => 'manual-url'], 'slug_auto_update_disabled' => true],
            'locked-blank' => ['title' => null, 'meta' => [], 'slug_auto_update_disabled' => true],
        ],
    ];
    $field = mountedNameInputForTest(PageNameInput::make('name')->withTitleUpdater(), $data, $operation);
    $field->callAfterStateUpdated();

    $translations = $field->getGetCallback()('translations');

    expect($translations)->toMatchArray([
        'english' => $data['translations']['english'],
        'french' => $data['translations']['french'],
        'blank' => ['title' => 'Internal reference', 'meta' => ['slug' => 'internal-reference']],
        'manual' => ['title' => 'Internal reference', 'meta' => ['slug' => 'manual-url'], 'slug_auto_update_disabled' => true],
        'locked-blank' => ['title' => 'Internal reference', 'meta' => [], 'slug_auto_update_disabled' => true],
    ]);
})->with(['create', 'createOption', 'replicate']);

it('preserves multilingual public titles and manual slug state in the page client updater', function (string $operation): void {
    $data = [
        'name' => 'Internal reference',
        'translations' => [
            'english' => ['title' => 'Public English title', 'meta' => ['slug' => 'english-url']],
            'french' => ['title' => 'Titre français', 'meta' => ['slug' => 'french-url']],
            'blank' => ['title' => ' ', 'meta' => []],
            'manual' => ['title' => '', 'meta' => ['slug' => 'manual-url'], 'is_slug_changed_manually' => true],
        ],
    ];
    $result = runNameUpdaterJsForTest(PageNameInput::make('name')->withTitleUpdater(), $data, $operation);

    expect(data_get($result, 'translations.english'))->toBe($data['translations']['english'])
        ->and(data_get($result, 'translations.french'))->toBe($data['translations']['french'])
        ->and(data_get($result, 'translations.blank.title'))->toBe('Internal reference')
        ->and(data_get($result, 'translations.manual.title'))->toBe('Internal reference')
        ->and(data_get($result, 'translations.manual.meta.slug'))->toBe('manual-url')
        ->and(data_get($result, 'translations.manual.is_slug_changed_manually'))->toBeTrue();
})->with(['create', 'createOption', 'replicate']);

it('keeps explicit generic translated titles when an internal name changes', function (string $operation): void {
    $data = ['name' => 'Internal reference', 'translations' => ['english' => ['title' => 'Public title']]];

    expect(runNameUpdaterJsForTest(NameInput::make('name')->withTitleUpdater(), $data, $operation))->toBe($data);
})->with(['create', 'createOption', 'replicate', 'edit']);

it('still supplies the initial generic translated title', function (): void {
    $result = runNameUpdaterJsForTest(NameInput::make('name')->withTitleUpdater(), [
        'name' => 'Internal reference', 'translations' => ['english' => ['title' => '']],
    ], 'create');

    expect(data_get($result, 'translations.english.title'))->toBe('Internal reference');
});

it('leaves page titles alone when editing the name or clearing it', function (string $operation, string $name): void {
    $data = ['name' => $name, 'translations' => ['english' => ['title' => 'Public title']]];
    $field = mountedNameInputForTest(PageNameInput::make('name')->withTitleUpdater(), $data, $operation);
    $field->callAfterStateUpdated();

    expect($field->getGetCallback()('translations'))->toBe($data['translations'])
        ->and(runNameUpdaterJsForTest(PageNameInput::make('name')->withTitleUpdater(), $data, $operation))->toBe($data);
})->with([['edit', 'Changed internal name'], ['create', '']]);

it('preserves a distinct public title in both page settings schema variants', function (string $variant): void {
    $data = ['name' => 'Internal reference', 'translations' => ['english' => ['title' => 'Public title']]];
    $schema = Schema::make(Livewire::make()->data($data))->operation('replicate');
    $components = $variant === 'legacy' ? SettingsSchema::make($schema) : SettingsSchema::pageConfiguration($schema);
    $field = $components[0];
    assert($field instanceof PageNameInput);
    $field = mountedNameInputForTest($field, $data, 'replicate');
    $field->callAfterStateUpdated();

    expect($field->getGetCallback()('translations'))->toBe($data['translations'])
        ->and(runNameUpdaterJsForTest($field, $data, 'replicate'))->toBe($data);
})->with(['legacy', 'page-configuration']);

/** @param class-string<NameInput> $fieldClass */
it('seeds the completed name instead of the first keystroke in the client', function (string $fieldClass): void {
    $result = runNameUpdaterJsForTest($fieldClass::make('name')->withTitleUpdater(), [
        'name' => 'Internal reference',
        'translations' => ['english' => ['title' => '']],
    ], 'create', typing: true);

    expect(data_get($result, 'translations.english.title'))->toBe('Internal reference');
})->with([[NameInput::class], [PageNameInput::class]]);

it('seeds a generic title on deferred submit without a client change event', function (string $operation): void {
    $data = [
        'name' => 'Completed internal name',
        'translations' => [
            'english' => ['title' => '', 'meta' => ['slug' => 'manual-url'], 'slug_auto_update_disabled' => true],
            'french' => ['title' => 'Titre français'],
        ],
    ];
    $field = mountedNameInputForTest(NameInput::make('name')->withTitleUpdater(), $data, $operation);
    // Enter can submit before blur/change. Only the PHP update hook runs here.
    $field->callAfterStateUpdated();

    expect($field->getState())->toBe('Completed internal name')
        ->and($field->getGetCallback()('translations'))->toBe([
            'english' => ['title' => 'Completed internal name', 'meta' => ['slug' => 'manual-url'], 'slug_auto_update_disabled' => true],
            'french' => ['title' => 'Titre français'],
        ]);
})->with(['create', 'createOption', 'replicate', 'edit']);

it('preserves an explicit generic title on deferred submit without a client change event', function (): void {
    $data = ['name' => 'Internal reference', 'translations' => ['english' => ['title' => 'Public title', 'meta' => ['slug' => 'manual-url']]]];
    $field = mountedNameInputForTest(NameInput::make('name')->withTitleUpdater(), $data, 'create');
    $field->callAfterStateUpdated();

    expect($field->getState())->toBe('Internal reference')
        ->and($field->getGetCallback()('translations'))->toBe($data['translations']);
});

/** @param class-string<NameInput> $fieldClass */
it('binds completed-name seeding to the native input change event', function (string $fieldClass): void {
    $field = mountedNameInputForTest($fieldClass::make('name')->withTitleUpdater(), [
        'name' => '', 'translations' => ['english' => ['title' => '']],
    ], 'create');

    new AssertElement($field->toHtml())->find('input', function (AssertElement $input): void {
        $input->has('wire:model', 'data.name')
            ->has('x-on:change');
    });

    expect($field->getAfterStateUpdatedJs())->toBe([]);
})->with([[NameInput::class], [PageNameInput::class]]);
