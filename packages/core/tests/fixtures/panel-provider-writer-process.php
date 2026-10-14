<?php

declare(strict_types=1);

use Capell\Core\Support\Install\PanelProviderWriter;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;

// A second installer process: it reads the provider, signals that it is ready,
// then either saves its own edit through the writer or replaces the file directly.
$arguments = is_array($argv ?? null) ? array_map(strval(...), $argv) : [];

require ($arguments[1] ?? '') . '/vendor/autoload.php';

throw_if(count($arguments) !== 7, InvalidArgumentException::class, 'Expected workspace, base path, mode, provider, replacement and ready paths.');

[, , $basePath, $mode, $providerPath, $replacementPath, $readyPath] = $arguments;

$app = new Application($basePath);
$app->instance('config', new Repository(['cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]]]));
$app->register(FilesystemServiceProvider::class);
$app->register(CacheServiceProvider::class);
Facade::setFacadeApplication($app);

$original = (string) file_get_contents($providerPath);
$replacement = (string) file_get_contents($replacementPath);
touch($readyPath);

if ($mode === 'replace') {
    file_put_contents($providerPath, $replacement);
    echo 'replaced';

    return;
}

try {
    $app->make(PanelProviderWriter::class)->save($providerPath, $original, $replacement);
    echo 'saved';
} catch (RuntimeException) {
    echo 'refused';
}
