<?php

declare(strict_types=1);

namespace Capell\Core\Http\Controllers\Reporting;

use Capell\Core\Actions\Reporting\GetReportingHealthAction;
use Illuminate\Http\JsonResponse;

final readonly class ReportingHealthController
{
    public function __invoke(): JsonResponse
    {
        $snapshot = GetReportingHealthAction::run();
        $status = match ($snapshot->status) {
            'ok' => 200,
            'disabled' => 404,
            default => 503,
        };

        return new JsonResponse($snapshot->toArray(), $status, ['Cache-Control' => 'no-store, private']);
    }
}
