<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Component;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static array<string, true|array<string, mixed>> run(Component $component)
 */
class GetFlatComponentKeysAction
{
    use AsFake;
    use AsObject;

    /**
     * @return array<string, true|array<string, mixed>>
     */
    public function handle(Component $component): array
    {
        return $this->getFieldKeys($component);
    }

    /**
     * @return array<string, true|array<string, mixed>>
     */
    private function getFieldKeys(Component $component): array
    {
        $result = [];
        foreach ($component->getChildComponents() as $child) {
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
