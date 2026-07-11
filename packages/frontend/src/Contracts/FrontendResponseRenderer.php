<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Core\Enums\FrontendRuntime;
use Capell\Frontend\Data\FrontendRenderContextData;
use Illuminate\Contracts\Support\Responsable;
use Symfony\Component\HttpFoundation\Response;

interface FrontendResponseRenderer
{
    public function runtime(): FrontendRuntime;

    public function render(FrontendRenderContextData $context): Response|Responsable;
}
