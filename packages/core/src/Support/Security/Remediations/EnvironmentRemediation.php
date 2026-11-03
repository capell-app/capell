<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security\Remediations;

use Capell\Core\Data\Security\SecurityRemediationOutcomeData;
use Capell\Core\Support\Patching\EnvFileEditor;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

final class EnvironmentRemediation
{
    /** @param array<string, string> $values */
    public function apply(array $values): SecurityRemediationOutcomeData
    {
        try {
            return Cache::lock('capell:security:env-remediation', 30)->block(10, fn (): SecurityRemediationOutcomeData => $this->applyLocked($values));
        } catch (LockTimeoutException) {
            return SecurityRemediationOutcomeData::skipped(__('capell-core::security.remediation.env_locked'));
        }
    }

    /** @param array<string, string> $values */
    private function applyLocked(array $values): SecurityRemediationOutcomeData
    {
        $handle = @fopen(app()->environmentFilePath(), 'rb');
        throw_if($handle === false, RuntimeException::class, 'Unable to open the environment file.');

        $deadline = microtime(true) + 5;
        try {
            while (! flock($handle, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    return SecurityRemediationOutcomeData::skipped(__('capell-core::security.remediation.env_locked'));
                }

                Sleep::usleep(100_000);
            }

            return $this->applyWithFileLock($values);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param array<string, string> $values */
    private function applyWithFileLock(array $values): SecurityRemediationOutcomeData
    {
        $editor = new EnvFileEditor(app()->environmentFilePath());
        if ($this->ambiguous($editor->content(), array_keys($values))) {
            return SecurityRemediationOutcomeData::skipped(__('capell-core::security.remediation.env_ambiguous'));
        }

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
            if (! app()->configurationIsCached()) {
                return new SecurityRemediationOutcomeData(false, __('capell-core::security.remediation.already_set'), nodeLocal: true);
            }

            if (! $this->refreshCache()) {
                return SecurityRemediationOutcomeData::failed(__('capell-core::security.remediation.cache_refresh_required'), $before, $values);
            }

            return new SecurityRemediationOutcomeData(true, __('capell-core::security.remediation.cache_refreshed'), $before, $values, nodeLocal: true);
        }

        $backup = $editor->backup();
        $editor->save();
        $after = $values;
        $after['backup_directory'] = $backup;
        if (app()->configurationIsCached() && ! $this->refreshCache()) {
            $after['config_cache_refresh_required'] = true;

            return SecurityRemediationOutcomeData::failed(__('capell-core::security.remediation.cache_refresh_required'), $before, $after);
        }

        return new SecurityRemediationOutcomeData(true, __('capell-core::security.remediation.changed'), $before, $after, nodeLocal: true);
    }

    private function refreshCache(): bool
    {
        try {
            return Artisan::call('config:clear') === 0;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param list<string> $keys */
    private function ambiguous(string $content, array $keys): bool
    {
        if (str_contains($content, "\r\n")) {
            return true;
        }

        $counts = array_fill_keys($keys, 0);
        $quote = null;
        foreach (explode("\n", $content) as $line) {
            foreach ($keys as $key) {
                $pattern = '/^\s*(?:export\s+)?["\']?' . preg_quote($key, '/') . '["\']?\s*=/';
                if (preg_match($pattern, $line) === 1) {
                    if (! str_starts_with($line, $key . '=') || $quote !== null || ++$counts[$key] > 1) {
                        return true;
                    }
                }
            }

            if ($quote === null) {
                if (preg_match('/^\s*(?:export\s+)?["\']?[A-Za-z_][\w.]*["\']?\s*=\s*(["\'])/', $line, $match, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }

                $quote = $match[1][0];
                $line = substr($line, $match[1][1] + 1);
            }

            for ($i = 0, $length = strlen($line); $i < $length; $i++) {
                if ($line[$i] === '\\') {
                    $i++;
                } elseif ($line[$i] === $quote) {
                    $quote = null;
                    break;
                }
            }
        }

        return false;
    }
}
