<?php

declare(strict_types=1);

use Capell\Marketplace\Support\MarketplaceComposerAuthWorkspace;
use Capell\Marketplace\Support\WorkspaceException;
use Capell\Marketplace\Support\WorkspaceFailure;
use Capell\Marketplace\Tests\Support\FakeWorkspacePathInspector;

it('rejects foreign and group writable higher ancestors before creating the root', function (): void {
    $parent = sys_get_temp_dir() . '/workspace-trust-' . bin2hex(random_bytes(8));
    mkdir($parent . '/child', 0700, true);
    $root = $parent . '/child/homes';
    config()->set('capell.marketplace.auth_workspace_path', $root);
    $inspector = new FakeWorkspacePathInspector;
    $workspace = new MarketplaceComposerAuthWorkspace(inspector: $inspector);
    try {
        $inspector->foreign = $parent;
        expect(fn (): array => $workspace->stale())->toThrow(WorkspaceException::class, $parent);
        $inspector->foreign = null;
        $inspector->groupWritable = $parent;
        expect(fn (): string => $workspace->create())->toThrow(WorkspaceException::class, $parent)
            ->and(file_exists($root))->toBeFalse();
    } finally {
        rmdir($parent . '/child');
        rmdir($parent);
    }
});

it('revalidates a competing first creation and rejects an untrusted one', function (): void {
    $parent = sys_get_temp_dir() . '/workspace-race-' . bin2hex(random_bytes(8));
    mkdir($parent, 0700);
    $root = $parent . '/homes';
    config()->set('capell.marketplace.auth_workspace_path', $root);
    $inspector = new FakeWorkspacePathInspector;
    $inspector->racedRoot = $root;

    $workspace = new MarketplaceComposerAuthWorkspace(inspector: $inspector);
    try {
        $home = $workspace->create();
        expect(dirname($home))->toBe($root);
        $workspace->removeDirectory($home);
        rmdir($root);
        $inspector->groupWritable = $root;
        expect(fn (): string => $workspace->create())->toThrow(WorkspaceException::class, $root);
    } finally {
        if (is_dir($root)) {
            rmdir($root);
        }

        rmdir($parent);
    }
});

it('reports failed scans and metadata as indeterminate', function (): void {
    $parent = sys_get_temp_dir() . '/workspace-scan-' . bin2hex(random_bytes(8));
    mkdir($parent . '/homes/marketplace-auth-test', 0700, true);
    config()->set('capell.marketplace.auth_workspace_path', $parent . '/homes');
    $inspector = new FakeWorkspacePathInspector;
    $workspace = new MarketplaceComposerAuthWorkspace(inspector: $inspector);
    try {
        $inspector->scanFails = true;
        try {
            $workspace->stale();
            test()->fail('The scan must be indeterminate.');
        } catch (WorkspaceException $exception) {
            expect($exception->failure)->toBe(WorkspaceFailure::Indeterminate);
        }

        $inspector->scanFails = false;
        $inspector->metadataFails = true;
        expect(fn (): array => $workspace->stale())->toThrow(WorkspaceException::class, __('capell-marketplace::marketplace.auth_workspace.indeterminate'));
    } finally {
        rmdir($parent . '/homes/marketplace-auth-test');
        rmdir($parent . '/homes');
        rmdir($parent);
    }
});

it('requires an explicit ACL override when POSIX identity is unavailable', function (): void {
    $parent = sys_get_temp_dir() . '/workspace-acl-' . bin2hex(random_bytes(8));
    mkdir($parent, 0700);
    $root = $parent . '/homes';
    config()->set('capell.marketplace.auth_workspace_path', $root);
    $inspector = new FakeWorkspacePathInspector;
    $inspector->uid = null;

    $workspace = new MarketplaceComposerAuthWorkspace(inspector: $inspector);
    try {
        expect(fn (): array => $workspace->stale())->toThrow(WorkspaceException::class, __('capell-marketplace::marketplace.auth_workspace.trust_mode'));
        config()->set('capell.marketplace.auth_workspace_trust', 'acl');
        $inspector->simulateAclModes = true;
        expect($workspace->stale())->toBe([]);
        config()->set('capell.marketplace.auth_workspace_path');
        expect(fn (): array => $workspace->stale())->toThrow(WorkspaceException::class, __('capell-marketplace::marketplace.auth_workspace.trust_mode'));
    } finally {
        rmdir($parent);
    }
});

it('checks the resolved ancestors of a storage symlink', function (): void {
    $parent = sys_get_temp_dir() . '/workspace-link-' . bin2hex(random_bytes(8));
    mkdir($parent . '/target/framework', 0700, true);
    mkdir($parent . '/source', 0700);
    symlink($parent . '/target', $parent . '/source/storage');
    $root = $parent . '/source/storage/framework/homes';
    config()->set('capell.marketplace.auth_workspace_path', $root);
    $inspector = new FakeWorkspacePathInspector;
    $inspector->foreign = (string) realpath($parent . '/target');
    try {
        expect(fn (): array => new MarketplaceComposerAuthWorkspace(inspector: $inspector)->stale())
            ->toThrow(WorkspaceException::class, $inspector->foreign)
            ->and(file_exists($root))->toBeFalse();
    } finally {
        unlink($parent . '/source/storage');
        rmdir($parent . '/source');
        rmdir($parent . '/target/framework');
        rmdir($parent . '/target');
        rmdir($parent);
    }
});
