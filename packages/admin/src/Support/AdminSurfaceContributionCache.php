<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Enums\AdminSurfaceContributionType;
use Capell\Core\Support\Extensions\ExtensionPosition;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;

final class AdminSurfaceContributionCache
{
    private const int CACHE_SCHEMA_VERSION = 2;

    public function __construct(
        private readonly AdminSurfaceContributionRegistry $registry,
        private readonly Filesystem $filesystem,
        private readonly Application $application,
    ) {}

    public function cache(): void
    {
        $cachePath = $this->path();
        $cacheDirectory = dirname($cachePath);

        if (! $this->filesystem->isDirectory($cacheDirectory)) {
            $this->filesystem->makeDirectory($cacheDirectory, 0755, true);
        }

        $this->filesystem->put($cachePath, '<?php return ' . var_export([
            'schema_version' => self::CACHE_SCHEMA_VERSION,
            ...$this->serializableContributions(),
        ], true) . ';' . PHP_EOL);
    }

    public function clear(): void
    {
        $this->filesystem->delete($this->path());
    }

    public function exists(): bool
    {
        return $this->filesystem->exists($this->path());
    }

    public function path(): string
    {
        return $this->application->bootstrapPath('cache/capell-admin-configurators.php');
    }

    public function restore(): void
    {
        if (! $this->exists()) {
            return;
        }

        /** @var array<string, mixed> $cachedPayload */
        $cachedPayload = require $this->path();
        $cachedContributions = $this->cachedContributions($cachedPayload);

        if ($cachedContributions === null) {
            return;
        }

        $contributions = $this->hydrateContributions($cachedContributions);

        $this->registry->clear();

        foreach ($contributions as $groupedContributions) {
            foreach ($groupedContributions as $contribution) {
                $this->registry->register($contribution);
            }
        }
    }

    /**
     * @return array{kind: string, priority: int, anchor: string|null}|null
     */
    private static function serializablePosition(?ExtensionPosition $position): ?array
    {
        if (! $position instanceof ExtensionPosition) {
            return null;
        }

        return [
            'kind' => $position->kind,
            'priority' => $position->priority,
            'anchor' => $position->anchor,
        ];
    }

    private static function hydratePosition(mixed $position): ?ExtensionPosition
    {
        if (! is_array($position)
            || ! is_string($position['kind'] ?? null)
            || ! is_int($position['priority'] ?? null)
            || (($position['anchor'] ?? null) !== null && ! is_string($position['anchor']))
        ) {
            return null;
        }

        $kind = $position['kind'];
        $priority = $position['priority'];
        $anchor = $position['anchor'] ?? null;

        if (in_array($kind, ['before', 'after'], true) && (! is_string($anchor) || trim($anchor) === '')) {
            return null;
        }

        return match ($kind) {
            'first' => ExtensionPosition::first(),
            'last' => ExtensionPosition::last(),
            'priority' => ExtensionPosition::priority($priority),
            'before' => ExtensionPosition::before($anchor),
            'after' => ExtensionPosition::after($anchor),
            default => null,
        };
    }

    /**
     * @return array<string, array<string, array{
     *     type: string,
     *     class: string,
     *     key: string,
     *     group: string|null,
     *     name: string,
     *     tag: string|null,
     *     owner: string,
     *     position: array{kind: string, priority: int, anchor: string|null}|null,
     *     source: string,
     * }>>
     */
    private function serializableContributions(): array
    {
        return array_map(
            static fn (array $groupedContributions): array => array_map(
                static fn (AdminSurfaceContributionData $contribution): array => [
                    'type' => $contribution->type->value,
                    'class' => $contribution->class,
                    'key' => $contribution->key,
                    'group' => $contribution->group,
                    'name' => $contribution->name,
                    'tag' => $contribution->tag,
                    'owner' => $contribution->owner,
                    'position' => self::serializablePosition($contribution->position),
                    'source' => $contribution->source,
                ],
                $groupedContributions,
            ),
            $this->registry->all(),
        );
    }

    /**
     * @param  array<string, array<string, array{
     *     type: string,
     *     class: string,
     *     key: string,
     *     group: string|null,
     *     name: string,
     *     tag: string|null,
     *     owner?: string,
     *     position?: array{kind: string, priority: int, anchor: string|null}|null,
     *     source?: string,
     * }>>  $cachedContributions
     * @return array<string, array<string, AdminSurfaceContributionData>>
     */
    private function hydrateContributions(array $cachedContributions): array
    {
        return array_map(
            static fn (array $groupedContributions): array => array_map(
                static fn (array $contribution): AdminSurfaceContributionData => new AdminSurfaceContributionData(
                    type: AdminSurfaceContributionType::from($contribution['type']),
                    class: $contribution['class'],
                    key: $contribution['key'],
                    group: $contribution['group'],
                    name: $contribution['name'],
                    tag: $contribution['tag'],
                    owner: $contribution['owner'] ?? 'capell-app/admin',
                    position: self::hydratePosition($contribution['position'] ?? null),
                    source: $contribution['source'] ?? AdminSurfaceContributionData::class,
                ),
                $groupedContributions,
            ),
            $cachedContributions,
        );
    }

    /**
     * @param  array<string, mixed>  $cachedPayload
     * @return array<string, array<string, array{
     *     type: string,
     *     class: string,
     *     key: string,
     *     group: string|null,
     *     name: string,
     *     tag: string|null,
     *     owner?: string,
     *     position?: array{kind: string, priority: int, anchor: string|null}|null,
     *     source?: string,
     * }>>|null
     */
    private function cachedContributions(array $cachedPayload): ?array
    {
        if (! array_key_exists('schema_version', $cachedPayload)) {
            /** @var array<string, array<string, array{type: string, class: string, key: string, group: string|null, name: string, tag: string|null}>> $cachedPayload */
            return $cachedPayload;
        }

        if ($cachedPayload['schema_version'] !== self::CACHE_SCHEMA_VERSION) {
            return null;
        }

        unset($cachedPayload['schema_version']);

        /** @var array<string, array<string, array{
         *     type: string,
         *     class: string,
         *     key: string,
         *     group: string|null,
         *     name: string,
         *     tag: string|null,
         *     owner?: string,
         *     position?: array{kind: string, priority: int, anchor: string|null}|null,
         *     source?: string,
         * }>> $cachedPayload */
        return $cachedPayload;
    }
}
