<?php

declare(strict_types=1);

use Capell\Frontend\Support\Logging\FrontendLogger;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

it('preserves supplied context and appends the caller without synthetic request state', function (): void {
    config()->set('logging.channels.capell', null);

    $logger = Mockery::mock(LoggerInterface::class);

    Log::shouldReceive('getLogger')
        ->once()
        ->andReturn($logger);

    $logger->shouldReceive('warning')
        ->once()
        ->with('Frontend warning', Mockery::on(fn (array $context): bool => $context['site_id'] === 42
                && ! array_key_exists('request_id', $context)
                && is_array($context['caller'])
                && is_string($context['caller']['file'])
                && is_int($context['caller']['line'])));

    (new FrontendLogger)->warning('Frontend warning', ['site_id' => 42]);
});
