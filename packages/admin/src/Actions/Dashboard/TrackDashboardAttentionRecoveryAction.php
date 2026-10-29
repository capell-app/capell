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
use Throwable;

final class TrackDashboardAttentionRecoveryAction
{
    /** @param list<DashboardAttentionItemData> $items */
    public function observe(Model&Authenticatable $actor, ?int $siteId, ?string $language, array $items): void
    {
        $store = resolve(AdminWorkspacePreferenceStore::class);
        $scope = $this->scope($siteId, $language);
        $state = $store->dashboardRecoveryState($actor);
        $observed = is_array($state['observed'][$scope] ?? null) ? $state['observed'][$scope] : [];
        $active = [];
        foreach ($items as $item) {
            $active[$item->key] = ['key' => $item->key, 'fingerprint' => $item->fingerprint, 'site_id' => $item->siteId, 'page_id' => $item->pageId, 'language' => $item->urgent ? null : $language, 'title' => $item->title];
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

        if ($resolved === [] && array_slice(array_replace($observed, $active), -100, null, true) === $observed) {
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

            $scopes[$scope] = array_slice(array_replace($previous, $active), -100, null, true);
            $state['observed'] = array_slice($scopes, -20, null, true);
            $state['history'] = array_slice($history, -50);

            return $state;
        });
    }

    /** @return list<array{title: string, site_name: string, resolved_at: string, display_date: string, timezone: string, acknowledged: bool}> */
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

            if (! resolve(CanViewDashboardItemAction::class)->handle($actor, $candidate->siteId, $siteId, $candidate->pageId, $candidate->language)) {
                continue;
            }

            $timezone = resolve(ResolveDashboardSiteTimezoneAction::class)->handle($site);
            $history[] = ['title' => $record['title'], 'site_name' => $site->name, 'resolved_at' => $record['resolved_at'], 'display_date' => $date->setTimezone($timezone)->format('j M Y, H:i'), 'timezone' => $timezone, 'acknowledged' => ($record['acknowledged'] ?? false) === true];
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
            || (isset($record['language']) && ! is_string($record['language']))) {
            return null;
        }

        return new DashboardAttentionRecoveryCandidateData($record['key'], $record['fingerprint'], $record['site_id'], $record['page_id'] ?? null, $record['language'] ?? null);
    }

    private function isRecovered(Model&Authenticatable $actor, ?int $selectedSiteId, DashboardAttentionRecoveryCandidateData $candidate): bool
    {
        try {
            if (! resolve(CanViewDashboardItemAction::class)->handle($actor, $candidate->siteId, $selectedSiteId, $candidate->pageId, $candidate->language)) {
                return false;
            }

            if ($candidate->pageId !== null && $candidate->key === 'page-setup-' . $candidate->pageId) {
                return resolve(DashboardSiteEvaluator::class)->allows($actor, $candidate->siteId, function () use ($actor, $candidate): bool {
                    if (! Gate::forUser($actor)->allows('viewAny', Page::class)) {
                        return false;
                    }

                    $page = SiteAccess::forActor($actor)->query(Page::class)->where('site_id', $candidate->siteId)->find($candidate->pageId);

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
