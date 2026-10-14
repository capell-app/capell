<?php

declare(strict_types=1);

use Capell\Admin\Actions\RestoreDeletedTranslationForLanguageAction;
use Capell\Admin\Filament\Actions\Page\CreatePageAction;
use Capell\Admin\Filament\Components\Forms\Page\ParentSelect;
use Capell\Admin\Filament\Components\Forms\TranslationsRepeater;
use Capell\Admin\Filament\Resources\Pages\Pages\CreatePage;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Tests\Fixtures\Livewire as FormLivewire;
use Capell\Core\Enums\BlueprintGroupEnum;
use Capell\Core\Enums\PageTypeEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\post;

uses(CreatesAdminUser::class)->group('page');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('creates content pages using a non-system fallback in the full form and quick create', function (bool $quickCreate): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->createOne();
    Blueprint::factory()->page()->group(BlueprintGroupEnum::System->value)->create([
        'key' => PageTypeEnum::NotFound->value,
        'order' => 0,
    ]);
    $contentType = Blueprint::factory()->page()->group('marketing')->create(['order' => 10]);
    $parent = Page::factory()->site($site)->for($contentType, 'blueprint')->withTranslations()->createOne();
    $name = 'New content page';

    $livewire = $quickCreate
        ? Livewire::test(EditPage::class, ['record' => $parent->getRouteKey()])
            ->mountAction(TestAction::make(CreatePageAction::class))
        : Livewire::test(CreatePage::class);

    $livewire->assertFormSet(['blueprint_id' => $contentType->id])
        ->fillForm(['name' => $name, 'site_id' => $site->id]);

    if ($quickCreate) {
        $livewire->callMountedAction();
    } else {
        $livewire->call('create');
    }

    $livewire->assertHasNoFormErrors();
    assertDatabaseHas(Page::class, ['name' => $name, 'blueprint_id' => $contentType->id]);
})->with([false, true]);

it('offers same-site content parents across blueprint groups in the full form and quick create', function (bool $quickCreate): void {
    $site = Site::factory()->withTranslations()->createOne();
    $contentType = Blueprint::factory()->page()->default()->createOne();
    $marketingType = Blueprint::factory()->page()->group('marketing')->createOne();
    $parent = Page::factory()->site($site)->for($marketingType, 'blueprint')->withTranslations()->createOne();
    $otherSite = Site::factory()->language($site->language)->withTranslations()->createOne();
    $otherSitePage = Page::factory()->site($otherSite)->for($marketingType, 'blueprint')->withTranslations()->createOne();
    $systemType = Blueprint::factory()->page()->group(BlueprintGroupEnum::System->value)->createOne();
    $systemPage = Page::factory()->site($site)->for($systemType, 'blueprint')->withTranslations()->createOne();
    $editorPage = Page::factory()->site($site)->for($contentType, 'blueprint')->withTranslations()->createOne();

    $livewire = $quickCreate
        ? Livewire::test(EditPage::class, ['record' => $editorPage->getRouteKey()])
            ->mountAction(TestAction::make(CreatePageAction::class))
        : Livewire::test(CreatePage::class);
    $livewire->fillForm(['site_id' => $site->id]);

    $schemaName = $quickCreate ? $livewire->instance()->getMountedActionSchemaName() : 'form';
    $schema = $livewire->instance()->getSchema(expectPresent($schemaName));
    $select = expectPresent($schema)->getComponent('parent_id');
    expect($select)->toBeInstanceOf(ParentSelect::class);
    assert($select instanceof ParentSelect);

    expect($select->getOptions())->toHaveKey($parent->id)
        ->not->toHaveKey($otherSitePage->id)
        ->not->toHaveKey($systemPage->id);
    expect($select->getSearchResults($parent->name))->toHaveKey($parent->id);

    $livewire->fillForm(['name' => 'Child content page', 'parent_id' => $parent->id]);
    if ($quickCreate) {
        $livewire->callMountedAction();
    } else {
        $livewire->call('create');
    }

    $livewire->assertHasNoFormErrors();
    assertDatabaseHas(Page::class, ['name' => 'Child content page', 'parent_id' => $parent->id]);
})->with([false, true]);

it('saves a translation added through the edit page repeater action', function (PageTypeEnum $pageType): void {
    $english = Language::factory()->createOne(['default' => true]);
    $french = Language::factory()->createOne(['default' => false]);
    $site = Site::factory()->language($english)->withTranslations([$english, $french])->createOne();
    $type = $pageType->createPageType();
    $page = Page::factory()->site($site)->for($type, 'blueprint')->withTranslations($english)->createOne();

    $livewire = Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->callAction(TestAction::make('add')->schemaComponent('translations', schema: 'form')->arguments([
            'language_id' => $french->id,
        ]));

    $translations = $livewire->get('data.translations');
    expect($translations)->toBeArray();
    $translationKey = collect($translations)->search(fn (array $translation): bool => (int) ($translation['language_id'] ?? 0) === $french->id);
    expect($translationKey)->not->toBeFalse();

    $livewire->set('data.translations.' . $translationKey . '.title', 'Nouvelle traduction')
        ->call('save')
        ->assertHasNoFormErrors();

    assertDatabaseHas(Translation::class, [
        'translatable_id' => $page->id,
        'translatable_type' => $page->getMorphClass(),
        'language_id' => $french->id,
        'title' => 'Nouvelle traduction',
    ]);
    expect($page->refresh()->translations)->toHaveCount(2);
})->with([PageTypeEnum::Default, PageTypeEnum::NotFound, PageTypeEnum::Maintenance]);

it('lets the same editor renew the content lock after a translation save fails', function (): void {
    $english = Language::factory()->createOne(['default' => true]);
    $french = Language::factory()->createOne(['default' => false]);
    $site = Site::factory()->language($english)->withTranslations([$english, $french])->createOne();
    $type = PageTypeEnum::NotFound->createPageType();
    $page = Page::factory()->site($site)->for($type, 'blueprint')->withTranslations($english)->createOne();
    $livewire = Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->callAction(TestAction::make('add')->schemaComponent('translations', schema: 'form')->arguments([
            'language_id' => $french->id,
        ]));

    $translationKey = collect($livewire->get('data.translations'))->search(
        fn (array $translation): bool => (int) ($translation['language_id'] ?? 0) === $french->id,
    );
    expect($translationKey)->not->toBeFalse();
    $livewire->set('data.translations.' . $translationKey . '.title', 'Failed translation');

    Event::listen('eloquent.creating: ' . Translation::class, function (): void {
        throw new RuntimeException('Controlled translation insert failure.');
    });

    expect(fn () => $livewire->call('save'))->toThrow(RuntimeException::class, 'Controlled translation insert failure.');

    post(route('capell-admin.api.pages.content-lock.heartbeat', [
        'page' => $page,
        'type' => $page->getMorphClass(),
    ]))->assertSuccessful();
});

it('reuses a deleted language translation when the editor adds it again', function (bool $normalizeJson): void {
    $english = Language::factory()->createOne(['default' => true]);
    $french = Language::factory()->createOne(['default' => false]);
    $site = Site::factory()->language($english)->withTranslations([$english, $french])->createOne();
    $type = PageTypeEnum::Default->createPageType();
    $page = Page::factory()->site($site)->for($type, 'blueprint')->withTranslations([$english, $french])->createOne();
    $originalEnglish = $page->translations->firstWhere('language_id', $english->id);
    $deletedFrench = $page->translations->firstWhere('language_id', $french->id);
    throw_unless($originalEnglish instanceof Translation && $deletedFrench instanceof Translation, RuntimeException::class, 'Expected both fixture translations.');
    $originalEnglish->forceFill(['meta' => [
        ...($originalEnglish->meta ?? []),
        'summary' => null,
        'label' => null,
        'link_text' => null,
        'title' => null,
        'description' => null,
        'keywords' => null,
    ]])->save();
    if ($normalizeJson) {
        $meta = array_reverse($originalEnglish->meta ?? [], preserve_keys: true);
        $storedMeta = '{' . collect($meta)->map(
            fn (mixed $value, string $key): string => json_encode($key, JSON_THROW_ON_ERROR) . ': ' . json_encode($value, JSON_THROW_ON_ERROR),
        )->implode(', ') . '}';
        DB::table($originalEnglish->getTable())->where('id', $originalEnglish->id)->update(['meta' => $storedMeta]);
    }

    $englishAttributes = $originalEnglish->refresh()->getAttributes();
    $deletedFrench->forceFill(['meta' => [...($deletedFrench->meta ?? []), 'undeclared_metadata' => 'retained']])->save();
    $deletedFrench->delete();

    $livewire = Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->callAction(TestAction::make('add')->schemaComponent('translations', schema: 'form')->arguments([
            'language_id' => $french->id,
        ]));
    $translationKey = collect($livewire->get('data.translations'))->search(
        fn (array $translation): bool => (int) ($translation['language_id'] ?? 0) === $french->id,
    );
    throw_unless(is_string($translationKey), RuntimeException::class, 'The added French translation is missing.');
    $this->travel(2)->seconds();
    $livewire->set('data.translations.' . $translationKey . '.title', 'Traduction rétablie')
        ->call('save')->assertHasNoFormErrors();

    expect($deletedFrench->refresh()->trashed())->toBeFalse()
        ->and($deletedFrench->title)->toBe('Traduction rétablie')
        ->and($deletedFrench->meta['undeclared_metadata'] ?? null)->toBe('retained')
        ->and($originalEnglish->refresh()->getAttributes())->toBe($englishAttributes)
        ->and($page->translations()->withTrashed()->count())->toBe(2);
})->with(['original JSON' => false, 'MySQL-shaped JSON' => true]);

it('restores only translations belonging to the supplied relationship owner', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->language($language)->withTranslations($language)->createOne();
    $page = Page::factory()->site($site)->withTranslations($language)->createOne();
    $otherPage = Page::factory()->site($site)->withTranslations($language)->createOne();
    $otherTranslation = $otherPage->translations->sole();
    $otherTranslation->delete();

    expect(RestoreDeletedTranslationForLanguageAction::run($page->translations()->getQuery(), $language->id))->toBeNull()
        ->and($otherTranslation->refresh()->trashed())->toBeTrue();
});

it('fires saved for an unchanged translation through the page editor without updating its timestamp or raw meta', function (): void {
    $language = Language::factory()->createOne(['default' => true]);
    $site = Site::factory()->language($language)->withTranslations($language)->createOne();
    $type = PageTypeEnum::Default->createPageType();
    $page = Page::factory()->site($site)->for($type, 'blueprint')->withTranslations($language)->createOne();
    $translation = $page->translations->sole();
    $translation->forceFill(['meta' => [
        ...($translation->meta ?? []),
        'summary' => null,
        'label' => null,
        'link_text' => null,
        'title' => null,
        'description' => null,
        'keywords' => null,
    ]])->save();
    $storedMeta = '{' . collect(array_reverse($translation->meta ?? [], preserve_keys: true))->map(
        fn (mixed $value, string $key): string => json_encode($key, JSON_THROW_ON_ERROR) . ': ' . json_encode($value, JSON_THROW_ON_ERROR),
    )->implode(', ') . '}';
    DB::table($translation->getTable())->where('id', $translation->id)->update(['meta' => $storedMeta]);
    $original = $translation->refresh()->getAttributes();
    $livewire = Livewire::test(EditPage::class, ['record' => $page->getRouteKey()]);
    $saved = 0;
    Event::listen('eloquent.saved: ' . Translation::class, function (Translation $record) use ($translation, &$saved): void {
        if ($record->is($translation)) {
            $saved++;
        }
    });

    $this->travel(2)->seconds();
    $livewire->call('save')->assertHasNoFormErrors();

    expect($saved)->toBe(1)
        ->and($translation->refresh()->getAttributes())->toBe($original)
        ->and($translation->getRawOriginal('updated_at'))->toBe($original['updated_at'])
        ->and($translation->getRawOriginal('meta'))->toBe($original['meta']);
});

it('persists nested relationship edits on a restored translation model', function (bool $editPrimaryFields): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->language($language)->withTranslations($language)->createOne();
    $page = Page::factory()->site($site)->withTranslations($language)->createOne();
    $translation = $page->translations->sole();
    $translation->delete();

    $title = $editPrimaryFields ? 'Restored title' : $translation->title;
    $meta = $editPrimaryFields ? ['primary_marker' => 'Primary form edit'] : $translation->meta;

    $livewire = FormLivewire::make()->data(['translations' => [
        'new-language' => ['language_id' => $language->id, 'title' => $title, 'meta' => $meta, 'nested_marker' => 'Nested relationship edit'],
    ]]);
    $schema = Schema::make($livewire)->model($page)->statePath('data')->components([
        TranslationsRepeater::make('translations')->tabs([
            TextInput::make('language_id'),
            TextInput::make('title'),
            TextInput::make('meta.primary_marker'),
            TextInput::make('nested_marker')->dehydrated(false)
                ->saveRelationshipsUsing(function (TextInput $component): void {
                    $record = $component->getRecord();
                    throw_unless($record instanceof Translation && $record->exists, RuntimeException::class, 'Nested relationships require a persisted translation.');
                    $record->forceFill(['meta' => [...($record->meta ?? []), 'nested_marker' => $component->getRawState()]])->save();
                }),
        ]),
    ]);

    $schema->saveRelationships();

    expect($translation->refresh()->trashed())->toBeFalse()
        ->and($translation->title)->toBe($title)
        ->and($translation->meta['nested_marker'] ?? null)->toBe('Nested relationship edit')
        ->and($translation->meta['primary_marker'] ?? null)->toBe($meta['primary_marker'] ?? null)
        ->and($page->translations()->withTrashed()->count())->toBe(1);
})->with(['primary and nested edits' => true, 'nested edits only' => false]);

it('rolls back a restored translation when restoration or persistence is cancelled', function (string $failure, string $message): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->language($language)->withTranslations($language)->createOne();
    $page = Page::factory()->site($site)->withTranslations($language)->createOne();
    $translation = $page->translations->sole();
    $translation->delete();

    $originalAttributes = $translation->getAttributes();
    $state = ['new-language' => ['language_id' => $language->id, 'title' => 'Edited title', 'nested_marker' => 'Edited nested value']];
    $livewire = FormLivewire::make()->data(['translations' => $state]);
    $repeater = TranslationsRepeater::make('translations')->tabs([
        TextInput::make('language_id'),
        TextInput::make('title'),
        TextInput::make('nested_marker')->dehydrated(false)
            ->saveRelationshipsUsing(function () use ($failure): void {
                throw_if($failure === 'nested-save', RuntimeException::class, 'Controlled nested persistence failure.');
            }),
    ])->mutateRelationshipDataBeforeSaveUsing(fn (array $data): ?array => $failure === 'parent-save' ? null : $data);
    $schema = Schema::make($livewire)->model($page)->statePath('data')->components([$repeater]);

    if ($failure === 'restore') {
        Event::listen('eloquent.restoring: ' . Translation::class, fn (): false => false);
    }

    expect(fn () => $schema->saveRelationships())->toThrow(RuntimeException::class, $message);
    expect($translation->refresh()->getAttributes())->toBe($originalAttributes)
        ->and($page->translations()->count())->toBe(0)
        ->and($page->translations()->withTrashed()->count())->toBe(1)
        ->and($livewire->data['translations'])->toBe($state);
})->with([
    ['restore', 'Could not restore the deleted translation.'],
    ['parent-save', 'The restored translation save was cancelled.'],
    ['nested-save', 'Controlled nested persistence failure.'],
]);
