# Security Policy

## Supported versions

Security updates are provided for the latest stable Doria toolchain release. Canary and release-candidate builds are pre-release channels and may be replaced by a newer pre-release rather than patched in place.

## Report a vulnerability

Please do not open a public issue for a suspected vulnerability.

Use [GitHub's private vulnerability reporting](https://github.com/dorialang/baton-php/security/advisories/new) and include:

- the affected Baton or Doria toolchain version;
- the operating system and architecture;
- a minimal reproduction or proof of concept;
- the security impact;
- any known workarounds;
- whether the issue has been disclosed elsewhere.

Do not include credentials, private keys, access tokens, or unrelated personal data. The maintainers will acknowledge the report through the private advisory, investigate it, and coordinate disclosure and release information there.

## Security boundaries

Baton is designed around these boundaries:

- compiler processes are invoked with argument vectors, never shell-interpolated commands;
- the compiler bundled with the selected toolchain takes precedence over `PATH`;
- development overrides require explicit opt-in;
- component paths must remain inside the toolchain root;
- installed component identities and SHA-256 hashes are verified;
- project entry paths must remain inside the project root;
- autoload roots and every discovered source must remain inside the project
  root after symlink resolution;
- source paths must use exact filesystem case and remain collision-free under
  portable ASCII case folding;
- managed build paths are checked component by component before writes, so an
  intermediate symlink cannot redirect plans, receipts, or artifacts;
- project manifests are data and do not execute code;
- PHP-bootstrap prereleases use an isolated private PHP runtime without user
  configuration or system extensions; production distributions remove that
  runtime at the mandatory Doria-native cutover;
- Baton does not execute PHP supplied by a Doria project;
- diagnostic commands must not expose secrets.

Changes that weaken one of these boundaries require explicit security review and regression coverage.

## Accepted package-system threat model

The package resolver preserves the same inert-data boundary. Dependency sources
are explicit and exactly locked; remote lock entries keep
canonical source URLs but never credentials, tokens, or secret query
parameters. Conflicting sources for one package identity are diagnosed.
Arbitrary archive URLs and implicit build scripts are rejected. Processors are
explicit, source-locked, visible in build output, and write beneath the build
directory without modifying handwritten source by default.

Offline mode never reaches the network or substitutes another version. The
global dependency cache is content-addressed by exact source identity and
integrity facts. Git runs non-interactively with isolated configuration,
disabled hooks and filters, no submodules, sanitized failures, immutable exact
checkouts, and symlink-contained cache writes. Baton resolves manifests and
source inventories but never executes dependency source. Processors remain
deferred to Slice 3.
