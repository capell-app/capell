<?php

declare(strict_types=1);

/**
 * @param  array<string, string>  $environment
 * @return array<string, mixed>
 */
function reloadCapellConfig(array $environment = []): array
{
    $projectRoot = dirname(__FILE__, 6);
    $originalEnv = $_ENV;
    $originalServer = $_SERVER;

    try {
        // Testbench's runtime-role bootstrap disables Laravel's putenv adapter.
        // Populate its supported environment readers and restore them afterwards.
        $_ENV = array_replace($_ENV, $environment);
        $_SERVER = array_replace($_SERVER, $environment);

        return require $projectRoot . '/packages/core/config/capell.php';
    } finally {
        $_ENV = $originalEnv;
        $_SERVER = $originalServer;
    }
}

it('exposes default role names under capell.roles', function (): void {
    $config = reloadCapellConfig();
    expect($config['roles']['admin'])->toBe('admin');
    expect($config['roles']['editor'])->toBe('editor');
});

it('exposes a toggle for the developer dashboard page defaulting to true', function (): void {
    expect(reloadCapellConfig()['dashboard']['developer_page_enabled'])->toBeTrue();
});

it('resolves admin role from CAPELL_ADMIN_ROLE env', function (): void {
    expect(reloadCapellConfig(['CAPELL_ADMIN_ROLE' => 'site-admin'])['roles']['admin'])->toBe('site-admin');
});

it('resolves editor role from CAPELL_EDITOR_ROLE env', function (): void {
    expect(reloadCapellConfig(['CAPELL_EDITOR_ROLE' => 'content-editor'])['roles']['editor'])->toBe('content-editor');
});

it('coerces CAPELL_DEVELOPER_PAGE=false env value to boolean false', function (): void {
    expect(reloadCapellConfig(['CAPELL_DEVELOPER_PAGE' => 'false'])['dashboard']['developer_page_enabled'])->toBeFalse();
});

it('coerces CAPELL_DEVELOPER_PAGE=true env value to boolean true', function (): void {
    expect(reloadCapellConfig(['CAPELL_DEVELOPER_PAGE' => 'true'])['dashboard']['developer_page_enabled'])->toBeTrue();
});
