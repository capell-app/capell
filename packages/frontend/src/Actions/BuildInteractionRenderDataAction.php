<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Data\Interactions\InteractionTriggerData;
use Capell\Core\Enums\InteractionTargetType;
use Capell\Frontend\Contracts\Fragments\PublicFragmentReferenceCodec;
use Capell\Frontend\Contracts\WidgetInteractionLocatorResolver;
use Capell\Frontend\Exceptions\PublicFragmentReferenceInvalid;
use Capell\Frontend\Support\Fragments\PublicFragmentUrlResolverRegistry;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class BuildInteractionRenderDataAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<int, InteractionTriggerData>  $triggers
     * @return array<int, array<string, mixed>>
     */
    public function handle(array $triggers): array
    {
        return collect($triggers)
            ->map(fn (InteractionTriggerData $trigger): ?array => $this->trigger($trigger))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function trigger(InteractionTriggerData $trigger): ?array
    {
        $targetUrl = $this->targetUrl($trigger);

        if ($targetUrl === null) {
            return null;
        }

        return [
            'key' => $trigger->key,
            'label' => $trigger->label,
            'icon' => $trigger->icon,
            'style' => $trigger->style,
            'event' => $trigger->event->value,
            'behavior' => $trigger->behavior->value,
            'target_type' => $trigger->target->type->value,
            'target_url' => $targetUrl,
            'fallback_url' => $trigger->target->fallbackUrl,
            'analytics_key' => $trigger->analyticsKey,
            'aria_label' => $trigger->ariaLabel ?? $trigger->label,
            'modal_size' => $trigger->modalSize,
            'close_on_backdrop' => $trigger->closeOnBackdrop,
        ];
    }

    private function targetUrl(InteractionTriggerData $trigger): ?string
    {
        $target = $trigger->target;

        if ($target->type === InteractionTargetType::Widget) {
            if ($target->widgetType === null || ! app()->bound(WidgetInteractionLocatorResolver::class)) {
                return null;
            }

            return resolve(WidgetInteractionLocatorResolver::class)->resolve($target);
        }

        if ($target->type === InteractionTargetType::Fragment) {
            if ($target->fragmentReference === null) {
                return null;
            }

            if (! app()->bound(PublicFragmentUrlResolverRegistry::class)
                || ! resolve(PublicFragmentUrlResolverRegistry::class)->hasResolvers()) {
                Log::debug('capell-frontend: no public fragment owner is registered; fragment interaction trigger omitted from public output.');

                return null;
            }

            try {
                $reference = resolve(PublicFragmentReferenceCodec::class)->decode($target->fragmentReference);

                return resolve(PublicFragmentUrlResolverRegistry::class)->url($reference);
            } catch (PublicFragmentReferenceInvalid) {
                Log::debug('capell-frontend: public fragment reference is invalid or has no registered owner; trigger omitted from public output.');

                return null;
            }
        }

        if ($target->type === InteractionTargetType::PublicAction) {
            return $target->fallbackUrl;
        }

        return $target->url;
    }
}
