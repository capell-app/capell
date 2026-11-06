<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Closure;
use Composer\InstalledVersions;
use Laravel\SerializableClosure\SerializableClosure;
use Laravel\SerializableClosure\Serializers\Native;
use Laravel\SerializableClosure\Serializers\Signed;
use Laravel\SerializableClosure\UnsignedSerializableClosure;
use Livewire\Features\SupportCssModules\SupportCssModules;
use Livewire\Features\SupportJsModules\SupportJsModules;
use Livewire\Features\SupportTesting\InitialRender;
use ReflectionClass;
use ReflectionFunction;
use Throwable;

/** Verifies vendor closure identity without restoring or invoking cached actions. */
final class LivewireRouteAction
{
    private const array SCOPES = [SupportCssModules::class, SupportJsModules::class, InitialRender::class];

    public static function closureClass(mixed $action): ?string
    {
        try {
            if ($action instanceof Closure) {
                $reflection = new ReflectionFunction($action);
                $scope = $reflection->getClosureScopeClass()?->getName();

                return $scope !== null && self::vendorFile($scope) === $reflection->getFileName() ? $scope : null;
            }

            if (! is_string($action)) {
                return null;
            }

            $wrapper = self::inertObject($action);
            if (! in_array($wrapper['__PHP_Incomplete_Class_Name'] ?? null, [SerializableClosure::class, UnsignedSerializableClosure::class], true)) {
                return null;
            }

            $data = (array) ($wrapper['serializable'] ?? null);
            if (($data['__PHP_Incomplete_Class_Name'] ?? null) === Signed::class) {
                if (! is_string($data['serializable'] ?? null) || ! is_string($data['hash'] ?? null) || ! Signed::$signer?->verify($data)) {
                    return null;
                }

                $data = self::inertObject($data['serializable']);
            }

            $scope = $data['scope'] ?? null;
            if (($data['__PHP_Incomplete_Class_Name'] ?? null) !== Native::class || ! is_string($scope)
                || ($data['use'] ?? null) !== [] || ! array_key_exists('this', $data) || $data['this'] !== null || ! is_string($data['function'] ?? null)) {
                return null;
            }

            $file = self::vendorFile($scope);
            if ($file === null) {
                return null;
            }

            // Native does not retain the original filename. Prove the code comes from the reflected
            // vendor scope's installed source instead; never include its closure stream to reflect it.
            $source = file_get_contents($file);
            if ($source === false) {
                return null;
            }

            $imports = [];
            preg_match_all('/^use ([\\\\\w]+)(?: as (\w+))?;/m', $source, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $imports['\\' . $match[1]] = $match[2] ?? substr(strrchr($match[1], '\\') ?: $match[1], 1);
            }

            $code = self::tokens('<?php ' . $data['function'], $imports);
            $vendor = self::tokens($source);
            if (array_slice($code, 0, 2) !== ['function', '('] || end($code) !== '}') {
                return null;
            }

            $length = count($code);
            foreach (array_keys($vendor) as $offset) {
                if ($length > 0 && array_slice($vendor, $offset, $length) === $code) {
                    return $scope;
                }
            }
        } catch (Throwable) {
            // Unknown serialisation formats, missing source and malformed actions are unverifiable.
        }

        return null;
    }

    /** @return array<array-key, mixed> */
    private static function inertObject(string $serialized): array
    {
        // No class is instantiated and no __wakeup/__unserialize hook or captured code can run.
        $value = @unserialize($serialized, ['allowed_classes' => false]);

        return is_object($value) ? (array) $value : [];
    }

    private static function vendorFile(string $scope): ?string
    {
        if (! in_array($scope, self::SCOPES, true)) {
            return null;
        }

        $root = InstalledVersions::getInstallPath('livewire/livewire');
        $file = new ReflectionClass($scope)->getFileName();
        $root = $root === null ? false : realpath($root);
        $file = $file === false ? false : realpath($file);

        return $root !== false && $file !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) ? $file : null;
    }

    /**
     * Undo only SerializableClosure's qualification of known imports and global functions.
     * Every other token must match the installed vendor source exactly.
     *
     * @param  array<string, string>  $imports
     * @return list<string>
     */
    private static function tokens(string $source, array $imports = []): array
    {
        $result = [];
        $tokens = token_get_all($source);
        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                $result[] = $token;

                continue;
            }

            if (in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $text = $token[1];
            if ($token[0] === T_NAME_FULLY_QUALIFIED && $imports !== []) {
                if (isset($imports[$text])) {
                    $text = $imports[$text];
                } elseif (! str_contains(substr($text, 1), '\\') && function_exists($text)
                    && ($tokens[$index + 1] ?? null) === '(' && end($result) !== 'new') {
                    $text = substr($text, 1);
                }
            }

            $result[] = $text;
        }

        return $result;
    }
}
