<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Dashboard;

use Capell\Admin\Contracts\Dashboard\DashboardAttentionContributor;
use Capell\Admin\Contracts\Dashboard\DashboardAttentionRecoveryContributor;
use Capell\Admin\Data\Dashboard\DashboardAttentionItemData;
use Capell\Admin\Data\Dashboard\DashboardAttentionRecoveryCandidateData;
use Capell\Admin\Support\Dashboard\DashboardSiteEvaluator;
use Capell\Admin\Support\Workspace\AdminWorkspacePreferenceStore;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\SiteAccess;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class TrackDashboardAttentionRecoveryAction
{
    use AsFake;
    use AsObject;

    /** @param list<DashboardAttentionItemData> $items */
    public function handle(Model&Authenticatable $actor, ?int $siteId, ?string $language, array $items): void
    {
        $store = resolve(AdminWorkspacePreferenceStore::class);
        $scope = $this->scope($siteId, $language);
        $state = $store->dashboardRecoveryState($actor);
        $observed = is_array($state['observed'][$scope] ?? null) ? $state['observed'][$scope] : [];
        $pageIds = array_values(array_unique(array_filter(array_map(static fn (DashboardAttentionItemData $item): ?int => $item->pageId, $items))));
        $pages = SiteAccess::forActor($actor, acrossAssignedSites: true)->scope(Page::query()->withoutGlobalScopes())
            ->whereNull('deleted_at')->whereIn('id', $pageIds)->get()->keyBy('id');
        $active = [];
        foreach ($items as $item) {
            $page = $pages->get($item->pageId);
            $uuid = $page instanceof Page && (int) $page->site_id === $item->siteId ? $page->uuid : null;
            $active[$item->key] = ['key' => $item->key, 'fingerprint' => $item->fingerprint, 'site_id' => $item->siteId, 'page_id' => $item->pageId, 'page_uuid' => $uuid, 'language' => $item->urgent ? null : $language, 'title' => $item->title];
        }

        $resolved = [];
        foreach ($observed as $key => $record) {
            if (! is_string($key)) {
                continue;
            }

            if (isset($active[$key])) {
                continue;
            }

            if (! is_array($record)) {
                continue;
            }

            $candidate = $this->candidate($record);
            if ($candidate instanceof DashboardAttentionRecoveryCandidateData && $this->isRecovered($actor, $siteId, $candidate)) {
                $resolved[$key] = $record;
            }
        }

        if ($resolved === [] && array_slice($active + $observed, 0, 100, true) === $observed) {
            return;
        }

        $store->updateDashboardRecoveryState($actor, static function (array $state) use ($scope, $active, $resolved): array {
            $scopes = is_array($state['observed'] ?? null) ? $state['observed'] : [];
            $previous = is_array($scopes[$scope] ?? null) ? $scopes[$scope] : [];
            $history = is_array($state['history'] ?? null) ? $state['history'] : [];
            foreach ($resolved as $key => $record) {
                if (($previous[$key]['fingerprint'] ?? null) !== ($record['fingerprint'] ?? null)) {
                    continue;
                }

                unset($previous[$key]);
                $history[] = [...$record, 'scope' => $scope, 'resolved_at' => now()->toIso8601String(), 'acknowledged' => false];
            }

            $scopes[$scope] = array_slice($active + $previous, 0, 100, true);
            $state['observed'] = array_slice($scopes, -20, null, true);
            $state['history'] = array_slice($history, -50);

            return $state;
        });
    }

    /** @return list<array{key: string, title: string, site_name: string, resolved_at: string, display_date: string, timezone: string, acknowledged: bool}> */
    public function history(Model&Authenticatable $actor, ?int $siteId, ?string $language): array
    {
        $state = resolve(AdminWorkspacePreferenceStore::class)->dashboardRecoveryState($actor);
        $records = is_array($state['history'] ?? null) ? $state['history'] : [];
        $candidates = [];
        foreach (array_reverse($records) as $record) {
            if (! is_array($record)) {
                continue;
            }

            if (($record['scope'] ?? null) !== $this->scope($siteId, $language)) {
                continue;
            }

            if (! is_string($record['title'] ?? null)) {
                continue;
            }

            if (! is_string($record['resolved_at'] ?? null)) {
                continue;
            }

            $candidate = $this->candidate($record);
            if (! $candidate instanceof DashboardAttentionRecoveryCandidateData) {
                continue;
            }

            try {
                $date = CarbonImmutable::createFromFormat('!Y-m-d\\TH:i:sP', $record['resolved_at']);
                if (! $date instanceof CarbonImmutable) {
                    continue;
                }

                if ($date->format(DateTimeInterface::ATOM) !== $record['resolved_at']) {
                    continue;
                }
            } catch (Throwable) {
                continue;
            }

            $candidates[] = [$candidate, $record, $date];
        }

        $siteIds = array_values(array_unique(array_map(static fn (array $entry): int => $entry[0]->siteId, $candidates)));
        $sites = SiteAccess::forActor($actor, acrossAssignedSites: true)->query(Site::class)->whereIn('id', $siteIds)->get()->keyBy('id');
        $history = [];
        foreach ($candidates as [$candidate, $record, $date]) {
            $site = $sites->get($candidate->siteId);
            if (! $site instanceof Site) {
                continue;
            }

            if (! $this->canViewCandidate($actor, $siteId, $candidate)) {
                continue;
            }

            if (! $this->canViewContributorHistory($actor, $candidate)) {
                continue;
            }

            $timezone = resolve(ResolveDashboardSiteTimezoneAction::class)->handle($site);
            $history[] = ['key' => $candidate->key, 'title' => $record['title'], 'site_name' => $site->name, 'resolved_at' => $record['resolved_at'], 'display_date' => $date->setTimezone($timezone)->format('j M Y, H:i'), 'timezone' => $timezone, 'acknowledged' => ($record['acknowledged'] ?? false) === true];
        }

        return $history;
    }

    public function acknowledge(Model $actor, ?int $siteId, ?string $language): void
    {
        $scope = $this->scope($siteId, $language);
        resolve(AdminWorkspacePreferenceStore::class)->updateDashboardRecoveryState($actor, static function (array $state) use ($scope): array {
            $history = is_array($state['history'] ?? null) ? $state['history'] : [];
            foreach ($history as &$record) {
                if (is_array($record) && ($record['scope'] ?? null) === $scope) {
                    $record['acknowledged'] = true;
                }
            }

            unset($record);
            $state['history'] = $history;

            return $state;
        });
    }

    private function scope(?int $siteId, ?string $language): string
    {
        return hash('sha256', serialize([$siteId, $language]));
    }

    /** @param array<mixed> $record */
    private function candidate(array $record): ?DashboardAttentionRecoveryCandidateData
    {
        if (! is_string($record['key'] ?? null) || ! is_string($record['fingerprint'] ?? null)
            || ! is_int($record['site_id'] ?? null) || $record['site_id'] < 1
            || (isset($record['page_id']) && ! is_int($record['page_id']))
            || (isset($record['page_uuid']) && ! is_string($record['page_uuid']))
            || (isset($record['language']) && ! is_string($record['language']))) {
            return null;
        }

        return new DashboardAttentionRecoveryCandidateData($record['key'], $record['fingerprint'], $record['site_id'], $record['page_id'] ?? null, $record['language'] ?? null, $record['page_uuid'] ?? null);
    }

    private function currentPage(Model&Authenticatable $actor, DashboardAttentionRecoveryCandidateData $candidate): ?Page
    {
        if ($candidate->pageId === null) {
            return null;
        }

        $query = SiteAccess::forActor($actor, acrossAssignedSites: true)->scope(Page::query()->withoutGlobalScopes())
            ->whereNull('deleted_at')->where('site_id', $candidate->siteId);
        $original = (clone $query)->find($candidate->pageId);
        if ($original instanceof Page) {
            return $original;
        }

        if ($candidate->pageUuid === null) {
            return null;
        }

        return $query->where('uuid', $candidate->pageUuid)->get()
            ->first(static fn (Page $page): bool => (int) ($page->getAttributes()['workspace_id'] ?? 0) === 0);
    }

    private function canViewCandidate(Model&Authenticatable $actor, ?int $selectedSiteId, DashboardAttentionRecoveryCandidateData $candidate): bool
    {
        $page = $this->currentPage($actor, $candidate);
        if ($candidate->pageId !== null && ! $page instanceof Page) {
            return false;
        }

        return resolve(CanViewDashboardItemAction::class)->handle($actor, $candidate->siteId, $selectedSiteId, $page?->getKey(), $candidate->language);
    }

    private function canViewContributorHistory(Model&Authenticatable $actor, DashboardAttentionRecoveryCandidateData $candidate): bool
    {
        if ($candidate->pageId !== null && $candidate->key === 'page-setup-' . $candidate->pageId) {
            return true;
        }

        try {
            return resolve(DashboardSiteEvaluator::class)->allows($actor, $candidate->siteId, function () use ($actor, $candidate): bool {
                foreach (app()->tagged(DashboardAttentionContributor::TAG) as $contributor) {
                    if ($contributor instanceof DashboardAttentionRecoveryContributor && $contributor->canViewHistory($actor, $candidate)) {
                        return true;
                    }
                }

                return false;
            });
        } catch (Throwable) {
            return false;
        }
    }

    private function isRecovered(Model&Authenticatable $actor, ?int $selectedSiteId, DashboardAttentionRecoveryCandidateData $candidate): bool
    {
        try {
            if (! $this->canViewCandidate($actor, $selectedSiteId, $candidate)) {
                return false;
            }

            if ($candidate->pageId !== null && $candidate->key === 'page-setup-' . $candidate->pageId) {
                return resolve(DashboardSiteEvaluator::class)->allows($actor, $candidate->siteId, function () use ($actor, $candidate): bool {
                    if (! Gate::forUser($actor)->allows('viewAny', Page::class)) {
                        return false;
                    }

                    $page = $this->currentPage($actor, $candidate);

                    return $page instanceof Page && Gate::forUser($actor)->allows('view', $page)
                        && resolve(BuildDashboardPageBlockersAction::class)->handle($page) === [];
                });
            }

            return resolve(DashboardSiteEvaluator::class)->allows($actor, $candidate->siteId, function () use ($actor, $candidate): bool {
                foreach (app()->tagged(DashboardAttentionContributor::TAG) as $contributor) {
                    if ($contributor instanceof DashboardAttentionRecoveryContributor
                        && $contributor->isRecovered($actor, $candidate)) {
                        return true;
                    }
                }

                return false;
            });
        } catch (Throwable) {
            return false;
        }
    }
}
