<?php

declare(strict_types=1);

it('keeps searchable install errors and hosting links aligned with runtime output', function (): void {
    $root = dirname(__DIR__, 2);
    $installGuide = (string) file_get_contents($root . '/docs/getting-started/install.md');
    $troubleshooting = (string) file_get_contents($root . '/docs/operations/troubleshooting.md');
    /** @var array<string, string> $installerMessages */
    $installerMessages = require $root . '/packages/installer/resources/lang/en/installer.php';

    expect($troubleshooting)
        ->toContain($installerMessages['server_timeout_error'])
        ->toContain('report that step as a defect')
        ->toContain('<a id="browser-install-timeout"></a>')
        ->toContain('<a id="queue-worker"></a>')
        ->toContain('<a id="scheduler"></a>')
        ->toContain('<a id="installation-logs"></a>')
        ->and($installGuide)
        ->toContain('../operations/troubleshooting.md#install-and-hosting')
        ->toContain('immutable 128 MB shared-hosting limits')
        ->toContain('../operations/troubleshooting.md#queue-worker')
        ->toContain('../operations/troubleshooting.md#scheduler')
        ->toContain('## Install-time write permissions');
});
