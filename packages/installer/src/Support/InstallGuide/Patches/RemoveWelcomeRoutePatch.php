<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Core\Support\Patching\Patch;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Core\Support\Patching\RootRouteEditor;
use Override;
use RuntimeException;
use Throwable;

class RemoveWelcomeRoutePatch implements Patch
{
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

    #[Override]
    public function apply(): void
    {
        $routesWebPath = base_path(self::ROUTES_WEB_PATH);

        throw_unless(
            file_exists($routesWebPath),
            RuntimeException::class,
            'routes/web.php not found at: ' . $routesWebPath,
        );

        $status = $this->probe();
        if ($status !== PatchStatus::Applicable) {
            throw new RuntimeException(
                'Cannot apply patch when status is: ' . $status->value,
            );
        }

        try {
            $content = file_get_contents($routesWebPath);
            throw_unless($content !== false, RuntimeException::class, 'Could not read routes/web.php');

            $modifiedContent = new RootRouteEditor($content)->removeStockWelcomeRoutes();

            // Write the file back
            $written = file_put_contents($routesWebPath, $modifiedContent);
            throw_unless(
                $written !== false,
                RuntimeException::class,
                'Failed to write modified routes/web.php',
            );
        } catch (Throwable $throwable) {
            throw new RuntimeException(
                'Failed to apply RemoveWelcomeRoutePatch: ' . $throwable->getMessage(),
                (int) $throwable->getCode(),
                $throwable,
            );
        }
    }
}
