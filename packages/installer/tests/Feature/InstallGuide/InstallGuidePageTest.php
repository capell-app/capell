<?php

declare(strict_types=1);

use Capell\Core\Models\Site;
use Capell\Core\Support\Patching\Patch;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Installer\Filament\Pages\InstallGuidePage;
use Capell\Installer\Support\InstallGuide\PatchRegistry;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;

uses(CreatesAdminUser::class)->group('installer');

beforeEach(function (): void {
    $this->app->instance(PatchRegistry::class, new PatchRegistry);
});

it('mounts grouped install guide patches and preselects applicable defaults', function (): void {
    test()->actingAsAdmin();

    registerInstallGuidePagePatch(
        id: 'env-queue',
        group: 'environment',
        status: PatchStatus::Applicable,
        defaultEnabled: true,
    );
    registerInstallGuidePagePatch(
        id: 'custom-theme',
        group: 'filament',
        status: PatchStatus::Customised,
        defaultEnabled: true,
        reason: 'Already customised by the host app.',
    );
    registerInstallGuidePagePatch(
        id: 'manual-only',
        group: 'filament',
        status: PatchStatus::Applicable,
        defaultEnabled: false,
    );

    Livewire::test(InstallGuidePage::class)
        ->assertSet('selectedPatches', ['env-queue'])
        ->assertSet('data.selectedPatches', ['env-queue'])
        ->assertSet('data.patches.environment.0.id', 'env-queue')
        ->assertSet('data.patches.filament.0.id', 'custom-theme')
        ->assertSet('data.patches.filament.0.reason', 'Already customised by the host app.')
        ->assertSet('data.patches.filament.1.id', 'manual-only');
});

it('warns when the install guide apply action is submitted without selected patches', function (): void {
    test()->actingAsAdmin();
    registerInstallGuidePagePatch(
        id: 'env-queue',
        group: 'environment',
        status: PatchStatus::Applicable,
        defaultEnabled: false,
    );

    Livewire::test(InstallGuidePage::class)
        ->set('data.selectedPatches', [])
        ->call('applyPatches')
        ->assertNotified(__('capell-installer::install-guide.no_patches_selected'));
});

it('applies selected install guide patches and reports success skipped and manual failures', function (): void {
    test()->actingAsAdmin();

    $appliedPatchCalls = 0;
    $skippedPatchCalls = 0;
    $failedPatchCalls = 0;

    registerInstallGuidePagePatch(
        id: 'queue',
        group: 'environment',
        status: PatchStatus::Applicable,
        statusAfterApply: PatchStatus::AlreadyApplied,
        applyCallback: static function () use (&$appliedPatchCalls): void {
            $appliedPatchCalls++;
        },
    );
    registerInstallGuidePagePatch(
        id: 'custom-theme',
        group: 'filament',
        status: PatchStatus::Customised,
        statusAfterApply: PatchStatus::Customised,
        applyCallback: static function () use (&$skippedPatchCalls): void {
            $skippedPatchCalls++;
        },
    );
    registerInstallGuidePagePatch(
        id: 'protected-file',
        group: 'filament',
        status: PatchStatus::Applicable,
        applyCallback: static function () use (&$failedPatchCalls): void {
            $failedPatchCalls++;

            throw new RuntimeException('Cannot write app/Models/User.php.');
        },
    );

    Livewire::test(InstallGuidePage::class)
        ->set('data.selectedPatches', ['queue', 'custom-theme', 'protected-file'])
        ->call('applyPatches')
        ->assertNotified('Install guide patch queue')
        ->assertSet('data.selectedPatches', ['protected-file']);

    expect($appliedPatchCalls)->toBe(1)
        ->and($skippedPatchCalls)->toBe(1)
        ->and($failedPatchCalls)->toBe(1);
});

it('keeps the install guide inaccessible to guests and installed sites', function (): void {
    expect(InstallGuidePage::canAccess())->toBeFalse();

    test()->actingAsAdmin();

    expect(InstallGuidePage::canAccess())->toBeTrue()
        ->and(InstallGuidePage::shouldRegisterNavigation())->toBeTrue();

    Site::factory()->createOne();

    expect(InstallGuidePage::shouldRegisterNavigation())->toBeFalse();
});

/**
 * @param  null|callable(): void  $applyCallback
 */
function registerInstallGuidePagePatch(
    string $id,
    string $group,
    PatchStatus $status,
    ?PatchStatus $statusAfterApply = null,
    bool $defaultEnabled = true,
    ?string $reason = null,
    ?callable $applyCallback = null,
): void {
    resolve(PatchRegistry::class)->register(new class($id, $group, $status, $statusAfterApply ?? $status, $defaultEnabled, $reason, $applyCallback) implements Patch
    {
        /**
         * @param  null|callable(): void  $applyCallback
         */
        public function __construct(
            private readonly string $id,
            private readonly string $group,
            private PatchStatus $status,
            private readonly PatchStatus $statusAfterApply,
            private readonly bool $defaultEnabled,
            private readonly ?string $reason,
            private readonly mixed $applyCallback,
        ) {}

        public function id(): string
        {
            return $this->id;
        }

        public function group(): string
        {
            return $this->group;
        }

        public function label(): string
        {
            return 'Install guide patch ' . $this->id;
        }

        public function description(): string
        {
            return 'Patch description for ' . $this->id;
        }

        public function docUrl(): string
        {
            return 'https://docs.capell.app/' . $this->id;
        }

        public function defaultEnabled(): bool
        {
            return $this->defaultEnabled;
        }

        public function probe(): PatchStatus
        {
            return $this->status;
        }

        public function reason(): ?string
        {
            return $this->reason;
        }

        public function apply(): void
        {
            $callback = $this->applyCallback;

            if (is_callable($callback)) {
                $callback();
            }

            $this->status = $this->statusAfterApply;
        }
    });
}
