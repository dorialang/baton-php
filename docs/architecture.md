# Baton Architecture

Baton is Doria's project manager and toolchain driver. Its architecture keeps project orchestration separate from the compiler so the command-line experience can evolve without duplicating language behavior.

## Ownership boundary

| Concern | Owner |
| --- | --- |
| Doria syntax, types, semantics, diagnostics, and code generation | `dorialang/doria` |
| Compiler component builds and machine-readable compiler identity | `dorialang/doria` |
| Stage 33 UX-contract implementation, project discovery, manifests, templates, build layout, and command workflow | `dorialang/baton-php` until native cutover |
| Bootstrap compiler discovery, validation, and process invocation | `dorialang/baton-php` until native cutover |
| Bootstrap private-runtime packaging and prerelease toolchain archives | `dorialang/baton-php` until native cutover |
| Production project/package workflow, templates, native executable, and complete toolchain archives | `dorialang/baton` after the mandatory Pre-Stage-45 cutover |
| Language server, syntax highlighting, and editor integrations | `dorialang/doria-language-server` |

Baton never parses Doria source or attempts to reproduce a compiler diagnostic. It selects a compatible `doriac`, constructs an argument vector, and forwards the process result.

## Bootstrap implementation strategy

The PHP implementation is intentionally lean and disposable. Its purpose is to improve the Doria development workflow now and gather concrete feedback about Baton's commands, diagnostics, paths, and project conventions before the durable implementation is written in Doria.

Stage 33 deliberately finishes the accepted package, dependency, workspace,
test, cache, lockfile, and offline behavior in this bootstrap. That milestone
freezes an exercised observable contract; it does not promote the PHP internals
or repository into the permanent architecture. Decision 0124 requires the
Pre-Stage-45 transition to port the contract to the clean `dorialang/baton`
repository under one shared behavior suite.

Symfony Console provides the command structure. Bootstrap features should normally be implemented directly in those commands, with integration tests preserving the useful experience. PHP abstraction layers are added only when they remove real duplication or enforce a security boundary; they are not a rehearsal for the Doria-native internal architecture.

## Command flow

```text
CLI command
    │
    ├── ProjectLocator ── finds Baton.toml
    ├── ManifestLoader ── TOML parse + typed schema validation
    ├── TargetSelector ── resolves one package target
    ├── SourceDiscovery ── creates one deterministic inventory
    ├── BuildPlanBuilder ── emits compiler build-plan schema 1
    ├── BuildLayout ── owns contained target-scoped paths
    │
    ├── ToolchainLocator ── selects and verifies doriac
    │       ├── ToolchainManifest
    │       ├── CompilerIdentity
    │       └── Platform
    │
    └── CompilerAdapter ── invokes doriac without a shell
            └── stdout, stderr, and exit code return unchanged
```

Commands such as `doctor` that do not require a project skip project discovery. Commands that produce artifacts additionally calculate a deterministic build path before invoking the compiler.

## Internal modules

| Module | Responsibility |
| --- | --- |
| `Application` | Registers the CLI and declares the Baton toolchain version |
| `Commands` | Translate command-line input into orchestration steps |
| `Project` | Locate project roots |
| `Manifest` | Load and validate `Baton.toml` dependency declarations |
| `Dependency` | Resolve one-version graphs, strict locks, exact Git cache entries, and offline policy |
| `Source` | Discover contained main/development source inventories without parsing Doria |
| `Build` | Select managed paths and write compiler plans and receipts atomically |
| `Workspace` | Discover members, select packages, and preserve workspace lock authority |
| `Processor` | Filter compiler metadata, execute exact processors, and publish generated source |
| `Testing` | Validate metadata callables, compile one dispatcher, and isolate test processes |
| `Inventory` | Maintain disposable content-hashed project state |
| `Toolchain` | Discover components and verify identity, target, and hashes |
| `Compiler` | Start `doriac` safely and preserve process behavior |
| `Templates` | Generate Baton-owned project scaffolds |
| `Diagnostics` | Render Baton-specific errors consistently |

The compiler boundary is narrow by design. Machine-readable responses must carry an explicit schema, and incompatible schema or toolchain versions fail before a project command proceeds.

The accepted package-system boundary keeps that separation at project scale.
Baton discovers source through compile-time `autoload`, resolves dependencies
and workspaces, and emits a versioned JSON build plan. `doriac` indexes every
declared source and owns names, types, package visibility, MIR, and code
generation. Baton never parses Doria declarations; `doriac` never parses
`Baton.toml`, resolves package versions, or fetches sources. The build plan and
versioned build receipt are the incremental boundary.

## Repository independence

The Baton repository builds and tests without a compiler repository checkout. Integration tests consume an explicit compiler artifact, a pinned released component, or a CI artifact identified by immutable metadata.

No workflow may infer a compiler from a sibling directory. Local contributors supply an absolute compiler path or explicitly enable development discovery.

## Bootstrap distribution architecture

The assembled toolchain has this platform-specific shape:

```text
doria-toolchain-<calver>-<platform>-<architecture>/
├── bin/
│   ├── baton
│   ├── doriac
│   └── doria-lsp
├── libexec/
│   └── doria/
│       ├── baton.phar
│       └── php/
│           ├── bin/php[.exe]
│           ├── runtime.json
│           └── LICENSES/
├── share/
│   └── doria/
│       └── templates/
├── toolchain.json
├── LICENSE
└── LICENSES/
```

The public launcher resolves the private PHP runtime relative to the installed toolchain and invokes the PHAR with `-n`. It has no system-PHP fallback and does not load a user `php.ini`, system configuration directory, or shared extension. See [Private Baton runtime](runtime.md) for the pinned build and isolation contract.

`toolchain.json` records the exact compatible components and their hashes. It is internal installation metadata and is unrelated to project dependency resolution.

This layout is permitted for bootstrap prereleases only. The parity-gated
native cutover replaces it with:

```text
doria-toolchain-<calver>-<platform>-<architecture>/
├── bin/
│   ├── baton
│   ├── doriac
│   └── doria-lsp
├── share/
│   └── doria/
│       └── templates/
├── toolchain.json
├── LICENSE
└── LICENSES/
```

The production `bin/baton` is the Doria-native executable. The archive contains
no Baton PHAR, Composer dependencies, PHP launcher, or private PHP runtime.

## Migration constraint

The PHP implementation is a bootstrap, not part of the public project contract.
The mandatory Pre-Stage-45 transition replaces it with the Doria-native
implementation while preserving:

- `Baton.toml` and `Baton.lock`;
- command names and argument behavior;
- exit-code and diagnostic conventions;
- build-directory layout;
- package SemVer and toolchain CalVer;
- compiler and toolchain manifest schemas.

Project users must not need to migrate because Baton's implementation language changes.

Cutover requires shared PHP/Doria fixtures for every observable contract, native
Baton self-build/check/test/package coverage, clean-machine native-only archive
tests, and production release-ownership transfer to `dorialang/baton`.
`dorialang/baton-php` then freezes as historical and compatibility reference.
The unsuffixed `2026.03.1` toolchain may not ship before this gate passes.

The accepted target architecture is detailed in [Phase F package and dependency
model](phase-f-package-and-dependency-model.md). Stage 33 and Phase F are
complete in this bootstrap while exact schema-1 behavior remains intact. The
next language stage is Stage 34; the mandatory Pre-Stage-45 native Baton
transition remains scheduled and blocks the unsuffixed release.
