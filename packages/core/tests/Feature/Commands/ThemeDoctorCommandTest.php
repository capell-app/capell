<?php

declare(strict_types=1);

use Capell\Core\Actions\Diagnostics\BuildThemeDoctorReportAction;
use Capell\Core\Actions\ProjectBuild\CanonicalizeProjectBuildManifestSigningInputAction;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Data\Diagnostics\DoctorReportData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Enums\PackageRole;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Marketplace\TrustedMarketplaceIssuerKeys;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Capell\Tests\Support\OwnedApplicationPaths;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Command\Command;

beforeEach(function (): void {
    $this->workspace = new OwnedApplicationPaths(app());

    // A scaffolded theme builds on whichever package declares the default-theme role.
    registerRolePackage('vendor/starter-theme', PackageRole::DefaultTheme);
});

afterEach(function (): void {
    $this->workspace->restore();
});

it('passes for a scaffolded theme package', function (): void {
    $packagesDirectory = $this->workspace->root . '/scaffold';
    File::ensureDirectoryExists($packagesDirectory);

    artisanCommand('capell:make-theme', [
        'theme' => 'doctor-scaffold',
        '--package' => 'app/doctor-scaffold-theme',
        '--path' => $packagesDirectory,
        '--local' => true,
    ])->assertExitCode(Command::SUCCESS);

    artisanCommand('capell:theme:doctor', [
        'theme' => 'doctor-scaffold',
        '--path' => $packagesDirectory . '/doctor-scaffold-theme',
    ])
        ->expectsOutputToContain('All theme checks passed.')
        ->assertExitCode(Command::SUCCESS);
});

it('checks the licence and Capell registration before bootstrapping a theme provider', function (bool $pinned, bool $hasReceipt, bool $allowed, bool $paid = true): void {
    $name = 'app/licensed-theme';
    $themeDirectory = $this->workspace->root . '/licensed-theme';
    $provider = 'App\\LicensedTheme\\ThemeProvider';
    File::ensureDirectoryExists($themeDirectory . '/resources/views');
    File::ensureDirectoryExists($themeDirectory . '/src');
    File::put($themeDirectory . '/composer.json', json_encode([
        'name' => $name,
        'autoload' => ['psr-4' => ['App\\LicensedTheme\\' => 'src/']],
    ], JSON_THROW_ON_ERROR));
    File::put($themeDirectory . '/capell.json', json_encode(capellManifestV3Array(
        name: $name,
        surfaces: ['frontend', 'shared'],
        namespace: 'App\\LicensedTheme',
        providers: ['runtime' => [$provider]],
        overrides: ['kind' => 'theme', 'themeKey' => 'licensed', 'extends' => 'default'],
    ), JSON_THROW_ON_ERROR));
    File::put($themeDirectory . '/src/ThemeProvider.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\LicensedTheme;

        use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
        use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
        use Illuminate\Support\ServiceProvider;
        use Override;

        final class ThemeProvider extends ServiceProvider
        {
            #[Override]
            public function register(): void
            {
                resolve(ThemeRegistry::class)->register(new ThemeDefinitionData(
                    key: 'licensed',
                    name: 'Licensed',
                    description: 'Theme Doctor fixture.',
                    package: 'app/licensed-theme',
                    previewImage: '',
                    tags: [],
                    bestFit: [],
                    presets: [],
                ));
            }
        }
        PHP);

    $keys = sodium_crypto_sign_keypair();
    app()->instance(TrustedMarketplaceIssuerKeys::class, new TrustedMarketplaceIssuerKeys(
        keys: $pinned ? ['test-issuer' => base64_encode(sodium_crypto_sign_publickey($keys))] : [],
    ));
    config([
        'app.url' => 'https://theme-doctor.test',
        'capell.extensions.marketplace_instance_id' => 'ddda8b4c-3850-4d20-9cf4-460c9364c413',
    ]);

    $activation = null;
    if ($hasReceipt) {
        $receipt = [
            'receipt_version' => 2, 'purpose' => 'capell-installed-receipt', 'receipt_id' => 'licensed-theme-receipt',
            'composer_name' => $name, 'package_version' => '1.0.0', 'package_identity' => 'licensed-theme',
            'instance_id' => 'ddda8b4c-3850-4d20-9cf4-460c9364c413', 'domain' => 'theme-doctor.test',
            'issued_at' => now()->subDay()->toIso8601String(), 'runtime_valid_until' => now()->addDay()->toIso8601String(),
            'perpetual_installed_runtime' => true, 'runtime_revoked' => false,
            'signature' => ['algorithm' => 'ed25519', 'keyId' => 'test-issuer', 'value' => ''],
        ];
        $receipt['signature']['value'] = base64_encode(sodium_crypto_sign_detached(
            CanonicalizeProjectBuildManifestSigningInputAction::run($receipt),
            sodium_crypto_sign_secretkey($keys),
        ));
        $activation = ['installed_receipt_v2' => $receipt];
    }

    CapellExtension::query()->create([
        'composer_name' => $name, 'status' => ExtensionStatusEnum::Enabled, 'is_paid_marketplace_extension' => $paid,
        'marketplace_runtime_status' => 'active', 'marketplace_runtime_allowed' => true,
        'marketplace_signed_activation' => $activation,
    ]);

    expect(CapellCore::hasPackage($name))->toBeFalse()
        ->and(resolve(ThemeRegistry::class)->has('licensed'))->toBeFalse()
        ->and(app()->providerIsLoaded($provider))->toBeFalse();

    $exit = Artisan::call('capell:theme:doctor', ['theme' => 'licensed', '--path' => $themeDirectory]);

    expect($exit)->toBe($allowed ? Command::SUCCESS : Command::FAILURE)
        ->and(app()->providerIsLoaded($provider))->toBe($allowed)
        ->and(resolve(ThemeRegistry::class)->has('licensed'))->toBe($allowed);

    if (! $allowed) {
        expect(Artisan::output())->toContain('is not registered with Capell', 'Install the package through Capell');
    }
})->with([
    'pinned issuer without a receipt' => [true, false, false],
    'pinned issuer with a valid receipt' => [true, true, false],
    'empty issuer keys without a receipt' => [false, false, true],
    'pinned issuer with a free theme' => [true, false, true, false],
]);

it('fails when theme Blade uses root-relative asset urls', function (): void {
    $themeDirectory = sys_get_temp_dir() . '/capell-theme-doctor-fail-' . bin2hex(random_bytes(6));
    mkdir($themeDirectory . '/resources/views', 0755, true);

    file_put_contents($themeDirectory . '/composer.json', json_encode([
        'name' => 'app/broken-theme',
        'autoload' => ['psr-4' => ['App\\BrokenTheme\\' => 'src/']],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    file_put_contents($themeDirectory . '/capell.json', json_encode(capellManifestV3Array(
        name: 'app/broken-theme',
        surfaces: ['frontend', 'shared'],
        namespace: 'App\\BrokenTheme',
        providers: ['runtime' => []],
        overrides: [
            'kind' => 'theme',
            'themeKey' => 'broken',
            'extends' => 'default',
            'visibility' => 'support',
            'security' => [
                'riskTier' => 'low',
                'publicSurface' => ['auth' => 'public'],
                'sensitiveData' => [],
                'publicOutput' => [
                    'cacheSafe' => true,
                    'forbidAuthoringSurface' => true,
                    'forbidSecrets' => true,
                    'forbidPublicBladeQueries' => true,
                ],
                'externalHttpClients' => ['clients' => []],
                'adminSurface' => ['authorization' => 'none'],
            ],
        ],
    ), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    file_put_contents($themeDirectory . '/resources/views/page.blade.php', '<img src="/images/logo.png">');

    artisanCommand('capell:theme:doctor', [
        'theme' => 'broken',
        '--path' => $themeDirectory,
    ])
        ->expectsOutputToContain('Root-relative asset URLs found')
        ->assertExitCode(Command::FAILURE);
});

it('points at the command that lists theme warnings and exits zero', function (): void {
    $report = DoctorReportData::fromChecks(collect([
        new DoctorCheckResultData('Optional', false, 'Optional thing is missing.', 'Add it.', 'theme.optional', DoctorCheckSeverity::Warning),
    ]));
    app()->instance(BuildThemeDoctorReportAction::class, new readonly class($report)
    {
        public function __construct(private DoctorReportData $report) {}

        public function handle(string $theme, ?string $path = null): DoctorReportData
        {
            return $this->report;
        }
    });

    $exitCode = Artisan::call('capell:theme:doctor', ['theme' => 'demo']);
    $output = Artisan::output();
    Artisan::call('capell:theme:doctor', ['theme' => 'demo', '--path' => '/tmp/my theme']);
    $withPath = Artisan::output();
    $listed = Artisan::call('capell:theme:doctor', ['theme' => 'demo', '--warnings' => true]);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($listed)->toBe(Command::SUCCESS)
        ->and($output)->toContain('Theme checks passed with warnings')
        ->toContain("php artisan capell:theme:doctor 'demo' --warnings")
        ->and($withPath)->toContain("php artisan capell:theme:doctor 'demo' --path='/tmp/my theme' --warnings");
});
