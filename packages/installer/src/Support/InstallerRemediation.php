<?php

declare(strict_types=1);

namespace Capell\Installer\Support;

use Capell\Core\Contracts\ProgressReporter;

final class InstallerRemediation
{
    /** @param array<string, mixed> $preflight */
    public function reportPreflight(array $preflight, ProgressReporter $reporter): void
    {
        foreach (($preflight['checks'] ?? []) as $check) {
            if (! is_array($check)) {
                continue;
            }

            $status = (string) ($check['status'] ?? 'warning');
            $label = (string) ($check['label'] ?? 'Check');
            $message = (string) ($check['message'] ?? '');
            $line = sprintf('%s %s: %s', $this->preflightMarker($status), $label, $message);

            if ($status === 'fail') {
                $reporter->error($line);
                $remediation = (string) ($check['remediation'] ?? '');
                if ($remediation !== '') {
                    $reporter->error('  Fix: ' . $remediation);
                }

                continue;
            }

            $reporter->report($line);
        }
    }

    public function preflightMarker(string $status): string
    {
        return match ($status) {
            'pass' => '✓',
            'fail' => '✗',
            default => '!',
        };
    }

    /** @param array<string, mixed> $preflight */
    public function preflightRemediation(array $preflight): string
    {
        $checks = $preflight['checks'] ?? [];

        if (! is_array($checks)) {
            return '';
        }

        /** @var array<int, array<string, mixed>> $checks */
        return collect($checks)
            ->filter(fn (array $check): bool => ($check['status'] ?? null) === 'fail')
            ->map(fn (array $check): string => (string) ($check['remediation'] ?? 'Review the failed preflight check.'))
            ->filter()
            ->unique()
            ->implode(' ');
    }

    public function remediationFor(string $message): ?string
    {
        $message = strtolower($message);

        return match (true) {
            str_contains($message, 'assignrole') => 'Update the application User model to use Spatie Permission HasRoles before installing the admin package.',
            str_contains($message, 'proc_open') => 'Enable proc_open for the web PHP runtime so Composer and Artisan subprocesses can run.',
            str_contains($message, 'git@github.com'), str_contains($message, 'publickey') => 'Composer attempted an SSH GitHub clone. Use HTTPS repository URLs or configure GitHub SSH access for the web user.',
            str_contains($message, 'access denied'), str_contains($message, 'permission denied') => 'Check database credentials and filesystem permissions for the web PHP user.',
            str_contains($message, 'unknown database') => 'Grant CREATE DATABASE permission or create the configured database manually.',
            str_contains($message, 'settings') && str_contains($message, 'base table') => 'Publish and run vendor migrations for spatie/laravel-settings before running Capell settings migrations.',
            default => null,
        };
    }

    /**
     * @param  array<int, mixed>  $lines
     * @return array<int, string>
     */
    public function remediationsForLines(array $lines): array
    {
        return collect($lines)
            ->map(fn (mixed $line): ?string => is_array($line) ? $this->remediationFor((string) ($line['line'] ?? '')) : null)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
