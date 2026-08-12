<?php

declare(strict_types=1);

$root = getenv('CAPELL_ROOT_DOCS_ROOT') ?: dirname(__DIR__);
$allowed = [
    'AGENTS.md',
    'CHANGELOG.md',
    'CODE_OF_CONDUCT.md',
    'CONTRIBUTING.md',
    'LICENSE.md',
    'README.md',
    'SECURITY.md',
];

$allowedLookup = array_fill_keys($allowed, true);
$failures = [];

$composerPath = $root . '/composer.json';
$composerContents = file_get_contents($composerPath);
$composer = is_string($composerContents) ? json_decode($composerContents, true) : null;

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
$readme = file_get_contents($readmePath);

if (! is_string($readme)) {
    $failures[] = 'README.md could not be read.';
} else {
    $normalizedReadme = (string) preg_replace('/\s+/', ' ', $readme);
    $readmeContracts = [
        '**The first editable page is easy. Capell solves what comes next.**',
        'Capell is an open-source CMS for Laravel, built on Filament.',
        'A Page model and Filament resource are quick; the long-term work is reusable page blueprints, preview through the real theme, URL history, revision comparison, validated recovery and repeatable upgrades.',
        'Capell Foundation turns that recurring work into one maintained, MIT-licensed contract. Editors preview, publish and recover pages in Filament; the Laravel application renders them with Blade, Livewire, Inertia, Vue.js or its own stack.',
        'Underneath is a slim, strictly typed and well-tested core. Filament editing and public rendering stay completely separate, while new capabilities plug in through normal Laravel packages instead of core patches.',
        'Your pages render inside your Laravel application through Blade, Livewire, Inertia, Vue, or your own stack.',
        'Capell is not a hosted CMS and does not ship a public content-delivery API.',
        'The canonical installation entry point for an existing Laravel application is `capell-app/installer`.',
        'The `capell-app/capell` package is the supported, version-aligned foundation aggregate for the Core, Admin, Frontend, Installer, and Marketplace code line',
    ];
    $retiredClaims = [
        'private foundation',
        'private package',
        'private distribution',
        'schema-driven',
    ];

    foreach ($readmeContracts as $contract) {
        if (! str_contains($normalizedReadme, $contract)) {
            $failures[] = 'README.md is missing package truth: ' . $contract;
        }
    }

    foreach ($retiredClaims as $retiredClaim) {
        if (str_contains(mb_strtolower($readme), $retiredClaim)) {
            $failures[] = 'README.md contains retired package positioning: ' . $retiredClaim;
        }
    }
}

foreach (glob($root . '/*.md') ?: [] as $path) {
    $fileName = basename($path);

    if ($fileName === 'CLAUDE.md' && is_link($path) && readlink($path) === 'AGENTS.md') {
        continue;
    }

    if (! isset($allowedLookup[$fileName])) {
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
