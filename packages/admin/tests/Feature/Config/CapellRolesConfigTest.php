<?php

declare(strict_types=1);

/**
 * @return array<string, mixed>
 */
function reloadCapellConfig(): array
{
    $projectRoot = dirname(__FILE__, 6);

    return require $projectRoot . '/packages/core/config/capell.php';
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
    putenv('CAPELL_ADMIN_ROLE=site-admin');
    try {
        expect(reloadCapellConfig()['roles']['admin'])->toBe('site-admin');
    } finally {
        putenv('CAPELL_ADMIN_ROLE');
    }
});

it('resolves editor role from CAPELL_EDITOR_ROLE env', function (): void {
    putenv('CAPELL_EDITOR_ROLE=content-editor');
    try {
        expect(reloadCapellConfig()['roles']['editor'])->toBe('content-editor');
    } finally {
        putenv('CAPELL_EDITOR_ROLE');
    }
});

it('coerces CAPELL_DEVELOPER_PAGE=false env value to boolean false', function (): void {
    putenv('CAPELL_DEVELOPER_PAGE=false');
    try {
        expect(reloadCapellConfig()['dashboard']['developer_page_enabled'])->toBeFalse();
    } finally {
        putenv('CAPELL_DEVELOPER_PAGE');
    }
});

it('coerces CAPELL_DEVELOPER_PAGE=true env value to boolean true', function (): void {
    putenv('CAPELL_DEVELOPER_PAGE=true');
    try {
        expect(reloadCapellConfig()['dashboard']['developer_page_enabled'])->toBeTrue();
    } finally {
        putenv('CAPELL_DEVELOPER_PAGE');
    }
});
