<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Diagnostics;

use Capell\Core\Enums\Diagnostics\CapellInstallationState;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Diagnostics\CapellRuntimeSchemaContract;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolveCapellInstallationStateAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly CapellRuntimeSchemaContract $schema,
    ) {}

    /** @param list<string>|null $existingTables */
    public function handle(
        ?array $existingTables = null,
        ?ExtensionStatusEnum $coreStatus = null,
        ?bool $coreRecorded = null,
    ): CapellInstallationState {
        $hasLifecycleTable = $existingTables !== null
            ? in_array('capell_extensions', $existingTables, true)
            : Schema::hasTable('capell_extensions');

        if ($coreRecorded === null) {
            $core = $hasLifecycleTable
                ? CapellExtension::query()->where('composer_name', 'capell-app/core')->first()
                : null;
            $coreRecorded = $core instanceof CapellExtension;
            $coreStatus = $core?->status;
        }

        if (! $this->schema->hasFootprint($existingTables) && ! $hasLifecycleTable && ! $coreRecorded) {
            return CapellInstallationState::NotInstalled;
        }

        $coreInstalled = $coreRecorded && in_array($coreStatus, [
            ExtensionStatusEnum::Enabled,
            ExtensionStatusEnum::Disabled,
        ], true);

        if (! $coreInstalled || $this->schema->missingTables($existingTables) !== []) {
            return CapellInstallationState::Partial;
        }

        return CapellInstallationState::Installed;
    }
}
