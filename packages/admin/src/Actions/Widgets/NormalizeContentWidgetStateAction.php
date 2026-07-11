<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Widgets;

use Capell\Admin\Contracts\Widgets\ContentWidgetStateProcessor;
use Capell\Admin\Exceptions\ContentWidgetStateTraversalLimitExceeded;
use Capell\Admin\Support\Widgets\BoundedContentWidgetStateTraversal;
use Capell\Admin\Support\Widgets\WidgetDiscovery;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsObject;

final class NormalizeContentWidgetStateAction
{
    use AsObject;

    /** @var array<string, true> */
    private array $seenIdentities = [];

    public function __construct(
        private readonly WidgetDiscovery $widgetDiscovery,
        private readonly Container $container,
    ) {}

    /**
     * @param  array<int|string, mixed>  $state
     * @return array<int|string, mixed>
     */
    public function handle(array $state): array
    {
        $this->seenIdentities = [];

        $registeredWidgetKeys = array_fill_keys(
            array_keys($this->widgetDiscovery->registeredWidgets()),
            true,
        );

        try {
            return BoundedContentWidgetStateTraversal::transform(
                $state,
                fn (array $node): array => $this->normalizeNode($node, $registeredWidgetKeys),
                static fn (array $node): bool => ! is_string($node['type'] ?? null)
                    || isset($registeredWidgetKeys[$node['type']]),
            );
        } catch (ContentWidgetStateTraversalLimitExceeded) {
            return $state;
        }
    }

    /**
     * @param  array<int|string, mixed>  $widget
     * @param  array<string, true>  $registeredWidgetKeys
     * @return array<int|string, mixed>
     */
    private function normalizeNode(array $widget, array $registeredWidgetKeys): array
    {
        $widgetKey = $widget['type'] ?? null;

        if (! is_string($widgetKey) || ! isset($registeredWidgetKeys[$widgetKey])) {
            return $widget;
        }

        $data = is_array($widget['data'] ?? null) ? $widget['data'] : [];
        $capellState = is_array($data['__capell'] ?? null) ? $data['__capell'] : [];
        $identity = $capellState['instance_id'] ?? null;

        if (! is_string($identity) || ! $this->isCanonicalUniqueUuid($identity)) {
            $identity = $this->newUniqueIdentity();
        }

        $this->seenIdentities[$identity] = true;
        $capellState['instance_id'] = $identity;
        $data['__capell'] = $capellState;
        $widget['data'] = $data;

        foreach ($this->container->tagged(ContentWidgetStateProcessor::TAG) as $processor) {
            if ($processor instanceof ContentWidgetStateProcessor) {
                $widget = $processor->process($widgetKey, $widget);
            }
        }

        return $widget;
    }

    private function isCanonicalUniqueUuid(string $identity): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $identity,
        ) === 1 && ! isset($this->seenIdentities[$identity]);
    }

    private function newUniqueIdentity(): string
    {
        do {
            $identity = (string) Str::uuid();
        } while (isset($this->seenIdentities[$identity]));

        return $identity;
    }
}
