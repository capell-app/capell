<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Fixtures\Autoload;

use Capell\Core\Enums\FrontendRuntime;
use Capell\Frontend\Contracts\FrontendResponseRenderer;
use Capell\Frontend\Data\FrontendRenderContextData;
use Symfony\Component\HttpFoundation\Response;

class RegistryTestRenderer implements FrontendResponseRenderer
{
    public function runtime(): FrontendRuntime
    {
        return FrontendRuntime::Inertia;
    }

    public function render(FrontendRenderContextData $context): Response
    {
        return response('inertia', $context->status ?? 200);
    }
}
