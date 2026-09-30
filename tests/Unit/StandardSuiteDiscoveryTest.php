<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class StandardSuiteDiscoveryTest extends TestCase
{
    public function test_every_runnable_test_belongs_to_a_standard_suite(): void
    {
        $root = dirname(__DIR__, 2);
        $configuration = simplexml_load_file($root . '/phpunit.xml');
        $this->assertNotFalse($configuration);
        $uncovered = [];
        $roots = [$root . '/tests', ...(glob($root . '/packages/*/tests', GLOB_ONLYDIR) ?: [])];
        foreach ($roots as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $path = substr((string) $file->getPathname(), strlen($root) + 1);
                $source = file_get_contents($file->getPathname());
                $this->assertIsString($source);
                // Test.php also finds PHPUnit classes; tokenising finds Pest files
                // with nonstandard names without evaluating or booting any test.
                $runnable = str_ends_with($path, 'Test.php');
                $tokens = array_values(array_filter(token_get_all($source), static fn (mixed $token): bool => ! is_array($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)));
                foreach ($tokens as $index => $token) {
                    if (is_array($token) && $token[0] === T_STRING && in_array($token[1], ['it', 'test', 'describe', 'arch'], true) && ($tokens[$index + 1] ?? null) === '(' && ($tokens[$index + 2] ?? null) !== ')') {
                        $previous = $tokens[$index - 1] ?? null;
                        if (! is_array($previous) || ! in_array($previous[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                            $runnable = true;
                        }
                    }
                }

                if (! $runnable) {
                    continue;
                }

                if ($this->isExplicitException($path)) {
                    continue;
                }

                $covered = false;
                foreach ($configuration->testsuites->testsuite as $suite) {
                    $excluded = false;
                    foreach ($suite->exclude as $exclude) {
                        $excluded = $excluded || $this->matchesPath($path, (string) $exclude);
                    }

                    foreach ($suite->directory as $entry) {
                        $covered = $covered || (! $excluded && $this->matchesPath($path, (string) $entry) && str_ends_with($path, (string) ($entry['suffix'] ?? 'Test.php')));
                    }

                    foreach ($suite->file as $entry) {
                        $covered = $covered || $path === preg_replace('~^\./~', '', (string) $entry);
                    }
                }

                if (! $covered) {
                    $uncovered[] = $path;
                }
            }
        }

        sort($uncovered);
        $this->assertSame([], $uncovered, "Runnable tests outside standard suites:\n" . implode("\n", $uncovered));
    }

    public function test_the_timestamp_mariadb_proof_is_declared_only_in_its_opt_in_suite(): void
    {
        $root = dirname(__DIR__, 2);
        $proof = 'packages/core/tests/MariaDB/ImplicitTimestampRepairTest.php';
        $standard = simplexml_load_file($root . '/phpunit.xml');
        $optIn = simplexml_load_file($root . '/phpunit.mariadb.xml');
        $this->assertNotFalse($standard);
        $this->assertNotFalse($optIn);
        $this->assertTrue($this->isExplicitException($proof));

        foreach ($standard->testsuites->testsuite as $suite) {
            foreach ($suite->directory as $directory) {
                $this->assertFalse($this->matchesPath($proof, (string) $directory));
            }

            foreach ($suite->file as $file) {
                $this->assertNotSame($proof, preg_replace('~^\./~', '', (string) $file));
            }
        }

        $declaredFiles = [];
        foreach ($optIn->testsuites->testsuite as $suite) {
            foreach ($suite->file as $file) {
                // Pest's explicit-file lookup must match the canonical source path.
                $this->assertSame($proof, (string) $file);
                $declaredFiles[] = (string) $file;
            }
        }

        $this->assertSame([$proof], $declaredFiles);
    }

    private function matchesPath(string $path, string $pattern): bool
    {
        $pattern = preg_replace('~^\./~', '', rtrim($pattern, '/'));
        $regex = str_replace('\\*', '[^/]*', preg_quote((string) $pattern, '~'));

        return preg_match('~^' . $regex . '(?:/|$)~', $path) === 1;
    }

    private function isExplicitException(string $path): bool
    {
        // Browser tests need their own browser runner; MariaDB compatibility
        // tests require the deliberately separate phpunit.mariadb.xml harness.
        // Fixtures are autoloaded collaborators, not executable test classes.
        $exceptions = [
            '~(?:^|/)Browser/~' => 'Separate browser runner and services',
            '~^tests/MariaDB/MariaDbMigrationCompatibilityTest\.php$~' => 'Separate MariaDB compatibility harness',
            '~^packages/core/tests/MariaDB/ImplicitTimestampRepairTest\.php$~' => 'Opt-in MariaDB 10.5 timestamp proof in phpunit.mariadb.xml',
            '~(?:^|/)[Ff]ixtures/~' => 'Autoloaded fixture classes, not test cases',
        ];

        return array_any(array_keys($exceptions), fn (string $pattern): bool => preg_match($pattern, $path) === 1);
    }
}
