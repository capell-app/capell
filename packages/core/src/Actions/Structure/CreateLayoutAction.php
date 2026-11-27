<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Structure;

use Capell\Core\Enums\LayoutGroupEnum;
use Capell\Core\Models\Layout;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Creates a layout, optionally starting from the containers of an existing layout so a new
 * layout keeps the same areas (header, main, footer and so on) without copying its widgets' content.
 *
 * @method static Layout run(string $key, string $name, ?string $fromKey = null)
 */
final class CreateLayoutAction
{
    use AsFake;
    use AsObject;

    public function handle(string $key, string $name, ?string $fromKey = null): Layout
    {
        StructureKey::assertValid($key, 'layout');

        throw_if(
            Layout::query()->where('key', $key)->exists(),
            InvalidArgumentException::class,
            sprintf('A layout with the key [%s] already exists. Choose another key or edit it in the admin panel under Layouts.', $key),
        );

        $source = null;

        if ($fromKey !== null) {
            $source = Layout::query()->where('key', $fromKey)->first();

            throw_unless(
                $source instanceof Layout,
                InvalidArgumentException::class,
                sprintf('No layout has the key [%s]. Available layouts: %s.', $fromKey, implode(', ', Layout::query()->orderBy('key')->pluck('key')->all())),
            );
        }

        return Layout::query()->create([
            'key' => $key,
            'name' => $name,
            'group' => $source instanceof Layout ? ($source->group ?? LayoutGroupEnum::Default->value) : LayoutGroupEnum::Default->value,
            // Keep the areas but not the widget placements, which belong to the source layout.
            'containers' => $source instanceof Layout ? array_map(
                fn (array $container): array => array_merge($container, ['widgets' => []]),
                $source->containers ?? [],
            ) : null,
            'theme_id' => $source?->theme_id,
            'site_id' => $source?->site_id,
            'default' => false,
            'status' => true,
        ]);
    }
}
