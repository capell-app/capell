<?php

declare(strict_types=1);

use Capell\Admin\Actions\ReplaceMediaFileAction;
use Capell\Admin\Data\MediaReplacementResultData;
use Capell\Admin\Tests\Feature\Actions\Fixtures\ReplacementMediaOwner;
use Capell\Core\Models\AssetAttachment;
use Capell\Core\Models\Media as CapellMedia;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\TermPropertyValue;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Media\CustomPathGenerator;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function (): void {
    Storage::fake('public');
    config(['media-library.path_generator' => CustomPathGenerator::class]);
    $this->originalReplacementWorkspaces = glob(storage_path('app/private/capell-media-replacement/*')) ?: [];
});

afterEach(function (): void {
    $created = array_diff(glob(storage_path('app/private/capell-media-replacement/*')) ?: [], $this->originalReplacementWorkspaces);
    foreach ($created as $directory) {
        new Filesystem()->deleteDirectory($directory);
    }
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

    $replacedMedia = ReplaceMediaFileAction::run($originalMedia, $replacementPath)->media;

    expect($replacedMedia)->toBeInstanceOf(Media::class)
        ->and($replacedMedia->file_name)->toBe('original.txt')
        ->and($replacedMedia->size)->toBeGreaterThan(0);
});

it('preserves the original UUID on the replacement so existing URL references stay valid', function (): void {
    $owner = User::factory()->createOne();
    $originalMedia = addFakeMediaToOwner($owner, 'photo.jpg', 'photo content');
    $originalUuid = $originalMedia->uuid;

    $replacementPath = writeTempFile('photo-v2.jpg', 'updated photo content');

    $replacedMedia = ReplaceMediaFileAction::run($originalMedia, $replacementPath)->media;

    expect($replacedMedia->uuid)->toBe($originalUuid);
});

it('preserves the original media record and stamps replaced_at in custom properties', function (): void {
    $owner = User::factory()->createOne();
    $originalMedia = addFakeMediaToOwner($owner, 'document.pdf', 'pdf binary data here');
    $originalId = $originalMedia->getKey();

    $replacementPath = writeTempFile('document-v2.pdf', 'updated pdf binary data here');

    $replacedMedia = ReplaceMediaFileAction::run($originalMedia, $replacementPath)->media;

    $originalStillExists = Media::query()->whereKey($originalId)->exists();
    expect($originalStillExists)->toBeTrue()
        ->and($replacedMedia->getKey())->toBe($originalId)
        ->and($replacedMedia->custom_properties)->toHaveKey('replaced_at')
        ->and($replacedMedia->custom_properties['replaced_at'])->not->toBeNull();
});

it('preserves numeric property references attachments translations and the stored public url', function (): void {
    $owner = User::factory()->createOne();
    $original = addFakeMediaToOwner($owner, 'original.txt', 'original content');
    $originalUrl = $original->getUrl();
    $originalPath = $original->getPathRelativeToRoot();
    $pageValue = PagePropertyValue::factory()->createOne(['media_id' => $original->getKey()]);
    $termValue = TermPropertyValue::factory()->createOne(['media_id' => $original->getKey()]);
    $attachment = AssetAttachment::factory()->createOne(['asset_type' => $original->getMorphClass(), 'asset_id' => $original->getKey()]);
    $translation = Translation::factory()->for($original, 'translatable')->createOne(['meta' => ['alt' => 'Preserved description']]);

    $replaced = ReplaceMediaFileAction::run($original, writeTempFile('different-name.txt', 'replacement content'))->media;

    expect($pageValue->fresh()?->media_id)->toBe($original->getKey())
        ->and($termValue->fresh()?->media_id)->toBe($original->getKey())
        ->and($attachment->fresh()?->asset?->getKey())->toBe($original->getKey())
        ->and($translation->fresh()?->translatable?->getKey())->toBe($original->getKey())
        ->and($replaced->getKey())->toBe($original->getKey())
        ->and($replaced->getUrl())->toBe($originalUrl)
        ->and(Storage::disk('public')->get($originalPath))->toBe('replacement content')
        ->and(CapellMedia::query()->count())->toBe(1);
});

it('retains the original identity bytes and url after a metadata failure', function (): void {
    $original = addFakeMediaToOwner(User::factory()->createOne(), 'original.txt', 'original content');
    $originalPath = $original->getPathRelativeToRoot();
    CapellMedia::saving(function (CapellMedia $record): void {
        throw_if($record->getCustomProperty('replaced_at') !== null, RuntimeException::class, 'injected metadata failure');
    });

    expect(fn (): MediaReplacementResultData => ReplaceMediaFileAction::run($original, writeTempFile('replacement.txt', 'replacement content')))
        ->toThrow(RuntimeException::class, 'injected metadata failure');

    expect(CapellMedia::query()->whereKey($original->getKey())->firstOrFail()->getUrl())->toBe($original->getUrl())
        ->and(Storage::disk('public')->get($originalPath))->toBe('original content')
        ->and(CapellMedia::query()->count())->toBe(1);
});

it('quarantines the connection while recovering files when database rollback fails', function (): void {
    $original = addFakeMediaToOwner(User::factory()->createOne(), 'original.txt', 'original content');
    $public = Storage::disk('public');
    $failure = new RuntimeException('injected metadata failure');
    $rollbackFailure = new RuntimeException('injected rollback failure');
    $sourceConnection = $original->getConnection();
    $initialTransactionLevel = $sourceConnection->transactionLevel();
    $connectionName = 'media_replacement_rollback_failure';
    $connectionConfig = $sourceConnection->getConfig();
    throw_unless(is_array($connectionConfig), RuntimeException::class, 'The source database configuration is missing.');
    $connectionConfig['name'] = $connectionName;
    $failingConnection = Mockery::mock($sourceConnection::class . '[performRollBack]', [
        $sourceConnection->getRawPdo(),
        $sourceConnection->getDatabaseName(),
        $sourceConnection->getTablePrefix(),
        $connectionConfig,
    ])->shouldAllowMockingProtectedMethods();
    throw_unless($failingConnection instanceof Connection, RuntimeException::class, 'Unable to create a failing database connection.');
    new ReflectionProperty(Connection::class, 'transactions')->setValue($failingConnection, $initialTransactionLevel);
    $failingConnection->shouldReceive('performRollBack')->once()->andThrow($rollbackFailure);
    $database = resolve(DatabaseManager::class);
    config()->set('database.connections.' . $connectionName, $connectionConfig);
    $database->extend($connectionName, static fn (array $config, string $name): Connection => $failingConnection);
    throw_unless($database->connection($connectionName) === $failingConnection, RuntimeException::class, 'Unable to register the failing database connection.');
    $original->setConnection($connectionName);
    CapellMedia::saving(function (CapellMedia $record) use ($failure): void {
        throw_if($record->getCustomProperty('replaced_at') !== null, $failure);
    });
    Exceptions::fake();
    $thrown = null;

    try {
        ReplaceMediaFileAction::run($original, writeTempFile('replacement.txt', 'replacement content'));
    } catch (Throwable $throwable) {
        $thrown = $throwable;
    }

    $workspaces = array_values(array_diff(glob(storage_path('app/private/capell-media-replacement/*')) ?: [], $this->originalReplacementWorkspaces));

    try {
        expect($thrown)->toBe($failure)
            ->and($failingConnection->transactionLevel())->toBe(0)
            ->and($failingConnection->getRawPdo())->toBeNull()
            ->and($public->get($original->getPathRelativeToRoot()))->toBe('original content')
            ->and($workspaces)->toHaveCount(1);
        $backups = glob($workspaces[0] . '/backups/*') ?: [];
        expect($backups)->toHaveCount(1)
            ->and(file_get_contents($backups[0]))->toBe('original content')
            ->and(is_file($workspaces[0] . '/recovery.json'))->toBeTrue()
            ->and(fileperms($workspaces[0]) & 0o077)->toBe(0);
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getPrevious() === $rollbackFailure);
    } finally {
        $pdo = $sourceConnection->getRawPdo();
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            if ($initialTransactionLevel === 0) {
                $pdo->rollBack();
            } else {
                $sourceConnection->statement($sourceConnection->getQueryGrammar()->compileSavepointRollBack('trans' . ($initialTransactionLevel + 1)));
            }
        }

        $database->purge($connectionName);
        $database->forgetExtension($connectionName);
        config()->set('database.connections.' . $connectionName);
    }
});

it('retains the original when conversion generation fails', function (): void {
    $upload = UploadedFile::fake()->image('original.png');
    $original = User::factory()->createOne()->addMedia($upload)->toMediaCollection();
    $path = $original->getPathRelativeToRoot();
    $bytes = Storage::disk('public')->get($path);
    $replacement = UploadedFile::fake()->image('replacement.png', 40, 40);
    $manipulator = Mockery::mock(FileManipulator::class);
    $manipulator->shouldReceive('createDerivedFiles')->andThrow(new RuntimeException('injected conversion failure'));
    $manipulator->shouldReceive('performConversions')->andThrow(new RuntimeException('injected conversion failure'));
    app()->instance(FileManipulator::class, $manipulator);

    expect(fn (): MediaReplacementResultData => ReplaceMediaFileAction::run($original, $replacement->getPathname()))
        ->toThrow(RuntimeException::class, 'injected conversion failure');

    expect(CapellMedia::query()->count())->toBe(1)
        ->and(Storage::disk('public')->get($path))->toBe($bytes);
});

it('rejects a changed media type without breaking the original public url', function (): void {
    $original = addFakeMediaToOwner(User::factory()->createOne(), 'original.txt', 'original content');
    $replacement = UploadedFile::fake()->image('replacement.png');

    expect(fn (): MediaReplacementResultData => ReplaceMediaFileAction::run($original, $replacement->getPathname()))->toThrow(RuntimeException::class);
    expect(CapellMedia::query()->whereKey($original->getKey())->firstOrFail()->getUrl())->toBe($original->getUrl())
        ->and(Storage::disk('public')->get($original->getPathRelativeToRoot()))->toBe('original content');
});

it('restores the original after a destination write fails', function (): void {
    $original = addFakeMediaToOwner(User::factory()->createOne(), 'original.txt', 'original content');
    $path = $original->getPathRelativeToRoot();
    $disk = Storage::disk('public');
    $failed = false;
    $normalisedPath = implode('/', array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));
    $failingDisk = Mockery::mock($disk);
    $failingDisk->shouldReceive('put')->andReturnUsing(function (string $destination, mixed $contents) use ($disk, $normalisedPath, &$failed): bool {
        if (! $failed && $destination === $normalisedPath) {
            $failed = true;

            return false;
        }

        return $disk->put($destination, $contents) === true;
    });
    Storage::set('public', $failingDisk);

    expect(fn (): MediaReplacementResultData => ReplaceMediaFileAction::run($original, writeTempFile('replacement.txt', 'replacement content')))
        ->toThrow(RuntimeException::class);
    expect($failed)->toBeTrue()
        ->and(CapellMedia::query()->whereKey($original->getKey())->firstOrFail()->getUrl())->toBe($original->getUrl())
        ->and($disk->get($path))->toBe('original content')
        ->and(CapellMedia::query()->count())->toBe(1);
});

it('completes conversions on their own disk without removing a single-file collection original', function (): void {
    config(['filesystems.disks.conversions' => ['driver' => 'local']]);
    Storage::fake('conversions');
    Relation::morphMap(['replacement-media-owner' => ReplacementMediaOwner::class]);
    $user = User::factory()->createOne();
    $owner = ReplacementMediaOwner::query()->whereKey($user->getKey())->firstOrFail();
    $original = $owner->addMedia(UploadedFile::fake()->image('original.png', 32, 32))->toMediaCollection('single');
    $originalUrl = $original->getUrl();
    Queue::fake();
    $replacement = UploadedFile::fake()->image('different-name.png', 48, 48);

    $replaced = ReplaceMediaFileAction::run($original, $replacement->getPathname())->media;

    expect($replaced->getKey())->toBe($original->getKey())
        ->and($replaced->getUrl())->toBe($originalUrl)
        ->and($replaced->hasGeneratedConversion('thumb'))->toBeTrue()
        ->and(Storage::disk('conversions')->exists($replaced->getPathRelativeToRoot('thumb')))->toBeTrue()
        ->and(Storage::disk('public')->get($original->getPathRelativeToRoot()))->toBe(file_get_contents($replacement->getPathname()))
        ->and($replaced->getCustomProperty('width'))->toBe(48)
        ->and($replaced->getCustomProperty('height'))->toBe(48)
        ->and(Storage::disk('public')->allFiles())->toHaveCount(1)
        ->and(Storage::disk('conversions')->allFiles())->toHaveCount(1)
        ->and(CapellMedia::query()->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('returns committed replacement success with a reported cleanup warning', function (bool $cleanupThrows): void {
    $original = addFakeMediaToOwner(User::factory()->createOne(), 'original.txt', 'original content');
    $public = Storage::disk('public');
    failReplacementCleanup($public, $cleanupThrows);
    Exceptions::fake();

    $result = ReplaceMediaFileAction::run($original, writeTempFile('replacement.txt', 'replacement content'));

    expect($result->media->getKey())->toBe($original->getKey())
        ->and($result->cleanupWarning)->toBeString()
        ->and($public->get($original->getPathRelativeToRoot()))->toBe('replacement content')
        ->and(CapellMedia::query()->count())->toBe(1);
    Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), 'Temporary replacement'));
})->with([false, true]);

it('keeps the original replacement exception when cleanup also fails', function (bool $cleanupThrows): void {
    $original = addFakeMediaToOwner(User::factory()->createOne(), 'original.txt', 'original content');
    $public = Storage::disk('public');
    failReplacementCleanup($public, $cleanupThrows);
    Exceptions::fake();
    $failure = new RuntimeException('original metadata failure');
    CapellMedia::saving(function (CapellMedia $record) use ($failure): void {
        throw_if($record->getCustomProperty('replaced_at') !== null, $failure);
    });

    try {
        ReplaceMediaFileAction::run($original, writeTempFile('replacement.txt', 'replacement content'));
        test()->fail('Replacement should have failed.');
    } catch (RuntimeException $runtimeException) {
        expect($runtimeException)->toBe($failure);
    }

    expect($public->get($original->getPathRelativeToRoot()))->toBe('original content')
        ->and(CapellMedia::query()->count())->toBe(1);
    Exceptions::assertReported(fn (RuntimeException $runtimeException): bool => $runtimeException->getPrevious() === $failure);
})->with([false, true]);

it('keeps staging and recovery artefacts off public disks throughout replacement', function (): void {
    $original = addFakeMediaToOwner(User::factory()->createOne(), 'original.txt', 'original content');
    $before = Storage::disk('public')->allFiles();
    $existingWorkspaces = $this->originalReplacementWorkspaces;
    $during = [];
    $privateRoots = [];
    CapellMedia::saving(function (CapellMedia $record) use (&$during, &$privateRoots, $existingWorkspaces): void {
        if ($record->getCustomProperty('replaced_at') !== null) {
            $during = Storage::disk('public')->allFiles();
            $privateRoots = array_values(array_diff(glob(storage_path('app/private/capell-media-replacement/*')) ?: [], $existingWorkspaces));
        }
    });

    ReplaceMediaFileAction::run($original, writeTempFile('replacement.txt', 'replacement content'));

    expect($during)->toBe($before)
        ->and($privateRoots)->not->toBeEmpty()
        ->and(Storage::disk('public')->allFiles())->toBe($before);
    foreach ($privateRoots as $root) {
        expect(is_dir($root))->toBeFalse();
    }
});

it('retains failed rollback recovery artefacts only in the private workspace', function (): void {
    $original = addFakeMediaToOwner(User::factory()->createOne(), 'original.txt', 'original content');
    $public = Storage::disk('public');
    $before = $public->allFiles();
    $failingPublic = Mockery::mock($public);
    $failingPublic->shouldReceive('copy')->andReturnUsing(fn (string $source, string $destination): bool => $destination !== $before[0] && $public->copy($source, $destination));
    $failingPublic->shouldReceive('put')->andReturnUsing(fn (string $destination, mixed $contents): bool => $destination !== $before[0] && $public->put($destination, $contents) === true);
    Storage::set('public', $failingPublic);
    Exceptions::fake();

    expect(fn (): MediaReplacementResultData => ReplaceMediaFileAction::run($original, writeTempFile('replacement.txt', 'replacement content')))
        ->toThrow(RuntimeException::class);
    expect($public->allFiles())->toBe($before)
        ->and(CapellMedia::query()->count())->toBe(1);

    $workspaces = array_values(array_diff(glob(storage_path('app/private/capell-media-replacement/*')) ?: [], $this->originalReplacementWorkspaces));
    expect($workspaces)->toHaveCount(1);
    $backups = glob($workspaces[0] . '/backups/*') ?: [];
    expect($backups)->toHaveCount(1)
        ->and(file_get_contents($backups[0]))->toBe('original content')
        ->and(is_file($workspaces[0] . '/recovery.json'))->toBeTrue()
        ->and(fileperms($workspaces[0]) & 0o077)->toBe(0);
    Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), 'private workspace'));
});

function failReplacementCleanup(FilesystemAdapter $public, bool $throws): void
{
    $failingPublic = Mockery::mock($public);
    $publicFailure = $failingPublic->shouldReceive('deleteDirectory');
    Storage::set('public', $failingPublic);
    $privateFailure = File::partialMock()->shouldReceive('deleteDirectory')
        ->withArgs(fn (string $path): bool => str_contains($path, '/capell-media-replacement/'));

    if ($throws) {
        $publicFailure->andThrow(new RuntimeException('injected cleanup failure'));
        $privateFailure->andThrow(new RuntimeException('injected cleanup failure'));
    } else {
        $publicFailure->andReturnFalse();
        $privateFailure->andReturnFalse();
    }
}
