# Marketplace Hosting

Capell evaluates the host before a Marketplace package operation starts. The result is shown in the install review and by `php artisan capell:doctor`.

## Capability Tiers

| Tier | Meaning |
| --- | --- |
| `Automated` | This node can run Composer and package lifecycle work locally. |
| `AutomatedViaDeployPublisher` | The release is immutable by design and a registered deployment publisher can apply the Composer change in the build pipeline. |
| `ManualOnly` | The catalogue and review remain available, but an operator must apply the documented Composer and lifecycle commands. |
| `Blocked` | A host declared capable of automation is misconfigured, or a required multi-node/queue invariant is unsafe. Fix the reported check before installing. |

## Hosting Matrix

| Host shape | Expected tier | Required setup | Remediation |
| --- | --- | --- | --- |
| Writable VPS | `Automated` | PHP and Composer binaries, `proc_open`, writable release root, Marketplace queue worker | Run Doctor, then fix the failed check below. |
| Shared hosting | `Automated` or `ManualOnly` | Writable release plus process execution for automation; otherwise use the manual commands | Do not try to bypass a disabled process API. Use SSH/host tooling or deploy a built release. |
| Docker | `Automated` | PHP and Composer in the application image, writable mounted release, persistent worker | Keep web and worker containers on the same release and shared cache. Rebuild the image for immutable containers. |
| Laravel Octane | `Automated` | Normal automated requirements plus runtime refresh and worker restart | Run `php artisan capell:runtime-refresh`, then restart Octane after package changes. |
| Atomic symlink release | `AutomatedViaDeployPublisher` or `ManualOnly` | Immutable current release, deployment publisher or build-pipeline Composer change | Publish the change into a new release. Never mutate the live symlink target. |
| Read-only serverless | `AutomatedViaDeployPublisher` or `ManualOnly` | Deployment publisher/build pipeline and external queue/cache services | Install packages during build and deploy the resulting artifact. |
| Windows | Development: `Automated` where checks pass. Production: best-effort. | Resolvable PHP/Composer executables, process execution, writable paths, worker | Windows is supported for development and best-effort for production. No end-to-end Windows Marketplace lifecycle run has been performed. |
| No worker | `Automated` with a warning until work proves active, or `Blocked` when the timeout chain is unsafe | A worker for the configured connection and `capell-marketplace` queue | Start the exact command shown by readiness and keep it supervised. |

`capell.multi_node` is declared through `CAPELL_MULTI_NODE`; Capell does not detect topology. Set it to `true` on every node in a multi-node installation.

## Readiness Remediation

### Process execution

<a id="process-execution"></a>

`proc_open` must be available for local automated package operations. Remove it from `disable_functions`, or deliberately use a deployment publisher/manual install path. Readiness reports this limitation before the operator confirms an install.

### PHP binary

<a id="php-binary"></a>

Make the PHP CLI executable available to the web and worker users. Set the configured Capell PHP binary to an absolute executable path when PATH differs between services.

### Composer binary

<a id="composer-binary"></a>

Install Composer for the web/worker runtime or configure its absolute path. A missing Composer binary keeps manual instructions available but prevents local automation.

### Release root writable

<a id="release-root-writable"></a>

Local automation needs write access to `composer.json`, `composer.lock`, and `vendor/`. On immutable or atomic-symlink deployments, register a deployment publisher or apply the recorded Composer command during the build. Do not grant write access to a live release merely to silence the check.

With `CAPELL_SERVER_SIDE_TOOLING` unset or false, deleting an extension package from the admin panel fails with the server-side tooling message. This is deliberate: package removal changes the release and must happen in the deployment pipeline or on a host explicitly declared capable of server-side tooling.

### Queue worker

<a id="queue-worker"></a>

Marketplace operations use their configured connection and named queue. Run the exact supervised command shown by readiness; the default is equivalent to:

```bash
php artisan queue:work --queue=capell-marketplace
```

A fresh recorded heartbeat is evidence that a worker has processed Marketplace work. A missing heartbeat is a warning rather than proof that no worker exists.

### Shared cache

<a id="shared-cache"></a>

When `CAPELL_MULTI_NODE=true`, use a shared cache such as Redis. File, array, and other node-local stores cannot safely coordinate operation locks or runtime state across nodes.

### Timeout chain

<a id="timeout-chain"></a>

The queue connection's `retry_after` must exceed the Marketplace job timeout, and the supervising worker timeout must leave enough time for the job to fail cleanly. Do not fix request timeouts by making browser requests wait longer; package work belongs on the queue.

### Deployment publisher

<a id="deploy-publisher"></a>

A deployment publisher converts the Composer change into a deployment-system commit or pull request. Confirm the publisher is registered, returns a durable reference, and builds a new release. Publisher failure remains visible in Package Operations and never counts as a successful deployment.

## After A Package Change

Run `php artisan capell:runtime-refresh`. Restart PHP-FPM or Octane so every process boots the new package registry. On a declared multi-node installation, repeat the refresh and restart on every node; Capell cannot infer or reach the other nodes for you.

Continue with [Debugging Marketplace](debugging-marketplace.md) when an operation is queued, partial, cancelled, or failed.
