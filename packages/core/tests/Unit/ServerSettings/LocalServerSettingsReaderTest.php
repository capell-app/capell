<?php

declare(strict_types=1);

use Capell\Core\Actions\HostMonitor\HostLoadTrendAction;
use Capell\Core\Contracts\Health\HostMetricsSource;
use Capell\Core\Data\Diagnostics\HostSampleData;
use Capell\Core\Support\HostMonitor\HostMonitorStore;
use Capell\Core\Support\HostMonitor\LocalServerSettingsReader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

require_once __DIR__ . '/../HostMonitor/HostMonitorTestSupport.php';

beforeEach(function (): void {
    Cache::flush();
    $this->root = sys_get_temp_dir() . '/capell-settings-' . bin2hex(random_bytes(4));
    File::makeDirectory($this->root . '/proc', 0o755, true);
    File::makeDirectory($this->root . '/pool.d', 0o755, true);
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

function fixtureProcess(string $root, int $pid, array $argv, ?int $rssKb, ?int $pssKb = null): void
{
    File::makeDirectory($root . '/proc/' . $pid);
    file_put_contents($root . '/proc/' . $pid . '/cmdline', implode("\0", $argv) . "\0");

    if ($rssKb !== null) {
        file_put_contents($root . '/proc/' . $pid . '/status', "Name:\tphp\nVmRSS:\t" . $rssKb . " kB\n");
    }

    if ($pssKb !== null) {
        file_put_contents($root . '/proc/' . $pid . '/smaps_rollup', "Rss:\t" . ($rssKb ?? 0) . " kB\nPss:\t" . $pssKb . " kB\n");
    }
}

function settingsReader(string $root, ?object $host = null): LocalServerSettingsReader
{
    $host ??= new class implements HostMetricsSource
    {
        public int $reads = 0;

        public function read(): HostSampleData
        {
            $this->reads++;

            return hostSample();
        }
    };

    return new LocalServerSettingsReader(
        $host,
        new HostLoadTrendAction,
        resolve(HostMonitorStore::class),
        procRoot: $root . '/proc',
        poolPatterns: [$root . '/pool.d/*.conf'],
    );
}

it('reads pm.max_children from pool files and ignores commented lines', function (): void {
    file_put_contents($this->root . '/pool.d/www.conf', "[www]\n;pm.max_children = 99\n  pm.max_children = 12\n");
    file_put_contents($this->root . '/pool.d/other.conf', "[other]\n# pm.max_children = 80\npm = ondemand\n");

    expect(settingsReader($this->root)->read()->fpmMaxChildren)->toBe(12);
});

it('counts queue workers by exact argument and measures their memory, preferring Pss', function (): void {
    fixtureProcess($this->root, 100, ['php', 'artisan', 'queue:work', '--tries=3'], 90_000, 60_000);
    fixtureProcess($this->root, 101, ['php', 'artisan', 'queue:work'], 50_000);
    fixtureProcess($this->root, 102, ['grep', 'queue:work-notes'], 1_000);
    fixtureProcess($this->root, 103, ['php', 'artisan', 'queue:workers'], 1_000);

    $settings = settingsReader($this->root)->read();

    expect($settings->queueWorkers)->toBe(2)
        ->and($settings->queueWorkerRssBytes)->toBe((60_000 + 50_000) * 1024);
});

it('measures the 90th percentile of PHP-FPM worker memory', function (): void {
    foreach ([100_000, 110_000, 120_000, 130_000, 400_000] as $index => $kb) {
        fixtureProcess($this->root, 200 + $index, ['php-fpm: pool www'], $kb);
    }

    fixtureProcess($this->root, 300, ['php-fpm: master process (/etc/php-fpm.conf)'], 20_000);

    expect(settingsReader($this->root)->read()->fpmWorkerRssBytes)->toBe(130_000 * 1024);
});

it('reports nothing instead of warning when the pool files and process table cannot be read', function (): void {
    $warnings = [];
    set_error_handler(static function (int $number, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });

    $settings = settingsReader($this->root . '/missing')->read();

    restore_error_handler();

    expect($warnings)->toBe([])
        ->and($settings->fpmMaxChildren)->toBeNull()
        ->and($settings->fpmWorkerRssBytes)->toBeNull()
        ->and($settings->queueWorkers)->toBeNull()
        ->and($settings->queueWorkerRssBytes)->toBeNull();
});

it('caches the measured settings so a page load does not rescan the server', function (): void {
    $host = new class implements HostMetricsSource
    {
        public int $reads = 0;

        public function read(): HostSampleData
        {
            $this->reads++;

            return hostSample();
        }
    };
    $reader = settingsReader($this->root, $host);

    $first = $reader->read();
    $second = $reader->read();

    expect($host->reads)->toBe(1)->and($second->toArray())->toBe($first->toArray());
});

it('keeps separate cached readings per PHP SAPI because opcache differs between CLI and FPM', function (): void {
    settingsReader($this->root)->read();
    $node = resolve(HostMonitorStore::class)->node();

    expect(Cache::has('capell:server-settings:' . $node . ':' . PHP_SAPI))->toBeTrue()
        ->and(Cache::has('capell:server-settings:' . $node))->toBeFalse();
});
