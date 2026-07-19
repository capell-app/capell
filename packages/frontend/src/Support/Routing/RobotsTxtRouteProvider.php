<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Routing;

use Capell\Frontend\Contracts\AeoRouteProvider;
use Capell\Frontend\Contracts\RobotsDirectiveContributor;
use Capell\Frontend\Data\RobotsDirectiveData;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RobotsTxtRouteProvider implements AeoRouteProvider
{
    public function __construct(private Container $container) {}

    public function path(): string
    {
        return 'robots.txt';
    }

    public function handle(Request $request): Response
    {
        $directives = [new RobotsDirectiveData('*', ['/'], [])];

        foreach ($this->container->tagged(RobotsDirectiveContributor::TAG) as $contributor) {
            if (! $contributor instanceof RobotsDirectiveContributor) {
                continue;
            }

            foreach ($contributor->directives() as $directive) {
                if ($directive instanceof RobotsDirectiveData && $directive->userAgent !== '') {
                    $directives[] = $directive;
                }
            }
        }

        return response($this->render($directives, $request), Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    /**
     * @param  list<RobotsDirectiveData>  $directives
     */
    private function render(array $directives, Request $request): string
    {
        $groups = [];

        foreach ($directives as $directive) {
            $groups[$directive->userAgent] ??= ['allow' => [], 'disallow' => []];
            $groups[$directive->userAgent]['allow'] = array_values(array_unique([
                ...$groups[$directive->userAgent]['allow'],
                ...$directive->allow,
            ]));
            $groups[$directive->userAgent]['disallow'] = array_values(array_unique([
                ...$groups[$directive->userAgent]['disallow'],
                ...$directive->disallow,
            ]));
        }

        uksort($groups, static fn (string $left, string $right): int => match (true) {
            $left === '*' => -1,
            $right === '*' => 1,
            default => $left <=> $right,
        });
        $sections = [];

        foreach ($groups as $userAgent => $rules) {
            $lines = ['User-agent: ' . $userAgent];
            sort($rules['allow']);
            sort($rules['disallow']);

            foreach ($rules['allow'] as $path) {
                $lines[] = 'Allow: ' . $path;
            }

            foreach ($rules['disallow'] as $path) {
                $lines[] = 'Disallow: ' . $path;
            }

            $sections[] = implode("\n", $lines);
        }

        $sections[] = 'Sitemap: ' . $request->getSchemeAndHttpHost() . '/sitemap.xml';

        return implode("\n\n", $sections) . "\n";
    }
}
