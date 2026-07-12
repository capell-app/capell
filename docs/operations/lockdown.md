# Lockdown

Lockdown is an emergency control for suspected admin account compromise or active frontend incidents. Use it when the public site should stop serving normal pages but one trusted admin session still needs access to investigate, rotate credentials, or recover the install.

When enabled from the Capell admin tools menu:

- public frontend routes return a `503` maintenance response;
- Filament admin remains available only to the activating user and configured break-glass users;
- other authenticated admin sessions are blocked on their next admin request;
- the state is stored in `storage/framework/capell-lockdown.json`, not the database.
- the public HTML [page cache](../architecture/page-cache.md) is moved aside and replaced with Lockdown HTML for the same cached paths.

Configure additional recovery users with environment variables:

```env
CAPELL_LOCKDOWN_USER_IDS=1,2
CAPELL_LOCKDOWN_EMAILS=owner@example.com,ops@example.com
```

These values feed the `lockdown.break_glass_user_ids` and
`lockdown.break_glass_emails` entries in `config/capell.php`.

## Using Lockdown

Open the Filament header tools menu and choose **Enable Lockdown**. The toolbar shows the active state, the admin shell displays a Lockdown banner, and the action requires confirmation before changing state.

While Lockdown is active:

- Laravel maintenance bypass secrets and bypass cookies are ignored for public frontend traffic;
- other admin sessions are denied on their next admin request;
- the activating admin can disable Lockdown from the same header tools menu;
- configured break-glass users can also keep admin access.

Choose **Disable Lockdown** when the incident is resolved. This reopens the public frontend and restores normal admin access.

## Page cache behaviour

Lockdown does not purge the live static page cache. On activation Capell renames the current `page-cache` directory to a preserved sibling, creates a fresh `page-cache` directory, and writes Lockdown HTML into the cached paths that previously existed. This keeps web-server-level static cache rules from serving stale public HTML while avoiding a cold cache rebuild when Lockdown is disabled.

On deactivation Capell removes the temporary Lockdown cache directory and moves the preserved live cache back into place. Avoid manually clearing `page-cache` during Lockdown unless you are deliberately accepting a cold rebuild after recovery.

## Recovery

If no allowed admin account is available, delete the Lockdown state file from the server as a final recovery path:

```sh
rm storage/framework/capell-lockdown.json
```

If the static page cache was swapped and the admin action cannot run, inspect `public/page-cache.capell-live-*` before deleting cache files. The preserved directory contains the live cache that Capell normally restores when Lockdown is disabled.
