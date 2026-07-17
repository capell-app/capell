<?php

declare(strict_types=1);

namespace Capell\Installer\Http\Responses;

use Capell\Installer\Support\InstallerSessionRepository;
use Illuminate\Http\JsonResponse;

final class InstallStepResponse
{
    public function __construct(private readonly InstallerSessionRepository $sessions) {}

    /** @param array<string, mixed> $additional */
    public function running(
        string $installId,
        string $currentStep,
        ?string $nextStep,
        string $logPath,
        array $additional = [],
    ): JsonResponse {
        return response()->json([
            'installId' => $installId,
            'currentStep' => $currentStep,
            'nextStep' => $nextStep,
            'status' => 'running',
            'lines' => $this->sessions->lines($installId),
            'logPath' => $logPath,
            ...$additional,
            'csrfToken' => csrf_token(),
        ]);
    }

    public function complete(string $installId, string $currentStep, string $logPath): JsonResponse
    {
        return response()->json([
            'installId' => $installId,
            'currentStep' => $currentStep,
            'nextStep' => null,
            'status' => 'complete',
            'lines' => $this->sessions->lines($installId),
            'logPath' => $logPath,
            'redirectUrl' => route('capell-installer.success', ['installId' => $installId]),
            'csrfToken' => csrf_token(),
        ]);
    }

    /** @param array<string, mixed> $additional */
    public function failed(
        string $installId,
        string $currentStep,
        string $logPath,
        string $error,
        array $additional = [],
        int $statusCode = 200,
    ): JsonResponse {
        return response()->json([
            'installId' => $installId,
            'currentStep' => $currentStep,
            'nextStep' => null,
            'status' => 'failed',
            'lines' => $this->sessions->lines($installId),
            'logPath' => $logPath,
            'error' => $error,
            ...$additional,
            'csrfToken' => csrf_token(),
        ], $statusCode);
    }

    public function gone(string $installId, string $error): JsonResponse
    {
        return response()->json([
            'installId' => $installId,
            'status' => 'failed',
            'error' => $error,
            'csrfToken' => csrf_token(),
        ], 410);
    }

    public function outOfSequence(
        string $installId,
        string $currentStep,
        string $expectedStep,
        string $logPath,
    ): JsonResponse {
        if (in_array($currentStep, $this->sessions->completedSteps($installId), true)) {
            return $this->running($installId, $currentStep, $expectedStep, $logPath);
        }

        return response()->json([
            'installId' => $installId,
            'currentStep' => $currentStep,
            'nextStep' => $expectedStep,
            'expectedStep' => $expectedStep,
            'status' => 'failed',
            'lines' => $this->sessions->lines($installId),
            'logPath' => $logPath,
            'error' => sprintf(
                'Install step "%s" is out of sequence. Expected "%s". Refresh the installer progress page and continue from the current step.',
                $currentStep,
                $expectedStep,
            ),
            'csrfToken' => csrf_token(),
        ], 409);
    }
}
