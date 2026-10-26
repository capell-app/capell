<?php

declare(strict_types=1);

use Capell\Admin\Actions\Themes\CreateAvailableThemeAction;
use Capell\Admin\Actions\Themes\CreateCustomThemeAction;
use Capell\Admin\Filament\Components\Forms\Page\TranslationsRepeater;
use Capell\Admin\Filament\Resources\Languages\Pages\ManageLanguages;
use Capell\Admin\Filament\Resources\Layouts\Pages\EditLayout;
use Capell\Admin\Filament\Resources\Pages\Pages\CreatePage;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Filament\Resources\Sites\Pages\CreateSite;
use Capell\Admin\Filament\Resources\Themes\Pages\ManageThemes;
use Capell\Admin\Tests\Unit\Filament\Components\Forms\Fixtures\PageTranslationsRepeaterLivewireForTest;
use Capell\Admin\Tests\Unit\Filament\Concerns\Fixtures\BlueprintDeleteValidationHarness;
use Capell\Admin\Tests\Unit\Filament\Concerns\Fixtures\PageDeleteValidationHarness;
use Capell\Core\Actions\SiteDomains\FindSiteDomainConflictAction;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Tests\Support\FilamentNotificationSnapshots;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Translation\Translator;
use Livewire\Livewire;

beforeEach(function (): void {
    test()->actingAsAdmin();
    // Applications can translate these messages with the supplied record names.
    __('capell-admin::message.page_not_deletable');
    resolve(Translator::class)->addLines([
        'message.page_not_deletable' => 'Cannot delete :name',
        'message.page_page_type_not_deletable' => 'Blueprint: :name',
        'message.type_not_deletable' => 'Cannot delete :name',
        'message.layout_not_deletable' => 'Cannot delete :name',
        'message.language_not_deletable' => 'Cannot delete :name',
        'message.theme_not_deletable' => 'Cannot delete :name',
        'message.site_domain_not_unique' => 'Site: :name',
    ], 'en', 'capell-admin');
});

it('renders record names as plain text in validation notifications', function (string $kind): void {
    $name = '<b>"x\'</b>';
    switch ($kind) {
        case 'page':
            $blueprint = Blueprint::factory()->page()->createOne(['name' => $name, 'admin' => ['deletable' => false]]);
            $record = Page::factory()->type($blueprint)->createOne(['name' => $name]);
            (new PageDeleteValidationHarness)->validateDelete($record);
            break;
        case 'blueprint':
            $record = Blueprint::factory()->page()->createOne(['name' => $name]);
            Page::factory()->type($record)->createOne();
            (new BlueprintDeleteValidationHarness)->validateDelete($record);
            break;
        case 'layout':
            $record = Layout::factory()->createOne(['name' => $name]);
            Page::factory()->layout($record)->createOne();
            (new EditLayout)->validateDelete($record);
            break;
        case 'language':
            $record = Language::factory()->createOne(['name' => $name]);
            Site::factory()->language($record)->createOne();
            (new ManageLanguages)->validateDelete($record);
            break;
        case 'theme':
            $record = Theme::factory()->createOne(['name' => $name]);
            Site::factory()->createOne(['theme_id' => $record->getKey()]);
            (new ManageThemes)->validateDelete($record);
            break;
        case 'site':
            $site = Site::factory()->createOne(['name' => $name]);
            $domain = new SiteDomain(['domain' => 'example.test', 'site_id' => $site->getKey()])->setRelation('site', $site);
            bindFakeAction(FindSiteDomainConflictAction::class, $domain);
            CreateSite::validateExists([]);
            break;
    }

    $notification = FilamentNotificationSnapshots::pull()->reverse()->firstOrFail();
    $html = $notification->toHtml();

    expect($notification->getTitle())->toContain(e($name))->not->toContain($name);
    expect($html)->toContain('&lt;b&gt;', '&lt;/b&gt;')->not->toContain($name);
    if ($kind === 'page') {
        expect($notification->getBody())->toContain(e($name))->not->toContain($name);
    }
})->with(['page', 'blueprint', 'layout', 'language', 'theme', 'site']);

it('escapes names in both theme creation notifications', function (bool $available): void {
    $name = '<b>"x\'</b>';
    $theme = Theme::factory()->createOne(['name' => $name]);
    if ($available) {
        bindFakeAction(CreateAvailableThemeAction::class, $theme);
        Livewire::test(ManageThemes::class)->call('createAvailableTheme', 'example');
    } else {
        bindFakeAction(CreateCustomThemeAction::class, $theme);
        $component = Livewire::test(ManageThemes::class)->instance();
        new ReflectionMethod(ManageThemes::class, 'handleCreateTheme')->invoke($component, ['custom_name' => $name]);
    }

    $notification = FilamentNotificationSnapshots::pull()->reverse()->firstOrFail();

    expect($notification->getTitle())->toContain(e($name))->not->toContain($name);
    expect($notification->toHtml())->toContain('&lt;b&gt;', '&lt;/b&gt;')->not->toContain($name);
})->with([true, false]);

it('escapes language names in parent coverage warnings', function (string $kind): void {
    $name = '<b>"x\'</b>';
    resolve(Translator::class)->addLines(['message.page_language_parent' => 'Language: :name'], 'en', 'capell-admin');
    $english = Language::factory()->english()->createOne();
    $other = Language::factory()->french()->createOne(['name' => $name]);
    $site = Site::factory()->language($english)->withTranslations([$english, $other])->createOne();
    $parent = Page::factory()->site($site)->withTranslations($english)->createOne();
    $child = Page::factory()->site($site)->makeOne();
    $data = [
        'name' => 'Child', 'site_id' => $site->getKey(), 'parent_id' => $parent->getKey(),
        'translations' => [['language_id' => $other->getKey()]],
    ];
    if ($kind === 'create') {
        Livewire::test(CreatePage::class)
            ->fillForm($data)->call('create')
            ->assertHasFormErrors(['parent_id'])
            ->assertSee(e($name), escape: false)
            ->assertDontSee($name, escape: false);

        // The final save guard must still escape names if coverage changes after form validation.
        $component = new CreatePage;
        $component->data = $data;
        expect(fn (): mixed => new ReflectionMethod($component, 'afterValidate')->invoke($component))->toThrow(Halt::class);
    } elseif ($kind === 'edit') {
        $component = new EditPage;
        $component->record = $child;
        $component->data = $data;
        expect(new ReflectionMethod($component, 'validateParentLanguages')->invoke($component))->toBeFalse();
    } else {
        $livewire = new PageTranslationsRepeaterLivewireForTest;
        $livewire->data($data);
        $repeater = TranslationsRepeater::make('translations')->withoutRelationship();
        $schema = Schema::make($livewire)->statePath('data')->operation('edit')->model($child)->components([
            Section::make('page')->schema([$repeater]),
        ]);
        $schema->getFlatComponents();
        $repeater->state([]);
        $repeater->getAddAction()->call([
            'component' => $repeater, 'arguments' => ['language_id' => $other->getKey()],
        ]);
        expect(array_column($repeater->getState(), 'language_id'))->toBe([$other->getKey()]);

        // Adding retains the draft input; saving remains blocked by missing parent languages.
        $component = new CreatePage;
        $component->data = array_replace($data, ['translations' => $repeater->getState()]);
        expect(fn (): mixed => new ReflectionMethod($component, 'afterValidate')->invoke($component))->toThrow(Halt::class);
    }

    $notification = FilamentNotificationSnapshots::pull()->reverse()->firstOrFail();

    expect($notification->getTitle())->toContain(e($name))->not->toContain($name);
    expect($notification->toHtml())->toContain('&lt;b&gt;', '&lt;/b&gt;')->not->toContain($name);
})->with(['create', 'edit', 'repeater']);
