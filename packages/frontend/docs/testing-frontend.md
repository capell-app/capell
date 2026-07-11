# Testing Capell Frontend Pages

![Capell Testing Capell Frontend Pages screenshot](./images/screenshots/frontend-published-page.png)

Frontend page tests should prove the public site output works, not that the admin editor stored a particular internal shape. Start from the smallest useful surface:

- use an Action or unit test for resolver, cache, or render-data behavior
- use a Blade view test when one Capell component owns the markup
- use an HTTP feature test when routing, site/language resolution, theme rendering, or public safety matters

Capell 4 includes `sinnbeck/laravel-dom-assertions` in its dev dependencies and registers `DomAssertionsServiceProvider` in `Capell\Tests\AbstractTestCase`. Use it for structure. Keep `assertSee()` and `assertDontSee()` for cheap smoke checks and public-output safety checks.

Host apps should install the same package when they test their theme output:

```bash
composer require sinnbeck/laravel-dom-assertions --dev
```

## Recommended Shape

For an app or theme test, do not create a factory record just to prove fixed site content exists. Seed/import the page content in your normal test setup, request the public URL, and assert the rendered output.

```php
<?php

declare(strict_types=1);

use Sinnbeck\DomAssertions\Asserts\AssertElement;
use Sinnbeck\DomAssertions\Asserts\BaseAssert;

it('renders the public about page heading', function (): void {
    $this->get('/about')
        ->assertOk()
        ->assertElementExists(
            'main.capell-layout-main',
            fn (AssertElement $main): BaseAssert => $main
                ->find('h1.capell-page-title', fn (AssertElement $heading): BaseAssert => $heading
                    ->containsText('Acme Studios'))
                ->containsText('Independent publishing and events.'),
        );
});
```

This is the pattern to copy for most app/theme tests: request the route your users visit and assert the DOM under stable public hooks. If the content is a business requirement, the test should read like that requirement.

If CI starts from an empty database, seed the required Capell page in the app's test setup or import a small fixture. Do not hide fixed content behind a factory state unless the factory is the agreed source of that page.

Use factories when the test is about Capell behavior or package behavior and the test needs to own the page fixture: routing, language resolution, page types, cache, component rendering, or public HTML safety. Keep the factory data as explicit strings, not faker output, so failures are readable.

## What To Assert

Prefer assertions that describe the user-facing contract:

- the request resolves successfully for the expected site, domain, language, and path
- the page title is rendered in the expected heading
- the main public component exists: `main.capell-layout-main`
- Capell component hooks exist where themes rely on them: `capell-component`, `capell-page-title`, `capell-components-content`, `capell-widgets-content`, `capell-page-results`, `capell-media-index`
- important page, widget, or theme content appears in the right region
- links have the expected `href`
- lists use the expected count and roles where the component defines them
- public HTML does not expose authoring/runtime internals

Avoid asserting generated model IDs, admin selectors, editor attributes, package paths, field names, signed URLs, or exact Tailwind class lists unless that class is the public contract being tested.

## `assertSee()` vs DOM Assertions

Use `assertSee()` for broad text smoke checks:

```php
$this->get('/about')
    ->assertOk()
    ->assertSee('Acme Studios');
```

Use DOM assertions when the position, element, count, or attributes matter:

```php
$this->get('/about')
    ->assertOk()
    ->assertElementExists(
        'main.capell-layout-main',
        fn (AssertElement $main): BaseAssert => $main
            ->find('h1.capell-page-title', fn (AssertElement $heading): BaseAssert => $heading
                ->containsText('Acme Studios')),
    );
```

Use absence assertions for public safety:

```php
$this->get($page->pageUrl->full_url)
    ->assertOk()
    ->assertDontSee('wire:navigate', false)
    ->assertDontSee('x-data=', false)
    ->assertDontSee('window.beaconData', false)
    ->assertDontSee('/livewire/', false)
    ->assertDontSee('capell-editor', false)
    ->assertDontSee('authoring-surface', false);
```

That mirrors Capell's own static public page tests: the important contract is that a normal public page does not ship Livewire, Alpine, beacon data, or authoring controls unless a package deliberately provides a public runtime.

## View-Level Component Tests

When the behavior belongs to one frontend component, test the view directly. This is faster than a full HTTP request and keeps the fixture small.

```php
<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Frontend\Support\State\FrontendState;
use Sinnbeck\DomAssertions\Asserts\AssertElement;
use Sinnbeck\DomAssertions\Asserts\BaseAssert;

it('renders page result cards with titles and read more links', function (): void {
    $language = Language::factory()->english()->create();
    $theme = Theme::factory()->defaultMeta()->create();
    $site = Site::factory()
        ->language($language)
        ->theme($theme)
        ->withTranslations($language)
        ->create();

    $page = Page::factory()
        ->site($site)
        ->withTranslations($language, [
            'title' => 'Result Card Title',
            'summary' => 'Short result card summary.',
            'meta' => ['slug' => 'result-card-title'],
        ])
        ->create();

    $pageUrl = PageUrl::factory()
        ->site($site)
        ->language($language)
        ->page($page)
        ->create(['url' => '/result-card-title']);

    $page->load('translation');
    $page->setRelation('pageUrl', $pageUrl);

    resolve(FrontendState::class)
        ->withLanguage($language)
        ->withSite($site)
        ->withTheme($theme)
        ->withPage($page);

    $this->view('capell::components.page.results', [
        'results' => collect([$page]),
    ])->assertElementExists(
        '.capell-page-results [role="list"]',
        fn (AssertElement $list): BaseAssert => $list
            ->contains('[role="listitem"]', 1)
            ->find('a[href="/result-card-title"]', fn (AssertElement $link): BaseAssert => $link
                ->containsText('Result Card Title')),
    );
});
```

Use this style for Capell-owned components such as `capell::components.page.results`, `capell::components.media.index`, `capell-layout-builder::components.layout-widgets.content`, and small package frontend components.

## Theme Output Tests

Theme tests should prove the theme can render the public page contract Capell gives it. Do not test admin layout-builder internals from a theme test unless the package owns that integration.

```php
<?php

declare(strict_types=1);

use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Contracts\ThemeRenderer;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemePageData;
use Capell\Core\ThemeStudio\Data\ThemePresetData;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Sinnbeck\DomAssertions\Asserts\AssertElement;
use Sinnbeck\DomAssertions\Asserts\BaseAssert;

it('renders the public page through the active theme', function (): void {
    $theme = Theme::factory()->createOne(['key' => 'campaign']);
    $layout = Layout::factory()->default()->create();
    $site = Site::factory()
        ->theme($theme)
        ->withTranslations(siteDomainData: ['domain' => 'campaign.test', 'scheme' => 'https'])
        ->create();

    Page::factory()
        ->site($site)
        ->layout($layout)
        ->home()
        ->withTranslations(data: [
            'title' => 'Campaign Home',
            'content' => '<p>Hero proof point.</p>',
        ], slug: '/')
        ->create(['meta' => null]);

    resolve(ThemeRegistry::class)->register(
        new ThemeDefinitionData(
            key: 'campaign',
            name: 'Campaign',
            description: 'Theme fixture for public rendering tests.',
            package: 'app/theme-campaign',
            previewImage: '',
            tags: [],
            bestFit: [],
            includedSections: [],
            presets: [
                new ThemePresetData(
                    key: 'default',
                    name: 'Default',
                    description: 'Default campaign preset.',
                    previewImage: '',
                ),
            ],
        ),
        new class implements ThemeRenderer
        {
            public function themeKey(): string
            {
                return 'campaign';
            }

            public function render(ThemePageData $page): string
            {
                $hero = $page->sections[0] ?? null;
                $summary = method_exists($hero, 'toViewData')
                    ? ($hero->toViewData()['section']->summary ?? '')
                    : '';

                return '<main class="capell-component capell-layout-main campaign-main">'
                    . '<h1 class="capell-component capell-page-title">' . e($page->title) . '</h1>'
                    . '<section class="campaign-content">' . e($summary) . '</section>'
                    . '</main>';
            }
        },
        [],
    );

    $this->get('/', ['HTTP_HOST' => 'campaign.test', 'HTTPS' => 'on'])
        ->assertOk()
        ->assertElementExists(
            'main.campaign-main',
            fn (AssertElement $main): BaseAssert => $main
                ->find('h1.capell-page-title', fn (AssertElement $heading): BaseAssert => $heading
                    ->containsText('Campaign Home'))
                ->find('.campaign-content', fn (AssertElement $content): BaseAssert => $content
                    ->containsText('Hero proof point.')),
        );
});
```

For a real theme package, replace the inline renderer with the package's registered renderer/view and assert the same public contract.

## Layout And Widget Content

If a test is about final site output, assert the rendered DOM, not the layout data model:

```php
$this->get($page->pageUrl->full_url)
    ->assertOk()
    ->assertElementExists(
        'main.capell-layout-main',
        fn (AssertElement $main): BaseAssert => $main
            ->find('.capell-widgets-content', fn (AssertElement $widget): BaseAssert => $widget
                ->containsText('Editorial intro')
                ->contains('a', ['href' => '/about', 'text' => 'About Capell'])),
    );
```

If a test is about the package that builds the layout graph, test that package's Action directly and assert the graph contains the expected container, element, and payload. Then keep the frontend test focused on proving the graph is rendered into public HTML.

## Multi-Language Pages

Use explicit language and domain setup when the URL shape matters:

```php
<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Sinnbeck\DomAssertions\Asserts\AssertElement;
use Sinnbeck\DomAssertions\Asserts\BaseAssert;

it('renders the translated title for a language-prefixed page', function (): void {
    $english = Language::factory()->english()->create(['code' => 'en']);
    $welsh = Language::factory()->createOne(['code' => 'cy', 'locale' => 'cy']);

    $site = Site::factory()
        ->language($english)
        ->withTranslations([$english, $welsh])
        ->create();

    SiteDomain::factory()
        ->site($site)
        ->enabled()
        ->create([
            'language_id' => $welsh->id,
            'domain' => 'localhost',
            'path' => '/cy',
            'scheme' => 'https',
        ]);

    $page = Page::factory()
        ->site($site)
        ->withTranslations([
            $english->id => ['title' => 'About Capell'],
            $welsh->id => ['title' => 'Amdanom ni'],
        ])
        ->create();

    $welshUrl = $page->pageUrls->firstWhere('language_id', $welsh->id);

    $this->get($welshUrl->full_url)
        ->assertOk()
        ->assertElementExists(
            'h1.capell-page-title',
            fn (AssertElement $heading): BaseAssert => $heading
                ->containsText('Amdanom ni')
                ->doesntContainText('About Capell'),
        );
});
```

## Public Safety Checks

Every test that changes public rendering, cache output, theme rendering, render hooks, or package frontend views should include at least one safety assertion.

```php
$response = $this->get($page->pageUrl->full_url);

$response
    ->assertOk()
    ->assertDontSee('authoring-surface', false)
    ->assertDontSee('capell-editor', false)
    ->assertDontSee('data-field-path', false)
    ->assertDontSee('signed-editor-url', false)
    ->assertDontSee('/admin/pages/', false);
```

For deeper safety coverage in Capell package tests, use `Capell\Frontend\Actions\AssertPublicHtmlContainsNoAuthoringSurfaceAction`, `Capell\Frontend\Actions\AssertPublicRenderContractAction`, or the public HTML safety inspector tests as the source of truth.

## Blade View Coverage

Capell also runs a ratcheted Blade coverage gate for package views:

```bash
composer coverage:blade
```

The gate only counts views Laravel actually renders. Reading a Blade file as source does not count, so prefer route, Livewire, or direct view tests for frontend components. See [Blade view coverage](../../../docs/development/blade-view-coverage.md) for the baseline workflow.

## Patterns To Follow

- Test through a public URL when route, host, language, theme, cache, or middleware behavior matters.
- Use view tests for isolated components; they are faster and easier to read.
- Use `assertElementExists()` around the smallest stable parent, then assert child structure inside it.
- Use Capell public classes as hooks: `capell-layout-main`, `capell-page-title`, `capell-components-content`, `capell-widgets-content`, `capell-page-results`.
- Assert important content appears in the intended region, not just somewhere in the response.
- Assert counts for repeated cards, navigation items, results, and media grids.
- Keep theme tests focused on public output. Test layout-builder storage and graph-building in the package that owns those internals.

## Patterns To Avoid

- Regex over raw HTML.
- Snapshotting the whole response body.
- Testing exact Tailwind class strings unless the class is a documented public hook.
- Asserting editor/admin attributes in public page tests.
- Building huge datasets unless the test is about query count, pagination, or cache behavior.
- Testing the same content with both `assertSee()` and a DOM assertion unless the text-only assertion is a deliberate smoke check.
