<?php

declare(strict_types=1);

use Capell\Tests\Support\CommandFixture;
use Symfony\Component\Yaml\Yaml;

it('forwards split package contents into the Core monorepo without executing fork code', function (): void {
    $root = dirname(__DIR__, 2);
    /** @var list<array{path: string}> $packages */
    $packages = json_decode((string) file_get_contents($root . '/config/release-packages.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach ($packages as $package) {
        /** @var array{jobs: array{forward: array{if: string, env: array<string, string>, steps: list<array{name: string, uses?: string, with?: array<string, mixed>, run?: string}>}}} $workflow */
        $workflow = Yaml::parseFile($root . '/' . $package['path'] . '/.github/workflows/forward-pr-to-monorepo.yml');
        $job = $workflow['jobs']['forward'];
        expect($job['env'])->toMatchArray(['MONOREPO_REPOSITORY' => 'capell-app/capell', 'MONOREPO_BASE' => '1.x']);
        // Prevent recursive forwarding when this workflow is copied into the monorepo.
        expect($job['if'])->toContain("github.event.repository.name != 'capell'");
        $steps = array_column($job['steps'], null, 'name');
        $source = $steps['Checkout split PR contents'];
        $monorepo = $steps['Checkout Capell monorepo'];
        throw_unless(isset($source['with'], $monorepo['with']), RuntimeException::class, 'Both forwarding checkouts need explicit options.');
        expect($source['with'])->toMatchArray([
            'repository' => '${{ github.event.pull_request.head.repo.full_name }}',
            'ref' => '${{ github.event.pull_request.head.sha }}',
            'persist-credentials' => false,
            'allow-unsafe-pr-checkout' => true,
        ])->not->toHaveKey('token');
        expect($monorepo['with'])->toMatchArray(['repository' => 'capell-app/capell', 'ref' => '1.x'])
            ->not->toHaveKey('allow-unsafe-pr-checkout');

        $fixture = new CommandFixture;
        $fixture->files->write('split/content.txt', 'Forwarded content');
        $fixture->files->write('split/.git/config', 'private checkout credentials');
        $fixture->files->write('split/package.json', '{"scripts":{"test":"touch executed-fork"}}');
        $fixture->files->write('monorepo/packages/core/stale.txt', 'Replace stale content');
        $fixture->fake('git', 'if (in_array("status", $argv, true)) { echo "M package"; }');
        $fixture->fake('gh', 'if (in_array("create", $argv, true)) { echo "https://example.test/pull/1"; }');
        try {
            $process = $fixture->run(['bash', '-c', $steps['Create or update monorepo PR']['run'] ?? ''], [
                'PACKAGE_NAME' => 'core', 'SOURCE_PR_NUMBER' => '42',
                'SOURCE_PR_TITLE' => 'Copy package', 'SOURCE_PR_AUTHOR' => 'author',
                'SOURCE_PR_URL' => 'https://example.test/pull/42',
                'SOURCE_PR_BASE' => 'main', 'SOURCE_PR_HEAD' => 'feature',
                'SOURCE_REPOSITORY' => 'example/core',
                'MONOREPO_REPOSITORY' => $job['env']['MONOREPO_REPOSITORY'],
                'MONOREPO_BASE' => $job['env']['MONOREPO_BASE'],
            ]);
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
                ->and(file_get_contents($fixture->files->root . '/monorepo/packages/core/content.txt'))->toBe('Forwarded content')
                ->and($fixture->files->root . '/monorepo/packages/core/stale.txt')->not->toBeFile()
                ->and($fixture->files->root . '/monorepo/packages/core/.git')->not->toBeDirectory()
                ->and($fixture->calls('composer'))->toBe([])
                ->and($fixture->calls('npm'))->toBe([])
                ->and($fixture->files->root . '/split/executed-fork')->not->toBeFile();
            expect(array_column($fixture->calls('gh'), 'arguments'))->toContain([
                'pr', 'comment', '42', '--repo', 'example/core', '--body', 'Forwarded to https://example.test/pull/1.',
            ]);
        } finally {
            $fixture->close();
        }
    }
});
