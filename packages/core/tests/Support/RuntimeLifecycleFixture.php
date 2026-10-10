<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Override;
use RuntimeException;
use Spatie\LaravelPackageTools\Package;

class RuntimeLifecycleFixture extends AbstractPackageServiceProvider
{
    public static string $name = 'lifecycle-fixture';

    public static string $packageName = 'test/lifecycle-fixture';

    /** @var list<string> */
    public array $registrations = [];

    public bool $fail = false;

    #[Override]
    public function configurePackage(Package $package): void
    {
        $package->name(static::$name);
    }

    #[Override]
    protected function registerPackageMetadata(): static
    {
        return $this;
    }

    // Models an author moving the legacy body to the new hook. Opting in
    // must prevent the old callback from executing the same body again.
    #[Override]
    protected function bootInstalledPackage(): self
    {
        $this->bootInstalledRuntime();

        return $this;
    }

    #[Override]
    protected function bootInstalledRuntime(): void
    {
        throw_if($this->fail, RuntimeException::class, 'fixture failure');

        $this->registrations[] = 'runtime';
    }
}
