<?php

declare(strict_types=1);

namespace Capell\Core\Support;

use Illuminate\Support\Facades\Schema;

final class UserKeyType
{
    public static function columnType(): string
    {
        if (Schema::hasTable('users')) {
            foreach (Schema::getColumns('users') as $definition) {
                if ($definition['name'] === 'id') {
                    $type = strtolower(explode(' ', $definition['type_name'])[0]);

                    return in_array($type, ['int', 'integer', 'int4'], true) ? 'integer' : 'bigInteger';
                }
            }
        }

        return 'bigInteger';
    }
}
