<?php

declare(strict_types=1);

namespace Capell\Core\Events;

final class UrlVisitFailed
{
    public function __construct(
        public string $url,
        public int $statusCode,
        public ?int $pageId = null,
    ) {}
}
