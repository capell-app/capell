<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

it('reports multi-argument negative containment assertions and ignores safe PHP', function (): void {
    $root = sys_get_temp_dir() . '/weak-containment-' . bin2hex(random_bytes(8));
    mkdir($root);

    try {
        file_put_contents($root . '/cases.php', <<<'PHP_FIXTURE'
<?php
expect($value)->not->toContain('one', 'two');
expect($value)
    ->not->toContain(
        nested('one', 'two'),
        ['three', 'four'],
    );
expect($value)->not->toContain(nested('one', 'two'));
$text = "->not->toContain('one', 'two')";
expect($value)->not->toContainOnlyInstancesOf(Foo::class);
expect($value)->not->toContain(...$needles);
PHP_FIXTURE);
        mkdir($root . '/vendor');
        mkdir($root . '/node_modules');
        file_put_contents($root . '/vendor/ignored.php', "<?php expect(\$x)->not->toContain('a', 'b');");
        file_put_contents($root . '/node_modules/ignored.php', "<?php expect(\$x)->not->toContain('a', 'b');");
        $command = [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/check-test-weak-assertions.php', '--root=' . $root];
        $process = new Process($command);
        // Hang ceiling only: a busy host must slow this down, never fail it.
        $process->setTimeout(300);
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and(explode("\n", trim($process->getErrorOutput())))->toBe([
                $root . '/cases.php:2: Use separate ->not->toContain(...) assertions.',
                $root . '/cases.php:4: Use separate ->not->toContain(...) assertions.',
                $root . '/cases.php:11: Use separate ->not->toContain(...) assertions.',
            ]);

        file_put_contents($root . '/cases.php', <<<'PHP_FIXTURE'
<?php
expect($value)->not->toContain(nested('one', 'two'));
$text = "->not->toContain('one', 'two')";
expect($value)->not->toContainOnlyInstancesOf(Foo::class);
PHP_FIXTURE);
        $clean = new Process($command);
        // Hang ceiling only: a busy host must slow this down, never fail it.
        $clean->setTimeout(300);
        $clean->run();
        expect($clean->getExitCode())->toBe(0)
            ->and($clean->getErrorOutput())->toBe('');
    } finally {
        File::deleteDirectory($root);
    }
});
