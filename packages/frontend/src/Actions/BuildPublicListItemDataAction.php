<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Data\PublicListItemData;
use Illuminate\Support\Facades\Blade;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildPublicListItemDataAction
{
    use AsFake;
    use AsObject;

    public function handle(object $item, ?bool $active, ?string $class): PublicListItemData
    {
        $children = method_exists($item, 'relationLoaded') && ! $item->relationLoaded('children') ? [] : ($item->children ?? []);
        $entries = [];
        foreach (is_iterable($children) ? $children : [] as $child) {
            $entries[] = [
                'component' => empty($child->data['component']) ? 'capell::list.item' : $child->data['component'],
                'item' => $child,
            ];
        }

        $renderedChildren = $entries === [] ? '' : Blade::render(<<<'BLADE'
            <x-capell::list class="ml-2" gap="gap-y-0.5">
                @foreach ($entries as $entry)
                    <x-dynamic-component :component="$entry['component']" :class="$class" :item="$entry['item']" />
                @endforeach
            </x-capell::list>
            BLADE, ['entries' => $entries, 'class' => $class]);

        return new PublicListItemData($item->data['url'] ?? '', $item->label ?? '', $active ?? (bool) ($item->active ?? false), $renderedChildren);
    }
}
