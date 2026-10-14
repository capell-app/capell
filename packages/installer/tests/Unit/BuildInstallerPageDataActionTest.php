<?php

declare(strict_types=1);

use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Plugins\PluginPackagesFetcher;
use Capell\Installer\Actions\BuildInstallerPageDataAction;
use Capell\Installer\Support\InstallerSessionRepository;
use Capell\Tests\Support\Fakes\FakeProcessFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

it('clears stale installer locks before rendering web installer data', function (): void {
    $run = resolve(InstallerSessionRepository::class)->run('stale-install');
    $run->startSynchronous();
    $run->markComplete();

    $data = BuildInstallerPageDataAction::run(capellAlreadyInstalled: false, canReinstall: true)->toViewData();

    expect($data['installId'])->toBeNull()
        ->and($data['installStatus'])->toBe('idle')
        ->and($data['cancelUrl'])->toBeNull()
        ->and(Cache::has(InstallerSessionRepository::LOCK_KEY))->toBeFalse();
});

it('does not offer registered packages as downloadable packages', function (): void {
    $data = BuildInstallerPageDataAction::run(capellAlreadyInstalled: false, canReinstall: false)->toViewData();

    $registeredPackageNames = collect([
        ...$data['corePackages'],
        ...$data['installedPackages'],
    ])->pluck('name');
    $downloadablePackageNames = collect([
        ...$data['downloadablePackages'],
    ])->pluck('name');

    expect($downloadablePackageNames->intersect($registeredPackageNames))->toBeEmpty();
});

it('does not dry-run uncached remote package choices when setup cache is unavailable', function (): void {
    $temporaryDirectory = storage_path('framework/testing/installer-page-data-' . uniqid());
    File::makeDirectory($temporaryDirectory, 0755, true);

    $argumentsPath = $temporaryDirectory . '/composer-arguments.txt';
    $fakeComposerPath = $temporaryDirectory . '/composer';
    File::put($fakeComposerPath, str_replace('__ARGUMENTS_PATH__', $argumentsPath, <<<'SH'
#!/bin/sh
printf '%s\n' "$@" >> '__ARGUMENTS_PATH__'
printf '%s\n' '---' >> '__ARGUMENTS_PATH__'
for argument in "$@"; do
    if [ "$argument" = "capell-app/admin" ]; then
        exit 0
    fi
done

exit 1
SH));
    chmod($fakeComposerPath, 0755);

    $originalPath = getenv('PATH');
    putenv('PATH=' . $temporaryDirectory . PATH_SEPARATOR . ($originalPath === false ? '' : $originalPath));

    app()->bind(PluginPackagesFetcher::class, fn (): PluginPackagesFetcher => new class extends PluginPackagesFetcher
    {
        public function fetch(bool $force = false): Collection
        {
            return $this->remotePackages();
        }

        public function getCached(): Collection
        {
            return $this->remotePackages();
        }

        private function remotePackages(): Collection
        {
            return Collection::make([
                [
                    'name' => 'capell-app/remote-tool',
                    'type' => 'plugin',
                    'description' => 'Remote installable tool.',
                    'requirements' => ['capell-app/frontend'],
                    'defaultSelected' => 'true',
                    'visibility' => 'catalogue',
                ],
                [
                    'name' => 'capell-app/remote-theme',
                    'type' => 'theme',
                    'themeKey' => 'remote-theme',
                    'description' => 'Theme choices belong in the theme selector.',
                    'visibility' => 'catalogue',
                ],
            ]);
        }
    });

    config([
        'cache.default' => 'database',
        'cache.stores.database.table' => 'missing_installer_page_data_cache',
        'capell-installer.composer_binary' => $fakeComposerPath,
    ]);

    try {
        $data = BuildInstallerPageDataAction::run(capellAlreadyInstalled: false, canReinstall: false)->toViewData();

        /** @var list<array{name: string}> $downloadablePackages */
        $downloadablePackages = $data['downloadablePackages'];
        $downloadablePackageNames = collect($downloadablePackages)->pluck('name')->all();
        $composerArguments = File::exists($argumentsPath) ? File::get($argumentsPath) : '';

        expect($downloadablePackageNames)
            ->not->toContain('capell-app/remote-tool')
            ->and($data['capellAlreadyInstalled'])->toBeFalse()
            ->and($data['canReinstall'])->toBeFalse()
            ->and($composerArguments)->not->toContain("capell-app/remote-tool:*\n");
    } finally {
        putenv('PATH=' . ($originalPath === false ? '' : $originalPath));
        File::deleteDirectory($temporaryDirectory);
        config([
            'cache.default' => 'array',
            'capell-installer.composer_binary' => 'composer',
        ]);
    }
});

it('uses cached Composer availability for remote package choices', function (): void {
    $temporaryDirectory = storage_path('framework/testing/installer-page-data-cached-' . uniqid());
    File::makeDirectory($temporaryDirectory, 0755, true);

    $fakeComposerPath = $temporaryDirectory . '/composer';
    File::put($fakeComposerPath, <<<'SH'
#!/bin/sh
exit 1
SH);
    chmod($fakeComposerPath, 0755);

    app()->bind(PluginPackagesFetcher::class, fn (): PluginPackagesFetcher => new class extends PluginPackagesFetcher
    {
        public function fetch(bool $force = false): Collection
        {
            return $this->remotePackages();
        }

        public function getCached(): Collection
        {
            return $this->remotePackages();
        }

        private function remotePackages(): Collection
        {
            return Collection::make([
                [
                    'name' => 'capell-app/remote-tool',
                    'type' => 'plugin',
                    'description' => 'Remote installable tool.',
                    'requirements' => ['capell-app/frontend'],
                    'defaultSelected' => 'true',
                    'visibility' => 'catalogue',
                ],
            ]);
        }
    });

    Cache::put('capell.installer.package_installable.' . hash('sha256', 'capell-app/remote-tool'), true);
    config(['capell-installer.composer_binary' => $fakeComposerPath]);

    try {
        $data = BuildInstallerPageDataAction::run(capellAlreadyInstalled: false, canReinstall: false)->toViewData();

        expect($data['downloadablePackages'])
            ->toHaveCount(1)
            ->and($data['downloadablePackages'][0])
            ->toMatchArray([
                'name' => 'capell-app/remote-tool',
                'defaultSelected' => true,
                'requirements' => ['capell-app/frontend'],
            ])
            ->and($data['requirementsMap']['capell-app/remote-tool'])->toBe(['capell-app/frontend']);
    } finally {
        File::deleteDirectory($temporaryDirectory);
        config(['capell-installer.composer_binary' => 'composer']);
    }
});

it('offers composer-available trusted core packages before they are installed', function (): void {
    Cache::put('capell.installer.package_installable.' . hash('sha256', 'capell-app/admin'), true);
    Cache::put('capell.installer.package_installable.' . hash('sha256', 'capell-app/frontend'), false);
    Cache::put('capell.installer.package_installable.' . hash('sha256', 'capell-app/marketplace'), false);
    CapellCore::clearPackages();
    CapellCore::registerPackage('capell-app/installer', type: PackageTypeEnum::Package);

    app()->bind(PluginPackagesFetcher::class, fn (): PluginPackagesFetcher => new class extends PluginPackagesFetcher
    {
        public function fetch(bool $force = false): Collection
        {
            return Collection::make();
        }

        public function getCached(): Collection
        {
            return Collection::make();
        }
    });

    $temporaryDirectory = storage_path('framework/testing/installer-page-data-trusted-' . uniqid());
    File::makeDirectory($temporaryDirectory, 0755, true);

    $fakeComposerPath = $temporaryDirectory . '/composer';
    File::put($fakeComposerPath, <<<'SH'
#!/bin/sh
for argument in "$@"; do
    if [ "$argument" = "capell-app/admin" ]; then
        exit 0
    fi
done

exit 1
SH);
    chmod($fakeComposerPath, 0755);

    config(['capell-installer.composer_binary' => $fakeComposerPath]);

    try {
        $data = BuildInstallerPageDataAction::run(capellAlreadyInstalled: false, canReinstall: false)->toViewData();

        /** @var list<array{name: string}> $downloadablePackages */
        $downloadablePackages = $data['downloadablePackages'];

        expect(collect($downloadablePackages)->pluck('name')->all())
            ->toBe(['capell-app/admin'])
            ->and($data['installableExtraPackageNames'])->toBe(['capell-app/admin']);
    } finally {
        File::deleteDirectory($temporaryDirectory);
        config(['capell-installer.composer_binary' => 'composer']);
    }
});

it('hides role-user creation once the starter roles already exist', function (): void {
    Role::findOrCreate('super_admin');
    Role::findOrCreate('editor');

    $data = BuildInstallerPageDataAction::run(capellAlreadyInstalled: false, canReinstall: false)->toViewData();

    expect($data['showRoleUsersToggle'])->toBeFalse();
});

it('keeps malformed installer defaults out of package and admin user state', function (): void {
    config([
        'capell-installer.default_packages' => 'capell-app/admin',
        'capell-installer.admin_user' => [
            'name' => '  Default Admin  ',
            'email' => 123,
            'password' => null,
        ],
    ]);

    CapellCore::registerPackage(
        'capell-app/non-core-theme',
        type: PackageTypeEnum::Theme,
    );
    CapellCore::getPackage('capell-app/non-core-theme')->themeKey = 'non-core-theme';

    $data = BuildInstallerPageDataAction::run(capellAlreadyInstalled: true, canReinstall: true)->toViewData();

    expect($data['defaultPackageNames'])->toContain('capell-app/admin')
        ->and($data['defaultAdminUser'])->toBe([
            'name' => 'Default Admin',
            'email' => '',
            'password' => '',
        ])
        ->and(collect((array) $data['installedPackages'])->pluck('name'))->not->toContain('capell-app/non-core-theme');
});

it('fails closed when optional installer page data dependencies are unavailable', function (): void {
    foreach (['capell-app/admin', 'capell-app/frontend', 'capell-app/marketplace'] as $package) {
        Cache::put('capell.installer.package_installable.' . hash('sha256', $package), false);
    }

    CapellCore::clearPackages();

    config([
        'auth.providers.users.model' => 'App\\Models\\MissingInstallerUser',
        'capell-installer.default_packages' => [
            '',
            'capell-app/missing',
            'capell-app/missing',
        ],
    ]);

    $data = BuildInstallerPageDataAction::run(capellAlreadyInstalled: false, canReinstall: false)->toViewData();

    expect($data['packages'])->toBe([])
        ->and($data['corePackages'])->toBe([])
        ->and($data['installedPackages'])->toBe([])
        ->and($data['existingUsers'])->toBe([])
        ->and($data['defaultPackageNames'])->toBe([])
        ->and($data['showFilamentPanelToggle'])->toBeFalse()
        ->and($data['showRoleUsersToggle'])->toBeTrue();
});

it('reuses one bounded catalogue result throughout each uncached installer page', function (string $response): void {
    FakeProcessFactory::bind()->byDefault(output: 'Available');
    config(['capell.disable_cache' => true, 'capell.plugins_source_url' => 'https://catalogue.capell.test/extensions']);
    $timeouts = [];
    Http::fake(function ($request, array $options) use (&$timeouts, $response) {
        $timeouts[] = $options['timeout'];

        return match ($response) {
            'offline' => Http::response(null, 503),
            'connection' => Http::failedConnection(),
            'empty' => Http::response(['packages' => []]),
            default => Http::response(['packages' => [[
                'name' => 'vendor/catalogue-theme', 'type' => 'theme', 'themeKey' => 'catalogue-theme',
            ]]]),
        };
    });

    $page = $this->get('/install')->assertOk();
    expect($timeouts)->not->toBeEmpty()
        ->and(max($timeouts))->toBeLessThanOrEqual(2);
    Http::assertSentCount(1);
    if ($response === 'populated') {
        $page->assertSee('catalogue-theme');
    }

    $this->get('/install')->assertOk();
    Http::assertSentCount(2);
    expect(max($timeouts))->toBeLessThanOrEqual(2);
})->with(['empty', 'offline', 'connection', 'populated']);
