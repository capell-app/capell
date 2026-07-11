<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Kernel;

use Capell\Frontend\Contracts\FrontendKernelInterface;
use Capell\Frontend\Data\ErrorData;
use Capell\Frontend\Data\FrontendBootstrapResult;
use Capell\Frontend\Data\FrontendWork;
use Capell\Frontend\Support\State\FrontendState;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;

final class FrontendKernel implements FrontendKernelInterface
{
    public function __construct(
        private readonly Pipeline $pipeline,
        /** @var array<int, callable|string> */
        private readonly array $steps,
        private readonly FrontendState $state,
    ) {}

    public function bootstrap(Request $request): FrontendBootstrapResult
    {
        /** @var FrontendWork $work */
        $work = $this->pipeline
            ->send(new FrontendWork($request, $this->state))
            ->through($this->steps)
            ->thenReturn();

        return new FrontendBootstrapResult(
            redirect: $work->getRedirect(),
            error: is_array($work->getError()) && $work->getError() !== null ? new ErrorData(status: $work->getError()['status'], message: $work->getError()['message'] ?? null) : null,
            context: $work->context(),
        );
    }
}
