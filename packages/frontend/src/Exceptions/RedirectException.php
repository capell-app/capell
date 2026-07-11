<?php

declare(strict_types=1);

namespace Capell\Frontend\Exceptions;

use Exception;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Illuminate\Support\ViewErrorBag;
use Livewire\Features\SupportRedirects\Redirector as LivewireRedirector;

class RedirectException extends Exception implements Responsable
{
    public string $error = '';

    public ?ViewErrorBag $errors = null;

    public function __construct(
        public string|RedirectResponse $redirectUrl,
        public int $statusCode = 302,
    ) {
        if ($this->redirectUrl instanceof RedirectResponse) {
            if ($this->redirectUrl->getSession()?->has('errors')) {
                $this->errors = $this->redirectUrl->getSession()->get('errors');
            }

            if ($this->redirectUrl->getSession()?->has('error')) {
                $this->error = $this->redirectUrl->getSession()->get('error');
            }

            $this->redirectUrl = $this->redirectUrl->getTargetUrl();
        }

        parent::__construct('Redirecting to: ' . $this->redirectUrl);
    }

    public function __toString(): string
    {
        return $this->getMessage();
    }

    public function toResponse($request): Redirector|RedirectResponse|LivewireRedirector
    {
        $response = redirect($this->redirectUrl, $this->statusCode);

        if ($this->errors instanceof ViewErrorBag) {
            // Extract all error bags and re-flash them
            foreach ($this->errors->getBags() as $key => $bag) {
                $response->withErrors($bag->getMessages(), $key);
            }
        }

        if ($this->error !== '' && $this->error !== '0') {
            $response->with('error', $this->error);
        }

        return $response;
    }
}
