@php
    /** @var \Capell\Marketplace\Data\SiteLicenceStatusData $status */
@endphp

<div
    role="status"
    class="mb-6 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-100"
    data-capell-site-licence-notice="{{ $status->state->value }}"
>
    <p class="font-semibold">{{ $status->state->noticeHeading() }}</p>
    <p class="mt-1">
        {{
            $status->state->noticeBody([
                'used' => $status->sitesUsed,
                'limit' => $status->siteLimit,
                'packages' => implode(', ', $status->unlicensedPackages),
            ])
        }}
    </p>
    <p class="mt-1 text-amber-900/80 dark:text-amber-100/80">
        {{ __('capell-marketplace::licence.notice.reassurance') }}
    </p>
</div>
