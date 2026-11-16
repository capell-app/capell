<?php

declare(strict_types=1);

namespace Capell\Core\Support\Extensions;

use Capell\Core\Data\Extensions\DeprecationData;
use InvalidArgumentException;

/**
 * Records deprecated surfaces as they are used, so the extension audit and the
 * doctor can tell an operator what to change before the removal lands.
 *
 * Entries are kept per surface for the life of the process: a surface used on
 * every request is one entry with a count, never an unbounded list.
 */
final class Deprecations
{
    /** @var array<string, DeprecationData> */
    private array $recorded = [];

    /**
     * @param  string  $surface  The deprecated class, method, config key, route or option.
     * @param  string  $replacement  What to use instead.
     * @param  string  $removal  The version or date in which the surface is removed.
     */
    public static function trigger(string $surface, string $replacement, string $removal): void
    {
        resolve(self::class)->record($surface, $replacement, $removal);
    }

    public function record(string $surface, string $replacement, string $removal): void
    {
        foreach (['surface' => $surface, 'replacement' => $replacement, 'removal' => $removal] as $field => $value) {
            throw_if(trim($value) === '', InvalidArgumentException::class, sprintf('A deprecation needs a %s.', $field));
        }

        $this->recorded[$surface] = isset($this->recorded[$surface])
            ? $this->recorded[$surface]->withAnotherOccurrence()
            : new DeprecationData($surface, $replacement, $removal);
    }

    /** @return list<DeprecationData> */
    public function all(): array
    {
        $recorded = $this->recorded;
        ksort($recorded);

        return array_values($recorded);
    }
}
