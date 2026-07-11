<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Illuminate\Http\RedirectResponse;
use Spatie\LaravelData\Data;

class FrontendBootstrapResult extends Data
{
    public function __construct(
        public ?RedirectResponse $redirect = null,
        public ?ErrorData $error = null,
        public ?FrontendContext $context = null,
    ) {}

    public function isOk(): bool
    {
        return $this->context instanceof FrontendContext;
    }

    public function isRedirect(): bool
    {
        return $this->redirect instanceof RedirectResponse;
    }

    public function isError(): bool
    {
        return $this->error instanceof ErrorData;
    }
}
