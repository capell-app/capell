<?php

declare(strict_types=1);

namespace Capell\Core\Testing\Assertions;

use AssertionError;
use Capell\Core\Testing\ExtensionTestHarness;
use Throwable;

final class AssertsExtensionManifest
{
    public static function run(string $manifestPath): void
    {
        try {
            ExtensionTestHarness::forPath($manifestPath)->assertManifestValid();
        } catch (Throwable $throwable) {
            throw new AssertionError("[manifest.valid] {$manifestPath}: {$throwable->getMessage()}", previous: $throwable);
        }
    }
}
