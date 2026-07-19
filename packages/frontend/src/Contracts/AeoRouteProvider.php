<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

interface AeoRouteProvider
{
    public const string TAG = 'capell-frontend.aeo-route-provider';

    public function path(): string;

    public function handle(Request $request): Response;
}
