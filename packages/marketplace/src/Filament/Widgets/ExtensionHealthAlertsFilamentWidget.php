<?php

declare(strict_types=1);

namespace Capell\Marketplace\Filament\Widgets;

use Capell\Core\Enums\ExtensionHealthAlertSeverity;
use Capell\Core\Models\ExtensionHealthAlert;
use Illuminate\Database\Eloquent\Builder;

final class ExtensionHealthAlertsFilamentWidget
{
    private const int CRITICAL_ALERT_LIMIT = 5;

    /**
     * @return array<int, ExtensionHealthAlert>
     */
    public static function criticalAlertsForExtension(string $extensionSlug, ?string $composerName): array
    {
        if (! app()->bound('db')) {
            return [];
        }

        return self::queryCriticalAlerts($extensionSlug, $composerName)
            ->limit(self::CRITICAL_ALERT_LIMIT)
            ->get()
            ->all();
    }

    /**
     * @return Builder<ExtensionHealthAlert>
     */
    private static function queryCriticalAlerts(string $extensionSlug, ?string $composerName): Builder
    {
        return ExtensionHealthAlert::query()
            ->where('severity', ExtensionHealthAlertSeverity::Critical->value)
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->where(function (Builder $query) use ($extensionSlug, $composerName): void {
                $query->where('extension_slug', $extensionSlug);

                if (is_string($composerName) && $composerName !== '') {
                    $query->orWhere('composer_name', $composerName);
                }
            })
            ->latest('issued_at')->latest();
    }
}
