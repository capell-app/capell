<?php

declare(strict_types=1);

use Capell\Admin\Filament\Actions\Page\CreatePageAction;
use Capell\Admin\Filament\Components\Forms\Page\ParentSelect;
use Capell\Admin\Filament\Resources\Pages\Pages\CreatePage;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Core\Enums\BlueprintGroupEnum;
use Capell\Core\Enums\PageTypeEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\Testing\TestAction;
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
