<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Closure;
use Composer\InstalledVersions;
use Livewire\Features\SupportCssModules\SupportCssModules;
use Livewire\Features\SupportJsModules\SupportJsModules;
use Livewire\Features\SupportTesting\InitialRender;
use ReflectionClass;
use ReflectionFunction;
use Throwable;

/** Verifies vendor closure identity without restoring or invoking cached actions. */
final class LivewireRouteAction
{
    private const array SCOPES = [SupportCssModules::class, SupportJsModules::class, InitialRender::class];

    /** Old route caches are unsupported: deployments rebuild them, and unverifiable actions fail closed. */
    public static function closureClass(mixed $action): ?string
    {
        if (! $action instanceof Closure) {
            // Serialized actions cannot be verified here, so they remain unclassified.
            return null;
        }

        try {
            $reflection = new ReflectionFunction($action);
            $scope = $reflection->getClosureScopeClass()?->getName();

            return $scope !== null && self::vendorFile($scope) === $reflection->getFileName() ? $scope : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function vendorFile(string $scope): ?string
    {
        if (! in_array($scope, self::SCOPES, true)) {
            return null;
        }

        $root = InstalledVersions::getInstallPath('livewire/livewire');
        $file = new ReflectionClass($scope)->getFileName();
        $root = $root === null ? false : realpath($root);
        $file = $file === false ? false : realpath($file);

        return $root !== false && $file !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) ? $file : null;
    }
}
