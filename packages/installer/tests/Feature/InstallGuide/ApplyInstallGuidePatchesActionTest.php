<?php

declare(strict_types=1);

use Capell\Admin\Filament\Widgets\Dashboard\ListPagesFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\MyWorkQueueFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\RecentlyPublishedFilamentWidget;
use Capell\Core\Support\Patching\Patch;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Installer\Actions\InstallGuide\ApplyInstallGuidePatchesAction;
use Capell\Installer\Data\InstallGuide\ApplyPatchesInputData;
use Capell\Installer\Support\InstallGuide\PatchRegistry;
use Capell\Tests\Support\GeneratedPanelProvider;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->originalBasePath = $this->app->basePath();
    $this->temporaryBasePath = sys_get_temp_dir() . '/capell-install-guide-action-' . uniqid();

    File::makeDirectory($this->temporaryBasePath, 0755, true);
    $this->app->setBasePath($this->temporaryBasePath);
});

afterEach(function (): void {
    $this->app->setBasePath($this->originalBasePath);

    if (is_dir($this->temporaryBasePath)) {
        File::deleteDirectory($this->temporaryBasePath);
    }
});

it('applies registry patches against an app skeleton and reports the outcomes', function (): void {
    writeInstallGuideFixture('.env', "APP_NAME=Capell\nQUEUE_CONNECTION=sync\n");
    writeInstallGuideFixture('config/filesystems.php', installGuideFilesystemsConfig());
    writeInstallGuideFixture('config/logging.php', installGuideLoggingConfig());
    writeInstallGuideFixture('app/Providers/Filament/AdminPanelProvider.php', installGuideAdminPanelProvider());

    $registry = resolve(PatchRegistry::class);

    expect($registry->get('env-queue-connection-patch'))->not->toBeNull()
        ->and($registry->get('filesystems-page-cache-disk-patch'))->not->toBeNull()
        ->and($registry->get('logging-capell-channel-patch'))->not->toBeNull()
        ->and($registry->get('admin-panel-widgets-patch'))->not->toBeNull();

    $result = ApplyInstallGuidePatchesAction::run(new ApplyPatchesInputData([
        'env-queue-connection-patch',
        'filesystems-page-cache-disk-patch',
        'logging-capell-channel-patch',
        'admin-panel-widgets-patch',
        'missing-patch-id',
    ]));

    expect($result->results)->toHaveCount(4)
        ->and($result->succeeded())->toHaveCount(4)
        ->and($result->failed())->toBeEmpty()
        ->and($result->skipped())->toBeEmpty();

    $result->results->each(function ($patchResult): void {
        expect($patchResult->statusBefore)->toBe(PatchStatus::Applicable)
            ->and($patchResult->statusAfter)->toBe(PatchStatus::AlreadyApplied)
            ->and($patchResult->errorMessage)->toBeNull();
    });

    expect(Dotenv::parse(File::get(base_path('.env')))['QUEUE_CONNECTION'])->toBe('database');
    $filesystems = require base_path('config/filesystems.php');
    $logging = require base_path('config/logging.php');

    expect($filesystems['disks']['page_cache'])->toBe([
        'driver' => 'local', 'root' => public_path('page-cache'), 'throw' => false,
    ])->and($logging['channels']['capell'])->toBe([
        'driver' => 'single', 'path' => storage_path('logs/capell.log'), 'level' => 'debug',
    ])->and(GeneratedPanelProvider::load(base_path('app/Providers/Filament/AdminPanelProvider.php'))->getWidgets())
        ->toEqualCanonicalizing([
            ListPagesFilamentWidget::class,
            MyWorkQueueFilamentWidget::class,
            RecentlyPublishedFilamentWidget::class,
        ]);
});

it('reports patch write failures as manual changes without throwing', function (): void {
    $registry = resolve(PatchRegistry::class);
    $registry->register(new class implements Patch
    {
        public function id(): string
        {
            return 'permission-failing-patch';
        }

        public function group(): string
        {
            return 'config';
        }

        public function label(): string
        {
            return 'Permission failing patch';
        }

        public function description(): string
        {
            return 'Fails like a protected config file.';
        }

        public function docUrl(): ?string
        {
            return null;
        }

        public function defaultEnabled(): bool
        {
            return true;
        }

        public function probe(): PatchStatus
        {
            return PatchStatus::Applicable;
        }

        public function reason(): ?string
        {
            return null;
        }

        public function apply(): void
        {
            throw new RuntimeException('Permission denied writing config/filesystems.php.');
        }
    });

    $result = ApplyInstallGuidePatchesAction::run(new ApplyPatchesInputData([
        'permission-failing-patch',
    ]));

    expect($result->results)->toHaveCount(1)
        ->and($result->failed())->toHaveCount(1)
        ->and($result->failed()->first()->errorMessage)
        ->toContain('Permission denied writing config/filesystems.php.')
        ->toContain('https://docs.capell.app/getting-started/install/#install-time-write-permissions');
});

function writeInstallGuideFixture(string $relativePath, string $contents): void
{
    $path = base_path($relativePath);

    File::ensureDirectoryExists(dirname($path));
    File::put($path, $contents);
}

function installGuideFilesystemsConfig(): string
{
    return <<<'PHP'
<?php

return [
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
        ],
    ],
];
PHP;
}

function installGuideLoggingConfig(): string
{
    return <<<'PHP'
<?php

return [
    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => ['single'],
        ],
        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => 'debug',
        ],
    ],
];
PHP;
}

function installGuideAdminPanelProvider(): string
{
    return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    #[\Override]
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login();
    }
}
PHP;
}
