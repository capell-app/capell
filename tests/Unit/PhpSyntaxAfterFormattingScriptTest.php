<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

function runPhpSyntaxFormattingProcess(array $command): Process
{
    $process = new Process($command, dirname(__DIR__, 2));
    // Hang ceiling only: a busy host must slow this down, never fail it.
    $process->setTimeout(300);
    $process->run();

    return $process;
}

function writePhpSyntaxFixture(string $body): string
{
    $path = sys_get_temp_dir() . '/capell-php-syntax-' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($path, "<?php\n\ndeclare(strict_types=1);\n\nfinal class PhpSyntaxFixture\n{\n    public function run(): int\n    {\n        return {$body};\n    }\n}\n");

    return $path;
}

it('fails the lint:changed syntax step on the parse error the formatter chain once produced', function (): void {
    $path = writePhpSyntaxFixture('new ArrayObject->count()');

    $process = runPhpSyntaxFormattingProcess(['scripts/check-php-syntax.sh', $path]);
    unlink($path);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('PHP syntax error after formatting: ' . $path);
});

it('keeps parenthesised and PHP 8.4 new-expression method calls parseable through Pint', function (string $body): void {
    $path = writePhpSyntaxFixture($body);

    $pint = runPhpSyntaxFormattingProcess([PHP_BINARY, 'vendor/bin/pint', '--config', 'pint.json', $path]);
    $syntax = runPhpSyntaxFormattingProcess(['scripts/check-php-syntax.sh', $path]);
    $formatted = (string) file_get_contents($path);
    unlink($path);

    expect($pint->getExitCode())->toBe(0)
        ->and($syntax->getExitCode())->toBe(0, $syntax->getErrorOutput() . $formatted)
        ->and($formatted)->not->toContain('new ArrayObject->');
})->with([
    'parenthesised without braces' => ['(new ArrayObject)->count()'],
    'parenthesised with braces' => ['(new ArrayObject())->count()'],
    'PHP 8.4 unparenthesised' => ['new ArrayObject()->count()'],
]);

it('runs the syntax check on changed PHP files straight after Pint in lint:changed', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 2) . '/scripts/lint-changed.sh');

    $pint = strpos($script, '"$PINT_BINARY" --parallel "${PHP_FILES[@]}"');
    $syntax = strpos($script, './scripts/check-php-syntax.sh "${PHP_FILES[@]}"');

    expect($pint)->not->toBeFalse()
        ->and($syntax)->not->toBeFalse()
        ->and($syntax)->toBeGreaterThan($pint);
});
