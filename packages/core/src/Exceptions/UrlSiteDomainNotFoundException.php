<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use Exception;

class UrlSiteDomainNotFoundException extends Exception
{
    public function __construct(string $url, ?string $suggestion = null)
    {
        $message = __('Unable to locate a site for the requested URL: :url.', ['url' => $url]);

        if ($suggestion !== null && $url !== $suggestion) {
            $message .= ' ' . __(
                'Did you mean: :suggestion?',
                ['suggestion' => $suggestion],
            );
        }

        parent::__construct($message);
    }
}
