<?php

declare(strict_types=1);

namespace Capell\Release;

final readonly class BuildReleaseNotesAction
{
    public function __construct(private CommandRunner $runner, private string $root) {}

    public function handle(string $fromTag, string $toTag): string
    {
        foreach ([$fromTag, $toTag] as $tag) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', $tag) !== 1 || str_contains($tag, '..')) {
                throw new ReleaseException('Invalid release notes tag.');
            }

            $this->run(['git', 'rev-parse', '--verify', 'refs/tags/' . $tag . '^{commit}']);
        }

        return $this->build('refs/tags/' . $fromTag, 'refs/tags/' . $toTag);
    }

    public function handlePlannedCommit(string $fromTag, string $commit): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', $fromTag) !== 1 || str_contains($fromTag, '..') || preg_match('/^[a-f0-9]{40}$/', $commit) !== 1) {
            throw new ReleaseException('Invalid release notes range.');
        }

        $this->run(['git', 'rev-parse', '--verify', 'refs/tags/' . $fromTag . '^{commit}']);
        $this->run(['git', 'rev-parse', '--verify', $commit . '^{commit}']);

        return $this->build('refs/tags/' . $fromTag, $commit);
    }

    private function build(string $from, string $to): string
    {
        $this->run(['git', 'merge-base', '--is-ancestor', $from, $to]);
        $subjects = $this->run(['git', 'log', '--no-merges', '--format=%s', $from . '..' . $to]);
        $groups = ['Breaking changes' => [], 'Added' => [], 'Fixed' => [], 'Changed' => []];
        foreach (preg_split('/\R/', $subjects) ?: [] as $subject) {
            $subject = trim($subject);
            if ($subject === '') {
                continue;
            }

            $group = 'Changed';
            if (preg_match('/^[a-z]+(?:\([^\r\n)]*\))?!: /i', $subject)) {
                $group = 'Breaking changes';
            } elseif (preg_match('/^feat(?:\([^\r\n)]*\))?: /i', $subject)) {
                $group = 'Added';
            } elseif (preg_match('/^fix(?:\([^\r\n)]*\))?: /i', $subject)) {
                $group = 'Fixed';
            }

            $groups[$group][] = '- ' . $subject;
        }

        $notes = [];
        foreach ($groups as $heading => $items) {
            if ($items !== []) {
                $notes[] = '## ' . $heading . "\n\n" . implode("\n", $items);
            }
        }

        return $notes === [] ? 'No non-merge changes in this range.' : implode("\n\n", $notes);
    }

    /** @param list<string> $command */
    private function run(array $command): string
    {
        $result = $this->runner->run($command, $this->root);
        if ($result['exitCode'] !== 0) {
            throw new ReleaseException('Unable to build release notes: tag range is unavailable or is not ancestral.');
        }

        return $result['output'];
    }
}

/** Replaces GitHub's split-history notes with notes from the source tag range. */
final class SourceReleaseNotesRunner implements CommandRunner
{
    /** @var array<string, string> */
    private array $notes = [];

    /** @param array<string, mixed> $plan */
    public function __construct(private readonly CommandRunner $runner, string $root, array $plan)
    {
        foreach ($plan['packages'] as $package) {
            if ($package['current_version'] !== null) {
                $previousTag = basename($package['path']) . '/v' . $package['current_version'];
                $this->notes[$package['split_repository']] = new BuildReleaseNotesAction($runner, $root)->handlePlannedCommit($previousTag, $plan['source']['commit']);
            }
        }
    }

    public function run(array $command, ?string $workingDirectory = null): array
    {
        if (array_slice($command, 0, 3) === ['gh', 'release', 'create']) {
            $repoIndex = array_search('--repo', $command, true);
            $repository = $repoIndex === false ? null : ($command[$repoIndex + 1] ?? null);
            if (is_string($repository) && isset($this->notes[$repository])) {
                $index = array_search('--generate-notes', $command, true);
                if ($index !== false) {
                    array_splice($command, $index, 1, ['--notes', $this->notes[$repository]]);
                }
            }
        }

        return $this->runner->run($command, $workingDirectory);
    }
}
