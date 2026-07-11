# Account security

![Capell Account security screenshot](../images/admin-settings.png)

Practical steps to keep admin access safe. This page covers what Capell provides today and where to add anything extra.

## Strong passwords
- Set a strong, unique password for every admin user (see [Users and roles](users-and-roles.md)).
- Passwords are stored hashed; an existing password can be reset but never read back.
- Always sign out on shared or public machines.

## Least privilege
- Give each person only the roles they need, and scope them to the sites they work on. Site-scoped roles mean an editor for one site does not automatically gain access to others. See [Users and roles](users-and-roles.md).
- Review roles when someone changes responsibilities, and remove roles or delete the account when they leave.

## Two-factor authentication (2FA)
Capell's admin does not ship a built-in 2FA setting. Add multi-factor authentication at the application layer — for example a Laravel/Filament MFA package or your identity provider / SSO — in the host app that mounts the admin panel. Ask your developer to enable it.

## Emergency access (Lockdown)
If you suspect a compromise or need to take the public site offline fast, use **Lockdown**. Configure break-glass recovery users ahead of time — ask your developer to set `CAPELL_LOCKDOWN_USER_IDS` or `CAPELL_LOCKDOWN_EMAILS` in the environment before an incident occurs. See [Lockdown](../operations/lockdown.md).

## Keep the platform patched
Apply Capell updates promptly; security fixes ship on the current line. See [Upgrading](../operations/upgrading.md). If you discover a potential vulnerability, contact your developer — do not open a public issue.
