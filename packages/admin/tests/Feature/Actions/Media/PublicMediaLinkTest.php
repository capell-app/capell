<?php

declare(strict_types=1);

use Capell\Admin\Actions\Media\CreateExternalVideoMediaAction;
use Capell\Admin\Actions\Media\ResolvePublicMediaLinkAction;
use Capell\Admin\Tests\Support\ScopedAdminUser;
use Capell\Core\Models\Site;
use Capell\Core\Support\Media\YouTubeVideoUrl;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToCheckFileExistence;

uses(CreatesAdminUser::class)->group('media');

it('returns the generated public file URL and never a private storage URL', function (): void {
    test()->actingAsAdmin();
    Storage::fake('public');
    Storage::fake('local');
    $site = Site::factory()->createOne();
    $public = $site->addMedia(UploadedFile::fake()->createWithContent('brochure.pdf', '%PDF-1.4 brochure'))
        ->toMediaCollection('uploads', 'public');
    $private = $site->addMedia(UploadedFile::fake()->createWithContent('private.pdf', '%PDF-1.4 private'))
        ->toMediaCollection('uploads', 'local');

    expect(ResolvePublicMediaLinkAction::run($public))->toBe($public->getUrl())
        ->and(ResolvePublicMediaLinkAction::run($private))->toBeNull();
});

it('uses canonical external video sources and excludes unauthorised media', function (): void {
    test()->actingAsAdmin();
    $site = Site::factory()->createOne();
    $video = CreateExternalVideoMediaAction::run(
        $site,
        'Source video',
        YouTubeVideoUrl::parse('https://youtu.be/FgalLC99jzY'),
    );
    expect(ResolvePublicMediaLinkAction::run($video))->toBe('https://www.youtube.com/watch?v=FgalLC99jzY');

    test()->actingAs(test()->createUser());
    expect(ResolvePublicMediaLinkAction::run($video))->toBeNull();
});

it('shares each public media link across repeated callbacks for several rows', function (): void {
    test()->actingAsAdmin();
    Storage::fake('public');
    $site = Site::factory()->createOne();
    $records = collect(range(1, 3))->map(fn (int $number) => $site
        ->addMedia(UploadedFile::fake()->createWithContent(sprintf('brochure-%d.pdf', $number), '%PDF brochure'))
        ->toMediaCollection('uploads', 'public'));
    $disk = Storage::disk('public');
    $storage = Mockery::mock($disk)->makePartial();
    foreach ($records as $record) {
        $storage->shouldReceive('exists')->with($record->getPathRelativeToRoot())->once()->andReturnTrue();
        $storage->shouldReceive('getVisibility')->with($record->getPathRelativeToRoot())->once()->andReturn('public');
    }

    Storage::set('public', $storage);

    test()->actingAs(ScopedAdminUser::make(collect([$site->id])));
    DB::enableQueryLog();
    DB::flushQueryLog();
    foreach ($records as $record) {
        for ($callback = 0; $callback < 5; $callback++) {
            expect(ResolvePublicMediaLinkAction::run($record))->toBe($record->getUrl());
        }
    }

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(3);
});

it('returns an unavailable link when an existence check fails without logging adapter secrets', function (): void {
    test()->actingAsAdmin();
    Storage::fake('public');
    $site = Site::factory()->createOne();
    $media = $site->addMedia(UploadedFile::fake()->createWithContent('brochure.pdf', '%PDF brochure'))
        ->toMediaCollection('uploads', 'public');
    $disk = Storage::disk('public');
    $storage = Mockery::mock($disk)->makePartial();
    $storage->shouldReceive('exists')->with($media->getPathRelativeToRoot())->once()
        ->andThrow(UnableToCheckFileExistence::forLocation($media->getPathRelativeToRoot(), new RuntimeException('secret endpoint')));
    $storage->shouldNotReceive('getVisibility');
    Storage::set('public', $storage);
    $logger = Log::spy();

    for ($callback = 0; $callback < 5; $callback++) {
        expect(ResolvePublicMediaLinkAction::run($media))->toBeNull();
    }

    $logger->shouldHaveReceived('warning')->once()->with('Unable to resolve public media link.', [
        'media_id' => $media->id,
        'disk' => 'public',
        'exception' => UnableToCheckFileExistence::class,
    ]);
});

it('keeps media link results inside actor and assigned site boundaries', function (): void {
    test()->actingAsAdmin();
    Storage::fake('public');
    $site = Site::factory()->createOne();
    $media = $site->addMedia(UploadedFile::fake()->createWithContent('brochure.pdf', '%PDF brochure'))
        ->toMediaCollection('uploads', 'public');
    expect(ResolvePublicMediaLinkAction::run($media))->toBe($media->getUrl());

    $actor = ScopedAdminUser::make(collect());
    test()->actingAs($actor);
    expect(ResolvePublicMediaLinkAction::run($media))->toBeNull();
    $actor->assignedSiteIds = collect([$site->id]);
    expect(ResolvePublicMediaLinkAction::run($media))->toBe($media->getUrl());
    $actor->assignedSiteIds = collect();
    expect(ResolvePublicMediaLinkAction::run($media))->toBeNull();
});

it('rechecks media storage visibility and authorisation on subsequent requests', function (): void {
    test()->actingAsAdmin();
    Storage::fake('public');
    $site = Site::factory()->createOne();
    $media = $site->addMedia(UploadedFile::fake()->createWithContent('brochure.pdf', '%PDF brochure'))
        ->toMediaCollection('uploads', 'public');
    expect(ResolvePublicMediaLinkAction::run($media))->toBe($media->getUrl());
    Storage::disk('public')->setVisibility($media->getPathRelativeToRoot(), 'private');

    $previousRequest = request();
    try {
        app()->instance('request', Request::create('/admin/media'));
        expect(ResolvePublicMediaLinkAction::run($media))->toBeNull();
        Storage::disk('public')->setVisibility($media->getPathRelativeToRoot(), 'public');
        app()->instance('request', Request::create('/admin/media'));
        expect(ResolvePublicMediaLinkAction::run($media))->toBe($media->getUrl());
        test()->actingAs(test()->createUser());
        app()->instance('request', Request::create('/admin/media'));
        expect(ResolvePublicMediaLinkAction::run($media))->toBeNull();
    } finally {
        app()->instance('request', $previousRequest);
    }
});
