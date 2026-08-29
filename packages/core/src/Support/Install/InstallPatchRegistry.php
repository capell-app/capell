<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Core\Support\Patching\Patch;
use Closure;

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

    public function __construct(private readonly ?ExtensionContributionReceiptRegistry $receipts = null) {}

    /**
     * @param  callable(InstallPatchContext): ?Patch  $factory  Return the patch when it applies to the context, or null to skip.
     */
    public function register(callable $factory, ?InstallPatchConfirmation $confirmation = null, ?string $key = null): void
    {
        $callable = Closure::fromCallable($factory);
        $this->contributions[] = [
            'factory' => $callable,
            'confirmation' => $confirmation,
        ];
        $reflection = new \ReflectionFunction($callable);
        $identity = $this->callableIdentity($reflection, $key);
        $receiptKey = $key !== null && $key !== '' ? $key : hash('sha256', $identity);
        $this->receipts?->recordFromContext(
            ExtensionContributionType::Migration,
            'install-patch:' . $receiptKey,
            $identity,
            self::class,
        );
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

    private function callableIdentity(\ReflectionFunction $reflection, ?string $key): string
    {
        if ($key !== null && $key !== '') {
            return 'callable:' . $key;
        }

        $scope = $reflection->getClosureScopeClass()?->getName();
        if ($scope !== null && $reflection->getName() !== '{closure}') {
            return $scope . '::' . $reflection->getName();
        }

        return 'closure:' . hash('sha256', $this->normalisedClosureSource($reflection));
    }

    private function normalisedClosureSource(\ReflectionFunction $reflection): string
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
        $normalised = '';

        foreach (token_get_all('<?php ' . $source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                    continue;
                }

                $normalised .= $token[1];
                continue;
            }

            $normalised .= $token;
        }

        return $normalised;
    }
}
