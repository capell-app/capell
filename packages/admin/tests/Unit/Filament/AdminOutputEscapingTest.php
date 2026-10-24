<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Support\FlagIconRenderer;
use Capell\Admin\Filament\Components\Forms\BlueprintSelect;
use Capell\Admin\Filament\Components\Forms\ComponentSelect;
use Capell\Admin\Filament\Components\Forms\ConfiguratorSelect;
use Capell\Admin\Filament\Components\Forms\PageSelect;
use Capell\Admin\Filament\Components\Forms\SiteSelect;
use Capell\Admin\Filament\Components\Forms\SlugInput;
use Capell\Admin\Filament\Components\Forms\ThemeSelect;
use Capell\Admin\Filament\Components\Tables\Columns\Page\AncestorsColumn;
use Capell\Admin\Filament\Resources\Layouts\Pages\EditLayout;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Filament\Resources\PageUrls\Schemas\PageUrlForm;
use Capell\Admin\Filament\Resources\Sites\Pages\EditSite;
use Capell\Admin\Tests\Fixtures\Livewire;
use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Actions\GetPageUrlPathAction;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\ComponentAttributeBag;

function seedAdminHeadingLines(): void
{
    // The user, panel or an earlier test can switch the active locale; seed every locale __() may read.
    $locales = array_unique(['en', app()->getLocale(), app()->getFallbackLocale()]);

    foreach ($locales as $locale) {
        Lang::addLines([
            'heading.edit_type_record' => 'Edit blueprint: :type',
            'heading.edit_page_record' => 'Edit page: :name',
            'heading.edit_layout_record' => 'Edit layout: :name',
            'heading.edit_site_record' => 'Edit site: :name',
        ], $locale, 'capell-admin');
    }
}

beforeEach(function (): void {
    test()->actingAsAdmin();
    app()->setLocale('en');
    seedAdminHeadingLines();
});

it('escapes ancestor names and link attributes', function (): void {
    $name = '<b>"x\'</b>';
    bindFakeAction(GetEditPageResourceUrlAction::class, '/admin/pages/1?name="x\'');
    $html = (string) AncestorsColumn::make('ancestors')->formatState(new Page(['name' => $name]));

    expect($html)->toContain(e($name), e('/admin/pages/1?name="x\''))->not->toContain($name);
});

it('renders site option names as plain text', function (): void {
    $name = '<b>"x\'</b>';
    $component = SiteSelect::make('site_id')->options([1 => $name])->native()->searchable(false);
    $schema = Schema::make(Livewire::make())->statePath('data')->components([$component]);
    $schema->fill(['site_id' => 1]);

    $html = $component->toHtml();

    expect($html)->toContain(e($name))->not->toContain($name);
    // The JS select must use the same plain-text label contract as the native select.
    expect($component->isHtmlAllowed())->toBeFalse();
});

it('escapes plain blueprint relationship labels', function (): void {
    $name = '<b>"x\'</b>';
    $component = BlueprintSelect::make('blueprint_id')->withRelation();
    $schema = Schema::make(Livewire::make())->model(Site::class)->components([$component]);
    $schema->getComponents();

    $blueprint = Blueprint::factory()->site()->createOne(['name' => $name]);
    $options = $component->getOptionsFromRelationship();
    $this->assertIsArray($options);
    $this->assertArrayHasKey($blueprint->id, $options);
    $html = $options[$blueprint->id];

    expect($html)->toContain(e($name))->not->toContain($name);
});

it('escapes blueprint and page edit modal headings', function (string $kind): void {
    $name = '<b>"x\'</b>';
    $blueprint = new Blueprint(['name' => $name]);
    $record = $kind === 'blueprint' ? $blueprint : (new Page)->setRelation('blueprint', $blueprint);
    $component = $kind === 'blueprint' ? BlueprintSelect::make('selected') : PageSelect::make('selected');
    $schema = Schema::make(Livewire::make())->statePath('data')->operation('edit')->components([$component]);
    $schema->fill(['selected' => 1]);
    new ReflectionProperty($component, 'cachedSelectedRecord')->setValue($component, $record);
    // Newer Filament only trusts the cached record while its state and path still match.
    foreach (['cachedSelectedRecordState' => 1, 'cachedSelectedRecordStatePath' => $component->getStatePath()] as $property => $value) {
        if (new ReflectionClass(Select::class)->hasProperty($property)) {
            new ReflectionProperty($component, $property)->setValue($component, $value);
        }
    }

    $component->withEditForm();
    $configure = new ReflectionProperty($component, 'modifyEditOptionActionUsing')->getValue($component);
    $action = $component->evaluate($configure, ['action' => Action::make('editOption')->schemaComponent($component)]);
    $heading = new ReflectionProperty($action, 'modalHeading')->getValue($action);
    seedAdminHeadingLines();
    $html = (string) $action->evaluate($heading, ['context' => 'edit', 'component' => $component, 'state' => 1]);
    $expectedName = $kind === 'page' ? Str::title($name) : $name;

    expect($html)->toContain(e($expectedName))->not->toContain('<b>', '<B>');
})->with(['blueprint', 'page']);

it('escapes editor names in record headings', function (string $kind): void {
    $name = '<b>"x\'</b>';
    [$editor, $record] = match ($kind) {
        'page' => [new EditPage, new Page(['name' => $name])->setRelation('site', new Site(['name' => $name]))],
        'layout' => [new EditLayout, new Layout(['name' => $name])],
        'site' => [new EditSite, new Site(['name' => $name])],
        default => throw new InvalidArgumentException('Unknown editor kind: ' . $kind),
    };
    $editor->record = $record;
    $title = $editor instanceof EditSite ? $editor->getPageTitle() : $editor->getTitle();
    $html = $title instanceof Htmlable ? $title->toHtml() : $title;

    expect($html)->toContain(e($name))->not->toContain($name);
})->with(['page', 'layout', 'site']);

it('encodes language tooltip HTML in every flag view', function (string $view, bool $translations): void {
    $name = '<b>"x\'</b>';
    $language = Language::factory()->create(['name' => $name, 'flag' => 'flag-4x3-gb']);
    $record = $translations
        ? (object) ['translations' => collect([(object) ['language' => $language]])]
        : (object) ['language' => $language, 'translated_locales' => json_encode([$language->code])];
    $viewName = match ($view) {
        'components.tables.columns.language-flags' => 'capell-admin::components.tables.columns.language-flags',
        'components.tables.columns.locale-flags' => 'capell-admin::components.tables.columns.locale-flags',
        'components.forms.site.site_domain_item_label' => 'capell-admin::components.forms.site.site_domain_item_label',
        default => throw new InvalidArgumentException('Unknown flag view: ' . $view),
    };
    $html = view($viewName, [
        'language' => $language,
        'url' => '/',
        'attributes' => new ComponentAttributeBag,
        'getRecord' => fn (): object => $record,
        'getName' => fn (): string => 'languages',
        'getExtraAttributes' => fn (): array => [],
        'isInline' => fn (): bool => false,
        'getAlignment' => fn (): null => null,
    ])->render();
    if ($html === '') {
        $this->fail('Expected non-empty flag view HTML.');
    }

    $dom = new DOMDocument;
    $this->assertTrue(@$dom->loadHTML($html));
    $flags = new DOMXPath($dom)->query('//*[@x-tooltip.raw]');
    $this->assertNotFalse($flags);
    $flag = $flags->item(0);

    $this->assertInstanceOf(DOMElement::class, $flag);
    expect($flag->getAttribute('x-tooltip.raw'))->toBe(e($name));
    expect($html)->not->toContain($name);
})->with([
    ['components.tables.columns.language-flags', false],
    ['components.tables.columns.language-flags', true],
    ['components.tables.columns.locale-flags', false],
    ['components.forms.site.site_domain_item_label', false],
]);

it('escapes unregistered component and configurator option labels', function (string $kind): void {
    $name = '<b>"x\'</b>';
    $component = $kind === 'component'
        ? ComponentSelect::make('selected')->setupType('page')
        : ConfiguratorSelect::make('selected')->setupOptions('Page');
    $schema = Schema::make(Livewire::make())->statePath('data')->components([$component]);
    $schema->fill(['selected' => $name]);

    $html = $component->getOptions()[$name];

    expect($html)->toContain(e($name))->not->toContain($name);
})->with(['component', 'configurator']);

it('escapes both ancestor URL and current slug in the URL helper', function (): void {
    $name = '<b>"x\'</b>';
    $site = Site::factory()->withTranslations()->createOne();
    $language = $site->language;
    $child = Page::factory()->site($site)->createOne();
    bindFakeAction(GetPageUrlPathAction::class, 'https://example.test/' . $name . '/');
    $schema = PageUrlForm::configure(
        Schema::make(Livewire::make())->model(PageUrl::class)->statePath('data')->operation('create'),
    );
    $schema->fill([
        'site_id' => $site->getKey(), 'language_id' => $language->getKey(),
        'pageable_type' => $child->getMorphClass(), 'pageable_id' => $child->getKey(),
        'type' => null, 'url' => $name,
    ]);
    $field = array_find($schema->getFlatComponents(), fn (mixed $component): bool => $component instanceof TextInput && $component->getName() === 'url');
    $this->assertInstanceOf(TextInput::class, $field);
    $html = $field->toHtml();

    expect($html)->toContain(e($name))->not->toContain($name);
    expect(substr_count($html, e($name)))->toBe(2);
});

it('rejects malformed slug paths while keeping normal page URL paths', function (string $path, bool $valid): void {
    $schema = PageUrlForm::configure(
        Schema::make(Livewire::make())->model(PageUrl::class)->statePath('data')->operation('create'),
    );
    $schema->fill(['type' => null, 'url' => $path]);

    $field = array_find($schema->getFlatComponents(), fn (mixed $component): bool => $component instanceof TextInput && $component->getName() === 'url');
    $this->assertInstanceOf(TextInput::class, $field);
    $rules = array_filter($field->getValidationRules(), fn (mixed $rule): bool => $rule instanceof Closure);

    expect(validator(['url' => $path], ['url' => $rules])->passes())->toBe($valid);
})->with([
    ['/', true], ['/ordinary-page_2/file.html', true], ['/trailing/', true],
    ['/.well-known/security.txt', true], ['/release..notes', true], ['/file.', true], ['/.../page', true],
    ['/<b>"x\'</b>', false], ["/page\n", false], ["/page\r\n", false],
    ['/.', false], ['/..', false], ['/./page', false], ['/../page', false],
    ['/page/.', false], ['/page/..', false], ['/page/./child', false], ['/page/../child', false],
    ['//page', false], ['/double//slash', false], ['/page//', false], ['/page\\child', false],
]);

it('escapes arbitrary flag renderer attributes without breaking the attribute boundary', function (): void {
    $name = '<b>"x\'</b>';
    $html = resolve(FlagIconRenderer::class)
        ->render('flag-4x3-gb', $name, attributes: ['title' => $name])->toHtml();

    expect($html)->toContain('title="' . e($name) . '"')->not->toContain($name);
    if ($html === '') {
        $this->fail('Expected non-empty flag renderer HTML.');
    }

    $dom = new DOMDocument;
    $this->assertTrue(@$dom->loadHTML($html));
    $flag = $dom->getElementsByTagName('span')->item(0);
    $this->assertInstanceOf(DOMElement::class, $flag);
    expect($flag->getAttribute('title'))->toBe($name);
});

it('escapes the image URL attribute in the theme helper', function (): void {
    $name = '<b>"x\'</b>';
    $component = ThemeSelect::make('theme_id');
    $schema = Schema::make(Livewire::make())->model(Site::class)->statePath('data')->components([$component]);
    $schema->fill(['theme_id' => 1]);
    new ReflectionProperty($component, 'cachedSelectedRecord')->setValue($component, new Theme([
        'name' => $name, 'admin' => ['image' => $name],
    ]));
    $html = $component->toHtml();

    expect($html)->toContain(e($name))->not->toContain($name);
});

it('escapes placeholder and autocomplete attributes in the slug input', function (): void {
    view()->share('errors', new ViewErrorBag);
    $name = '<b>"x\'</b>';
    $component = SlugInput::make('slug')
        ->slugInputLabelPrefix('')->placeholder($name)->autocomplete($name)->slugInputRecordSlug(fn (): string => 'page');
    $schema = Schema::make(Livewire::make())->statePath('data')->components([$component]);
    $schema->fill(['slug' => 'page']);

    $html = $component->toHtml();

    expect($html)->toContain('placeholder="' . e($name) . '"', 'autocomplete="' . e($name) . '"')->not->toContain($name);
});
