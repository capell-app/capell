<?php

declare(strict_types=1);

use Capell\Core\Providers\CapellServiceProvider;
use Capell\Tests\Fixtures\Providers\GeneratedTestbenchProvider;
use Capell\Tests\Support\IsolatedTestbenchSkeleton;
use Illuminate\Filesystem\Filesystem;

it('keeps generated host providers out of a copied package test application', function (): void {
    $directory = sys_get_temp_dir() . '/capell-provider-isolation-' . bin2hex(random_bytes(8));
    $preparedPath = new ReflectionProperty(IsolatedTestbenchSkeleton::class, 'preparedPath');
    $originalPath = $preparedPath->getValue();
    $prepare = new ReflectionMethod(IsolatedTestbenchSkeleton::class, 'prepare');
    $prepare->invoke(null, app()->basePath(), $directory);

    $path = $directory . '/bootstrap/providers.php';
    $contents = '<?php return [\\' . GeneratedTestbenchProvider::class . '::class];';
    file_put_contents($path, $contents);

    try {
        $preparedPath->setValue(null, $directory);
        $this->refreshApplication();

        expect(app()->getProvider(GeneratedTestbenchProvider::class))->toBeNull()
            ->and(app()->bound('generated-testbench-provider'))->toBeFalse()
            ->and(app()->getProvider(CapellServiceProvider::class))->toBeInstanceOf(CapellServiceProvider::class)
            ->and(file_get_contents($path))->toBe($contents);
    } finally {
        $preparedPath->setValue(null, $originalPath);
        new Filesystem()->deleteDirectory($directory);
    }
});
