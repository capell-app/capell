<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Widgets;

use Capell\Admin\Exceptions\ContentWidgetStateTraversalLimitExceeded;
use Closure;

final class BoundedContentWidgetStateTraversal
{
    public const int MAX_DEPTH = 64;

    public const int MAX_NODES = 10_000;

    /**
     * @param  array<int|string, mixed>  $state
     * @param  Closure(array<int|string, mixed>): array<int|string, mixed>  $transform
     * @param  Closure(array<int|string, mixed>): bool  $shouldDescend
     * @return array<int|string, mixed>
     */
    public static function transform(array $state, Closure $transform, Closure $shouldDescend): array
    {
        self::assertWithinBounds($state, $shouldDescend);

        $visitedNodes = 0;

        return self::transformNode($state, 0, $visitedNodes, $transform, $shouldDescend);
    }

    /**
     * @param  array<int|string, mixed>  $state
     * @param  Closure(array<int|string, mixed>): bool  $shouldDescend
     */
    private static function assertWithinBounds(array $state, Closure $shouldDescend): void
    {
        /** @var list<array{state: array<int|string, mixed>, depth: int}> $pending */
        $pending = [['state' => $state, 'depth' => 0]];
        $visitedNodes = 0;

        while ($pending !== []) {
            $current = array_pop($pending);
            $currentState = $current['state'];
            $depth = $current['depth'];

            self::assertNodeWithinBounds($depth, ++$visitedNodes);

            if (! $shouldDescend($currentState)) {
                continue;
            }

            foreach ($currentState as $value) {
                if (is_array($value)) {
                    $pending[] = ['state' => $value, 'depth' => $depth + 1];
                }
            }
        }
    }

    /**
     * @param  array<int|string, mixed>  $state
     * @param  Closure(array<int|string, mixed>): array<int|string, mixed>  $transform
     * @param  Closure(array<int|string, mixed>): bool  $shouldDescend
     * @return array<int|string, mixed>
     */
    private static function transformNode(
        array $state,
        int $depth,
        int &$visitedNodes,
        Closure $transform,
        Closure $shouldDescend,
    ): array {
        self::assertNodeWithinBounds($depth, ++$visitedNodes);

        $descend = $shouldDescend($state);
        $state = $transform($state);

        if (! $descend) {
            return $state;
        }

        foreach ($state as $key => $value) {
            if (is_array($value)) {
                $state[$key] = self::transformNode(
                    $value,
                    $depth + 1,
                    $visitedNodes,
                    $transform,
                    $shouldDescend,
                );
            }
        }

        return $state;
    }

    private static function assertNodeWithinBounds(int $depth, int $visitedNodes): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw ContentWidgetStateTraversalLimitExceeded::depth();
        }

        if ($visitedNodes > self::MAX_NODES) {
            throw ContentWidgetStateTraversalLimitExceeded::nodes();
        }
    }
}
