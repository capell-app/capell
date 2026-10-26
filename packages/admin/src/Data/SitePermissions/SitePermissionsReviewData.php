<?php

declare(strict_types=1);

namespace Capell\Admin\Data\SitePermissions;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Data;

final class SitePermissionsReviewData extends Data
{
    /**
     * @param  list<SitePermissionChangeData>  $additions
     * @param  list<SitePermissionChangeData>  $revocations
     */
    public function __construct(
        public int $actorId,
        public int $siteId,
        public string $fingerprint,
        public array $additions,
        public array $revocations,
    ) {}

    public static function fromConfirmationToken(string $token): self
    {
        try {
            $review = Crypt::decrypt($token);
            if ($review instanceof self) {
                return $review;
            }
        } catch (DecryptException) {
            // An altered review cannot authorise a write.
        }

        throw ValidationException::withMessages([
            'assignments' => __('capell-admin::site_permissions.stale'),
        ]);
    }

    public function confirmationToken(): string
    {
        return Crypt::encrypt($this);
    }
}
