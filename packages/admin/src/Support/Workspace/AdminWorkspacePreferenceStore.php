<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Workspace;

use Capell\Admin\Data\Dashboard\DashboardAdvisoryDecisionData;
use Capell\Admin\Data\Dashboard\DashboardScopePreferenceData;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class AdminWorkspacePreferenceStore
{
    private const string COLUMN = 'admin_workspace_preferences';

    private const int MAX_PINS = 12;

    private const int MAX_RECENTS = 12;

    /** @return array{pinned: list<string>, recent: list<string>} */
    public function read(?Model $actor): array
    {
        if (! $actor instanceof Model || ! $this->available($actor)) {
            return ['pinned' => [], 'recent' => []];
        }

        $raw = array_key_exists(self::COLUMN, $actor->getAttributes())
            ? $actor->getAttribute(self::COLUMN)
            : null;
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

        if (! is_array($decoded)) {
            return ['pinned' => [], 'recent' => []];
        }

        return [
            'pinned' => $this->normalise($decoded['pinned'] ?? [], self::MAX_PINS),
            'recent' => $this->normalise($decoded['recent'] ?? [], self::MAX_RECENTS),
        ];
    }

    /** @param list<string> $visibleKeys */
    public function togglePin(Model $actor, string $key, array $visibleKeys): void
    {
        if (! in_array($key, $visibleKeys, true)) {
            return;
        }

        $state = $this->read($actor);

        if (in_array($key, $state['pinned'], true)) {
            $state['pinned'] = array_values(array_filter($state['pinned'], static fn (string $item): bool => $item !== $key));
        } else {
            array_unshift($state['pinned'], $key);
            $state['pinned'] = array_slice($state['pinned'], 0, self::MAX_PINS);
        }

        $this->write($actor, $state);
    }

    /** @param list<string> $visibleKeys */
    public function recordVisit(Model $actor, string $key, array $visibleKeys): void
    {
        if (! in_array($key, $visibleKeys, true)) {
            return;
        }

        $state = $this->read($actor);
        $state['recent'] = array_values(array_filter($state['recent'], static fn (string $item): bool => $item !== $key));
        array_unshift($state['recent'], $key);
        $state['recent'] = array_slice($state['recent'], 0, self::MAX_RECENTS);

        $this->write($actor, $state);
    }

    public function dashboardScope(Model $actor): ?DashboardScopePreferenceData
    {
        if (! $this->available($actor)) {
            return null;
        }

        $raw = array_key_exists(self::COLUMN, $actor->getAttributes())
            ? $actor->getAttribute(self::COLUMN)
            : $actor->newQuery()->whereKey($actor->getKey())->value(self::COLUMN);
        $data = is_array($raw) ? $raw : json_decode((string) $raw, true);
        $scope = is_array($data) ? ($data['dashboard_scope'] ?? null) : null;
        if (! is_array($scope)) {
            return null;
        }

        $siteId = $scope['site_id'] ?? null;
        $language = $scope['language'] ?? null;
        if (($siteId !== null && (! is_int($siteId) || $siteId < 1))
            || ($language !== null && (! is_string($language) || $language === ''))) {
            return null;
        }

        return new DashboardScopePreferenceData($siteId, $language);
    }

    public function saveDashboardScope(Model $actor, DashboardScopePreferenceData $scope): void
    {
        $this->merge($actor, static function (array $data) use ($scope): array {
            $data['dashboard_scope'] = ['site_id' => $scope->siteId, 'language' => $scope->language];

            return $data;
        });
    }

    public function dashboardAdvisoryDecision(Model $actor, string $key): ?DashboardAdvisoryDecisionData
    {
        if (! $this->available($actor)) {
            return null;
        }

        $raw = array_key_exists(self::COLUMN, $actor->getAttributes())
            ? $actor->getAttribute(self::COLUMN)
            : $actor->newQuery()->whereKey($actor->getKey())->value(self::COLUMN);
        $data = is_array($raw) ? $raw : json_decode((string) $raw, true);
        $decisions = is_array($data) && is_array($data['dashboard_advisories'] ?? null) ? $data['dashboard_advisories'] : [];
        $decision = $decisions[$key] ?? null;
        if (! is_array($decision) || ! is_string($decision['fingerprint'] ?? null)
            || (isset($decision['until']) && ! is_string($decision['until']))) {
            return null;
        }

        return new DashboardAdvisoryDecisionData($decision['fingerprint'], $decision['until'] ?? null);
    }

    public function saveDashboardAdvisoryDecision(Model $actor, string $key, DashboardAdvisoryDecisionData $decision): void
    {
        $this->merge($actor, static function (array $data) use ($key, $decision): array {
            $decisions = is_array($data['dashboard_advisories'] ?? null) ? $data['dashboard_advisories'] : [];
            $decisions[$key] = ['fingerprint' => $decision->fingerprint, 'until' => $decision->until];
            $data['dashboard_advisories'] = $decisions;

            return $data;
        });
    }

    /** @return array<string, mixed> */
    public function dashboardRecoveryState(Model $actor): array
    {
        if (! $this->available($actor)) {
            return [];
        }

        $raw = array_key_exists(self::COLUMN, $actor->getAttributes())
            ? $actor->getAttribute(self::COLUMN)
            : $actor->newQuery()->whereKey($actor->getKey())->value(self::COLUMN);
        $data = is_array($raw) ? $raw : json_decode((string) $raw, true);
        $state = is_array($data) ? ($data['dashboard_recovery'] ?? null) : null;

        return is_array($state) ? $state : [];
    }

    /** @param Closure(array<string, mixed>): array<string, mixed> $mutator */
    public function updateDashboardRecoveryState(Model $actor, Closure $mutator): void
    {
        $this->merge($actor, static function (array $data) use ($mutator): array {
            $state = is_array($data['dashboard_recovery'] ?? null) ? $data['dashboard_recovery'] : [];
            $data['dashboard_recovery'] = $mutator($state);

            return $data;
        });
    }

    /** @return list<string>|null */
    public function dashboardLayout(Model $actor, bool $allSites): ?array
    {
        if (! $this->available($actor)) {
            return null;
        }

        $raw = array_key_exists(self::COLUMN, $actor->getAttributes())
            ? $actor->getAttribute(self::COLUMN)
            : $actor->newQuery()->whereKey($actor->getKey())->value(self::COLUMN);
        $data = is_array($raw) ? $raw : json_decode((string) $raw, true);
        $layout = is_array($data) ? data_get($data, 'dashboard_layout.' . ($allSites ? 'all' : 'site')) : null;

        return is_array($layout) ? $this->normalise($layout, 100) : null;
    }

    /** @param list<string> $widgetKeys */
    public function saveDashboardLayout(Model $actor, bool $allSites, array $widgetKeys): void
    {
        $this->merge($actor, function (array $data) use ($allSites, $widgetKeys): array {
            $layouts = is_array($data['dashboard_layout'] ?? null) ? $data['dashboard_layout'] : [];
            $layouts[$allSites ? 'all' : 'site'] = $this->normalise($widgetKeys, 100);
            $data['dashboard_layout'] = $layouts;

            return $data;
        });
    }

    /** @return array{hidden: list<string>, sizes: array<string, string>} */
    public function dashboardOptions(Model $actor, bool $allSites): array
    {
        if (! $this->available($actor)) {
            return ['hidden' => [], 'sizes' => []];
        }

        $raw = array_key_exists(self::COLUMN, $actor->getAttributes())
            ? $actor->getAttribute(self::COLUMN)
            : $actor->newQuery()->whereKey($actor->getKey())->value(self::COLUMN);
        $data = is_array($raw) ? $raw : json_decode((string) $raw, true);
        $options = is_array($data) ? data_get($data, 'dashboard_options.' . ($allSites ? 'all' : 'site'), []) : [];
        $sizes = [];
        if (is_array($options) && is_array($options['sizes'] ?? null)) {
            foreach ($options['sizes'] as $key => $size) {
                if (is_string($key) && in_array($size, ['standard', 'wide'], true)) {
                    $sizes[$key] = $size;
                }
            }
        }

        return ['hidden' => $this->normalise(is_array($options) ? ($options['hidden'] ?? []) : [], 100), 'sizes' => $sizes];
    }

    /**
     * @param  list<string>  $order
     * @param  list<string>  $hidden
     * @param  array<string, string>  $sizes
     */
    public function saveDashboardPreferences(Model $actor, bool $allSites, array $order, array $hidden, array $sizes): void
    {
        $this->merge($actor, function (array $data) use ($allSites, $order, $hidden, $sizes): array {
            $scope = $allSites ? 'all' : 'site';
            $layouts = is_array($data['dashboard_layout'] ?? null) ? $data['dashboard_layout'] : [];
            $layouts[$scope] = $this->normalise($order, 100);
            $data['dashboard_layout'] = $layouts;
            $options = is_array($data['dashboard_options'] ?? null) ? $data['dashboard_options'] : [];
            $options[$scope] = ['hidden' => $this->normalise($hidden, 100), 'sizes' => array_filter($sizes, static fn (mixed $size): bool => in_array($size, ['standard', 'wide'], true))];
            $data['dashboard_options'] = $options;

            return $data;
        });
    }

    public function resetDashboardLayout(Model $actor, bool $allSites): void
    {
        $this->merge($actor, static function (array $data) use ($allSites): array {
            $layouts = is_array($data['dashboard_layout'] ?? null) ? $data['dashboard_layout'] : [];
            unset($layouts[$allSites ? 'all' : 'site']);
            $data['dashboard_layout'] = $layouts;
            $options = is_array($data['dashboard_options'] ?? null) ? $data['dashboard_options'] : [];
            unset($options[$allSites ? 'all' : 'site']);
            $data['dashboard_options'] = $options;

            return $data;
        });
    }

    /** @param array{pinned: list<string>, recent: list<string>} $state */
    private function write(Model $actor, array $state): void
    {
        $this->merge($actor, static fn (array $data): array => array_replace($data, $state));
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutator
     */
    private function merge(Model $actor, callable $mutator): void
    {
        if (! $this->available($actor)) {
            return;
        }

        $actor->getConnection()->transaction(function () use ($actor, $mutator): void {
            $query = $actor->newQuery()->whereKey($actor->getKey());
            $raw = (clone $query)->lockForUpdate()->value(self::COLUMN);
            $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);
            $data = $mutator(is_array($decoded) ? $decoded : []);
            $encoded = json_encode($data, JSON_THROW_ON_ERROR);
            $query->update([self::COLUMN => $encoded]);
            $actor->setAttribute(self::COLUMN, $encoded);
        });
    }

    private function available(Model $actor): bool
    {
        if (! $actor instanceof Authenticatable) {
            return false;
        }

        try {
            return Schema::hasTable($actor->getTable()) && Schema::hasColumn($actor->getTable(), self::COLUMN);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return list<string> */
    private function normalise(mixed $value, int $limit): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && $item !== ''));

        return array_slice(array_values(array_unique($items)), 0, $limit);
    }
}
