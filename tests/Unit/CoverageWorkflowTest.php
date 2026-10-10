<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

require_once __DIR__ . '/../Support/DomQuery.php';

/** @return array<string, mixed> */
function coverageWorkflow(): array
{
    return Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/coverage-release.yml');
}

/** @return array<string, mixed> */
function coverageComposerScripts(): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR)['scripts'];
}

/** @return list<string> */
function coverageJobCommands(string $job): array
{
    return array_values(array_filter(array_column(coverageWorkflow()['jobs'][$job]['steps'], 'run'), is_string(...)));
}

/** @return array<string, mixed> */
function coverageConfigurationSemantics(string $file): array
{
    $document = new DOMDocument;
    $document->load($file);

    $nodeValue = static function (DOMElement $element) use (&$nodeValue): array {
        $attributes = [];
        foreach ($element->attributes as $attribute) {
            $attributes[$attribute->name] = $attribute->value;
        }

        ksort($attributes);
        $children = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $nodeValue($child);
            }
        }

        // Attribute order, indentation, comments and sibling order are cosmetic here.
        sort($children);

        return [$element->tagName, $attributes, $children, trim($children === [] ? $element->textContent : '')];
    };
    $limit = domElement(new DOMXPath($document), '/phpunit/php/ini[@name="memory_limit"]');
    $memoryLimit = $limit->getAttribute('value');
    $limit->parentNode?->removeChild($limit);

    return ['memory_limit' => $memoryLimit, 'configuration' => $nodeValue($document->documentElement)];
}

it('gives coverage workers a larger memory budget with equivalent test configuration', function (): void {
    $root = dirname(__DIR__, 2);
    $main = coverageConfigurationSemantics($root . '/phpunit.xml');
    $coverage = coverageConfigurationSemantics($root . '/phpunit-coverage.xml');

    expect($main['memory_limit'])->toBe('2G')
        ->and($coverage['memory_limit'])->toBe('8G')
        ->and($coverage['configuration'])->toEqual($main['configuration']);

    // PHPUnit overrides CLI limits; competing declarations misrepresent worker budgets.
    foreach (coverageComposerScripts() as $commands) {
        foreach ((array) $commands as $command) {
            if (is_string($command) && str_contains($command, 'vendor/bin/pest')) {
                expect($command)->not->toContain('memory_limit');
            }
        }
    }

    foreach (coverageJobCommands('generate-coverage') as $command) {
        expect($command)->not->toContain('memory_limit');
    }

    // The profiler launches workers too and must leave their budget to PHPUnit.
    expect((string) file_get_contents($root . '/scripts/profile-pest-tests.php'))->not->toContain('memory_limit');
});

it('runs coverage and mutation commands with the coverage worker configuration', function (): void {
    foreach (['coverage', 'coverage-report', 'coverage:blade', 'test:mutate', 'test:mutate:ci'] as $name) {
        $commands = array_filter((array) coverageComposerScripts()[$name], static fn (string $command): bool => str_contains($command, 'vendor/bin/pest'));
        expect($commands)->not->toBeEmpty();
        foreach ($commands as $command) {
            // These executable flags select the worker budget, not source formatting.
            expect($command)->toContain('--configuration=phpunit-coverage.xml')
                ->not->toContain('--configuration=phpunit.xml');
        }
    }

    expect(implode("\n", coverageJobCommands('generate-coverage')))->toContain('--configuration=phpunit-coverage.xml');
});

it('optimizes release coverage with an in-memory cache and the runtime role enabled', function (): void {
    $workflow = coverageWorkflow();
    $environment = array_replace($workflow['env'], $workflow['jobs']['generate-coverage']['env']);
    expect($environment['CACHE_STORE'])->toBe('array')
        ->and($environment['CAPELL_TESTBENCH_RUNTIME_ROLE'])->toBeTrue();
    // The wrapper bootstraps a cache-safe Testbench environment before optimizing.
    expect(implode("\n", coverageJobCommands('generate-coverage')))->toContain('run-testbench-command.php optimize')
        ->not->toContain('vendor/bin/testbench optimize');
});

it('shards coverage in parallel and gates publication on merged coverage', function (): void {
    $workflow = coverageWorkflow();
    $commands = implode("\n", coverageJobCommands('generate-coverage'));
    $shards = $workflow['jobs']['generate-coverage']['strategy']['matrix']['shard'];
    expect($shards)->toEqual(range(1, count($shards)));
    // Serial coverage exceeds the release budget; each shard needs its own report.
    expect($commands)->toContain('--parallel')
        ->toContain('--shard=${{ matrix.shard }}/' . count($shards))
        ->toContain('--coverage-clover=coverage/clover-${{ matrix.shard }}.xml')
        ->not->toContain('--coverage-php')
        ->and($workflow['jobs']['merge-coverage']['needs'])->toBe('generate-coverage')
        ->and($workflow['jobs']['publish-coverage']['needs'])->toBe('merge-coverage');
    expect(implode("\n", coverageJobCommands('merge-coverage')))->toContain('scripts/merge-clover-coverage.php');
    foreach (['coverage', 'coverage-report'] as $name) {
        $command = implode("\n", (array) coverageComposerScripts()[$name]);
        expect($command)->toContain('--parallel')->not->toContain('--coverage-php');
    }
});

it('writes Clover and HTML reports directly from Composer coverage commands', function (): void {
    // Clover is consumed by the threshold merger; HTML is the human-readable report.
    expect(implode("\n", (array) coverageComposerScripts()['coverage']))
        ->toContain('--coverage-clover=.cache/phpunit/coverage-clover.xml')
        ->toContain('scripts/merge-clover-coverage.php')
        ->and(implode("\n", (array) coverageComposerScripts()['coverage-report']))
        ->toContain('--coverage-html=coverage');
});

it('merges Clover statement hits before enforcing the release threshold', function (): void {
    $root = dirname(__DIR__, 2);
    $temporaryDirectory = sys_get_temp_dir() . '/capell-clover-' . bin2hex(random_bytes(8));
    mkdir($temporaryDirectory, 0777, true);

    $report = static fn (int $firstCount, int $secondCount): string => <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <coverage generated="1">
          <project timestamp="1">
            <file name="Example.php">
              <line num="10" type="stmt" count="{$firstCount}"/>
              <line num="20" type="stmt" count="{$secondCount}"/>
              <metrics methods="0" coveredmethods="0" statements="2" coveredstatements="1" elements="2" coveredelements="1"/>
            </file>
            <metrics files="1" methods="0" coveredmethods="0" statements="2" coveredstatements="1" elements="2" coveredelements="1"/>
          </project>
        </coverage>
        XML;

    file_put_contents($temporaryDirectory . '/one.xml', $report(1, 0));
    file_put_contents($temporaryDirectory . '/two.xml', $report(0, 1));

    $command = sprintf(
        '%s %s --output %s %s %s 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($root . '/scripts/merge-clover-coverage.php'),
        escapeshellarg($temporaryDirectory . '/merged.xml'),
        escapeshellarg($temporaryDirectory . '/one.xml'),
        escapeshellarg($temporaryDirectory . '/two.xml'),
    );
    exec($command, $output, $exitCode);

    $merged = new DOMDocument;
    $merged->load($temporaryDirectory . '/merged.xml');

    $xpath = new DOMXPath($merged);

    $summary = implode("\n", $output);
    $coveredStatements = $xpath->evaluate('string(//project/metrics/@coveredstatements)');
    $firstLineCount = $xpath->evaluate('string(//line[@num="10"]/@count)');
    $secondLineCount = $xpath->evaluate('string(//line[@num="20"]/@count)');

    unlink($temporaryDirectory . '/one.xml');
    unlink($temporaryDirectory . '/two.xml');
    unlink($temporaryDirectory . '/merged.xml');
    rmdir($temporaryDirectory);

    expect($exitCode)->toBe(0)
        ->and($summary)->toContain('2/2 statements covered (100.00%)')
        ->and($coveredStatements)->toBe('2')
        ->and($firstLineCount)->toBe('1')
        ->and($secondLineCount)->toBe('1');
});
