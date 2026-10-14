## Verification Operating Pattern

Use affected/diff checks while iterating, then run one full PHPStan lane at a time
across App, Core, and companion packages. Keep `maximumNumberOfProcesses: 4`;
parallelism is part of the configured contract and must not be reduced to hide a
timeout, race, or memory failure. Keep result caches warm but branch-isolated,
and clear or prime only the exact cache whose path provenance is known.

The shared PHPStan configuration must define an explicit child-process timeout.
A timeout is an incomplete harness result, not a green or a type error; rerun
with the configured bound and preserve the first diagnostic. Server-backed Pest
shards must use distinct generated databases (including sequential fallback),
while static checks remain database-free. Run `preflight:all` only after focused
gates pass and competing lanes are idle; classify setup, tooling, isolation,
source, and hosted failures separately rather than skipping or baselining them.

**`preflight:all`, `test:all:matrix:local`, and any other whole-tree gate go through
the orchestrator agent, not an arbitrary session** — a focused/diff-scoped run is
always fine to run yourself, but two full runs against the same checkout at once
corrupts shared state and OOM-kills the host. Ask the orchestrator for a full-suite
result rather than running one yourself.
