<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Contracts\RuntimeRefreshWarmer;
use Capell\Core\Models\SiteDomain;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class WarmCriticalFrontendPagesAction implements RuntimeRefreshWarmer
{
    public function __construct(private readonly Kernel $kernel) {}

    public function label(): string
    {
        return 'Capell site homepages';
    }

    public function warm(): void
    {
        SiteDomain::query()
            ->enabled()
            ->default()
            ->whereHas('site', fn (Builder $query): Builder => $query->enabled())
            ->orderBy('id')
            ->each(function (SiteDomain $siteDomain): void {
                $request = Request::create($siteDomain->full_url, Request::METHOD_GET);
                $response = $this->kernel->handle($request);
                $this->kernel->terminate($request, $response);

                if ($response->getStatusCode() >= Response::HTTP_BAD_REQUEST) {
                    throw new RuntimeException(sprintf(
                        'Homepage [%s] returned HTTP %d.',
                        $siteDomain->full_url,
                        $response->getStatusCode(),
                    ));
                }
            });
    }
}
