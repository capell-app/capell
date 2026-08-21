<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\File;
use Workbench\App\Support\FrontendScreenshotSeed;

beforeEach(function (): void {
    $publicPath = storage_path('framework/testing/frontend-screenshot-seed-public');

    app()->usePublicPath($publicPath);
    File::ensureDirectoryExists($publicPath . '/build/screenshots');
    File::put(
        $publicPath . '/build/screenshots/default-theme.css',
        "/* Test-owned generated frontend screenshot stylesheet. */\n",
    );
});

afterEach(function (): void {
    File::deleteDirectory(storage_path('framework/testing/frontend-screenshot-seed-public'));
});

/**
 * @return array{layout: Layout, page: Page, theme: Theme, translation: Translation}
 */
function frontendScreenshotSeedModels(): array
{
    $theme = Theme::factory()->createOne([
        'meta' => [
            'assets' => ['build/old.css'],
            'colors' => ['primary' => '#123456'],
            'editor' => [
                'assets' => [
                    'buildPath' => 'build/old',
                    'paths' => ['build/old.css'],
                ],
                'groups' => ['layout'],
            ],
            'unrelated' => 'preserved',
        ],
    ]);
    $site = Site::factory()->theme($theme)->createOne();
    $layout = Layout::factory()->site($site)->createOne([
        // The default install leaves layouts neutral and resolves the theme
        // through the owning site.
        'theme_id' => null,
        'containers' => ['legacy' => ['elements' => []]],
    ]);
    $page = Page::factory()
        ->home()
        ->site($site)
        ->layout($layout)
        ->published()
        ->createOne();
    $translation = Translation::factory()
        ->translatable($page)
        ->language($site->language)
        ->createOne([
            'title' => 'Old screenshot fixture title',
            'content' => '<p>Old screenshot fixture content.</p>',
            'meta' => [
                'label' => 'Home',
                'slug' => 'old-screenshot-fixture',
            ],
        ]);

    return ['layout' => $layout, 'page' => $page, 'theme' => $theme, 'translation' => $translation];
}

it('initializes an idempotent generated frontend screenshot fixture without claiming installed-route evidence', function (): void {
    expect(public_path('build/screenshots/default-theme.css'))->toBeFile();

    ['layout' => $layout, 'page' => $page, 'theme' => $theme, 'translation' => $translation] = frontendScreenshotSeedModels();

    CapellCore::setToCache('frontend-screenshot-seed-test', 'stale');

    FrontendScreenshotSeed::initialize('http://127.0.0.1:8145');
    FrontendScreenshotSeed::initialize('http://127.0.0.1:8145');

    $layout->refresh();
    $theme->refresh();
    $translation->refresh();

    expect($layout->containers)->toEqual([
        'main' => [
            'elements' => [
                ['element_key' => 'page-content', 'occurrence' => 1],
            ],
        ],
    ])
        ->and($page->translations()->count())->toBe(1)
        ->and($page->translations()->sole()->is($translation))->toBeTrue()
        ->and($translation->title)->toBe('Welcome to Capell')
        ->and($translation->content)->toBe('<p>Build and publish a clear, durable site with Capell.</p><p>This is the ordinary published homepage rendered by the local application.</p>')
        ->and($translation->meta)->toEqual([
            'label' => 'Home',
            'slug' => '/',
        ])
        ->and($theme->meta['assets'] ?? null)->toBe(['build/screenshots/default-theme.css'])
        ->and(data_get($theme->meta, 'editor.assets.paths'))->toBe(['build/screenshots/default-theme.css'])
        ->and(data_get($theme->meta, 'editor.assets.buildPath'))->toBe('build/old')
        ->and(data_get($theme->meta, 'editor.groups'))->toBe(['layout'])
        ->and(data_get($theme->meta, 'colors.primary'))->toBe('#123456')
        ->and($theme->meta['unrelated'] ?? null)->toBe('preserved')
        ->and(SiteDomain::query()->where([
            'site_id' => $page->site_id,
            'language_id' => $page->site->language_id,
            'domain' => '127.0.0.1',
            'scheme' => 'http',
            'path' => null,
            'default' => true,
            'status' => true,
        ])->count())->toBe(1)
        ->and(CapellCore::cacheExists('frontend-screenshot-seed-test'))->toBeFalse();
});

it('keeps an installed site domain as the default', function (): void {
    ['page' => $page] = frontendScreenshotSeedModels();

    $installedDomain = SiteDomain::factory()
        ->default()
        ->for($page->site)
        ->language($page->site->language)
        ->createOne();

    FrontendScreenshotSeed::initialize('http://127.0.0.1:8145');

    expect($installedDomain->refresh()->default)->toBeTrue()
        ->and(SiteDomain::query()->where([
            'site_id' => $page->site_id,
            'language_id' => $page->site->language_id,
            'domain' => '127.0.0.1',
            'scheme' => 'http',
            'path' => null,
            'default' => false,
            'status' => true,
        ])->count())->toBe(1);
});

it('fails clearly when the seeded homepage is missing', function (): void {
    expect(static fn () => FrontendScreenshotSeed::initialize('http://127.0.0.1:8145'))
        ->toThrow(ModelNotFoundException::class, 'The screenshot app must be seeded before building the generated frontend screenshot fixture.');
});

it('fails clearly when the seeded homepage theme is missing', function (): void {
    ['theme' => $theme] = frontendScreenshotSeedModels();
    $theme->delete();

    expect(static fn () => FrontendScreenshotSeed::initialize('http://127.0.0.1:8145'))
        ->toThrow(ModelNotFoundException::class, 'The screenshot homepage resolves no theme.');
});

it('fails clearly when the generated frontend stylesheet has not been built', function (): void {
    frontendScreenshotSeedModels();

    $stylesheet = public_path('build/screenshots/default-theme.css');

    expect($stylesheet)->toBeFile();
    File::delete($stylesheet);

    try {
        expect(static fn () => FrontendScreenshotSeed::initialize('http://127.0.0.1:8145'))
            ->toThrow(RuntimeException::class, 'The generated frontend screenshot stylesheet is missing. Run the screenshot workbench preparation before seeding the fixture.');
    } finally {
        File::put($stylesheet, "/* Test-owned generated frontend screenshot stylesheet. */\n");
    }
});
