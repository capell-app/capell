<?php

declare(strict_types=1);

namespace Capell\Installer\Actions;

use Capell\Core\Actions\Install\FindTableCollisionsAction;
use Capell\Core\Actions\Install\ResolveTableCollisionsAction;
use Capell\Core\Data\Install\TableCollisionData;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class ResolveInstallerTableNamesAction
{
    use AsObject;

    /** @param list<string> $packages @return array<string, string> */
    public function handle(array $packages, ?string $prefix, ?string $aliases): array
    {
        $detector = resolve(FindTableCollisionsAction::class);
        $collisions = $detector->handle($packages);
        if ($collisions === []) {
            return [];
        }

        $choices = [];
        foreach (preg_split('/\R/', $aliases ?? '') ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            $parts = explode('=', $line, 2);
            throw_unless(count($parts) === 2 && trim($parts[0]) !== '' && trim($parts[1]) !== '', RuntimeException::class, (string) __('capell-core::install.tables.invalid_option'));
            $choices[trim($parts[0])] = trim($parts[1]);
        }

        $changes = resolve(ResolveTableCollisionsAction::class)->handle($collisions, $prefix, $choices, $detector->planned($packages));
        $previous = [];
        try {
            foreach ($changes as $key => $value) {
                $previous[$key] = config($key);
                config()->set($key, $value);
            }

            $remaining = $detector->handle($packages);
            if ($remaining !== []) {
                $names = array_map(static fn ($table): string => $table->table, $remaining);
                throw new RuntimeException((string) __('capell-core::install.tables.choice_required', ['tables' => implode(', ', $names)]));
            }

            $tables = array_map(static fn (TableCollisionData $table): string => $table->table, $detector->planned($packages));
            throw_if(count($tables) !== count(array_unique($tables)), RuntimeException::class, (string) __('capell-core::install.tables.duplicate_targets'));
        } finally {
            foreach ($previous as $key => $value) {
                config()->set($key, $value);
            }
        }

        return $changes;
    }
}
