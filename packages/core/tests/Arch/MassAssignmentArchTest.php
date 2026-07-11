<?php

declare(strict_types=1);

use Capell\Core\Models\Media;
use Illuminate\Database\Eloquent\Model;

/**
 * Guards against introducing broad `$guarded = []` mass-assignment on Capell
 * models. A model is unsafe when it is "fully unguarded": its `$guarded`
 * default is the empty array AND it declares no `$fillable`, making every
 * column mass-assignable from raw input.
 *
 * Safe models are either default totally-guarded (`$guarded = ['*']`) or
 * declare an explicit non-empty `$fillable` allow-list.
 *
 * ALLOWED_FULLY_UNGUARDED holds documented opt-outs only.
 *
 * @return array<string, list<class-string<Model>>>
 */
function capellModelClasses(): array
{
    $packagesPath = dirname(__DIR__, 3);

    /** @var array<string, string> $namespaceByPackage */
    $namespaceByPackage = [
        'core' => 'Capell\\Core\\Models\\',
        'admin' => 'Capell\\Admin\\Models\\',
        'marketplace' => 'Capell\\Marketplace\\Models\\',
        'frontend' => 'Capell\\Frontend\\Models\\',
        'installer' => 'Capell\\Installer\\Models\\',
    ];

    $classes = [];

    foreach ($namespaceByPackage as $package => $namespace) {
        $modelsPath = $packagesPath . '/' . $package . '/src/Models';

        if (! is_dir($modelsPath)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($modelsPath, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = substr($file->getPathname(), strlen($modelsPath) + 1, -4);
            $class = $namespace . str_replace('/', '\\', $relativePath);

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if (! $reflection->isInstantiable()) {
                continue;
            }

            if (! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            /** @var class-string<Model> $class */
            $classes[$class] = [$class];
        }
    }

    ksort($classes);

    return $classes;
}

/**
 * Documented opt-outs. Each entry must carry a reason.
 *
 * - Capell\Core\Models\Media: extends Spatie\MediaLibrary's Media model, which
 *   ships `$guarded = []` and relies on unguarded mass assignment for its
 *   internal custom_properties / conversions columns. Pinning a $fillable here
 *   would break the media library.
 *
 * @var list<class-string<Model>>
 */
const ALLOWED_FULLY_UNGUARDED = [
    Media::class,
];

it('discovers Capell models', function (): void {
    expect(capellModelClasses())->not->toBeEmpty();
})->group('Core');

it('does not use broad $guarded = [] without an explicit $fillable', function (string $modelClass): void {
    $defaults = new ReflectionClass($modelClass)->getDefaultProperties();

    $guarded = $defaults['guarded'] ?? ['*'];
    $fillable = $defaults['fillable'] ?? [];

    $isFullyUnguarded = $guarded === [] && $fillable === [];

    expect($isFullyUnguarded && ! in_array($modelClass, ALLOWED_FULLY_UNGUARDED, true))
        ->toBeFalse(
            $modelClass . ' is fully unguarded ($guarded = [] with no $fillable). '
            . 'Define an explicit $fillable allow-list of user-assignable columns, '
            . 'and write sensitive/server-controlled columns via forceFill().',
        );
})->with(capellModelClasses())->group('Core');
