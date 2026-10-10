<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Illuminate\Log\Events\MessageLogged;

final class SchemaDiagnosticRecorder
{
    /** @var list<MessageLogged> */
    private array $events = [];

    public function record(MessageLogged $event): void
    {
        if ($event->level === 'warning') {
            $this->events[] = $event;
        }
    }

    /** @return list<MessageLogged> */
    public function events(): array
    {
        return $this->events;
    }
}
