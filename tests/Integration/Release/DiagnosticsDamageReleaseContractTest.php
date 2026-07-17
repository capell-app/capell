<?php

declare(strict_types=1);

use Capell\Core\Actions\Diagnostics\ResolveCapellInstallationStateAction;
use Capell\Core\Enums\Diagnostics\CapellInstallationState;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Support\Diagnostics\CapellRuntimeSchemaContract;

it('classifies a persisted-install footprint with schema damage as partial', function (): void {
    $schema = resolve(CapellRuntimeSchemaContract::class);
    $damagedTables = array_values(array_diff($schema->requiredTables(), ['pages']));

    $state = ResolveCapellInstallationStateAction::run(
        existingTables: $damagedTables,
        coreStatus: ExtensionStatusEnum::Enabled,
        coreRecorded: true,
    );

    expect($state)->toBe(CapellInstallationState::Partial)
        ->and($schema->missingTables($damagedTables))->toBe(['pages']);
});
