<?php

declare(strict_types=1);

use Capell\Core\Providers\CapellServiceProvider;
use Capell\Tests\AbstractTestCase;
use Capell\Tests\Fixtures\Providers\GeneratedTestbenchProvider;
use Capell\Tests\Support\IsolatedTestbenchSkeleton;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Env;

it('keeps generated host providers out of a copied package test application', function (): void {
    $directory = sys_get_temp_dir() . '/capell-provider-isolation-' . bin2hex(random_bytes(8));
    $preparedPath = new ReflectionProperty(IsolatedTestbenchSkeleton::class, 'preparedPath');
    $originalPath = $preparedPath->getValue();
    $bootstrapFile = new ReflectionProperty(AbstractTestCase::class, 'cacheApplicationBootstrapFile');
    $originalBootstrapFile = $bootstrapFile->getValue();
    $runtimeRole = getenv('CAPELL_TESTBENCH_RUNTIME_ROLE');
    $runtimeRoleCachePaths = [
        'APP_CONFIG_CACHE',
        'APP_PACKAGES_CACHE',
        'APP_SERVICES_CACHE',
        'APP_ROUTES_CACHE',
        'APP_EVENTS_CACHE',
    ];
    $originalCachePaths = array_combine(
        $runtimeRoleCachePaths,
        array_map(getenv(...), $runtimeRoleCachePaths),
    );
    $environment = &$GLOBALS['_ENV'];
    $server = &$GLOBALS['_SERVER'];
    $originalEnvironmentCachePaths = array_intersect_key($environment, array_flip($runtimeRoleCachePaths));
    $originalServerCachePaths = array_intersect_key($server, array_flip($runtimeRoleCachePaths));
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
        foreach ($runtimeRoleCachePaths as $key) {
            putenv($key);
            unset($environment[$key], $server[$key]);
            Env::getRepository()->clear($key);
        }

        // The workflow optimises the source skeleton before running tests, and
        // prepare() copies that runtime-role config cache into this application.
        // An ordinary bootstrap must load its own config files and provider merges.
        $ordinaryConfigCache = $directory . '/bootstrap/cache/ordinary-config.php';
        putenv('APP_CONFIG_CACHE=' . $ordinaryConfigCache);
        $environment['APP_CONFIG_CACHE'] = $ordinaryConfigCache;
        $server['APP_CONFIG_CACHE'] = $ordinaryConfigCache;
        Env::getRepository()->set('APP_CONFIG_CACHE', $ordinaryConfigCache);
        $bootstrapFile->setValue(null, null);
        $preparedPath->setValue(null, $directory);
        $this->refreshApplication();

        expect(app()->getProvider(GeneratedTestbenchProvider::class))->toBeNull()
            ->and(app()->bound('generated-testbench-provider'))->toBeFalse()
            ->and(app()->getProvider(CapellServiceProvider::class))->toBeInstanceOf(CapellServiceProvider::class)
            ->and(file_get_contents($path))->toBe($contents);
    } finally {
        putenv($runtimeRole === false ? 'CAPELL_TESTBENCH_RUNTIME_ROLE' : 'CAPELL_TESTBENCH_RUNTIME_ROLE=' . $runtimeRole);
        foreach ($originalCachePaths as $key => $value) {
            unset($environment[$key], $server[$key]);

            if (array_key_exists($key, $originalEnvironmentCachePaths)) {
                $environment[$key] = $originalEnvironmentCachePaths[$key];
            }

            if (array_key_exists($key, $originalServerCachePaths)) {
                $server[$key] = $originalServerCachePaths[$key];
            }

            if ($value === false) {
                putenv($key);
                Env::getRepository()->clear($key);

                continue;
            }

            putenv($key . '=' . $value);
            Env::getRepository()->set($key, $value);
        }

        $bootstrapFile->setValue(null, $originalBootstrapFile);
        $preparedPath->setValue(null, $originalPath);
        new Filesystem()->deleteDirectory($directory);
    }
});
