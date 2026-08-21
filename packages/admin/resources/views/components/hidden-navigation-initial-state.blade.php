<script>
    (() => {
        const openedSessionKey = 'capell.admin.navigation.opened';

        try {
            if (window.sessionStorage.getItem(openedSessionKey) !== 'true') {
                window.localStorage.setItem('isOpen', 'false');
                window.localStorage.setItem('isOpenDesktop', 'false');
            }
        } catch {
            // Storage can be unavailable in embedded or privacy-restricted admin contexts.
        }

        document.addEventListener('click', (event) => {
            if (! (event.target instanceof Element) || ! event.target.closest('.fi-topbar-open-sidebar-btn')) {
                return;
            }

            try {
                window.sessionStorage.setItem(openedSessionKey, 'true');
            } catch {
                // The Filament control remains usable when browser storage is unavailable.
            }
        });
    })();
</script>
