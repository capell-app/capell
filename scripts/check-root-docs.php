<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$allowed = [
    'AGENTS.md',
    'CAPELL-4-CAPABILITIES.md',
    'CHANGELOG.md',
    'CODE_OF_CONDUCT.md',
    'CONTEXT.md',
    'CONTRIBUTING.md',
    'LICENSE.md',
    'README.md',
    'SECURITY.md',
];

$allowedLookup = array_fill_keys($allowed, true);
$failures = [];

foreach (glob($root . '/*.md') ?: [] as $path) {
    $fileName = basename($path);

    if (! isset($allowedLookup[$fileName])) {
        $failures[] = $fileName;
    }
}

if ($failures !== []) {
    sort($failures);

    fwrite(STDERR, "Unexpected root markdown file(s):\n");

    foreach ($failures as $failure) {
        fwrite(STDERR, "- {$failure}\n");
    }

    fwrite(STDERR, "\nMove scratch/handoff notes outside the repo or add permanent docs under docs/.\n");

    return 2;
}

$staleThreshold = (new DateTimeImmutable('today'))->modify('-60 days');

foreach (['CAPELL-4-CAPABILITIES.md', 'CONTEXT.md'] as $reviewedFile) {
    $path = $root . DIRECTORY_SEPARATOR . $reviewedFile;
    $contents = file_get_contents($path);

    if ($contents === false) {
        continue;
    }

    if (preg_match('/^Last reviewed:\s*(\d{4}-\d{2}-\d{2})\s*$/m', $contents, $matches) !== 1) {
        emitWarning("{$reviewedFile} is missing a Last reviewed date.");

        continue;
    }

    $reviewedAt = DateTimeImmutable::createFromFormat('!Y-m-d', $matches[1]);

    if (! $reviewedAt instanceof DateTimeImmutable || $reviewedAt < $staleThreshold) {
        emitWarning("{$reviewedFile} was last reviewed on {$matches[1]}; refresh it if the status has drifted.");
    }
}

function emitWarning(string $message): void
{
    if (getenv('GITHUB_ACTIONS') === 'true') {
        echo "::warning::{$message}\n";

        return;
    }

    fwrite(STDERR, "Warning: {$message}\n");
}
