<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\PrepareInstallApplicationAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Install\InstallPatchConfirmation;
use Capell\Core\Support\Install\InstallPatchContext;
use Capell\Core\Support\Install\InstallPatchRegistry;
use Capell\Core\Support\Patching\Patch;
use Capell\Core\Support\Patching\PatchStatus;

beforeEach(function (): void {
    PrepareInstallApplicationAction::clearFake();
});

afterEach(function (): void {
    PrepareInstallApplicationAction::clearFake();
});

it('applies applicable install patches through the action entrypoint', function (): void {
    $applied = false;
    $registry = new InstallPatchRegistry;
    $registry->register(function (InstallPatchContext $context) use (&$applied): Patch {
        return prepareInstallApplicationTestPatch(
            label: 'Apply test patch',
            status: PatchStatus::Applicable,
            apply: function () use (&$applied): void {
                $applied = true;
            },
        );
    });
    app()->instance(InstallPatchRegistry::class, $registry);

    $reporter = new PrepareInstallApplicationTestReporter;
    $manualChanges = [];

    PrepareInstallApplicationAction::run(
        inputData: prepareInstallApplicationTestInput(),
        hasFilamentAdminPanelProvider: false,
        interactive: false,
        useFreshDemoDefaults: false,
        reporter: $reporter,
        confirmPatch: static fn (InstallPatchConfirmation $confirmation): never => throw new RuntimeException('Confirmation should not be requested.'),
        recordManualInstallChange: function (string $message) use (&$manualChanges): void {
            $manualChanges[] = $message;
        },
    );

    expect($reporter->steps)->toBe(['Applying install guide patch: Apply test patch'])
        ->and($applied)->toBeTrue()
        ->and($reporter->errors)->toBe([])
        ->and($manualChanges)->toBe([]);
});

it('reports skipped confirmation without applying the patch', function (): void {
    $applied = false;
    $registry = new InstallPatchRegistry;
    $registry->register(
        function (InstallPatchContext $context) use (&$applied): Patch {
            return prepareInstallApplicationTestPatch(
                label: 'Confirm test patch',
                status: PatchStatus::Applicable,
                apply: function () use (&$applied): void {
                    $applied = true;
                },
            );
        },
        new InstallPatchConfirmation(
            label: 'Apply the test patch?',
            hint: null,
            skippedMessage: '→ Test patch skipped.',
        ),
    );
    app()->instance(InstallPatchRegistry::class, $registry);

    $reporter = new PrepareInstallApplicationTestReporter;

    PrepareInstallApplicationAction::run(
        inputData: prepareInstallApplicationTestInput(),
        hasFilamentAdminPanelProvider: false,
        interactive: true,
        useFreshDemoDefaults: false,
        reporter: $reporter,
        confirmPatch: static fn (InstallPatchConfirmation $confirmation): bool => false,
        recordManualInstallChange: static function (string $message): void {},
    );

    expect($applied)->toBeFalse()
        ->and($reporter->steps)->toBe([])
        ->and($reporter->reports)->toBe(['→ Test patch skipped.'])
        ->and($reporter->errors)->toBe([]);
});

it('records and reports failed patch application', function (): void {
    $registry = new InstallPatchRegistry;
    $registry->register(static fn (InstallPatchContext $context): Patch => prepareInstallApplicationTestPatch(
        label: 'Failing test patch',
        status: PatchStatus::Applicable,
        apply: static fn (): never => throw new RuntimeException('Patch write failed.'),
    ));
    app()->instance(InstallPatchRegistry::class, $registry);

    $reporter = new PrepareInstallApplicationTestReporter;
    $manualChanges = [];

    PrepareInstallApplicationAction::run(
        inputData: prepareInstallApplicationTestInput(),
        hasFilamentAdminPanelProvider: false,
        interactive: false,
        useFreshDemoDefaults: false,
        reporter: $reporter,
        confirmPatch: static fn (InstallPatchConfirmation $confirmation): never => throw new RuntimeException('Confirmation should not be requested.'),
        recordManualInstallChange: function (string $message) use (&$manualChanges): void {
            $manualChanges[] = $message;
        },
    );

    expect($manualChanges)->toBe(['Failing test patch: Patch write failed.'])
        ->and($reporter->steps)->toBe(['Applying install guide patch: Failing test patch'])
        ->and($reporter->errors)->toBe([
            '⚠ Failing test patch was not applied automatically. Manual changes may be required.',
            'Patch write failed.',
        ]);
});

function prepareInstallApplicationTestInput(): InstallInputData
{
    return new InstallInputData(
        siteUrl: 'https://example.test',
        packages: ['capell-app/core'],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
    );
}

function prepareInstallApplicationTestPatch(string $label, PatchStatus $status, Closure $apply): Patch
{
    return new class($label, $status, $apply) implements Patch
    {
        public function __construct(
            private readonly string $label,
            private readonly PatchStatus $status,
            private readonly Closure $apply,
        ) {}

        public function id(): string
        {
            return 'prepare-install-application-test';
        }

        public function group(): string
        {
            return 'testing';
        }

        public function label(): string
        {
            return $this->label;
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
            return $this->status;
        }

        public function reason(): ?string
        {
            return null;
        }

        public function apply(): void
        {
            ($this->apply)();
        }
    };
}

final class PrepareInstallApplicationTestReporter implements ProgressReporter
{
    /** @var array<int, string> */
    public array $steps = [];

    /** @var array<int, string> */
    public array $reports = [];

    /** @var array<int, string> */
    public array $errors = [];

    public function step(string $label): void
    {
        $this->steps[] = $label;
    }

    public function report(string $line): void
    {
        $this->reports[] = $line;
    }

    public function error(string $line): void
    {
        $this->errors[] = $line;
    }
}
