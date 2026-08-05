<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

use Capell\Marketplace\Enums\MarketplaceOperationType;
use Illuminate\Support\Facades\Lang;

/**
 * The words an operation uses for a step every operation takes.
 *
 * Installs, updates and uninstalls share one status enum, one transition
 * whitelist and one timeline, and most of the sentences on that timeline are
 * true for all three — "Composer completed", "Operation cancelled". A few are
 * not: telling an operator that "Composer skipped because the package is
 * already downloaded" during an uninstall is not a wording nit, it describes
 * the opposite of what happened.
 *
 * So the shared sentence stays the default and an operation overrides only the
 * ones it would otherwise get wrong. The alternative — a per-operation copy of
 * the whole vocabulary — makes every future timeline entry three edits, two of
 * which are easy to forget and impossible to notice.
 */
final class MarketplaceOperationVocabulary
{
    public const string NAMESPACE = 'capell-marketplace::marketplace.operations.';

    /**
     * @param  array<string, mixed>  $replace
     */
    public static function translate(
        MarketplaceOperationType $operation,
        string $key,
        array $replace = [],
    ): string {
        return (string) __(self::key($operation, $key), $replace);
    }

    /**
     * The translation key this operation actually resolves for $key: its own
     * override when it declares one, the shared sentence otherwise.
     *
     * Public so a caller that needs the key rather than the sentence — anything
     * asserting which wording a surface chose — asks this rather than
     * reimplementing the fallback.
     */
    public static function key(MarketplaceOperationType $operation, string $key): string
    {
        $scoped = self::NAMESPACE . $operation->value . '.' . $key;

        return Lang::has($scoped) ? $scoped : self::NAMESPACE . $key;
    }
}
