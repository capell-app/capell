<?php

declare(strict_types=1);

namespace Capell\Core\Support\Url;

use Closure;

final class PageUrlRewriteContext
{
    private bool $automaticRedirectsAllowed = true;

    public function automaticRedirectsAllowed(): bool
    {
        return $this->automaticRedirectsAllowed;
    }

    public function withoutAutomaticRedirects(Closure $callback): mixed
    {
        $previous = $this->automaticRedirectsAllowed;
        $this->automaticRedirectsAllowed = false;

        try {
            return $callback();
        } finally {
            $this->automaticRedirectsAllowed = $previous;
        }
    }
}
