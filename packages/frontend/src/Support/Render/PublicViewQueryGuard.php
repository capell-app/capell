<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Render;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Theme;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Enums\FrontendRenderAudience;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class PublicViewQueryGuard
{
    private const string DOCS_URL = 'https://docs.capell.app/core/frontend/debugging-public-output/#symptom-table';

    private int $activeDepth = 0;

    private readonly PublicViewQueryCapture $capture;

    public function __construct()
    {
        $this->capture = new PublicViewQueryCapture;
    }

    public function guard(FrontendRenderContextData $context, Closure $render): mixed
    {
        if (! $this->enabled() || ! $this->publicAudience()) {
            return $render();
        }

        $outermostGuard = $this->activeDepth === 0;

        if ($outermostGuard) {
            $this->capture->flush();
        }

        $this->activeDepth++;

        try {
            $result = $render();
        } finally {
            $this->activeDepth--;
        }

        if (! $outermostGuard) {
            return $result;
        }

        $queries = $this->capture->all();

        if ($queries === []) {
            return $result;
        }

        $payload = [
            'queries' => $queries,
            'page_id' => $this->modelKey($context->page),
            'layout_id' => $this->modelKey($context->layout),
            'theme_id' => $this->modelKey($context->theme),
        ];

        Log::warning('capell-frontend: public Blade rendering executed database queries', $payload);

        if ($this->mode() === 'exception') {
            throw new RuntimeException(sprintf(
                'Public Blade rendering executed %d database query(s). First query: %s. First query Blade view: %s. Move public render data loading out of Blade views. To disable this guard temporarily, set CAPELL_FRONTEND_PUBLIC_VIEW_QUERY_GUARD_ENABLED=false or capell-frontend.public_view_query_guard.enabled=false. Docs: %s.',
                count($queries),
                (string) ($queries[0]['sql_shape'] ?? 'unknown'),
                (string) ($queries[0]['first_blade_view'] ?? 'unknown'),
                (string) config('capell-frontend.public_view_query_guard.docs_url', self::DOCS_URL),
            ));
        }

        return $result;
    }

    public function isActive(): bool
    {
        return $this->activeDepth > 0;
    }

    public function capture(QueryExecuted $event): void
    {
        if (! $this->isActive() || $this->ignoredConnection($event->connectionName)) {
            return;
        }

        $bladeViews = $this->bladeViewsFromTrace();

        $this->capture->record([
            'connection' => $event->connectionName,
            'sql_shape' => $this->sqlShape($event->sql),
            'bindings_count' => count($event->bindings),
            'duration_ms' => (float) $event->time,
            'first_blade_view' => $bladeViews[0] ?? null,
            'blade_views' => $bladeViews,
        ]);
    }

    private function enabled(): bool
    {
        $configured = config('capell-frontend.public_view_query_guard.enabled');

        if (is_bool($configured)) {
            return $configured;
        }

        return true;
    }

    private function mode(): string
    {
        $mode = config('capell-frontend.public_view_query_guard.mode', 'exception');

        return $mode === 'log' ? 'log' : 'exception';
    }

    private function publicAudience(): bool
    {
        if (! app()->bound(FrontendContextReader::class)) {
            return true;
        }

        try {
            $audience = resolve(FrontendContextReader::class)->getFrontendData('renderAudience');
        } catch (Throwable) {
            return true;
        }

        if ($audience instanceof FrontendRenderAudience) {
            return $audience === FrontendRenderAudience::Public;
        }

        if (is_string($audience)) {
            return (FrontendRenderAudience::tryFrom($audience) ?? FrontendRenderAudience::Public) === FrontendRenderAudience::Public;
        }

        return true;
    }

    private function ignoredConnection(string $connectionName): bool
    {
        $connections = config('capell-frontend.public_view_query_guard.ignored_connections', []);

        return is_array($connections) && in_array($connectionName, $connections, true);
    }

    private function sqlShape(string $sql): string
    {
        $shape = preg_replace("/'[^']*'/", "'?'", $sql) ?? $sql;
        $shape = preg_replace('/\\b\\d+\\b/', '?', $shape) ?? $shape;
        $shape = preg_replace('/\\s+/', ' ', $shape) ?? $shape;

        return str($shape)->trim()->limit(240, '')->toString();
    }

    /**
     * @return list<string>
     */
    private function bladeViewsFromTrace(): array
    {
        $views = [];

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame['file'] ?? null;

            if (! is_string($file)) {
                continue;
            }

            $view = str_ends_with($file, '.blade.php')
                ? $file
                : $this->compiledBladeSource($file);
            if ($view === null) {
                continue;
            }

            if (in_array($view, $views, true)) {
                continue;
            }

            $views[] = $view;
        }

        return $views;
    }

    private function compiledBladeSource(string $file): ?string
    {
        if (! str_contains($file, DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR)) {
            return null;
        }

        if (! is_file($file) || ! is_readable($file)) {
            return null;
        }

        $contents = file_get_contents($file);

        if (! is_string($contents) || preg_match('/\\/\\*\\*PATH (?<path>.*?) ENDPATH\\*\\*\\//s', $contents, $matches) !== 1) {
            return null;
        }

        $path = trim($matches['path']);

        return $path !== '' ? $path : null;
    }

    private function modelKey(mixed $model): ?int
    {
        if (! $model instanceof Model && ! $model instanceof Pageable && ! $model instanceof Layout && ! $model instanceof Theme) {
            return null;
        }

        if (! method_exists($model, 'getKey')) {
            return null;
        }

        $key = $model->getKey();

        return is_numeric($key) ? (int) $key : null;
    }
}
