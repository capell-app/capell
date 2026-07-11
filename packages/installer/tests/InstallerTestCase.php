<?php

declare(strict_types=1);

namespace Capell\Installer\Tests;

use Capell\Installer\Providers\InstallerServiceProvider;
use Capell\Tests\PackagesTestCase;
use Override;

class InstallerTestCase extends PackagesTestCase
{
    #[Override]
    protected function getPackageServiceName(): string
    {
        return 'capell-installer';
    }

    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [
            ...parent::getPackageProviders($app),
            InstallerServiceProvider::class,
        ];
    }
}
