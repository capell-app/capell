<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use RuntimeException;

/** Publishing a site spec's pages stopped; no page was published. Names the first page that blocked it. */
final class SiteSpecPublishRefusedException extends RuntimeException
{
    /** @param  list<string>  $pageNames  every page that blocked publishing, first one first */
    public function __construct(string $message, public readonly array $pageNames)
    {
        parent::__construct($message);
    }

    public function firstPageName(): ?string
    {
        return $this->pageNames[0] ?? null;
    }
}
