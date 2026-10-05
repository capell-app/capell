<?php

declare(strict_types=1);

namespace Capell\Core\Support;

use Capell\Core\Exceptions\PageRestoreCancelledException;
use Capell\Core\Models\Page;
use Closure;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Events\NullDispatcher;
use Throwable;

/** Extra cancelling hooks need a pure guard contract before they can join an atomic restore. */
#[Singleton]
final class PageRestoreLifecycle
{
    /** @var array<class-string<Page>, array<string, mixed>> */
    private array $nativeListeners = [];

    /** @var array<class-string<Page>, bool> */
    private array $preExistingGuards = [];

    private int $siteHistoryDepth = 0;

    /** @param class-string<Page> $class */
    public function beginModelBoot(string $class): void
    {
        $dispatcher = $class::getEventDispatcher();
        $this->preExistingGuards[$class] = $dispatcher !== null && $this->listeners($dispatcher, $class) !== [];
    }

    /** @param class-string<Page> $class */
    public function rememberNativeListeners(string $class): void
    {
        $dispatcher = $class::getEventDispatcher();
        if ($dispatcher instanceof Dispatcher && is_callable([$dispatcher, 'getRawListeners'])) {
            $this->nativeListeners[$class] = $this->normalise($this->listeners($dispatcher, $class));
        }
    }

    public function supports(Page $page): bool
    {
        $dispatcher = $page::getEventDispatcher();
        if ($dispatcher === null) {
            return true;
        }

        if (! $dispatcher instanceof Dispatcher || ! is_callable([$dispatcher, 'getRawListeners']) || array_intersect_key($page->dispatchesEvents(), array_flip(['restoring', 'saving', 'updating'])) !== []) {
            return false;
        }

        return ! ($this->preExistingGuards[$page::class] ?? true)
            && isset($this->nativeListeners[$page::class])
            && $this->nativeListeners[$page::class] === $this->normalise($this->listeners($dispatcher, $page::class));
    }

    public function fireGuard(Page $page, string $event): mixed
    {
        throw_unless($this->supports($page), PageRestoreCancelledException::class);
        $dispatcher = $page::getEventDispatcher();
        if ($dispatcher === null || $dispatcher instanceof NullDispatcher) {
            return true;
        }

        $name = 'eloquent.' . $event . ': ' . $page::class;
        $raw = $this->listeners($dispatcher, $page::class)[$name] ?? [];
        /** @var list<Closure(string, array<int, Page>): mixed> $listeners */
        $listeners = $dispatcher->getListeners($name);
        $snapshot = clone $page;
        foreach (array_slice($listeners, count($raw)) as $notification) {
            $this->afterCommit($page->getConnection(), static fn (): mixed => $notification($name, [$snapshot]));
        }

        foreach (array_slice($listeners, 0, count($raw)) as $guard) {
            $result = $guard($name, [$page]);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /** @param Closure(): void $restorePages */
    public function preservingSiteHistory(Closure $restorePages): void
    {
        $this->siteHistoryDepth++;
        try {
            $restorePages();
        } finally {
            $this->siteHistoryDepth--;
        }
    }

    public function preservesSiteHistory(): bool
    {
        return $this->siteHistoryDepth > 0;
    }

    /** @param Closure(): mixed $notification */
    public function afterCommit(Connection $connection, Closure $notification): void
    {
        $connection->afterCommit(static function () use ($notification): void {
            try {
                $notification();
            } catch (Throwable $throwable) {
                // Delivery cannot veto committed state or turn a successful restore into a refusal.
                report($throwable);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $listeners
     * @return array<string, mixed>
     */
    private function normalise(array $listeners): array
    {
        foreach ($listeners as $event => $callbacks) {
            $unique = [];
            foreach ($callbacks as $callback) {
                if (! in_array($callback, $unique, true)) {
                    $unique[] = $callback;
                }
            }

            $listeners[$event] = $unique;
        }

        return $listeners;
    }

    /**
     * @param  class-string<Page>  $class
     * @return array<string, mixed>
     */
    private function listeners(Dispatcher $dispatcher, string $class): array
    {
        $raw = $dispatcher->getRawListeners();
        if (! is_array($raw)) {
            return [];
        }

        $listeners = [];
        foreach (['restoring', 'saving', 'updating'] as $event) {
            $name = 'eloquent.' . $event . ': ' . $class;
            if (($raw[$name] ?? []) !== []) {
                $listeners[$name] = $raw[$name];
            }
        }

        return $listeners;
    }
}
