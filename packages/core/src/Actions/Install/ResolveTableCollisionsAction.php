<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Data\Install\TableCollisionData;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class ResolveTableCollisionsAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  list<TableCollisionData>  $collisions
     * @param  array<string, string>  $aliases
     * @param  list<TableCollisionData>  $planned
     * @return array<string, string>
     */
    public function handle(array $collisions, ?string $prefix, array $aliases, array $planned = []): array
    {
        if ($collisions === [] && ($prefix === null || $prefix === '') && $aliases === []) {
            return [];
        }

        if ($prefix !== null && $prefix !== '') {
            foreach ($planned as $table) {
                if (str_starts_with($table->configKey, 'capell.database.table_aliases.')) {
                    throw_if(preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D', $prefix . $table->name) !== 1, RuntimeException::class, (string) __('capell-core::install.tables.invalid_name', ['table' => $prefix . $table->name]));
                }
            }
        }

        $names = array_map(static fn (TableCollisionData $collision): string => $collision->table, $collisions);
        $available = $planned !== [] ? $planned : $collisions;
        $keys = array_map(static fn (TableCollisionData $table): string => $table->name, $available);
        throw_if(array_diff(array_keys($aliases), $keys) !== [], RuntimeException::class, (string) __('capell-core::install.tables.invalid_option'));
        throw_if($collisions !== [] && ($prefix === null || $prefix === '') && $aliases === [], RuntimeException::class, (string) __('capell-core::install.tables.choice_required', ['tables' => implode(', ', $names)]));
        $result = $prefix !== null && $prefix !== '' ? ['capell.database.table_prefix' => $prefix] : [];
        $targets = [];
        $selected = $collisions;
        foreach ($available as $table) {
            if (array_key_exists($table->name, $aliases) && ! in_array($table->name, array_map(static fn (TableCollisionData $collision): string => $collision->name, $selected), true)) {
                $selected[] = $table;
            }
        }

        foreach ($selected as $collision) {
            $target = $aliases[$collision->name] ?? ($prefix !== null && $prefix !== '' ? $prefix . $collision->name : null);
            throw_unless(is_string($target) && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D', $target) === 1, RuntimeException::class, (string) __('capell-core::install.tables.invalid_name', ['table' => $collision->table]));
            throw_if(Schema::hasTable($target) || in_array($target, $targets, true), RuntimeException::class, (string) __('capell-core::install.tables.target_in_use', ['table' => $target]));
            $targets[] = $target;
            if (str_starts_with($collision->configKey, 'capell.database.table_aliases.') && $prefix !== null && $prefix !== '' && ! array_key_exists($collision->name, $aliases)) {
                $result['capell.database.table_prefix'] = $prefix;
                if (config($collision->configKey) !== null) {
                    $result[$collision->configKey] = $target;
                }
            } else {
                $result[$collision->configKey] = $target;
            }
        }

        return $result;
    }
}
