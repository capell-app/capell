<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Frontend\Data\FrontendBootstrapResult;
use Illuminate\Http\Request;

interface FrontendKernelInterface
{
    public function bootstrap(Request $request): FrontendBootstrapResult;
}
