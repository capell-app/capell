<?php

declare(strict_types=1);

namespace Acme\AnnouncementBar\Data;

final readonly class AnnouncementBarData
{
    public function __construct(
        public bool $enabled,
        public string $message,
    ) {}
}
