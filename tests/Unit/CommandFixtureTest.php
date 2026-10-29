<?php

declare(strict_types=1);

use Capell\Tests\Support\CommandFixture;

it('keeps generated command fakes independent of host PHP configuration', function (string $configurationSource): void {
    $fixture = new CommandFixture;
    $fixture->files->write('host/php.ini', 'auto_prepend_file="' . $fixture->files->root . '/host-prepend.php"' . "\n");
    $fixture->files->write('host-prepend.php', '<?php file_put_contents(__DIR__ . "/host-config-loaded", "loaded");');
    $fixture->fake('diagnostic', 'echo "fixture output";');
    try {
        $process = $fixture->run(['diagnostic', 'alpha', 'two words'], [
            'PHPRC' => $configurationSource === 'PHPRC' ? $fixture->files->root . '/host' : false,
            'PHP_INI_SCAN_DIR' => $configurationSource === 'PHP_INI_SCAN_DIR' ? $fixture->files->root . '/host' : '',
        ]);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())->toBe('fixture output')
            ->and($fixture->calls('diagnostic'))->toBe([
                ['command' => 'diagnostic', 'cwd' => $fixture->files->root, 'arguments' => ['alpha', 'two words']],
            ])
            ->and(file_exists($fixture->files->root . '/host-config-loaded'))->toBeFalse();
    } finally {
        $fixture->close();
    }
})->with(['PHPRC', 'PHP_INI_SCAN_DIR']);
