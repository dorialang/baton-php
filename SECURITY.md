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
- project manifests are data and do not execute code;
- public distributions use an isolated private PHP runtime without user configuration or system extensions;
- Baton does not execute PHP supplied by a Doria project;
- diagnostic commands must not expose secrets.

Changes that weaken one of these boundaries require explicit security review and regression coverage.
