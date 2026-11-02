<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security\Remediations;

use Capell\Core\Data\Security\SecurityRemediationOutcomeData;
use Capell\Core\Support\Patching\EnvFileEditor;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Throwable;

final class EnvironmentRemediation
{
    /** @param array<string, string> $values */
    public function apply(array $values): SecurityRemediationOutcomeData
    {
        $editor = new EnvFileEditor(app()->environmentFilePath());
        $before = [];
        foreach ($values as $key => $value) {
            $current = $editor->get($key);
            // Only retain known boolean values; an unexpected value could contain a secret.
            throw_if($current !== null && ! in_array(strtolower(trim($current)), ['true', 'false', '1', '0', '(true)', '(false)'], true), RuntimeException::class, 'A setting has an unexpected value and needs manual review.');

            $before[$key] = $current;
            if ($current !== $value) {
                $editor->set($key, $value);
            }
        }

        if ($before === $values) {
            return SecurityRemediationOutcomeData::skipped(__('capell-core::security.remediation.already_set'));
        }

        $backup = $editor->backup();
        $editor->save();
        $after = $values;
        $after['backup_directory'] = $backup;
        try {
            $refreshRequired = app()->configurationIsCached() && Artisan::call('config:clear') !== 0;
        } catch (Throwable) {
            $refreshRequired = true;
        }

        if ($refreshRequired) {
            $after['config_cache_refresh_required'] = true;
            $message = __('capell-core::security.remediation.cache_refresh_required');
        } else {
            $message = __('capell-core::security.remediation.changed');
        }

        return new SecurityRemediationOutcomeData(true, $message, $before, $after);
    }
}
