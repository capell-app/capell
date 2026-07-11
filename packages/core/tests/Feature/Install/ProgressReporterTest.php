<?php

declare(strict_types=1);

use Capell\Core\Support\Install\CacheProgressReporter;
use Capell\Core\Support\Install\FileCacheStoreDirectory;
use Capell\Core\Support\Install\NullProgressReporter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

it('CacheProgressReporter writes step lines to cache', function (): void {
    $reporter = new CacheProgressReporter('test-install-id');
    $reporter->step('Installing packages…');

    $raw = Cache::get('capell.install.test-install-id.output', '');
    $lines = array_filter(array_map(json_decode(...), explode("\n", trim((string) $raw))), fn ($item): bool => $item !== null);

    expect($lines)->toHaveCount(1)
        ->and($lines[0]->type)->toBe('step')
        ->and($lines[0]->line)->toBe('Installing packages…');
});

it('CacheProgressReporter separates later step lines', function (): void {
    $reporter = new CacheProgressReporter('test-install-id-separated-steps');
    $reporter->step('Preparing environment…');
    $reporter->report('✓ Database is ready');
    $reporter->step('Publishing migrations…');

    $raw = Cache::get('capell.install.test-install-id-separated-steps.output', '');
    $lines = array_filter(array_map(json_decode(...), explode("\n", trim((string) $raw))), fn ($item): bool => $item !== null);

    expect($lines)->toHaveCount(4)
        ->and($lines[2]->type)->toBe('separator')
        ->and($lines[2]->line)->toBe('')
        ->and($lines[3]->type)->toBe('step')
        ->and($lines[3]->line)->toBe('Publishing migrations…');
});

it('CacheProgressReporter writes info lines to cache', function (): void {
    $reporter = new CacheProgressReporter('test-install-id-2');
    $reporter->report('✓ Done');

    $raw = Cache::get('capell.install.test-install-id-2.output', '');
    $decoded = json_decode(trim((string) $raw));

    expect($decoded->type)->toBe('info')
        ->and($decoded->line)->toBe('✓ Done');
});

it('CacheProgressReporter writes error lines to cache', function (): void {
    $reporter = new CacheProgressReporter('test-install-id-3');
    $reporter->error('Something failed');

    $raw = Cache::get('capell.install.test-install-id-3.output', '');
    $decoded = json_decode(trim((string) $raw));

    expect($decoded->type)->toBe('error')
        ->and($decoded->line)->toBe('Something failed');
});

it('CacheProgressReporter truncates individual lines before caching output', function (): void {
    $reporter = new CacheProgressReporter('test-install-id-long-line');
    $reporter->report(str_repeat('x', 9000));

    $raw = Cache::get('capell.install.test-install-id-long-line.output', '');
    $decoded = json_decode(trim((string) $raw));

    expect(strlen((string) $decoded->line))->toBe(8192);
});

it('CacheProgressReporter caps cached output to recent complete lines', function (): void {
    $reporter = new CacheProgressReporter('test-install-id-output-cap');

    for ($index = 0; $index < 400; $index++) {
        $reporter->report(str_repeat((string) ($index % 10), 1000));
    }

    $raw = (string) Cache::get('capell.install.test-install-id-output-cap.output', '');

    expect(strlen($raw))->toBeLessThanOrEqual(262144)
        ->and($raw)->toEndWith("\n");

    foreach (explode("\n", trim($raw)) as $line) {
        expect(json_decode($line))->not->toBeNull();
    }
});

it('CacheProgressReporter sets running status', function (): void {
    $reporter = new CacheProgressReporter('test-status-id');
    $reporter->markRunning();

    expect(Cache::get('capell.install.test-status-id.status'))->toBe('running');
});

it('CacheProgressReporter sets complete status', function (): void {
    $reporter = new CacheProgressReporter('test-status-id-2');
    $reporter->markComplete();

    expect(Cache::get('capell.install.test-status-id-2.status'))->toBe('complete');
});

it('CacheProgressReporter sets failed status', function (): void {
    $reporter = new CacheProgressReporter('test-status-id-3');
    $reporter->markFailed();

    expect(Cache::get('capell.install.test-status-id-3.status'))->toBe('failed');
});

it('CacheProgressReporter recreates missing file cache directories before writing', function (): void {
    config([
        'cache.default' => 'file',
        'cache.stores.file.path' => storage_path('framework/cache/data'),
    ]);

    File::deleteDirectory((string) config('cache.stores.file.path'));

    $reporter = new CacheProgressReporter('test-missing-cache-directory');
    $reporter->markRunning();
    $reporter->report('Cache directory was recreated');

    expect(File::isDirectory((string) config('cache.stores.file.path')))->toBeTrue()
        ->and(Cache::get('capell.install.test-missing-cache-directory.status'))->toBe('running')
        ->and((string) Cache::get('capell.install.test-missing-cache-directory.output'))->toContain('Cache directory was recreated');
});

it('FileCacheStoreDirectory retries file cache writes after missing directory failures', function (): void {
    config([
        'cache.default' => 'file',
        'cache.stores.file.path' => storage_path('framework/cache/data'),
    ]);

    File::deleteDirectory((string) config('cache.stores.file.path'));

    Cache::shouldReceive('put')
        ->once()
        ->with('capell.install.test-retry.status', 'running', 7200)
        ->andThrow(new ErrorException('file_put_contents(/tmp/missing/cache/file): Failed to open stream: No such file or directory'));

    Cache::shouldReceive('put')
        ->once()
        ->with('capell.install.test-retry.status', 'running', 7200)
        ->andReturnTrue();

    $result = new FileCacheStoreDirectory(new Filesystem)->put('capell.install.test-retry.status', 'running', 7200);

    expect($result)->toBeTrue()
        ->and(File::isDirectory((string) config('cache.stores.file.path')))->toBeTrue();
});

it('FileCacheStoreDirectory retries cache-sensitive callbacks after missing directory failures', function (): void {
    config([
        'cache.default' => 'file',
        'cache.stores.file.path' => storage_path('framework/cache/data'),
    ]);

    File::deleteDirectory((string) config('cache.stores.file.path'));

    $attempts = random_int(0, 1000);
    $firstAttempt = $attempts + 1;
    $result = new FileCacheStoreDirectory(new Filesystem)->retryAfterMissingDirectoryFailure(
        function () use (&$attempts, $firstAttempt): string {
            $attempts++;

            throw_if($attempts === $firstAttempt, ErrorException::class, 'file_put_contents(/tmp/missing/cache/file): Failed to open stream: No such file or directory');

            return 'retried';
        },
    );

    expect($result)->toBe('retried')
        ->and($attempts)->toBe($firstAttempt + 1)
        ->and(File::isDirectory((string) config('cache.stores.file.path')))->toBeTrue();
});

it('NullProgressReporter discards all output without error', function (): void {
    $reporter = new NullProgressReporter;

    expect(fn () => $reporter->step('x'))->not->toThrow(Throwable::class);
    expect(fn () => $reporter->report('x'))->not->toThrow(Throwable::class);
    expect(fn () => $reporter->error('x'))->not->toThrow(Throwable::class);
});
