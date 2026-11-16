<?php

declare(strict_types=1);

namespace Capell\Core\Http\Middleware;

use Capell\Core\Support\Extensions\Deprecations;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Marks a route as deprecated for HTTP clients and records each use for the
 * extension audit and the doctor.
 *
 * Usage: `->middleware('capell.deprecated:<surface>,<since Y-m-d>,<sunset Y-m-d>,<replacement>')`.
 * The response carries `Deprecation` (RFC 9745) from the since date, `Sunset`
 * (RFC 8594) from the sunset date and, when the replacement is a path or URL,
 * a `Link` with `rel="successor-version"`.
 */
final readonly class DeprecatedHttpSurface
{
    public const string ALIAS = 'capell.deprecated';

    public function __construct(private Deprecations $deprecations) {}

    public function handle(Request $request, Closure $next, string $surface, string $since, string $sunset, string $replacement): Response
    {
        $sinceDate = $this->date($since, 'since');
        $sunsetDate = $this->date($sunset, 'sunset');
        throw_if($sunsetDate->lessThan($sinceDate), InvalidArgumentException::class, sprintf('The sunset date of %s is before its deprecation date.', $surface));

        $this->deprecations->record($surface, $replacement, $sunsetDate->toDateString());

        $response = $next($request);
        $response->headers->set('Deprecation', '@' . $sinceDate->getTimestamp());
        $response->headers->set('Sunset', $sunsetDate->toRfc7231String());

        if (str_starts_with($replacement, '/') || str_starts_with($replacement, 'https://') || str_starts_with($replacement, 'http://')) {
            $response->headers->set('Link', sprintf('<%s>; rel="successor-version"', $replacement), false);
        }

        return $response;
    }

    private function date(string $value, string $field): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (Throwable) {
            $date = null;
        }

        throw_if(! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $value, InvalidArgumentException::class, sprintf('The %s date must be written as YYYY-MM-DD.', $field));

        return $date;
    }
}
