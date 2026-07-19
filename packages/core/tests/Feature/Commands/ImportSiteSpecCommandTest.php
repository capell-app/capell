<?php

declare(strict_types=1);

use Capell\Core\Actions\CreateDefaultLanguagesAction;
use Capell\Core\Contracts\SiteSpec\SiteSpecApplier;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Data\SiteSpec\CapellSiteSpecNavigationData;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Support\SiteSpec\SiteSpecApplierRegistry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Command\Command;

final class RecordingNavigationSiteSpecApplier implements SiteSpecApplier
{
    public function key(): string
    {
        return 'navigation';
    }

    /** @param array<string, Page> $pagesBySlug */
    public function apply(CapellSiteSpecData $spec, Site $site, array $pagesBySlug): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['site_spec_test'] = [
            'navigation' => array_map(
                static fn (CapellSiteSpecNavigationData $navigation): array => $navigation->toArray(),
                $spec->navigations,
            ),
            'resolved_page_slugs' => array_keys($pagesBySlug),
            'apply_count' => (int) data_get($meta, 'site_spec_test.apply_count', 0) + 1,
        ];
        $site->meta = $meta;
        $site->save();
    }
}

beforeEach(function (): void {
    CreateDefaultLanguagesAction::run(['en']);
    Queue::fake();
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');
    config()->set('media-library.queue_conversions_by_default', true);
});

/** @return array<string, mixed> */
function importSiteSpecPayload(): array
{
    return [
        'site' => [
            'name' => 'Harbour Books',
            'businessName' => 'Harbour Books Ltd',
            'organisationType' => 'bookshop',
        ],
        'theme' => [
            'key' => 'default',
            'colors' => ['primary' => '#123456'],
        ],
        'pages' => [
            [
                'name' => 'Home',
                'slug' => 'home',
                'title' => 'Welcome',
                'pageType' => 'default',
                'order' => 0,
                'sections' => [['type' => 'content', 'content' => '<p>Hello.</p>']],
            ],
            [
                'name' => 'About',
                'slug' => 'about',
                'title' => 'About us',
                'pageType' => 'default',
                'order' => 1,
                'sections' => [['type' => 'content', 'content' => '<p>Independent booksellers.</p>']],
            ],
        ],
        'navigations' => [[
            'key' => 'main',
            'name' => 'Main navigation',
            'pageSlugs' => ['home', 'about'],
        ]],
        'media' => [
            'sourceUrl' => 'https://93.184.216.34',
            'logo' => 'https://93.184.216.34/logo.png',
            'images' => ['about' => 'https://93.184.216.34/about.png'],
        ],
        'extensions' => ['capell-app/navigation'],
        'initialVisibility' => 'public',
        'acknowledgePublic' => true,
    ];
}

/** @param array<string, mixed> $payload */
function writeSiteSpec(array $payload): string
{
    $path = tempnam(sys_get_temp_dir(), 'capell-site-spec-import-');

    throw_unless(is_string($path), RuntimeException::class, 'Unable to create a temporary SiteSpec fixture.');

    file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));

    return $path;
}

function importSiteSpecPng(): string
{
    $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z3h8AAAAASUVORK5CYII=', true);

    throw_unless(is_string($image), RuntimeException::class, 'Unable to decode the SiteSpec import PNG fixture.');

    return $image;
}

it('round trips navigation media and extension state through the import command idempotently', function (): void {
    $image = importSiteSpecPng();
    Http::fake([
        'https://93.184.216.34/*' => Http::response($image, 200, [
            'Content-Type' => 'image/png',
            'Content-Length' => (string) strlen($image),
        ]),
    ]);
    CapellCore::markPackageInstalled('capell-app/navigation');
    resolve(SiteSpecApplierRegistry::class)->register(new RecordingNavigationSiteSpecApplier);
    $path = writeSiteSpec(importSiteSpecPayload());

    try {
        artisanCommand('capell:site-spec-import', ['spec' => $path])
            ->assertExitCode(Command::SUCCESS);
        artisanCommand('capell:site-spec-import', ['spec' => $path])
            ->assertExitCode(Command::SUCCESS);
    } finally {
        unlink($path);
    }

    $site = Site::query()->where('name', 'Harbour Books')->firstOrFail();
    $about = Page::query()->whereBelongsTo($site)->where('name', 'About')->firstOrFail();

    expect(Site::query()->count())->toBe(1)
        ->and(Page::query()->whereBelongsTo($site)->count())->toBe(2)
        ->and(data_get($site->meta, 'site_spec_test.navigation.0.key'))->toBe('main')
        ->and(data_get($site->meta, 'site_spec_test.resolved_page_slugs'))->toBe(['home', 'about'])
        ->and(data_get($site->meta, 'site_spec_test.apply_count'))->toBe(1)
        ->and($site->getMedia('logo'))->toHaveCount(1)
        ->and($about->getMedia('image'))->toHaveCount(1)
        ->and(CapellExtension::query()->where('composer_name', 'capell-app/navigation')->exists())->toBeTrue();

    Http::assertSentCount(2);
});

it('publishes only the coming soon page for a private import', function (): void {
    Http::fake();
    $payload = importSiteSpecPayload();
    $payload['initialVisibility'] = 'private';
    $payload['acknowledgePublic'] = false;
    $payload['navigations'] = [];
    $payload['media'] = ['sourceUrl' => null, 'logo' => null, 'images' => []];
    $payload['extensions'] = [];
    $path = writeSiteSpec($payload);

    try {
        artisanCommand('capell:site-spec-import', ['spec' => $path])
            ->assertExitCode(Command::SUCCESS);
    } finally {
        unlink($path);
    }

    $site = Site::query()->where('name', 'Harbour Books')->firstOrFail();
    $home = Page::query()->whereBelongsTo($site)->where('name', 'Home')->firstOrFail();
    $about = Page::query()->whereBelongsTo($site)->where('name', 'About')->firstOrFail();

    expect($home->publishVisibilityState())->toBe(PublishVisibilityStateEnum::published)
        ->and($about->publishVisibilityState())->toBe(PublishVisibilityStateEnum::draft)
        ->and(Page::query()->whereBelongsTo($site)->published()->pluck('name')->all())->toBe(['Home'])
        ->and(Page::query()->whereBelongsTo($site)->draft()->pluck('name')->all())->toBe(['About']);

    Http::assertNothingSent();
});

it('uses an explicit page URL instead of its navigation slug', function (): void {
    Http::fake();
    $payload = importSiteSpecPayload();
    $payload['pages'][1]['url'] = '/company/about-us';
    $payload['navigations'] = [];
    $payload['media'] = ['sourceUrl' => null, 'logo' => null, 'images' => []];
    $payload['extensions'] = [];
    $path = writeSiteSpec($payload);

    try {
        artisanCommand('capell:site-spec-import', ['spec' => $path])
            ->assertExitCode(Command::SUCCESS);
    } finally {
        unlink($path);
    }

    $about = Page::query()->where('name', 'About')->firstOrFail();

    expect(PageUrl::query()->whereMorphedTo('pageable', $about)->value('url'))
        ->toBe('/company/about-us');

    Http::assertNothingSent();
});

it('refuses a spec whose requested extension is not installed before fetching media', function (): void {
    Http::fake();
    $payload = importSiteSpecPayload();
    $payload['extensions'] = ['vendor/missing-extension'];
    $path = writeSiteSpec($payload);

    try {
        artisanCommand('capell:site-spec-import', ['spec' => $path])
            ->assertExitCode(Command::FAILURE);
    } finally {
        unlink($path);
    }

    expect(Site::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('requires a package-owned navigation applier before fetching media', function (): void {
    Http::fake();
    CapellCore::markPackageInstalled('capell-app/navigation');
    $path = writeSiteSpec(importSiteSpecPayload());

    try {
        artisanCommand('capell:site-spec-import', ['spec' => $path])
            ->assertExitCode(Command::FAILURE);
    } finally {
        unlink($path);
    }

    expect(Site::query()->count())->toBe(0);
    Http::assertNothingSent();
});
