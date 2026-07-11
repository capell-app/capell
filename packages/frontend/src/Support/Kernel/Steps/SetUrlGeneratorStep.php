<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Kernel\Steps;

use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Data\FrontendWork;
use Closure;
use Illuminate\Routing\UrlGenerator;
use ReflectionProperty;

final class SetUrlGeneratorStep
{
    /** @var list<string> */
    private const array URL_GENERATOR_STATE_PROPERTIES = [
        'forcedRoot',
        'forceScheme',
        'cachedRoot',
        'cachedScheme',
    ];

    public function handle(FrontendWork $work, Closure $next): mixed
    {
        $state = $work->state;
        $domain = $state->domain();

        if (! $domain instanceof SiteDomain) {
            return $next($work);
        }

        /** @var UrlGenerator $url */
        $url = resolve(\Illuminate\Contracts\Routing\UrlGenerator::class);
        $urlState = $this->captureUrlGeneratorState($url);

        try {
            $root = $state->rootUrl();
            if (is_string($root) && $root !== '') {
                $url->useOrigin(rtrim($root, '/'));
            }

            if (is_string($domain->scheme) && $domain->scheme !== '') {
                $url->forceScheme($domain->scheme);
            }

            return $next($work);
        } finally {
            $this->restoreUrlGeneratorState($url, $urlState);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function captureUrlGeneratorState(UrlGenerator $url): array
    {
        $state = [];

        foreach (self::URL_GENERATOR_STATE_PROPERTIES as $propertyName) {
            $state[$propertyName] = $this->urlGeneratorProperty($propertyName)->getValue($url);
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function restoreUrlGeneratorState(UrlGenerator $url, array $state): void
    {
        foreach (self::URL_GENERATOR_STATE_PROPERTIES as $propertyName) {
            $this->urlGeneratorProperty($propertyName)->setValue($url, $state[$propertyName] ?? null);
        }
    }

    private function urlGeneratorProperty(string $propertyName): ReflectionProperty
    {
        return new ReflectionProperty(UrlGenerator::class, $propertyName);
    }
}
