<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('reports internal Capell class use without reporting stable or unrelated classes', function (): void {
    $root = dirname(__DIR__, 5);
    $fixture = $root . '/packages/core/tests/Unit/PHPStan/Fixtures/InternalClassConsumer.php';
    $config = $root . '/packages/core/tests/Unit/PHPStan/Fixtures/extension-rule.neon';
    $process = new Process([PHP_BINARY, $root . '/vendor/bin/phpstan', 'analyse', '--no-progress', '--error-format=json', '--configuration=' . $config, $fixture], $root);
    $process->run();

    expect($process->getExitCode())->toBe(1);
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $messages = $result['files'][$fixture]['messages'] ?? $result['error_details'][$fixture] ?? [];
    $ruleMessages = array_values(array_filter($messages, static fn (array $message): bool => ($message['identifier'] ?? null) === 'capell.internalClass'));

    expect($ruleMessages)->not->toBeEmpty()
        ->and(implode(' ', array_column($ruleMessages, 'message')))->toContain('DoctorCheck', 'RenderHookRegistry')
        ->not->toContain('ExtensionContribution', 'DateTimeImmutable');
});

it('reports internal Capell method, property and constant use without reporting stable members', function (): void {
    $root = dirname(__DIR__, 5);
    $fixture = $root . '/packages/core/tests/Unit/PHPStan/Fixtures/InternalMemberConsumer.php';
    $config = $root . '/packages/core/tests/Unit/PHPStan/Fixtures/extension-rule.neon';
    $process = new Process([PHP_BINARY, $root . '/vendor/bin/phpstan', 'analyse', '--no-progress', '--error-format=json', '--configuration=' . $config, $fixture], $root);
    $process->run();

    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $messages = $result['files'][$fixture]['messages'] ?? $result['error_details'][$fixture] ?? [];
    $reported = array_column(array_filter($messages, static fn (array $message): bool => ($message['identifier'] ?? null) === 'capell.internalMember'), 'message');
    $text = implode("\n", $reported);

    expect($reported)->toHaveCount(5)
        ->and($text)->toContain('::hiddenMethod', '::hiddenProperty', '::hiddenStaticMethod', '::hiddenStatic', '::HIDDEN_CONSTANT')
        ->not->toContain('openMethod', 'openProperty', 'OPEN_CONSTANT');
});
