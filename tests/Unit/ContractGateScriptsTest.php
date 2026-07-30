<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

function contractGateFixture(): string
{
    $root = sys_get_temp_dir() . '/capell-contract-gates-' . bin2hex(random_bytes(6));
    mkdir($root . '/scripts', 0777, true);
    mkdir($root . '/packages/fixture/src/Jobs', 0777, true);
    mkdir($root . '/packages/fixture/src/Listeners', 0777, true);
    mkdir($root . '/packages/fixture/resources/lang/en', 0777, true);

    foreach (['audit-language-keys.sh', 'check-language-key-drift.php', 'check-queue-contract.php'] as $script) {
        copy(dirname(__DIR__, 2) . '/scripts/' . $script, $root . '/scripts/' . $script);
    }

    return $root;
}

function runContractGate(string $root, string ...$command): Process
{
    $process = new Process($command, $root);
    $process->run();

    return $process;
}

function deleteContractGateFixture(string $path): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($path);
}

it('ratchets normalized dynamic translation sites and validated families', function (): void {
    $root = contractGateFixture();

    try {
        file_put_contents($root . '/packages/fixture/resources/lang/en/labels.php', "<?php\nreturn ['status' => ['active' => 'Active', 'inactive' => 'Inactive']];\n");
        file_put_contents($root . '/packages/fixture/src/Status.php', "<?php\n__(sprintf('capell-fixture::labels.status.%s', \$status));\n");

        $update = runContractGate($root, PHP_BINARY, 'scripts/check-language-key-drift.php', '--update');
        expect($update->isSuccessful())->toBeTrue();

        $baseline = json_decode((string) file_get_contents($root . '/scripts/language-keys-baseline.json'), true, 512, JSON_THROW_ON_ERROR);
        expect($baseline['unused'])->toBe([])
            ->and($baseline['dynamicFamilies'])->toBe(['capell-fixture::labels.status.*'])
            ->and($baseline['dynamicSites'])->toHaveCount(1);

        file_put_contents($root . '/packages/fixture/src/NewStatus.php', "<?php\n__(sprintf('capell-fixture::labels.other.%s', \$status));\n");
        $check = runContractGate($root, PHP_BINARY, 'scripts/check-language-key-drift.php', '--format=json');

        expect($check->getExitCode())->toBe(2);
        $report = json_decode($check->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        expect($report['new_dynamic_sites'])->toHaveCount(1);
    } finally {
        deleteContractGateFixture($root);
    }
});

it('enforces exact queue declarations and real listener overlap protection', function (): void {
    $root = contractGateFixture();

    try {
        $valid = <<<'PHP'
        <?php
        final class ValidJob implements ShouldQueue {
            public int $tries = 2;
            public int $backoff = 10;
            public int $timeout = 30;
            public function failed(?Throwable $exception): void {}
        }
        PHP;
        file_put_contents($root . '/packages/fixture/src/Jobs/ValidJob.php', $valid);
        expect(runContractGate($root, PHP_BINARY, 'scripts/check-queue-contract.php', '--update')->isSuccessful())->toBeTrue();

        $invalid = <<<'PHP'
        <?php
        use Illuminate\Queue\Middleware\WithoutOverlapping;
        final class ValidJob implements ShouldQueue {
            protected int $tries = 0;
            public int $backoff = 10;
            public function timeout(): int { return 30; }
            public function handle(): void { Http::get('https://example.test'); }
            public function failed(Throwable $exception) {}
        }
        PHP;
        file_put_contents($root . '/packages/fixture/src/Jobs/ValidJob.php', $invalid);
        file_put_contents(
            $root . '/packages/fixture/src/Listeners/DebouncedListener.php',
            "<?php\n/** @queue-contract-upstream-debounce Requests are coalesced before dispatch. */\nfinal class DebouncedListener implements ShouldQueue { public int \$tries = 2; public int \$backoff = 5; public function failed(object \$event, Throwable \$exception): void {} }\n",
        );

        $check = runContractGate($root, PHP_BINARY, 'scripts/check-queue-contract.php', '--format=json');
        expect($check->getExitCode())->toBe(2);
        $report = json_decode($check->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $rules = array_column($report['new'], 'rule');

        expect($rules)->toContain('QUEUE001')
            ->toContain('QUEUE003')
            ->toContain('QUEUE005')
            ->not->toContain('QUEUE004');
    } finally {
        deleteContractGateFixture($root);
    }
});

it('accepts returned overlap middleware and independently rejects a malformed listener failure callback', function (): void {
    $root = contractGateFixture();

    try {
        $listenerPath = $root . '/packages/fixture/src/Listeners/ProtectedListener.php';
        $protectedListener = <<<'PHP'
        <?php
        final class ProtectedListener implements ShouldQueue {
            public int $tries = 2;
            public int $backoff = 5;
            public function middleware(): array { return [new WithoutOverlapping('fixture')]; }
            public function failed(object $event, ?Throwable $exception): void {}
        }
        PHP;
        file_put_contents($listenerPath, $protectedListener);

        expect(runContractGate($root, PHP_BINARY, 'scripts/check-queue-contract.php', '--update')->isSuccessful())->toBeTrue();
        $baseline = json_decode((string) file_get_contents($root . '/scripts/queue-contract-baseline.json'), true, 512, JSON_THROW_ON_ERROR);
        expect($baseline['violations'])->toBe([]);

        file_put_contents(
            $listenerPath,
            str_replace('?Throwable $exception', 'Throwable $exception', $protectedListener),
        );
        $check = runContractGate($root, PHP_BINARY, 'scripts/check-queue-contract.php', '--format=json');
        $report = json_decode($check->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        expect($check->getExitCode())->toBe(2)
            ->and(array_column($report['new'], 'rule'))->toBe(['QUEUE003']);
    } finally {
        deleteContractGateFixture($root);
    }
});
