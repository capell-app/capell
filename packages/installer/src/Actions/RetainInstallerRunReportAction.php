<?php

declare(strict_types=1);

namespace Capell\Installer\Actions;

use Capell\Installer\Support\InstallerSessionRepository;
use Lorisleiva\Actions\Concerns\AsObject;
use stdClass;

final class RetainInstallerRunReportAction
{
    use AsObject;

    public function __construct(private readonly InstallerSessionRepository $sessions) {}

    public function handle(string $installId): void
    {
        if ($this->sessions->has($this->sessions->key($installId, 'terminal_report'))) {
            return;
        }

        // Do not run new preflight checks while cancelling an incomplete session.
        if ($this->sessions->preflightReport($installId) === null) {
            $this->sessions->putPreflightReport($installId, []);
        }

        $reportData = BuildInstallerRunReportAction::run($installId);
        $report = $reportData->toPayload();
        $report['status'] = 'cancelled';
        $report['diagnostics'] = [...$reportData->diagnostics, 'completedSteps' => $this->sessions->completedSteps($installId)];
        $secrets = $this->secrets($this->sessions->input($installId) ?? []);
        $this->sessions->put($this->sessions->key($installId, 'terminal_report'), $this->redact($report, $secrets));
    }

    /** @param array<array-key, mixed> $input
     * @return list<string>
     */
    private function secrets(array $input): array
    {
        $secrets = [];
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $secrets = [...$secrets, ...$this->secrets($value)];
            } elseif (is_string($key) && preg_match('/password|secret|token|credential/i', $key) && is_string($value) && $value !== '') {
                $secrets[] = $value;
            }
        }

        return $secrets;
    }

    /** @param list<string> $secrets */
    private function redact(mixed $value, array $secrets): mixed
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }

        if (is_array($value)) {
            $result = [];
            foreach (array_slice($value, 0, 500, preserve_keys: true) as $key => $item) {
                if (is_string($key) && preg_match('/password|secret|token|credential/i', $key)) {
                    continue;
                }

                $result[$key] = $this->redact($item, $secrets);
            }

            return $result;
        }

        if (! is_string($value)) {
            return $value;
        }

        $value = str_replace($secrets, '[redacted]', $value);
        $value = (string) preg_replace('/\b(password|secret|token|credential|api_key)\s*[:=]\s*[^\s,;]+/i', '$1=[redacted]', $value);
        $value = (string) preg_replace('~(://)[^/@\s]+:[^/@\s]+@~', '$1[redacted]@', $value);

        return mb_strcut($value, 0, 8192, 'UTF-8');
    }
}
