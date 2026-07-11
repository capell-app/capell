<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Installer\Support\InstallGuide\Patch;
use Capell\Installer\Support\InstallGuide\PatchStatus;
use RuntimeException;
use Throwable;

class RemoveWelcomeRoutePatch implements Patch
{
    private const string ROUTES_WEB_PATH = 'routes/web.php';

    public function id(): string
    {
        return 'remove-welcome-route-patch';
    }

    public function group(): string
    {
        return 'routes';
    }

    public function label(): string
    {
        return __('capell-installer::install-guide.remove_welcome_route_patch_label');
    }

    public function description(): string
    {
        return __('capell-installer::install-guide.remove_welcome_route_patch_description');
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
        $routesWebPath = base_path(self::ROUTES_WEB_PATH);

        if (! file_exists($routesWebPath)) {
            return PatchStatus::Unsupported;
        }

        try {
            $content = file_get_contents($routesWebPath);
            if ($content === false) {
                return PatchStatus::Unsupported;
            }

            // Check if the stock welcome block is present
            if ($this->hasStockWelcomeBlock($content)) {
                return PatchStatus::Applicable;
            }

            // Check if there's any other route bound to '/'
            if ($this->hasRootRoute($content)) {
                return PatchStatus::Customised;
            }

            // Stock block is absent and no other root route exists
            return PatchStatus::AlreadyApplied;
        } catch (RuntimeException|Throwable) {
            return PatchStatus::Unsupported;
        }
    }

    public function reason(): ?string
    {
        return null;
    }

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

            $modifiedContent = preg_replace($this->stockWelcomeRoutePatterns(), '', $content);

            throw_unless(
                $modifiedContent !== null,
                RuntimeException::class,
                'Regex replacement failed for welcome route',
            );

            // Collapse multiple consecutive newlines to maximum of 2
            $modifiedContent = preg_replace('/\n\n\n+/', "\n\n", $modifiedContent);

            throw_unless(
                $modifiedContent !== null,
                RuntimeException::class,
                'Failed to collapse newlines',
            );

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

    /**
     * Check if the stock welcome route block is present in the file.
     */
    private function hasStockWelcomeBlock(string $content): bool
    {
        return array_any($this->stockWelcomeRoutePatterns(), fn ($pattern): bool => preg_match($pattern, $content) === 1);
    }

    /**
     * Check if there's any route (other than the stock welcome) bound to '/'.
     * Matches Route::get('/', ...), Route::post('/', ...), etc with flexible whitespace.
     */
    private function hasRootRoute(string $content): bool
    {
        $pattern = '/Route::\w+\s*\(\s*[\'"][\/]["\']\s*[,\]]/';

        return preg_match($pattern, $content) === 1;
    }

    /**
     * @return array<int, string>
     */
    private function stockWelcomeRoutePatterns(): array
    {
        return [
            '/Route::get\s*\(\s*[\'"][\/]["\']\s*,\s*function\s*\(\s*\)\s*\{[^}]*return\s+view\s*\(\s*[\'"]welcome["\']\s*\)\s*;[^}]*\}\s*\)\s*;/',
            '/Route::view\s*\(\s*[\'"][\/]["\']\s*,\s*[\'"]welcome[\'"]\s*\)\s*;/',
        ];
    }
}
