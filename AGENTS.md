# Baton Agent Guide

This file contains repository-specific instructions for coding agents. Human contributor setup lives in [CONTRIBUTING.md](CONTRIBUTING.md).

## Authority and scope

1. Read [docs/baton-php-development-plan.md](docs/baton-php-development-plan.md) before changing commands, manifests, distribution layout, release behavior, or repository ownership.
2. Baton owns project workflow, compiler/toolchain discovery, process orchestration, templates, packaging, and final toolchain assembly.
3. `dorialang/doria` owns language syntax and semantics, compiler diagnostics, code generation, and compiler component artifacts.
4. `dorialang/doria-language-server` owns `doria-lsp`, syntax highlighting, and editor integrations.
5. Do not duplicate compiler semantics in PHP or couple Baton to compiler source-tree internals.

## Public documentation

- Describe the completed product experience in public README and user documentation.
- Keep temporary milestone status, unsupported-feature caveats, and agent-facing rationale out of public product copy.
- Never assume repositories are sibling directories or that contributors share a workspace layout.
- Use explicit placeholders such as `/absolute/path/to/doriac` when a local artifact is required.
- Keep PHP and Composer in contributor documentation only; users receive an assembled toolchain with a private runtime.

## Durable contracts

- Toolchain components use zero-padded CalVer; project packages use SemVer.
- `Baton.toml`, command names, exit behavior, diagnostics, build layout, and installed layout are migration-sensitive public contracts.
- The compiler discovery order is: explicit `--compiler`, installed `toolchain.json`, compiler beside Baton, `BATON_DORIAC` in explicit development mode, then `PATH` in explicit development mode.
- A random compiler on `PATH` must never override the installed compiler in public mode.
- `toolchain.json` is internal distribution metadata, not a project manifest or lockfile.

## Implementation guardrails

- Invoke `doriac` only through `CompilerAdapter`.
- Use argument vectors, never interpolated shell command strings.
- Preserve standard streams, signals where supported, and exact child exit codes.
- Validate component identities, versions, target triples, containment, and hashes before execution.
- Treat manifests as inert data. Do not execute project PHP, plugins, or build scripts.
- Design and test paths for spaces, Unicode, Windows separators, relocation, symlinks, and read-only toolchain roots.
- Preserve unrelated worktree changes and do not commit or publish unless asked.

## Required validation

For PHP or documentation changes, run:

```bash
composer validate --strict --no-check-publish
composer check
git diff --check
```

Also exercise the affected command with a matching explicit compiler artifact when behavior crosses the compiler boundary. Distribution changes require archive-level clean-machine tests on every supported platform.
