<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Enums\AdminSurfaceContributionType;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;

final class AdminSurfaceContributionCache
{
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

        $this->filesystem->put(
            $cachePath,
            '<?php return ' . var_export($this->serializableContributions(), true) . ';' . PHP_EOL,
        );
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

        /** @var array<string, array<string, array{type: string, class: string, key: string, group: string|null, name: string, tag: string|null}>> $cachedContributions */
        $cachedContributions = require $this->path();
        $contributions = $this->hydrateContributions($cachedContributions);

        $this->registry->clear();

        foreach ($contributions as $groupedContributions) {
            foreach ($groupedContributions as $contribution) {
                $this->registry->register($contribution);
            }
        }
    }

    /** @return array<string, array<string, array{type: string, class: string, key: string, group: string|null, name: string, tag: string|null}>> */
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
                ],
                $groupedContributions,
            ),
            $this->registry->all(),
        );
    }

    /**
     * @param  array<string, array<string, array{type: string, class: string, key: string, group: string|null, name: string, tag: string|null}>>  $cachedContributions
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
                ),
                $groupedContributions,
            ),
            $cachedContributions,
        );
    }
}
