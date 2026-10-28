<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Pass a repeater item's child schema so state-dependent closures evaluate against
 * the item record rather than the repeater's parent model.
 *
 * @method static array<string, true|array<string, mixed>> run(Component|Schema $component)
 */
class GetFlatComponentKeysAction
{
    use AsFake;
    use AsObject;

    /**
     * @return array<string, true|array<string, mixed>>
     */
    public function handle(Component|Schema $component): array
    {
        if ($component instanceof Schema) {
            return $this->collectFieldKeys($component->getComponents(withActions: false));
        }

        return $this->getFieldKeys($component);
    }

    /**
     * @return array<string, true|array<string, mixed>>
     */
    private function getFieldKeys(Component $component): array
    {
        return $this->collectFieldKeys($component->getChildComponents());
    }

    /**
     * @param  array<mixed>  $children
     * @return array<string, true|array<string, mixed>>
     */
    private function collectFieldKeys(array $children): array
    {
        $result = [];
        foreach ($children as $child) {
            if (! $child instanceof Component) {
                continue;
            }

            if ($child instanceof Field) {
                if (! $child->isDehydrated()) {
                    continue;
                }

                $statePath = $child->getStatePath(false) ?? '';

                if ($statePath !== '') {
                    $result[$statePath] = true;
                }
            } else {
                $subKeys = $this->getFieldKeys($child);

                $result = array_merge_recursive($result, $subKeys);
            }
        }

        return $result;
    }
}
