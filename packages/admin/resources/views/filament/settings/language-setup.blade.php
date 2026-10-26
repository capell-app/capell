<div
    class="space-y-2 text-sm"
    data-capell-language-setup
>
    <p>{{ __('capell-admin::form.language_application_fallback', ['locale' => $setup->applicationLocale]) }}</p>
    <p>{{ __('capell-admin::form.language_admin_effective', ['locale' => $setup->adminLocale]) }}</p>
    <p>{{ __('capell-admin::form.language_public_control') }}</p>
    @if ($setup->userUrl)
        <a
            href="{{ $setup->userUrl }}"
            >{{ __('capell-admin::form.language_edit_preference') }}</a
        >
    @endif
    @if ($setup->sitesUrl)
        <a
            href="{{ $setup->sitesUrl }}"
            >{{ __('capell-admin::form.language_edit_sites') }}</a
        >
    @endif
</div>
