# Contributing to Baton

Thank you for helping improve Doria's project and toolchain experience.

## Before you begin

Read the repository's [development plan](docs/baton-php-development-plan.md) and [architecture](docs/architecture.md) before changing a public command, manifest field, compiler contract, installed layout, or release artifact. Stage 33 freezes these surfaces here as an exercised UX contract; the mandatory Pre-Stage-45 transition parity-ports them to the Doria-native Baton implementation.

Changes to Doria syntax, parsing, type checking, diagnostics, or code generation belong in [`dorialang/doria`](https://github.com/dorialang/doria). Editor and language-server changes belong in [`dorialang/doria-language-server`](https://github.com/dorialang/doria-language-server).

## Development requirements

- PHP 8.4 or newer within the supported 8.x line
- Composer 2
- Git
- A compatible `doriac` artifact only for real-compiler integration work

The Baton repository can be cloned and tested independently. Do not assume that a Doria compiler checkout exists beside it—or anywhere else on the machine.

## Set up a source checkout

Clone Baton wherever you keep projects:

```bash
git clone git@github.com:dorialang/baton-php.git
cd baton-php
composer install
```

Run the complete local validation suite:

```bash
composer validate --strict --no-check-publish
composer check
```

`composer check` runs PHPUnit and PHPStan at the maximum configured level.

Runtime and PHAR packaging has additional contributor commands:

```bash
composer runtime:plan
composer build:phar
```

See [Private Baton runtime](docs/runtime.md) for the pinned inputs, native build requirements, isolation checks, generated inventories, and update procedure.

## Use a compiler during development

Commands that invoke `doriac` accept an explicit compiler artifact:

```bash
php bin/baton check --compiler /absolute/path/to/doriac
php bin/baton doctor --compiler /absolute/path/to/doriac
```

The selected compiler must report the same Doria toolchain CalVer and host target as Baton. An arbitrary sibling checkout is neither required nor inferred.

For repeated local work, the PHP bootstrap automatically uses
`BATON_DORIAC` and then `PATH` after checking the explicit and installed
toolchain sources:

```bash
BATON_DORIAC=/absolute/path/to/doriac php bin/baton check
php bin/baton run
```

Installed toolchain metadata and a compiler beside Baton still take precedence.
The Doria-native Baton owns final public distribution policy after the
Pre-Stage-45 cutover. Bootstrap prereleases may still exercise the PHP
distribution, but the unsuffixed `2026.03.1` release may not ship it.

## Design and implementation rules

- Treat the PHP bootstrap as a lean developer-experience prototype. Prefer direct Symfony commands and concrete integration tests over abstractions intended for long-term reuse.
- The durable Baton implementation will be written in Doria. Do not pre-build its internal architecture in PHP.
- Express every Stage 33 behavior in implementation-neutral fixtures suitable for the shared PHP/Doria transition suite; a PHP-only test is insufficient when it defines a public contract.
- Keep Baton semantic-free. It coordinates projects and invokes `doriac`; it does not parse or reinterpret Doria source.
- Route every compiler process through `CompilerAdapter`.
- Pass process arguments as arrays. Never construct a shell command with interpolated paths or user input.
- Preserve compiler standard streams and exit codes.
- Keep project entry paths contained within the project root.
- Treat `Baton.toml` and `toolchain.json` as data, never executable configuration.
- Keep public mode deterministic: bundled, versioned components take precedence over machine-global tools.
- Make paths work with spaces, Unicode, Windows separators, relocation, and symlinks.
- Do not introduce source-tree coupling between Doria repositories.

## Tests

Add the smallest test layer that proves the behavior:

- Unit tests for parsing, validation, selection, path handling, and diagnostics.
- Fake-compiler integration tests for exact arguments, streams, exit codes, and malformed machine output.
- Real-compiler integration tests against an exact released component artifact.
- Distribution tests against extracted archives, not source-tree entry points.
- Runtime tests with a hostile PHP first on `PATH` and hostile ini environment variables.

Cross-platform process or filesystem behavior must be exercised on Linux, macOS, and Windows.

## Documentation

Update the durable documentation in the same change as a public contract:

- command and user workflow changes belong in [README.md](README.md);
- manifest changes belong in [docs/project-manifest.md](docs/project-manifest.md);
- discovery or installed-layout changes belong in [docs/toolchain.md](docs/toolchain.md);
- ownership and component-boundary changes belong in [docs/architecture.md](docs/architecture.md);
- release changes belong in [docs/releasing.md](docs/releasing.md).

Public documentation describes the completed Doria experience. Milestone status, temporary implementation caveats, and agent coordination notes belong in the development plan, issues, or [AGENTS.md](AGENTS.md), not in user-facing product copy.

Examples must not assume a particular workspace layout.

## Versioning

Doria toolchains and their public components use zero-padded CalVer:

```text
yyyy.mm.n-canary
yyyy.mm.n-rc
yyyy.mm.n
```

Packages declared in `Baton.toml` use SemVer. Never compare or substitute one version domain for the other.

## Pull-request checklist

- The change stays within Baton ownership.
- User input and filesystem paths are not passed through a shell string.
- Tests cover success, failure, and relevant cross-platform cases.
- `composer validate --strict --no-check-publish` passes.
- `composer check` passes.
- Documentation and examples match the public contract.
- No example assumes another Doria repository is in a sibling directory.
- No generated dependency or build output is committed.
