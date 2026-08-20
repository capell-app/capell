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

    expect($page->site_id)->toBe($site->getKey())
        ->and($page->visible_from)->not->toBeNull()
        ->and(PageUrl::query()->where('pageable_id', $page->getKey())->where('status', false)->exists())->toBeTrue()
        ->and(Layout::query()->where('key', 'record-state-disabled-unused')->where('status', false)->exists())->toBeTrue()
        ->and($media->name)->toBe('Unused editorial image')
        ->and(Storage::disk('public')->exists($media->getKey() . '/' . $media->file_name))->toBeTrue()
        ->and(AssetAttachment::query()->where('asset_id', (string) $media->getKey())->exists())->toBeFalse();
});

it('rejects direct initialization without the disposable app marker', function (): void {
    expect(fn (): null => RecordStateScreenshotFixture::initialize())
        ->toThrow(RuntimeException::class, 'explicit disposable local screenshot environment');
});
