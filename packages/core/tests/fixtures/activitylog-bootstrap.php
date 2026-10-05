<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\RequestGuard;
use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Spatie\Activitylog\ActivitylogServiceProvider;

/** @return array{app: Application, source: string} */
function bootActivityLogFixture(string $root): array
{
    $vendor = $root . '/vendor';
    $source = getenv('CAPELL_ACTIVITYLOG_SOURCE') ?: $vendor . '/spatie/laravel-activitylog';

    // Rebuild the loader before loading either major: a shared vendor classmap must
    // not select the other major or another checkout's Capell classes.
    require_once $vendor . '/composer/ClassLoader.php';
    $loader = new ClassLoader;
    foreach (require $vendor . '/composer/autoload_psr4.php' as $prefix => $paths) {
        $loader->setPsr4($prefix, $paths);
    }

    $manifest = json_decode(file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach (['autoload', 'autoload-dev'] as $section) {
        foreach ($manifest[$section]['psr-4'] as $prefix => $paths) {
            $loader->setPsr4($prefix, array_map(static fn (string $path): string => $root . '/' . $path, (array) $paths));
        }
    }

    $loader->setPsr4('Spatie\\Activitylog\\', $source . '/src');
    $loader->addClassMap(array_filter(
        require $vendor . '/composer/autoload_classmap.php',
        static fn (string $name): bool => ! str_starts_with($name, 'Spatie\\Activitylog\\') && ! str_starts_with($name, 'Capell\\'),
        ARRAY_FILTER_USE_KEY,
    ));
    $loader->register(true);
    foreach (require $vendor . '/composer/autoload_files.php' as $id => $file) {
        if (str_contains((string) $file, '/spatie/laravel-activitylog/')) {
            continue;
        }

        $GLOBALS['__composer_autoload_files'][$id] = true;
        require_once $file;
    }

    require_once $source . '/src/helpers.php';

    $app = new Application($root);
    $app->instance('config', new Repository([
        'activitylog' => require $source . '/config/activitylog.php',
        'auth' => ['defaults' => ['guard' => 'fixture'], 'guards' => ['fixture' => ['driver' => 'fixture']]],
    ]));
    $app->instance('events', new Dispatcher($app));

    $auth = new AuthManager($app);
    $auth->extend('fixture', fn (): RequestGuard => new RequestGuard(static fn (): null => null, new Request));

    $app->instance('auth', $auth);
    $database = new Manager($app);
    $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $database->setEventDispatcher($app->make(Illuminate\Contracts\Events\Dispatcher::class));
    $database->bootEloquent();

    $app->instance('db', $database->getDatabaseManager());
    $app->bind('db.schema', static fn () => $database->getDatabaseManager()->connection()->getSchemaBuilder());
    Facade::setFacadeApplication($app);
    new ActivitylogServiceProvider($app)->registeringPackage();

    return ['app' => $app, 'source' => $source];
}
