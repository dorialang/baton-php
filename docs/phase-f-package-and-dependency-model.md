# Phase F Package And Dependency Model

This document records the accepted target contract for Baton's package system.
It follows Doria Decisions 0117 and 0118. Stage 33 Slice 1 implements the
manifest, target, discovery, and single-package build-plan portions in the
executable PHP bootstrap.

## Schema Compatibility And Current Boundary

The bootstrap continues to read historical manifest schema 1 exactly:

```toml
manifest-version = 1

[package]
name = "hello-doria"
version = "0.1.0"
kind = "binary"
entry = "src/main.doria"
```

Schema 1 means one binary, one explicit entry file, no `autoload`, no
dependencies, no `Baton.lock`, and no workspace. It is not reinterpreted.

Stage 33 Slice 1 also accepts strict schema 2 and emits compiler build plans for
one package. It does not resolve or fetch dependencies, generate a lockfile,
discover workspaces, execute tests, or run processors. Slice 2 owns dependency
resolution, lockfiles, cache, offline behavior, and dependency commands. Slice 3
owns workspaces, tests, and processors.

## Schema 2

```toml
manifest-version = 2

[package]
name = "acme/blog"
version = "1.0.0"
edition = "2026"
kind = "binary"
entry = "src/main.doria"

[autoload.namespaces]
"Acme\\Blog\\" = "src/"

[autoload-dev.namespaces]
"Acme\\Blog\\Tests\\" = "tests/"

```

Unscoped local names require explicit `publishable = false` and map to the
reserved compiler identity `local/<name>`. Scoped `vendor/package` identities
default to publishable and may opt out. Schema 2 requires strict SemVer and
edition `"2026"`. Dependency, development-dependency, processor, and workspace
tables are recognized but diagnosed with their owning future slice rather than
ignored.

## Source Discovery

`autoload` tells Baton where a namespace's source files live. Baton finds those
files during the build and gives a deterministic source inventory to `doriac`.
Doria programs do not load source files while running.

The default mapping pattern is `**/*.doria`. An advanced mapping may supply
`path`, `include`, and `exclude` while preserving the same operation. Paths are
project-relative, canonical, contained by the package root, deterministically
ordered, free from symlink loops or escapes, and checked for exact casing and
cross-platform case collisions. The longest matching namespace prefix governs
layout validation.

Main sources come from `[autoload.namespaces]`. Development sources come from
`[autoload-dev.namespaces]` and participate only in tests, examples,
benchmarks, and development tooling. Baton or an explicit processor injects
generated roots under the build directory. Generated files are checked like
handwritten source and do not trigger recursive processing.

Every active main, development, generated, dependency, and explicitly included
file is checked even when no reachable declaration uses it.

## Hybrid Strict Layout

Namespace directory segments match exactly. Given `"Acme\\Blog\\" = "src/"`,
`namespace Acme\Blog\Http;` belongs beneath `src/Http/`.

An externally accessible type named `PostController` belongs in
`PostController.doria`, and a file has one primary externally accessible type.
Related `internal` helpers may share the file. Function and constant bundles may
use descriptive filenames. Generated bundles and selected binary entry files
have bounded exceptions to the type-filename rule.

Only a selected binary entry file may contain top-level executable statements.
Other package sources are declaration-only. Discovery order never defines
runtime initialization.

## `autoload`, `use`, `include`, And Dependencies

- `autoload` discovers a package's source files.
- `use` shortens a Doria symbol name; Baton does not interpret it.
- `include` adds one same-package source file at compile time, relative to the
  including file, with required include-once semantics.
- dependencies add other packages to the build graph.

Autoload and include deduplicate by canonical source identity. Include may add a
same-package file excluded from ordinary discovery. Cross-package source
traversal, remote includes, computed includes, and runtime includes are
rejected.

## Package Identity, Targets, And Access

Publishable identities use lowercase `vendor/package`. Package identity is
independent of Doria namespace identity. An unscoped name is an explicitly
local, non-publishable package and uses `publishable = false`.

A package may have at most one `[targets.library]` and zero or more
`[[targets.binary]]` tables. The common single-binary `kind` and `entry`
shorthand remains and cannot mix with explicit targets. Commands select with
`--binary <name>` or `--library`; there is no generic `--target` or default
target field.

Doria `internal` is package-wide, including the package's own development and
generated sources. It is not workspace-wide. Several packages may contribute
distinct declarations to one namespace, but duplicate fully qualified symbols
are compiler errors with both packages and sources identified.

## Baton-To-Compiler Build Plan

Baton resolves manifests, source mappings, dependency and workspace graphs,
generated roots, targets, and profiles into a versioned JSON build plan. The
plan explicitly carries package roots, stable source identities, scopes,
origins, namespace mappings, entries, dependency edges, direct dependencies,
package access boundaries, compiler options, target, and profile.

`doriac` owns Doria parsing, declaration indexing, namespace and import
resolution, type and ownership checking, package visibility, duplicate symbols,
MIR, code generation, and compiler caches. It does not parse `Baton.toml` or
fetch packages. Baton does not parse Doria declarations or maintain a semantic
symbol index.

Standalone `doriac` remains usable with explicit files or a compiler-owned
build plan.

## Dependency Resolution

Only a package's own declarations, the standard library/prelude, and directly
declared dependencies are source-visible. Transitive dependencies are not.
Externally accessible signatures cannot leak a type from an undeclared direct
dependency.

One build graph resolves one version per `vendor/package` identity. Conflicts
report every chain, constraint, and source. Every package dependency cycle is
rejected, including active development, processor, and workspace edges.

The first source transports are path and Git. A Git dependency chooses exactly
one of `rev`, `tag`, or `branch`; the lockfile always records the exact commit.
Path dependencies remain live inputs. Source transport is distinct from
artifact role so future registries, verified archives, native libraries,
processors, binary tools, and prebuilt artifacts fit without replacing the
model. Registry, archive, native-feed, binary-feed, and publishing behavior are
not part of the initial resolver.

Packages use SemVer exact, caret, tilde, and bounded comparator constraints.
Prereleases are explicit. OR expressions, Composer stability flags, implicit
development stability, and toolchain CalVer ranges are rejected. Toolchain
releases remain CalVer.

## Lockfile And Build Receipt

`Baton.lock` is deterministic, machine-generated JSON and is never hand-edited.
Applications, libraries, and workspaces commit one lockfile at the workspace
root. Entries carry package/version identity, category, edges, source kind,
canonical URL or manifest-relative path, exact resolution, and integrity data.
Remote URLs contain no secrets; committed path entries contain no absolute
local paths.

Compiler, target, profile, flag, native-toolchain, generated-source,
path-content-hash, lock-identity, and build-plan facts belong in a versioned
build receipt such as `build.json`, not in the lockfile.

`baton install` uses an existing lock exactly or creates one when absent.
`baton update` deliberately re-resolves all or selected dependencies. Ordinary
check, build, run, and test commands may install locked content but never update
a valid lockfile silently.

## Workspaces And Categories

A workspace has deterministic members, one lockfile, shared build storage, and
one shared dependency cache. Each member retains its manifest, identity,
autoload mappings, targets, dependencies, and `internal` boundary. Duplicate
identities or paths, root escapes, ambiguous globs, and cycles are rejected.

Normal dependencies enter normal builds. Development dependencies enter tests,
examples, benchmarks, and development tooling only. Processors are explicit,
locked build tools kept separate from source dependencies and from package
source visibility.

No package-defined arbitrary build scripts, PHP hooks, shell hooks, or implicit
install/build commands are accepted. Processors write generated files under the
build directory and do not mutate handwritten source by default. Baton does not
claim sandboxing until a real sandbox and permission model exist.

## Cache, Offline Mode, And Commands

Exact dependency sources use a global content-addressed cache. Projects do not
receive a local `vendor/` directory by default. Workspace storage contains
build plans, receipts, generated sources, inventories, compiler-cache
references, and artifacts.

Offline install/check/build/run/test never reaches the network. It uses the
lockfile, live path dependencies, cached exact remote sources, and valid
generated inputs. Missing content is reported without version substitution.

The accepted dependency commands are `install`, `add`, `remove`, `update`,
`fetch`, `tree`, and `why`. Slice 1 recognizes them only to produce precise
Slice-2 diagnostics. They perform no resolution, mutation, fetching, caching,
or lockfile writes.

## Delivery Sequence

Stages 31 and 32 are complete. Stage 33 Slice 1 is complete: schema 2, source
inventory, targets, and single-package compiler plans. Stage 33 Slice 2 is next:
resolver, lockfile, cache, and offline behavior. Slice 3 follows with
workspaces, tests, and processors. Stage 33 remains in progress, not complete.
Those slices run in this disposable PHP UX bootstrap to validate and freeze the
observable contract. Decision 0124 then requires a parity-gated Pre-Stage-45
port to the clean Doria-native `dorialang/baton` repository, production release
ownership transfer, and removal of the Baton PHP payload before the unsuffixed
`2026.03.1` release.

The active language sequence remains unchanged. This document does not begin
those implementation stages.
