<?php

declare(strict_types=1);

use Capell\Admin\Filament\Actions\Page\ReplicatePageAction as HeaderReplicatePageAction;
use Capell\Admin\Filament\Actions\Table\ReplicatePageAction as RowReplicatePageAction;
use Capell\Admin\Filament\Components\Forms\Page\SiteSelect;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Filament\Resources\Pages\Pages\ListPages;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Livewire\Livewire;

uses(CreatesAdminUser::class)->group('page');

beforeEach(function (): void {
    $this->actingAsAdmin();
    $english = Language::factory()->english()->createOne(['default' => true]);
    $french = Language::factory()->french()->createOne(['default' => false]);
    $site = Site::factory()->language($english)->withTranslations([$english, $french])->createOne();
    $this->page = Page::factory()->createOne(['site_id' => $site->id, 'name' => 'Atlas parent']);

    foreach ([$english, $french] as $language) {
        $this->page->translations()->create([
            'language_id' => $language->id,
            'title' => 'Public title ' . $language->id,
            'content' => json_encode(['type' => 'doc', 'content' => [['type' => 'paragraph', 'attrs' => ['textAlign' => 'start'], 'content' => [['type' => 'text', 'text' => 'Original content']]]]], JSON_THROW_ON_ERROR),
            'meta' => ['slug' => 'atlas-parent', 'description' => 'Original description'],
        ]);
    }

    $this->page->refresh()->load('translations');
});

it('prefills the required name for replicate', function (bool $header): void {
    $component = $header
        ? Livewire::test(EditPage::class, ['record' => $this->page->id])
        : Livewire::test(ListPages::class);
    $action = $header ? HeaderReplicatePageAction::class : TestAction::make(RowReplicatePageAction::class)->table($this->page);

    $component->mountAction($action)->assertActionDataSet(['name' => 'Atlas parent (2)'])->assertFormFieldVisible('name');
    $component->assertFormFieldExists('name', function (TextInput $field): bool {
        $parent = $field->getContainer()->getParentComponent();
        while ($parent instanceof Component) {
            if ($parent instanceof Section) {
                expect($parent->isCollapsed())->toBeFalse();
            }

            $parent = $parent->getContainer()->getParentComponent();
        }

        return $field->isRequired();
    });
})->with(['header' => true, 'row' => false]);

it('reconciles the destination site before saving a replica', function (bool $header): void {
    $siteA = $this->page->site;
    $german = Language::factory()->createOne(['name' => 'German', 'code' => 'de', 'default' => false]);
    $siteB = Site::factory()->language($german)->withTranslations($german)->createOne();
    $layoutA = Layout::factory()->site($siteA)->createOne();
    $layoutB = Layout::factory()->site($siteB)->createOne();
    $parentA = Page::factory()->site($siteA)->layout($layoutA)->createOne();
    $this->page->update(['layout_id' => $layoutA->id, 'parent_id' => $parentA->id]);
    $component = $header
        ? Livewire::test(EditPage::class, ['record' => $this->page->id])
        : Livewire::test(ListPages::class);
    $action = $header ? HeaderReplicatePageAction::class : TestAction::make(RowReplicatePageAction::class)->table($this->page);
    $component->mountAction($action);
    $schemaName = expectPresent($component->instance()->getMountedActionSchemaName());
    $statePath = 'mountedActions.0.data';
    $originals = collect($component->get($statePath . '.translations'))->keyBy('language_id');
    $component->set($statePath . '.site_id', $siteB->id)
        ->assertFormSet(['parent_id' => null, 'layout_id' => null], $schemaName)
        ->assertNotified(__('capell-admin::message.page_site_choices_cleared'));
    $translations = collect($component->get($statePath . '.translations'))->keyBy('language_id');
    expect($translations->keys()->all())->toContain($german->id);
    foreach ($originals as $languageId => $original) {
        expect($translations[$languageId])->toMatchArray($original);
    }

    $schema = expectPresent($component->instance()->getSchema($schemaName));
    $siteSelect = $schema->getComponent('site_id');
    assert($siteSelect instanceof SiteSelect);
    $warning = $siteSelect->getChildSchema(SiteSelect::BELOW_CONTENT_SCHEMA_KEY)?->toHtml();
    foreach ($this->page->load('translations.language')->translations as $translation) {
        expect($warning)->toContain(e($translation->language->name));
    }

    $component->fillForm(['layout_id' => $layoutB->id], $schemaName)
        ->callMountedAction()->assertHasActionErrors(['site_id']);
    expect(Page::query()->where('site_id', $siteB->id)->exists())->toBeFalse();
    $component->set($statePath . '.site_id', $siteA->id);
    foreach ($originals as $languageId => $original) {
        expect(collect($component->get($statePath . '.translations'))->keyBy('language_id')[$languageId])->toMatchArray($original);
    }

    $component->set($statePath . '.site_id', $siteB->id);
    $confirmation = TestAction::make('useSiteLanguages')->schemaComponent('site_id', schema: $schemaName);
    $component->mountAction($confirmation)->assertActionMounted($confirmation);
    expect($component->get($statePath . '.translations'))->toHaveCount(3);
    $component->callMountedAction();
    expect(collect($component->get($statePath . '.translations'))->pluck('language_id')->all())->toBe([$german->id]);
    $component->fillForm(['layout_id' => $layoutB->id, 'parent_id' => null], $schemaName)
        ->callMountedAction()->assertHasNoActionErrors();
    $copy = Page::query()->where('site_id', $siteB->id)->sole();
    expect($copy->translations()->sole()->language_id)->toBe($german->id)
        ->and($copy->layout_id)->toBe($layoutB->id)
        ->and($this->page->fresh()->translations)->toHaveCount(2);
})->with(['header' => true, 'row' => false]);

it('replicates the default state twice with unique urls in every language', function (bool $header): void {
    foreach ([1, 2] as $attempt) {
        $component = $header
            ? Livewire::test(EditPage::class, ['record' => $this->page->id])
            : Livewire::test(ListPages::class);
        $action = $header ? HeaderReplicatePageAction::class : TestAction::make(RowReplicatePageAction::class)->table($this->page);
        $component->mountAction($action)->callMountedAction()->assertHasNoActionErrors();

        $copy = Page::query()->whereKeyNot($this->page->id)->latest('id')->firstOrFail();
        expect($copy->name)->toBe('Atlas parent (' . ($attempt + 1) . ')')
            ->and($copy->uuid)->not->toBe($this->page->uuid)
            ->and($copy->layout_id)->toBe($this->page->layout_id)
            ->and($copy->translations)->toHaveCount(2)
            ->and($copy->pageUrls)->toHaveCount(2);
        foreach ($this->page->translations as $original) {
            $translation = $copy->translations->firstWhere('language_id', $original->language_id);
            expect($translation)->toBeInstanceOf(Translation::class);
            assert($translation instanceof Translation);
            expect($translation->title)->toBe($original->title)
                ->and($translation->content)->toBe($original->content)
                ->and($translation->meta['description'] ?? null)->toBe('Original description')
                ->and($translation->slug)->not->toBe($original->slug)
                ->and($copy->pageUrls->firstWhere('language_id', $original->language_id)?->url)->toBe('/' . $translation->slug);
        }
    }

    foreach ($this->page->fresh()->translations as $original) {
        expect($original->slug)->toBe('atlas-parent')
            ->and($original->title)->toBe('Public title ' . $original->language_id);
    }

    expect(Page::query()->count())->toBe(3)
        ->and(Translation::query()->where('translatable_type', $this->page->getMorphClass())->count())->toBe(6)
        ->and(PageUrl::query()->count())->toBe(6)
        ->and(PageUrl::query()->get()->unique(fn (PageUrl $url): string => $url->language_id . ':' . $url->url))->toHaveCount(6);
})->with(['header' => true, 'row' => false]);

it('reports a late replicate url collision as a form error and rolls back every copy', function (bool $header): void {
    $translations = [];
    foreach ($this->page->translations as $index => $translation) {
        $translations['record-' . $translation->id] = [
            'language_id' => $translation->language_id,
            'title' => $translation->title,
            'meta' => ['slug' => $index === 0 ? 'available-copy' : 'reserved-copy'],
        ];
    }

    $last = $this->page->translations->last();
    assert($last instanceof Translation);
    PageUrl::factory()->createOne([
        'site_id' => $this->page->site_id,
        'language_id' => $last->language_id,
        'url' => '/reserved-copy',
        'is_manual' => true,
        'status' => true,
        'pageable_type' => null,
        'pageable_id' => null,
    ]);
    $counts = [Page::query()->count(), Translation::query()->count(), PageUrl::query()->count()];
    $component = $header
        ? Livewire::test(EditPage::class, ['record' => $this->page->id])
        : Livewire::test(ListPages::class);
    $action = $header ? HeaderReplicatePageAction::class : TestAction::make(RowReplicatePageAction::class)->table($this->page);

    $component->callAction($action, data: ['name' => 'Collision copy', 'translations' => $translations])
        ->assertSuccessful()
        ->assertHasActionErrors(['translations.record-' . $last->id . '.meta.slug'])
        ->assertNotNotified(__('capell-admin::message.replicate_success', ['name' => $this->page->name]));

    expect([Page::query()->count(), Translation::query()->count(), PageUrl::query()->count()])->toBe($counts);
})->with(['header' => true, 'row' => false]);

it('preserves custom slugs when replicating', function (bool $header): void {
    $translations = [];
    foreach ($this->page->translations as $translation) {
        $translations['record-' . $translation->id] = [
            'language_id' => $translation->language_id,
            'title' => 'Custom title',
            'meta' => ['slug' => 'custom-' . $translation->language_id],
        ];
    }

    $component = $header
        ? Livewire::test(EditPage::class, ['record' => $this->page->id])
        : Livewire::test(ListPages::class);
    $action = $header ? HeaderReplicatePageAction::class : TestAction::make(RowReplicatePageAction::class)->table($this->page);
    $component->callAction($action, data: ['name' => 'Custom copy', 'translations' => $translations])->assertHasNoActionErrors();

    $copy = Page::query()->where('name', 'Custom copy')->firstOrFail();
    expect($copy->translations)->toHaveCount(2);
    foreach ($copy->translations as $translation) {
        expect($translation->title)->toBe('Custom title')
            ->and($translation->slug)->toBe('custom-' . $translation->language_id)
            ->and($copy->pageUrls->firstWhere('language_id', $translation->language_id)?->url)->toBe('/custom-' . $translation->language_id);
    }
})->with(['header' => true, 'row' => false]);

it('rejects an explicitly reused source slug as a replicate form error', function (bool $header): void {
    $translations = [];
    $errors = [];
    $translationIds = Translation::query()->orderBy('id')->pluck('id')->all();
    foreach ($this->page->translations as $translation) {
        $key = 'record-' . $translation->id;
        $translations[$key] = $translation->only($translation->getFillable());
        $errors['translations.' . $key . '.meta.slug'] = 'unique';
    }

    $component = $header
        ? Livewire::test(EditPage::class, ['record' => $this->page->id])
        : Livewire::test(ListPages::class);
    $action = $header ? HeaderReplicatePageAction::class : TestAction::make(RowReplicatePageAction::class)->table($this->page);

    $component->callAction($action, data: ['name' => 'Rejected copy', 'translations' => $translations])
        ->assertSuccessful()
        ->assertHasActionErrors($errors);

    expect(Page::query()->count())->toBe(1)
        ->and(Translation::query()->count())->toBe(4)
        ->and(Translation::query()->orderBy('id')->pluck('id')->all())->toBe($translationIds)
        ->and(PageUrl::query()->count())->toBe(2);
})->with(['header' => true, 'row' => false]);

it('chooses replicate suffixes independently for each language including manual urls', function (bool $header): void {
    $last = $this->page->translations->last();
    assert($last instanceof Translation);
    PageUrl::factory()->createOne([
        'site_id' => $this->page->site_id,
        'language_id' => $last->language_id,
        'url' => '/atlas-parent-copy',
        'is_manual' => true,
        'status' => true,
        'pageable_type' => null,
        'pageable_id' => null,
    ]);
    $component = $header
        ? Livewire::test(EditPage::class, ['record' => $this->page->id])
        : Livewire::test(ListPages::class);
    $action = $header ? HeaderReplicatePageAction::class : TestAction::make(RowReplicatePageAction::class)->table($this->page);
    $component->callAction($action)->assertHasNoActionErrors();

    $copy = Page::query()->whereKeyNot($this->page->id)->sole();
    foreach ($copy->translations as $translation) {
        expect($translation->slug)->toBe($translation->language_id === $last->language_id ? 'atlas-parent-copy-2' : 'atlas-parent-copy');
    }

    expect($copy->pageUrls)->toHaveCount(2);
})->with(['header' => true, 'row' => false]);

it('replicates nested and home urls with the existing language paths', function (bool $header, bool $nested): void {
    if ($nested) {
        $parent = Page::factory()->createOne(['site_id' => $this->page->site_id]);
        foreach ($this->page->translations as $translation) {
            $parent->translations()->create([
                'language_id' => $translation->language_id,
                'title' => 'Parent',
                'meta' => ['slug' => 'parent-' . $translation->language_id],
            ]);
        }

        $this->page->parent()->associate($parent);
        $this->page->save();
    } else {
        foreach ($this->page->translations as $translation) {
            $translation->update(['meta' => ['slug' => '/']]);
        }
    }

    $component = $header
        ? Livewire::test(EditPage::class, ['record' => $this->page->id])
        : Livewire::test(ListPages::class);
    $action = $header ? HeaderReplicatePageAction::class : TestAction::make(RowReplicatePageAction::class)->table($this->page);
    $component->callAction($action)->assertHasNoActionErrors();

    $copy = Page::query()->where('name', 'Atlas parent (2)')->sole();
    expect($copy->parent_id)->toBe($this->page->parent_id)
        ->and($copy->translations)->toHaveCount(2)
        ->and($copy->pageUrls)->toHaveCount(2);
    foreach ($copy->pageUrls as $url) {
        expect($url->url)->toBe($nested ? '/parent-' . $url->language_id . '/atlas-parent-copy' : '/copy');
    }
})->with([
    'header nested' => [true, true],
    'row nested' => [false, true],
    'header home' => [true, false],
    'row home' => [false, false],
]);
