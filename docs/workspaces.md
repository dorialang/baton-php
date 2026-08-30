# Workspaces

A schema-2 workspace places one strict `Baton.lock` at its root and keeps every
member's package identity, manifest, targets, dependencies, autoload mappings,
and `internal` boundary independent.

## Manifest

A virtual root contains only workspace configuration:

```toml
manifest-version = 2

[workspace]
members = ["apps/*", "packages/**"]
```

A package-bearing root may also declare `[package]`, targets, and autoload
mappings. That root is the implicit `.` member. `members` is always present; an
empty list is valid only when the root package is the sole member.

Patterns are workspace-relative and use only `*`, `?`, and `**`. Baton selects
directories, requires `Baton.toml` in each result, checks exact case and
canonical containment, rejects symlink escapes and ambiguous multiple matches,
and orders members by normalized binary path spelling. Schema-1 members are not
accepted.

Packages may be nested at any directory depth. Composable nested workspace
roots are deferred because lock authority, member visibility, command
selection, and graph composition require a later decision; they are not a
permanent language restriction.

## Selection

Use `--package <manifest-name>` for one member and `--workspace` for aggregate
commands such as `check`, `test`, `tree`, and `project`. From a member directory,
that member is selected automatically. `run` and `build` remain package-specific.

Workspace artifacts use:

```text
build/<host>/<profile>/<compiler-package>/<target>/
```

Compiler package identity is encoded as contained path segments, preventing
member and target collisions.

## Lock

Workspaces use strict lock schema 2. It records every member, exact workspace
path, manifest fingerprint, normal/development/processor edge, and external
source. Member-level locks conflict with workspace lock authority. Standalone
projects continue to use lock schema 1 unchanged.
