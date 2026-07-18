<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Dashboard;

use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Capell\Admin\Filament\Pages\UpgradePage;
use Capell\Core\Support\Json\JsonCodec;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Override;
use Throwable;

final class UpdateAdvisoryFilamentWidget extends Widget implements CapellFilamentWidgetContract
{
    use GatedByRoleAndSettings;

    private const string UPDATE_ADVISORY_SNAPSHOTS_TABLE = 'marketplace_update_advisory_snapshots';

    protected static string $settingsKey = 'update_advisories';

    /** @var list<string> */
    protected static array $rolesConfigKeys = [];

    protected string $view = 'capell-admin::widgets.update-advisory';

    protected int|string|array $columnSpan = ['default' => 'full'];

    protected static ?int $sort = 30;

    #[Override]
    public static function canView(): bool
    {
        return self::canViewCheck() && self::criticalSecurityAdvisories() !== [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function criticalSecurityAdvisories(): array
    {
        if (! Schema::hasTable(self::UPDATE_ADVISORY_SNAPSHOTS_TABLE)) {
            return [];
        }

        try {
            $snapshot = DB::table(self::UPDATE_ADVISORY_SNAPSHOTS_TABLE)
                ->latest('checked_at')
                ->first();
        } catch (Throwable) {
            return [];
        }

        if ($snapshot === null) {
            return [];
        }

        $advisories = JsonCodec::decodeArray((string) ($snapshot->advisories ?? ''));

        return collect($advisories)
            ->filter(fn (mixed $notice): bool => is_array($notice))
            ->where('type', 'security')
            ->filter(fn (array $notice): bool => in_array((string) ($notice['severity'] ?? ''), ['critical', 'high'], true))
            ->sortByDesc(fn (array $notice): int => (string) ($notice['severity'] ?? '') === 'critical' ? 2 : 1)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function advisories(): array
    {
        return self::criticalSecurityAdvisories();
    }

    public function upgradeUrl(): string
    {
        return UpgradePage::getUrl();
    }
}
