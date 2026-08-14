<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Workspace;

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

    /** @param array{pinned: list<string>, recent: list<string>} $state */
    private function write(Model $actor, array $state): void
    {
        if (! $this->available($actor)) {
            return;
        }

        $actor->forceFill([self::COLUMN => json_encode($state, JSON_THROW_ON_ERROR)])->save();
    }

    private function available(Model $actor): bool
    {
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
