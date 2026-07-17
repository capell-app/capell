<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class GenerateUniqueKeyAction
{
    use AsFake;
    use AsObject;

    public function handle(Model $model, string $var = 'name'): string
    {
        $name = $model->getAttribute($var);
        throw_unless($name, InvalidArgumentException::class, sprintf('Model must have a %s to generate a unique key.', $var));

        $base = str($name)->slug()->toString();
        $key = $base;

        while ($model->newQuery()->where('key', $key)->exists()) {
            $key = IncrementNameAction::run($key, '-');
        }

        return $key;
    }
}
