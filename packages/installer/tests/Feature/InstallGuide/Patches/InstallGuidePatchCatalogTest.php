<?php

declare(strict_types=1);

use Capell\Core\Support\Patching\Patch;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Installer\Support\InstallGuide\Patches\AdminPanelColorsPatch;
use Capell\Installer\Support\InstallGuide\Patches\AdminPanelDashboardPatch;
use Capell\Installer\Support\InstallGuide\Patches\AdminPanelNavigationPatch;
use Capell\Installer\Support\InstallGuide\Patches\AdminPanelPluginPatch;
use Capell\Installer\Support\InstallGuide\Patches\AdminPanelThemePatch;
use Capell\Installer\Support\InstallGuide\Patches\AdminPanelWidgetsPatch;
use Capell\Installer\Support\InstallGuide\Patches\DocOnlyMediaLibraryPatch;
use Capell\Installer\Support\InstallGuide\Patches\DocOnlyQueueWorkerPatch;
use Capell\Installer\Support\InstallGuide\Patches\DocOnlyWebServerPatch;
use Capell\Installer\Support\InstallGuide\Patches\EnvQueueConnectionPatch;
use Capell\Installer\Support\InstallGuide\Patches\EnvSettingsCachePatch;
use Capell\Installer\Support\InstallGuide\Patches\FilesystemsPageCacheDiskPatch;
use Capell\Installer\Support\InstallGuide\Patches\LoggingCapellChannelPatch;
use Capell\Installer\Support\InstallGuide\Patches\RemoveWelcomeRoutePatch;
use Capell\Installer\Support\InstallGuide\Patches\ThemeSourcesPatch;
use Capell\Installer\Support\InstallGuide\Patches\UserModelPatch;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->originalBasePath = $this->app->basePath();
    $this->temporaryBasePath = sys_get_temp_dir() . '/capell-install-guide-patch-catalog-' . uniqid();

    File::makeDirectory($this->temporaryBasePath, 0755, true);
    $this->app->setBasePath($this->temporaryBasePath);
});

afterEach(function (): void {
    $this->app->setBasePath($this->originalBasePath);

    if (is_dir($this->temporaryBasePath)) {
        File::deleteDirectory($this->temporaryBasePath);
    }
});

it('exposes complete install guide patch metadata and safe missing-file probe states', function (): void {
    $patches = installGuideCatalogPatchInstances();

    expect($patches)->toHaveCount(16);

    foreach ($patches as $patch) {
        $docUrl = $patch->docUrl();
        $reason = $patch->reason();

        expect($patch->id())->not->toBe('')
            ->and($patch->group())->not->toBe('')
            ->and($patch->label())->not->toBe('')
            ->and($patch->description())->not->toBe('')
            ->and($patch->defaultEnabled())->toBeBool()
            ->and($docUrl === null || $docUrl !== '')->toBeTrue()
            ->and($reason === null || $reason !== '')->toBeTrue()
            ->and($patch->probe())->toBeInstanceOf(PatchStatus::class);
    }
});

it('keeps documentation-only install guide patches manual and non-applicable', function (): void {
    $manualPatches = [
        new DocOnlyMediaLibraryPatch,
        new DocOnlyQueueWorkerPatch,
        new DocOnlyWebServerPatch,
    ];

    foreach ($manualPatches as $patch) {
        expect($patch->defaultEnabled())->toBeFalse()
            ->and($patch->probe())->toBe(PatchStatus::Unsupported)
            ->and($patch->reason())->not->toBeNull()
            ->and($patch->docUrl())->not->toBeNull();

        expect(function () use ($patch): void {
            $patch->apply();
        })
            ->toThrow(RuntimeException::class);
    }
});

/**
 * @return list<Patch>
 */
function installGuideCatalogPatchInstances(): array
{
    return [
        new UserModelPatch,
        new AdminPanelColorsPatch,
        new AdminPanelDashboardPatch,
        new AdminPanelNavigationPatch,
        new AdminPanelPluginPatch,
        new AdminPanelThemePatch,
        new AdminPanelWidgetsPatch,
        new ThemeSourcesPatch,
        new RemoveWelcomeRoutePatch,
        new EnvQueueConnectionPatch,
        new EnvSettingsCachePatch,
        new FilesystemsPageCacheDiskPatch,
        new LoggingCapellChannelPatch,
        new DocOnlyQueueWorkerPatch,
        new DocOnlyWebServerPatch,
        new DocOnlyMediaLibraryPatch,
    ];
}
