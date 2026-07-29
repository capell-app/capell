<?php

declare(strict_types=1);

namespace Capell\Installer\Http\Responses;

use Capell\Installer\Data\InstallerRunStepData;
use Capell\Installer\Enums\InstallerRunStepResultCode;
use Illuminate\Http\JsonResponse;

final class InstallStepResponse
{
    public function fromResult(InstallerRunStepData $result): JsonResponse
    {
        if (in_array($result->code, [
            InstallerRunStepResultCode::SessionNotFound,
            InstallerRunStepResultCode::PlanNotFound,
        ], true)) {
            return response()->json([
                'installId' => $result->installId,
                'status' => 'failed',
                'error' => $this->errorMessage($result),
                'csrfToken' => csrf_token(),
            ], 410);
        }

        $payload = [];

        if ($result->exceptionClass !== null) {
            $payload['errorClass'] = $result->exceptionClass;
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
            'status' => $this->status($result->code),
            'lines' => $result->lines,
            'logPath' => $result->logPath,
        ];

        $error = $this->errorMessage($result);
        if ($error !== null) {
            $payload['error'] = $error;
        }

        if ($result->code === InstallerRunStepResultCode::Complete) {
            $payload['redirectUrl'] = route('capell-installer.success', ['installId' => $result->installId]);
        }

        $payload['csrfToken'] = csrf_token();

        return response()->json(
            $payload,
            $result->code === InstallerRunStepResultCode::OutOfSequence ? 409 : 200,
        );
    }

    private function status(InstallerRunStepResultCode $code): string
    {
        return match ($code) {
            InstallerRunStepResultCode::Complete => 'complete',
            InstallerRunStepResultCode::Running => 'running',
            default => 'failed',
        };
    }

    private function errorMessage(InstallerRunStepData $result): ?string
    {
        return match ($result->code) {
            InstallerRunStepResultCode::ExecutionFailed => $result->exceptionMessage,
            InstallerRunStepResultCode::OutOfSequence => (string) __('capell-installer::installer.step_out_of_sequence', [
                'current' => $result->currentStep,
                'expected' => $result->expectedStep,
            ]),
            InstallerRunStepResultCode::PlanNotFound => (string) __('capell-installer::installer.step_plan_not_found'),
            InstallerRunStepResultCode::PreflightFailed => (string) __('capell-installer::installer.step_preflight_failed'),
            InstallerRunStepResultCode::SessionNotFound => (string) __('capell-installer::installer.step_session_not_found'),
            default => null,
        };
    }
}
