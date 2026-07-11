<?php

declare(strict_types=1);

namespace Capell\Admin\Http\Middleware;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class ProfileAdminRequest
{
    private const string HEADER = 'X-Capell-Admin-Profile';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldProfile($request)) {
            return $next($request);
        }

        $startedAt = hrtime(true);
        $queryCount = 0;
        $queryTimeMs = 0.0;

        DB::listen(function (QueryExecuted $query) use (&$queryCount, &$queryTimeMs): void {
            $queryCount++;
            $queryTimeMs += $query->time;
        });

        $response = $next($request);
        $responseBytes = $this->responseBytes($response);
        $roundedQueryTime = round($queryTimeMs, 2);
        $durationMs = round((hrtime(true) - $startedAt) / 1_000_000, 2);
        $peakMemoryMb = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

        $response->headers->set('X-Capell-Admin-Queries', (string) $queryCount);
        $response->headers->set('X-Capell-Admin-Sql-Ms', (string) $roundedQueryTime);
        $response->headers->set('X-Capell-Admin-Duration-Ms', (string) $durationMs);
        $response->headers->set('X-Capell-Admin-Memory-Mb', (string) $peakMemoryMb);
        $response->headers->set('X-Capell-Admin-Response-Bytes', (string) $responseBytes);
        $response->headers->set(
            'Server-Timing',
            sprintf(
                'capell-app;dur=%s;desc="Capell admin request", capell-sql;dur=%s;desc="Capell admin SQL"',
                $durationMs,
                $roundedQueryTime,
            ),
            replace: false,
        );

        return $response;
    }

    private function shouldProfile(Request $request): bool
    {
        if ($request->headers->get(self::HEADER) !== '1') {
            return false;
        }

        if (! $request->user()) {
            return false;
        }

        $ip = $request->ip();

        if (in_array($ip, ['127.0.0.1', '::1'], true)) {
            return true;
        }

        if (app()->environment(['local', 'testing'])) {
            return true;
        }

        return is_string($ip)
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private function responseBytes(Response $response): int
    {
        $content = $response->getContent();

        if ($content === false) {
            return 0;
        }

        return strlen($content);
    }
}
