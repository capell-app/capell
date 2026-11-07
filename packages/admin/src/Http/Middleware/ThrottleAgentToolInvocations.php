<?php

declare(strict_types=1);

namespace Capell\Admin\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limits how often one signed-in user can invoke admin agent tools. Each
 * invocation may write content, so an agent loop or a stolen session cannot
 * hammer the tools faster than the configured rate.
 */
final readonly class ThrottleAgentToolInvocations
{
    private const int DECAY_SECONDS = 60;

    public function __construct(private RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $maxAttempts = max(1, (int) config('capell-admin.agent.tool_invocations_per_minute', 30));
        $key = 'capell-admin:agent-tools-invoke:' . hash('sha256', (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()));

        if ($this->limiter->hit($key, self::DECAY_SECONDS) > $maxAttempts) {
            $retryAfter = $this->limiter->availableIn($key);

            return response()->json(
                ['message' => __('capell-admin::agent.tool_invocations_throttled', ['seconds' => $retryAfter])],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => (string) $retryAfter],
            );
        }

        return $next($request);
    }
}
