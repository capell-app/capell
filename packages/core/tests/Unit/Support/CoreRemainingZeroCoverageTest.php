<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\RequireExtraPackagesAction;
use Capell\Core\Concerns\HasDefaultPages;
use Capell\Core\Concerns\HasVendorAssets;
use Capell\Core\Console\Commands\Concerns\HasPackageInstall;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\PackageData;
use Capell\Core\Data\VendorAssetData;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Enums\VendorAssetEnum;
use Capell\Core\Exceptions\UrlSiteDomainNotFoundException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Request;
use Symfony\Component\Console\Tester\CommandTester;

it('loads registers and retrieves configured default pages', function (): void {
    config(['capell.default_pages' => ['home', 'contact']]);

    $registry = new class
    {
        use HasDefaultPages;
    };

    $callback = fn (): string => 'created';
    $homePage = expectPresent($registry->getDefaultPage('home'));
    $addedRegistry = $registry->addDefaultPage('landing', 'Landing page', $callback);
    $landingPage = expectPresent($registry->getDefaultPage('landing'));
    $landingCallback = expectPresent($landingPage->callback);

    expect($registry->getDefaultPages()->keys()->all())->toBe(['home', 'contact', 'landing'])
        ->and($homePage->label)->toBe('Home')
        ->and($addedRegistry)->toBe($registry)
        ->and($landingPage->label)->toBe('Landing page')
        ->and($landingCallback())->toBe('created');
});

it('throws a clear exception when a default page key is missing', function (): void {
    $registry = new class
    {
        use HasDefaultPages;
    };

    $registry->getDefaultPage('missing');
})->throws(InvalidArgumentException::class, 'Default page with key missing not found.');

it('groups registered vendor assets by type', function (): void {
    $registry = new class
    {
        use HasVendorAssets;
    };

    $tailwindSource = VendorAssetData::tailwindSource('./vendor/capell/example/**/*.blade.php');

    $registry
        ->registerVendorAsset($tailwindSource);

    expect($registry->hasVendorAssets(VendorAssetEnum::TailwindSource))->toBeTrue()
        ->and($registry->getVendorAssetsForType(VendorAssetEnum::TailwindSource)->all())->toBe([$tailwindSource])
        ->and($registry->getAllVendorAssets()->keys()->all())->toBe([VendorAssetEnum::TailwindSource->value]);
});

it('includes suggestions when a URL site domain cannot be located', function (): void {
    expect(new UrlSiteDomainNotFoundException('https://wrong.test', 'https://right.test')->getMessage())
        ->toBe('Unable to locate a site for the requested URL: https://wrong.test. Did you mean: https://right.test?')
        ->and(new UrlSiteDomainNotFoundException('https://wrong.test', 'https://wrong.test')->getMessage())
        ->toBe('Unable to locate a site for the requested URL: https://wrong.test.');
});

it('runs package install setup and after-install workflows through the shared command concern', function (): void {
    $package = new PackageData(
        name: 'capell-app/example',
        type: PackageTypeEnum::Package,
        installParams: ['url', 'languages', 'sites', 'user', 'assets'],
        setupParams: ['url'],
        afterInstallParams: ['url'],
    );

    $command = new class(collect([$package->name => $package])) extends Command
    {
        use HasPackageInstall;

        protected $signature = 'capell:test-package-install-concern';

        protected $description = 'Test package install concern.';

        /**
         * @param  Collection<string, PackageData>  $packages
         */
        public function __construct(private readonly Collection $packages)
        {
            parent::__construct();
        }

        public function handle(): int
        {
            $user = new class implements Authenticatable
            {
                public function getAuthIdentifierName(): string
                {
                    return 'id';
                }

                public function getAuthIdentifier(): int
                {
                    return 42;
                }

                public function getAuthPasswordName(): string
                {
                    return 'password';
                }

                public function getAuthPassword(): string
                {
                    return '';
                }

                public function getRememberToken(): string
                {
                    return '';
                }

                public function setRememberToken($value): void {}

                public function getRememberTokenName(): string
                {
                    return 'remember_token';
                }
            };

            $this->installPackages($this->packages, 'https://capell.test', ['en'], ['main'], $user, ['css' => 'app.css']);
            $this->setupPackages($this->packages, 'https://capell.test');
            $this->afterInstallPackages($this->packages, 'https://capell.test');

            return self::SUCCESS;
        }
    };
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain('Installing package: capell-app/example')
        ->and($tester->getDisplay())->toContain('Setting up package: capell-app/example')
        ->and($tester->getDisplay())->toContain('Post-install for package: capell-app/example');
});

it('requires extra packages through composer and reports process output', function (): void {
    $temporaryDirectory = sys_get_temp_dir() . '/capell-fake-composer-' . uniqid();
    File::makeDirectory($temporaryDirectory, 0755, true);
    $composerPath = $temporaryDirectory . '/composer';
    File::put($composerPath, "#!/bin/sh\necho required \"$@\"\n");
    chmod($composerPath, 0755);

    $originalServerPath = Request::server('PATH');
    $originalServerPath = is_string($originalServerPath) ? $originalServerPath : '';

    $fakeComposerPath = $temporaryDirectory . PATH_SEPARATOR . $originalServerPath;
    $_SERVER['PATH'] = $fakeComposerPath;
    putenv('PATH=' . $fakeComposerPath);

    $reporter = new class implements ProgressReporter
    {
        /** @var array<int, string> */
        public array $steps = [];

        /** @var array<int, string> */
        public array $reports = [];

        /** @var array<int, string> */
        public array $errors = [];

        public function step(string $label): void
        {
            $this->steps[] = $label;
        }

        public function report(string $line): void
        {
            $this->reports[] = $line;
        }

        public function error(string $line): void
        {
            $this->errors[] = $line;
        }
    };

    try {
        RequireExtraPackagesAction::run(['capell-app/example'], $reporter);
    } finally {
        $_SERVER['PATH'] = $originalServerPath;
        putenv('PATH=' . $originalServerPath);
        File::deleteDirectory($temporaryDirectory);
    }

    $reportedOutput = implode("\n", $reporter->reports);

    expect($reporter->steps)->toBe(['Requiring extra packages via Composer…'])
        ->and($reportedOutput)->toContain('required require --no-interaction --prefer-dist --with-all-dependencies')
        ->and($reportedOutput)->toContain('capell-app/example')
        ->and($reporter->reports)->toContain('✓ Required: capell-app/example')
        ->and($reporter->errors)->toBeEmpty();
});
