<div align="center">
  <img src="res/images/doria-app-icon-warm.svg" alt="Doria Logo" width="200" height="200">

# Baton

**The project manager and toolchain driver for the Doria programming language.**

</div>

---

Baton gives Doria projects one consistent command-line workflow. It creates projects, validates manifests, selects the compiler shipped with the active Doria toolchain, and coordinates checking, building, and running programs without taking ownership of language semantics.

## Quick start

Install the [Doria toolchain for your platform](https://github.com/dorialang/baton-php/releases), then create and run a project:

```bash
baton new hello-doria
cd hello-doria
baton run
```

A PHP-bootstrap prerelease contains Baton, `doriac`, `doria-lsp`, and Baton's
isolated private runtime. After the mandatory native cutover, production
toolchains contain the Doria-native Baton executable, `doriac`, and `doria-lsp`
with no Baton PHP payload. Neither layout requires Rust, Cargo, system PHP,
Composer, or a repository checkout.

Run `baton doctor` after installation to verify component versions and hashes,
host compatibility, and writable project/cache locations. Bootstrap prereleases
also verify their private runtime.

## Commands

| Command | Purpose |
| --- | --- |
| `baton new <name>` | Create a Doria binary project |
| `baton check` | Type-check the current project without producing a binary |
| `baton build` | Build the current project using the development profile |
| `baton build --release` | Build an optimized release artifact |
| `baton run -- [args...]` | Build and run the project, forwarding program arguments |
| `--binary <name>` | Select a declared binary for `check`, `build`, or `run` |
| `--library` | Select the declared library for `check` or source-only `build` |
| `baton doctor` | Report and verify the installed toolchain |
| `baton version` | Print Baton and compiler versions |

Baton searches the current directory and its parents for `Baton.toml`, so project commands also work from a subdirectory.

Use `baton run --release` to run the release profile. Everything after `--` is passed to the program unchanged:

```bash
baton run -- --port 8080 "two words"
```

A normal run displays only the program's output. Use `-v`, `-vv`, or `-vvv` to include compiler and build details; build failures are always shown.

## Project layout

`baton new hello-doria` creates:

```text
hello-doria/
├── Baton.toml
└── src/
    └── main.doria
```

The project manifest is deliberately small:

```toml
manifest-version = 2

[package]
name = "hello-doria"
version = "0.1.0"
edition = "2026"
publishable = false
kind = "binary"
entry = "src/main.doria"

[autoload.namespaces]
"" = "src/"
```

Package versions use SemVer. Doria toolchain releases use CalVer, such as `2026.03.1` or `2026.03.1-canary`; the two version domains are independent.

Schema-2 build artifacts are written beneath
`build/<host-target>/<profile>/<target-name>/`. Baton never uses Cargo's
`target/` convention for Doria project output. Existing schema-1 projects keep
their historical non-target-scoped layout.

Each profile directory also contains `build.json`, recording the package, package version, toolchain, target, and profile that produced the artifact.

See [Project manifest](docs/project-manifest.md) for the field contract and path rules.

## Package-system contract

Stage 33 Slice 1 implements strict manifest schema 2, compile-time autoload
discovery, main and development source inventories, explicit package targets,
target selection, compiler build-plan generation, and target-scoped receipts.
Schema 1 remains exactly compatible. Dependency tables, `Baton.lock`, package
fetching, and resolver commands remain stage-gated for Slice 2; workspaces,
tests, and processors remain stage-gated for Slice 3.

Stage 33 implements and exercises that complete product contract in this
disposable bootstrap. It does not make PHP Baton's permanent implementation.
After the required Doria tooling foundations land, the mandatory Pre-Stage-45
transition parity-ports the frozen behavior to the clean `dorialang/baton`
repository. The unsuffixed `2026.03.1` release is blocked until production
toolchains use the native Baton executable and carry no Baton PHP runtime.

See [Phase F package and dependency model](docs/phase-f-package-and-dependency-model.md)
for the complete target contract and its current-state boundary.

## Toolchain integrity

Baton prefers the compiler recorded in the installed `toolchain.json`, then a compiler shipped beside Baton. It does not silently substitute an unrelated `doriac` from `PATH`.

Before invoking the compiler, Baton verifies its machine-readable identity, toolchain version, platform, architecture, and—when selected through the installed manifest—its SHA-256 digest. Compiler diagnostics and exit codes pass through unchanged.

See [Toolchain discovery and validation](docs/toolchain.md) for the complete selection order and development overrides.

## Repository boundaries

Until the native cutover, this repository owns:

- the Baton command-line experience;
- `Baton.toml` loading and project discovery;
- project templates and build layout;
- compiler discovery and safe process invocation;
- private runtime packaging;
- prerelease Doria toolchain assembly and distribution tests.

The [`dorialang/doria`](https://github.com/dorialang/doria) repository owns the language, compiler, and compiler component artifacts. The [`dorialang/doria-language-server`](https://github.com/dorialang/doria-language-server) repository owns `doria-lsp` and editor integrations.

The clean `dorialang/baton` repository will own the production Doria-native
Baton, templates, and complete toolchain releases after the parity-gated
Pre-Stage-45 transition. This repository then freezes as historical bootstrap
and compatibility reference.

Read [Architecture](docs/architecture.md) for the component and ownership model.

## Documentation

- [Project manifest](docs/project-manifest.md)
- [Package targets](docs/targets.md)
- [Source discovery](docs/source-discovery.md)
- [Compiler build plans](docs/build-plan.md)
- [Phase F package and dependency model](docs/phase-f-package-and-dependency-model.md)
- [Toolchain discovery and validation](docs/toolchain.md)
- [Private Baton runtime](docs/runtime.md)
- [Architecture](docs/architecture.md)
- [Release process](docs/releasing.md)
- [Development plan](docs/baton-php-development-plan.md)
- [Changelog](CHANGELOG.md)
- [Contributing](CONTRIBUTING.md)
- [Security policy](SECURITY.md)

## Contributing

Baton's source-development workflow uses PHP 8.4 and Composer 2. These are contributor requirements only; they are not user installation requirements. See [CONTRIBUTING.md](CONTRIBUTING.md) for setup, validation, and compiler-integration guidance.

## License

MIT. See [LICENSE](LICENSE).
