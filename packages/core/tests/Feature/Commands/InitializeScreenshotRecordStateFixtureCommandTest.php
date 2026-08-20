<?php

declare(strict_types=1);

use Capell\Core\Models\AssetAttachment;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Support\Screenshots\RecordStateScreenshotFixture;
use Illuminate\Support\Facades\Storage;

afterEach(function (): void {
    putenv('CAPELL_SCREENSHOT_FIXTURE');
    putenv('CAPELL_SCREENSHOT_APP_PATH');
});

it('refuses to initialize without explicit force confirmation', function (): void {
    putenv('CAPELL_SCREENSHOT_FIXTURE=record-state');
    putenv('CAPELL_SCREENSHOT_APP_PATH=' . base_path());

    artisanCommand('capell:screenshot-record-state-fixture')
        ->expectsOutputToContain('without --force')
        ->assertExitCode(1);
});

it('initializes deterministic record-state data only in the disposable screenshot environment', function (): void {
    Storage::fake('public');
    $site = Site::factory()->withTranslations()->create();
    Blueprint::factory()->page()->create();
    putenv('CAPELL_SCREENSHOT_FIXTURE=record-state');
    putenv('CAPELL_SCREENSHOT_APP_PATH=' . base_path());

    artisanCommand('capell:screenshot-record-state-fixture', ['--force' => true])
        ->expectsOutputToContain('initialized')
        ->assertExitCode(0);

    $page = Page::query()->where('name', 'Scheduled page without an active URL')->firstOrFail();
    $media = Media::query()->where('uuid', 'c6de44d7-a8d4-4c5d-9e24-7c6492811d48')->firstOrFail();
    $pageId = $page->getKey();
    $pageUuid = $page->uuid;
    $mediaId = $media->getKey();
    $mediaUuid = $media->uuid;

    expect($page->site_id)->toBe($site->getKey())
        ->and($page->visible_from)->not->toBeNull()
        ->and(data_get($page->meta, 'capell.screenshot_fixture'))->toBe('record-state')
        ->and(PageUrl::query()->where('pageable_id', $page->getKey())->where('status', false)->exists())->toBeTrue()
        ->and(Layout::query()->where('key', 'record-state-disabled-unused')->where('status', false)->exists())->toBeTrue()
        ->and($media->name)->toBe('Unused editorial image')
        ->and($media->file_name)->toBe('record-state-image.svg')
        ->and($media->mime_type)->toBe('image/svg+xml')
        ->and(Storage::disk('public')->exists($media->getKey() . '/' . $media->file_name))->toBeTrue()
        ->and(AssetAttachment::query()->where('asset_id', (string) $media->getKey())->exists())->toBeFalse();

    artisanCommand('capell:screenshot-record-state-fixture', ['--force' => true])
        ->assertExitCode(0);

    $rerunMedia = Media::query()->whereKey($mediaId)->firstOrFail();

    expect(Page::query()->whereKey($pageId)->value('uuid'))->toBe($pageUuid)
        ->and(Page::query()->where('uuid', $pageUuid)->count())->toBe(1)
        ->and($rerunMedia->getKey())->toBe($mediaId)
        ->and(Media::query()->where('uuid', $mediaUuid)->count())->toBe(1)
        ->and(data_get($rerunMedia->custom_properties, 'capell.screenshot_fixture'))->toBe('record-state')
        ->and(AssetAttachment::query()->where('asset_id', (string) $mediaId)->exists())->toBeFalse();
});

it('resolves the fixture image from the installed Core package path', function (): void {
    $classPath = new ReflectionClass(RecordStateScreenshotFixture::class)->getFileName();

    expect($classPath)->not->toBeFalse();

    $packagePath = dirname((string) $classPath, 4);
    $fixturePath = $packagePath . '/resources/screenshot-fixtures/record-state-image.svg';

    expect($fixturePath)->toBeFile()
        ->and(file_get_contents($fixturePath))->toContain('<svg');
});

it('rejects direct initialization without the disposable app marker', function (): void {
    expect(fn (): null => RecordStateScreenshotFixture::initialize())
        ->toThrow(RuntimeException::class, 'explicit disposable local screenshot environment');
});

it('does not alter a same-named page on another site', function (): void {
    Storage::fake('public');
    $site = Site::factory()->withTranslations()->create();
    $otherSite = Site::factory()->withTranslations()->create();
    $blueprint = Blueprint::factory()->page()->create();
    $otherPage = Page::factory()->site($otherSite)->type($blueprint)->withTranslations()->create([
        'site_id' => $otherSite->getKey(),
        'blueprint_id' => $blueprint->getKey(),
        'name' => 'Scheduled page without an active URL',
        'meta' => ['editorial_note' => 'preserve this page'],
        'visible_from' => now()->subWeek(),
    ]);
    $otherPageUrl = PageUrl::factory()
        ->site($otherSite)
        ->page($otherPage)
        ->language($otherSite->language)
        ->create(['url' => '/other-site-preserved', 'status' => true]);
    $originalOtherPage = $otherPage->only(['uuid', 'name', 'site_id', 'meta', 'visible_from']);
    putenv('CAPELL_SCREENSHOT_FIXTURE=record-state');
    putenv('CAPELL_SCREENSHOT_APP_PATH=' . base_path());

    artisanCommand('capell:screenshot-record-state-fixture', ['--force' => true])
        ->assertExitCode(0);

    $otherPage->refresh();
    $otherPageUrl->refresh();

    expect($originalOtherPage['uuid'])->toBe($otherPage->uuid)
        ->and($originalOtherPage['name'])->toBe($otherPage->name)
        ->and($originalOtherPage['site_id'])->toBe($otherPage->site_id)
        ->and($originalOtherPage['meta'])->toBe($otherPage->meta)
        ->and($originalOtherPage['visible_from']->toIso8601String())->toBe($otherPage->visible_from->toIso8601String())
        ->and($otherPageUrl->url)->toBe('/other-site-preserved')
        ->and($otherPageUrl->status)->toBeTrue()
        ->and(Page::query()->where('site_id', $site->getKey())->where('name', 'Scheduled page without an active URL')->exists())->toBeTrue();
});

it('fails closed when a same-site human page uses the fixture display name', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $blueprint = Blueprint::factory()->page()->create();
    $humanPage = Page::factory()->site($site)->type($blueprint)->withTranslations()->create([
        'name' => 'Scheduled page without an active URL',
        'meta' => ['editorial_note' => 'preserve this page'],
        'visible_from' => now()->subWeek(),
    ]);
    $humanPageUrl = PageUrl::factory()
        ->site($site)
        ->page($humanPage)
        ->language($site->language)
        ->create(['url' => '/human-page-preserved', 'status' => true]);
    $originalPage = $humanPage->only(['uuid', 'name', 'site_id', 'meta', 'visible_from']);
    putenv('CAPELL_SCREENSHOT_FIXTURE=record-state');
    putenv('CAPELL_SCREENSHOT_APP_PATH=' . base_path());

    artisanCommand('capell:screenshot-record-state-fixture', ['--force' => true])
        ->expectsOutputToContain('same-site page already uses')
        ->assertExitCode(1);

    $humanPage->refresh();
    $humanPageUrl->refresh();

    expect($originalPage['uuid'])->toBe($humanPage->uuid)
        ->and($originalPage['name'])->toBe($humanPage->name)
        ->and($originalPage['site_id'])->toBe($humanPage->site_id)
        ->and($originalPage['meta'])->toBe($humanPage->meta)
        ->and($originalPage['visible_from']->toIso8601String())->toBe($humanPage->visible_from->toIso8601String())
        ->and($humanPageUrl->url)->toBe('/human-page-preserved')
        ->and($humanPageUrl->status)->toBeTrue();
});

it('fails closed when the stable page identity belongs to another site', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $otherSite = Site::factory()->withTranslations()->create();
    $blueprint = Blueprint::factory()->page()->create();
    $otherPage = Page::factory()->site($otherSite)->type($blueprint)->withTranslations()->create([
        'uuid' => '2f3f0f74-8e75-4ba2-9d5f-0d5a0ef8f1b8',
        'name' => 'Human page with a conflicting fixture identity',
        'meta' => ['editorial_note' => 'preserve this page'],
        'visible_from' => now()->subWeek(),
    ]);
    $otherPageUrl = PageUrl::factory()
        ->site($otherSite)
        ->page($otherPage)
        ->language($otherSite->language)
        ->create(['url' => '/conflicting-page-identity', 'status' => true]);
    $originalOtherPage = $otherPage->only(['uuid', 'name', 'site_id', 'meta', 'visible_from']);
    putenv('CAPELL_SCREENSHOT_FIXTURE=record-state');
    putenv('CAPELL_SCREENSHOT_APP_PATH=' . base_path());

    artisanCommand('capell:screenshot-record-state-fixture', ['--force' => true])
        ->expectsOutputToContain('page identity is already owned')
        ->assertExitCode(1);

    $otherPage->refresh();
    $otherPageUrl->refresh();

    expect($originalOtherPage['uuid'])->toBe($otherPage->uuid)
        ->and($originalOtherPage['name'])->toBe($otherPage->name)
        ->and($originalOtherPage['site_id'])->toBe($otherPage->site_id)
        ->and($originalOtherPage['meta'])->toBe($otherPage->meta)
        ->and($originalOtherPage['visible_from']->toIso8601String())->toBe($otherPage->visible_from->toIso8601String())
        ->and($otherPageUrl->url)->toBe('/conflicting-page-identity')
        ->and($otherPageUrl->status)->toBeTrue()
        ->and(Page::query()->where('site_id', $site->getKey())->where('name', 'Scheduled page without an active URL')->exists())->toBeFalse();
});

it('fails closed when the stable media identity belongs to another site', function (): void {
    Storage::fake('public');
    $site = Site::factory()->withTranslations()->create();
    $otherSite = Site::factory()->withTranslations()->create();
    $blueprint = Blueprint::factory()->page()->create();
    $otherPage = Page::factory()->site($otherSite)->type($blueprint)->create();
    $otherMedia = Media::factory()->model($otherPage)->create([
        'uuid' => 'c6de44d7-a8d4-4c5d-9e24-7c6492811d48',
        'name' => 'Human-owned media',
        'file_name' => 'human-owned.png',
    ]);
    putenv('CAPELL_SCREENSHOT_FIXTURE=record-state');
    putenv('CAPELL_SCREENSHOT_APP_PATH=' . base_path());

    artisanCommand('capell:screenshot-record-state-fixture', ['--force' => true])
        ->expectsOutputToContain('media identity is already owned')
        ->assertExitCode(1);

    expect($otherMedia->refresh()->name)->toBe('Human-owned media')
        ->and($otherMedia->file_name)->toBe('human-owned.png')
        ->and(Page::query()->where('site_id', $site->getKey())->where('name', 'Scheduled page without an active URL')->exists())->toBeFalse();
});

it('fails closed when fixture media has an attachment', function (): void {
    Storage::fake('public');
    $site = Site::factory()->withTranslations()->create();
    Blueprint::factory()->page()->create();
    putenv('CAPELL_SCREENSHOT_FIXTURE=record-state');
    putenv('CAPELL_SCREENSHOT_APP_PATH=' . base_path());

    artisanCommand('capell:screenshot-record-state-fixture', ['--force' => true])
        ->assertExitCode(0);

    $page = Page::query()->where('name', 'Scheduled page without an active URL')->firstOrFail();
    $media = Media::query()->where('uuid', 'c6de44d7-a8d4-4c5d-9e24-7c6492811d48')->firstOrFail();
    $attachment = AssetAttachment::query()->create([
        'related_type' => $page->getMorphClass(),
        'related_id' => (string) $page->getKey(),
        'asset_type' => $media->getMorphClass(),
        'asset_id' => (string) $media->getKey(),
        'order' => 1,
    ]);

    artisanCommand('capell:screenshot-record-state-fixture', ['--force' => true])
        ->expectsOutputToContain('media is attached')
        ->assertExitCode(1);

    expect($attachment->refresh()->asset_id)->toBe((string) $media->getKey())
        ->and($media->refresh()->name)->toBe('Unused editorial image')
        ->and(Storage::disk('public')->exists($media->getKey() . '/' . $media->file_name))->toBeTrue();
});
