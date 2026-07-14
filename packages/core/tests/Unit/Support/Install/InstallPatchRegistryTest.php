<?php

declare(strict_types=1);

use Capell\Core\Support\Install\InstallPatchConfirmation;
use Capell\Core\Support\Install\InstallPatchContext;
use Capell\Core\Support\Install\InstallPatchRegistry;
use Capell\Core\Support\Patching\Patch;
use Capell\Core\Support\Patching\PatchStatus;

function makeInstallPatchRegistryTestPatch(string $patchId): Patch
{
    return new readonly class($patchId) implements Patch
    {
        public function __construct(private string $patchId) {}

        public function id(): string
        {
            return $this->patchId;
        }

        public function group(): string
        {
            return 'testing';
        }

        public function label(): string
        {
            return 'Test patch ' . $this->patchId;
        }

        public function description(): string
        {
            return 'A test patch.';
        }

        public function docUrl(): ?string
        {
            return null;
        }

        public function defaultEnabled(): bool
        {
            return true;
        }

        public function probe(): PatchStatus
        {
            return PatchStatus::Applicable;
        }

        public function reason(): ?string
        {
            return null;
        }

        public function apply(): void {}
    };
}

it('returns no patches when nothing is registered', function (): void {
    $registry = new InstallPatchRegistry;
    $context = new InstallPatchContext(packageNames: ['capell-app/admin'], hasFilamentAdminPanelProvider: true);

    expect($registry->patchesFor($context))->toBe([]);
});

it('yields registered patches whose factory matches the context, in registration order', function (): void {
    $registry = new InstallPatchRegistry;

    $registry->register(
        static fn (InstallPatchContext $context): ?Patch => $context->hasPackage('capell-app/admin')
            ? makeInstallPatchRegistryTestPatch('first-patch')
            : null,
    );
    $registry->register(
        static fn (InstallPatchContext $context): ?Patch => $context->hasPackage('capell-app/admin') && $context->hasFilamentAdminPanelProvider
            ? makeInstallPatchRegistryTestPatch('second-patch')
            : null,
    );

    $context = new InstallPatchContext(packageNames: ['capell-app/admin'], hasFilamentAdminPanelProvider: true);
    $registeredPatches = $registry->patchesFor($context);

    expect($registeredPatches)->toHaveCount(2)
        ->and($registeredPatches[0]->patch->id())->toBe('first-patch')
        ->and($registeredPatches[0]->confirmation)->toBeNull()
        ->and($registeredPatches[1]->patch->id())->toBe('second-patch');
});

it('skips factories that decline the context', function (): void {
    $registry = new InstallPatchRegistry;

    $registry->register(
        static fn (InstallPatchContext $context): ?Patch => $context->hasPackage('capell-app/admin')
            ? makeInstallPatchRegistryTestPatch('admin-only-patch')
            : null,
    );
    $registry->register(
        static fn (InstallPatchContext $context): ?Patch => $context->hasFilamentAdminPanelProvider
            ? makeInstallPatchRegistryTestPatch('panel-only-patch')
            : null,
    );

    $context = new InstallPatchContext(packageNames: ['capell-app/frontend'], hasFilamentAdminPanelProvider: true);
    $registeredPatches = $registry->patchesFor($context);

    expect($registeredPatches)->toHaveCount(1)
        ->and($registeredPatches[0]->patch->id())->toBe('panel-only-patch');
});

it('carries an optional confirmation alongside the registered patch', function (): void {
    $registry = new InstallPatchRegistry;
    $confirmation = new InstallPatchConfirmation(
        label: 'Apply the patch?',
        hint: 'Skipped automatically in some cases.',
        skippedMessage: '→ Skipped the patch.',
    );

    $registry->register(
        static fn (InstallPatchContext $context): Patch => makeInstallPatchRegistryTestPatch('confirmable-patch'),
        $confirmation,
    );

    $context = new InstallPatchContext(packageNames: [], hasFilamentAdminPanelProvider: false);
    $registeredPatches = $registry->patchesFor($context);

    expect($registeredPatches)->toHaveCount(1)
        ->and($registeredPatches[0]->confirmation)->toBe($confirmation)
        ->and($registeredPatches[0]->confirmation?->default)->toBeTrue();
});
