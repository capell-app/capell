<?php

declare(strict_types=1);

use Capell\Core\Support\Install\InstallMemoryLimit;

it('keeps searchable install errors and hosting links aligned with runtime output', function (): void {
    $root = dirname(__DIR__, 2);
    $installGuide = (string) file_get_contents($root . '/docs/getting-started/install.md');
    $troubleshooting = (string) file_get_contents($root . '/docs/operations/troubleshooting.md');
    /** @var array<string, string> $installerMessages */
    $installerMessages = require $root . '/packages/installer/resources/lang/en/installer.php';

    expect($troubleshooting)
        ->toContain((new InstallMemoryLimit)->failureMessage('128M'))
        ->toContain('Allowed memory size of 134217728 bytes exhausted (tried to allocate 4096 bytes)')
        ->toContain($installerMessages['server_timeout_error'])
        ->toContain('<a id="php-memory-limit"></a>')
        ->toContain('<a id="browser-install-timeout"></a>')
        ->toContain('<a id="queue-worker"></a>')
        ->toContain('<a id="scheduler"></a>')
        ->toContain('<a id="installation-logs"></a>')
        ->and($installGuide)
        ->toContain('../operations/troubleshooting.md#install-and-hosting')
        ->toContain('../operations/troubleshooting.md#php-memory-limit')
        ->toContain('../operations/troubleshooting.md#queue-worker')
        ->toContain('../operations/troubleshooting.md#scheduler')
        ->toContain('## Install-time write permissions');
});
