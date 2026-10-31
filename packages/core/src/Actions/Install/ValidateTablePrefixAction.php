<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Support\TableName;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class ValidateTablePrefixAction
{
    use AsFake;
    use AsObject;

    /**
     * Rejects a prefix that is not a safe identifier fragment or that would push the longest
     * prefixed Capell table name past the identifier limit (63 characters, the PostgreSQL cap;
     * MySQL allows 64).
     *
     * @param  list<string>  $baseTables  Every base table name the prefix would be applied to.
     *
     * @throws RuntimeException
     */
    public function handle(string $prefix, array $baseTables): void
    {
        if ($prefix === '') {
            return;
        }

        throw_if(
            preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $prefix) !== 1,
            RuntimeException::class,
            (string) __('capell-core::install.tables.invalid_prefix'),
        );

        $longest = '';
        foreach ($baseTables as $base) {
            if (strlen($base) > strlen($longest)) {
                $longest = $base;
            }
        }

        throw_if(
            strlen($prefix) + strlen($longest) > TableName::MAX_IDENTIFIER_LENGTH,
            RuntimeException::class,
            (string) __('capell-core::install.tables.prefix_too_long', [
                'prefix' => $prefix,
                'table' => $longest,
                'max' => TableName::MAX_IDENTIFIER_LENGTH - strlen($longest),
            ]),
        );
    }
}
