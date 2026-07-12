<?php

declare(strict_types=1);

$shardPluginPath = dirname(__DIR__) . '/vendor/pestphp/pest/src/Plugins/Shard.php';

if (! file_exists($shardPluginPath)) {
    echo "Pest shard plugin not found at {$shardPluginPath}; skipping shard patch.\n";

    return;
}

$contents = file_get_contents($shardPluginPath);

if (! is_string($contents)) {
    throw new RuntimeException("Unable to read Pest shard plugin at {$shardPluginPath}.");
}

$originalPattern = "preg_match_all('/ - (?:P\\\\\\\\)?(Tests\\\\\\\\[^:]+)::/', \$output, \$matches);";
$patchedPattern = "preg_match_all('/ - (?:P\\\\\\\\)?([^:\\\\r\\\\n]+)::/', \$output, \$matches);";

$fullyQualifiedPattern = "preg_match_all('/ - ([^:\\r\\n]+)::/', \$output, \$matches);";
$contents = str_replace($fullyQualifiedPattern, $patchedPattern, $contents);

if (str_contains($contents, $patchedPattern)) {
    echo "Pest shard namespace patch already applied.\n";
} elseif (! str_contains($contents, $originalPattern)) {
    throw new RuntimeException('Pest shard namespace patch could not find the expected source line.');
} else {
    $contents = str_replace($originalPattern, $patchedPattern, $contents);
    echo "Pest shard namespace patch applied.\n";
}

$originalParallelFilter = "return array_filter(\$arguments, fn (string \$argument): bool => ! in_array(\$argument, ['--parallel', '-p'], strict: true));";
$patchedParallelFilter = "return array_filter(\$arguments, fn (string \$argument): bool => ! in_array(\$argument, ['--parallel', '-p'], strict: true)\n            && ! str_starts_with(\$argument, '--processes=')\n            && ! str_starts_with(\$argument, '--max-processes='));";

if (str_contains($contents, $patchedParallelFilter)) {
    echo "Pest shard parallel-option patch already applied.\n";
} elseif (! str_contains($contents, $originalParallelFilter)) {
    throw new RuntimeException('Pest shard parallel-option patch could not find the expected source line.');
} else {
    $contents = str_replace($originalParallelFilter, $patchedParallelFilter, $contents);
    echo "Pest shard parallel-option patch applied.\n";
}

file_put_contents($shardPluginPath, $contents);
