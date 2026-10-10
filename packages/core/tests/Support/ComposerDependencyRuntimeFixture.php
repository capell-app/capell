<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Override;

final class ComposerDependencyRuntimeFixture extends RuntimeLifecycleFixture
{
    public static string $packageName = 'test/loader-dependency';

    #[Override]
    protected function bootInstalledRuntime(): void
    {
        LoaderDependentRuntimeFixture::$order[] = 'dependency';
    }
}
