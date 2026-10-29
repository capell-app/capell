<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Dashboard\WorkFirst;

use Capell\Admin\Actions\Dashboard\TrackDashboardAttentionRecoveryAction;
use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Data\Dashboard\DashboardAdvisoryDecisionData;
use Capell\Admin\Data\Dashboard\DashboardAttentionItemData;
use Capell\Admin\Filament\Concerns\HasDashboardDateRange;
use Capell\Admin\Filament\Concerns\WorkFirst\ChecksWorkFirstWidgetAccess;
use Capell\Admin\Filament\Concerns\WorkFirst\HasPersonalDashboardSize;
use Capell\Admin\Filament\Concerns\WorkFirst\HasStableDashboardOrder;
use Capell\Admin\Support\Dashboard\AdminDashboardDataRequestCache;
use Capell\Admin\Support\Workspace\AdminWorkspacePreferenceStore;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Override;
use Throwable;

/** @property-read list<DashboardAttentionItemData> $currentItems */
final class AttentionWidget extends Widget implements CapellFilamentWidgetContract
{
    use ChecksWorkFirstWidgetAccess;
    use HasDashboardDateRange;
    use HasPersonalDashboardSize;
    use HasStableDashboardOrder;

    #[Locked]
    public bool $issueResolved = false;

    protected static string $workFirstSettingsKey = 'attention';

    protected string $view = 'capell-admin::widgets.dashboard.work-first-attention';

    #[Override]
    public static function canView(): bool
    {
        return self::widgetEnabled('attention');
    }

    /** @return list<DashboardAttentionItemData> */
    #[Computed]
    public function items(): array
    {
        $items = $this->currentItems;
        $keys = array_map(static fn (DashboardAttentionItemData $item): string => $item->key, $items);
        $order = $this->stableDashboardKeys($keys, $this->scopeKey());
        $byKey = array_column($items, null, 'key');

        return array_values(array_map(static fn (string $key): DashboardAttentionItemData => $byKey[$key], $order));
    }

    #[Computed]
    public function pendingItems(): int
    {
        return $this->countPendingDashboardKeys(array_map(static fn (DashboardAttentionItemData $item): string => $item->key, $this->currentItems), $this->scopeKey());
    }

    /** @return list<DashboardAttentionItemData> */
    #[Computed]
    public function currentItems(): array
    {
        $actor = auth()->user();
        if (! self::canView() || ! $actor instanceof Model) {
            return [];
        }

        $items = resolve(AdminDashboardDataRequestCache::class)->attentionSnapshot($actor, $this->getDashboardSiteId(), $this->getDashboardLanguage());
        $tracker = resolve(TrackDashboardAttentionRecoveryAction::class);
        $tracker->observe($actor, $this->getDashboardSiteId(), $this->getDashboardLanguage(), $items);

        $now = CarbonImmutable::now();
        $this->issueResolved = array_any($tracker->history($actor, $this->getDashboardSiteId(), $this->getDashboardLanguage()), static fn (array $record): bool => ! $record['acknowledged'] && CarbonImmutable::parse($record['resolved_at'])->betweenIncluded($now->subSeconds(60), $now));

        return array_values(array_filter($items, function (DashboardAttentionItemData $item) use ($actor): bool {
            if ($item->urgent) {
                return true;
            }

            $decision = resolve(AdminWorkspacePreferenceStore::class)->dashboardAdvisoryDecision($actor, $item->key);
            if ($decision === null || $decision->fingerprint !== $item->fingerprint) {
                return true;
            }

            try {
                return $decision->until !== null && CarbonImmutable::parse($decision->until)->isPast();
            } catch (Throwable) {
                return true;
            }
        }));
    }

    public function dismiss(string $key): void
    {
        $this->saveDecision($key, null);
    }

    public function snooze(string $key): void
    {
        $this->saveDecision($key, CarbonImmutable::now()->addDay()->toIso8601String());
    }

    /** @return list<array{title: string, site_name: string, resolved_at: string, display_date: string, timezone: string, acknowledged: bool}> */
    #[Computed]
    public function recoveryHistory(): array
    {
        $actor = auth()->user();

        return self::canView() && $actor instanceof Model
            ? resolve(TrackDashboardAttentionRecoveryAction::class)->history($actor, $this->getDashboardSiteId(), $this->getDashboardLanguage())
            : [];
    }

    public function acknowledgeRecovery(): void
    {
        $actor = auth()->user();
        if (! self::canView() || ! $actor instanceof Model) {
            return;
        }

        resolve(TrackDashboardAttentionRecoveryAction::class)->acknowledge($actor, $this->getDashboardSiteId(), $this->getDashboardLanguage());
        $this->issueResolved = false;
        unset($this->recoveryHistory);
    }

    private function saveDecision(string $key, ?string $until): void
    {
        $actor = auth()->user();
        if (! self::canView() || ! $actor instanceof Model) {
            return;
        }

        foreach (resolve(AdminDashboardDataRequestCache::class)->attentionSnapshot($actor, $this->getDashboardSiteId(), $this->getDashboardLanguage()) as $item) {
            if ($item->key === $key && ! $item->urgent) {
                resolve(AdminWorkspacePreferenceStore::class)->saveDashboardAdvisoryDecision($actor, $key, new DashboardAdvisoryDecisionData($item->fingerprint, $until));
                unset($this->items, $this->currentItems, $this->pendingItems);

                return;
            }
        }
    }

    private function scopeKey(): string
    {
        return auth()->id() . ':' . ($this->getDashboardSiteId() ?? 'all') . ':' . ($this->getDashboardLanguage() ?? 'all');
    }
}
