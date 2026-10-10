<?php

declare(strict_types=1);

use Capell\Tests\Support\CommandFixture;
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

});

it('profiles tests using the repository worker configuration', function (): void {
    $fixture = new CommandFixture;
    $fixture->files->copy('scripts/profile-pest-tests.php');
    $fixture->files->write('ExampleTest.php', '<?php');
    $fixture->files->write('vendor/bin/pest', <<<'PHP'
        <?php
        $configuration = simplexml_load_file('phpunit.xml');
        $limit = (string) $configuration->xpath('/phpunit/php/ini[@name="memory_limit"]')[0]['value'];
        echo json_encode(['arguments' => array_slice($argv, 1), 'memory_limit' => $limit], JSON_THROW_ON_ERROR), "\n";
        PHP);
    $fixture->files->copy('phpunit.xml');
    try {
        $process = $fixture->run([PHP_BINARY, 'scripts/profile-pest-tests.php', 'ExampleTest.php']);
        expect($process->getExitCode())->toBe(0);
        $result = json_decode(explode("\n", trim($process->getOutput()))[0], true, flags: JSON_THROW_ON_ERROR);
        expect($result['arguments'])->toBe(['ExampleTest.php', '--configuration=phpunit.xml', '--compact'])
            ->and($result['memory_limit'])->toBe('2G');
    } finally {
        $fixture->close();
    }
});

it('dispatches coverage and mutation workers with the coverage configuration', function (string $name): void {
    $fixture = new CommandFixture;
    $fixture->fake('php');
    try {
        foreach ((array) coverageComposerScripts()[$name] as $command) {
            $process = $fixture->run(['bash', '-c', str_replace('@php ', 'php ', $command)]);
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
        }

        $workers = array_values(array_filter($fixture->calls('php'), static fn (array $call): bool => in_array('vendor/bin/pest', $call['arguments'], true)));
        expect($workers)->toHaveCount(1);
        expect($workers[0]['arguments'])->toContain('--configuration=phpunit-coverage.xml')
            ->not->toContain('--configuration=phpunit.xml');
        if (str_starts_with($name, 'coverage')) {
            expect($workers[0]['arguments'])->toContain('--parallel');
        }

        if ($name === 'coverage') {
            expect($workers[0]['arguments'])->toContain('--coverage-clover=.cache/phpunit/coverage-clover.xml');
        }

        if ($name === 'coverage-report') {
            expect($workers[0]['arguments'])->toContain('--coverage-html=coverage');
        }
    } finally {
        $fixture->close();
    }
})->with(['coverage', 'coverage-report', 'coverage:blade', 'test:mutate', 'test:mutate:ci']);

it('dispatches one independent Clover report per release shard before merging and publication', function (): void {
    $workflow = coverageWorkflow();
    $environment = array_replace($workflow['env'], $workflow['jobs']['generate-coverage']['env']);
    expect($environment['CACHE_STORE'])->toBe('array')
        ->and($environment['CAPELL_TESTBENCH_RUNTIME_ROLE'])->toBeTrue()
        ->and($workflow['jobs']['merge-coverage']['needs'])->toBe('generate-coverage')
        ->and($workflow['jobs']['publish-coverage']['needs'])->toBe('merge-coverage');
    $shards = $workflow['jobs']['generate-coverage']['strategy']['matrix']['shard'];
    expect($shards)->toEqual(range(1, count($shards)));
    foreach ($shards as $shard) {
        $fixture = new CommandFixture;
        $fixture->fake('php');
        try {
            $commands = implode("\n", array_filter(coverageJobCommands('generate-coverage'), static fn (string $command): bool => str_contains($command, 'vendor/bin/pest')));
            $process = $fixture->run(['bash', '-c', str_replace('${{ matrix.shard }}', (string) $shard, $commands)]);
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
            $calls = array_column($fixture->calls('php'), 'arguments');
            expect($calls)->toContain(['scripts/run-testbench-command.php', 'optimize', '--except=routes', '--ansi']);
            $workers = array_values(array_filter($calls, static fn (array $arguments): bool => in_array('vendor/bin/pest', $arguments, true)));
            expect($workers)->toHaveCount(1)
                ->and($workers[0])->toContain('--parallel', '--shard=' . $shard . '/' . count($shards), '--coverage-clover=coverage/clover-' . $shard . '.xml', '--configuration=phpunit-coverage.xml');
        } finally {
            $fixture->close();
        }
    }
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
