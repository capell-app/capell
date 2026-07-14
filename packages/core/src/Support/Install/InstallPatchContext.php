<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

/**
 * The install-time selection facts Core exposes to install patch factories.
 * Contributing packages decide from this context whether their patch applies.
 */
final readonly class InstallPatchContext
{
    /**
     * @param  array<int, string>  $packageNames  Composer names of the packages selected for install (including extras).
     */
    public function __construct(
        public array $packageNames,
        public bool $hasFilamentAdminPanelProvider,
    ) {}

    public function hasPackage(string $packageName): bool
    {
        return in_array($packageName, $this->packageNames, true);
    }
}
