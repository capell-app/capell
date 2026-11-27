<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Structure;

use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Models\Blueprint;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Creates a page type: a page blueprint editors can choose when they add a page.
 *
 * @method static Blueprint run(string $key, string $name)
 */
final class CreatePageTypeAction
{
    use AsFake;
    use AsObject;

    public function handle(string $key, string $name): Blueprint
    {
        StructureKey::assertValid($key, 'page type');

        throw_if(
            Blueprint::query()->where('type', BlueprintSubjectEnum::Page->value)->where('key', $key)->exists(),
            InvalidArgumentException::class,
            sprintf('A page type with the key [%s] already exists. Choose another key or edit it in the admin panel under Blueprints.', $key),
        );

        return Blueprint::query()->create([
            'type' => BlueprintSubjectEnum::Page,
            'key' => $key,
            'name' => $name,
            'default' => false,
            'status' => true,
        ]);
    }
}
