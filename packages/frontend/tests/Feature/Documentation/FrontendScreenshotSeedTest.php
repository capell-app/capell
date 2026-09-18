<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Support\FrontendScreenshotSeed;

beforeEach(function (): void {
    Storage::fake('public');
    $publicPath = storage_path('framework/testing/frontend-screenshot-seed-public');

    app()->usePublicPath($publicPath);
    File::ensureDirectoryExists($publicPath . '/build/screenshots');
    File::copyDirectory(dirname(__DIR__, 3) . '/publishes/build', $publicPath . '/vendor/capell-frontend');
    File::put(
        $publicPath . '/build/screenshots/default-theme.css',
        file_get_contents(dirname(__DIR__, 3) . '/resources/css/base/default-theme.css'),
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

    $media = Media::query()->where('uuid', '6b6f1639-95be-4cc3-a5a5-f19a0ef825dc')->sole();
    Storage::disk('public')->assertExists($media->getKey() . '/coastal-walk.svg');
    $translation->refresh();
    expect($translation->content)->toContain(e($media->getUrl()));

    $layout->refresh();
    $theme->refresh();

    expect($layout->containers)->toEqual([
        'main' => [
            'elements' => [
                ['element_key' => 'page-content', 'occurrence' => 1],
            ],
        ],
    ])
        ->and($page->translations()->count())->toBe(1)
        ->and($page->translations()->sole()->is($translation))->toBeTrue()
        ->and($translation->title)->toBe('A slower weekend outdoors')
        ->and($translation->content)->toContain('A morning by the water', '<img', 'Plan your visit', '<nav ', '<footer>')
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
        File::put($stylesheet, file_get_contents(dirname(__DIR__, 3) . '/resources/css/base/default-theme.css'));
    }
});

it('renders the populated fixture through the anonymous frontend route', function (): void {
    frontendScreenshotSeedModels();
    FrontendScreenshotSeed::initialize('http://127.0.0.1:8145');

    $this->get('http://127.0.0.1/')
        ->assertOk()
        ->assertSee('A slower weekend outdoors')
        ->assertSee('A morning by the water')
        ->assertSee('coastal-walk.svg', false)
        ->assertSee('build/screenshots/default-theme.css', false)
        ->assertDontSee('data-capell-authoring', false)
        ->assertDontSee('data-capell-editor', false)
        ->assertDontSee(base_path(), false);
});

it('rejects a placeholder stylesheet instead of accepting an unstyled fixture', function (): void {
    frontendScreenshotSeedModels();
    File::put(public_path('build/screenshots/default-theme.css'), '/* Placeholder stylesheet. */');
    expect(fn () => FrontendScreenshotSeed::initialize('http://127.0.0.1:8145'))
        ->toThrow(RuntimeException::class, 'compiled default-theme CSS');
});
