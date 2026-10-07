<?php

declare(strict_types=1);

use Capell\Core\Support\Activity\ActivityLogCompat;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

it('loads the exact vendor aliases with a generated Composer classmap', function (string $manifestPath, string $optimisation): void {
    $root = dirname(__DIR__, 6);
    $temporary = sys_get_temp_dir() . '/capell-activity-autoload-' . bin2hex(random_bytes(8));

    try {
        $manifest = json_decode(File::get($root . '/' . $manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $manifestDirectory = dirname($root . '/' . $manifestPath);
        $coreSource = rtrim($manifestDirectory . '/' . $manifest['autoload']['psr-4']['Capell\\Core\\'], '/');
        File::copyDirectory($coreSource, $temporary . '/src');
        $source = getenv('CAPELL_ACTIVITYLOG_SOURCE') ?: $root . '/vendor/spatie/laravel-activitylog';
        $files = array_map(
            static function (string $path) use ($coreSource, $manifestDirectory): string {
                $sourcePath = $manifestDirectory . '/' . ltrim($path, '/');
                $relativePath = str_starts_with($sourcePath, $coreSource . '/')
                    ? substr($sourcePath, strlen($coreSource) + 1)
                    : throw new RuntimeException('Composer autoload file is outside the Core PSR-4 source: ' . $path);

                throw_unless(File::exists($sourcePath), RuntimeException::class, 'Composer autoload file does not exist: ' . $sourcePath);

                return 'src/' . $relativePath;
            },
            $manifest['autoload']['files'] ?? [],
        );
        File::put($temporary . '/composer.json', json_encode([
            'name' => 'capell-test/activity-autoload',
            'autoload' => [
                'psr-4' => ['Capell\\Core\\' => 'src/', 'Spatie\\Activitylog\\' => $source . '/src/'],
                'files' => [$root . '/vendor/laravel/framework/src/Illuminate/Support/helpers.php', ...$files],
            ],
        ], JSON_THROW_ON_ERROR));
        // A caller may select its own manifest or vendor directory through the
        // environment; the temporary project must use the one written here.
        $dump = new Process(['composer', 'dump-autoload', $optimisation, '--no-plugins', '--no-scripts'], $temporary, [
            'COMPOSER_DISABLE_NETWORK' => '1',
            'COMPOSER' => false,
            'COMPOSER_VENDOR_DIR' => false,
        ]);
        $dump->mustRun();

        File::put($temporary . '/check.php', <<<'PHP'
<?php
declare(strict_types=1);
$loader = require __DIR__ . '/vendor/autoload.php';
$options = \Capell\Core\Support\Activity\LogOptions::defaults();
$coreTrait = trait_exists(\Capell\Core\Support\Activity\LogsActivity::class);
require __DIR__ . '/src/Support/Activity/LogOptions.php';
require __DIR__ . '/src/Support/Activity/VendorLogsActivity.php';
echo json_encode([
    'authoritative' => $loader->isClassMapAuthoritative(),
    'options' => $options::class,
    'core_trait' => $coreTrait,
    'vendor_trait' => trait_exists(\Capell\Core\Support\Activity\VendorLogsActivity::class, false),
], JSON_THROW_ON_ERROR);
PHP);
        $check = new Process([PHP_BINARY, '-d', 'auto_prepend_file=', $temporary . '/check.php']);
        $check->mustRun();
        $result = json_decode($check->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($result)->toMatchArray([
            'authoritative' => $optimisation === '--classmap-authoritative',
            'options' => ActivityLogCompat::logOptionsClass(),
            'core_trait' => true,
            'vendor_trait' => true,
        ]);
        expect($check->getErrorOutput())->toBe('');
    } finally {
        File::deleteDirectory($temporary);
    }
})->with(['aggregate' => ['composer.json'], 'Core package' => ['packages/core/composer.json']])
    ->with(['authoritative' => ['--classmap-authoritative'], 'optimised' => ['-o']]);
