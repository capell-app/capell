# Extension surface catalogue

Generated from executable metadata by `scripts/build-extension-surface-catalog.php`. JSON is the machine-readable source; this page is the human index.

| Stable ID                                        | Kind           | Identifier                                                            | Owner              | Stability    | Summary                                                             |
| ------------------------------------------------ | -------------- | --------------------------------------------------------------------- | ------------------ | ------------ | ------------------------------------------------------------------- |
| `admin.render-hook.navigation-after`             | render-hook    | `panels::sidebar.nav.end`                                             | `capell-app/admin` | experimental | Admin navigation contribution hook.                                 |
| `core.config.roles-admin`                        | config         | `capell.roles.admin`                                                  | `capell-app/core`  | experimental | Configured administrator role name.                                 |
| `core.contract.extension-contribution`           | contract       | `Capell\Core\Contracts\Extensions\ExtensionContribution`              | `capell-app/core`  | stable       | Core contribution boundary.                                         |
| `core.contract.health-check`                     | contract       | `Capell\Core\Contracts\Extensions\ChecksExtensionHealth`              | `capell-app/core`  | experimental | Typed extension health checks.                                      |
| `core.contract.project-build-artifact-handler`   | contract       | `Capell\Core\Contracts\ProjectBuild\ProjectBuildArtifactHandler`      | `capell-app/core`  | stable       | Package-owned project artifact verification boundary.               |
| `core.contract.project-build-manifest-migration` | contract       | `Capell\Core\Contracts\ProjectBuild\ProjectBuildManifestMigration`    | `capell-app/core`  | experimental | Explicit forward migration boundary for portable project manifests. |
| `core.contract.site-spec-applier`                | contract       | `Capell\Core\Contracts\SiteSpec\SiteSpecApplier`                      | `capell-app/core`  | stable       | Package-owned SiteSpec application boundary.                        |
| `core.dto.extension-contribution`                | dto            | `Capell\Core\Data\Manifest\ExtensionContributionData`                 | `capell-app/core`  | stable       | Typed manifest contribution data.                                   |
| `core.dto.project-build-manifest`                | dto            | `Capell\Core\Data\ProjectBuild\ProjectBuildManifestData`              | `capell-app/core`  | experimental | Typed portable project build manifest envelope.                     |
| `core.event.package-installed`                   | event          | `Capell\Core\Events\PackageInstalled`                                 | `capell-app/core`  | stable       | Package lifecycle completion event.                                 |
| `core.facade.capell-core`                        | facade         | `Capell\Core\Facades\CapellCore`                                      | `capell-app/core`  | experimental | Runtime package and model registry facade.                          |
| `core.internal.registry-builder`                 | internal       | `Capell\Core\Actions\Extensions\BuildExtensionContractRegistryAction` | `capell-app/core`  | internal     | Internal executable contribution index.                             |
| `core.schema.project-build-manifest-v1`          | schema         | `Capell\Core\Support\ProjectBuild\ProjectBuildManifestSchema`         | `capell-app/core`  | experimental | Closed JSON Schema for portable project build manifests.            |
| `core.tag.extension-health`                      | tagged-service | `capell.extension-health-checks`                                      | `capell-app/core`  | experimental | Container tag for extension health checks.                          |
| `core.tag.project-build-artifact-handler`        | tagged-service | `capell.project-build.artifact-handler`                               | `capell-app/core`  | stable       | Container tag for project build artifact handlers.                  |
| `core.tag.project-build-manifest-migration`      | tagged-service | `capell.project-build.manifest-migration`                             | `capell-app/core`  | experimental | Container tag for project build manifest migrations.                |
| `core.tag.site-spec-applier`                     | tagged-service | `capell.site-spec.applier`                                            | `capell-app/core`  | stable       | Container tag for SiteSpec appliers.                                |
| `core.testing.extension-harness`                 | testing        | `Capell\Core\Testing\ExtensionTestHarness`                            | `capell-app/core`  | stable       | Single-package manifest and contribution assertions.                |

Stable entries have a direct contract test ID in the JSON catalogue. Experimental entries may change before the first public release. Internal entries are not extension APIs.

Compatibility enforcement is prepared in `stable-extension-api-baseline.json`. Its status remains `pending-first-public-release` until the first public tag; after activation, drift requires an explicit compatibility decision and is never silently reformatted away.
