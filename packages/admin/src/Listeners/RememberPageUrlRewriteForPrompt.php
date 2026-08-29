<?php

declare(strict_types=1);

namespace Capell\Admin\Listeners;

use Capell\Admin\Support\Pages\PageUrlRewritePromptState;
use Capell\Core\Events\PageUrlsRewritten;

final readonly class RememberPageUrlRewriteForPrompt
{
    public function __construct(
        private PageUrlRewritePromptState $state,
    ) {}

    public function handle(PageUrlsRewritten $event): void
    {
        $this->state->remember($event);
    }
}
