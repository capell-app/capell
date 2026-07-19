<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Frontend\Data\FrontendRenderContextData;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

interface PageVariantNegotiator
{
    public const string TAG = 'capell-frontend.page-variant-negotiator';

    public function variant(Request $request, FrontendRenderContextData $context): ?Response;
}
