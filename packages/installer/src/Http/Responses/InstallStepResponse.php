<?php

declare(strict_types=1);

namespace Capell\Installer\Http\Responses;

use Capell\Installer\Data\InstallerRunStepData;
use Illuminate\Http\JsonResponse;

final class InstallStepResponse
{
    public function fromResult(InstallerRunStepData $result): JsonResponse
    {
        if ($result->statusCode === 410) {
            return response()->json([
                'installId' => $result->installId,
                'status' => 'failed',
                'error' => $result->error,
                'csrfToken' => csrf_token(),
            ], 410);
        }

        $payload = [];

        if ($result->errorClass !== null) {
            $payload['errorClass'] = $result->errorClass;
        }

        if ($result->remediation !== null) {
            $payload['remediation'] = $result->remediation;
        }

        if ($result->preflight !== null) {
            $payload['preflight'] = $result->preflight;
        }

        $payload['installId'] = $result->installId;
        $payload['currentStep'] = $result->currentStep;
        $payload['nextStep'] = $result->nextStep;

        if ($result->expectedStep !== null) {
            $payload['expectedStep'] = $result->expectedStep;
        }

        $payload = [
            ...$payload,
            'status' => $result->status,
            'lines' => $result->lines,
            'logPath' => $result->logPath,
        ];

        if ($result->error !== null) {
            $payload['error'] = $result->error;
        }

        if ($result->status === 'complete') {
            $payload['redirectUrl'] = route('capell-installer.success', ['installId' => $result->installId]);
        }

        $payload['csrfToken'] = csrf_token();

        return response()->json($payload, $result->statusCode);
    }
}
