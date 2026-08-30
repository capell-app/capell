<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Enums\AdminSurfaceContributionType;
use Capell\Core\Support\Extensions\ExtensionPosition;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Throwable;
use UnexpectedValueException;

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

        $cachedPayload = $this->loadPayload();

        if ($cachedPayload === null) {
            return;
        }

        try {
            $cachedContributions = $this->cachedContributions($cachedPayload);

            if ($cachedContributions === null) {
                return;
            }

            $contributions = $this->hydrateContributions($cachedContributions);
        } catch (Throwable) {
            return;
        }

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

    /**
     * @param  array{kind: string, priority: int, anchor: string|null}|null  $position
     */
    private static function hydratePosition(?array $position): ?ExtensionPosition
    {
        if ($position === null) {
            return null;
        }

        $kind = $position['kind'];
        $priority = $position['priority'];
        $anchor = $position['anchor'];

        if ($kind === 'before' || $kind === 'after') {
            throw_unless(is_string($anchor), UnexpectedValueException::class, 'Invalid cached extension position anchor.');

            return $kind === 'before'
                ? ExtensionPosition::before($anchor)
                : ExtensionPosition::after($anchor);
        }

        return match ($kind) {
            'first' => ExtensionPosition::first(),
            'last' => ExtensionPosition::last(),
            'priority' => ExtensionPosition::priority($priority),
            default => throw new UnexpectedValueException('Invalid cached extension position kind.'),
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
     * @return array<string, mixed>|null
     */
    private function loadPayload(): ?array
    {
        try {
            $payload = require $this->path();
        } catch (Throwable) {
            return null;
        }

        return is_array($payload) ? $payload : null;
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
        if (array_key_exists('schema_version', $cachedPayload)
            && $cachedPayload['schema_version'] !== self::CACHE_SCHEMA_VERSION
        ) {
            return null;
        }

        if (array_key_exists('schema_version', $cachedPayload)) {
            unset($cachedPayload['schema_version']);
        }

        $contributions = [];

        foreach ($cachedPayload as $type => $groupedContributions) {
            if (! is_string($type)
                || ! AdminSurfaceContributionType::tryFrom($type) instanceof AdminSurfaceContributionType
                || ! is_array($groupedContributions)
            ) {
                return null;
            }

            foreach ($groupedContributions as $key => $contribution) {
                if (! is_string($key) || ! is_array($contribution)) {
                    return null;
                }

                $validatedContribution = $this->validatedContribution($type, $key, $contribution);

                if ($validatedContribution === null) {
                    return null;
                }

                $contributions[$type][$key] = $validatedContribution;
            }
        }

        return $contributions;
    }

    /**
     * @param  array<string, mixed>  $contribution
     * @return array{
     *     type: string,
     *     class: string,
     *     key: string,
     *     group: string|null,
     *     name: string,
     *     tag: string|null,
     *     owner: string,
     *     position: array{kind: string, priority: int, anchor: string|null}|null,
     *     source: string,
     * }|null
     */
    private function validatedContribution(string $type, string $key, array $contribution): ?array
    {
        if (($contribution['type'] ?? null) !== $type
            || ($contribution['key'] ?? null) !== $key
            || ! is_string($contribution['class'] ?? null)
            || $contribution['class'] === ''
            || (($contribution['group'] ?? null) !== null && ! is_string($contribution['group']))
            || ! is_string($contribution['name'] ?? null)
            || (($contribution['tag'] ?? null) !== null && ! is_string($contribution['tag']))
            || (array_key_exists('owner', $contribution) && ! is_string($contribution['owner']))
            || (array_key_exists('source', $contribution) && ! is_string($contribution['source']))
            || ! $this->validPosition($contribution['position'] ?? null)
        ) {
            return null;
        }

        return [
            'type' => $type,
            'class' => $contribution['class'],
            'key' => $key,
            'group' => $contribution['group'] ?? null,
            'name' => $contribution['name'],
            'tag' => $contribution['tag'] ?? null,
            'owner' => $contribution['owner'] ?? 'capell-app/admin',
            'position' => $contribution['position'] ?? null,
            'source' => $contribution['source'] ?? AdminSurfaceContributionData::class,
        ];
    }

    private function validPosition(mixed $position): bool
    {
        if ($position === null) {
            return true;
        }

        if (! is_array($position)
            || ! array_key_exists('kind', $position)
            || ! array_key_exists('priority', $position)
            || ! array_key_exists('anchor', $position)
            || ! is_string($position['kind'])
            || ! is_int($position['priority'])
            || ($position['anchor'] !== null && ! is_string($position['anchor']))
        ) {
            return false;
        }

        return match ($position['kind']) {
            'first', 'last' => $position['priority'] === 0 && $position['anchor'] === null,
            'priority' => $position['anchor'] === null,
            'before', 'after' => $position['priority'] === 0
                && is_string($position['anchor'])
                && trim($position['anchor']) !== '',
            default => false,
        };
    }
}
