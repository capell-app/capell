@props(['url'])

@php
    $logo = config('mail.markdown.logo');
    $logo = is_string($logo) && $logo !== '' ? $logo : null;
@endphp

<tr>
    <td class="header">
        <a
            href="{{ $url }}"
            style="display: inline-block"
        >
            @if ($logo)
                <img
                    src="{{ $logo }}"
                    class="logo"
                    alt="{{ config('app.name') }} Logo"
                />
            @elseif (trim($slot) === 'Laravel')
                <img
                    src="https://laravel.com/img/notification-logo-v2.1.png"
                    class="logo"
                    alt="Laravel Logo"
                />
            @else
                {!! $slot !!}
            @endif
        </a>
    </td>
</tr>
