<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Widgets;

use Capell\Admin\Exceptions\ContentWidgetStateTraversalLimitExceeded;

final class UnavailableContentWidgetState
{
    public const string PLACEHOLDER_TYPE = '__capell_unavailable_widget';

    public const string OPAQUE_STATE_KEY = '__capell_opaque_widget_state';

    /**
     * @param  array<int|string, mixed>  $state
     * @param  list<string>  $registeredWidgetKeys
     * @return array<int|string, mixed>
     */
    public static function prepare(array $state, array $registeredWidgetKeys): array
    {
        $registered = array_fill_keys($registeredWidgetKeys, true);

        try {
            return BoundedContentWidgetStateTraversal::transform(
                $state,
                static function (array $node) use ($registered): array {
                    $type = $node['type'] ?? null;

                    if (! is_string($type)
                        || isset($registered[$type])
                        || ($type === self::PLACEHOLDER_TYPE
                            && is_array($node['data'][self::OPAQUE_STATE_KEY] ?? null))) {
                        return $node;
                    }

                    return [
                        'type' => self::PLACEHOLDER_TYPE,
                        'data' => [self::OPAQUE_STATE_KEY => $node],
                    ];
                },
                static fn (array $node): bool => ! is_string($node['type'] ?? null)
                    || isset($registered[$node['type']]),
            );
        } catch (ContentWidgetStateTraversalLimitExceeded) {
            return $state;
        }
    }

    /**
     * @param  array<int|string, mixed>  $state
     * @param  list<string>  $registeredWidgetKeys
     * @return array<int|string, mixed>
     */
    public static function restore(array $state, array $registeredWidgetKeys): array
    {
        $registered = array_fill_keys($registeredWidgetKeys, true);

        return BoundedContentWidgetStateTraversal::transform(
            $state,
            static function (array $node): array {
                if (($node['type'] ?? null) !== self::PLACEHOLDER_TYPE) {
                    return $node;
                }

                $opaqueState = $node['data'][self::OPAQUE_STATE_KEY] ?? null;

                return is_array($opaqueState) ? $opaqueState : $node;
            },
            static fn (array $node): bool => ! is_string($node['type'] ?? null)
                || isset($registered[$node['type']]),
        );
    }
}
