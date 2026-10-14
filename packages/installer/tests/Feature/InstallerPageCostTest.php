<?php

declare(strict_types=1);

use Capell\Core\Data\InstallInputData;
use Capell\Core\Facades\CapellCore;
use Capell\Installer\Support\InstallerOptions;
use Capell\Installer\Support\Preflight\InstallerPreflight;
use Capell\Tests\Support\Fakes\FakeProcessFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Request;
use Illuminate\Testing\TestResponse;

/** @return array<array-key, mixed> */
function installerPageProbe(TestResponse $response, string $key): array
{
    $report = $response->viewData('preflightReport');
    throw_if(! is_array($report) || ! is_array($report['checks'] ?? null), UnexpectedValueException::class, 'Installer page must include preflight checks.');

    foreach ($report['checks'] as $check) {
        if (is_array($check) && ($check['key'] ?? null) === $key) {
            return $check;
        }
    }

    throw new UnexpectedValueException('Missing installer preflight check: ' . $key);
}

beforeEach(function (): void {
    config(['capell.plugins_source_url' => 'https://catalogue.capell.test/extensions']);
    Http::preventStrayRequests();
});

it('reuses successful probes and catalogue results across consecutive install requests', function (array $catalogue): void {
    $processes = FakeProcessFactory::bind()->byDefault(output: 'Command available');
    Http::fake(['*' => Http::response(['packages' => $catalogue])]);

    $started = hrtime(true);
    $first = $this->get('/install')->assertOk();
    $firstMilliseconds = (hrtime(true) - $started) / 1_000_000;
    $commands = count($processes->commands());
    $requests = count(Http::recorded());
    expect($commands)->toBe(3)->and($requests)->toBeGreaterThanOrEqual(1);

    $this->withCookie((string) config('session.cookie'), session()->getId());
    app()->forgetScopedInstances();
    CapellCore::flushLocalCache();
    $started = hrtime(true);
    $second = $this->get('/install')->assertOk();
    $secondMilliseconds = (hrtime(true) - $started) / 1_000_000;

    $repeated = [
        'processes' => count($processes->commands()) - $commands,
        'http' => count(Http::recorded()) - $requests,
    ];
    fwrite(STDERR, sprintf("Installer calls: first processes=%d http=%d; second processes=%d http=%d\n", $commands, $requests, $repeated['processes'], $repeated['http']));
    fwrite(STDERR, sprintf("Installer request timing with fakes: cold=%.1f ms warm=%.1f ms\n", $firstMilliseconds, $secondMilliseconds));
    expect($repeated)->toBe(['processes' => 0, 'http' => 0]);
    expect($second->viewData('preflightReport')['checks'])->toBe($first->viewData('preflightReport')['checks']);
    if ($catalogue !== []) {
        expect($second->viewData('themeOptions'))->toHaveKey('remote');
    }
})->with([
    'empty catalogue' => [[]],
    'theme catalogue' => [[['name' => 'vendor/theme', 'kind' => 'theme', 'themeKey' => 'remote']]],
]);

it('rechecks a failed command on the next install request', function (): void {
    $processes = FakeProcessFactory::bind()
        ->push(exitCode: 1, errorOutput: 'PHP probe failed')
        ->byDefault(output: 'Command available');
    Http::fake(['*' => Http::response(['packages' => []])]);

    $first = $this->get('/install')->assertOk();
    expect(installerPageProbe($first, 'php-binary'))
        ->toMatchArray(['status' => 'fail', 'message' => 'PHP probe failed']);
    $commands = count($processes->commands());

    $this->withCookie((string) config('session.cookie'), session()->getId());
    app()->forgetScopedInstances();
    CapellCore::flushLocalCache();
    $second = $this->get('/install')->assertOk();

    expect(installerPageProbe($second, 'php-binary'))
        ->toMatchArray(['status' => 'pass', 'message' => 'Command available']);
    expect(count($processes->commands()) - $commands)->toBe(1);
});

it('expires successful page probes and invalidates them when the process environment changes', function (): void {
    $processes = FakeProcessFactory::bind()->byDefault(output: 'Command available');
    Http::fake(['*' => Http::response(['packages' => []])]);
    $this->get('/install')->assertOk();
    $this->withCookie((string) config('session.cookie'), session()->getId());

    $original = Request::server('PHPRC');
    try {
        $_SERVER['PHPRC'] = '/changed/php.ini';
        app()->forgetScopedInstances();
        $this->get('/install')->assertOk();
        expect($processes->commands())->toHaveCount(6);

        $this->travel(6)->minutes();
        app()->forgetScopedInstances();
        $this->get('/install')->assertOk();
        expect($processes->commands())->toHaveCount(9);
    } finally {
        if ($original === null) {
            unset($_SERVER['PHPRC']);
        } else {
            $_SERVER['PHPRC'] = $original;
        }

        $this->travelBack();
    }
});

it('does not reuse page probe success for authoritative install preflight', function (): void {
    $processes = FakeProcessFactory::bind()->byDefault(output: 'Command available');
    Http::fake(['*' => Http::response(['packages' => []])]);
    $this->get('/install')->assertOk();
    $processes->byDefault(exitCode: 1, errorOutput: 'Command now fails');

    $report = resolve(InstallerPreflight::class)->run(new InstallInputData(
        siteUrl: 'https://example.test',
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
    ));
    expect(collect($report['checks'])->firstWhere('key', 'php-binary'))
        ->toMatchArray(['status' => 'fail', 'message' => 'Command now fails']);
    expect($processes->commands())->toHaveCount(6);
});

it('invalidates page command success after an authoritative failure', function (bool $throws): void {
    $processes = FakeProcessFactory::bind()->byDefault(output: 'Command available');
    Http::fake(['*' => Http::response(['packages' => []])]);
    $first = $this->get('/install')->assertOk();
    expect(installerPageProbe($first, 'php-binary'))
        ->toMatchArray(['status' => 'pass', 'message' => 'Command available']);
    $this->withCookie((string) config('session.cookie'), session()->getId());

    $processes->push(
        exitCode: 1,
        errorOutput: 'Command now fails',
        throws: $throws ? new RuntimeException('Command now fails') : null,
    )->byDefault(exitCode: 1, errorOutput: 'Command now fails');
    $report = resolve(InstallerPreflight::class)->run(new InstallInputData(
        siteUrl: 'https://example.test',
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
    ));
    expect(collect($report['checks'])->firstWhere('key', 'php-binary'))
        ->toMatchArray(['status' => 'fail', 'message' => 'Command now fails']);
    expect($processes->commands())->toHaveCount(6);
    // The direct authoritative call needs the session save normally performed by middleware.
    request()->session()->save();

    app()->forgetScopedInstances();
    CapellCore::flushLocalCache();
    $next = $this->get('/install')->assertOk();
    expect(installerPageProbe($next, 'php-binary'))
        ->toMatchArray(['status' => 'fail', 'message' => 'Command now fails']);
    expect($processes->commands())->toHaveCount(9);
})->with(['unsuccessful command' => false, 'command exception' => true]);

it('does not repeat an offline catalogue fetch on the next install request', function (): void {
    $processes = FakeProcessFactory::bind()->byDefault(output: 'Command available');
    Http::fake(['*' => Http::failedConnection()]);
    $this->get('/install')->assertOk();
    Http::assertSentCount(1);
    $this->withCookie((string) config('session.cookie'), session()->getId());
    app()->forgetScopedInstances();
    CapellCore::flushLocalCache();
    $this->get('/install')->assertOk();
    Http::assertSentCount(1);
    expect($processes->commands())->toHaveCount(3);
});

it('defers uncached trusted package dry runs while honouring known availability failures', function (): void {
    FakeProcessFactory::bind()->byDefault(output: 'Command available');
    Http::fake(['*' => Http::response(['packages' => []])]);
    $this->get('/install')->assertOk();
    CapellCore::clearPackages();

    $directory = storage_path('framework/testing/installer-page-cost-' . uniqid());
    File::makeDirectory($directory, 0755, true);
    $arguments = $directory . '/arguments';
    $composer = $directory . '/composer';
    File::put($composer, "#!/bin/sh\nprintf '%s\\n' \"\$@\" >> '" . $arguments . "'\nexit 0\n");
    chmod($composer, 0755);
    config(['capell-installer.composer_binary' => $composer]);

    try {
        $packages = resolve(InstallerOptions::class)->downloadablePackages();
        expect(array_column($packages, 'name'))->toContain('capell-app/admin');
        expect(File::exists($arguments))->toBeFalse();

        Cache::put('capell.installer.package_installable.' . hash('sha256', 'capell-app/admin'), false);
        $packages = resolve(InstallerOptions::class)->downloadablePackages();
        expect(array_column($packages, 'name'))->not->toContain('capell-app/admin');
        expect(File::exists($arguments))->toBeFalse();
    } finally {
        File::deleteDirectory($directory);
    }
});
