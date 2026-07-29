<?php

declare(strict_types=1);

use Capell\Installer\Data\InstallerRunStepData;
use Capell\Installer\Enums\InstallerRunStepResultCode;
use Capell\Installer\Http\Responses\InstallStepResponse;
use Illuminate\Support\Facades\Lang;

it('translates out-of-sequence responses at the HTTP boundary', function (): void {
    $originalLocale = app()->getLocale();
    Lang::addLines([
        'installer.step_out_of_sequence' => 'Cam ":current" allan o drefn; disgwyl ":expected".',
    ], 'installer-review', 'capell-installer');
    app()->setLocale('installer-review');

    try {
        $response = resolve(InstallStepResponse::class)->fromResult(new InstallerRunStepData(
            installId: '11111111-1111-4111-a111-111111111111',
            currentStep: 'requested-step',
            code: InstallerRunStepResultCode::OutOfSequence,
            lines: [],
            nextStep: 'expected-step',
            logPath: '/tmp/capell-install.log',
            expectedStep: 'expected-step',
        ));

        expect($response->getStatusCode())->toBe(409)
            ->and($response->getData(true))->toMatchArray([
                'installId' => '11111111-1111-4111-a111-111111111111',
                'currentStep' => 'requested-step',
                'nextStep' => 'expected-step',
                'expectedStep' => 'expected-step',
                'status' => 'failed',
                'error' => 'Cam "requested-step" allan o drefn; disgwyl "expected-step".',
            ]);
    } finally {
        app()->setLocale($originalLocale);
    }
});

it('translates both expired-run responses at the HTTP boundary', function (
    InstallerRunStepResultCode $code,
    string $translationKey,
    string $translatedMessage,
): void {
    $originalLocale = app()->getLocale();
    Lang::addLines([
        $translationKey => $translatedMessage,
    ], 'installer-review', 'capell-installer');
    app()->setLocale('installer-review');

    try {
        $response = resolve(InstallStepResponse::class)->fromResult(new InstallerRunStepData(
            installId: '22222222-2222-4222-a222-222222222222',
            currentStep: 'requested-step',
            code: $code,
        ));

        expect($response->getStatusCode())->toBe(410)
            ->and($response->getData(true))->toMatchArray([
                'installId' => '22222222-2222-4222-a222-222222222222',
                'status' => 'failed',
                'error' => $translatedMessage,
            ])
            ->and($response->getData(true))->not->toHaveKeys([
                'currentStep',
                'nextStep',
                'lines',
                'logPath',
            ]);
    } finally {
        app()->setLocale($originalLocale);
    }
})->with([
    'missing session' => [
        InstallerRunStepResultCode::SessionNotFound,
        'installer.step_session_not_found',
        'Mae sesiwn y gosodwr wedi dod i ben.',
    ],
    'missing plan' => [
        InstallerRunStepResultCode::PlanNotFound,
        'installer.step_plan_not_found',
        'Mae cynllun y gosodwr wedi dod i ben.',
    ],
]);
