<?php

declare(strict_types=1);

use Composer\Semver\Semver;

require_once dirname(__DIR__, 2) . '/scripts/ComposerMajorConstraints.php';

it('admits locked releases in manifests and command overrides with audited holds', function (): void {
    expect(ComposerMajorConstraints::failures(dirname(__DIR__, 2)))->toBe([]);
});

it('rejects an older major pin on a scratch copy and accepts an explicit union', function (string $path, string $section): void {
    $root = sys_get_temp_dir() . '/capell-composer-majors-' . bin2hex(random_bytes(8));
    mkdir($root . '/' . dirname($path), 0777, true);

    if (! is_dir($root . '/scripts')) {
        mkdir($root . '/scripts', 0777, true);
    }

    file_put_contents($root . '/scripts/composer-major-exceptions.json', '[]');
    file_put_contents($root . '/composer.json', '{}');
    file_put_contents($root . '/composer.lock', json_encode([
        'packages' => [['name' => 'symfony/html-sanitizer', 'version' => 'v8.1.8']],
        'packages-dev' => [],
    ], JSON_THROW_ON_ERROR));

    $writeConstraint = function (string $constraint) use ($root, $path, $section): void {
        file_put_contents($root . '/' . $path, $section === 'command'
            ? 'composer require "symfony/html-sanitizer:' . $constraint . '"'
            : json_encode([$section => ['symfony/html-sanitizer' => $constraint]], JSON_THROW_ON_ERROR));
    };

    try {
        $writeConstraint('^7.0');
        expect(ComposerMajorConstraints::failures($root))->toBe([
            $path . ': symfony/html-sanitizer ^7.0 excludes locked v8.1.8.',
        ]);

        $writeConstraint('^7.0 || ^8.0');
        expect(ComposerMajorConstraints::failures($root))->toBe([]);
    } finally {
        unlink($root . '/' . $path);

        if ($path !== 'composer.json') {
            unlink($root . '/composer.json');
        }

        unlink($root . '/composer.lock');
        unlink($root . '/scripts/composer-major-exceptions.json');

        if (dirname($path) !== 'scripts') {
            rmdir($root . '/scripts');
        }

        $directory = dirname($root . '/' . $path);

        while ($directory !== $root) {
            rmdir($directory);
            $directory = dirname($directory);
        }

        rmdir($root);
    }
})->with([
    'aggregate runtime' => ['composer.json', 'require'],
    'aggregate development' => ['composer.json', 'require-dev'],
    'split runtime' => ['packages/core/composer.json', 'require'],
    'split development' => ['packages/marketplace/composer.json', 'require-dev'],
    'CI override' => ['.github/workflows/test.yml', 'command'],
    'provisioning override' => ['scripts/prepare.sh', 'command'],
]);

it('admits locked external releases in every published Composer manifest', function (): void {
    $root = dirname(__DIR__, 2);

    /** @var array<string, list<array{name: string, version: string, replace?: array<string, string>}>> $lock */
    $lock = json_decode((string) file_get_contents($root . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
    $versions = [];

    foreach ([...$lock['packages'], ...$lock['packages-dev']] as $package) {
        $versions[$package['name']] = $package['version'];

        foreach ($package['replace'] ?? [] as $name => $constraint) {
            if ($constraint === 'self.version') {
                $versions[$name] = $package['version'];
            }
        }
    }

    $failures = [];

    foreach ([$root . '/composer.json', ...(glob($root . '/packages/*/composer.json') ?: [])] as $path) {
        /** @var array{require?: array<string, string>, 'require-dev'?: array<string, string>} $manifest */
        $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        foreach (['require', 'require-dev'] as $section) {
            foreach ($manifest[$section] ?? [] as $name => $constraint) {
                if (isset($versions[$name]) && ! Semver::satisfies($versions[$name], $constraint)) {
                    $failures[] = sprintf('%s: %s %s excludes locked %s.', $path, $name, $constraint, $versions[$name]);
                }
            }
        }
    }

    expect($failures)->toBe([], implode(PHP_EOL, $failures));
});

/**
 * @param  Closure(string): void  $assertion
 * @param  list<array{name: string, heldMajor: int, reason: string, owner: string}>  $exceptions
 */
function withComposerConstraintFixture(Closure $assertion, string $name = 'symfony/html-sanitizer', string $version = 'v8.1.8', array $exceptions = []): void
{
    $root = sys_get_temp_dir() . '/capell-composer-constraints-' . bin2hex(random_bytes(8));
    mkdir($root . '/scripts', 0777, true);
    mkdir($root . '/.github/workflows', 0777, true);
    file_put_contents($root . '/composer.json', '{}');
    file_put_contents($root . '/composer.lock', json_encode([
        'packages' => [['name' => $name, 'version' => $version]],
        'packages-dev' => [],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($root . '/scripts/composer-major-exceptions.json', json_encode($exceptions, JSON_THROW_ON_ERROR));

    try {
        $assertion($root);
    } finally {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($root);
    }
}

function writeComposerConstraintFixture(string $root, string $source, string $constraint): string
{
    if ($source === 'manifest') {
        file_put_contents($root . '/composer.json', json_encode([
            'require' => ['symfony/html-sanitizer' => $constraint],
        ], JSON_THROW_ON_ERROR));

        return 'composer.json';
    }

    if ($source === 'matrix') {
        $workflow = "jobs:\n  test:\n    strategy:\n      matrix:\n        sanitizer: [" . json_encode($constraint, JSON_THROW_ON_ERROR) . "]\n"
            . "    steps:\n      - run: |\n          composer require \"symfony/html-sanitizer:" . '${{ matrix.sanitizer }}' . "\"\n";
    } else {
        $workflow = "jobs:\n  test:\n    steps:\n      - run: |\n          composer require \"symfony/html-sanitizer:" . $constraint . "\"\n";
    }

    file_put_contents($root . '/.github/workflows/test.yml', $workflow);

    return '.github/workflows/test.yml';
}

it('evaluates the full Composer constraint against the exact locked release', function (string $source, string $constraint, bool $admitted): void {
    withComposerConstraintFixture(function (string $root) use ($source, $constraint, $admitted): void {
        $path = writeComposerConstraintFixture($root, $source, $constraint);
        $failures = ComposerMajorConstraints::failures($root);

        expect($failures)->toBe($admitted ? [] : [
            $path . ': symfony/html-sanitizer ' . $constraint . ' excludes locked v8.1.8.',
        ]);
    });
})->with(['manifest', 'workflow', 'matrix'])->with([
    'bounded comparator' => ['>=7.0 <8.0', false],
    'stability flag' => ['^7.0@stable', false],
    'development branch' => ['dev-main', false],
    'disjoint open union' => ['^7.0 || >=9.0', false],
    'exact pin' => ['8.0.0', false],
    'minor wildcard' => ['8.0.*', false],
    'patch lower bound' => ['~8.1.9', false],
    'open union' => ['^7.0 || >=8.0', true],
    'stability union' => ['^7.0@stable || ^8.0', true],
    'hyphen range' => ['7 - 8', true],
    'wildcard union' => ['^7.0 || *', true],
]);

it('reports rejected constraint syntax even when the dependency is absent from the lock', function (string $source, string $constraint, string $lockedName): void {
    withComposerConstraintFixture(function (string $root) use ($source, $constraint): void {
        $path = writeComposerConstraintFixture($root, $source, $constraint);
        $failures = ComposerMajorConstraints::failures($root);

        expect($failures)->toHaveCount(1)
            ->and($failures[0])->toContain($path . ': symfony/html-sanitizer', $constraint, 'Invalid constraint');
    }, name: $lockedName);
})->with(['manifest', 'workflow', 'matrix'])->with([
    'unknown syntax' => ['this-is-not-a-constraint'],
    'invalid union branch' => ['^8.0 || definitely-invalid'],
])->with(['symfony/html-sanitizer', 'another/dependency']);

it('fails closed on an unterminated override instead of accepting its prefix', function (): void {
    withComposerConstraintFixture(function (string $root): void {
        file_put_contents($root . '/scripts/prepare.sh', 'composer require "symfony/html-sanitizer:^8.0');
        $failures = ComposerMajorConstraints::failures($root);

        expect($failures)->toHaveCount(1)
            ->and($failures[0])->toContain('scripts/prepare.sh', 'symfony/html-sanitizer:^8.0', 'Unterminated');
    });
});

it('checks single quoted, YAML flow and bare command overrides', function (string $contents, string $constraint): void {
    withComposerConstraintFixture(function (string $root) use ($contents, $constraint): void {
        file_put_contents($root . '/.github/workflows/test.yml', $contents);

        expect(ComposerMajorConstraints::failures($root))->toBe([
            '.github/workflows/test.yml: symfony/html-sanitizer ' . $constraint . ' excludes locked v8.1.8.',
        ]);
    });
})->with([
    'single quoted command' => ["run: composer require 'symfony/html-sanitizer:>=7.0 <8.0'", '>=7.0 <8.0'],
    'bare command' => ['run: composer require symfony/html-sanitizer:8.0.* --no-update', '8.0.*'],
    'flow matrix override' => ['matrix: {overrides: ["symfony/html-sanitizer:>=7.0 <8.0"]}', '>=7.0 <8.0'],
    'plain matrix override' => ["matrix:\n  overrides:\n    - symfony/html-sanitizer:>=7.0 <8.0", '>=7.0 <8.0'],
]);

it('rejects an unresolved matrix override with its complete text', function (): void {
    withComposerConstraintFixture(function (string $root): void {
        file_put_contents($root . '/.github/workflows/test.yml', 'run: composer require "symfony/html-sanitizer:${{ matrix.missing }}"');
        $failures = ComposerMajorConstraints::failures($root);

        expect($failures)->toHaveCount(1)
            ->and($failures[0])->toContain('.github/workflows/test.yml', '${{ matrix.missing }}', 'Unresolved');
    });
});

it('checks every included matrix constraint rather than just the passing value', function (): void {
    withComposerConstraintFixture(function (string $root): void {
        file_put_contents($root . '/.github/workflows/test.yml', <<<'YAML'
jobs:
  test:
    strategy:
      matrix:
        include:
          - sanitizer: ^8.0
          - sanitizer: '>=7.0 <8.0'
    steps:
      - run: composer require "symfony/html-sanitizer:${{ matrix.sanitizer }}"
YAML);

        expect(ComposerMajorConstraints::failures($root))->toBe([
            '.github/workflows/test.yml: symfony/html-sanitizer >=7.0 <8.0 excludes locked v8.1.8.',
        ]);
    });
});

it('enforces each audited hold at major four and requires a decision at major five', function (string $name, string $version, bool $held): void {
    $exceptions = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/scripts/composer-major-exceptions.json'), true, flags: JSON_THROW_ON_ERROR);
    $exception = array_values(array_filter($exceptions, fn (array $candidate): bool => $candidate['name'] === $name));

    expect($exception)->toHaveCount(1);

    withComposerConstraintFixture(function (string $root) use ($name, $held, $version): void {
        // A permissive constraint makes the hold check independent of release admission.
        file_put_contents($root . '/composer.json', json_encode(['require' => [$name => '^4.0 || ^5.0']], JSON_THROW_ON_ERROR));
        $failures = ComposerMajorConstraints::failures($root);

        expect($failures)->toBe($held ? [] : [
            'scripts/composer-major-exceptions.json: ' . $name . ' is held at major 4 but locked ' . $version . '; review or remove the audited hold.',
        ]);
    }, $name, $version, $exception);
})->with(['spatie/laravel-activitylog', 'guava/filament-icon-picker', 'openspout/openspout'])->with([
    'held' => ['v4.12.3', true],
    'bumped' => ['5.0.0', false],
]);

it('reports stale audited exceptions absent from all constraints', function (): void {
    withComposerConstraintFixture(function (string $root): void {
        expect(ComposerMajorConstraints::failures($root))->toBe([
            'scripts/composer-major-exceptions.json: absent/dependency is stale; no manifest or command override declares it.',
        ]);
    }, exceptions: [[
        'name' => 'absent/dependency',
        'heldMajor' => 4,
        'reason' => 'Compatibility review pending.',
        'owner' => 'Review dependency compatibility',
    ]]);
});

it('does not let an audited hold excuse an invalid release constraint', function (): void {
    withComposerConstraintFixture(function (string $root): void {
        file_put_contents($root . '/composer.json', json_encode(['require' => ['held/dependency' => '4.0.*']], JSON_THROW_ON_ERROR));

        expect(ComposerMajorConstraints::failures($root))->toBe([
            'composer.json: held/dependency 4.0.* excludes locked 4.12.3.',
        ]);
    }, 'held/dependency', '4.12.3', [[
        'name' => 'held/dependency',
        'heldMajor' => 4,
        'reason' => 'Compatibility review pending.',
        'owner' => 'Review dependency compatibility',
    ]]);
});

/**
 * @return array{0: int, 1: string}
 */
function packageDependencyRun(string ...$arguments): array
{
    $root = dirname(__DIR__, 2);

    $command = sprintf(
        '%s %s %s 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($root . '/scripts/check-package-dependencies.php'),
        implode(' ', array_map(escapeshellarg(...), $arguments)),
    );

    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);

    return [$exitCode, implode("\n", $output)];
}

it('passes for every package with the recorded baseline applied', function (): void {
    [$exitCode, $output] = packageDependencyRun();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Package dependency contract satisfied');
});

it('fails when the baseline is not applied, proving the analyser is wired', function (): void {
    [$exitCode, $output] = packageDependencyRun('--package=marketplace', '--no-baseline');

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Package dependency contract failed for: marketplace');
});

it('rejects an unknown package', function (): void {
    [$exitCode, $output] = packageDependencyRun('--package=nope');

    expect($exitCode)->toBe(2)
        ->and($output)->toContain('Unknown package: nope');
});

it('records baseline debt only for packages that exist', function (): void {
    $root = dirname(__DIR__, 2);

    /** @var array<string, array<string, list<string>>> $baseline */
    $baseline = require $root . '/scripts/package-dependency-baseline.php';

    foreach (array_keys($baseline) as $package) {
        expect($root . '/packages/' . $package . '/composer.json')->toBeFile();
    }
});

it('prints a candidate baseline without changing the accepted debt file', function (): void {
    $root = dirname(__DIR__, 2);
    $baselinePath = $root . '/scripts/package-dependency-baseline.php';
    $before = (string) file_get_contents($baselinePath);

    [$exitCode, $output] = packageDependencyRun('--package=marketplace', '--print-baseline');

    expect($exitCode)->toBe(0)
        ->and($output)->toContain("'marketplace' =>")
        ->and(file_get_contents($baselinePath))->toBe($before);
});

it('rejects automatic baseline rewrites', function (): void {
    [$exitCode, $output] = packageDependencyRun('--update');

    expect($exitCode)->toBe(2)
        ->and($output)->toContain('Baseline files are never written by this gate');
});
