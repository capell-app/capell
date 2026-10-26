<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Core\Support\Patching\FilePatchPlan;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Core\Support\Patching\ReviewableFilePatch;
use Capell\Core\Support\Patching\RootRouteEditor;
use Capell\Installer\Support\InstallGuide\PlansFilePatch;
use Override;
use RuntimeException;
use Throwable;

class RemoveWelcomeRoutePatch implements ReviewableFilePatch
{
    use PlansFilePatch;

    private const string ROUTES_WEB_PATH = 'routes/web.php';

    #[Override]
    public function id(): string
    {
        return 'remove-welcome-route-patch';
    }

    #[Override]
    public function group(): string
    {
        return 'routes';
    }

    #[Override]
    public function label(): string
    {
        return __('capell-installer::install-guide.remove_welcome_route_patch_label');
    }

    #[Override]
    public function description(): string
    {
        return __('capell-installer::install-guide.remove_welcome_route_patch_description');
    }

    #[Override]
    public function docUrl(): ?string
    {
        return null;
    }

    #[Override]
    public function defaultEnabled(): bool
    {
        return true;
    }

    #[Override]
    public function probe(): PatchStatus
    {
        $routesWebPath = base_path(self::ROUTES_WEB_PATH);

        if (! file_exists($routesWebPath)) {
            return PatchStatus::Unsupported;
        }

        try {
            $content = file_get_contents($routesWebPath);
            if ($content === false) {
                return PatchStatus::Unsupported;
            }

            $routes = new RootRouteEditor($content);
            if ($routes->hasStockWelcomeRoute()) {
                return PatchStatus::Applicable;
            }

            if ($routes->hasRootRoute()) {
                return PatchStatus::Customised;
            }

            // Stock block is absent and no other root route exists
            return PatchStatus::AlreadyApplied;
        } catch (RuntimeException|Throwable) {
            return PatchStatus::Unsupported;
        }
    }

    #[Override]
    public function reason(): ?string
    {
        return null;
    }

    protected function plan(): FilePatchPlan
    {
        $path = base_path(self::ROUTES_WEB_PATH);
        $content = file_get_contents($path);
        throw_unless(is_string($content), RuntimeException::class, 'Could not read routes/web.php');

        return new FilePatchPlan($path, self::ROUTES_WEB_PATH, $content, new RootRouteEditor($content)->removeStockWelcomeRoutes());
    }
}
