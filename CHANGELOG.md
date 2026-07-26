# Changelog

All notable changes to Baton are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Doria toolchain releases use CalVer; package versions inside `Baton.toml` use SemVer.

## [Unreleased]

### Added

- The Baton command boundary and Doria-style diagnostics.
- Project creation with a versioned `Baton.toml` and accepted Doria template.
- Upward project discovery and `baton check`.
- Safe compiler invocation with argument vectors and exit-code forwarding.
- Deterministic compiler discovery, machine-readable identity checks, host validation, and installed-component hash verification.
- `baton doctor` and `baton version` toolchain reporting.
- Cross-platform PHPUnit, PHPStan, and CI coverage.
