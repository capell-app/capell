<?php

declare(strict_types=1);

namespace Capell\Marketplace\Enums;

/** What this site knows about its licence. Only some states show the admin notice; none changes how the site runs. */
enum SiteLicenceState: string
{
    /** Every paid package on the site is licensed for this domain. */
    case Licensed = 'licensed';

    /** A local or staging site: free, and nothing is sent. */
    case NonProduction = 'non_production';

    /** Paid packages are installed but no licence key is set. */
    case MissingKey = 'missing_key';

    /** The licence's production sites are all taken by other sites. */
    case OverLimit = 'over_limit';

    /** The licence has ended. */
    case Expired = 'expired';

    /** A paid package on the site is not covered by any key the site holds. */
    case Unlicensed = 'unlicensed';

    public function showsNotice(): bool
    {
        return in_array($this, [self::MissingKey, self::OverLimit, self::Expired, self::Unlicensed], true);
    }

    /** The admin notice heading; empty for a state that shows no notice. */
    public function noticeHeading(): string
    {
        return match ($this) {
            self::MissingKey => __('capell-marketplace::licence.notice.missing_key.heading'),
            self::OverLimit => __('capell-marketplace::licence.notice.over_limit.heading'),
            self::Expired => __('capell-marketplace::licence.notice.expired.heading'),
            self::Unlicensed => __('capell-marketplace::licence.notice.unlicensed.heading'),
            self::Licensed, self::NonProduction => '',
        };
    }

    /**
     * The admin notice body; empty for a state that shows no notice.
     *
     * @param  array<string, int|string|null>  $replace
     */
    public function noticeBody(array $replace): string
    {
        return match ($this) {
            self::MissingKey => __('capell-marketplace::licence.notice.missing_key.body', $replace),
            self::OverLimit => __('capell-marketplace::licence.notice.over_limit.body', $replace),
            self::Expired => __('capell-marketplace::licence.notice.expired.body', $replace),
            self::Unlicensed => __('capell-marketplace::licence.notice.unlicensed.body', $replace),
            self::Licensed, self::NonProduction => '',
        };
    }
}
