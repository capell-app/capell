<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Interactions;

use BackedEnum;
use Capell\Core\Data\Interactions\InteractionTargetData;
use Capell\Core\Data\Interactions\InteractionTriggerData;
use Capell\Core\Enums\InteractionBehavior;
use Capell\Core\Enums\InteractionTargetType;
use Capell\Core\Enums\InteractionTriggerEvent;
use Capell\Core\Support\Security\PublicUrlSanitizer;
use Illuminate\Support\Arr;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolveInteractionTriggersAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<int|string, mixed>  $instanceTriggers
     * @param  array<int|string, mixed>  $typeDefaultTriggers
     * @return array<int, InteractionTriggerData>
     */
    public function handle(array $instanceTriggers = [], array $typeDefaultTriggers = []): array
    {
        $triggers = $instanceTriggers !== [] ? $instanceTriggers : $typeDefaultTriggers;

        return collect($triggers)
            ->filter(fn (mixed $trigger): bool => is_array($trigger))
            ->map(fn (array $trigger, int|string $index): ?InteractionTriggerData => $this->triggerData($trigger, $index))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $blockData
     * @param  array<int|string, mixed>  $typeDefaultTriggers
     * @return array<int, InteractionTriggerData>
     */
    public function fromWidgetBlockData(array $blockData, array $typeDefaultTriggers = []): array
    {
        $interactions = Arr::get($blockData, 'data.__capell.interactions');

        return $this->handle(is_array($interactions) ? $interactions : [], $typeDefaultTriggers);
    }

    /**
     * @param  array<string, mixed>  $trigger
     */
    private function triggerData(array $trigger, int|string $index): ?InteractionTriggerData
    {
        $label = $this->stringValue($trigger['label'] ?? null);

        if ($label === null) {
            return null;
        }

        $target = $this->targetData(is_array($trigger['target'] ?? null) ? $trigger['target'] : $trigger);

        if (! $target instanceof InteractionTargetData) {
            return null;
        }

        return new InteractionTriggerData(
            key: $this->stringValue($trigger['key'] ?? null) ?? 'interaction-' . $index,
            label: $label,
            icon: $this->stringValue($trigger['icon'] ?? null),
            style: $this->stringValue($trigger['style'] ?? null) ?? 'primary',
            event: $this->enumValue(InteractionTriggerEvent::class, $trigger['event'] ?? null, InteractionTriggerEvent::Click),
            behavior: $this->enumValue(InteractionBehavior::class, $trigger['behavior'] ?? null, InteractionBehavior::Modal),
            target: $target,
            analyticsKey: $this->stringValue($trigger['analytics_key'] ?? null),
            ariaLabel: $this->stringValue($trigger['aria_label'] ?? null),
            modalSize: $this->stringValue($trigger['modal_size'] ?? null),
            closeOnBackdrop: $this->booleanValue($trigger['close_on_backdrop'] ?? null, true),
        );
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function targetData(array $target): ?InteractionTargetData
    {
        $type = $this->enumValue(InteractionTargetType::class, $target['target_type'] ?? $target['type'] ?? null, InteractionTargetType::Widget);

        if ($type === InteractionTargetType::Widget) {
            $targetWidget = $this->targetWidget($target['target_widget'] ?? null);
            $widgetType = $this->stringValue($target['widget_type'] ?? null) ?? $targetWidget['type'];

            if ($widgetType === null) {
                return null;
            }

            return new InteractionTargetData(
                type: $type,
                widgetType: $widgetType,
                widgetData: is_array($target['widget_data'] ?? null) ? $target['widget_data'] : $targetWidget['data'],
                presentationSettings: is_array($target['presentation'] ?? null) ? $target['presentation'] : [],
                fallbackUrl: $this->safeUrl($target['fallback_url'] ?? null),
            );
        }

        if ($type === InteractionTargetType::Fragment) {
            $reference = $this->stringValue($target['fragment_reference'] ?? null);

            return $reference === null
                ? null
                : new InteractionTargetData(type: $type, fragmentReference: $reference, fallbackUrl: $this->safeUrl($target['fallback_url'] ?? null));
        }

        if ($type === InteractionTargetType::Url) {
            $url = $this->safeUrl($target['url'] ?? null);

            return $url === null ? null : new InteractionTargetData(type: $type, url: $url);
        }

        $publicActionKey = $this->stringValue($target['public_action_key'] ?? null);

        return $publicActionKey === null ? null : new InteractionTargetData(type: $type, publicActionKey: $publicActionKey);
    }

    /**
     * @template TEnum of \BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @param  TEnum  $default
     * @return TEnum
     */
    private function enumValue(string $enum, mixed $value, BackedEnum $default): BackedEnum
    {
        if (! is_string($value) || $value === '') {
            return $default;
        }

        return $enum::tryFrom($value) ?? $default;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function safeUrl(mixed $value): ?string
    {
        return PublicUrlSanitizer::sanitize($value);
    }

    private function booleanValue(mixed $value, bool $default): bool
    {
        return is_bool($value) ? $value : $default;
    }

    /**
     * @return array{type: ?string, data: array<string, mixed>}
     */
    private function targetWidget(mixed $value): array
    {
        if (! is_array($value)) {
            return ['type' => null, 'data' => []];
        }

        $first = collect($value)->first(fn (mixed $item): bool => is_array($item));

        if (! is_array($first)) {
            return ['type' => null, 'data' => []];
        }

        $type = $this->stringValue($first['type'] ?? null);
        $data = is_array($first['data'] ?? null) ? $first['data'] : [];

        return ['type' => $type, 'data' => $data];
    }
}
