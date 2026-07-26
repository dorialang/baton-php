# Baton Architecture

Baton is Doria's project manager and toolchain driver. Its architecture keeps project orchestration separate from the compiler so the command-line experience can evolve without duplicating language behavior.

## Ownership boundary

| Concern | Owner |
| --- | --- |
| Doria syntax, types, semantics, diagnostics, and code generation | `dorialang/doria` |
| Compiler component builds and machine-readable compiler identity | `dorialang/doria` |
| Project discovery, manifests, templates, build layout, and command workflow | `dorialang/baton-php` |
| Compiler discovery, validation, and process invocation | `dorialang/baton-php` |
| Private runtime packaging and complete toolchain archives | `dorialang/baton-php` |
| Language server, syntax highlighting, and editor integrations | `dorialang/doria-language-server` |

Baton never parses Doria source or attempts to reproduce a compiler diagnostic. It selects a compatible `doriac`, constructs an argument vector, and forwards the process result.

## Bootstrap implementation strategy

The PHP implementation is intentionally lean and disposable. Its purpose is to improve the Doria development workflow now and gather concrete feedback about Baton's commands, diagnostics, paths, and project conventions before the durable implementation is written in Doria.

Symfony Console provides the command structure. Bootstrap features should normally be implemented directly in those commands, with integration tests preserving the useful experience. PHP abstraction layers are added only when they remove real duplication or enforce a security boundary; they are not a rehearsal for the Doria-native internal architecture.

## Command flow

```text
CLI command
    │
    ├── ProjectLocator ── finds Baton.toml
    │
    ├── ManifestLoader ── validates project metadata
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
| `Manifest` | Load and validate `Baton.toml` |
| `Toolchain` | Discover components and verify identity, target, and hashes |
| `Compiler` | Start `doriac` safely and preserve process behavior |
| `Templates` | Generate Baton-owned project scaffolds |
| `Diagnostics` | Render Baton-specific errors consistently |

The compiler boundary is narrow by design. Machine-readable responses must carry an explicit schema, and incompatible schema or toolchain versions fail before a project command proceeds.

## Repository independence

The Baton repository builds and tests without a compiler repository checkout. Integration tests consume an explicit compiler artifact, a pinned released component, or a CI artifact identified by immutable metadata.

No workflow may infer a compiler from a sibling directory. Local contributors supply an absolute compiler path or explicitly enable development discovery.

## Distribution architecture

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
├── share/
│   └── doria/
│       └── templates/
├── toolchain.json
├── LICENSE
└── LICENSES/
```

The public launcher resolves the private PHP runtime relative to the installed toolchain. It does not load a system PHP installation, user `php.ini`, or system extensions.

`toolchain.json` records the exact compatible components and their hashes. It is internal installation metadata and is unrelated to project dependency resolution.

## Migration constraint

The PHP implementation is a bootstrap, not part of the public project contract. A later Doria-native implementation may replace its internals while preserving:

- `Baton.toml` and `Baton.lock`;
- command names and argument behavior;
- exit-code and diagnostic conventions;
- build-directory layout;
- package SemVer and toolchain CalVer;
- compiler and toolchain manifest schemas.

Project users must not need to migrate because Baton's implementation language changes.
