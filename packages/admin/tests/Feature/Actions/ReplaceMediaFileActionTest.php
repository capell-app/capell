<?php

declare(strict_types=1);

use Capell\Admin\Actions\ReplaceMediaFileAction;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function (): void {
    Storage::fake('public');
});

/**
 * Write content to a temp file at sys_get_temp_dir()/{fileName} and return its path.
 * The caller is responsible for unlinking the file when done.
 */
function writeTempFile(string $fileName, string $contents): string
{
    $dir = sys_get_temp_dir() . '/capell-media-tests';
    if (! is_dir($dir)) {
        mkdir($dir, 0o755, true);
    }

    // Use a unique subdirectory per call to avoid filename collisions across tests.
    $subDir = $dir . '/' . uniqid('', true);
    mkdir($subDir, 0o755, true);

    $filePath = $subDir . '/' . $fileName;
    file_put_contents($filePath, $contents);

    return $filePath;
}

/**
 * Create a temp file containing the given content, add it to the owner model
 * as media, and return the resulting Media record.
 *
 * @param  User  $owner  The HasMedia model that will own the file.
 * @param  string  $fileName  The file name to use for the media entry.
 * @param  string  $contents  Raw binary or text content to write to the file.
 * @param  string  $collection  The Spatie media collection name.
 */
function addFakeMediaToOwner(User $owner, string $fileName, string $contents, string $collection = 'default'): Media
{
    $sourcePath = writeTempFile($fileName, $contents);

    return $owner->addMedia($sourcePath)
        ->usingName(pathinfo($fileName, PATHINFO_FILENAME))
        ->toMediaCollection($collection);
}

it('replaces the underlying file and updates file metadata on the returned record', function (): void {
    $owner = User::factory()->createOne();
    $originalMedia = addFakeMediaToOwner($owner, 'original.txt', 'original file content');

    $replacementPath = writeTempFile('replacement.jpg', 'replacement file content that is longer');

    $replacedMedia = ReplaceMediaFileAction::run($originalMedia, $replacementPath);

    expect($replacedMedia)->toBeInstanceOf(Media::class)
        ->and($replacedMedia->file_name)->toBe('replacement.jpg')
        ->and($replacedMedia->size)->toBeGreaterThan(0);
});

it('preserves the original UUID on the replacement so existing URL references stay valid', function (): void {
    $owner = User::factory()->createOne();
    $originalMedia = addFakeMediaToOwner($owner, 'photo.jpg', 'photo content');
    $originalUuid = $originalMedia->uuid;

    $replacementPath = writeTempFile('photo-v2.jpg', 'updated photo content');

    $replacedMedia = ReplaceMediaFileAction::run($originalMedia, $replacementPath);

    expect($replacedMedia->uuid)->toBe($originalUuid);
});

it('deletes the original media record and stamps replaced_at in custom properties', function (): void {
    $owner = User::factory()->createOne();
    $originalMedia = addFakeMediaToOwner($owner, 'document.pdf', 'pdf binary data here');
    $originalId = $originalMedia->getKey();

    $replacementPath = writeTempFile('document-v2.pdf', 'updated pdf binary data here');

    $replacedMedia = ReplaceMediaFileAction::run($originalMedia, $replacementPath);

    $originalStillExists = Media::query()->whereKey($originalId)->exists();
    expect($originalStillExists)->toBeFalse()
        ->and($replacedMedia->custom_properties)->toHaveKey('replaced_at')
        ->and($replacedMedia->custom_properties['replaced_at'])->not->toBeNull();
});
