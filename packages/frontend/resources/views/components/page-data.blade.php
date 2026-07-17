<?php
use Capell\Frontend\Facades\Frontend;
use Illuminate\Support\Facades\Route;

$site = Frontend::site();
$page = Frontend::page();
$language = Frontend::language();
$theme = Frontend::theme();

$routeName = config('capell-page.frontend.route_name', 'capell-frontend.beacon');
$beaconRoute = is_string($routeName) && Route::has($routeName) ? route($routeName, [], false) : null;

$beacon = [
    'url' => $beaconRoute,
    'timeout' => config('session.lifetime') * 60 * 1000,
    'error' => Frontend::isError(),
    'payload' => [],
];
?>

<div wire:ignore>
    <script>
        window.beaconData = @json($beacon)
        ;(function (beacon) {
            if (!beacon || !beacon.url || beacon.error) {
                return
            }

            const token = document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content')
            const beaconUrl = new URL(beacon.url, window.location.origin)

            const fetchBeaconData = function () {
                fetch(beaconUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        ...(token ? { 'X-CSRF-TOKEN': token } : {}),
                    },
                    body: JSON.stringify({
                        ...beacon.payload,
                        url: window.location.href,
                    }),
                })
                    .then((response) => (response.ok ? response.json() : null))
                    .then((payload) => {
                        if (!payload || !Array.isArray(payload.scripts)) {
                            return
                        }

                        payload.scripts.forEach((scriptContent) => {
                            if (
                                typeof scriptContent !== 'string' ||
                                scriptContent.trim() === ''
                            ) {
                                return
                            }

                            const script = document.createElement('script')
                            script.text = scriptContent
                            document.body.appendChild(script)
                        })
                    })
                    .catch(() => {})
            }

            const queueBeaconFetch = function () {
                if ('requestIdleCallback' in window) {
                    window.requestIdleCallback(fetchBeaconData, {
                        timeout: 2000,
                    })

                    return
                }

                window.setTimeout(fetchBeaconData, 1)
            }

            if (document.readyState === 'complete') {
                queueBeaconFetch()

                return
            }

            window.addEventListener('load', queueBeaconFetch, { once: true })
        })(window.beaconData)
    </script>
</div>
