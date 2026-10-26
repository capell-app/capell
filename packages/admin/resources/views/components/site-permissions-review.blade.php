<div class="space-y-4">
    <p>{{ __('capell-admin::site_permissions.review_description') }}</p>

    @if ($review !== null)
        @foreach (['additions', 'revocations'] as $kind)
            <section>
                <h3 class="font-semibold">
                    {{ $kind === 'additions' ? __('capell-admin::site_permissions.additions') : __('capell-admin::site_permissions.revocations') }}
                </h3>
                <ul class="list-inside list-disc">
                    @forelse ($review->{$kind} as $change)
                        <li>
                            {{ $kind === 'additions' ? __('capell-admin::site_permissions.grant', ['user' => $change->userName, 'role' => $change->roleName]) : __('capell-admin::site_permissions.revoke', ['user' => $change->userName, 'role' => $change->roleName]) }}
                            @if ($change->losesSiteAccess)
                                <strong
                                    >{{ $change->isActor ? __('capell-admin::site_permissions.own_access_lost') : __('capell-admin::site_permissions.access_lost') }}</strong
                                >
                            @elseif ($change->isActor && $kind === 'revocations')
                                <strong
                                    >{{ __('capell-admin::site_permissions.own_role_removed') }}</strong
                                >
                            @endif
                        </li>
                    @empty
                        <li>{{ __('capell-admin::site_permissions.none') }}</li>
                    @endforelse
                </ul>
            </section>
        @endforeach
    @endif
</div>
