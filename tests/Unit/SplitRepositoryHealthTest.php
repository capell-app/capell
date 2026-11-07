<?php

declare(strict_types=1);

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

require_once dirname(__DIR__, 2) . '/scripts/RepositoryDistributionFiles.php';

it('keeps generated split repository policies aligned with their shared sources', function (): void {
    $root = dirname(__DIR__, 2);
    $process = new Process([PHP_BINARY, 'scripts/sync-split-repository-health.php', '--check'], $root);

    $process->mustRun();

    expect($process->getOutput())->toContain('Split repository health files are aligned.');
});

it('covers every repository ecosystem with a cooldown and pins every external workflow action', function (): void {
    $root = dirname(__DIR__, 2);
    /** @var list<array{path: string}> $packages */
    $packages = json_decode((string) file_get_contents($root . '/config/release-packages.json'), true, 512, JSON_THROW_ON_ERROR);

    foreach (['', ...array_column($packages, 'path')] as $path) {
        $directory = $root . ($path === '' ? '' : '/' . $path);
        /** @var array{version: int, updates: list<array{package-ecosystem: string, cooldown: array{default-days: int}, open-pull-requests-limit?: int, groups?: array<string, array{applies-to: string, patterns: list<string>}>}>} $dependabot */
        $dependabot = Yaml::parseFile($directory . '/.github/dependabot.yml');
        $ecosystems = array_column($dependabot['updates'], 'package-ecosystem');

        expect($dependabot['version'])->toBe(2)
            ->and($ecosystems)->toContain('composer', 'github-actions');

        if (is_file($directory . '/package.json')) {
            expect($ecosystems)->toContain('npm');
        }

        foreach ($dependabot['updates'] as $update) {
            expect($update['cooldown']['default-days'])->toBeGreaterThanOrEqual(3);

            if ($path !== '') {
                expect($update['open-pull-requests-limit'] ?? null)->toBe(0)
                    ->and($update['groups']['security-updates'] ?? null)->toBe([
                        'applies-to' => 'security-updates',
                        'patterns' => ['*'],
                    ]);
            }
        }

        foreach (glob($directory . '/.github/workflows/*.{yml,yaml}', GLOB_BRACE) ?: [] as $workflowPath) {
            $workflow = (string) file_get_contents($workflowPath);
            preg_match_all('/^\s*(?:-\s*)?uses:\s*([^\s#]+)([^\r\n]*)/m', $workflow, $references, PREG_SET_ORDER);

            foreach ($references as $reference) {
                if (str_starts_with($reference[1], './')) {
                    continue;
                }

                expect($reference[1])->toMatch('/@[a-fA-F0-9]{40}$/')
                    ->and($reference[2])->toMatch('/#\s*v\S+/');
            }
        }
    }
});

it('ships runtime inputs and excludes development files in real aggregate and split archives', function (string $scope): void {
    $root = dirname(__DIR__, 2);
    $distribution = new RepositoryDistributionFiles($root);
    $temporary = sys_get_temp_dir() . '/capell-dist-' . bin2hex(random_bytes(8));
    $filesystem = new Filesystem;
    $filesystem->mkdir($temporary . '/attributes');

    try {
        // Each split has its own repository root. An attributes-only work tree
        // lets git archive read the edited policy without touching Git objects,
        // the index, or refs, and without inheriting aggregate attributes.
        $scopes = $scope === '' ? ['', ...$distribution->packagePaths] : [$scope];
        foreach ($scopes as $attributesScope) {
            $relative = $scope === '' ? $attributesScope : '';
            $filesystem->copy(
                $root . ($attributesScope === '' ? '' : '/' . $attributesScope) . '/.gitattributes',
                $temporary . '/attributes/' . ($relative === '' ? '' : $relative . '/') . '.gitattributes',
            );
        }

        $gitDirectory = new Process(['git', 'rev-parse', '--absolute-git-dir'], $root);
        $gitDirectory->mustRun();
        $archive = new Process([
            'git', '--git-dir=' . trim($gitDirectory->getOutput()), '--work-tree=' . $temporary . '/attributes',
            '-c', 'core.attributesFile=/dev/null', 'archive', '--worktree-attributes', '--format=zip',
            '--output=' . $temporary . '/dist.zip', 'HEAD' . ($scope === '' ? '' : ':' . $scope),
        ], $temporary . '/attributes');
        $archive->mustRun();

        $zip = new ZipArchive;
        expect($zip->open($temporary . '/dist.zip'))->toBeTrue();
        $files = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (is_string($name) && ! str_ends_with($name, '/')) {
                $files[] = $name;
            }
        }

        $zip->close();

        $prefix = $scope === '' ? '' : $scope . '/';
        $runtimeFiles = $distribution->requiredFiles();
        $required = array_values(array_map(
            static fn (string $file): string => substr($file, strlen($prefix)),
            array_filter($runtimeFiles, static fn (string $file): bool => str_starts_with($file, $prefix)),
        ));
        expect(array_values(array_diff($required, $files)))->toBe([], 'Missing runtime files from ' . ($scope ?: 'aggregate'));

        foreach ($scopes as $documentationScope) {
            preg_match_all('/^\/(.+) export-ignore$/m', $distribution->documentationAttributes($documentationScope, $runtimeFiles), $exclusions);
            foreach ($exclusions[1] as $path) {
                $excluded = ($scope === '' && $documentationScope !== '' ? $documentationScope . '/' : '') . $path;
                expect(array_values(array_filter($files, static fn (string $file): bool => $file === $excluded || str_starts_with($file, $excluded . '/'))))
                    ->toBe([], 'Development documentation leaked into archive: ' . $excluded);
            }
        }

        foreach ($files as $file) {
            // Anchor tooling to repository/package roots: extension stub tests,
            // package.json templates and phpunit.xml stubs must still ship.
            $relative = preg_replace('#^packages/[^/]+/#', '', $file);
            expect($relative)->not->toMatch('#^(?:\.github|\.docker|\.claude|\.codex|\.agents|tests|scripts|workbench|phpstan|artwork)(?:/|$)#')
                ->and($relative)->not->toMatch('#^(?:composer\.lock|package-lock\.json|package\.json|yarn\.lock|pnpm-lock\.yaml|bun\.lockb?|AGENTS\.md|CLAUDE\.md|\.gitattributes|\.gitignore|\.editorconfig|rector\.php|pint\.json|phpstan[^/]*|phpunit[^/]*|testbench\.yaml|docker-compose[^/]*|capell|playwright\.config\.js|screenshots\.config\.mjs|tailwind\.config\.[^/]+|vite\.config\.[^/]+)$#');
        }
    } finally {
        $filesystem->remove($temporary);
    }
})->with(function (): array {
    $distribution = new RepositoryDistributionFiles(dirname(__DIR__, 2));

    return ['', ...$distribution->packagePaths];
});

it('derives documentation exclusions from tracked and non-ignored files without dropping runtime docs', function (): void {
    $root = sys_get_temp_dir() . '/capell-distribution-ignore-' . bin2hex(random_bytes(8));
    $filesystem = new Filesystem;
    $files = [
        'config/release-packages.json' => json_encode([['path' => 'packages/example']], JSON_THROW_ON_ERROR),
        'packages/example/capell.json' => '{}',
        'packages/example/src/Provider.php' => "<?php const REQUIRED_DOC = 'docs/ignored/needed.md';",
        '.gitignore' => "/docs/ignored/\n/packages/example/docs/ignored/\n**/*.local\n/docs/private-cache/\n",
        'docs/ignored/needed.md' => 'Tracked runtime documentation.',
        'docs/ignored/tracked.md' => 'Tracked documentation covered by a later ignore rule.',
        'docs/ignored/scratch.md' => 'Ignored local scratch.',
        'docs/ordinary.md' => 'New untracked documentation still needs an exclusion.',
        'docs/notes.local' => 'Ignored local file.',
        'docs/private-cache/scratch.md' => 'Ignored local directory.',
        'packages/example/docs/ignored/needed.md' => 'Tracked package runtime documentation.',
        'packages/example/docs/ordinary.md' => 'New package documentation.',
        'packages/example/docs/notes.local' => 'Ignored package local file.',
    ];

    try {
        foreach ($files as $path => $contents) {
            $filesystem->mkdir(dirname($root . '/' . $path));
            file_put_contents($root . '/' . $path, $contents);
        }

        // Isolate Git from any enclosing hook's repository/index and global exclude configuration.
        $environment = ['GIT_DIR' => false, 'GIT_WORK_TREE' => false, 'GIT_COMMON_DIR' => false, 'GIT_INDEX_FILE' => false];
        new Process(['git', '-c', 'core.excludesFile=/dev/null', 'init', '--quiet', $root], env: $environment)->mustRun();
        new Process(['git', '-C', $root, 'config', 'core.excludesFile', '/dev/null'], env: $environment)->mustRun();
        new Process(['git', '-C', $root, 'add', '--force', '--', 'docs/ignored/needed.md', 'docs/ignored/tracked.md', 'packages/example/docs/ignored/needed.md'], env: $environment)->mustRun();

        $distribution = new RepositoryDistributionFiles($root);
        $required = $distribution->requiredFiles();

        expect($required)->toContain('docs/ignored/needed.md', 'packages/example/docs/ignored/needed.md')
            ->and($distribution->documentationAttributes('', $required))->toBe("/docs/ignored/tracked.md export-ignore\n/docs/ordinary.md export-ignore\n")
            ->and($distribution->documentationAttributes('packages/example', $required))->toBe("/docs/ordinary.md export-ignore\n");
    } finally {
        $filesystem->remove($root);
    }
});

it('continues deriving documentation exclusions from source archives without Git metadata', function (): void {
    $root = sys_get_temp_dir() . '/capell-distribution-archive-' . bin2hex(random_bytes(8));
    $filesystem = new Filesystem;
    $filesystem->mkdir([$root . '/config', $root . '/docs']);

    try {
        file_put_contents($root . '/config/release-packages.json', '[]');
        file_put_contents($root . '/.gitignore', "docs/*.local\n");
        file_put_contents($root . '/docs/ordinary.md', 'Source archive documentation.');
        file_put_contents($root . '/docs/notes.local', 'No Git metadata establishes local-only status.');
        $distribution = new RepositoryDistributionFiles($root);

        expect($distribution->documentationAttributes('', []))->toBe("/docs/notes.local export-ignore\n/docs/ordinary.md export-ignore\n");
    } finally {
        $filesystem->remove($root);
    }
});

it('reports an unusable Git checkout instead of silently generating host-specific exclusions', function (): void {
    $root = sys_get_temp_dir() . '/capell-distribution-invalid-git-' . bin2hex(random_bytes(8));
    $filesystem = new Filesystem;
    $filesystem->mkdir([$root . '/config', $root . '/docs']);

    try {
        file_put_contents($root . '/config/release-packages.json', '[]');
        file_put_contents($root . '/.git', "gitdir: missing-git-directory\n");
        file_put_contents($root . '/docs/ordinary.md', 'Documentation.');
        $distribution = new RepositoryDistributionFiles($root);

        expect(fn (): string => $distribution->documentationAttributes('', []))
            ->toThrow(RuntimeException::class, 'Unable to read Git documentation paths');
    } finally {
        $filesystem->remove($root);
    }
});
