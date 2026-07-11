<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static string run(string $name, int $index)
 */
class GenerateComponentKeyAction
{
    use AsObject;

    public function handle(string $name, int $index): string
    {
        $base = str($name)
            ->lower()
            ->replace(' ', '-')
            ->replace('_', '-')
            ->replace('--', '-')
            ->trim('-');

        return $base . ($index > 1 ? '-' . $index : '');
    }
}
