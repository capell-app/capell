<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Events\PageSaved;
use Illuminate\Contracts\Queue\ShouldQueue;

final class RestoreExternalIndexListener implements ShouldQueue
{
    /** @var list<int> */
    public static array $writes = [];

    public function handle(PageSaved $event): void
    {
        self::$writes[] = $event->page->id;
    }
}
