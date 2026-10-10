<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Override;

final class ComposerDependentRuntimeFixture extends RuntimeLifecycleFixture
{
    public static string $packageName = 'test/loader-dependent';

    #[Override]
    protected function bootInstalledRuntime(): void
    {
        LoaderDependentRuntimeFixture::$order[] = 'dependent';
    }
}
