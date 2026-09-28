<?php

declare(strict_types=1);

use Capell\Core\Providers\CapellServiceProvider;
use Capell\Tests\AbstractTestCase;
use Capell\Tests\Fixtures\Providers\GeneratedTestbenchProvider;
use Capell\Tests\Support\IsolatedTestbenchSkeleton;
use Illuminate\Filesystem\Filesystem;

it('keeps generated host providers out of a copied package test application', function (): void {
    $directory = sys_get_temp_dir() . '/capell-provider-isolation-' . bin2hex(random_bytes(8));
    $preparedPath = new ReflectionProperty(IsolatedTestbenchSkeleton::class, 'preparedPath');
    $originalPath = $preparedPath->getValue();
    $bootstrapFile = new ReflectionProperty(AbstractTestCase::class, 'cacheApplicationBootstrapFile');
    $originalBootstrapFile = $bootstrapFile->getValue();
    $runtimeRole = getenv('CAPELL_TESTBENCH_RUNTIME_ROLE');
    $prepare = new ReflectionMethod(IsolatedTestbenchSkeleton::class, 'prepare');
    $prepare->invoke(null, app()->basePath(), $directory);

    $path = $directory . '/bootstrap/providers.php';
    $contents = '<?php return [\\' . GeneratedTestbenchProvider::class . '::class];';
    file_put_contents($path, $contents);

    try {
        // The coverage process boots through the runtime-role application, which
        // intentionally consumes generated host providers. This assertion covers
        // the ordinary package-test bootstrap, so select it explicitly instead of
        // inheriting the job-wide runtime role and its cached app.php path.
        putenv('CAPELL_TESTBENCH_RUNTIME_ROLE=false');
        $bootstrapFile->setValue(null, null);
        $preparedPath->setValue(null, $directory);
        $this->refreshApplication();

        expect(app()->getProvider(GeneratedTestbenchProvider::class))->toBeNull()
            ->and(app()->bound('generated-testbench-provider'))->toBeFalse()
            ->and(app()->getProvider(CapellServiceProvider::class))->toBeInstanceOf(CapellServiceProvider::class)
            ->and(file_get_contents($path))->toBe($contents);
    } finally {
        putenv($runtimeRole === false ? 'CAPELL_TESTBENCH_RUNTIME_ROLE' : 'CAPELL_TESTBENCH_RUNTIME_ROLE=' . $runtimeRole);
        $bootstrapFile->setValue(null, $originalBootstrapFile);
        $preparedPath->setValue(null, $originalPath);
        new Filesystem()->deleteDirectory($directory);
    }
});
