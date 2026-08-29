<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use BackedEnum;
use Capell\Core\Contracts\Extensions\RecordsExtensionContributionReceipt;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Support\Patching\Patch;
use Closure;
use InvalidArgumentException;
use ReflectionFunction;
use ReflectionObject;
use ReflectionReference;

/**
 * Core-owned seam for install-time application patches. Companion packages
 * (for example the installer) register patch factories from their service
 * providers; the install command evaluates them against the current install
 * selection without depending on any contributing package's classes.
 */
final class InstallPatchRegistry
{
    /** @var list<array{factory: Closure(InstallPatchContext): ?Patch, confirmation: ?InstallPatchConfirmation}> */
    private array $contributions = [];

    public function __construct(private readonly ?RecordsExtensionContributionReceipt $receipts = null) {}

    /**
     * @param  callable(InstallPatchContext): ?Patch  $factory  Return the patch when it applies to the context, or null to skip.
     */
    public function register(callable $factory, ?InstallPatchConfirmation $confirmation = null, ?string $key = null): void
    {
        $callable = Closure::fromCallable($factory);
        $reflection = new ReflectionFunction($callable);
        $identity = $this->callableIdentity($reflection, $key);
        $receiptKey = $key !== null && $key !== '' ? $key : hash('sha256', $identity);
        $this->receipts?->recordContribution(
            ExtensionContributionType::InstallPatch,
            'install-patch:' . $receiptKey,
            $identity,
            self::class,
            'install',
        );
        $this->contributions[] = [
            'factory' => $callable,
            'confirmation' => $confirmation,
        ];
    }

    /**
     * @return list<RegisteredInstallPatch>
     */
    public function patchesFor(InstallPatchContext $context): array
    {
        $registeredPatches = [];

        foreach ($this->contributions as $contribution) {
            $patch = ($contribution['factory'])($context);

            if (! $patch instanceof Patch) {
                continue;
            }

            $registeredPatches[] = new RegisteredInstallPatch($patch, $contribution['confirmation']);
        }

        return $registeredPatches;
    }

    private function callableIdentity(ReflectionFunction $reflection, ?string $key): string
    {
        if ($key !== null && $key !== '') {
            return 'callable:' . $key;
        }

        $scope = $reflection->getClosureScopeClass()?->getName();
        if ($scope !== null && $reflection->getName() !== '{closure}') {
            return $scope . '::' . $reflection->getName();
        }

        $source = $this->normalisedClosureSource($reflection);
        $captures = $this->closureCaptures($reflection, $this->sourceUsesThis($source));

        return 'closure:' . hash('sha256', ($scope ?? '') . '|' . $source . '|' . $captures);
    }

    private function normalisedClosureSource(ReflectionFunction $reflection): string
    {
        $file = $reflection->getFileName();
        $start = $reflection->getStartLine();
        $end = $reflection->getEndLine();

        if (! is_string($file) || ! is_file($file) || $start === false || $end === false) {
            return 'unavailable';
        }

        $lines = file($file);
        if ($lines === false) {
            return 'unavailable';
        }

        $source = implode('', array_slice($lines, $start - 1, $end - $start + 1));
        $tokens = token_get_all('<?php ' . $source);
        $closureIndex = null;
        foreach ($tokens as $index => $token) {
            if (! is_array($token) || ! in_array($token[0], [T_FUNCTION, T_FN], true)) {
                continue;
            }

            if ($token[0] === T_FUNCTION) {
                $next = $index + 1;
                while ($next < count($tokens) && is_array($tokens[$next]) && in_array($tokens[$next][0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                    $next++;
                }

                if (isset($tokens[$next]) && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) {
                    continue;
                }
            }

            $closureIndex = $index;
            break;
        }

        if ($closureIndex === null) {
            return 'unavailable';
        }

        $closureType = $tokens[$closureIndex][0];
        $start = $closureIndex;
        $previous = $closureIndex - 1;
        while ($previous >= 0 && is_array($tokens[$previous]) && in_array($tokens[$previous][0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
            $previous--;
        }
        if ($previous >= 0 && is_array($tokens[$previous]) && $tokens[$previous][0] === T_STATIC) {
            $start = $previous;
        }

        $normalised = '';
        $bodyStarted = $closureType === T_FUNCTION;
        $depth = 0;

        $endIndex = count($tokens) - 1;
        foreach (array_slice($tokens, $start, null, true) as $index => $token) {
            $value = is_array($token) ? $token[1] : $token;
            $type = is_array($token) ? $token[0] : null;

            if ($type !== null && in_array($type, [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                continue;
            }

            if ($closureType === T_FN && $value === '=>') {
                $bodyStarted = true;
                $normalised .= $value;

                continue;
            }

            if ($closureType === T_FN && $bodyStarted) {
                if (in_array($value, [',', ';'], true) && $depth === 0) {
                    $endIndex = $index;
                    break;
                }

                if (in_array($value, [')', ']', '}'], true)) {
                    if ($depth === 0) {
                        $endIndex = $index;
                        break;
                    }

                    $depth--;
                }

                if (in_array($value, ['(', '[', '{'], true)) {
                    $depth++;
                }
            }

            if ($closureType === T_FUNCTION) {
                if ($value === '{') {
                    $depth++;
                } elseif ($value === '}') {
                    $depth--;
                }

                if ($bodyStarted && $depth === 0 && $value === '}') {
                    $normalised .= $value;
                    $endIndex = $index;

                    break;
                }
            }

            $normalised .= $value;
        }

        foreach (array_slice($tokens, $endIndex + 1, null, true) as $token) {
            if (is_array($token) && in_array($token[0], [T_FUNCTION, T_FN], true)) {
                throw new InvalidArgumentException(
                    'Anonymous install-patch factories must provide an explicit key when their source span contains multiple closures.',
                );
            }
        }

        return $normalised;
    }

    private function sourceUsesThis(string $source): bool
    {
        foreach (token_get_all('<?php ' . $source) as $token) {
            if (is_array($token) && $token[0] === T_VARIABLE && $token[1] === '$this') {
                return true;
            }
        }

        return false;
    }

    private function closureCaptures(ReflectionFunction $reflection, bool $includeBoundObject): string
    {
        $captures = [];
        $activeObjects = [];
        $activeReferences = [];
        $boundObject = $reflection->getClosureThis();
        if ($includeBoundObject && $boundObject !== null) {
            $captures['$this'] = $this->stableValue($boundObject, $activeObjects, $activeReferences);
        }

        foreach ($reflection->getStaticVariables() as $name => $value) {
            $captures[$name] = $this->stableValue($value, $activeObjects, $activeReferences);
        }

        ksort($captures);

        return json_encode($captures, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<mixed>  $values
     * @param  array<int, true>  $activeObjects
     * @param  array<string, true>  $activeReferences
     */
    private function stableArray(array $values, array &$activeObjects, array &$activeReferences): array
    {
        $stable = [];
        foreach ($values as $key => $value) {
            $reference = ReflectionReference::fromArrayElement($values, $key);
            $referenceId = $reference === null ? null : bin2hex($reference->getId());
            if ($referenceId !== null) {
                if (isset($activeReferences[$referenceId])) {
                    throw new InvalidArgumentException(
                        'Anonymous install-patch factories may not capture cyclic arrays.',
                    );
                }

                $activeReferences[$referenceId] = true;
            }

            try {
                $stable[(string) $key] = $this->stableValue($value, $activeObjects, $activeReferences);
            } finally {
                if ($referenceId !== null) {
                    unset($activeReferences[$referenceId]);
                }
            }
        }
        ksort($stable);

        return $stable;
    }

    /**
     * @param  array<int, true>  $activeObjects
     * @param  array<string, true>  $activeReferences
     */
    private function stableValue(mixed $value, array &$activeObjects, array &$activeReferences): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if ($value instanceof BackedEnum) {
            return ['enum' => $value::class, 'value' => $value->value];
        }

        if (is_array($value)) {
            return $this->stableArray($value, $activeObjects, $activeReferences);
        }

        if (is_object($value)) {
            $objectId = spl_object_id($value);
            if (isset($activeObjects[$objectId])) {
                throw new InvalidArgumentException(
                    'Anonymous install-patch factories may not capture cyclic objects.',
                );
            }

            $activeObjects[$objectId] = true;
            $properties = [];
            try {
                $reflection = new ReflectionObject($value);
                foreach ($reflection->getProperties() as $property) {
                    if (! $property->isInitialized($value)) {
                        continue;
                    }

                    $properties[$property->getDeclaringClass()->getName() . ':' . $property->getName()] = $this->stableValue(
                        $property->getValue($value),
                        $activeObjects,
                        $activeReferences,
                    );
                }
                ksort($properties);

                if ($properties === []) {
                    throw new InvalidArgumentException(
                        'Anonymous install-patch factories capturing object values must provide an explicit key.',
                    );
                }

                return [
                    'object' => $value::class,
                    'properties' => $properties,
                ];
            } finally {
                unset($activeObjects[$objectId]);
            }
        }

        throw new InvalidArgumentException(
            'Anonymous install-patch factories may only capture scalar, enum, array, or stateful object values without an explicit key.',
        );
    }
}
