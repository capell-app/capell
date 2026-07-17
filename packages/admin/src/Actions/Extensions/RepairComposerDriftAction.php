<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Extensions;

use Capell\Admin\Support\Extensions\ComposerDriftClassifier;
use Capell\Admin\Support\Extensions\ComposerDriftMetadata;
use Capell\Core\Actions\RequirePackageAction;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class RepairComposerDriftAction
{
    use AsFake;
    use AsObject;

    /**
     * @return list<array{package:string,status:string,message:string,reason:string|null}>
     */
    public function handle(?string $composerName = null): array
    {
        if (! Schema::hasTable('capell_extensions')) {
            return [];
        }

        $query = CapellExtension::query()->orderBy('composer_name');

        if (is_string($composerName) && $composerName !== '') {
            $query->where('composer_name', $composerName);
        }

        /** @var ComposerDriftClassifier $classifier */
        $classifier = resolve(ComposerDriftClassifier::class);

        return array_values($query
            ->get()
            ->map(fn (CapellExtension $extension): array => $this->repairExtension($extension, $classifier))
            ->filter(fn (array $result): bool => $result['reason'] !== null || $composerName !== null)
            ->values()
            ->all());
    }

    /**
     * @return array{package:string,status:string,message:string,reason:string|null}
     */
    private function repairExtension(CapellExtension $extension, ComposerDriftClassifier $classifier): array
    {
        $reason = $classifier->reason($extension);

        if ($reason === null) {
            return [
                'package' => $extension->composer_name,
                'status' => 'skipped',
                'message' => (string) __('capell-admin::dashboard.extension_composer_drift_repair_no_drift'),
                'reason' => null,
            ];
        }

        ComposerDriftMetadata::recordDetection($extension, $reason);

        if (! $classifier->isComposerActionable($reason)) {
            $message = (string) __('capell-admin::dashboard.extension_composer_drift_repair_not_actionable');
            ComposerDriftMetadata::recordRepair($extension, 'skipped', $message);

            return [
                'package' => $extension->composer_name,
                'status' => 'skipped',
                'message' => $message,
                'reason' => $reason,
            ];
        }

        try {
            /** @var array{message?: string} $result */
            $result = RequirePackageAction::run($extension->composer_name);
            CapellCore::clearExtensionCache();

            $message = is_string($result['message'] ?? null)
                ? $result['message']
                : (string) __('capell-admin::dashboard.extension_composer_drift_repair_success');

            ComposerDriftMetadata::recordRepair($extension->refresh(), 'success', $message);

            return [
                'package' => $extension->composer_name,
                'status' => 'success',
                'message' => $message,
                'reason' => $reason,
            ];
        } catch (Throwable $throwable) {
            ComposerDriftMetadata::recordRepair($extension->refresh(), 'failed', $throwable->getMessage());

            return [
                'package' => $extension->composer_name,
                'status' => 'failed',
                'message' => $throwable->getMessage(),
                'reason' => $reason,
            ];
        }
    }
}
