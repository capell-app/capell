<?php

declare(strict_types=1);

use Composer\InstalledVersions;

require dirname(__DIR__) . '/vendor/autoload.php';

$shardPluginPath = dirname(__DIR__) . '/vendor/pestphp/pest/src/Plugins/Shard.php';
$checkOnly = in_array('--check', $argv, true);
$version = InstalledVersions::getPrettyVersion('pestphp/pest');

if (! is_string($version) || preg_match('/^v?4\.7\./', $version) !== 1) {
    fwrite(STDERR, "Pest shard compatibility patch only supports Pest 4.7.x; review whether it can now be deleted.\n");

    throw new RuntimeException('Unsupported Pest version.');
}

if (! file_exists($shardPluginPath)) {
    echo "Pest shard plugin not found at {$shardPluginPath}; skipping shard patch.\n";

    return;
}

$contents = file_get_contents($shardPluginPath);

if (! is_string($contents)) {
    fwrite(STDERR, "Unable to read Pest shard plugin at {$shardPluginPath}.\n");

    throw new RuntimeException('Unable to read Pest shard plugin.');
}

$originalPattern = "preg_match_all('/ - (?:P\\\\\\\\)?(Tests\\\\\\\\[^:]+)::/', \$output, \$matches);";
$patchedPattern = "preg_match_all('/ - (?:P\\\\\\\\)?([^:\\\\r\\\\n]+)::/', \$output, \$matches);";
$originalProcess = "        \$output = (new Process([\n            'php',";
$patchedProcess = "        \$output = (new Process([\n            PHP_BINARY,\n            '-d',\n            'memory_limit=' . ini_get('memory_limit'),";
$namespaceReady = str_contains($contents, $patchedPattern);
$memoryReady = str_contains($contents, $patchedProcess);

if ($namespaceReady && $memoryReady) {
    echo "Pest shard compatibility is present.\n";

    return;
}

if ($checkOnly) {
    fwrite(STDERR, "Pest shard compatibility patch is missing; run composer patch:pest-shards.\n");

    throw new RuntimeException('Pest shard compatibility patch is missing.');
}

if (! $namespaceReady && ! str_contains($contents, $originalPattern)) {
    fwrite(STDERR, "Pest shard namespace patch could not find the expected source line.\n");

    throw new RuntimeException('Unexpected Pest shard namespace implementation.');
}

if (! $memoryReady && ! str_contains($contents, $originalProcess)) {
    fwrite(STDERR, "Pest shard memory patch could not find the expected process definition.\n");

    throw new RuntimeException('Unexpected Pest shard process implementation.');
}

$contents = str_replace($originalPattern, $patchedPattern, $contents);
$contents = str_replace($originalProcess, $patchedProcess, $contents);

file_put_contents($shardPluginPath, $contents);

echo "Pest shard compatibility patch applied.\n";
