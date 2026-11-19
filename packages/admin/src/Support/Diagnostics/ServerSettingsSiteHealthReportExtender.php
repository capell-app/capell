<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Diagnostics;

use Capell\Admin\Contracts\Diagnostics\SiteHealthReportExtender;
use Capell\Admin\Data\Diagnostics\DiagnosticCheckData;
use Capell\Admin\Data\Diagnostics\DiagnosticSectionData;
use Capell\Core\Actions\HostMonitor\BuildServerSettingsRecommendationsAction;
use Capell\Core\Contracts\Health\ServerSettingsReader;
use Capell\Core\Data\Diagnostics\ServerSettingRecommendationData;
use Capell\Core\Enums\Diagnostics\ServerSettingFlag;
use Override;
use Throwable;

/** Adds a "Recommended settings" section to Site Health: measured values against the host's cores and RAM, with the change to make. */
final readonly class ServerSettingsSiteHealthReportExtender implements SiteHealthReportExtender
{
    public function __construct(private ServerSettingsReader $reader) {}

    /** @return list<DiagnosticSectionData> */
    #[Override]
    public function sections(): array
    {
        try {
            $result = BuildServerSettingsRecommendationsAction::run($this->reader->read());
        } catch (Throwable $throwable) {
            report($throwable);

            return [];
        }

        $checks = array_map($this->check(...), $result->recommendations);

        foreach ($result->unmeasured as $setting) {
            $checks[] = new DiagnosticCheckData(
                status: 'green',
                label: $setting,
                detail: (string) __('capell-admin::server-settings.not_measured', ['setting' => $setting]),
            );
        }

        if ($result->recommendations === []) {
            array_unshift($checks, new DiagnosticCheckData(
                status: 'green',
                label: (string) __('capell-admin::server-settings.healthy_label'),
                detail: (string) __('capell-admin::server-settings.healthy'),
            ));
        }

        return [new DiagnosticSectionData((string) __('capell-admin::server-settings.section'), $checks)];
    }

    private function check(ServerSettingRecommendationData $recommendation): DiagnosticCheckData
    {
        $values = [
            'measured' => $recommendation->measured,
            'recommended' => $recommendation->recommended,
        ];

        return new DiagnosticCheckData(
            status: 'amber',
            label: $recommendation->setting,
            detail: (string) __('capell-admin::server-settings.measured_detail', $values),
            remediation: match ($recommendation->flag) {
                ServerSettingFlag::ValidateTimestamps => (string) __('capell-admin::server-settings.change_validate_timestamps', $values),
                ServerSettingFlag::OpcacheMemory => (string) __('capell-admin::server-settings.change_opcache_memory', $values),
                ServerSettingFlag::OpcacheInternedStrings => (string) __('capell-admin::server-settings.change_opcache_interned_strings', $values),
                ServerSettingFlag::OpcacheMaxFiles => (string) __('capell-admin::server-settings.change_opcache_max_files', $values),
                ServerSettingFlag::FpmMaxChildren => (string) __('capell-admin::server-settings.change_fpm_max_children', $values),
                ServerSettingFlag::SwapPressure => (string) __('capell-admin::server-settings.change_swap_pressure', $values),
                ServerSettingFlag::IdleQueueWorkers => (string) __('capell-admin::server-settings.change_idle_queue_workers', $values),
            },
        );
    }
}
