<?php

declare(strict_types=1);

namespace Capell\Tests\Support;

use InvalidArgumentException;
use Symfony\Component\Process\Process;

final class SiblingRepositoryLocator
{
    public static function path(string $root, string $repository): ?string
    {
        $variables = match ($repository) {
            'capell-packages-4' => ['CAPELL_PACKAGES_REPO_PATH', 'CAPELL_PACKAGES_ROOT'],
            'capell-app' => ['CAPELL_APPLICATION_ROOT', 'CAPELL_RELEASE_SKELETON_ROOT'],
            default => throw new InvalidArgumentException(sprintf('Unknown sibling repository [%s].', $repository)),
        };

        foreach ($variables as $variable) {
            $configured = getenv($variable);

            if (is_string($configured) && $configured !== '') {
                // An explicit override is authoritative; do not fall back to another checkout.
                return self::checkout($configured);
            }
        }

        $candidates = [$root . '/' . $repository, $root . '/repositories/' . $repository];
        $candidates[] = dirname($root) . '/' . $repository;

        // Match init-worktree.sh: the common Git directory locates the primary checkout.
        $process = new Process(['git', '-C', $root, 'rev-parse', '--git-common-dir']);
        $process->mustRun();

        $common = trim($process->getOutput());
        $primary = dirname(str_starts_with($common, '/') ? $common : $root . '/' . $common);
        $candidates[] = dirname($primary) . '/' . $repository;

        if ($repository === 'capell-app') {
            $candidates[] = dirname($primary, 3) . '/capell-app';
        }

        foreach (array_unique($candidates) as $candidate) {
            $checkout = self::checkout($candidate);

            if ($checkout !== null) {
                return $checkout;
            }
        }

        return null;
    }

    private static function checkout(string $path): ?string
    {
        $path = rtrim($path, '/');

        return is_file($path . '/composer.json') && file_exists($path . '/.git') ? $path : null;
    }
}
