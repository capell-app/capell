<?php

declare(strict_types=1);

namespace Capell\Installer\Actions;

use Capell\Core\Actions\Install\BuildInstallReviewAction;
use Capell\Core\Data\InstallInputData;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ReviewInstallerInputAction
{
    use AsFake;
    use AsObject;

    /** @return array{items: array<string, string>, token: string} */
    public function handle(InstallInputData $input, bool $runAsJob = false, string $context = ''): array
    {
        $items = $this->items($input, $runAsJob);

        return [
            'items' => $items,
            'token' => Crypt::encryptString(json_encode([
                'fingerprint' => $this->fingerprint($input, $items, $context),
                'expires' => now()->addMinutes(30)->timestamp,
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    public function accepts(InstallInputData $input, string $token, bool $runAsJob = false, string $context = ''): bool
    {
        try {
            $review = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return false;
        }

        return is_array($review) && is_int($review['expires'] ?? null)
            && $review['expires'] >= now()->timestamp
            && is_string($review['fingerprint'] ?? null)
            && hash_equals($review['fingerprint'], $this->fingerprint($input, $this->items($input, $runAsJob), $context));
    }

    /** @return array<string, string> */
    private function items(InstallInputData $input, bool $runAsJob): array
    {
        return [...BuildInstallReviewAction::run($input)->items,
            __('capell-installer::installer.review_mode') => $runAsJob
                ? __('capell-installer::installer.review_mode_queue')
                : __('capell-installer::installer.review_mode_browser'),
        ];
    }

    /** @param array<string, string> $items */
    private function fingerprint(InstallInputData $input, array $items, string $context): string
    {
        // Include credentials without returning or retaining them in the review token.
        // Rebuild the review too: changes to installed packages or account state require renewed acceptance.
        return hash('sha256', json_encode([$input->toArray(), $items, $context], JSON_THROW_ON_ERROR));
    }
}
