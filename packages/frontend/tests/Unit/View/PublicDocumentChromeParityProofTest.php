<?php

declare(strict_types=1);

use Capell\Core\Contracts\PublicRenderData;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Themes\ThemeChromeRegistry;
use Capell\Frontend\Data\PublicLayoutShellData;
use Capell\Frontend\Support\State\FrontendState;
use Capell\Tests\Fixtures\Models\User;
use Capell\Tests\Support\ScriptFixture;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

require_once dirname(__DIR__, 5) . '/tests/Support/FrontendViewFixture.php';

function documentChromeSite(): Site
{
    $site = resolve(FrontendState::class)->site();
    throw_unless($site instanceof Site, RuntimeException::class, 'The frontend view fixture did not resolve a site.');

    return $site;
}

function documentChromeHtml(string $html): string
{
    return trim((string) preg_replace(['/\s+>/', '/>\s+</', '/\s+/'], ['>', '><', ' '], $html));
}

it('preserves the frozen document chrome HTML for anonymous and signed-in visitors', function (bool $signedIn, string $template): void {
    if ($signedIn) {
        $visitor = new User(['name' => 'Private visitor']);
        $this->actingAs($visitor);
        $this->assertAuthenticatedAs($visitor);
    } else {
        $this->assertGuest();
    }

    ['page' => $page, 'media' => $media] = frontendViewFixture();
    $layout = new Layout(['key' => 'landing', 'meta' => ['body_class' => 'layout-extra']]);
    $theme = new Theme(['meta' => ['rounded_images' => true, 'body_class' => 'theme-extra']]);
    resolve(FrontendState::class)->withTheme($theme)->withLayout($layout);

    $render = static fn (): string => documentChromeHtml(Blade::render($template, [
        'page' => $page, 'media' => $media, 'layout' => $layout, 'theme' => $theme,
    ]));

    $connection = DB::connection();
    $connection->enableQueryLog();
    try {
        $actual = $render();
        expect($connection->getQueryLog())->toBe([]);
    } finally {
        $connection->disableQueryLog();
    }

    $base = dirname(__DIR__, 2) . '/Fixtures/stage-four-base-public-views';
    view()->prependNamespace('capell', $base);
    view()->getFinder()->flush();
    expect(view()->getFinder()->find('capell::components.content'))->toStartWith($base . '/');
    expect($actual)->toBe($render())
        ->and($actual)->not->toContain('Private visitor')
        ->and($actual)->not->toContain('data-capell-authoring');
})->with(['anonymous' => false, 'signed in' => true])->with([
    'body' => ['<x-capell::app.body :layout="$layout" :language="null" :page-record="$page" :site="null" :theme="$theme" body-class="external">Body slot</x-capell::app.body>'],
    'content from frontend context' => ['<x-capell::content content="&lt;p&gt;Public content&lt;/p&gt;" title="Public heading" :image="$media" />'],
    'content with explicit records' => ['<x-capell::content content="&lt;p&gt;Public content&lt;/p&gt;" title="Public heading" heading-style="secondary" :page-record="$page" :layout="$layout" :theme="$theme" divider="below_heading" />'],
    'content without image' => ['<x-capell::content content="&lt;p&gt;Public content&lt;/p&gt;" heading-size="h2" text-align="center" />'],
    'content image without title keeps media alt text' => ['<x-capell::content content="&lt;p&gt;Public content&lt;/p&gt;" :title="null" :image="$media" />'],
    'rounded media' => ['<x-capell::media :media="$media" alt="Public image" />'],
]);

it('renders the site schema from typed data in the public document', function (): void {
    frontendViewFixture();
    resolve(FrontendState::class)->withTheme(new Theme(['key' => 'public-theme']));
    $site = documentChromeSite();
    $site->meta = ['logo_blade_view' => false, 'custom_meta_schema' => '{"@type":"Organization","name":"Public site"}'];

    $html = view('capell::app', [
        'layout' => new Layout(['key' => 'landing']),
        'theme' => new Theme,
        'language' => null,
        'pageRecord' => null,
        'slot' => 'Document slot',
    ])->render();

    $script = domElement(frontendRenderedDom($html), '//body/script[@type="application/ld+json"]');
    expect(json_decode(trim($script->textContent), true))->toBe(['@type' => 'Organization', 'name' => 'Public site'])
        ->and(domAttribute(domElement(frontendRenderedDom($html), '//body'), 'class'))->toContain('layout-landing')
        ->and($html)->toContain('Document slot');
});

it('gives the layout shell view typed data and no frontend records', function (bool $system): void {
    ['media' => $logo] = frontendViewFixture(system: $system);
    $site = documentChromeSite();
    $site->setRelation('logo', $logo);
    $site->setRelation('siteDomain', new SiteDomain(['domain' => 'brand.example.test', 'path' => '/brand-home']));

    $inputs = [];
    view()->composer('capell::components.layout.index', function (View $view) use (&$inputs): void {
        $inputs = $view->getData();
    });

    $connection = DB::connection();
    $connection->enableQueryLog();
    try {
        $html = Blade::render('<x-capell::layout>Public slot</x-capell::layout>');
        expect($connection->getQueryLog())->toBe([]);
    } finally {
        $connection->disableQueryLog();
    }

    expect($inputs['renderData'])->toBeInstanceOf(PublicLayoutShellData::class)
        ->toBeInstanceOf(PublicRenderData::class);
    foreach ($inputs as $input) {
        expect($input)->not->toBeInstanceOf(Model::class);
    }

    $dom = frontendRenderedDom($html);
    expect(domCount($dom, '//main[@id="main"]'))->toBe(1)
        ->and($html)->toContain('Public slot', 'Public body')
        ->not->toContain('data-model-id')->not->toContain('data-capell-authoring');

    if ($system) {
        expect(domAttribute(domElement($dom, '//a[contains(@class, "capell-default-theme__brand")]'), 'href'))->toBe('/brand-home')
            ->and(domCount($dom, '//a[contains(@class, "capell-default-theme__brand")]//img'))->toBe(1)
            ->and(domElement($dom, '//h1')->textContent)->toContain('Public title');
    }
})->with(['standard layout' => false, 'system page layout' => true]);

it('renders theme header and footer selectors from the layout shell data', function (bool $headerEnabled): void {
    $fixture = new ScriptFixture;
    $fixture->write('header.blade.php', '<header data-test="chrome-header">Theme header</header>');
    $fixture->write('footer.blade.php', '<footer data-test="chrome-footer">Theme footer</footer>');

    view()->addNamespace('chrome-shell', $fixture->root);

    try {
        frontendViewFixture();
        $chrome = resolve(ThemeChromeRegistry::class);
        $chrome->registerHeader('chrome-shell::header', 'Header');
        $chrome->registerFooter('chrome-shell::footer', 'Footer');
        resolve(FrontendState::class)->withTheme(new Theme(['meta' => [
            'header' => $headerEnabled,
            'header_file' => 'chrome-shell::header',
            'footer_file' => 'chrome-shell::footer',
        ]]));

        $dom = frontendRenderedDom(Blade::render('<x-capell::layout>Public slot</x-capell::layout>'));

        expect(domCount($dom, '//header[@data-test="chrome-header"]'))->toBe($headerEnabled ? 1 : 0)
            ->and(domCount($dom, '//footer[@data-test="chrome-footer"]'))->toBe(1);
    } finally {
        $fixture->close();
    }
})->with(['header shown' => true, 'header disabled' => false]);

it('falls back to the site name when a media logo is absent', function (): void {
    frontendViewFixture(system: true);
    $site = documentChromeSite();
    $site->unsetRelation('translation');
    $site->unsetRelation('logo');

    $dom = frontendRenderedDom(Blade::render('<x-capell::layout>System slot</x-capell::layout>'));

    expect(trim(domElement($dom, '//a[contains(@class, "capell-default-theme__brand")]/span')->textContent))->toBe('Public site')
        ->and(domCount($dom, '//a[contains(@class, "capell-default-theme__brand")]//img'))->toBe(0);
});

it('escapes stored body classes instead of letting them add attributes', function (): void {
    $layout = new Layout(['key' => 'landing', 'meta' => ['body_class' => 'layout-extra" onclick="alert(1)']]);

    $html = Blade::render('<x-capell::app.body :layout="$layout" :language="null" :page-record="null" :site="null" :theme="null">Body slot</x-capell::app.body>', ['layout' => $layout]);
    $body = domElement(frontendRenderedDom($html), '//body');

    expect($body->hasAttribute('onclick'))->toBeFalse()
        ->and(domAttribute($body, 'class'))->toBe('layout-landing layout-extra" onclick="alert(1)  ');
});
