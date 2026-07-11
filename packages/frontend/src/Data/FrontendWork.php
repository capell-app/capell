<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Frontend\Support\State\FrontendState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FrontendWork
{
    public function __construct(
        public readonly Request $request,
        public readonly FrontendState $state,
        private ?RedirectResponse $redirect = null,
        /** @var array{status:int,message?:string}|null */
        private ?array $error = null,
        private ?FrontendContext $context = null,
    ) {}

    public function setRedirect(RedirectResponse $response): self
    {
        $this->redirect = $response;

        return $this;
    }

    public function getRedirect(): ?RedirectResponse
    {
        return $this->redirect;
    }

    /** @param array{status:int,message?:string} $error */
    public function setError(array $error): self
    {
        $this->error = $error;

        return $this;
    }

    /** @return array{status:int,message?:string}|null */
    public function getError(): ?array
    {
        return $this->error;
    }

    public function setContext(FrontendContext $context): self
    {
        $this->context = $context;

        return $this;
    }

    public function context(): ?FrontendContext
    {
        return $this->context;
    }
}
