<?php

declare(strict_types=1);

namespace Capell\Tests\Support;

use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class ScriptFixture
{
    public readonly string $root;

    public function __construct()
    {
        $directory = sys_get_temp_dir() . '/capell-script-fixture-' . bin2hex(random_bytes(8));
        (new Filesystem)->mkdir($directory);
        $root = realpath($directory);
        throw_if($root === false, RuntimeException::class, 'Unable to resolve script fixture root.');

        $this->root = $root;
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $this->write('vendor/autoload.php', '<?php return require ' . var_export($autoload, true) . ';');
    }

    public function copy(string $relativePath): void
    {
        (new Filesystem)->copy(dirname(__DIR__, 2) . '/' . $relativePath, $this->root . '/' . $relativePath);
    }

    public function write(string $relativePath, string $contents): void
    {
        (new Filesystem)->dumpFile($this->root . '/' . $relativePath, $contents);
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string|false>  $environment
     */
    public function php(string $script, array $arguments = [], array $environment = []): Process
    {
        $process = new Process([PHP_BINARY, $this->root . '/' . $script, ...$arguments], $this->root, $environment);
        $process->setTimeout(30);
        $process->run();

        return $process;
    }

    public function close(): void
    {
        (new Filesystem)->remove($this->root);
    }
}
