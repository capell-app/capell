<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Widgets;

use Capell\Admin\Support\Widgets\BoundedContentWidgetStateTraversal;
use Capell\Admin\Support\Widgets\WidgetDiscovery;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsObject;

final class RegenerateContentWidgetIdentitiesAction
{
    use AsObject;

    /** @var array<string, true> */
    private array $generatedIdentities = [];

    public function __construct(
        private readonly WidgetDiscovery $widgetDiscovery,
    ) {}

    /**
     * @param  array<int|string, mixed>  $state
     * @return array<int|string, mixed>
     */
    public function handle(array $state): array
    {
        $this->generatedIdentities = [];
        $registeredWidgetKeys = array_fill_keys(
            array_keys($this->widgetDiscovery->registeredWidgets()),
            true,
        );

        return BoundedContentWidgetStateTraversal::transform(
            $state,
            fn (array $node): array => $this->regenerateNode($node, $registeredWidgetKeys),
            static fn (array $node): bool => ! is_string($node['type'] ?? null)
                || isset($registeredWidgetKeys[$node['type']]),
        );
    }

    /**
     * @param  array<int|string, mixed>  $node
     * @param  array<string, true>  $registeredWidgetKeys
     * @return array<int|string, mixed>
     */
    private function regenerateNode(array $node, array $registeredWidgetKeys): array
    {
        $widgetKey = $node['type'] ?? null;

        if (! is_string($widgetKey) || ! isset($registeredWidgetKeys[$widgetKey])) {
            return $node;
        }

        $data = is_array($node['data'] ?? null) ? $node['data'] : [];
        $capellState = is_array($data['__capell'] ?? null) ? $data['__capell'] : [];
        $capellState['instance_id'] = $this->newIdentity();
        $data['__capell'] = $capellState;
        $node['data'] = $data;

        return $node;
    }

    private function newIdentity(): string
    {
        do {
            $identity = (string) Str::uuid();
        } while (isset($this->generatedIdentities[$identity]));

        $this->generatedIdentities[$identity] = true;

        return $identity;
    }
}
