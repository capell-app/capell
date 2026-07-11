<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Extensions;

use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Extensions\InstalledExtensionRepository;

final class ComposerDriftClassifier
{
    public const REASON_MISSING_REGISTRY_MANIFEST = 'missing_registry_manifest';

    public const REASON_COMPOSER_UNAVAILABLE = 'composer_unavailable';

    public const REASON_VERSION_MISMATCH = 'version_mismatch';

    public const REASON_DISABLED_OR_FAILED = 'disabled_or_failed';

    public function reason(CapellExtension $extension): ?string
    {
        if (! CapellCore::hasPackage($extension->composer_name)) {
            return self::REASON_MISSING_REGISTRY_MANIFEST;
        }

        $available = CapellCore::isPackageAvailable($extension->composer_name);

        if (! $available) {
            return self::REASON_COMPOSER_UNAVAILABLE;
        }

        if ($this->versionDiffers($extension)) {
            return self::REASON_VERSION_MISMATCH;
        }

        if (in_array($extension->status->value, ['disabled', 'failed'], true)) {
            return self::REASON_DISABLED_OR_FAILED;
        }

        return null;
    }

    public function isComposerActionable(string $reason): bool
    {
        return in_array($reason, [
            self::REASON_COMPOSER_UNAVAILABLE,
            self::REASON_VERSION_MISMATCH,
        ], true);
    }

    private function versionDiffers(CapellExtension $extension): bool
    {
        if (! is_string($extension->version) || $extension->version === '') {
            return false;
        }

        $repository = resolve(InstalledExtensionRepository::class);

        if (! method_exists($repository, 'version')) {
            return false;
        }

        $composerVersion = $repository->version($extension->composer_name);

        if (! is_string($composerVersion) || $composerVersion === '') {
            return false;
        }

        return ltrim($composerVersion, 'v') !== ltrim($extension->version, 'v');
    }
}
