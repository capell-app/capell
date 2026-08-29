<?php

declare(strict_types=1);

namespace Capell\Core\Support\Extensions;

use Capell\Core\Data\Extensions\ExtensionOrderDiagnosticData;
use LogicException;

final class ExtensionOrderResolver
{
    /** @var list<ExtensionOrderDiagnosticData> */
    private array $diagnostics = [];

    /** @return list<ExtensionOrderDiagnosticData> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * @template T
     *
     * @param  iterable<T>  $items
     * @param  callable(T, int):string  $key
     * @param  callable(T):ExtensionPosition|null  $position
     * @return list<T>
     */
    public function resolve(iterable $items, callable $key, ?callable $position = null): array
    {
        $this->diagnostics = [];
        $values = is_array($items) ? array_values($items) : iterator_to_array($items, false);
        $keys = [];
        foreach ($values as $i => $value) {
            $keys[] = $key($value, $i);
        }
        $index = [];
        foreach ($keys as $i => $itemKey) {
            if ($itemKey === '' || isset($index[$itemKey])) {
                throw new LogicException("Extension keys must be unique and non-empty: {$itemKey}");
            }
            $index[$itemKey] = $i;
        }

        $edges = array_fill(0, count($values), []);
        $in = array_fill(0, count($values), 0);
        foreach ($values as $i => $item) {
            $pos = $position === null ? null : $position($item);
            if ($pos === null || $pos->anchor === null || ! isset($index[$pos->anchor])) {
                if ($pos?->anchor !== null) {
                    $this->diagnostics[] = new ExtensionOrderDiagnosticData('missing-anchor', $keys[$i], $pos->anchor);
                }

                continue;
            }
            $j = $index[$pos->anchor];
            $from = $pos->kind === 'before' ? $i : $j;
            $to = $pos->kind === 'before' ? $j : $i;
            $edges[$from][] = $to;
            $in[$to]++;
        }

        $ready = array_keys(array_filter($in, static fn (int $count): bool => $count === 0));
        $ordered = [];
        while ($ready !== []) {
            usort($ready, function (int $a, int $b) use ($values, $position): int {
                $weight = static function (int $i) use ($values, $position): int {
                    $p = $position === null ? null : $position($values[$i]);

                    return match ($p?->kind) {
                        'first' => PHP_INT_MIN,
                        'last' => PHP_INT_MAX,
                        default => $p === null ? 0 : $p->priority,
                    };
                };

                return [$weight($a), $a] <=> [$weight($b), $b];
            });
            $i = array_shift($ready);
            if (! is_int($i)) {
                throw new LogicException('Extension ordering queue contained an invalid item index.');
            }
            $ordered[] = $values[$i];
            foreach ($edges[$i] as $to) {
                if (--$in[$to] === 0) {
                    $ready[] = $to;
                }
            }
        }
        if (count($ordered) !== count($values)) {
            $cycle = [];
            foreach ($values as $i => $value) {
                if ($in[$i] > 0) {
                    $cycle[] = $keys[$i];
                    $ordered[] = $value;
                }
            }
            $this->diagnostics[] = new ExtensionOrderDiagnosticData('cycle', $cycle[0], cycle: $cycle);
        }

        return $ordered;
    }
}
