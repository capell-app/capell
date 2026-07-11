<?php

declare(strict_types=1);

namespace Capell\Core\Events;

final class PageUrlChanged
{
    public function __construct(
        public readonly int $page_url_id,
        public readonly ?int $page_id,
        public readonly int $site_id,
        public readonly int $language_id,
        public readonly string $old_url,
        public readonly string $new_url,
    ) {}
}
