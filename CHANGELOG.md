# Changelog

All notable changes to Baton are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Doria toolchain releases use CalVer; package versions inside `Baton.toml` use SemVer.

## [Unreleased]

### Added

- Strict manifest schema 2 support with typed local and scoped package identity,
  SemVer validation, the 2026 edition, publishability rules, shorthand and
  explicit package targets, and unambiguous `--binary`/`--library` selection.
- Compile-time main and development autoload discovery with simple or advanced
  namespace mappings, deterministic include/exclude matching, exact-case and
  symlink containment checks, and stable source identities.
- Compiler build-plan schema 1 emission for schema-2 projects, target-scoped
  build layouts, deterministic build receipts, and source-only library builds.
- An internal validated generated-source boundary reserved for Stage 33 Slice 3.
- The Baton command boundary and Doria-style diagnostics.
- Project creation with a versioned `Baton.toml` and accepted Doria template.
- Upward project discovery and `baton check`.
- Deterministic development and release builds through `baton build`.
- Build-and-run orchestration with program stream, argument, and exit-code forwarding.
- Full toolchain health reporting through `baton doctor`, including language-server integrity and writable-location checks.
- A pinned, statically linked private PHP runtime for every supported toolchain target.
- Relative POSIX and Windows launchers that disable ini loading and never fall back to system PHP.
- Production PHAR assembly with SHA-256 signatures and generated dependency and licence inventories.
- Safe compiler invocation with argument vectors and exit-code forwarding.
- Deterministic compiler discovery, machine-readable identity checks, host validation, and installed-component hash verification.
- `baton doctor` and `baton version` toolchain reporting.
- Cross-platform PHPUnit, PHPStan, and CI coverage.
- Normal path and Git dependencies with strict SemVer constraints, one-version
  graph resolution, source-substitution and cycle diagnostics.
- Deterministic `Baton.lock`, exact Git pinning, a global content-addressed cache,
  centralized offline operation, and transactional `install`, `add`, `remove`,
  `update`, and `fetch` workflows.
- Multi-package compiler plans and lock/path identity in build receipts.

### Changed

- `baton new` now creates a schema-2 local, non-publishable binary project.
- Existing schema-1 projects retain their exact manifest interpretation,
  compiler invocation, command behavior, and build layout.

### Deferred

- Workspaces, development dependencies, tests, processors, graph commands, and
  generated-source writes remain in Stage 33 Slice 3.
