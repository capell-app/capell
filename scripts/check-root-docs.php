<?php

declare(strict_types=1);

$root = getenv('CAPELL_ROOT_DOCS_ROOT') ?: dirname(__DIR__);
$allowedRootDocuments = [
    'AGENTS.md',
    'CHANGELOG.md',
    'CODE_OF_CONDUCT.md',
    'CONTRIBUTING.md',
    'LICENSE.md',
    'README.md',
    'SECURITY.md',
];
$failures = [];

$composer = json_decode((string) file_get_contents($root . '/composer.json'), true);

if (! is_array($composer)) {
    $failures[] = 'composer.json must contain valid JSON.';
} else {
    $expectedDescription = 'The supported, version-aligned Capell foundation aggregate for Core, Admin, Frontend, Installer, and Marketplace.';
    $expectedReplacements = [
        'capell-app/admin',
        'capell-app/core',
        'capell-app/frontend',
        'capell-app/installer',
        'capell-app/marketplace',
    ];

    if (($composer['name'] ?? null) !== 'capell-app/capell') {
        $failures[] = 'composer.json must identify the root aggregate as capell-app/capell.';
    }

    if (($composer['description'] ?? null) !== $expectedDescription) {
        $failures[] = 'composer.json must describe the supported version-aligned foundation aggregate.';
    }

    foreach ($expectedReplacements as $package) {
        if (($composer['replace'][$package] ?? null) !== 'self.version') {
            $failures[] = sprintf('composer.json must replace %s at self.version.', $package);
        }
    }
}

$readmePath = $root . '/README.md';

if (! is_file($readmePath) || ! is_readable($readmePath)) {
    $failures[] = 'README.md could not be read.';
}

foreach (glob($root . '/*.md') ?: [] as $path) {
    $fileName = basename($path);

    if ($fileName === 'CLAUDE.md' && is_link($path) && readlink($path) === 'AGENTS.md') {
        continue;
    }

    if (! in_array($fileName, $allowedRootDocuments, true)) {
        $failures[] = $fileName;
    }
}

if ($failures !== []) {
    sort($failures);

    fwrite(STDERR, "Root documentation contract failed:\n");

    foreach ($failures as $failure) {
        fwrite(STDERR, sprintf('- %s%s', $failure, PHP_EOL));
    }

    fwrite(STDERR, "\nKeep scratch and handoff notes outside the repository, and keep package positioning aligned with Composer.\n");

    exit(2);
}

fwrite(STDOUT, "Root documentation contract is verified.\n");
