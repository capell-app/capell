#!/usr/bin/env php
<?php

declare(strict_types=1);

use Capell\Core\Support\CapellSiteSpecSchema;

require dirname(__DIR__) . '/vendor/autoload.php';

$schemaPath = dirname(__DIR__) . '/docs/packages/site-spec.schema.json';
$schema = json_encode(
    CapellSiteSpecSchema::toArray(),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
) . PHP_EOL;

file_put_contents($schemaPath, $schema);
fwrite(STDOUT, sprintf('Content schema written to [%s].%s', $schemaPath, PHP_EOL));
