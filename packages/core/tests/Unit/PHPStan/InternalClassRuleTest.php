<?php

declare(strict_types=1);

use Capell\Core\Tests\Support\PhpStanFixtureAnalysis;

it('reports internal Capell class use without reporting stable or unrelated classes', function (): void {
    $root = dirname(__DIR__, 5);
    $fixture = $root . '/packages/core/tests/Unit/PHPStan/Fixtures/InternalClassConsumer.php';
    $config = $root . '/packages/core/tests/Unit/PHPStan/Fixtures/extension-rule.neon';
    $analysis = PhpStanFixtureAnalysis::run($root, $config, $fixture);
    $ruleMessages = $analysis->messagesWithIdentifier($fixture, 'capell.internalClass');

    expect($analysis->exitCode)->toBe(1, $analysis->diagnostic())
        ->and($analysis->generalErrors())->toBe([], $analysis->diagnostic())
        ->and($ruleMessages)->not->toBeEmpty()
        ->and(implode(' ', $ruleMessages))->toContain('DoctorCheck', 'FrontendComponentContributor')
        ->not->toContain('ExtensionContribution')->not->toContain('DateTimeImmutable');
});

it('reports internal Capell method, property and constant use without reporting stable members', function (): void {
    $root = dirname(__DIR__, 5);
    $fixture = $root . '/packages/core/tests/Unit/PHPStan/Fixtures/InternalMemberConsumer.php';
    $config = $root . '/packages/core/tests/Unit/PHPStan/Fixtures/extension-rule.neon';
    $analysis = PhpStanFixtureAnalysis::run($root, $config, $fixture);
    $reported = $analysis->messagesWithIdentifier($fixture, 'capell.internalMember');
    $text = implode("\n", $reported);

    expect($analysis->generalErrors())->toBe([], $analysis->diagnostic())
        ->and($reported)->toHaveCount(5, $analysis->diagnostic())
        ->and($text)->toContain('::hiddenMethod', '::hiddenProperty', '::hiddenStaticMethod', '::hiddenStatic', '::HIDDEN_CONSTANT')
        ->not->toContain('openMethod')->not->toContain('openProperty')->not->toContain('OPEN_CONSTANT');
});

it('reports nothing for the provider the end-to-end tutorial tells authors to write', function (): void {
    $root = dirname(__DIR__, 5);
    $directory = $root . '/packages/core/tests/Unit/PHPStan/Fixtures/Tutorial/src';
    $config = $root . '/packages/core/tests/Unit/PHPStan/Fixtures/extension-rule.neon';
    $analysis = PhpStanFixtureAnalysis::run($root, $config, $directory);

    expect($analysis->exitCode)->toBe(0, $analysis->diagnostic())
        ->and($analysis->generalErrors())->toBe([], $analysis->diagnostic())
        ->and($analysis->fileMessages())->toBe([]);
});

it('keeps the tutorial provider snippet identical to the analysed fixture', function (): void {
    $root = dirname(__DIR__, 5);
    $doc = file_get_contents($root . '/docs/packages/build-extension-end-to-end.md');
    $fixture = file_get_contents($root . '/packages/core/tests/Unit/PHPStan/Fixtures/Tutorial/src/Providers/AnnouncementBarServiceProvider.php');

    $section = substr($doc, (int) strpos($doc, '## Provider Registration'));
    preg_match('/```php\n(.*?)\n```/s', $section, $snippet);

    expect($snippet[1] ?? null)->toBe(rtrim($fixture, "\n"));
});
