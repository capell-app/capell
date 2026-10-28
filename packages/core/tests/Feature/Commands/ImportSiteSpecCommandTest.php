<?php

declare(strict_types=1);

use Capell\Core\Actions\BuildSiteFromSpecFileAction;
use Capell\Core\Actions\CreateDefaultLanguagesAction;
use Capell\Core\Actions\ResolvePublicPageByUrlAction;
use Capell\Core\Contracts\SiteSpec\SiteSpecApplier;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Data\SiteSpec\CapellSiteSpecNavigationData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Publishing\PublishSentinel;
use Capell\Core\Support\SiteSpec\SiteSpecApplierRegistry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Symfony\Component\Console\Command\Command;

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

it('reports malformed JSON before validating fields without creating a site', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-site-spec-import-');
    throw_unless(is_string($path), RuntimeException::class, 'Unable to create a temporary SiteSpec fixture.');
    file_put_contents($path, '{"secret":"do-not-print",');
    $siteCount = Site::query()->count();

    try {
        $command = $this->artisan('capell:site-spec-import', ['spec' => $path]);
        assert($command instanceof PendingCommand);
        $command->expectsOutputToContain('invalid JSON')
            ->doesntExpectOutput('do-not-print')
            ->assertExitCode(Command::FAILURE)
            ->run();
        expect(Site::query()->count())->toBe($siteCount);
    } finally {
        unlink($path);
    }
});

it('continues to report field validation for valid incomplete JSON', function (): void {
    $path = writeSiteSpec([]);
    $siteCount = Site::query()->count();

    try {
        $command = $this->artisan('capell:site-spec-import', ['spec' => $path]);
        assert($command instanceof PendingCommand);
        $command->expectsOutputToContain(__('capell::message.site_spec_import_validation_failed'))
            ->assertExitCode(Command::FAILURE)
            ->run();
        expect(Site::query()->count())->toBe($siteCount);
    } finally {
        unlink($path);
    }
});

it('round trips navigation media and extension state through the import command idempotently', function (): void {
    $image = importSiteSpecPng();
    Http::fake([
        'https://93.184.216.34/*' => Http::response($image, 200, [
            'Content-Type' => 'image/png',
            'Content-Length' => (string) strlen($image),
        ]),
    ]);
    CapellCore::markPackageInstalled('capell-app/navigation');
    resolve(SiteSpecApplierRegistry::class)->register(new class implements SiteSpecApplier
    {
        #[Override]
        public function key(): string
        {
            return 'navigation';
        }

        /** @param array<string, Page> $pagesBySlug */
        #[Override]
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
    });
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

it('only exposes the coming soon homepage for a private import', function (bool $public): void {
    Http::preventStrayRequests();
    $payload = importSiteSpecPayload();
    $payload['initialVisibility'] = $public ? 'public' : 'private';
    $payload['acknowledgePublic'] = $public;
    $payload['media'] = ['images' => []];
    $payload['extensions'] = [];
    $payload['navigations'] = [];
    // The first published page follows spec ordering, not array position.
    $payload['pages'] = array_reverse($payload['pages']);
    $path = writeSiteSpec($payload);

    try {
        artisanCommand('capell:site-spec-import', ['spec' => $path])->assertSuccessful();
        artisanCommand('capell:site-spec-import', ['spec' => $path])->assertSuccessful();
    } finally {
        unlink($path);
    }

    $site = Site::query()->where('name', 'Harbour Books')->firstOrFail();
    $home = Page::query()->whereBelongsTo($site)->where('name', 'Home')->firstOrFail();
    $about = Page::query()->whereBelongsTo($site)->where('name', 'About')->firstOrFail();
    $homeUrl = $home->pageUrls()->firstOrFail()->url;
    $aboutUrl = $about->pageUrls()->firstOrFail()->url;
    $homeResolution = ResolvePublicPageByUrlAction::run($site, $site->language, $homeUrl);
    $aboutResolution = ResolvePublicPageByUrlAction::run($site, $site->language, $aboutUrl);

    expect(Page::query()->whereBelongsTo($site)->count())->toBe(2)
        ->and($homeResolution->page?->getKey())->toBe($home->getKey())
        ->and($homeResolution->fields->content)->toBe($public ? '<p>Hello.</p>' : '<h1>Coming soon</h1><p>This site is being prepared.</p>')
        ->and($about->translation?->content)->toBe('<p>Independent booksellers.</p>')
        ->and(PublishSentinel::isDraftValue($about->visible_from))->toBe(! $public)
        ->and($aboutResolution->page?->getKey())->toBe($public ? $about->getKey() : null)
        ->and(Page::query()->whereBelongsTo($site)->publishedDate()->count())->toBe($public ? 2 : 1);
    Http::assertNothingSent();
})->with(['private' => false, 'acknowledged public' => true]);

it('requires an explicit legacy privacy audit and repairs identical private imports without undoing publication decisions', function (): void {
    $payload = importSiteSpecPayload();
    $payload['initialVisibility'] = 'private';
    $payload['acknowledgePublic'] = false;
    $payload['media'] = ['images' => []];
    $payload['extensions'] = [];
    $payload['navigations'] = [];
    $payload['pages'][] = array_merge($payload['pages'][1], ['name' => 'Published', 'slug' => 'published']);
    $path = writeSiteSpec($payload);

    try {
        $site = BuildSiteFromSpecFileAction::run($path);
        $meta = $site->meta;
        unset($meta['site_spec_privacy_version']);
        $site->update(['meta' => $meta]);
        $about = $site->pages()->where('name', 'About')->firstOrFail();
        $published = $site->pages()->where('name', 'Published')->firstOrFail();
        $about->update(['visible_from' => null]);
        $published->update(['visible_from' => now()->subDays(3)]);
        $publicationDate = $published->visible_from;

        // A matching hash must no longer silently certify unsafe legacy state.
        artisanCommand('capell:site-spec-import', ['spec' => $path, '--audit-private' => true])->assertSuccessful();
        expect($about->fresh()->visible_from)->toBeNull();
        artisanCommand('capell:site-spec-import', ['spec' => $path])->assertFailed();
        artisanCommand('capell:site-spec-import', ['spec' => $path, '--repair-private' => true])->assertSuccessful();
        artisanCommand('capell:site-spec-import', ['spec' => $path])->assertSuccessful();

        expect(PublishSentinel::isDraftValue($about->fresh()->visible_from))->toBeTrue()
            ->and($published->fresh()->visible_from->equalTo($publicationDate))->toBeTrue()
            ->and(ResolvePublicPageByUrlAction::run($site->fresh(), $site->language, $about->pageUrls()->firstOrFail()->url)->page)->toBeNull()
            ->and($site->fresh()->meta['site_spec_privacy_version'])->toBe(2);
    } finally {
        unlink($path);
    }
});

it('refuses to repair a different original spec without creating a replacement site', function (): void {
    $payload = importSiteSpecPayload();
    $payload['initialVisibility'] = 'private';
    $payload['acknowledgePublic'] = false;
    $payload['media'] = ['images' => []];
    $payload['extensions'] = [];
    $payload['navigations'] = [];
    $path = writeSiteSpec($payload);
    try {
        artisanCommand('capell:site-spec-import', ['spec' => $path, '--repair-private' => true])->assertFailed();
        expect(Site::query()->count())->toBe(0);
    } finally {
        unlink($path);
    }
});

it('preserves explicitly reviewed legacy null publication decisions during repair', function (): void {
    $payload = importSiteSpecPayload();
    $payload['initialVisibility'] = 'private';
    $payload['acknowledgePublic'] = false;
    $payload['media'] = ['images' => []];
    $payload['extensions'] = [];
    $payload['navigations'] = [];
    $path = writeSiteSpec($payload);

    try {
        $site = BuildSiteFromSpecFileAction::run($path);
        $meta = $site->meta;
        unset($meta['site_spec_privacy_version']);
        $site->update(['meta' => $meta]);
        $about = $site->pages()->where('name', 'About')->firstOrFail();
        $about->update(['visible_from' => null]);
        artisanCommand('capell:site-spec-import', ['spec' => $path, '--repair-private' => true, '--keep-published' => ['about']])->assertSuccessful();
        artisanCommand('capell:site-spec-import', ['spec' => $path])->assertSuccessful();
        expect($about->fresh()->visible_from)->toBeNull()
            ->and(Page::query()->whereKey($about->id)->publishedDate()->exists())->toBeTrue();
    } finally {
        unlink($path);
    }
});
