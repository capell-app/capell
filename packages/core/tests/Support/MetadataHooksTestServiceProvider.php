<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Override;
use Spatie\LaravelPackageTools\Package;

final class MetadataHooksTestServiceProvider extends AbstractPackageServiceProvider
{
    public static string $name = 'metadata-hooks-test';

    public static string $packageName = 'capell-app/metadata-hooks-test';

    #[Override]
    public function configurePackage(Package $package): void
    {
        $package->name(self::$name);
    }

    /** @return class-string */
    #[Override]
    protected function packageSettingClass(): string
    {
        return MetadataHooksTestSettings::class;
    }

    #[Override]
    protected function packageSetupCommand(): string
    {
        return 'capell:test-setup';
    }

    /** @return array<int, string> */
    #[Override]
    protected function packageSetupParameters(): array
    {
        return ['url', 'force'];
    }
}
