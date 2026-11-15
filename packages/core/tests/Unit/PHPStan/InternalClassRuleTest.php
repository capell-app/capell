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
        ->and(implode(' ', array_column($ruleMessages, 'message')))->toContain('DoctorCheck', 'FrontendComponentContributor')
        ->not->toContain('ExtensionContribution')->not->toContain('DateTimeImmutable');
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
        ->not->toContain('openMethod')->not->toContain('openProperty')->not->toContain('OPEN_CONSTANT');
});

it('reports nothing for the provider the end-to-end tutorial tells authors to write', function (): void {
    $root = dirname(__DIR__, 5);
    $directory = $root . '/packages/core/tests/Unit/PHPStan/Fixtures/Tutorial';
    $config = $root . '/packages/core/tests/Unit/PHPStan/Fixtures/extension-rule.neon';
    $process = new Process([PHP_BINARY, $root . '/vendor/bin/phpstan', 'analyse', '--no-progress', '--error-format=json', '--configuration=' . $config, $directory], $root);
    $process->run();

    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($result['result'] ?? null)->toBe('passed')
        ->and($result['errors'] ?? null)->toBe(0)
        ->and($process->getExitCode())->toBe(0);
});

it('keeps the tutorial provider snippet identical to the analysed fixture', function (): void {
    $root = dirname(__DIR__, 5);
    $doc = file_get_contents($root . '/docs/packages/build-extension-end-to-end.md');
    $fixture = file_get_contents($root . '/packages/core/tests/Unit/PHPStan/Fixtures/Tutorial/AnnouncementBarServiceProvider.php');

    $section = substr($doc, (int) strpos($doc, '## Provider Registration'));
    preg_match('/```php\n(.*?)\n```/s', $section, $snippet);

    expect($snippet[1] ?? null)->toBe(rtrim($fixture, "\n"));
});
