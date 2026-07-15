<?php

declare(strict_types=1);

use Capell\Core\Actions\Diagnostics\ResolveCapellInstallationStateAction;
use Capell\Core\Enums\Diagnostics\CapellInstallationState;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Support\Diagnostics\CapellRuntimeSchemaContract;

it('resolves the installation-state schema matrix', function (
    array $tables,
    bool $coreRecorded,
    ?ExtensionStatusEnum $status,
    CapellInstallationState $expected,
): void {
    $state = resolve(ResolveCapellInstallationStateAction::class)->handle(
        existingTables: $tables,
        coreStatus: $status,
        coreRecorded: $coreRecorded,
    );

    expect($state)->toBe($expected);
})->with(function (): array {
    $required = resolve(CapellRuntimeSchemaContract::class)->requiredTables();

    return [
        'no footprint' => [[], false, null, CapellInstallationState::NotInstalled],
        'only sites' => [['sites'], false, null, CapellInstallationState::Partial],
        'only lifecycle table' => [['capell_extensions'], false, null, CapellInstallationState::Partial],
        'core row absent' => [$required, false, null, CapellInstallationState::Partial],
        'core row uninstalled' => [$required, true, ExtensionStatusEnum::Uninstalled, CapellInstallationState::Partial],
        'missing required core table' => [array_values(array_diff($required, ['pages'])), true, ExtensionStatusEnum::Enabled, CapellInstallationState::Partial],
        'missing theme table' => [array_values(array_diff($required, ['themes'])), true, ExtensionStatusEnum::Enabled, CapellInstallationState::Partial],
        'missing layout table' => [array_values(array_diff($required, ['layouts'])), true, ExtensionStatusEnum::Enabled, CapellInstallationState::Partial],
        'missing stored events' => [array_values(array_diff($required, ['stored_events'])), true, ExtensionStatusEnum::Enabled, CapellInstallationState::Partial],
        'missing snapshots' => [array_values(array_diff($required, ['snapshots'])), true, ExtensionStatusEnum::Enabled, CapellInstallationState::Partial],
        'missing workflow state' => [array_values(array_diff($required, ['page_workflow_states'])), true, ExtensionStatusEnum::Enabled, CapellInstallationState::Partial],
        'complete enabled install' => [$required, true, ExtensionStatusEnum::Enabled, CapellInstallationState::Installed],
        'complete disabled install remains installed' => [$required, true, ExtensionStatusEnum::Disabled, CapellInstallationState::Installed],
    ];
});

it('keeps the required schema catalog grouped and complete', function (): void {
    $contract = resolve(CapellRuntimeSchemaContract::class);

    expect($contract->footprintAnchors())->toContain('sites', 'capell_extensions')
        ->and($contract->themeAndLayoutTables())->toBe(['themes', 'layouts'])
        ->and($contract->eventSourcingTables())->toBe(['stored_events', 'snapshots'])
        ->and($contract->requiredTables())->toContain('page_workflow_states', 'page_revisions');
});
