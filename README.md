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

A Doria toolchain distribution contains Baton, `doriac`, `doria-lsp`, and the private runtime Baton needs. Using it does not require Rust, Cargo, PHP, Composer, or a repository checkout.

Run `baton doctor` after installation to verify the toolchain components and their versions.

## Commands

| Command | Purpose |
| --- | --- |
| `baton new <name>` | Create a Doria binary project |
| `baton check` | Type-check the current project without producing a binary |
| `baton build` | Build the current project using the development profile |
| `baton build --release` | Build an optimized release artifact |
| `baton run -- [args...]` | Build and run the project, forwarding program arguments |
| `baton doctor` | Report and verify the installed toolchain |
| `baton version` | Print Baton and compiler versions |

Baton searches the current directory and its parents for `Baton.toml`, so project commands also work from a subdirectory.

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
manifest-version = 1

[package]
name = "hello-doria"
version = "0.1.0"
kind = "binary"
entry = "src/main.doria"
```

Package versions use SemVer. Doria toolchain releases use CalVer, such as `2026.03.1` or `2026.03.1-canary`; the two version domains are independent.

Build artifacts are written beneath `build/<host-target>/<profile>/`. Baton never uses Cargo's `target/` convention for Doria project output.

See [Project manifest](docs/project-manifest.md) for the field contract and path rules.

## Toolchain integrity

Baton prefers the compiler recorded in the installed `toolchain.json`, then a compiler shipped beside Baton. It does not silently substitute an unrelated `doriac` from `PATH`.

Before invoking the compiler, Baton verifies its machine-readable identity, toolchain version, platform, architecture, and—when selected through the installed manifest—its SHA-256 digest. Compiler diagnostics and exit codes pass through unchanged.

See [Toolchain discovery and validation](docs/toolchain.md) for the complete selection order and development overrides.

## Repository boundaries

This repository owns:

- the Baton command-line experience;
- `Baton.toml` loading and project discovery;
- project templates and build layout;
- compiler discovery and safe process invocation;
- private runtime packaging;
- final Doria toolchain assembly and distribution tests.

The [`dorialang/doria`](https://github.com/dorialang/doria) repository owns the language, compiler, and compiler component artifacts. The [`dorialang/doria-language-server`](https://github.com/dorialang/doria-language-server) repository owns `doria-lsp` and editor integrations.

Read [Architecture](docs/architecture.md) for the component and ownership model.

## Documentation

- [Project manifest](docs/project-manifest.md)
- [Toolchain discovery and validation](docs/toolchain.md)
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
