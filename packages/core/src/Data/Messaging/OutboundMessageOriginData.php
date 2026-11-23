<?php

declare(strict_types=1);

namespace Capell\Core\Data\Messaging;

use Spatie\LaravelData\Data;

/**
 * What was running when a message was sent: a queued job, a console command or a named route.
 * The name is a class, command or route name only, never a URL, argument or payload.
 */
final class OutboundMessageOriginData extends Data
{
    public const string JOB = 'job';

    public const string COMMAND = 'command';

    public const string ROUTE = 'route';

    public const string UNKNOWN = 'unknown';

    public function __construct(
        public string $type,
        public ?string $name = null,
    ) {}

    public static function unknown(): self
    {
        return new self(self::UNKNOWN);
    }
}
