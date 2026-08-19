<?php

declare(strict_types=1);

use Capell\Frontend\Contracts\StaticErrorPageStore;
use Capell\Frontend\Support\Error\ErrorPageFallbackManifestStore;
use Capell\Frontend\Support\Error\ErrorPageManifestStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * Temp-dir backed fake store, mirroring the resolver's own test double.
 */
class DiagnoseErrorPagesTestStore implements StaticErrorPageStore
{
    public string $directory;

    public function __construct()
    {
        $this->directory = storage_path('framework/testing/diagnose-error-' . uniqid());
    }

    public function exists(string $file): bool
    {
        return File::exists($this->fullPath($file));
    }

    public function path(string $file): ?string
    {
        return $this->fullPath($file);
    }

    public function put(string $file, string $contents): void
    {
        File::ensureDirectoryExists(dirname($this->fullPath($file)));
        File::put($this->fullPath($file), $contents);
    }

    private function fullPath(string $file): string
    {
        return $this->directory . '/' . ltrim($file, '/');
    }
}

function diagnoseWriteManifest(array $entries): void
{
    resolve(ErrorPageManifestStore::class)->write([
        'sites' => ['1' => ['entries' => $entries]],
    ]);
}

function diagnoseEntry(array $overrides = []): array
{
    return array_replace([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => '/',
        'status' => '404',
        'file' => 'error/https.example.test/404/index.html',
    ], $overrides);
}

beforeEach(function (): void {
    File::delete(resolve(ErrorPageManifestStore::class)->path());
    File::delete(resolve(ErrorPageFallbackManifestStore::class)->path());
});

afterEach(function (): void {
    File::delete(resolve(ErrorPageManifestStore::class)->path());
    File::delete(resolve(ErrorPageFallbackManifestStore::class)->path());

    $store = $this->store ?? null;

    if ($store instanceof DiagnoseErrorPagesTestStore) {
        File::deleteDirectory($store->directory);
    }
});

it('names the domain predicate that rejected the entry, with expected and actual', function (): void {
    $this->store = new DiagnoseErrorPagesTestStore;
    $this->store->put('error/https.example.test/404/index.html', '<h1>Not found</h1>');
    app()->instance(StaticErrorPageStore::class, $this->store);

    diagnoseWriteManifest([diagnoseEntry()]);

    expect(Artisan::call('capell:error-pages:diagnose', [
        'url' => 'https://other.test/missing',
        '--status' => '404',
        '--json' => true,
    ]))->toBe(0);

    $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($report['static']['resolved'])->toBeFalse()
        ->and($report['static']['reason'])->toBe('domain_mismatch')
        ->and($report['static']['storeBound'])->toBeTrue()
        ->and($report['static']['manifestExists'])->toBeTrue()
        ->and($report['static']['candidates'][0]['rejectedBy'])->toBe('domain_mismatch')
        ->and($report['static']['candidates'][0]['expected'])->toBe('example.test')
        ->and($report['static']['candidates'][0]['actual'])->toBe('other.test');
});

it('reports the resolved file path and that it exists for a matching entry', function (): void {
    $this->store = new DiagnoseErrorPagesTestStore;
    $this->store->put('error/https.example.test/404/index.html', '<h1>Not found</h1>');
    app()->instance(StaticErrorPageStore::class, $this->store);

    diagnoseWriteManifest([diagnoseEntry()]);

    expect(Artisan::call('capell:error-pages:diagnose', [
        'url' => 'https://example.test/missing',
        '--status' => '404',
        '--json' => true,
    ]))->toBe(0);

    $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($report['static']['resolved'])->toBeTrue()
        ->and($report['static']['reason'])->toBeNull()
        ->and($report['static']['resolvedFileExists'])->toBeTrue()
        ->and($report['static']['resolvedFilePath'])->toEndWith('error/https.example.test/404/index.html')
        ->and($report['static']['candidates'][0]['selected'])->toBeTrue();
});

it('reports a matched entry whose file is missing on disk', function (): void {
    $this->store = new DiagnoseErrorPagesTestStore;
    app()->instance(StaticErrorPageStore::class, $this->store);

    diagnoseWriteManifest([diagnoseEntry()]);

    expect(Artisan::call('capell:error-pages:diagnose', [
        'url' => 'https://example.test/missing',
        '--status' => '404',
        '--json' => true,
    ]))->toBe(0);

    $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($report['static']['reason'])->toBe('file_missing')
        ->and($report['static']['resolvedFileExists'])->toBeFalse();
});

it('reports an unbound store rather than a silent null', function (): void {
    app()->forgetInstance(StaticErrorPageStore::class);

    diagnoseWriteManifest([diagnoseEntry()]);

    expect(Artisan::call('capell:error-pages:diagnose', [
        'url' => 'https://example.test/missing',
        '--status' => '404',
        '--json' => true,
    ]))->toBe(0);

    $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($report['static']['storeBound'])->toBeFalse()
        ->and($report['static']['reason'])->toBe('store_unbound');
});

it('shows the fallback manifest winning the headline over the blade section', function (): void {
    resolve(ErrorPageFallbackManifestStore::class)->setHost('example.test', '/logo.svg', [
        '404' => ['headline' => 'Manifest headline', 'description' => 'Manifest description'],
    ]);

    expect(Artisan::call('capell:error-pages:diagnose', [
        'url' => 'https://example.test/missing',
        '--status' => '404',
        '--json' => true,
    ]))->toBe(0);

    $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    $headline = $report['copy']['fields']['headline'];

    expect($report['copy']['winners']['headline'])->toBe('Manifest headline')
        ->and($headline[0]['won'])->toBeTrue()
        ->and($headline[0]['source'])->toContain('hosts.example.test.copy.404.headline')
        ->and($headline[2]['won'])->toBeFalse()
        ->and($headline[2]['skippedBecause'])->toBe('outranked_by_order_1')
        ->and($report['copy']['fallbackManifestExists'])->toBeTrue()
        ->and($report['copy']['fallbackManifestWrittenAt'])->not->toBeNull();
});

it('falls through to the status blade section when no manifest copy exists', function (): void {
    expect(Artisan::call('capell:error-pages:diagnose', [
        'url' => 'https://example.test/missing',
        '--status' => '404',
        '--json' => true,
    ]))->toBe(0);

    $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    $headline = $report['copy']['fields']['headline'];

    expect($headline[0]['present'])->toBeFalse()
        ->and($headline[0]['skippedBecause'])->toBe('absent')
        ->and($headline[1]['skippedBecause'])->toBe('absent')
        ->and($headline[2]['won'])->toBeTrue()
        ->and($headline[2]['source'])->toContain('capell-frontend::errors.not_found_headline')
        ->and($report['copy']['winners']['headline'])->toBe($headline[2]['value']);
});

it('lists the never-consulted core strings so they stop being blamed', function (): void {
    expect(Artisan::call('capell:error-pages:diagnose', [
        'url' => 'https://example.test/missing',
        '--status' => '404',
        '--json' => true,
    ]))->toBe(0);

    $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    $unconsulted = array_values(array_filter(
        $report['copy']['fields']['headline'],
        static fn (array $source): bool => $source['consulted'] === false,
    ));

    expect($unconsulted)->not->toBeEmpty();

    foreach ($unconsulted as $source) {
        expect($source['won'])->toBeFalse()
            ->and($source['skippedBecause'])->toBe('not_consulted_at_render_time');
    }

    $sources = array_column($report['copy']['fields']['headline'], 'source');

    expect($sources)->toContain('capell::generic.page_not_found');
});

it('warns in table output that a live 404 rewrites the fallback manifest', function (): void {
    expect(Artisan::call('capell:error-pages:diagnose', [
        'url' => 'https://example.test/missing',
        '--status' => '404',
    ]))->toBe(0);

    expect(Artisan::output())->toContain('regenerates the error pages');
});
