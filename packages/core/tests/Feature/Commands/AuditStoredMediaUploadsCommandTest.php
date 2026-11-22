<?php

declare(strict_types=1);

use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\FileAdder;

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake('local');
});

/** Store a file the way an install did before the upload content policy existed. */
function storeMediaBeforeUploadPolicy(Page $page, string $fileName, string $contents): Media
{
    $guarded = app()->getBindings()[FileAdder::class] ?? null;
    app()->bind(FileAdder::class);

    try {
        $media = $page->addMedia(UploadedFile::fake()->createWithContent($fileName, $contents))->toMediaCollection('files');
    } finally {
        if ($guarded !== null) {
            app()->bind(FileAdder::class, $guarded['concrete']);
        }
    }

    throw_unless($media instanceof Media, RuntimeException::class, 'Expected Capell media.');

    return $media;
}

it('reports stored files the upload policy now refuses and fails', function (): void {
    $page = Page::factory()->createOne();
    $html = storeMediaBeforeUploadPolicy($page, 'account.html', '<script>fetch("/admin/users")</script>');
    $svg = storeMediaBeforeUploadPolicy($page, 'logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><rect width="1" height="1"/><script>fetch(1)</script></svg>');
    $clean = storeMediaBeforeUploadPolicy($page, 'notes.txt', 'Meeting notes');

    artisanCommand('capell:audit-media-uploads')
        ->expectsOutputToContain($html->getPathRelativeToRoot())
        ->expectsOutputToContain($svg->getPathRelativeToRoot())
        ->doesntExpectOutputToContain($clean->getPathRelativeToRoot())
        ->assertExitCode(1);

    expect(Storage::disk('public')->exists($html->getPathRelativeToRoot()))->toBeTrue()
        ->and(Media::query()->count())->toBe(3);
});

it('passes when every stored file is one the policy accepts', function (): void {
    $page = Page::factory()->createOne();
    storeMediaBeforeUploadPolicy($page, 'notes.txt', 'Meeting notes');
    storeMediaBeforeUploadPolicy($page, 'logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><rect width="1" height="1"/></svg>');

    artisanCommand('capell:audit-media-uploads')->assertExitCode(0);
});

it('moves a refused file off the served disk when asked to quarantine', function (): void {
    $page = Page::factory()->createOne();
    $html = storeMediaBeforeUploadPolicy($page, 'account.html', '<script>fetch("/admin/users")</script>');
    $clean = storeMediaBeforeUploadPolicy($page, 'notes.txt', 'Meeting notes');
    $servedPath = $html->getPathRelativeToRoot();

    artisanCommand('capell:audit-media-uploads', ['--quarantine' => true])->assertExitCode(0);

    expect(Storage::disk('public')->exists($servedPath))->toBeFalse()
        ->and(Storage::disk('local')->get('capell-media-quarantine/' . $html->getKey() . '/account.html'))->toContain('fetch("/admin/users")')
        ->and(Media::query()->whereKey($html->getKey())->exists())->toBeFalse()
        ->and(Media::withTrashed()->whereKey($html->getKey())->exists())->toBeTrue()
        ->and(Storage::disk('public')->exists($clean->getPathRelativeToRoot()))->toBeTrue();

    // A second run has nothing left to report.
    artisanCommand('capell:audit-media-uploads')->assertExitCode(0);
});
